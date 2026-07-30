#!/usr/bin/env bash
# Functional contract tests for scripts/control-plane-migrate.sh (fork issue #153).
# All docker, pg_restore, and hostname interactions are stubbed; no network calls.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/../.." && pwd)"
SCRIPT="$ROOT/scripts/control-plane-migrate.sh"
STATE="$(mktemp -d "${TMPDIR:-/tmp}/control-plane-migrate-test.XXXXXX")"
MOCK_BIN="$STATE/bin"
DOCKER_LOG="$STATE/docker.log"
FLOCK_LOG="$STATE/flock.log"
CHOWN_LOG="$STATE/chown.log"

fail() {
  printf 'FAIL: %s\n' "$1" >&2
  exit 1
}

pass() {
  printf 'ok: %s\n' "$1"
}

cleanup() {
  rm -rf "$STATE"
}
trap cleanup EXIT INT TERM

mkdir -p "$MOCK_BIN"
: > "$DOCKER_LOG"
: > "$FLOCK_LOG"
: > "$CHOWN_LOG"

# --- stubs -----------------------------------------------------------------

cat > "$MOCK_BIN/docker" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
printf 'docker:%s\n' "$*" >> "${MOCK_DOCKER_LOG:?}"

next_sequence_value() {
  local sequence="$1" counter_file="$2" fallback="$3" count=0 value
  if [ -z "$sequence" ]; then
    printf '%s\n' "$fallback"
    return
  fi
  if [ -f "$counter_file" ]; then
    IFS= read -r count < "$counter_file"
  fi
  count=$((count + 1))
  printf '%s\n' "$count" > "$counter_file"
  value=$(printf '%s' "$sequence" | cut -d, -f"$count")
  [ -n "$value" ] || value=$(printf '%s' "$sequence" | awk -F, '{print $NF}')
  printf '%s\n' "$value"
}

cmd="${1:-}"; shift || true
case "$cmd" in
  ps)
    printf '%s\n' ${MOCK_RUNNING_CONTAINERS:-}
    ;;
  exec)
    # docker exec [-i] <container> <args...>
    local_i="${1:-}"
    if [ "$local_i" = "-i" ]; then shift; fi
    container="${1:-}"; shift
    case "${1:-}" in
      pg_dump)
        if [ -n "${MOCK_REMOVE_ARTIFACT_ON_PG_DUMP:-}" ]; then
          rm -f -- "$MOCK_REMOVE_ARTIFACT_ON_PG_DUMP"
        fi
        printf 'PGDMP-fake-custom-format\n'
        ;;
      pg_restore)
        cat >/dev/null
        exit "${MOCK_MIGRATION_PG_RESTORE_EXIT:-0}"
        ;;
      psql)
        last="${*: -1}"
        case "$last" in
          'SHOW server_version_num') printf '%s\n' "${MOCK_PG_VERSION_NUM:-150002}" ;;
          'SELECT 1') printf '1\n' ;;
          *coolify-control-plane-migration-state-fingerprint*)
            fingerprint=$(next_sequence_value \
              "${MOCK_CONTROL_PLANE_FINGERPRINT_SEQUENCE:-}" \
              "${MOCK_CONTROL_PLANE_FINGERPRINT_COUNTER_FILE:-/tmp/control-plane-unused-fingerprint-counter}" \
              "${MOCK_CONTROL_PLANE_FINGERPRINT:-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}")
            printf '%s\n' "$fingerprint"
            if [ -n "${MOCK_CREATE_ARTIFACT_ON_FINGERPRINT:-}" ]; then
              printf 'race-artifact\n' > "$MOCK_CREATE_ARTIFACT_ON_FINGERPRINT"
            fi
            ;;
          *"WHERE proxy::jsonb ? 'control_plane_proxy_enrollment'"*)
            if [ "${MOCK_CONTROL_PLANE_ENROLLMENT_NULL_KEY_PRESENT:-false}" = true ]; then
              printf '1\n'
            else
              next_sequence_value \
                "${MOCK_CONTROL_PLANE_ENROLLMENT_COUNT_SEQUENCE:-}" \
                "${MOCK_CONTROL_PLANE_ENROLLMENT_COUNTER_FILE:-/tmp/control-plane-unused-enrollment-counter}" \
                "${MOCK_CONTROL_PLANE_ENROLLMENT_COUNT:-0}"
            fi
            ;;
          *"WHERE proxy::jsonb ? 'control_plane_generation_promotion'"*)
            if [ "${MOCK_CONTROL_PLANE_GENERATION_NULL_KEY_PRESENT:-false}" = true ]; then
              printf '1\n'
            else
              next_sequence_value \
                "${MOCK_CONTROL_PLANE_GENERATION_COUNT_SEQUENCE:-}" \
                "${MOCK_CONTROL_PLANE_GENERATION_COUNTER_FILE:-/tmp/control-plane-unused-generation-counter}" \
                "${MOCK_CONTROL_PLANE_GENERATION_COUNT:-0}"
            fi
            ;;
          'SELECT count(*) FROM '*)
            table="${last#SELECT count(*) FROM }"
            printf '%s\n' "${MOCK_COUNT_users:-3}" >/dev/null
            case "$table" in
              users) printf '%s\n' "${MOCK_COUNT_users:-3}" ;;
              servers) printf '%s\n' "${MOCK_COUNT_servers:-11}" ;;
              applications) printf '%s\n' "${MOCK_COUNT_applications:-42}" ;;
              *) printf '0\n' ;;
            esac
            ;;
          *) exit 0 ;;
        esac
        ;;
      php)
        # php artisan start:migration | php artisan config:show app.key
        if printf '%s' "$*" | grep -q 'config:show'; then
          # Laravel's twoColumnDetail output: blank line, padded value row
          # with trailing spaces, blank line.
          printf '\n  app.key ..................................... %s  \n\n' "${MOCK_EFFECTIVE_APP_KEY:-base64:sourcekey}"
          exit 0
        fi
        exit "${MOCK_MIGRATION_EXIT:-0}"
        ;;
      redis-cli)
        cat >/dev/null
        printf 'Background saving started\n'
        ;;
      *)
        exit 0
        ;;
    esac
    ;;
  run)
    # docker run --rm -i --network none --pull never -v dump:ro postgres:N-alpine pg_restore -l ...
    [ "${MOCK_PG_RESTORE_FAIL:-false}" = true ] && exit 1
    exit 0
    ;;
  inspect)
    # docker inspect --format '{{.Config.Image}}' coolify
    printf '%s\n' "${MOCK_APP_IMAGE_REF:-registry.example.test/williamagh/coolify:4.13.23-fork}"
    ;;
  image)
    # docker image inspect [--format fmt] <ref>
    ref="${*: -1}"
    case "$ref" in
      postgres:*-alpine)
        [ "${MOCK_PG_CLIENT_IMAGE_MISSING:-false}" = true ] && exit 1
        ;;
      *)
        printf '%s\n' "${MOCK_APP_REPO_DIGESTS:-[\"registry.example.test/williamagh/coolify@sha256:expecteddigest\"]}"
        ;;
    esac
    ;;
  stop|start)
    exit 0
    ;;
  cp)
    # docker cp coolify-redis:/data/dump.rdb <dest>
    dest="${*: -1}"
    printf 'RDB-fake\n' > "$dest"
    ;;
  *)
    printf 'unexpected docker arguments: %s\n' "$cmd $*" >&2
    exit 2
    ;;
esac
STUB

cat > "$MOCK_BIN/flock" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
printf 'flock:%s\n' "$*" >> "${MOCK_FLOCK_LOG:?}"
exit 0
STUB

cat > "$MOCK_BIN/stat" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
format="${2:-}"
target="${*: -1}"
if [ -n "${MOCK_STATIC_LOCK_METADATA_PATH:-}" ] \
    && [ "$target" = "$MOCK_STATIC_LOCK_METADATA_PATH" ] \
    && { [ -z "${MOCK_STATIC_LOCK_NORMALIZED_MARKER:-}" ] \
      || [ ! -e "$MOCK_STATIC_LOCK_NORMALIZED_MARKER" ]; }; then
  case "$format" in
    '%u')
      [ -n "${MOCK_STATIC_LOCK_OWNER_UID:-}" ] \
        && { printf '%s\n' "$MOCK_STATIC_LOCK_OWNER_UID"; exit 0; }
      ;;
    '%g')
      [ -n "${MOCK_STATIC_LOCK_GROUP_GID:-}" ] \
        && { printf '%s\n' "$MOCK_STATIC_LOCK_GROUP_GID"; exit 0; }
      ;;
  esac
fi
exec /usr/bin/stat "$@"
STUB

cat > "$MOCK_BIN/chown" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
printf 'chown:%s\n' "$*" >> "${MOCK_CHOWN_LOG:?}"
if [ "${1:-}" = "root:root" ]; then
  case "${2:-}" in
    /dev/fd/8)
      [ -z "${MOCK_STATIC_LOCK_NORMALIZED_MARKER:-}" ] \
        || touch "$MOCK_STATIC_LOCK_NORMALIZED_MARKER"
      if [ -n "${MOCK_SWAP_PROXY_DIRECTORY:-}" ]; then
        /bin/mv "$MOCK_SWAP_PROXY_DIRECTORY" "${MOCK_SWAP_PROXY_DIRECTORY}.swapped-original"
        mkdir "$MOCK_SWAP_PROXY_DIRECTORY"
        chmod 0755 "$MOCK_SWAP_PROXY_DIRECTORY"
      fi
      exit 0
      ;;
    /dev/fd/9)
      exit 0
      ;;
  esac
fi
for candidate in /usr/bin/chown /usr/sbin/chown; do
  if [ -x "$candidate" ]; then
    exec "$candidate" "$@"
  fi
done
exit 127
STUB

cat > "$MOCK_BIN/rename-noreplace" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
source_path="${1:?source path required}"
destination_path="${2:?destination path required}"
if [ -n "${MOCK_PUBLISH_RACE_DESTINATION:-}" ] \
    && [ "$destination_path" = "$MOCK_PUBLISH_RACE_DESTINATION" ]; then
  mkdir "$destination_path"
  printf 'raced-destination\n' > "$destination_path/sentinel"
  exit 17
fi
if [ -e "$destination_path" ] || [ -L "$destination_path" ]; then
  exit 17
fi
/bin/mv "$source_path" "$destination_path"
STUB

cat > "$MOCK_BIN/pg_restore" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
[ "${MOCK_PG_RESTORE_FAIL:-false}" = true ] && exit 1
for arg in "$@"; do
  case "$arg" in
    -*) ;;
    *) [ -f "$arg" ] || exit 1 ;;
  esac
done
printf 'fake archive TOC\n'
STUB

FORK_DEPLOY_STUB="$MOCK_BIN/fork-deploy-stub"
FORK_DEPLOY_STUB_LOG="$STATE/fork-deploy-stub.log"
cat > "$FORK_DEPLOY_STUB" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
printf 'fork-deploy:%s\n' "$*" >> "${FORK_DEPLOY_STUB_LOG:?}"
exit "${FORK_DEPLOY_STUB_EXIT:-0}"
STUB
: > "$FORK_DEPLOY_STUB_LOG"

chmod +x "$MOCK_BIN/docker" "$MOCK_BIN/flock" "$MOCK_BIN/stat" "$MOCK_BIN/chown" \
  "$MOCK_BIN/rename-noreplace" "$MOCK_BIN/pg_restore" "$FORK_DEPLOY_STUB"

run_migrate() {
  COOLIFY_MIGRATE_TEST_MODE=true \
  COOLIFY_MIGRATE_HOSTNAME="target-host.test" \
  COOLIFY_MIGRATE_PG_RESTORE_BIN="${TEST_PG_RESTORE_BIN:-$MOCK_BIN/pg_restore}" \
  COOLIFY_MIGRATE_FLOCK_BIN="$MOCK_BIN/flock" \
  COOLIFY_MIGRATE_RENAME_NOREPLACE_BIN="$MOCK_BIN/rename-noreplace" \
  MOCK_DOCKER_LOG="$DOCKER_LOG" \
  MOCK_FLOCK_LOG="$FLOCK_LOG" \
  MOCK_CHOWN_LOG="$CHOWN_LOG" \
  PATH="$MOCK_BIN:$PATH" \
  bash "$SCRIPT" "$@"
}

expect_fail() {
  local description="$1"; shift
  if run_migrate "$@" > "$STATE/out.log" 2>&1; then
    cat "$STATE/out.log" >&2
    fail "$description: command unexpectedly succeeded"
  fi
}

expect_output_contains() {
  local needle="$1"
  grep -Fq -- "$needle" "$STATE/out.log" || {
    cat "$STATE/out.log" >&2
    fail "expected output to contain: $needle"
  }
}

PRESERVED_STAGING=""
assert_marked_capture_staging_preserved() {
  local requested_output="$1" parent basename
  parent=$(dirname "$requested_output")
  basename=$(basename "$requested_output")
  PRESERVED_STAGING=$(find "$parent" -maxdepth 1 -type d \
    -name ".${basename}.control-plane-migrate-staging.*" -print -quit)
  [ -n "$PRESERVED_STAGING" ] \
    || fail "failed capture must preserve its private sibling staging directory"
  grep -qx 'coolify-control-plane-migration/2' \
    "$PRESERVED_STAGING/.control-plane-migrate.incomplete" \
    || fail "preserved capture staging must retain its schema-matched incomplete marker"
}

# --- fixtures ---------------------------------------------------------------

write_source_env() {
  local path="$1"
  cat > "$path" <<'EOF'
APP_ID=sourceappid
APP_KEY=base64:sourcekey
APP_PREVIOUS_KEYS=base64:oldkey
APP_NAME=Coolify
APP_URL=https://coolify.iocloudhost.net
DB_PASSWORD=source_db_pass
REDIS_PASSWORD=source_redis_pass
PUSHER_APP_ID=src_pusher_id
PUSHER_APP_KEY=src_pusher_key
PUSHER_APP_SECRET=src_pusher_secret
PUSHER_HOST=coolify.iocloudhost.net
PUSHER_PORT=6001
ROOT_USERNAME=admin
REGISTRY_URL=registry.iocloudhost.net
DOCKER_ADDRESS_POOL_BASE=10.0.0.0/8
DOCKER_ADDRESS_POOL_SIZE=24
AUTOUPDATE=true
SOKETI_PORT=6001
UNCLASSIFIED_KEY=carryme
EOF
}

write_target_env() {
  local path="$1"
  cat > "$path" <<'EOF'
APP_ID=targetappid
APP_KEY=base64:targetkey
APP_URL=https://22.haiku.host
DB_PASSWORD=target_db_pass
REDIS_PASSWORD=target_redis_pass
COOLIFY_FORK_VERSION=4.13.23-fork
VERSIONS_URL=http://127.0.0.1:9/fork-deploy-disabled/versions.json
AUTOUPDATE=false
APP_PORT=8000
PUSHER_PORT=6301
PUSHER_BACKEND_HOST=127.0.0.1
PUSHER_BACKEND_PORT=6001
TERMINAL_PORT=6002
HORIZON_ENABLED=false
SCHEDULER_ENABLED=false
EOF
}

# Builds a fake control-plane root with the given top-level trees.
build_root() {
  local root="$1"; shift
  mkdir -p "$root/source"
  for tree in "$@"; do
    mkdir -p "$root/$tree"
    printf 'payload-%s\n' "$tree" > "$root/$tree/payload.txt"
  done
}

# --- env-merge tests ---------------------------------------------------------

test_env_merge_provenance() {
  local work="$STATE/merge1"
  mkdir -p "$work"
  write_source_env "$work/source.env"
  write_target_env "$work/target.env"

  run_migrate env-merge --source-env "$work/source.env" --target-env "$work/target.env" --output "$work/merged.env" \
    || fail "env-merge exited non-zero"

  grep -qx 'APP_KEY=base64:sourcekey' "$work/merged.env" || fail "source APP_KEY must win"
  grep -qx 'APP_PREVIOUS_KEYS=base64:oldkey' "$work/merged.env" || fail "APP_PREVIOUS_KEYS must be preserved"
  grep -qx 'DB_PASSWORD=target_db_pass' "$work/merged.env" || fail "target DB_PASSWORD must win"
  grep -qx 'REDIS_PASSWORD=target_redis_pass' "$work/merged.env" || fail "target REDIS_PASSWORD must win"
  grep -qx 'COOLIFY_FORK_VERSION=4.13.23-fork' "$work/merged.env" || fail "target fork version must be retained"
  grep -qx 'PUSHER_HOST=coolify.iocloudhost.net' "$work/merged.env" || fail "source PUSHER_HOST must be preserved"
  grep -qx 'REGISTRY_URL=registry.iocloudhost.net' "$work/merged.env" || fail "source REGISTRY_URL must be preserved"
  grep -qx 'DOCKER_ADDRESS_POOL_BASE=10.0.0.0/8' "$work/merged.env" || fail "source address pool must be preserved"
  grep -qx 'PUSHER_BACKEND_HOST=127.0.0.1' "$work/merged.env" || fail "target managed runtime must be retained"
  grep -qx 'AUTOUPDATE=false' "$work/merged.env" || fail "target-retained AUTOUPDATE must never cross over from source"
  if grep -q '^SOKETI_PORT=' "$work/merged.env"; then
    fail "source-only SOKETI_PORT must be dropped"
  fi
  grep -qx 'PUSHER_PORT=6301' "$work/merged.env" \
    || fail "a fork-deploy managed target keeps its own PUSHER_PORT (its environment contract requires the key)"
  if [ "$(grep -c '^PUSHER_PORT=' "$work/merged.env")" != 1 ]; then
    fail "PUSHER_PORT must appear exactly once or fork-deploy reconcile/verify fail closed"
  fi
  grep -qx 'UNCLASSIFIED_KEY=carryme' "$work/merged.env" || fail "unclassified source keys carry over with review flag"
  grep -qx 'APP_ID=sourceappid' "$work/merged.env" || fail "source APP_ID must be preserved"

  local report="$work/merged.env.provenance"
  grep -qx 'DB_PASSWORD=target(retained)' "$report" || fail "provenance must record target retention"
  grep -qx 'APP_KEY=source(preserved)' "$report" || fail "provenance must record source preservation"
  grep -qx 'AUTOUPDATE=target(retained)' "$report" || fail "provenance must record overridden target-retained key"
  grep -qx 'SOKETI_PORT=dropped(browser-port-pin)' "$report" || fail "provenance must record dropped source-side port pin"
  grep -qx 'PUSHER_PORT=target(retained-browser-port-pin)' "$report" || fail "provenance must record the retained target-side port pin"
  grep -qx 'UNCLASSIFIED_KEY=source(unclassified-review)' "$report" || fail "provenance must flag unclassified keys"

  [ "$(stat -c '%a' "$work/merged.env" 2>/dev/null || stat -f '%Lp' "$work/merged.env")" = "600" ] \
    || fail "merged .env must be mode 0600"
  pass 'env_merge_provenance'
}

test_env_merge_requires_source_app_key() {
  local work="$STATE/merge2"
  mkdir -p "$work"
  grep -v '^APP_KEY=' "$STATE/merge1/source.env" > "$work/source.env" 2>/dev/null || {
    write_source_env "$work/source.env.full"
    grep -v '^APP_KEY=' "$work/source.env.full" > "$work/source.env"
  }
  write_target_env "$work/target.env"
  expect_fail "env-merge without source APP_KEY" \
    env-merge --source-env "$work/source.env" --target-env "$work/target.env" --output "$work/merged.env"
  expect_output_contains 'missing required key APP_KEY'
  pass 'env_merge_requires_source_app_key'
}

# --- capture tests -----------------------------------------------------------

test_capture_requires_quiescence() {
  local root="$STATE/cap1/root"
  build_root "$root" ssh applications databases services backups source proxy
  write_source_env "$root/source/.env"
  expect_fail "capture without quiescence mode" \
    capture --root "$root" --output "$STATE/cap1/out"
  expect_output_contains 'stop the source application'

  expect_fail "capture with a live quiescence attestation" \
    capture --root "$root" --output "$STATE/cap1/attested-out" --attest-quiesced "test"
  expect_output_contains 'rejects --attest-quiesced'
  pass 'capture_requires_quiescence'
}

test_capture_census_fail_closed() {
  local root="$STATE/cap2/root"
  build_root "$root" ssh applications databases services backups source proxy mystery
  write_source_env "$root/source/.env"
  MOCK_RUNNING_CONTAINERS="coolify-db" expect_fail "capture with undecided tree" \
    capture --root "$root" --output "$STATE/cap2/out" --require-source-stopped
  expect_output_contains "undecided top-level directory 'mystery'"

  MOCK_RUNNING_CONTAINERS="coolify-db" run_migrate capture --root "$root" --output "$STATE/cap2/out" \
    --require-source-stopped --exclude mystery > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "capture with --exclude mystery failed"; }
  pass 'capture_census_fail_closed'
}

test_capture_require_source_stopped() {
  local root="$STATE/cap3/root"
  build_root "$root" ssh source proxy
  write_source_env "$root/source/.env"

  MOCK_RUNNING_CONTAINERS="coolify coolify-db" run_migrate \
    capture --root "$root" --output "$STATE/cap3/out" --require-source-stopped > "$STATE/out.log" 2>&1 \
    && fail "capture must refuse while the app container is running"
  expect_output_contains "still running"

  MOCK_RUNNING_CONTAINERS="coolify-db" run_migrate \
    capture --root "$root" --output "$STATE/cap3/out" --require-source-stopped > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "capture with stopped source failed"; }
  pass 'capture_require_source_stopped'
}

test_capture_refuses_missing_app_key() {
  local root="$STATE/cap4/root"
  build_root "$root" ssh source proxy
  write_source_env "$root/source/.env.full"
  grep -v '^APP_KEY=' "$root/source/.env.full" > "$root/source/.env"
  MOCK_RUNNING_CONTAINERS="coolify-db" expect_fail "capture without APP_KEY" \
    capture --root "$root" --output "$STATE/cap4/out" --require-source-stopped
  expect_output_contains 'APP_KEY'
  [ ! -e "$STATE/cap4/out" ] || fail "failed capture must not publish its requested output"
  assert_marked_capture_staging_preserved "$STATE/cap4/out"
  pass 'capture_refuses_missing_app_key'
}

test_capture_refuses_durable_control_plane_state() {
  local root="$STATE/capstate/root"
  build_root "$root" source proxy
  write_source_env "$root/source/.env"

  MOCK_RUNNING_CONTAINERS="coolify-db" MOCK_CONTROL_PLANE_ENROLLMENT_COUNT=1 \
    expect_fail "capture with durable enrollment state" \
    capture --root "$root" --output "$STATE/capstate/enrollment-out" --require-source-stopped
  expect_output_contains 'control_plane_proxy_enrollment is absent'
  [ ! -e "$STATE/capstate/enrollment-out" ] || fail "enrollment-state refusal must happen before archive creation"

  MOCK_RUNNING_CONTAINERS="coolify-db" MOCK_CONTROL_PLANE_GENERATION_COUNT=1 \
    expect_fail "capture with durable generation-promotion state" \
    capture --root "$root" --output "$STATE/capstate/generation-out" --require-source-stopped
  expect_output_contains 'control_plane_generation_promotion is absent'
  [ ! -e "$STATE/capstate/generation-out" ] || fail "generation-state refusal must happen before archive creation"

  MOCK_RUNNING_CONTAINERS="coolify-db" MOCK_CONTROL_PLANE_ENROLLMENT_NULL_KEY_PRESENT=true \
    expect_fail "capture with a JSON-null durable enrollment key" \
    capture --root "$root" --output "$STATE/capstate/enrollment-null-out" --require-source-stopped
  expect_output_contains 'control_plane_proxy_enrollment is absent'

  MOCK_RUNNING_CONTAINERS="coolify-db" MOCK_CONTROL_PLANE_GENERATION_NULL_KEY_PRESENT=true \
    expect_fail "capture with a JSON-null durable generation key" \
    capture --root "$root" --output "$STATE/capstate/generation-null-out" --require-source-stopped
  expect_output_contains 'control_plane_generation_promotion is absent'

  grep -Fq "SELECT count(*) FROM servers WHERE proxy::jsonb ? 'control_plane_proxy_enrollment'" "$DOCKER_LOG" \
    || fail "enrollment state guard must use PostgreSQL JSONB key-existence semantics"
  grep -Fq "SELECT count(*) FROM servers WHERE proxy::jsonb ? 'control_plane_generation_promotion'" "$DOCKER_LOG" \
    || fail "generation state guard must use PostgreSQL JSONB key-existence semantics"
  if grep -Fq "proxy->>'control_plane_" "$DOCKER_LOG"; then
    fail "state guard must not use value extraction because JSON null still represents a present durable key"
  fi

  pass 'capture_refuses_durable_control_plane_state'
}

test_capture_refuses_managed_control_plane_filesystem_artifacts() {
  local listener_root="$STATE/capfiles/listener-root"
  build_root "$listener_root" source proxy
  write_source_env "$listener_root/source/.env"
  printf 'services: {}\n' > "$listener_root/source/docker-compose.control-plane-listener.yml"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    expect_fail "capture with managed listener override" \
    capture --root "$listener_root" --output "$STATE/capfiles/listener-out" --require-source-stopped
  expect_output_contains 'docker-compose.control-plane-listener.yml'
  [ ! -e "$STATE/capfiles/listener-out" ] || fail "listener-artifact refusal must happen before archive creation"

  local rollback_root="$STATE/capfiles/rollback-root"
  build_root "$rollback_root" source proxy
  write_source_env "$rollback_root/source/.env"
  mkdir "$rollback_root/source/.control-plane-source-override-rollback.operation.completed"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    expect_fail "capture with source-override rollback state" \
    capture --root "$rollback_root" --output "$STATE/capfiles/rollback-out" --require-source-stopped
  expect_output_contains '.control-plane-source-override-rollback.operation.completed'
  [ ! -e "$STATE/capfiles/rollback-out" ] || fail "rollback-state refusal must happen before archive creation"

  local state_root="$STATE/capfiles/state-root"
  build_root "$state_root" source proxy
  write_source_env "$state_root/source/.env"
  mkdir -p "$state_root/proxy/.control-plane-managed-traefik"
  printf '{}\n' > "$state_root/proxy/.control-plane-managed-traefik/authority.json"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    expect_fail "capture with managed enrollment writer state" \
    capture --root "$state_root" --output "$STATE/capfiles/state-out" --require-source-stopped
  expect_output_contains '.control-plane-managed-traefik'
  [ ! -e "$STATE/capfiles/state-out" ] || fail "writer-state refusal must happen before archive creation"

  local symlink_root="$STATE/capfiles/symlink-root"
  build_root "$symlink_root" source proxy
  write_source_env "$symlink_root/source/.env"
  mkdir -p "$STATE/capfiles/symlink-target"
  ln -s "$STATE/capfiles/symlink-target" "$symlink_root/proxy/.control-plane-managed-traefik"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    expect_fail "capture with symlinked enrollment writer state" \
    capture --root "$symlink_root" --output "$STATE/capfiles/symlink-out" --require-source-stopped
  expect_output_contains 'writer state path is a symlink'
  [ ! -e "$STATE/capfiles/symlink-out" ] || fail "symlinked writer-state refusal must happen before archive creation"

  local file_root="$STATE/capfiles/file-root"
  build_root "$file_root" source proxy
  write_source_env "$file_root/source/.env"
  printf '{}\n' > "$file_root/proxy/.control-plane-managed-traefik"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    expect_fail "capture with non-directory enrollment writer state" \
    capture --root "$file_root" --output "$STATE/capfiles/file-out" --require-source-stopped
  expect_output_contains 'managed Traefik state directory must be a non-symlink directory'
  [ ! -e "$STATE/capfiles/file-out" ] || fail "non-directory writer-state refusal must happen before archive creation"

  local unsafe_lock_root="$STATE/capfiles/unsafe-lock-root"
  build_root "$unsafe_lock_root" source proxy
  write_source_env "$unsafe_lock_root/source/.env"
  mkdir "$unsafe_lock_root/proxy/.control-plane-managed-traefik"
  printf '' > "$unsafe_lock_root/proxy/.control-plane-managed-traefik/.coolify.yaml.lock"
  chmod 0600 "$unsafe_lock_root/proxy/.control-plane-managed-traefik/.coolify.yaml.lock"
  ln "$unsafe_lock_root/proxy/.control-plane-managed-traefik/.coolify.yaml.lock" \
    "$unsafe_lock_root/proxy/.control-plane-managed-traefik/lock-hardlink"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    expect_fail "capture with hardlinked managed writer lock" \
    capture --root "$unsafe_lock_root" --output "$STATE/capfiles/unsafe-lock-out" --require-source-stopped
  expect_output_contains 'exactly one hard link'
  [ ! -e "$STATE/capfiles/unsafe-lock-out" ] || fail "unsafe-lock refusal must happen before archive creation"

  local unsafe_root="$STATE/capfiles/unsafe-root"
  build_root "$unsafe_root" source proxy
  write_source_env "$unsafe_root/source/.env"
  chmod 0777 "$unsafe_root"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    expect_fail "capture below a writable Coolify root" \
    capture --root "$unsafe_root" --output "$STATE/capfiles/unsafe-root-out" --require-source-stopped
  expect_output_contains 'Coolify root must not be writable by group or other'

  local unsafe_proxy_root="$STATE/capfiles/unsafe-proxy-root"
  build_root "$unsafe_proxy_root" source proxy
  write_source_env "$unsafe_proxy_root/source/.env"
  chmod 0777 "$unsafe_proxy_root/proxy"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    expect_fail "capture with a writable proxy parent" \
    capture --root "$unsafe_proxy_root" --output "$STATE/capfiles/unsafe-proxy-out" --require-source-stopped
  expect_output_contains 'control-plane proxy directory must not be writable by group or other'

  local static_symlink_root="$STATE/capfiles/static-symlink-root"
  local static_symlink_path="$static_symlink_root/proxy/.control-plane-static-listener-enrollment.lock"
  build_root "$static_symlink_root" source proxy
  write_source_env "$static_symlink_root/source/.env"
  printf '' > "$STATE/capfiles/static-symlink-target"
  chmod 0600 "$STATE/capfiles/static-symlink-target"
  ln -s "$STATE/capfiles/static-symlink-target" "$static_symlink_path"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    expect_fail "capture with a symlinked static writer lock" \
    capture --root "$static_symlink_root" --output "$STATE/capfiles/static-symlink-out" --require-source-stopped
  expect_output_contains 'static listener lock must be a regular non-symlink file'

  local static_hardlink_root="$STATE/capfiles/static-hardlink-root"
  local static_hardlink_path="$static_hardlink_root/proxy/.control-plane-static-listener-enrollment.lock"
  build_root "$static_hardlink_root" source proxy
  write_source_env "$static_hardlink_root/source/.env"
  printf '' > "$static_hardlink_path"
  chmod 0600 "$static_hardlink_path"
  ln "$static_hardlink_path" "$static_hardlink_root/proxy/static-lock-second-link"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    expect_fail "capture with a hardlinked static writer lock" \
    capture --root "$static_hardlink_root" --output "$STATE/capfiles/static-hardlink-out" --require-source-stopped
  expect_output_contains 'static listener lock must have exactly one hard link'

  local static_mode_root="$STATE/capfiles/static-mode-root"
  local static_mode_path="$static_mode_root/proxy/.control-plane-static-listener-enrollment.lock"
  build_root "$static_mode_root" source proxy
  write_source_env "$static_mode_root/source/.env"
  printf '' > "$static_mode_path"
  chmod 0644 "$static_mode_path"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    expect_fail "capture with an unsupported static writer lock mode" \
    capture --root "$static_mode_root" --output "$STATE/capfiles/static-mode-out" --require-source-stopped
  expect_output_contains 'must be root:root mode 0600 or legacy 9999:root mode 0700'

  local static_owner_root="$STATE/capfiles/static-owner-root"
  local static_owner_path="$static_owner_root/proxy/.control-plane-static-listener-enrollment.lock"
  build_root "$static_owner_root" source proxy
  write_source_env "$static_owner_root/source/.env"
  printf '' > "$static_owner_path"
  chmod 0600 "$static_owner_path"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    MOCK_STATIC_LOCK_METADATA_PATH="$static_owner_path" \
    MOCK_STATIC_LOCK_OWNER_UID=4242 \
    MOCK_STATIC_LOCK_GROUP_GID="$(id -g)" \
    expect_fail "capture with an unsupported static writer lock owner" \
    capture --root "$static_owner_root" --output "$STATE/capfiles/static-owner-out" --require-source-stopped
  expect_output_contains 'must be root:root mode 0600 or legacy 9999:root mode 0700'

  local static_group_root="$STATE/capfiles/static-group-root"
  local static_group_path="$static_group_root/proxy/.control-plane-static-listener-enrollment.lock"
  build_root "$static_group_root" source proxy
  write_source_env "$static_group_root/source/.env"
  printf '' > "$static_group_path"
  chmod 0700 "$static_group_path"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    MOCK_STATIC_LOCK_METADATA_PATH="$static_group_path" \
    MOCK_STATIC_LOCK_OWNER_UID=9999 \
    MOCK_STATIC_LOCK_GROUP_GID=4242 \
    expect_fail "capture with an unsupported legacy static writer lock group" \
    capture --root "$static_group_root" --output "$STATE/capfiles/static-group-out" --require-source-stopped
  expect_output_contains 'must be root:root mode 0600 or legacy 9999:root mode 0700'

  local swap_root="$STATE/capfiles/swap-root"
  build_root "$swap_root" source proxy
  write_source_env "$swap_root/source/.env"
  printf '' > "$swap_root/proxy/.control-plane-static-listener-enrollment.lock"
  chmod 0600 "$swap_root/proxy/.control-plane-static-listener-enrollment.lock"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    MOCK_SWAP_PROXY_DIRECTORY="$swap_root/proxy" \
    expect_fail "capture when the proxy directory inode changes under the held static lock" \
    capture --root "$swap_root" --output "$STATE/capfiles/swap-out" --require-source-stopped
  expect_output_contains 'control-plane proxy directory inode changed while migration held its fence'

  local legacy_root="$STATE/capfiles/legacy-root"
  local legacy_static_path="$legacy_root/proxy/.control-plane-static-listener-enrollment.lock"
  local legacy_marker="$STATE/capfiles/legacy-static-normalized"
  local legacy_inode_before legacy_inode_after legacy_static_listing legacy_dynamic_listing
  build_root "$legacy_root" source proxy
  write_source_env "$legacy_root/source/.env"
  printf '' > "$legacy_static_path"
  chmod 0700 "$legacy_static_path"
  legacy_inode_before=$(stat -c '%i' "$legacy_static_path" 2>/dev/null || stat -f '%i' "$legacy_static_path")
  : > "$CHOWN_LOG"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    MOCK_STATIC_LOCK_METADATA_PATH="$legacy_static_path" \
    MOCK_STATIC_LOCK_OWNER_UID=9999 \
    MOCK_STATIC_LOCK_GROUP_GID=0 \
    MOCK_STATIC_LOCK_NORMALIZED_MARKER="$legacy_marker" \
    run_migrate capture --root "$legacy_root" --output "$STATE/capfiles/legacy-out" \
      --require-source-stopped > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "capture must accept and normalize the legacy static lock"; }
  legacy_inode_after=$(stat -c '%i' "$legacy_static_path" 2>/dev/null || stat -f '%i' "$legacy_static_path")
  [ "$legacy_inode_after" = "$legacy_inode_before" ] \
    || fail "legacy static lock normalization must preserve the locked inode"
  [ "$(stat -c '%a' "$legacy_static_path" 2>/dev/null || stat -f '%Lp' "$legacy_static_path")" = 600 ] \
    || fail "legacy static lock normalization must produce mode 0600"
  [ -f "$legacy_marker" ] || fail "legacy static lock normalization must chown the opened FD"
  grep -Fqx 'chown:root:root /dev/fd/8' "$CHOWN_LOG" \
    || fail "legacy static lock normalization must target the opened FD"
  legacy_static_listing=$(tar -tvzf "$STATE/capfiles/legacy-out/tree-proxy.tar.gz" \
    'proxy/.control-plane-static-listener-enrollment.lock')
  legacy_dynamic_listing=$(tar -tvzf "$STATE/capfiles/legacy-out/tree-proxy.tar.gz" \
    'proxy/.control-plane-managed-traefik/.coolify.yaml.lock')
  case "$legacy_static_listing" in
    -rw-------*) ;;
    *) fail "captured proxy archive must record the normalized static lock mode" ;;
  esac
  case "$legacy_dynamic_listing" in
    -rw-------*) ;;
    *) fail "captured proxy archive must record the strict dynamic lock mode" ;;
  esac

  local empty_root="$STATE/capfiles/empty-root"
  build_root "$empty_root" source proxy
  write_source_env "$empty_root/source/.env"
  mkdir -p "$empty_root/proxy/.control-plane-managed-traefik"
  : > "$FLOCK_LOG"

  MOCK_RUNNING_CONTAINERS="coolify-db" run_migrate \
    capture --root "$empty_root" --output "$STATE/capfiles/empty-out" --require-source-stopped \
    > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "capture must accept an empty non-symlink writer-state directory"; }
  grep -qx 'CONTROL_PLANE_STATE_CONTRACT=absent' "$STATE/capfiles/empty-out/manifest.env" \
    || fail "accepted empty writer-state directory must still record the absent-state contract"
  [ -f "$empty_root/proxy/.control-plane-static-listener-enrollment.lock" ] \
    || fail "capture must preserve the canonical static listener lock inode"
  [ -f "$empty_root/proxy/.control-plane-managed-traefik/.coolify.yaml.lock" ] \
    || fail "capture must preserve the canonical managed writer lock inode"
  local static_lock_line dynamic_lock_line
  static_lock_line=$(grep -nFx 'flock:-x 8' "$FLOCK_LOG" | head -n 1 | cut -d: -f1 || true)
  dynamic_lock_line=$(grep -nFx 'flock:-x 9' "$FLOCK_LOG" | head -n 1 | cut -d: -f1 || true)
  [ -n "$static_lock_line" ] && [ -n "$dynamic_lock_line" ] \
    || fail "capture must acquire both canonical control-plane writer locks"
  [ "$static_lock_line" -lt "$dynamic_lock_line" ] \
    || fail "capture must acquire the static listener lock before the managed writer lock"

  pass 'capture_refuses_managed_control_plane_filesystem_artifacts'
}

test_capture_rechecks_state_and_preserves_racy_snapshot() {
  local count_root="$STATE/capraces/count-root"
  local count_counter="$STATE/capraces/enrollment-counter"
  build_root "$count_root" source proxy
  write_source_env "$count_root/source/.env"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    MOCK_CONTROL_PLANE_ENROLLMENT_COUNT_SEQUENCE="0,0,1" \
    MOCK_CONTROL_PLANE_ENROLLMENT_COUNTER_FILE="$count_counter" \
  expect_fail "capture when durable state appears during the snapshot" \
    capture --root "$count_root" --output "$STATE/capraces/count-out" --require-source-stopped
  expect_output_contains 'source after snapshot database contains durable control-plane proxy enrollment state'
  [ ! -e "$STATE/capraces/count-out" ] || fail "racy durable-state capture must not publish its requested output"
  assert_marked_capture_staging_preserved "$STATE/capraces/count-out"

  local fingerprint_root="$STATE/capraces/fingerprint-root"
  local fingerprint_counter="$STATE/capraces/fingerprint-counter"
  build_root "$fingerprint_root" source proxy
  write_source_env "$fingerprint_root/source/.env"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    MOCK_CONTROL_PLANE_FINGERPRINT_SEQUENCE="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa,bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" \
    MOCK_CONTROL_PLANE_FINGERPRINT_COUNTER_FILE="$fingerprint_counter" \
  expect_fail "capture when a server row changes and returns to absent state" \
    capture --root "$fingerprint_root" --output "$STATE/capraces/fingerprint-out" --require-source-stopped
  expect_output_contains 'server rows changed while the migration snapshot was captured'
  [ ! -e "$STATE/capraces/fingerprint-out" ] || fail "racy fingerprint capture must not publish its requested output"
  assert_marked_capture_staging_preserved "$STATE/capraces/fingerprint-out"

  local archive_root="$STATE/capraces/archive-root"
  local transient_listener="$archive_root/source/docker-compose.control-plane-listener.yml"
  build_root "$archive_root" source proxy
  write_source_env "$archive_root/source/.env"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    MOCK_CREATE_ARTIFACT_ON_FINGERPRINT="$transient_listener" \
    MOCK_REMOVE_ARTIFACT_ON_PG_DUMP="$transient_listener" \
  expect_fail "capture whose tree snapshot contains transient listener state" \
    capture --root "$archive_root" --output "$STATE/capraces/archive-out" --require-source-stopped
  expect_output_contains 'source tree archive contains unsupported control-plane enrollment state'
  [ ! -e "$STATE/capraces/archive-out" ] || fail "transient filesystem-state capture must not publish its requested output"
  assert_marked_capture_staging_preserved "$STATE/capraces/archive-out"

  local publish_root="$STATE/capraces/publish-root"
  local publish_output="$STATE/capraces/publish-out"
  build_root "$publish_root" source proxy
  write_source_env "$publish_root/source/.env"

  MOCK_RUNNING_CONTAINERS="coolify-db" \
    MOCK_PUBLISH_RACE_DESTINATION="$publish_output" \
    expect_fail "capture when the requested destination appears during atomic publication" \
    capture --root "$publish_root" --output "$publish_output" --require-source-stopped
  expect_output_contains 'atomic no-replace capture publication failed'
  grep -qx 'raced-destination' "$publish_output/sentinel" \
    || fail "no-replace publication must preserve the raced destination"
  assert_marked_capture_staging_preserved "$publish_output"

  pass 'capture_rechecks_state_and_preserves_racy_snapshot'
}

# Performs a full capture into $STATE/archive; used by verify/restore tests.
perform_full_capture() {
  local root="$STATE/full/root"
  build_root "$root" ssh applications databases services backups source proxy
  write_source_env "$root/source/.env"
  printf 'target-compose-sentinel\n' > "$root/source/docker-compose.yml"
  MOCK_RUNNING_CONTAINERS="coolify-db coolify-redis" run_migrate \
    capture --root "$root" --output "$STATE/archive" --require-source-stopped \
    > "$STATE/out.log" 2>&1 || { cat "$STATE/out.log" >&2; fail "full capture failed"; }
}

test_capture_produces_verifiable_archive() {
  perform_full_capture
  local archive="$STATE/archive"
  for artifact in manifest.env manifest.json SHA256SUMS env.source postgres.dump inventory.json \
      tree-ssh.tar.gz tree-applications.tar.gz tree-databases.tar.gz tree-services.tar.gz \
      tree-backups.tar.gz tree-source.tar.gz tree-proxy.tar.gz; do
    [ -f "$archive/$artifact" ] || fail "archive is missing $artifact"
  done
  grep -qx 'MANIFEST_SCHEMA=coolify-control-plane-migration/2' "$archive/manifest.env" \
    || fail "manifest schema marker missing"
  grep -qx 'CONTROL_PLANE_STATE_CONTRACT=absent' "$archive/manifest.env" \
    || fail "manifest must record the unsupported control-plane state contract as absent"
  grep -q '"control_plane_state": { "contract": "absent" }' "$archive/manifest.json" \
    || fail "JSON manifest must record the unsupported control-plane state contract as absent"
  grep -q '"mode": "source-stopped"' "$archive/manifest.json" \
    || fail "schema-v2 capture must record verified stopped-source quiescence"
  [ ! -e "$archive/.control-plane-migrate.incomplete" ] \
    || fail "an accepted archive must not retain the incomplete marker"
  tar -tzf "$archive/tree-proxy.tar.gz" \
    | grep -qx 'proxy/.control-plane-static-listener-enrollment.lock' \
    || fail "proxy archive must contain the persistent static writer lock"
  tar -tzf "$archive/tree-proxy.tar.gz" \
    | grep -qx 'proxy/.control-plane-managed-traefik/.coolify.yaml.lock' \
    || fail "proxy archive must contain the persistent dynamic writer lock"
  grep -qx 'SOURCE_PG_MAJOR=15' "$archive/manifest.env" || fail "manifest must record source PG major"
  grep -q '"restore": "never"' "$archive/manifest.json" || fail "source tree must be restore-never in manifest"
  grep -q '"restore": "opt-in"' "$archive/manifest.json" || fail "proxy tree must be restore-opt-in in manifest"
  grep -q '"users": 3' "$archive/inventory.json" || fail "inventory must record stubbed user count"
  grep -q '"servers": 11' "$archive/inventory.json" || fail "inventory must record stubbed server count"
  grep -q '"applications": 42' "$archive/inventory.json" || fail "inventory must record stubbed application count"
  if grep -Fq 'source_db_pass' "$archive/manifest.env" "$archive/manifest.json"; then
    fail "manifest must never contain secrets"
  fi
  if grep -Eq 'docker:.*exec.*(-e |--env )' "$DOCKER_LOG"; then
    fail "credentials must never travel via docker exec environment flags"
  fi
  if grep -Eq 'docker:exec.*-a |docker:exec.*--password' "$DOCKER_LOG"; then
    fail "credentials must never travel via process arguments"
  fi

  run_migrate verify --archive "$archive" > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "verify of fresh archive failed"; }
  expect_output_contains 'archive verified'
  pass 'capture_produces_verifiable_archive'
}

test_capture_redis_opt_in_uses_stdin_auth() {
  local root="$STATE/capredis/root"
  build_root "$root" source proxy
  write_source_env "$root/source/.env"
  MOCK_RUNNING_CONTAINERS="coolify-db coolify-redis" run_migrate \
    capture --root "$root" --output "$STATE/capredis/out" --require-source-stopped --capture-redis \
    > "$STATE/out.log" 2>&1 || { cat "$STATE/out.log" >&2; fail "redis capture failed"; }
  [ -f "$STATE/capredis/out/redis-dump.rdb" ] || fail "redis dump missing"
  grep -qx 'REDIS_CAPTURED=true' "$STATE/capredis/out/manifest.env" || fail "manifest must record redis capture"
  if grep -Eq 'docker:exec.*-a |docker:exec.*AUTH' "$DOCKER_LOG"; then
    fail "redis password must never appear in docker exec arguments"
  fi
  pass 'capture_redis_opt_in_uses_stdin_auth'
}

test_capture_live_standby_seed_reduced_guarantee() {
  local root="$STATE/caplive/root"
  build_root "$root" ssh source proxy
  write_source_env "$root/source/.env"
  # A running enrolled production plane: listener override, managed writer
  # state, durable enrollment rows, app container up.
  printf 'services: {}\n' > "$root/source/docker-compose.control-plane-listener.yml"
  mkdir -p "$root/proxy/.control-plane-managed-traefik"
  chmod 0700 "$root/proxy/.control-plane-managed-traefik"
  printf '{}\n' > "$root/proxy/.control-plane-managed-traefik/authority.json"

  MOCK_RUNNING_CONTAINERS="coolify coolify-db" expect_fail "capture with both quiescence modes" \
    capture --root "$root" --output "$STATE/caplive/both-out" --require-source-stopped --live-standby-seed
  expect_output_contains 'exactly one of --require-source-stopped or --live-standby-seed'

  MOCK_RUNNING_CONTAINERS="coolify coolify-db" expect_fail "live capture with a quiescence attestation" \
    capture --root "$root" --output "$STATE/caplive/attest-out" --live-standby-seed --attest-quiesced "test"
  expect_output_contains 'rejects --attest-quiesced'

  MOCK_RUNNING_CONTAINERS="coolify coolify-db" expect_fail "live capture with redis capture" \
    capture --root "$root" --output "$STATE/caplive/redis-out" --live-standby-seed --capture-redis
  expect_output_contains '--capture-redis is refused with --live-standby-seed'

  : > "$DOCKER_LOG"
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" MOCK_CONTROL_PLANE_ENROLLMENT_COUNT=1 run_migrate \
    capture --root "$root" --output "$STATE/live-archive" --live-standby-seed > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "live standby seed capture failed against a running enrolled source"; }
  expect_output_contains 'live-standby-seed capture'
  grep -qx 'CAPTURE_MODE=live-standby-seed' "$STATE/live-archive/manifest.env" \
    || fail "live archive manifest must record the live-standby-seed capture mode"
  grep -qx 'CONTROL_PLANE_STATE_CONTRACT=live-standby-seed-unverified' "$STATE/live-archive/manifest.env" \
    || fail "live archive manifest must record the unverified control-plane state contract"
  grep -q '^LIVE_STANDBY_SEED_GUARANTEE=' "$STATE/live-archive/manifest.env" \
    || fail "live archive manifest must record the reduced guarantee text"
  grep -q '"mode": "live-standby-seed"' "$STATE/live-archive/manifest.json" \
    || fail "live archive JSON manifest must record the live quiescence mode"
  grep -q '"contract": "live-standby-seed-unverified"' "$STATE/live-archive/manifest.json" \
    || fail "live archive JSON manifest must record the unverified state contract"
  tar -tzf "$STATE/live-archive/tree-proxy.tar.gz" \
    | grep -qx 'proxy/.control-plane-static-listener-enrollment.lock' \
    || fail "live archive must still carry the persistent static writer lock"
  tar -tzf "$STATE/live-archive/tree-proxy.tar.gz" \
    | grep -qx 'proxy/.control-plane-managed-traefik/.coolify.yaml.lock' \
    || fail "live archive must still carry the persistent dynamic writer lock"
  if grep -Fq 'docker:stop' "$DOCKER_LOG" || grep -Fq 'docker:start' "$DOCKER_LOG"; then
    fail "live capture must be read-only with respect to source containers"
  fi

  run_migrate verify --archive "$STATE/live-archive" > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "verify of a live standby seed archive failed"; }
  expect_output_contains 'reduced guarantee'
  pass 'capture_live_standby_seed_reduced_guarantee'
}

# --- verify tests ------------------------------------------------------------

test_verify_detects_tampering() {
  cp -R "$STATE/archive" "$STATE/tampered"
  printf 'tampered\n' >> "$STATE/tampered/tree-ssh.tar.gz"
  expect_fail "verify of tampered archive" verify --archive "$STATE/tampered"
  expect_output_contains 'checksum'
  pass 'verify_detects_tampering'
}

test_verify_rejects_incomplete_capture_marker() {
  cp -R "$STATE/archive" "$STATE/incomplete"
  printf 'coolify-control-plane-migration/2\n' > "$STATE/incomplete/.control-plane-migrate.incomplete"
  expect_fail "verify of an incomplete archive" verify --archive "$STATE/incomplete"
  expect_output_contains 'marked incomplete'
  pass 'verify_rejects_incomplete_capture_marker'
}

test_verify_requires_proxy_lock_evidence() {
  cp -R "$STATE/archive" "$STATE/missing-proxy"
  rm "$STATE/missing-proxy/tree-proxy.tar.gz"
  awk '
    /^TREES=/ {
      gsub(/proxy /, "")
      sub(/ proxy"$/, "\"")
      print
      next
    }
    { print }
  ' "$STATE/missing-proxy/manifest.env" > "$STATE/missing-proxy/manifest.env.next"
  mv "$STATE/missing-proxy/manifest.env.next" "$STATE/missing-proxy/manifest.env"
  rm "$STATE/missing-proxy/SHA256SUMS"
  (cd "$STATE/missing-proxy" && sha256sum ./* > SHA256SUMS)
  expect_fail "verify without proxy writer-lock evidence" verify --archive "$STATE/missing-proxy"
  expect_output_contains 'archive proxy tree archive must be a regular non-symlink file'
  pass 'verify_requires_proxy_lock_evidence'
}

test_verify_detects_unreadable_dump() {
  cp -R "$STATE/archive" "$STATE/baddump"
  printf 'not-a-dump\n' > "$STATE/baddump/postgres.dump"
  # Refresh checksums so only dump readability can fail.
  (cd "$STATE/baddump" && for artifact in ./*; do [ "$artifact" = "./SHA256SUMS" ] || sha256sum "$artifact"; done > SHA256SUMS)
  MOCK_PG_RESTORE_FAIL=true expect_fail "verify of unreadable dump" verify --archive "$STATE/baddump"
  expect_output_contains 'not readable'
  pass 'verify_detects_unreadable_dump'
}

test_verify_reports_missing_pg_client_image() {
  TEST_PG_RESTORE_BIN="$STATE/definitely-missing-pg-restore" MOCK_PG_CLIENT_IMAGE_MISSING=true \
    expect_fail "verify without local pg_restore or client image" verify --archive "$STATE/archive"
  expect_output_contains 'is not present locally'
  if grep -Fq 'not readable' "$STATE/out.log"; then
    cat "$STATE/out.log" >&2
    fail "missing client image must not be misreported as an unreadable dump"
  fi
  pass 'verify_reports_missing_pg_client_image'
}

test_verify_rejects_capture_mode_contract_mismatch() {
  cp -R "$STATE/live-archive" "$STATE/mode-mismatch"
  awk '
    /^CAPTURE_MODE=/ { print "CAPTURE_MODE=source-stopped"; next }
    { print }
  ' "$STATE/mode-mismatch/manifest.env" > "$STATE/mode-mismatch/manifest.env.next"
  mv "$STATE/mode-mismatch/manifest.env.next" "$STATE/mode-mismatch/manifest.env"
  rm -f "$STATE/mode-mismatch/SHA256SUMS"
  (cd "$STATE/mode-mismatch" && sha256sum ./* > SHA256SUMS)
  expect_fail "verify of a live archive relabeled as source-stopped" \
    verify --archive "$STATE/mode-mismatch"
  expect_output_contains "control-plane state contract must be 'absent'"

  cp -R "$STATE/live-archive" "$STATE/mode-unknown"
  awk '
    /^CAPTURE_MODE=/ { print "CAPTURE_MODE=warm-fuzzy"; next }
    { print }
  ' "$STATE/mode-unknown/manifest.env" > "$STATE/mode-unknown/manifest.env.next"
  mv "$STATE/mode-unknown/manifest.env.next" "$STATE/mode-unknown/manifest.env"
  rm -f "$STATE/mode-unknown/SHA256SUMS"
  (cd "$STATE/mode-unknown" && sha256sum ./* > SHA256SUMS)
  expect_fail "verify of an unknown capture mode" verify --archive "$STATE/mode-unknown"
  expect_output_contains 'unsupported capture mode in archive manifest: warm-fuzzy'
  pass 'verify_rejects_capture_mode_contract_mismatch'
}

# --- restore tests -----------------------------------------------------------

build_target_root() {
  local root="$1"
  build_root "$root" ssh applications databases services backups source proxy
  mkdir -p "$root/fork-deploy"
  printf '4.13.23-fork\n' > "$root/fork-deploy/current"
  write_target_env "$root/source/.env"
  printf 'target-docker-compose-sentinel\n' > "$root/source/docker-compose.yml"
  for tree in ssh applications databases services backups proxy; do
    printf 'OLD-%s\n' "$tree" > "$root/$tree/payload.txt"
  done
}

restore_args() {
  printf '%s\n' \
    --archive "$STATE/archive" \
    --authorize-overwrite "target-host.test" \
    --expect-fork-version "4.13.23-fork" \
    --expect-image-digest "sha256:expecteddigest"
}

test_restore_refuses_wrong_hostname_authorization() {
  local root="$STATE/res1/root"
  build_target_root "$root"
  expect_fail "restore with wrong authorization hostname" \
    restore --root "$root" --archive "$STATE/archive" \
    --authorize-overwrite "some-other-host" \
    --expect-fork-version "4.13.23-fork" \
    --expect-image-digest "sha256:expecteddigest"
  expect_output_contains 'overwrite authorization mismatch'
  pass 'restore_refuses_wrong_hostname_authorization'
}

test_restore_refuses_unmanaged_target() {
  local root="$STATE/res2/root"
  build_target_root "$root"
  rm -f "$root/fork-deploy/current"
  # shellcheck disable=SC2046
  expect_fail "restore on unmanaged target" \
    restore --root "$root" $(restore_args)
  expect_output_contains 'fork-deploy current-release marker'
  pass 'restore_refuses_unmanaged_target'
}

test_restore_refuses_pg_major_downgrade() {
  local root="$STATE/res3/root"
  build_target_root "$root"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" MOCK_PG_VERSION_NUM=140002     expect_fail "restore with older target PostgreSQL" \
    restore --root "$root" $(restore_args)
  expect_output_contains 'PostgreSQL major downgrade refused'
  pass 'restore_refuses_pg_major_downgrade'
}

test_restore_refuses_digest_mismatch() {
  local root="$STATE/res4/root"
  build_target_root "$root"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    MOCK_APP_REPO_DIGESTS='["registry.example.test/williamagh/coolify@sha256:otherdigest"]' \
    expect_fail "restore with wrong image digest" \
    restore --root "$root" $(restore_args)
  expect_output_contains 'release proof failed'
  pass 'restore_refuses_digest_mismatch'
}

test_restore_refuses_version_mismatch() {
  local root="$STATE/res5/root"
  build_target_root "$root"
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    expect_fail "restore with wrong fork version" \
    restore --root "$root" --archive "$STATE/archive" \
    --authorize-overwrite "target-host.test" \
    --expect-fork-version "9.9.9-fork" \
    --expect-image-digest "sha256:expecteddigest"
  expect_output_contains 'target fork version mismatch'
  pass 'restore_refuses_version_mismatch'
}

assert_restore_guard_precedes_mutation() {
  local root="$1" backup
  if grep -Fq 'docker:stop ' "$DOCKER_LOG"; then
    fail "control-plane state guard must run before stopping target containers"
  fi
  if grep -Fq 'DROP DATABASE' "$DOCKER_LOG" \
      || grep -Fq 'docker:exec -i coolify-db pg_restore ' "$DOCKER_LOG"; then
    fail "control-plane state guard must run before restoring the target database"
  fi
  if grep -Fq 'docker:exec coolify-db pg_dump ' "$DOCKER_LOG"; then
    fail "control-plane state guard must run before backing up the target database"
  fi
  backup=$(find "$root" -maxdepth 1 -name 'control-plane-migrate-target-backup-*' -print -quit)
  [ -z "$backup" ] || fail "control-plane state guard must run before creating a target backup"
  backup=$(find "$root" -maxdepth 1 -name '.pre-migration-*' -print -quit)
  [ -z "$backup" ] || fail "control-plane state guard must run before setting target trees aside"
  grep -qx 'OLD-ssh' "$root/ssh/payload.txt" || fail "control-plane state refusal must leave target trees untouched"
  grep -qx 'APP_KEY=base64:targetkey' "$root/source/.env" || fail "control-plane state refusal must leave target env untouched"
}

assert_restore_post_backup_fence_precedes_mutation() {
  local root="$1" backup
  grep -Fq 'docker:stop coolify' "$DOCKER_LOG" \
    || fail "post-backup fence must be evaluated after the target app is stopped"
  grep -Fq 'docker:exec coolify-db pg_dump ' "$DOCKER_LOG" \
    || fail "post-backup fence must preserve a completed target database backup"
  if grep -Fq 'DROP DATABASE' "$DOCKER_LOG" \
      || grep -Fq 'docker:exec -i coolify-db pg_restore ' "$DOCKER_LOG"; then
    fail "post-backup fence failure must precede database restore mutation"
  fi
  if grep -Fq 'docker:start coolify' "$DOCKER_LOG"; then
    fail "post-backup fence failure must not restart the target app"
  fi
  backup=$(find "$root" -maxdepth 1 -type d -name 'control-plane-migrate-target-backup-*' -print -quit)
  [ -n "$backup" ] || fail "post-backup fence failure must preserve the target backup"
  [ -f "$backup/postgres.pre-restore.dump" ] \
    || fail "post-backup fence failure must preserve the target database dump"
  backup=$(find "$root" -maxdepth 1 -name '.pre-migration-*' -print -quit)
  [ -z "$backup" ] || fail "post-backup fence must fail before setting target trees aside"
  grep -qx 'OLD-ssh' "$root/ssh/payload.txt" \
    || fail "post-backup fence failure must leave target trees untouched"
  grep -qx 'APP_KEY=base64:targetkey' "$root/source/.env" \
    || fail "post-backup fence failure must leave target env untouched"
}

test_restore_refuses_non_absent_archive_control_plane_contract_before_mutation() {
  local archive="$STATE/unsupported-control-plane-archive"
  local root="$STATE/res-contract/root"
  cp -R "$STATE/archive" "$archive"
  awk '
    /^CONTROL_PLANE_STATE_CONTRACT=/ { print "CONTROL_PLANE_STATE_CONTRACT=unsupported"; next }
    { print }
  ' "$archive/manifest.env" > "$archive/manifest.env.next"
  mv "$archive/manifest.env.next" "$archive/manifest.env"
  rm -f "$archive/SHA256SUMS"
  (cd "$archive" && sha256sum ./* > SHA256SUMS)
  build_target_root "$root"
  : > "$DOCKER_LOG"

  MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    expect_fail "restore with non-absent archive control-plane contract" \
    restore --root "$root" --archive "$archive" \
    --authorize-overwrite "target-host.test" \
    --expect-fork-version "4.13.23-fork" \
    --expect-image-digest "sha256:expecteddigest"
  expect_output_contains "control-plane state contract must be 'absent'"
  assert_restore_guard_precedes_mutation "$root"
  pass 'restore_refuses_non_absent_archive_control_plane_contract_before_mutation'
}

test_restore_refuses_target_control_plane_state_before_mutation() {
  local enrollment_root="$STATE/res-state/enrollment-root"
  build_target_root "$enrollment_root"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" MOCK_CONTROL_PLANE_ENROLLMENT_COUNT=1 \
    expect_fail "restore onto target with durable enrollment state" \
    restore --root "$enrollment_root" $(restore_args)
  expect_output_contains 'control_plane_proxy_enrollment is absent'
  assert_restore_guard_precedes_mutation "$enrollment_root"

  local generation_root="$STATE/res-state/generation-root"
  build_target_root "$generation_root"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" MOCK_CONTROL_PLANE_GENERATION_COUNT=1 \
    expect_fail "restore onto target with durable generation-promotion state" \
    restore --root "$generation_root" $(restore_args)
  expect_output_contains 'control_plane_generation_promotion is absent'
  assert_restore_guard_precedes_mutation "$generation_root"

  pass 'restore_refuses_target_control_plane_state_before_mutation'
}

test_restore_refuses_target_managed_control_plane_artifacts_before_mutation() {
  local listener_root="$STATE/res-files/listener-root"
  build_target_root "$listener_root"
  printf 'services: {}\n' > "$listener_root/source/docker-compose.control-plane-listener.yml"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    expect_fail "restore onto target with managed listener override" \
    restore --root "$listener_root" $(restore_args)
  expect_output_contains 'docker-compose.control-plane-listener.yml'
  assert_restore_guard_precedes_mutation "$listener_root"

  local rollback_root="$STATE/res-files/rollback-root"
  build_target_root "$rollback_root"
  mkdir "$rollback_root/source/.control-plane-source-override-rollback.operation.quarantine"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    expect_fail "restore onto target with source-override rollback state" \
    restore --root "$rollback_root" $(restore_args)
  expect_output_contains '.control-plane-source-override-rollback.operation.quarantine'
  assert_restore_guard_precedes_mutation "$rollback_root"

  local state_root="$STATE/res-files/state-root"
  build_target_root "$state_root"
  mkdir -p "$state_root/proxy/.control-plane-managed-traefik"
  printf '{}\n' > "$state_root/proxy/.control-plane-managed-traefik/authority.json"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    expect_fail "restore onto target with managed enrollment writer state" \
    restore --root "$state_root" $(restore_args)
  expect_output_contains '.control-plane-managed-traefik'
  assert_restore_guard_precedes_mutation "$state_root"

  local symlink_root="$STATE/res-files/symlink-root"
  build_target_root "$symlink_root"
  mkdir -p "$STATE/res-files/symlink-target"
  ln -s "$STATE/res-files/symlink-target" "$symlink_root/proxy/.control-plane-managed-traefik"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    expect_fail "restore onto target with symlinked enrollment writer state" \
    restore --root "$symlink_root" $(restore_args)
  expect_output_contains 'writer state path is a symlink'
  assert_restore_guard_precedes_mutation "$symlink_root"

  local file_root="$STATE/res-files/file-root"
  build_target_root "$file_root"
  printf '{}\n' > "$file_root/proxy/.control-plane-managed-traefik"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    expect_fail "restore onto target with non-directory enrollment writer state" \
    restore --root "$file_root" $(restore_args)
  expect_output_contains 'managed Traefik state directory must be a non-symlink directory'
  assert_restore_guard_precedes_mutation "$file_root"

  local empty_root="$STATE/res-files/empty-root"
  build_target_root "$empty_root"
  mkdir -p "$empty_root/proxy/.control-plane-managed-traefik"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" run_migrate \
    restore --root "$empty_root" $(restore_args) > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "restore must accept an empty non-symlink writer-state directory"; }

  pass 'restore_refuses_target_managed_control_plane_artifacts_before_mutation'
}

test_restore_rechecks_quiesced_state_after_backup_before_mutation() {
  local count_root="$STATE/res-races/count-root"
  local count_counter="$STATE/res-races/enrollment-counter"
  build_target_root "$count_root"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    MOCK_CONTROL_PLANE_ENROLLMENT_COUNT_SEQUENCE="0,0,1" \
    MOCK_CONTROL_PLANE_ENROLLMENT_COUNTER_FILE="$count_counter" \
    expect_fail "restore when durable enrollment state appears during target backup" \
    restore --root "$count_root" $(restore_args)
  expect_output_contains 'target immediately before restore mutation database contains durable control-plane proxy enrollment state'
  assert_restore_post_backup_fence_precedes_mutation "$count_root"

  local fingerprint_root="$STATE/res-races/fingerprint-root"
  local fingerprint_counter="$STATE/res-races/fingerprint-counter"
  build_target_root "$fingerprint_root"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    MOCK_CONTROL_PLANE_FINGERPRINT_SEQUENCE="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa,bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" \
    MOCK_CONTROL_PLANE_FINGERPRINT_COUNTER_FILE="$fingerprint_counter" \
    expect_fail "restore when a server row changes during target backup" \
    restore --root "$fingerprint_root" $(restore_args)
  expect_output_contains 'server rows changed while the quiesced backup was captured'
  assert_restore_post_backup_fence_precedes_mutation "$fingerprint_root"

  local archive_root="$STATE/res-races/archive-root"
  local transient_listener="$archive_root/source/docker-compose.control-plane-listener.yml"
  build_target_root "$archive_root"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    MOCK_CREATE_ARTIFACT_ON_FINGERPRINT="$transient_listener" \
    MOCK_REMOVE_ARTIFACT_ON_PG_DUMP="$transient_listener" \
    expect_fail "restore whose target backup contains transient listener state" \
    restore --root "$archive_root" $(restore_args)
  expect_output_contains 'target backup source tree archive contains unsupported control-plane enrollment state'
  assert_restore_post_backup_fence_precedes_mutation "$archive_root"

  pass 'restore_rechecks_quiesced_state_after_backup_before_mutation'
}

test_restore_proxy_is_refused_before_mutation() {
  local root="$STATE/res-proxy/root"
  build_target_root "$root"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    expect_fail "schema-v2 proxy restore" \
    restore --root "$root" $(restore_args) --restore-proxy
  expect_output_contains '--restore-proxy is unsupported by migration schema v2'
  assert_restore_guard_precedes_mutation "$root"
  pass 'restore_proxy_is_refused_before_mutation'
}

test_restore_happy_path_disables_workers_by_default() {
  local root="$STATE/res6/root"
  build_target_root "$root"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db coolify-redis" run_migrate \
    restore --root "$root" $(restore_args) > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "happy-path restore failed"; }

  grep -qx 'payload-ssh' "$root/ssh/payload.txt" || fail "ssh tree must be restored from the archive"
  grep -qx 'payload-applications' "$root/applications/payload.txt" || fail "applications tree must be restored"
  ls -d "$root"/.pre-migration-*/ssh > /dev/null 2>&1 || fail "pre-migration trees must be set aside"
  grep -qx 'OLD-ssh' "$root"/.pre-migration-*/ssh/payload.txt || fail "set-aside tree must keep old payload"
  grep -qx 'target-docker-compose-sentinel' "$root/source/docker-compose.yml" \
    || fail "source tree must never be restored over the managed target"
  grep -qx '4.13.23-fork' "$root/fork-deploy/current" || fail "fork-deploy state must be untouched"
  ls -d "$root"/control-plane-migrate-target-backup-* > /dev/null 2>&1 || fail "target backup must exist"
  grep -qx 'OLD-ssh' "$root"/control-plane-migrate-target-backup-*/ssh/payload.txt 2>/dev/null \
    || tar -tzf "$root"/control-plane-migrate-target-backup-*/tree-ssh.tar.gz > /dev/null \
    || fail "target backup must contain the pre-restore trees"

  grep -qx 'APP_KEY=base64:sourcekey' "$root/source/.env" || fail "restored .env must carry source APP_KEY"
  grep -qx 'DB_PASSWORD=target_db_pass' "$root/source/.env" || fail "restored .env must keep target DB_PASSWORD"
  grep -qx 'COOLIFY_FORK_VERSION=4.13.23-fork' "$root/source/.env" || fail "restored .env must keep fork version"
  grep -qx 'HORIZON_ENABLED=false' "$root/source/.env" || fail "Horizon must be disabled by default"
  grep -qx 'SCHEDULER_ENABLED=false' "$root/source/.env" || fail "scheduler must be disabled by default"
  [ -f "$root/source/.env.provenance" ] || fail "env-merge provenance report must be written"

  grep -Fq 'docker:stop coolify' "$DOCKER_LOG" || fail "restore must stop the app container"
  grep -Fq 'docker:start coolify' "$DOCKER_LOG" || fail "restore must start the app container"
  grep -Fq 'docker:exec coolify php artisan start:migration' "$DOCKER_LOG" \
    || fail "restore must prove database migrations"
  [ -f "$root/source/.inventory-post-restore.json" ] || fail "post-restore inventory must be written"
  expect_output_contains 'restore complete'
  pass 'restore_happy_path_disables_workers_by_default'
}

test_restore_backs_up_target_database_before_overwrite() {
  local root="$STATE/res9/root"
  build_target_root "$root"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db coolify-redis" run_migrate \
    restore --root "$root" $(restore_args) > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "restore for target-db backup test failed"; }
  local backup_dump
  backup_dump=$(find "$root" -type f -path '*/control-plane-migrate-target-backup-*/postgres.pre-restore.dump' 2>/dev/null | head -n 1 || true)
  [ -n "$backup_dump" ] || fail "target backup must contain a pre-restore database dump"
  grep -qx 'PGDMP-fake-custom-format' "$backup_dump" || fail "pre-restore dump must come from pg_dump"
  local dump_line drop_line
  dump_line=$(grep -n 'pg_dump' "$DOCKER_LOG" | head -n 1 | cut -d: -f1 || true)
  drop_line=$(grep -n 'DROP DATABASE' "$DOCKER_LOG" | head -n 1 | cut -d: -f1 || true)
  [ -n "$dump_line" ] || fail "restore must pg_dump the target database before overwrite"
  [ -n "$drop_line" ] || fail "mock log must record the database drop"
  [ "$dump_line" -lt "$drop_line" ] || fail "target database dump must happen before the database is dropped"
  pass 'restore_backs_up_target_database_before_overwrite'
}

test_restore_fails_closed_on_stale_effective_app_key() {
  local root="$STATE/res10/root"
  build_target_root "$root"
  : > "$DOCKER_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db coolify-redis" MOCK_EFFECTIVE_APP_KEY="base64:stale-creation-key" \
    expect_fail "restore with stale effective APP_KEY" restore --root "$root" $(restore_args)
  expect_output_contains 'effective APP_KEY mismatch'
  expect_output_contains 'force-recreate'
  pass 'restore_fails_closed_on_stale_effective_app_key'
}

test_restore_enable_workers_is_explicit() {
  local root="$STATE/res7/root"
  build_target_root "$root"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" run_migrate \
    restore --root "$root" $(restore_args) --enable-workers > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "restore --enable-workers failed"; }
  grep -qx 'HORIZON_ENABLED=true' "$root/source/.env" \
    || fail "--enable-workers must set HORIZON_ENABLED=true even when the target carried false"
  grep -qx 'SCHEDULER_ENABLED=true' "$root/source/.env" \
    || fail "--enable-workers must set SCHEDULER_ENABLED=true even when the target carried false"
  expect_output_contains 'WARNING: --enable-workers given'
  pass 'restore_enable_workers_is_explicit'
}

test_restore_migration_failure_fails_closed() {
  local root="$STATE/res8/root"
  build_target_root "$root"
  # shellcheck disable=SC2046
  MOCK_MIGRATION_EXIT=1 MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    COOLIFY_MIGRATE_MIGRATION_ATTEMPTS=2 COOLIFY_MIGRATE_MIGRATION_RETRY_SECONDS=0 \
    expect_fail "restore with failing migrations" \
    restore --root "$root" $(restore_args)
  expect_output_contains 'database migrations failed'
  pass 'restore_migration_failure_fails_closed'
}

test_restore_reconciles_fork_deploy_managed_target() {
  local root="$STATE/res11/root"
  build_target_root "$root"
  : > "$FORK_DEPLOY_STUB_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db coolify-redis" \
    COOLIFY_MIGRATE_FORK_DEPLOY_BIN="$FORK_DEPLOY_STUB" \
    FORK_DEPLOY_STUB_LOG="$FORK_DEPLOY_STUB_LOG" \
    run_migrate restore --root "$root" $(restore_args) > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "reconcile-managed restore failed"; }
  grep -Fq 'fork-deploy:reconcile-migrated-state' "$FORK_DEPLOY_STUB_LOG" \
    || fail "restore must invoke fork-deploy reconcile-migrated-state on a managed target"
  expect_output_contains 'release tracking reconciled'
  pass 'restore_reconciles_fork_deploy_managed_target'
}

test_restore_skips_reconcile_without_fork_deploy_tooling() {
  local root="$STATE/res12/root"
  build_target_root "$root"
  : > "$FORK_DEPLOY_STUB_LOG"
  # No COOLIFY_MIGRATE_FORK_DEPLOY_BIN: in test mode the binary stays unresolved,
  # so the bridge must not auto-invoke the real release tool.
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db coolify-redis" \
    FORK_DEPLOY_STUB_LOG="$FORK_DEPLOY_STUB_LOG" \
    run_migrate restore --root "$root" $(restore_args) > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "restore without fork-deploy tooling failed"; }
  [ ! -s "$FORK_DEPLOY_STUB_LOG" ] \
    || fail "restore must not invoke fork-deploy when the tool is not resolvable"
  expect_output_contains 'fork-deploy tooling not found'
  expect_output_contains 'restore complete'
  pass 'restore_skips_reconcile_without_fork_deploy_tooling'
}

test_restore_fails_closed_when_reconcile_fails() {
  local root="$STATE/res13/root"
  build_target_root "$root"
  : > "$FORK_DEPLOY_STUB_LOG"
  # shellcheck disable=SC2046
  MOCK_RUNNING_CONTAINERS="coolify coolify-db coolify-redis" \
    COOLIFY_MIGRATE_FORK_DEPLOY_BIN="$FORK_DEPLOY_STUB" \
    FORK_DEPLOY_STUB_LOG="$FORK_DEPLOY_STUB_LOG" \
    FORK_DEPLOY_STUB_EXIT=1 \
    expect_fail "restore with failing reconcile" \
    restore --root "$root" $(restore_args)
  expect_output_contains 'reconcile-migrated-state failed'
  pass 'restore_fails_closed_when_reconcile_fails'
}

test_live_standby_seed_restore_refuses_workers_before_mutation() {
  local root="$STATE/res-live-workers/root"
  build_target_root "$root"
  : > "$DOCKER_LOG"
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    expect_fail "live-seed restore with --enable-workers" \
    restore --root "$root" --archive "$STATE/live-archive" \
    --authorize-overwrite "target-host.test" \
    --expect-fork-version "4.13.23-fork" \
    --expect-image-digest "sha256:expecteddigest" \
    --enable-workers
  expect_output_contains '--enable-workers is refused'
  expect_output_contains 'docs/operations/warm-standby-refresh.md'
  assert_restore_guard_precedes_mutation "$root"
  pass 'live_standby_seed_restore_refuses_workers_before_mutation'
}

test_live_standby_seed_restore_seeds_standby() {
  local root="$STATE/res-live-happy/root"
  build_target_root "$root"
  : > "$DOCKER_LOG"
  # The standby's database may already carry control-plane rows imported by a
  # previous live seed; a second refresh must not be blocked by them.
  MOCK_RUNNING_CONTAINERS="coolify coolify-db coolify-redis" \
    MOCK_CONTROL_PLANE_ENROLLMENT_COUNT=1 \
    run_migrate restore --root "$root" --archive "$STATE/live-archive" \
    --authorize-overwrite "target-host.test" \
    --expect-fork-version "4.13.23-fork" \
    --expect-image-digest "sha256:expecteddigest" > "$STATE/out.log" 2>&1 \
    || { cat "$STATE/out.log" >&2; fail "live standby seed restore failed"; }
  expect_output_contains 'live-standby-seed restore'
  expect_output_contains 'restore complete'
  expect_output_contains 'live-standby-seed notes'
  grep -qx 'HORIZON_ENABLED=false' "$root/source/.env" \
    || fail "live seed restore must leave Horizon disabled"
  grep -qx 'SCHEDULER_ENABLED=false' "$root/source/.env" \
    || fail "live seed restore must leave the scheduler disabled"
  grep -qx 'APP_KEY=base64:sourcekey' "$root/source/.env" \
    || fail "live seed restore must carry the source APP_KEY"
  grep -qx 'target-docker-compose-sentinel' "$root/source/docker-compose.yml" \
    || fail "live seed restore must never restore the source tree over the standby"
  pass 'live_standby_seed_restore_seeds_standby'
}

test_live_standby_seed_restore_keeps_target_filesystem_contract() {
  local root="$STATE/res-live-fs/root"
  build_target_root "$root"
  printf 'services: {}\n' > "$root/source/docker-compose.control-plane-listener.yml"
  : > "$DOCKER_LOG"
  MOCK_RUNNING_CONTAINERS="coolify coolify-db" \
    expect_fail "live-seed restore onto a standby carrying a listener override" \
    restore --root "$root" --archive "$STATE/live-archive" \
    --authorize-overwrite "target-host.test" \
    --expect-fork-version "4.13.23-fork" \
    --expect-image-digest "sha256:expecteddigest"
  expect_output_contains 'docker-compose.control-plane-listener.yml'
  pass 'live_standby_seed_restore_keeps_target_filesystem_contract'
}

# --- runner ------------------------------------------------------------------

test_env_merge_provenance
test_env_merge_requires_source_app_key
test_capture_requires_quiescence
test_capture_census_fail_closed
test_capture_require_source_stopped
test_capture_refuses_missing_app_key
test_capture_refuses_durable_control_plane_state
test_capture_refuses_managed_control_plane_filesystem_artifacts
test_capture_rechecks_state_and_preserves_racy_snapshot
test_capture_produces_verifiable_archive
test_capture_redis_opt_in_uses_stdin_auth
test_capture_live_standby_seed_reduced_guarantee
test_verify_detects_tampering
test_verify_rejects_incomplete_capture_marker
test_verify_requires_proxy_lock_evidence
test_verify_detects_unreadable_dump
test_verify_reports_missing_pg_client_image
test_verify_rejects_capture_mode_contract_mismatch
test_restore_refuses_wrong_hostname_authorization
test_restore_refuses_unmanaged_target
test_restore_refuses_pg_major_downgrade
test_restore_refuses_digest_mismatch
test_restore_refuses_version_mismatch
test_restore_refuses_non_absent_archive_control_plane_contract_before_mutation
test_restore_refuses_target_control_plane_state_before_mutation
test_restore_refuses_target_managed_control_plane_artifacts_before_mutation
test_restore_rechecks_quiesced_state_after_backup_before_mutation
test_restore_proxy_is_refused_before_mutation
test_restore_happy_path_disables_workers_by_default
test_restore_backs_up_target_database_before_overwrite
test_restore_fails_closed_on_stale_effective_app_key
test_restore_enable_workers_is_explicit
test_restore_migration_failure_fails_closed
test_restore_reconciles_fork_deploy_managed_target
test_restore_skips_reconcile_without_fork_deploy_tooling
test_restore_fails_closed_when_reconcile_fails
test_live_standby_seed_restore_refuses_workers_before_mutation
test_live_standby_seed_restore_seeds_standby
test_live_standby_seed_restore_keeps_target_filesystem_contract

printf 'all control-plane-migrate integration tests passed\n'
