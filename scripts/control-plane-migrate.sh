#!/usr/bin/env bash
## Coolify control-plane cross-host migration bridge (fork issue #153).
##
## Subcommands:
##   capture    Snapshot a source control plane: tree census, PostgreSQL custom-format
##              dump, .env snapshot, inventory counts, manifest + SHA256 checksums.
##   verify     Fail-closed validation of an archive: checksums, dump readability,
##              tree archive integrity, APP_KEY presence.
##   env-merge  Key-by-key merge of source and target .env files (never wholesale).
##   inventory  Emit inventory counts JSON for a running control plane database.
##   restore    Fail-closed restore onto a managed fork target with release proof,
##              overwrite authorization, target backup, worker quiescence, and
##              database migration validation.
##
## This tool never adopts unmanaged state itself; it moves state between hosts.
## It never touches fork-deploy trust/release state and never runs fork-deploy install.
set -Eeuo pipefail

MANIFEST_SCHEMA="coolify-control-plane-migration/2"
SUPPORTED_CONTROL_PLANE_STATE_CONTRACT="absent"
DEFAULT_ROOT="/data/coolify"
APP_CONTAINER="${COOLIFY_MIGRATE_APP_CONTAINER:-coolify}"
DB_CONTAINER="${COOLIFY_MIGRATE_DB_CONTAINER:-coolify-db}"
REDIS_CONTAINER="${COOLIFY_MIGRATE_REDIS_CONTAINER:-coolify-redis}"
LEGACY_REALTIME_CONTAINER="${COOLIFY_MIGRATE_REALTIME_CONTAINER:-coolify-realtime}"
FLOCK_BIN="${COOLIFY_MIGRATE_FLOCK_BIN:-flock}"
CONTROL_PLANE_LISTENER_OVERRIDE_RELATIVE_PATH="source/docker-compose.control-plane-listener.yml"
CONTROL_PLANE_MANAGED_STATE_RELATIVE_PATH="proxy/.control-plane-managed-traefik"
CONTROL_PLANE_STATIC_LOCK_RELATIVE_PATH="proxy/.control-plane-static-listener-enrollment.lock"
CONTROL_PLANE_DYNAMIC_LOCK_RELATIVE_PATH="$CONTROL_PLANE_MANAGED_STATE_RELATIVE_PATH/.coolify.yaml.lock"
CONTROL_PLANE_SOURCE_ROLLBACK_PREFIX=".control-plane-source-override-rollback."
CAPTURE_INCOMPLETE_MARKER=".control-plane-migrate.incomplete"
CONTROL_PLANE_LOCKS_HELD=false
CONTROL_PLANE_LOCK_ROOT=""
CONTROL_PLANE_PROXY_IDENTITY=""
CONTROL_PLANE_STATIC_LOCK_IDENTITY=""
CONTROL_PLANE_DYNAMIC_LOCK_IDENTITY=""
CAPTURE_STAGING_OUTPUT=""
CAPTURE_STAGING_OUTPUT_IDENTITY=""
CAPTURE_STAGING_PARENT=""
CAPTURE_STAGING_PARENT_IDENTITY=""

# Trees captured by default and their restore policy on the target.
#   replace      restored verbatim onto the target (after target backup)
#   never        captured for evidence/env-merge only; never extracted on the target
#   opt-in       extracted only with --restore-proxy
DEFAULT_CAPTURE_TREES="ssh applications databases services backups source proxy"
NEVER_RESTORE_TREES="source"
OPT_IN_RESTORE_TREES="proxy"
# Target-side state that must never be captured from a source or restored over.
PROTECTED_TREES="fork-deploy"

# env-merge key tables. APP_KEY is required in the source; all others are optional.
SOURCE_PRESERVED_KEYS="APP_KEY APP_PREVIOUS_KEYS APP_ID APP_NAME APP_URL \
PUSHER_APP_ID PUSHER_APP_KEY PUSHER_APP_SECRET PUSHER_HOST \
ROOT_USERNAME ROOT_USER_EMAIL ROOT_USER_PASSWORD \
REGISTRY_URL DOCKER_ADDRESS_POOL_BASE DOCKER_ADDRESS_POOL_SIZE DOCKER_POOL_FORCE_OVERRIDE"
TARGET_RETAINED_KEYS="APP_ENV APP_DEBUG \
DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
REDIS_HOST REDIS_PORT REDIS_PASSWORD REDIS_DB \
COOLIFY_FORK_VERSION VERSIONS_URL UPGRADE_SCRIPT_URL RELEASES_URL AUTOUPDATE \
APP_PORT PUSHER_BACKEND_HOST PUSHER_BACKEND_PORT \
TERMINAL_PORT TERMINAL_BACKEND_PORT HORIZON_ENABLED SCHEDULER_ENABLED"
# Browser-facing websocket port pins. If either reaches the runtime env, the UI
# dials wss://host:6001 directly instead of the same-origin /app path that
# Traefik routes to the in-container Reverb, breaking realtime on the canonical
# domain. Production keeps both unset (compose defaults still serve direct
# port access), so the merge drops them from both sides.
DROPPED_KEYS="PUSHER_PORT SOKETI_PORT"
REQUIRED_SOURCE_KEYS="APP_KEY"

INVENTORY_TABLES="users teams projects environments servers private_keys \
github_apps gitlab_apps applications application_previews services \
service_applications service_databases standalone_postgresqls standalone_mysqls \
standalone_mariadbs standalone_mongodbs standalone_redis standalone_clickhouses \
standalone_keydbs standalone_dragonflies standalone_dockers \
environment_variables shared_environment_variables \
scheduled_database_backups scheduled_tasks"

log() {
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

usage() {
    cat <<'EOF'
Usage:
  control-plane-migrate.sh capture --output DIR [--root DIR]
      --require-source-stopped
      [--include NAME]... [--exclude NAME]... [--capture-redis] [--gpg-recipient ID]
  control-plane-migrate.sh verify --archive DIR
  control-plane-migrate.sh env-merge --source-env FILE --target-env FILE --output FILE [--report FILE]
  control-plane-migrate.sh inventory [--root DIR] [--output FILE]
  control-plane-migrate.sh restore --archive DIR [--root DIR]
      --authorize-overwrite HOSTNAME --expect-fork-version VERSION --expect-image-digest sha256:...
      [--restore-proxy] [--enable-workers] [--target-backup-dir DIR] [--skip-app-start]

Safety contract:
  - capture refuses undecided top-level directories (full /data/coolify census).
  - schema-v2 capture requires a verified stopped source; live attestations are rejected.
  - capture and restore hold the canonical static and dynamic enrollment writer
    locks through archive acceptance or restore completion.
  - restore requires the exact target hostname as overwrite authorization.
  - restore refuses non-managed targets (missing fork-deploy/current), PostgreSQL
    major downgrades, release/digest mismatches, and failed checksums.
  - restore disables Horizon and the scheduler unless --enable-workers is given;
    there must never be two mutable production control planes.
  - Database passwords never appear in process arguments or container metadata;
    the dump/restore path uses the container-local trust socket only.
  - If --gpg-recipient is used, store the decryption key separately from the archive.
EOF
}

list_contains() {
    local needle="$1" item
    shift
    for item in "$@"; do
        [ "$item" = "$needle" ] && return 0
    done
    return 1
}

# shellcheck disable=SC2086
in_word_list() {
    local needle="$1" list="$2"
    list_contains "$needle" $list
}

get_env_var() {
    local key="$1" file="$2" fallback="${3:-}" value
    value=$(grep -E "^${key}=" "$file" 2>/dev/null | tail -n 1 | cut -d '=' -f 2- || true)
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"
    if [ -z "$value" ]; then
        printf '%s' "$fallback"
    else
        printf '%s' "$value"
    fi
}

set_env_var() {
    local key="$1" value="$2" file="$3" tmp
    tmp=$(mktemp "${file}.tmp.XXXXXX")
    chmod 0600 "$tmp"
    if grep -qE "^${key}=" "$file" 2>/dev/null; then
        awk -v key="$key" -v value="$value" 'BEGIN { FS="="; OFS="=" }
            $1 == key && !done { print key, value; done=1; next }
            { print }' "$file" > "$tmp"
    else
        cat "$file" > "$tmp" 2>/dev/null || true
        printf '%s=%s\n' "$key" "$value" >> "$tmp"
    fi
    mv "$tmp" "$file"
}

json_escape() {
    printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/	/\\t/g' | tr '\n' ' '
}

sha256_file() {
    sha256sum "$1" | awk '{print $1}'
}

utc_now() {
    date -u '+%Y-%m-%dT%H:%M:%SZ'
}

require_file() {
    [ -f "$1" ] && [ ! -L "$1" ] || fail "$2 must be a regular non-symlink file: $1"
}

require_dir() {
    [ -d "$1" ] && [ ! -L "$1" ] || fail "$2 must be a non-symlink directory: $1"
}

resolve_root() {
    local root="${1:-$DEFAULT_ROOT}"
    if [ "${COOLIFY_MIGRATE_TEST_MODE:-false}" = "true" ]; then
        [ -n "${1:-}" ] || fail "test mode requires an explicit --root"
    else
        [ "$root" = "$DEFAULT_ROOT" ] || fail "production root must be exactly $DEFAULT_ROOT"
        [ "$(id -u)" -eq 0 ] || fail "run as root outside test mode"
    fi
    require_dir "$root" "Coolify root"
    printf '%s' "$root"
}

db_credentials() {
    # Reads DB_USERNAME/DB_DATABASE from the control-plane .env of root $1.
    local root="$1" env_file="$1/source/.env"
    require_file "$env_file" "control-plane .env"
    DB_USERNAME=$(get_env_var DB_USERNAME "$env_file" "postgres")
    DB_DATABASE=$(get_env_var DB_DATABASE "$env_file" "coolify")
    [ -n "$DB_USERNAME" ] && [ -n "$DB_DATABASE" ] || fail "DB_USERNAME/DB_DATABASE could not be resolved from $env_file"
}

pg_query() {
    docker exec "$DB_CONTAINER" psql -U "$DB_USERNAME" -d "$DB_DATABASE" -Atc "$1"
}

pg_admin_query() {
    docker exec "$DB_CONTAINER" psql -U "$DB_USERNAME" -d postgres -Atc "$1"
}

# The key-existence operator ? is defined for jsonb only. Pre-fork control planes
# (upstream 4.1.2 and earlier) declare servers.proxy as json, so an uncast operand
# aborts with "operator does not exist: json ? unknown" and fails the census on
# exactly the unmigrated sources this bridge exists to move. The cast is a no-op
# once the column is already jsonb.
control_plane_state_count() {
    local key="$1"
    pg_query "SELECT count(*) FROM servers WHERE proxy::jsonb ? '${key}'"
}

control_plane_state_fingerprint() {
    local fingerprint
    fingerprint=$(pg_query "SELECT md5(COALESCE(string_agg(id::text || ':' || xmin::text || ':' || COALESCE((proxy::jsonb ? 'control_plane_proxy_enrollment')::text, 'false') || ':' || COALESCE((proxy::jsonb ? 'control_plane_generation_promotion')::text, 'false'), ',' ORDER BY id), '')) FROM servers /* coolify-control-plane-migration-state-fingerprint */") \
        || fail "control-plane server state fingerprint could not be inspected"
    [ "${#fingerprint}" -eq 32 ] \
        || fail "control-plane server state fingerprint is malformed"
    case "$fingerprint" in
        *[!a-f0-9]*) fail "control-plane server state fingerprint is malformed" ;;
    esac
    printf '%s' "$fingerprint"
}

path_link_count() {
    stat -c '%h' "$1" 2>/dev/null || stat -f '%l' "$1"
}

path_owner_uid() {
    stat -c '%u' "$1" 2>/dev/null || stat -f '%u' "$1"
}

path_group_gid() {
    stat -c '%g' "$1" 2>/dev/null || stat -f '%g' "$1"
}

path_mode() {
    stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"
}

path_identity_following_symlinks() {
    stat -Lc '%d:%i' "$1" 2>/dev/null || stat -Lf '%d:%i' "$1"
}

path_inode_following_symlinks() {
    stat -Lc '%i' "$1" 2>/dev/null || stat -Lf '%i' "$1"
}

assert_trusted_control_plane_directory() {
    local path="$1" label="$2" owner expected_owner mode group_digit other_digit
    [ -d "$path" ] && [ ! -L "$path" ] || fail "$label must be a non-symlink directory: $path"
    owner=$(path_owner_uid "$path") || fail "$label owner could not be inspected: $path"
    if [ "${COOLIFY_MIGRATE_TEST_MODE:-false}" = "true" ]; then
        expected_owner=$(id -u)
    else
        expected_owner=0
    fi
    [ "$owner" -eq "$expected_owner" ] || fail "$label must be root-owned: $path"
    mode=$(path_mode "$path") || fail "$label permissions could not be inspected: $path"
    case "$mode" in
        ''|*[!0-7]*) fail "$label permissions are malformed: $path" ;;
    esac
    [ "${#mode}" -le 3 ] || fail "$label must not carry special permission bits: $path"
    group_digit="${mode: -2:1}"
    other_digit="${mode: -1}"
    case "${group_digit}${other_digit}" in
        *[2367]*) fail "$label must not be writable by group or other: $path" ;;
    esac
}

assert_safe_control_plane_lock_or_absent() {
    local path="$1" label="$2" owner group expected_owner expected_group mode links
    if [ ! -e "$path" ] && [ ! -L "$path" ]; then
        return 0
    fi
    [ -f "$path" ] && [ ! -L "$path" ] || fail "$label must be a regular non-symlink file: $path"
    links=$(path_link_count "$path") || fail "$label link count could not be inspected: $path"
    [ "$links" -eq 1 ] || fail "$label must have exactly one hard link: $path"
    owner=$(path_owner_uid "$path") || fail "$label owner could not be inspected: $path"
    group=$(path_group_gid "$path") || fail "$label group could not be inspected: $path"
    if [ "${COOLIFY_MIGRATE_TEST_MODE:-false}" = "true" ]; then
        expected_owner=$(id -u)
        expected_group=$(id -g)
    else
        expected_owner=0
        expected_group=0
    fi
    [ "$owner" -eq "$expected_owner" ] && [ "$group" -eq "$expected_group" ] \
        || fail "$label must be root:root: $path"
    mode=$(path_mode "$path") || fail "$label permissions could not be inspected: $path"
    [ "$mode" = 600 ] || fail "$label must have mode 0600: $path"
}

assert_static_control_plane_lock_pre_acquire_or_absent() {
    local path="$1" label="$2" owner group root_owner root_group mode links
    if [ ! -e "$path" ] && [ ! -L "$path" ]; then
        return 0
    fi
    [ -f "$path" ] && [ ! -L "$path" ] || fail "$label must be a regular non-symlink file: $path"
    links=$(path_link_count "$path") || fail "$label link count could not be inspected: $path"
    [ "$links" -eq 1 ] || fail "$label must have exactly one hard link: $path"
    owner=$(path_owner_uid "$path") || fail "$label owner could not be inspected: $path"
    group=$(path_group_gid "$path") || fail "$label group could not be inspected: $path"
    mode=$(path_mode "$path") || fail "$label permissions could not be inspected: $path"
    if [ "${COOLIFY_MIGRATE_TEST_MODE:-false}" = "true" ]; then
        root_owner=$(id -u)
        root_group=$(id -g)
    else
        root_owner=0
        root_group=0
    fi
    if [ "$owner" -eq "$root_owner" ] && [ "$group" -eq "$root_group" ] && [ "$mode" = 600 ]; then
        return 0
    fi
    if [ "$owner" -eq 9999 ] && [ "$group" -eq 0 ] && [ "$mode" = 700 ]; then
        return 0
    fi
    fail "$label must be root:root mode 0600 or legacy 9999:root mode 0700: $path"
}

assert_control_plane_proxy_directory_identity() {
    local root="$1" expected_identity="$2" proxy_path identity
    assert_trusted_control_plane_directory "$root" "Coolify root"
    proxy_path="$root/proxy"
    assert_trusted_control_plane_directory "$proxy_path" "control-plane proxy directory"
    identity=$(path_identity_following_symlinks "$proxy_path") \
        || fail "control-plane proxy directory identity could not be inspected"
    [ "$identity" = "$expected_identity" ] \
        || fail "control-plane proxy directory inode changed while migration held its fence"
}

assert_control_plane_writer_locks_held() {
    local static_path dynamic_path static_identity dynamic_identity
    [ "$CONTROL_PLANE_LOCKS_HELD" = true ] || fail "control-plane writer locks are not held"
    assert_control_plane_proxy_directory_identity \
        "$CONTROL_PLANE_LOCK_ROOT" "$CONTROL_PLANE_PROXY_IDENTITY"
    static_path="$CONTROL_PLANE_LOCK_ROOT/$CONTROL_PLANE_STATIC_LOCK_RELATIVE_PATH"
    dynamic_path="$CONTROL_PLANE_LOCK_ROOT/$CONTROL_PLANE_DYNAMIC_LOCK_RELATIVE_PATH"
    assert_safe_control_plane_lock_or_absent "$static_path" "control-plane static listener lock"
    assert_safe_control_plane_lock_or_absent "$dynamic_path" "control-plane managed Traefik lock"
    static_identity=$(path_identity_following_symlinks "$static_path") \
        || fail "control-plane static listener lock identity could not be inspected"
    dynamic_identity=$(path_identity_following_symlinks "$dynamic_path") \
        || fail "control-plane managed Traefik lock identity could not be inspected"
    [ "$static_identity" = "$CONTROL_PLANE_STATIC_LOCK_IDENTITY" ] \
        || fail "control-plane static listener lock inode changed while migration held its fence"
    [ "$dynamic_identity" = "$CONTROL_PLANE_DYNAMIC_LOCK_IDENTITY" ] \
        || fail "control-plane managed Traefik lock inode changed while migration held its fence"
}

acquire_control_plane_writer_locks() {
    local root="$1" proxy_path managed_state_path static_path dynamic_path previous_umask
    local proxy_identity static_path_inode dynamic_path_inode static_fd_inode dynamic_fd_inode
    [ "$CONTROL_PLANE_LOCKS_HELD" = false ] || fail "control-plane writer locks were already acquired"
    command -v "$FLOCK_BIN" >/dev/null 2>&1 || fail "flock is required for the control-plane migration fence"

    assert_trusted_control_plane_directory "$root" "Coolify root"
    proxy_path="$root/proxy"
    assert_trusted_control_plane_directory "$proxy_path" "control-plane proxy directory"
    proxy_identity=$(path_identity_following_symlinks "$proxy_path") \
        || fail "control-plane proxy directory identity could not be recorded"
    managed_state_path="$root/$CONTROL_PLANE_MANAGED_STATE_RELATIVE_PATH"
    if [ ! -e "$managed_state_path" ] && [ ! -L "$managed_state_path" ]; then
        mkdir "$managed_state_path" || fail "could not create the persistent control-plane writer state directory"
        if [ "${COOLIFY_MIGRATE_TEST_MODE:-false}" = "true" ]; then
            chmod 0700 "$managed_state_path"
        else
            chown root:9999 "$managed_state_path"
            chmod 0710 "$managed_state_path"
        fi
    fi
    assert_trusted_control_plane_directory "$managed_state_path" "control-plane managed Traefik state directory"

    static_path="$root/$CONTROL_PLANE_STATIC_LOCK_RELATIVE_PATH"
    dynamic_path="$root/$CONTROL_PLANE_DYNAMIC_LOCK_RELATIVE_PATH"
    assert_static_control_plane_lock_pre_acquire_or_absent "$static_path" "control-plane static listener lock"
    assert_safe_control_plane_lock_or_absent "$dynamic_path" "control-plane managed Traefik lock"

    previous_umask=$(umask)
    umask 077
    exec 8>>"$static_path" || fail "could not open the control-plane static listener lock"
    "$FLOCK_BIN" -x 8 || fail "could not acquire the control-plane static listener lock"
    assert_control_plane_proxy_directory_identity "$root" "$proxy_identity"
    static_path_inode=$(path_inode_following_symlinks "$static_path") \
        || fail "control-plane static listener lock inode could not be inspected"
    static_fd_inode=$(path_inode_following_symlinks /dev/fd/8) \
        || fail "open control-plane static listener lock inode could not be inspected"
    [ "$static_fd_inode" = "$static_path_inode" ] \
        || fail "control-plane static listener lock path did not retain its opened inode"
    chown root:root /dev/fd/8 || fail "could not normalize the opened control-plane static listener lock owner"
    chmod 0600 /dev/fd/8 || fail "could not normalize the opened control-plane static listener lock mode"
    assert_control_plane_proxy_directory_identity "$root" "$proxy_identity"
    static_path_inode=$(path_inode_following_symlinks "$static_path") \
        || fail "normalized control-plane static listener lock inode could not be inspected"
    static_fd_inode=$(path_inode_following_symlinks /dev/fd/8) \
        || fail "normalized open control-plane static listener lock inode could not be inspected"
    [ "$static_fd_inode" = "$static_path_inode" ] \
        || fail "normalized control-plane static listener lock path changed inode"
    assert_safe_control_plane_lock_or_absent "$static_path" "control-plane static listener lock"

    exec 9>>"$dynamic_path" || fail "could not open the control-plane managed Traefik lock"
    "$FLOCK_BIN" -x 9 || fail "could not acquire the control-plane managed Traefik lock"
    assert_control_plane_proxy_directory_identity "$root" "$proxy_identity"
    dynamic_path_inode=$(path_inode_following_symlinks "$dynamic_path") \
        || fail "control-plane managed Traefik lock inode could not be inspected"
    dynamic_fd_inode=$(path_inode_following_symlinks /dev/fd/9) \
        || fail "open control-plane managed Traefik lock inode could not be inspected"
    [ "$dynamic_fd_inode" = "$dynamic_path_inode" ] \
        || fail "control-plane managed Traefik lock path did not retain its opened inode"
    chown root:root /dev/fd/9 || fail "could not secure the opened control-plane managed Traefik lock owner"
    chmod 0600 /dev/fd/9 || fail "could not secure the opened control-plane managed Traefik lock mode"
    assert_control_plane_proxy_directory_identity "$root" "$proxy_identity"
    umask "$previous_umask"

    assert_control_plane_proxy_directory_identity "$root" "$proxy_identity"
    assert_safe_control_plane_lock_or_absent "$static_path" "control-plane static listener lock"
    assert_safe_control_plane_lock_or_absent "$dynamic_path" "control-plane managed Traefik lock"
    # macOS exposes open descriptors through fdesc with a synthetic device ID,
    # while retaining the opened vnode's inode. Compare each FD/path inode here,
    # then retain device+inode for all subsequent pathname checks.
    static_path_inode=$(path_inode_following_symlinks "$static_path") \
        || fail "control-plane static listener lock inode could not be inspected"
    static_fd_inode=$(path_inode_following_symlinks /dev/fd/8) \
        || fail "open control-plane static listener lock inode could not be inspected"
    dynamic_path_inode=$(path_inode_following_symlinks "$dynamic_path") \
        || fail "control-plane managed Traefik lock inode could not be inspected"
    dynamic_fd_inode=$(path_inode_following_symlinks /dev/fd/9) \
        || fail "open control-plane managed Traefik lock inode could not be inspected"
    [ "$static_fd_inode" = "$static_path_inode" ] \
        || fail "control-plane static listener lock path did not retain its opened inode"
    [ "$dynamic_fd_inode" = "$dynamic_path_inode" ] \
        || fail "control-plane managed Traefik lock path did not retain its opened inode"
    CONTROL_PLANE_STATIC_LOCK_IDENTITY=$(path_identity_following_symlinks "$static_path") \
        || fail "control-plane static listener lock identity could not be inspected"
    CONTROL_PLANE_DYNAMIC_LOCK_IDENTITY=$(path_identity_following_symlinks "$dynamic_path") \
        || fail "control-plane managed Traefik lock identity could not be inspected"

    CONTROL_PLANE_LOCK_ROOT="$root"
    CONTROL_PLANE_PROXY_IDENTITY="$proxy_identity"
    CONTROL_PLANE_LOCKS_HELD=true
    assert_control_plane_writer_locks_held
}

assert_control_plane_migration_filesystem_absent() {
    local root="$1" owner="$2"
    local listener_path proxy_path managed_state_path static_lock_path dynamic_lock_path path basename

    listener_path="$root/$CONTROL_PLANE_LISTENER_OVERRIDE_RELATIVE_PATH"
    if [ -e "$listener_path" ] || [ -L "$listener_path" ]; then
        fail "$owner filesystem contains a managed control-plane listener override: $listener_path. Enrollment migration is unsupported; roll back or reconcile the enrollment until the listener override is absent, then retry."
    fi

    for path in "$root/source/$CONTROL_PLANE_SOURCE_ROLLBACK_PREFIX"*; do
        if [ -e "$path" ] || [ -L "$path" ]; then
            fail "$owner filesystem contains managed control-plane source-override rollback state: $path. Enrollment migration is unsupported; complete and reconcile the rollback before retrying."
        fi
    done

    assert_trusted_control_plane_directory "$root" "Coolify root"
    proxy_path="$root/proxy"
    assert_trusted_control_plane_directory "$proxy_path" "control-plane proxy directory"
    static_lock_path="$root/$CONTROL_PLANE_STATIC_LOCK_RELATIVE_PATH"
    dynamic_lock_path="$root/$CONTROL_PLANE_DYNAMIC_LOCK_RELATIVE_PATH"
    if [ "$CONTROL_PLANE_LOCKS_HELD" = true ] && [ "$CONTROL_PLANE_LOCK_ROOT" = "$root" ]; then
        assert_safe_control_plane_lock_or_absent "$static_lock_path" "control-plane static listener lock"
    else
        assert_static_control_plane_lock_pre_acquire_or_absent "$static_lock_path" "control-plane static listener lock"
    fi

    managed_state_path="$root/$CONTROL_PLANE_MANAGED_STATE_RELATIVE_PATH"
    if [ -L "$managed_state_path" ]; then
        fail "$owner filesystem control-plane enrollment writer state path is a symlink: $managed_state_path. Enrollment migration is unsupported; roll back or reconcile the enrollment until writer state is absent or contains only the persistent lock, then retry."
    fi
    if [ -e "$managed_state_path" ]; then
        assert_trusted_control_plane_directory "$managed_state_path" "control-plane managed Traefik state directory"
        assert_safe_control_plane_lock_or_absent "$dynamic_lock_path" "control-plane managed Traefik lock"
        for path in "$managed_state_path"/* "$managed_state_path"/.*; do
            [ -e "$path" ] || [ -L "$path" ] || continue
            basename="${path##*/}"
            [ "$basename" != . ] && [ "$basename" != .. ] || continue
            if [ "$path" = "$dynamic_lock_path" ]; then
                assert_safe_control_plane_lock_or_absent "$path" "control-plane managed Traefik lock"
                continue
            fi
            fail "$owner filesystem contains managed control-plane enrollment writer state: $path. Enrollment migration is unsupported; roll back or reconcile the enrollment until only the persistent writer lock remains, then retry."
        done
    fi
}

assert_control_plane_migration_state_absent() {
    local root="$1" owner="$2"
    local enrollment_count promotion_count

    enrollment_count=$(control_plane_state_count control_plane_proxy_enrollment) \
        || fail "$owner database control-plane enrollment state could not be inspected"
    promotion_count=$(control_plane_state_count control_plane_generation_promotion) \
        || fail "$owner database control-plane generation-promotion state could not be inspected"
    case "$enrollment_count" in
        ''|*[!0-9]*) fail "$owner database returned an invalid control-plane enrollment state count: $enrollment_count" ;;
    esac
    case "$promotion_count" in
        ''|*[!0-9]*) fail "$owner database returned an invalid control-plane generation-promotion state count: $promotion_count" ;;
    esac
    [ "$enrollment_count" -eq 0 ] \
        || fail "$owner database contains durable control-plane proxy enrollment state. Enrollment migration is unsupported; roll back or reconcile the enrollment until control_plane_proxy_enrollment is absent, then retry."
    [ "$promotion_count" -eq 0 ] \
        || fail "$owner database contains durable control-plane generation-promotion state. Generation migration is unsupported; roll back or reconcile the promotion until control_plane_generation_promotion is absent, then retry."

    assert_control_plane_migration_filesystem_absent "$root" "$owner"
}

assert_control_plane_archive_filesystem_absent() {
    local archive_root="$1" owner="$2"
    local source_archive="$archive_root/tree-source.tar.gz"
    local proxy_archive="$archive_root/tree-proxy.tar.gz"
    local listing member normalized verbose saw_static=false saw_dynamic=false

    require_file "$source_archive" "$owner source tree archive"
    require_file "$proxy_archive" "$owner proxy tree archive"

    listing=$(tar -tzf "$source_archive") || fail "$owner source tree archive could not be inspected"
    while IFS= read -r member; do
        normalized="${member#./}"
        normalized="${normalized%/}"
        case "$normalized" in
            "$CONTROL_PLANE_LISTENER_OVERRIDE_RELATIVE_PATH"|source/"$CONTROL_PLANE_SOURCE_ROLLBACK_PREFIX"*)
                fail "$owner source tree archive contains unsupported control-plane enrollment state: $normalized"
                ;;
        esac
    done <<< "$listing"

    listing=$(tar -tzf "$proxy_archive") || fail "$owner proxy tree archive could not be inspected"
    while IFS= read -r member; do
        normalized="${member#./}"
        normalized="${normalized%/}"
        case "$normalized" in
            "$CONTROL_PLANE_STATIC_LOCK_RELATIVE_PATH")
                saw_static=true
                ;;
            "$CONTROL_PLANE_STATIC_LOCK_RELATIVE_PATH"/*)
                fail "$owner proxy tree archive contains an unsafe static listener lock entry: $normalized"
                ;;
            "$CONTROL_PLANE_MANAGED_STATE_RELATIVE_PATH")
                ;;
            "$CONTROL_PLANE_DYNAMIC_LOCK_RELATIVE_PATH")
                saw_dynamic=true
                ;;
            "$CONTROL_PLANE_MANAGED_STATE_RELATIVE_PATH"/*)
                fail "$owner proxy tree archive contains unsupported control-plane writer state: $normalized"
                ;;
        esac
    done <<< "$listing"
    [ "$saw_static" = true ] || fail "$owner proxy tree archive is missing the persistent static listener lock"
    [ "$saw_dynamic" = true ] || fail "$owner proxy tree archive is missing the persistent managed Traefik lock"
    verbose=$(tar -tvzf "$proxy_archive" "$CONTROL_PLANE_STATIC_LOCK_RELATIVE_PATH") \
        || fail "$owner static listener lock archive entry could not be inspected"
    case "$verbose" in -*) ;; *) fail "$owner static listener lock archive entry is not a regular file" ;; esac
    verbose=$(tar -tvzf "$proxy_archive" "$CONTROL_PLANE_DYNAMIC_LOCK_RELATIVE_PATH") \
        || fail "$owner managed Traefik lock archive entry could not be inspected"
    case "$verbose" in -*) ;; *) fail "$owner managed Traefik lock archive entry is not a regular file" ;; esac
}

assert_capture_staging_output_owned() {
    local path="$CAPTURE_STAGING_OUTPUT" marker identity parent_identity links mode
    [ -n "$path" ] || fail "capture staging output was not recorded"
    [ -d "$path" ] && [ ! -L "$path" ] \
        || fail "capture staging output is no longer a non-symlink directory: $path"
    identity=$(path_identity_following_symlinks "$path") \
        || fail "capture staging output identity could not be inspected: $path"
    [ "$identity" = "$CAPTURE_STAGING_OUTPUT_IDENTITY" ] \
        || fail "capture staging output inode changed: $path"
    parent_identity=$(path_identity_following_symlinks "$CAPTURE_STAGING_PARENT") \
        || fail "capture output parent identity could not be inspected"
    [ "$parent_identity" = "$CAPTURE_STAGING_PARENT_IDENTITY" ] \
        || fail "capture output parent inode changed"
    mode=$(path_mode "$path") || fail "capture staging output permissions could not be inspected"
    [ "$mode" = 700 ] || fail "capture staging output must have mode 0700: $path"
    marker="$path/$CAPTURE_INCOMPLETE_MARKER"
    require_file "$marker" "capture incomplete marker"
    links=$(path_link_count "$marker") || fail "capture incomplete marker link count could not be inspected"
    [ "$links" -eq 1 ] || fail "capture incomplete marker must have exactly one hard link"
    [ "$(path_mode "$marker")" = 600 ] || fail "capture incomplete marker must have mode 0600"
    grep -Fqx "$MANIFEST_SCHEMA" "$marker" \
        || fail "capture incomplete marker does not match migration schema"
}

mark_capture_staging_incomplete_if_owned() {
    local path="$CAPTURE_STAGING_OUTPUT" marker identity parent_identity
    [ -n "$path" ] || return 0
    [ -d "$path" ] && [ ! -L "$path" ] || return 1
    identity=$(path_identity_following_symlinks "$path" 2>/dev/null || true)
    [ -n "$identity" ] && [ "$identity" = "$CAPTURE_STAGING_OUTPUT_IDENTITY" ] || return 1
    parent_identity=$(path_identity_following_symlinks "$CAPTURE_STAGING_PARENT" 2>/dev/null || true)
    [ -n "$parent_identity" ] && [ "$parent_identity" = "$CAPTURE_STAGING_PARENT_IDENTITY" ] || return 1
    marker="$path/$CAPTURE_INCOMPLETE_MARKER"
    if [ -f "$marker" ] && [ ! -L "$marker" ] \
        && [ "$(path_link_count "$marker" 2>/dev/null || true)" = 1 ] \
        && grep -Fqx "$MANIFEST_SCHEMA" "$marker"; then
        chmod 0600 "$marker" 2>/dev/null || true
        return 0
    fi
    [ ! -e "$marker" ] && [ ! -L "$marker" ] || return 1
    (
        set -C
        umask 077
        printf '%s\n' "$MANIFEST_SCHEMA" > "$marker"
    ) || return 1
    chmod 0600 "$marker" || return 1
}

preserve_incomplete_capture_on_exit() {
    local status=$?
    trap - EXIT
    if [ "$status" -ne 0 ] && [ -n "$CAPTURE_STAGING_OUTPUT" ]; then
        if mark_capture_staging_incomplete_if_owned; then
            printf 'ERROR: incomplete capture staging directory preserved for inspection: %s\n' \
                "$CAPTURE_STAGING_OUTPUT" >&2
        else
            printf 'ERROR: capture staging ownership changed; inspect without deleting either path: %s\n' \
                "$CAPTURE_STAGING_OUTPUT" >&2
        fi
    fi
    exit "$status"
}

atomic_rename_noreplace() {
    local source="$1" destination="$2" python_bin
    if [ "${COOLIFY_MIGRATE_TEST_MODE:-false}" = "true" ] \
        && [ -n "${COOLIFY_MIGRATE_RENAME_NOREPLACE_BIN:-}" ]; then
        "$COOLIFY_MIGRATE_RENAME_NOREPLACE_BIN" "$source" "$destination"
        return
    fi
    python_bin=$(command -v python3) \
        || fail "python3 is required for atomic no-replace archive publication"
    "$python_bin" - "$source" "$destination" <<'PY'
import ctypes
import errno
import os
import sys

AT_FDCWD = -100
RENAME_NOREPLACE = 1
source = os.fsencode(sys.argv[1])
destination = os.fsencode(sys.argv[2])
libc = ctypes.CDLL(None, use_errno=True)
try:
    renameat2 = libc.renameat2
except AttributeError:
    print("renameat2 is unavailable; refusing non-atomic archive publication", file=sys.stderr)
    raise SystemExit(70)
renameat2.argtypes = [
    ctypes.c_int,
    ctypes.c_char_p,
    ctypes.c_int,
    ctypes.c_char_p,
    ctypes.c_uint,
]
renameat2.restype = ctypes.c_int
if renameat2(AT_FDCWD, source, AT_FDCWD, destination, RENAME_NOREPLACE) == 0:
    raise SystemExit(0)
error = ctypes.get_errno()
if error == errno.EEXIST:
    print("archive destination already exists; no path was replaced", file=sys.stderr)
elif error in {errno.ENOSYS, errno.EINVAL, getattr(errno, "ENOTSUP", errno.EINVAL)}:
    print("renameat2 RENAME_NOREPLACE is unsupported; refusing archive publication", file=sys.stderr)
else:
    print(f"renameat2 RENAME_NOREPLACE failed: {os.strerror(error)}", file=sys.stderr)
raise SystemExit(error if 0 < error < 126 else 70)
PY
}

publish_capture_staging_output() {
    local requested_output="$1" marker final_identity
    assert_capture_staging_output_owned
    [ ! -e "$requested_output" ] && [ ! -L "$requested_output" ] \
        || fail "capture output destination appeared before publication; staging is preserved: $requested_output"

    marker="$CAPTURE_STAGING_OUTPUT/$CAPTURE_INCOMPLETE_MARKER"
    rm -f -- "$marker" \
        || fail "could not remove the verified incomplete marker immediately before publication"
    [ ! -e "$marker" ] && [ ! -L "$marker" ] \
        || fail "capture incomplete marker still exists immediately before publication"

    if ! atomic_rename_noreplace "$CAPTURE_STAGING_OUTPUT" "$requested_output"; then
        mark_capture_staging_incomplete_if_owned || true
        fail "atomic no-replace capture publication failed; destination and staging were preserved"
    fi
    if [ -e "$CAPTURE_STAGING_OUTPUT" ] || [ -L "$CAPTURE_STAGING_OUTPUT" ]; then
        mark_capture_staging_incomplete_if_owned || true
        fail "atomic capture publication did not consume the staging path; destination and staging were preserved"
    fi
    [ -d "$requested_output" ] && [ ! -L "$requested_output" ] \
        || fail "published capture output is not the recorded staging directory"
    final_identity=$(path_identity_following_symlinks "$requested_output") \
        || fail "published capture output identity could not be inspected"
    [ "$final_identity" = "$CAPTURE_STAGING_OUTPUT_IDENTITY" ] \
        || fail "published capture output does not have the recorded staging inode"
    [ ! -e "$requested_output/$CAPTURE_INCOMPLETE_MARKER" ] \
        && [ ! -L "$requested_output/$CAPTURE_INCOMPLETE_MARKER" ] \
        || fail "published capture output retained the incomplete marker"

    CAPTURE_STAGING_OUTPUT=""
    CAPTURE_STAGING_OUTPUT_IDENTITY=""
    CAPTURE_STAGING_PARENT=""
    CAPTURE_STAGING_PARENT_IDENTITY=""
}

container_running() {
    docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "$1"
}

pg_server_major() {
    local version_num
    version_num=$(pg_admin_query 'SHOW server_version_num') || fail "could not read target PostgreSQL version from $DB_CONTAINER"
    case "$version_num" in
        ''|*[!0-9]*) fail "unexpected PostgreSQL server_version_num: $version_num" ;;
    esac
    printf '%s' $((version_num / 10000))
}

# A container's environment is fixed at creation (compose env_file), so a plain
# docker start after the .env swap can leave the app running with its
# creation-time APP_KEY. Laravel gives the real environment precedence over the
# .env file, which silently breaks decryption of data encrypted under the
# restored key. Compare the app's effective key against the restored .env.
verify_effective_app_key() {
    local env_file="$1"
    local expected effective
    expected=$(get_env_var APP_KEY "$env_file")
    effective=$(docker exec "$APP_CONTAINER" php artisan config:show app.key 2>/dev/null \
        | awk '/app\.key/ {print $NF; exit}')
    [ -n "$effective" ] || fail "could not read the effective app.key from '$APP_CONTAINER'"
    if [ "$effective" != "$expected" ]; then
        fail "effective APP_KEY mismatch: '$APP_CONTAINER' still runs its creation-time key, so data encrypted under the restored APP_KEY cannot decrypt. Recreate the app container from its compose project (docker compose ... up -d --force-recreate) so the restored .env takes effect, then re-run restore with a fresh --target-backup-dir."
    fi
    log "effective APP_KEY matches the restored environment"
}

verify_dump_readable() {
    local dump="$1" major="${2:-}"
    local pg_restore_bin="${COOLIFY_MIGRATE_PG_RESTORE_BIN:-pg_restore}"
    if command -v "$pg_restore_bin" >/dev/null 2>&1; then
        "$pg_restore_bin" -l "$dump" >/dev/null 2>&1 && return 0
        fail "pg_dump archive is not readable by local pg_restore: $dump"
    fi
    [ -n "$major" ] || fail "pg_restore is unavailable locally and no PostgreSQL major is known for a client container"
    local client_image="postgres:${major}-alpine"
    docker image inspect "$client_image" >/dev/null 2>&1 \
        || fail "PostgreSQL client image $client_image is not present locally; run 'docker pull $client_image' first (this check never pulls images)"
    docker run --rm -i --network none --pull never \
        -v "$dump:/tmp/migration.dump:ro" \
        "$client_image" pg_restore -l /tmp/migration.dump >/dev/null 2>&1 \
        || fail "pg_dump archive is not readable (verified with $client_image client): $dump"
}

## ---------------------------------------------------------------------------
## inventory
## ---------------------------------------------------------------------------

write_inventory_json() {
    # Emits {"tables": {"users": 1, ...}}; missing tables record null, never fail.
    local output="$1" table count first=true
    {
        printf '{"tables": {'
        for table in $INVENTORY_TABLES; do
            if count=$(pg_query "SELECT count(*) FROM ${table}" 2>/dev/null); then
                :
            else
                count="null"
            fi
            if [ "$first" = true ]; then
                first=false
            else
                printf ', '
            fi
            printf '"%s": %s' "$table" "$count"
        done
        printf '}}\n'
    } > "$output"
}

## ---------------------------------------------------------------------------
## capture
## ---------------------------------------------------------------------------

cmd_capture() {
    local root="" output="" attest="" require_stopped=false capture_redis=false gpg_recipient=""
    local capture_state_fingerprint final_state_fingerprint
    local requested_output output_parent output_basename
    local -a extra_includes=() extra_excludes=()
    while [ $# -gt 0 ]; do
        case "$1" in
            --root) root="$2"; shift 2 ;;
            --output) output="$2"; shift 2 ;;
            --attest-quiesced) attest="$2"; shift 2 ;;
            --require-source-stopped) require_stopped=true; shift ;;
            --include) extra_includes+=("$2"); shift 2 ;;
            --exclude) extra_excludes+=("$2"); shift 2 ;;
            --capture-redis) capture_redis=true; shift ;;
            --gpg-recipient) gpg_recipient="$2"; shift 2 ;;
            *) usage; fail "unknown capture argument: $1" ;;
        esac
    done
    [ -n "$output" ] || { usage; fail "capture requires --output"; }
    root=$(resolve_root "$root")
    [ -z "$attest" ] \
        || fail "schema-v2 capture rejects --attest-quiesced because a running app cannot substantiate the absent control-plane state contract; stop the source and use --require-source-stopped"
    [ "$require_stopped" = true ] \
        || fail "schema-v2 capture is fail-closed: stop the source application and pass --require-source-stopped"
    if container_running "$APP_CONTAINER"; then
        fail "source application container '$APP_CONTAINER' is still running; stop it before the final capture"
    fi
    container_running "$DB_CONTAINER" || fail "database container '$DB_CONTAINER' must be running for the dump"

    db_credentials "$root"
    container_running "$DB_CONTAINER" || fail "database container '$DB_CONTAINER' is not running"
    assert_control_plane_migration_state_absent "$root" "source"
    acquire_control_plane_writer_locks "$root"
    container_running "$APP_CONTAINER" \
        && fail "source application container '$APP_CONTAINER' restarted while the migration fence was being acquired"
    assert_control_plane_writer_locks_held
    assert_control_plane_migration_state_absent "$root" "source after writer-lock acquisition"
    capture_state_fingerprint=$(control_plane_state_fingerprint)

    # Full fail-closed census of the control-plane root.
    local -a trees=()
    local entry name
    for entry in "$root"/*; do
        [ -d "$entry" ] || { log "census: ignoring non-directory entry: ${entry##*/}"; continue; }
        name="${entry##*/}"
        case "$name" in
            control-plane-migrate-*|.pre-migration-*) continue ;;
        esac
        # shellcheck disable=SC2086
        if in_word_list "$name" "$PROTECTED_TREES"; then
            log "census: skipping protected tree: $name"
            continue
        fi
        # shellcheck disable=SC2086
        if in_word_list "$name" "$DEFAULT_CAPTURE_TREES"; then
            trees+=("$name")
            continue
        fi
        if list_contains "$name" "${extra_includes[@]+"${extra_includes[@]}"}"; then
            trees+=("$name")
            continue
        fi
        if list_contains "$name" "${extra_excludes[@]+"${extra_excludes[@]}"}"; then
            log "census: excluded by operator decision: $name"
            continue
        fi
        fail "census fail-closed: undecided top-level directory '$name' under $root; pass --include $name or --exclude $name"
    done
    [ "${#trees[@]}" -gt 0 ] || fail "census found no trees to capture under $root"

    requested_output="$output"
    output_parent=$(dirname "$requested_output")
    output_basename=$(basename "$requested_output")
    case "$output_basename" in
        ''|.|..) fail "capture output must name a new child directory" ;;
    esac
    [ ! -e "$requested_output" ] && [ ! -L "$requested_output" ] \
        || fail "output directory already exists (fail-closed): $requested_output"
    require_dir "$output_parent" "capture output parent"
    CAPTURE_STAGING_PARENT="$output_parent"
    CAPTURE_STAGING_PARENT_IDENTITY=$(path_identity_following_symlinks "$output_parent") \
        || fail "capture output parent identity could not be recorded"
    output=$(mktemp -d "$output_parent/.${output_basename}.control-plane-migrate-staging.XXXXXX") \
        || fail "could not create private sibling capture staging directory"
    chmod 0700 "$output"
    CAPTURE_STAGING_OUTPUT="$output"
    CAPTURE_STAGING_OUTPUT_IDENTITY=$(path_identity_following_symlinks "$output") \
        || fail "capture staging output identity could not be recorded"
    printf '%s\n' "$MANIFEST_SCHEMA" > "$output/$CAPTURE_INCOMPLETE_MARKER"
    chmod 0600 "$output/$CAPTURE_INCOMPLETE_MARKER"
    trap preserve_incomplete_capture_on_exit EXIT
    assert_capture_staging_output_owned

    # .env snapshot first; the migration is impossible without APP_KEY.
    cp -p "$root/source/.env" "$output/env.source"
    chmod 0600 "$output/env.source"
    [ -n "$(get_env_var APP_KEY "$output/env.source")" ] || fail "source .env has no usable APP_KEY; encrypted credentials would be unrecoverable"

    # Tree archives with numeric ownership preserved.
    container_running "$APP_CONTAINER" \
        && fail "source application container '$APP_CONTAINER' restarted before the migration snapshot"
    local tree archive
    for tree in "${trees[@]}"; do
        archive="$output/tree-${tree}.tar.gz"
        tar -C "$root" --numeric-owner -czpf "$archive" "$tree" \
            || fail "failed to archive tree: $tree"
        log "captured tree: $tree"
    done

    # PostgreSQL custom-format dump over the container-local trust socket.
    # The password never appears in process arguments or container metadata.
    container_running "$DB_CONTAINER" || fail "database container '$DB_CONTAINER' is not running"
    docker exec "$DB_CONTAINER" pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" -Fc > "$output/postgres.dump" \
        || fail "pg_dump failed"
    local source_pg_major
    source_pg_major=$(pg_server_major)
    verify_dump_readable "$output/postgres.dump" "$source_pg_major"
    log "captured PostgreSQL dump (server major ${source_pg_major})"

    # Redis is not captured by default: queue/cache state must not resurrect
    # drained jobs on the target. --capture-redis records an explicit decision.
    local redis_captured=false
    if [ "$capture_redis" = true ]; then
        container_running "$REDIS_CONTAINER" || fail "redis container '$REDIS_CONTAINER' is not running"
        local redis_password
        redis_password=$(get_env_var REDIS_PASSWORD "$root/source/.env")
        if [ -n "$redis_password" ]; then
            # AUTH travels over stdin, never argv or container metadata.
            printf 'AUTH %s\nBGSAVE\n' "$redis_password" | docker exec -i "$REDIS_CONTAINER" redis-cli > /dev/null \
                || fail "redis BGSAVE failed"
        else
            printf 'BGSAVE\n' | docker exec -i "$REDIS_CONTAINER" redis-cli > /dev/null \
                || fail "redis BGSAVE failed"
        fi
        sleep 2
        docker cp "$REDIS_CONTAINER":/data/dump.rdb "$output/redis-dump.rdb" \
            || fail "could not copy redis dump out of $REDIS_CONTAINER"
        chmod 0600 "$output/redis-dump.rdb"
        redis_captured=true
        log "captured Redis RDB (operator-requested)"
    fi

    write_inventory_json "$output/inventory.json"
    assert_control_plane_archive_filesystem_absent "$output" "captured"
    assert_control_plane_writer_locks_held
    assert_control_plane_migration_state_absent "$root" "source after snapshot"
    final_state_fingerprint=$(control_plane_state_fingerprint)
    [ "$final_state_fingerprint" = "$capture_state_fingerprint" ] \
        || fail "source control-plane server rows changed while the migration snapshot was captured; the incomplete archive is invalid"

    # Manifest: machine-checkable contract for verify/restore plus a
    # shell-consumable manifest.env (no secrets).
    local created_at arch hostname_value coolify_version
    created_at=$(utc_now)
    arch=$(uname -m)
    hostname_value="${COOLIFY_MIGRATE_HOSTNAME:-$(hostname)}"
    coolify_version=$(get_env_var COOLIFY_FORK_VERSION "$output/env.source" "unmanaged")
    {
        printf 'MANIFEST_SCHEMA=%s\n' "$MANIFEST_SCHEMA"
        printf 'CREATED_AT_UTC=%s\n' "$created_at"
        printf 'SOURCE_HOSTNAME=%s\n' "$hostname_value"
        printf 'SOURCE_ARCH=%s\n' "$arch"
        printf 'SOURCE_COOLIFY_VERSION=%s\n' "$coolify_version"
        printf 'SOURCE_PG_MAJOR=%s\n' "$source_pg_major"
        printf 'DB_USERNAME=%s\n' "$DB_USERNAME"
        printf 'DB_DATABASE=%s\n' "$DB_DATABASE"
        printf 'REDIS_CAPTURED=%s\n' "$redis_captured"
        printf 'CONTROL_PLANE_STATE_CONTRACT=%s\n' "$SUPPORTED_CONTROL_PLANE_STATE_CONTRACT"
        printf 'TREES="%s"\n' "${trees[*]}"
    } > "$output/manifest.env"
    chmod 0600 "$output/manifest.env"

    {
        printf '{\n'
        printf '  "schema": "%s",\n' "$MANIFEST_SCHEMA"
        printf '  "created_at_utc": "%s",\n' "$created_at"
        printf '  "source": {\n'
        printf '    "hostname": "%s",\n' "$(json_escape "$hostname_value")"
        printf '    "arch": "%s",\n' "$arch"
        printf '    "coolify_version": "%s",\n' "$(json_escape "$coolify_version")"
        printf '    "postgres_major": %s\n' "$source_pg_major"
        printf '  },\n'
        printf '  "quiescence": {\n'
        printf '    "mode": "source-stopped",\n'
        printf '    "attestation": ""\n'
        printf '  },\n'
        printf '  "control_plane_state": { "contract": "%s" },\n' "$SUPPORTED_CONTROL_PLANE_STATE_CONTRACT"
        printf '  "redis": { "captured": %s },\n' "$redis_captured"
        printf '  "database": { "dump": "postgres.dump", "format": "pg-custom", "username": "%s", "database": "%s" },\n' "$DB_USERNAME" "$DB_DATABASE"
        printf '  "env": { "file": "env.source", "app_key_present": true },\n'
        printf '  "trees": [\n'
        local index
        for index in "${!trees[@]}"; do
            tree="${trees[$index]}"
            local policy="replace"
            # shellcheck disable=SC2086
            in_word_list "$tree" "$NEVER_RESTORE_TREES" && policy="never"
            # shellcheck disable=SC2086
            in_word_list "$tree" "$OPT_IN_RESTORE_TREES" && policy="opt-in"
            archive="tree-${tree}.tar.gz"
            printf '    { "name": "%s", "archive": "%s", "restore": "%s", "sha256": "%s", "bytes": %s }%s\n' \
                "$tree" "$archive" "$policy" "$(sha256_file "$output/$archive")" "$(stat -c '%s' "$output/$archive" 2>/dev/null || stat -f '%z' "$output/$archive")" \
                "$([ "$index" -lt $((${#trees[@]} - 1)) ] && printf ',' || true)"
        done
        printf '  ]\n'
        printf '}\n'
    } > "$output/manifest.json"
    chmod 0600 "$output/manifest.json"

    (cd "$output" && sha256sum ./* > SHA256SUMS)
    chmod 0600 "$output/SHA256SUMS"

    if [ -n "$gpg_recipient" ]; then
        local artifact
        for artifact in "$output"/*; do
            [ "${artifact##*.}" = "gpg" ] && continue
            gpg --batch --yes --trust-model always -e -r "$gpg_recipient" -o "${artifact}.gpg" "$artifact" \
                || fail "gpg encryption failed for ${artifact##*/}"
        done
        log "encrypted archive to recipient ${gpg_recipient}; keep the decryption key off-host and away from this archive"
    fi

    assert_control_plane_writer_locks_held
    assert_control_plane_migration_state_absent "$root" "source before archive acceptance"
    final_state_fingerprint=$(control_plane_state_fingerprint)
    [ "$final_state_fingerprint" = "$capture_state_fingerprint" ] \
        || fail "source control-plane server rows changed before archive acceptance; the incomplete archive is invalid"
    container_running "$APP_CONTAINER" \
        && fail "source application container '$APP_CONTAINER' restarted before archive acceptance"
    publish_capture_staging_output "$requested_output"
    trap - EXIT
    output="$requested_output"

    log "capture complete: $output"
    log "inventory: $(tr -d '\n' < "$output/inventory.json")"
}

## ---------------------------------------------------------------------------
## verify
## ---------------------------------------------------------------------------

cmd_verify() {
    local archive=""
    while [ $# -gt 0 ]; do
        case "$1" in
            --archive) archive="$2"; shift 2 ;;
            *) usage; fail "unknown verify argument: $1" ;;
        esac
    done
    [ -n "$archive" ] || { usage; fail "verify requires --archive"; }
    require_dir "$archive" "migration archive"
    require_file "$archive/manifest.env" "archive manifest.env"
    require_file "$archive/manifest.json" "archive manifest.json"
    require_file "$archive/SHA256SUMS" "archive SHA256SUMS"
    require_file "$archive/env.source" "archived env.source"
    require_file "$archive/postgres.dump" "archived postgres.dump"
    require_file "$archive/inventory.json" "archived inventory"
    [ ! -e "$archive/$CAPTURE_INCOMPLETE_MARKER" ] && [ ! -L "$archive/$CAPTURE_INCOMPLETE_MARKER" ] \
        || fail "migration archive is marked incomplete and cannot be verified"

    grep -q "^MANIFEST_SCHEMA=${MANIFEST_SCHEMA}\$" "$archive/manifest.env" \
        || fail "archive schema mismatch; expected $MANIFEST_SCHEMA"
    [ "$(get_env_var CONTROL_PLANE_STATE_CONTRACT "$archive/manifest.env")" = "$SUPPORTED_CONTROL_PLANE_STATE_CONTRACT" ] \
        || fail "archive control-plane state contract must be '$SUPPORTED_CONTROL_PLANE_STATE_CONTRACT'; enrollment and generation migration are unsupported"
    grep -Fq '"control_plane_state": { "contract": "absent" }' "$archive/manifest.json" \
        || fail "archive JSON control-plane state contract must be '$SUPPORTED_CONTROL_PLANE_STATE_CONTRACT'"
    [ -n "$(get_env_var APP_KEY "$archive/env.source")" ] \
        || fail "archived env.source has no usable APP_KEY"

    (cd "$archive" && sha256sum -c SHA256SUMS >/dev/null) \
        || fail "archive checksum verification failed"

    local tree major
    # shellcheck disable=SC2034
    while IFS= read -r tree; do
        [ -n "$tree" ] || continue
        tar -tzf "$archive/tree-${tree}.tar.gz" > /dev/null 2>&1 \
            || fail "tree archive is corrupt: tree-${tree}.tar.gz"
    done < <(get_env_var TREES "$archive/manifest.env" | tr ' ' '\n')
    assert_control_plane_archive_filesystem_absent "$archive" "archive"

    major=$(get_env_var SOURCE_PG_MAJOR "$archive/manifest.env")
    verify_dump_readable "$archive/postgres.dump" "$major"

    log "archive verified: $archive"
    log "inventory: $(tr -d '\n' < "$archive/inventory.json")"
}

## ---------------------------------------------------------------------------
## env-merge
## ---------------------------------------------------------------------------

cmd_env_merge() {
    local source_env="" target_env="" output="" report=""
    while [ $# -gt 0 ]; do
        case "$1" in
            --source-env) source_env="$2"; shift 2 ;;
            --target-env) target_env="$2"; shift 2 ;;
            --output) output="$2"; shift 2 ;;
            --report) report="$2"; shift 2 ;;
            *) usage; fail "unknown env-merge argument: $1" ;;
        esac
    done
    [ -n "$source_env" ] && [ -n "$target_env" ] && [ -n "$output" ] \
        || { usage; fail "env-merge requires --source-env, --target-env, and --output"; }
    require_file "$source_env" "source .env"
    require_file "$target_env" "target .env"
    [ -n "$report" ] || report="${output}.provenance"

    local key
    for key in $REQUIRED_SOURCE_KEYS; do
        [ -n "$(get_env_var "$key" "$source_env")" ] \
            || fail "source .env is missing required key $key; refusing to merge"
    done

    local tmp
    tmp=$(mktemp "${output}.tmp.XXXXXX")
    chmod 0600 "$tmp"

    # Pass 1: target keys in target order. Source-preserved keys overlay the
    # source value; everything else keeps the target value.
    local value source_value
    : > "$report"
    while IFS= read -r line; do
        case "$line" in
            ''|'#'*) printf '%s\n' "$line" >> "$tmp"; continue ;;
        esac
        key="${line%%=*}"
        [ "$key" = "$line" ] && { printf '%s\n' "$line" >> "$tmp"; continue; }
        # The source's browser port pin must never cross over, but a fork-deploy
        # managed target requires PUSHER_PORT present exactly once: its environment
        # contract fails closed with "environment field is missing or duplicated"
        # otherwise, leaving reconcile-migrated-state and verify permanently red on
        # every restored host. Retain the target's own value here; the source-side
        # drop below still keeps the pin from being inherited.
        # shellcheck disable=SC2086
        if in_word_list "$key" "$DROPPED_KEYS"; then
            printf '%s\n' "$line" >> "$tmp"
            printf '%s=target(retained-browser-port-pin)\n' "$key" >> "$report"
            continue
        fi
        source_value=$(get_env_var "$key" "$source_env")
        # shellcheck disable=SC2086
        if in_word_list "$key" "$SOURCE_PRESERVED_KEYS" && [ -n "$source_value" ]; then
            printf '%s=%s\n' "$key" "$source_value" >> "$tmp"
            printf '%s=source(preserved)\n' "$key" >> "$report"
        else
            printf '%s\n' "$line" >> "$tmp"
            printf '%s=target(retained)\n' "$key" >> "$report"
        fi
    done < "$target_env"

    # Pass 2: source-only keys. Target-retained keys never cross over; preserved
    # keys are appended; unclassified keys carry the source value and are logged
    # for operator review. The source .env is never copied wholesale.
    while IFS= read -r line <&3; do
        case "$line" in
            ''|'#'*) continue ;;
        esac
        key="${line%%=*}"
        [ "$key" = "$line" ] && continue
        if grep -qE "^${key}=" "$target_env"; then
            continue
        fi
        # shellcheck disable=SC2086
        if in_word_list "$key" "$DROPPED_KEYS"; then
            printf '%s=dropped(browser-port-pin)\n' "$key" >> "$report"
            continue
        fi
        value="${line#*=}"
        value="${value%\"}"
        value="${value#\"}"
        value="${value%\'}"
        value="${value#\'}"
        # shellcheck disable=SC2086
        if in_word_list "$key" "$TARGET_RETAINED_KEYS"; then
            printf '%s=target(retained-absent)\n' "$key" >> "$report"
            continue
        fi
        # shellcheck disable=SC2086
        if in_word_list "$key" "$SOURCE_PRESERVED_KEYS"; then
            printf '%s=%s\n' "$key" "$value" >> "$tmp"
            printf '%s=source(preserved)\n' "$key" >> "$report"
        else
            printf '%s=%s\n' "$key" "$value" >> "$tmp"
            printf '%s=source(unclassified-review)\n' "$key" >> "$report"
        fi
    done 3< "$source_env"

    # Postconditions: merged file must decrypt credentials and reach target infra.
    [ -n "$(get_env_var APP_KEY "$tmp")" ] || fail "merged .env lost APP_KEY"
    [ -n "$(get_env_var DB_PASSWORD "$tmp")" ] || fail "merged .env lost target DB_PASSWORD"
    [ -n "$(get_env_var REDIS_PASSWORD "$tmp")" ] || fail "merged .env lost target REDIS_PASSWORD"
    sort "$tmp" | uniq -d | grep -qE '^[A-Z_]+=' && fail "merged .env contains duplicate keys" || true
    [ -z "$(sort "$tmp" | cut -d= -f1 | uniq -d)" ] || fail "merged .env contains duplicate keys"

    mv "$tmp" "$output"
    chmod 0600 "$output"
    log "env-merge complete: $output (provenance: $report)"
}

## ---------------------------------------------------------------------------
## restore
## ---------------------------------------------------------------------------

prove_release_digest() {
    # Fails unless the running app container provably uses the expected digest.
    local expected="$1" reference digests
    reference=$(docker inspect --format '{{.Config.Image}}' "$APP_CONTAINER") \
        || fail "could not inspect app container '$APP_CONTAINER' for release proof"
    case "$reference" in
        *@"$expected") return 0 ;;
    esac
    digests=$(docker image inspect --format '{{json .RepoDigests}}' "$reference" 2>/dev/null) \
        || fail "could not resolve repo digests for image reference: $reference"
    case "$digests" in
        *"$expected"*) return 0 ;;
    esac
    fail "release proof failed: app image does not resolve to expected digest $expected"
}

resolve_fork_deploy_bin() {
    # Resolves the fork-deploy binary used to reconcile release tracking after a
    # restore. An explicit override always wins. In test mode without an
    # override the binary is treated as unresolved so a fresh checkout never
    # auto-invokes the real release tool; production resolves a sibling script
    # or a PATH entry.
    local candidate
    if [ -n "${COOLIFY_MIGRATE_FORK_DEPLOY_BIN:-}" ]; then
        [ -x "$COOLIFY_MIGRATE_FORK_DEPLOY_BIN" ] || return 1
        printf '%s' "$COOLIFY_MIGRATE_FORK_DEPLOY_BIN"
        return 0
    fi
    if [ "${COOLIFY_MIGRATE_TEST_MODE:-false}" = "true" ]; then
        return 1
    fi
    candidate="$(dirname "$0")/fork-deploy"
    if [ -x "$candidate" ]; then
        printf '%s' "$candidate"
        return 0
    fi
    candidate=$(command -v fork-deploy 2>/dev/null || true)
    if [ -n "$candidate" ]; then
        printf '%s' "$candidate"
        return 0
    fi
    return 1
}

reconcile_fork_deploy_state() {
    # After a successful restore onto a fork-deploy-managed target, re-record the
    # active release's migration fingerprint and rendered-Compose state so
    # fork-deploy update/repair stop refusing on the benign drift a bridge
    # migration introduces. fork-deploy itself proves the running image is the
    # recorded signed release before adopting any drift; this bridge only invokes
    # it. Non-managed targets and hosts without the tool are left untouched.
    local root="$1" bin
    if [ ! -f "$root/fork-deploy/current" ]; then
        log "target is not fork-deploy managed; skipping release-tracking reconciliation"
        return 0
    fi
    if ! bin=$(resolve_fork_deploy_bin); then
        log "fork-deploy tooling not found on this host; after this migration run 'fork-deploy reconcile-migrated-state' to re-record release tracking (migration fingerprint and rendered Compose state)"
        return 0
    fi
    log "reconciling fork-deploy release tracking after the bridge migration: $bin reconcile-migrated-state"
    "$bin" reconcile-migrated-state \
        || fail "fork-deploy reconcile-migrated-state failed; the database was restored but fork-deploy release tracking is not reconciled. Investigate, then re-run 'fork-deploy reconcile-migrated-state' on this host before any fork-deploy update or repair."
    log "fork-deploy release tracking reconciled to the post-migration state"
}

cmd_restore() {
    local root="" archive="" authorize="" expect_version="" expect_digest=""
    local restore_proxy=false enable_workers=false skip_app_start=false target_backup_dir=""
    while [ $# -gt 0 ]; do
        case "$1" in
            --root) root="$2"; shift 2 ;;
            --archive) archive="$2"; shift 2 ;;
            --authorize-overwrite) authorize="$2"; shift 2 ;;
            --expect-fork-version) expect_version="$2"; shift 2 ;;
            --expect-image-digest) expect_digest="$2"; shift 2 ;;
            --restore-proxy) restore_proxy=true; shift ;;
            --enable-workers) enable_workers=true; shift ;;
            --skip-app-start) skip_app_start=true; shift ;;
            --target-backup-dir) target_backup_dir="$2"; shift 2 ;;
            *) usage; fail "unknown restore argument: $1" ;;
        esac
    done
    [ -n "$archive" ] && [ -n "$authorize" ] && [ -n "$expect_version" ] && [ -n "$expect_digest" ] \
        || { usage; fail "restore requires --archive, --authorize-overwrite, --expect-fork-version, and --expect-image-digest"; }
    [ "$restore_proxy" = false ] \
        || fail "--restore-proxy is unsupported by migration schema v2 because replacing the proxy tree would invalidate the held canonical writer-lock inodes; reconstruct or reconcile target proxy state separately after the default restore"
    root=$(resolve_root "$root")
    require_dir "$archive" "migration archive"

    # Overwrite authorization must name the actual target host.
    local hostname_value
    hostname_value="${COOLIFY_MIGRATE_HOSTNAME:-$(hostname)}"
    [ "$authorize" = "$hostname_value" ] \
        || fail "overwrite authorization mismatch: --authorize-overwrite must equal this host's hostname ($hostname_value)"

    # The target must be a managed fork installation; trust/release state is
    # never touched by this tool.
    require_file "$root/fork-deploy/current" "fork-deploy current-release marker"

    cmd_verify --archive "$archive"

    local expected_schema="$MANIFEST_SCHEMA"
    # shellcheck disable=SC1090,SC1091
    . "$archive/manifest.env"
    [ "${MANIFEST_SCHEMA:-}" = "$expected_schema" ] || fail "manifest schema mismatch"
    [ "${CONTROL_PLANE_STATE_CONTRACT:-}" = "$SUPPORTED_CONTROL_PLANE_STATE_CONTRACT" ] \
        || fail "archive control-plane state contract must be '$SUPPORTED_CONTROL_PLANE_STATE_CONTRACT'"
    # shellcheck disable=SC2153  # assigned by the sourced manifest.env
    local source_pg_major="$SOURCE_PG_MAJOR"

    # Target proof: exact signed fork version and immutable image digest.
    local target_env="$root/source/.env"
    require_file "$target_env" "target control-plane .env"
    local target_version
    target_version=$(get_env_var COOLIFY_FORK_VERSION "$target_env")
    [ "$target_version" = "$expect_version" ] \
        || fail "target fork version mismatch: expected $expect_version, found ${target_version:-<unset>}"
    prove_release_digest "$expect_digest"

    # PostgreSQL major gate: a dump restores only into the same or newer major.
    db_credentials "$root"
    container_running "$DB_CONTAINER" || fail "target database container '$DB_CONTAINER' is not running"
    local target_pg_major
    target_pg_major=$(pg_server_major)
    [ "$target_pg_major" -ge "$source_pg_major" ] \
        || fail "PostgreSQL major downgrade refused: source $source_pg_major, target $target_pg_major"
    assert_control_plane_migration_state_absent "$root" "target"

    # Free-space gate: target backup plus extracted trees need headroom.
    local archive_kb available_kb
    archive_kb=$(du -sk "$archive" | awk '{print $1}')
    available_kb=$(df -k "$root" | awk 'NR==2 {print $4}')
    [ "$((available_kb))" -gt "$((archive_kb * 2))" ] \
        || fail "insufficient free space under $root: need > $((archive_kb * 2)) KiB, have $available_kb KiB"

    local stamp
    stamp=$(date +%Y-%m-%d-%H-%M-%S)
    [ -n "$target_backup_dir" ] || target_backup_dir="$root/control-plane-migrate-target-backup-${stamp}"
    [ ! -e "$target_backup_dir" ] && [ ! -L "$target_backup_dir" ] \
        || fail "target backup directory already exists: $target_backup_dir"

    acquire_control_plane_writer_locks "$root"

    # Stop every mutable application process while both canonical remote-writer
    # locks are held. The database and Redis remain available for backup.
    if container_running "$APP_CONTAINER"; then
        docker stop "$APP_CONTAINER" > /dev/null || fail "could not stop app container '$APP_CONTAINER'"
    fi
    if container_running "$LEGACY_REALTIME_CONTAINER"; then
        docker stop "$LEGACY_REALTIME_CONTAINER" > /dev/null || fail "could not stop legacy realtime container"
    fi
    assert_control_plane_writer_locks_held
    assert_control_plane_migration_state_absent "$root" "quiesced target"
    local quiesced_state_fingerprint final_state_fingerprint
    quiesced_state_fingerprint=$(control_plane_state_fingerprint)

    # Back up the quiesced target's current installation before overwriting
    # anything. The backup is intentionally preserved if the final fence fails.
    require_dir "$(dirname "$target_backup_dir")" "target backup parent"
    mkdir "$target_backup_dir" || fail "could not atomically claim target backup directory: $target_backup_dir"
    chmod 0700 "$target_backup_dir"
    local tree
    for tree in $DEFAULT_CAPTURE_TREES; do
        [ -d "$root/$tree" ] || continue
        tar -C "$root" --numeric-owner -czpf "$target_backup_dir/tree-${tree}.tar.gz" "$tree" \
            || fail "target backup failed for tree: $tree"
    done
    cp -p "$target_env" "$target_backup_dir/env.pre-restore"
    chmod 0600 "$target_backup_dir/env.pre-restore"
    docker exec "$DB_CONTAINER" pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" -Fc \
        > "$target_backup_dir/postgres.pre-restore.dump" \
        || fail "target database backup failed: pg_dump of '$DB_DATABASE' in $DB_CONTAINER"
    chmod 0600 "$target_backup_dir/postgres.pre-restore.dump"
    log "target backup complete: $target_backup_dir"

    assert_control_plane_archive_filesystem_absent "$target_backup_dir" "target backup"
    assert_control_plane_writer_locks_held
    assert_control_plane_migration_state_absent "$root" "target immediately before restore mutation"
    final_state_fingerprint=$(control_plane_state_fingerprint)
    [ "$final_state_fingerprint" = "$quiesced_state_fingerprint" ] \
        || fail "target control-plane server rows changed while the quiesced backup was captured; target backup was preserved and restore mutation was refused"

    # Restore trees per manifest policy. 'never' trees are evidence only;
    # schema v2 refuses proxy replacement because it would replace held locks.
    local pre_migration="$root/.pre-migration-${stamp}"
    mkdir -p "$pre_migration"
    # shellcheck disable=SC2034,SC2153  # TREES is assigned by the sourced manifest.env
    for tree in $TREES; do
        case "$tree" in
            source) continue ;;
            proxy)
                [ "$restore_proxy" = true ] || { log "skipping opt-in tree (pass --restore-proxy to restore): proxy"; continue; }
                ;;
        esac
        # shellcheck disable=SC2086
        if in_word_list "$tree" "$PROTECTED_TREES"; then
            fail "manifest names a protected tree; refusing to restore: $tree"
        fi
        if [ -d "$root/$tree" ]; then
            mv "$root/$tree" "$pre_migration/" || fail "could not set aside existing tree: $tree"
        fi
        tar -C "$root" --numeric-owner -xzpf "$archive/tree-${tree}.tar.gz" \
            || fail "failed to restore tree: $tree"
        log "restored tree: $tree"
    done

    # Key-by-key .env merge; the source .env is never copied wholesale.
    local merged_env
    merged_env=$(mktemp "${TMPDIR:-/tmp}/control-plane-migrate-env.XXXXXX")
    cmd_env_merge --source-env "$archive/env.source" --target-env "$target_env" \
        --output "$merged_env" --report "$root/source/.env.provenance"
    if [ "$enable_workers" = true ]; then
        # Set explicitly: the merge retains the target's previous values, and a
        # target that followed this playbook carries false from earlier restores.
        set_env_var HORIZON_ENABLED true "$merged_env"
        set_env_var SCHEDULER_ENABLED true "$merged_env"
        log "WARNING: --enable-workers given; Horizon and the scheduler will run on the target."
        log "WARNING: confirm the source control plane is stopped before exposing this instance."
    else
        set_env_var HORIZON_ENABLED false "$merged_env"
        set_env_var SCHEDULER_ENABLED false "$merged_env"
        log "Horizon and scheduler disabled on the restored target (default; pass --enable-workers during final cutover)"
    fi
    cp "$target_env" "$pre_migration/env.target"
    cat "$merged_env" > "$target_env"
    rm -f "$merged_env"
    chmod 0600 "$target_env"
    chown 9999:root "$target_env" 2>/dev/null || log "note: .env ownership contract (9999:root) not applied (non-Linux or test mode)"

    # Database restore: drop/recreate, then pg_restore over the trust socket.
    pg_admin_query "SELECT 1" > /dev/null || fail "target database is not reachable for restore"
    docker exec "$DB_CONTAINER" psql -U "$DB_USERNAME" -d postgres -q \
        -c "DROP DATABASE IF EXISTS \"${DB_DATABASE}\" WITH (FORCE);" \
        -c "CREATE DATABASE \"${DB_DATABASE}\" OWNER \"${DB_USERNAME}\";" \
        || fail "could not recreate target database ${DB_DATABASE}"
    docker exec -i "$DB_CONTAINER" pg_restore -U "$DB_USERNAME" -d "$DB_DATABASE" --exit-on-error \
        < "$archive/postgres.dump" || fail "pg_restore failed"
    log "database restored into ${DB_DATABASE}"

    # Ownership and permission contracts for restored trees.
    if [ -d "$root/ssh" ]; then
        find "$root/ssh" -type d -exec chmod 0700 {} +
        find "$root/ssh" -type f -exec chmod 0600 {} +
    fi
    if [ "$restore_proxy" = true ] && [ -d "$root/proxy" ]; then
        chown -R root:9999 "$root/proxy" 2>/dev/null \
            || log "note: proxy ownership contract (root:9999) not applied (non-Linux or test mode)"
    fi

    # Start the app and prove database migrations against the restored dump.
    if [ "$skip_app_start" = false ]; then
        docker start "$APP_CONTAINER" > /dev/null || fail "could not start app container '$APP_CONTAINER'"
        local attempts="${COOLIFY_MIGRATE_MIGRATION_ATTEMPTS:-60}"
        local retry_seconds="${COOLIFY_MIGRATE_MIGRATION_RETRY_SECONDS:-5}"
        while [ "$attempts" -gt 0 ]; do
            if docker exec "$APP_CONTAINER" php artisan start:migration; then
                break
            fi
            attempts=$((attempts - 1))
            [ "$attempts" -gt 0 ] || fail "database migrations failed against the restored database"
            sleep "$retry_seconds"
        done
        log "database migrations completed against the restored database"
        verify_effective_app_key "$root/source/.env"
        # Re-record fork-deploy release tracking now that the app is up and the
        # database/env reflect the migrated source. fork-deploy re-proves the
        # running image against the signed release before adopting any drift.
        reconcile_fork_deploy_state "$root"
    else
        log "app start skipped (--skip-app-start); run migrations before serving traffic"
        log "after starting the app, run 'fork-deploy reconcile-migrated-state' to re-record release tracking"
    fi

    # Post-restore inventory for acceptance comparison against the manifest.
    write_inventory_json "$root/source/.inventory-post-restore.json" \
        || log "note: post-restore inventory could not be collected"
    if [ -f "$root/source/.inventory-post-restore.json" ]; then
        log "post-restore inventory: $(tr -d '\n' < "$root/source/.inventory-post-restore.json")"
    fi
    log "manifest inventory:      $(tr -d '\n' < "$archive/inventory.json")"
    assert_control_plane_writer_locks_held
    assert_control_plane_migration_state_absent "$root" "restored target before acceptance"

    cat <<EOF
restore complete.

Next steps (operator):
  1. Compare post-restore inventory against the manifest inventory above.
  2. Validate encrypted credentials decrypt and managed servers are reachable over SSH.
  3. Dogfood with forced DNS resolution for the canonical hostname before any DNS change.
  4. Workers are $( [ "$enable_workers" = true ] && printf 'ENABLED' || printf 'DISABLED' ) on this target.
     There must never be two mutable production control planes.
  5. The pre-migration target state is retained at: $pre_migration
     The pre-restore target backup is at: $target_backup_dir
  6. fork-deploy release tracking is re-recorded automatically when the tool is
     present (see the reconciliation log lines above); otherwise run
     'fork-deploy reconcile-migrated-state' on this host before any fork-deploy
     update or repair.
EOF
}

## ---------------------------------------------------------------------------
## dispatch
## ---------------------------------------------------------------------------

[ $# -gt 0 ] || { usage; exit 1; }
COMMAND="$1"
shift
case "$COMMAND" in
    capture) cmd_capture "$@" ;;
    verify) cmd_verify "$@" ;;
    env-merge) cmd_env_merge "$@" ;;
    inventory)
        root=""
        output=""
        while [ $# -gt 0 ]; do
            case "$1" in
                --root) root="$2"; shift 2 ;;
                --output) output="$2"; shift 2 ;;
                *) usage; fail "unknown inventory argument: $1" ;;
            esac
        done
        root=$(resolve_root "$root")
        db_credentials "$root"
        [ -n "$output" ] || output="${root}/source/.inventory.json"
        write_inventory_json "$output"
        cat "$output"
        ;;
    restore) cmd_restore "$@" ;;
    *) usage; fail "unknown command: $COMMAND" ;;
esac
