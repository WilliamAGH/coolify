#!/usr/bin/env bash

set -Eeuo pipefail

umask 077

readonly REQUIRED_POSTGRES_VERSION=15.18

fail()
{
    printf 'control-plane-backup-capture: %s\n' "$1" >&2
    exit 1
}

usage()
{
    cat >&2 <<'EOF'
Usage: capture.sh \
  --operation-id ID \
  --candidate-image-digest REPOSITORY@sha256:HEX \
  --expected-source-host-identity HOST \
  --database-container CONTAINER --database-user USER --database-name DATABASE \
  --redis-container CONTAINER --redis-port PORT \
  --expected-redis-endpoint HOST:PORT \
  --expected-redis-image-digest REPOSITORY@sha256:HEX \
  --state-root ABSOLUTE_DIRECTORY --state-path TOP_LEVEL_NAME [--state-path ...] \
  --plaintext-tmpfs-root ABSOLUTE_TMPFS_DIRECTORY \
  --quiesce-operator ABSOLUTE_FILE --expected-quiesce-operator-sha256 HEX \
  --quiesce-lease-seconds NUMBER \
  --output-root ABSOLUTE_DIRECTORY --recipient-fingerprint OPENPGP_FINGERPRINT \
  --restore-tool ABSOLUTE_FILE

GNUPGHOME must name a root-owned mode-0700 keyring containing the recipient's
public key and no matching private key. The output root must already be a
root-owned mode-0700 non-symlink directory. The command emits encrypted
ciphertext artifacts only; transfer the four resulting files to an encrypted,
off-host destination and pin the printed capture-manifest SHA-256 out of band.
EOF
    exit 64
}

require_command()
{
    command -v "$1" >/dev/null 2>&1 || fail "required command is unavailable: $1"
}

sha256_file()
{
    sha256sum "$1" | awk '{print $1}'
}

file_size()
{
    stat -c '%s' "$1"
}

file_uid()
{
    stat -c '%u' "$1"
}

file_gid()
{
    stat -c '%g' "$1"
}

file_mode()
{
    stat -c '%a' "$1"
}

assert_no_symlink_components()
{
    local path=$1 current=/ component
    local -a components

    [[ $path == /* ]] || fail 'security-sensitive paths must be absolute'
    IFS='/' read -r -a components <<< "${path#/}"
    for component in "${components[@]}"; do
        [[ -n $component ]] || continue
        if [[ $current == / ]]; then
            current="/$component"
        else
            current="$current/$component"
        fi
        [[ ! -L $current ]] || fail 'security-sensitive path contains a symlink'
    done
}

assert_secure_directory()
{
    local path=$1

    assert_no_symlink_components "$path"
    [[ -d $path && ! -L $path ]] || fail 'required secure directory is unavailable'
    [[ $(file_uid "$path") == 0 && $(file_gid "$path") == 0 ]] \
        || fail 'secure directory must be owned by root:root'
    [[ $(file_mode "$path") == 700 ]] || fail 'secure directory must have mode 0700'
}

assert_tmpfs_directory()
{
    assert_secure_directory "$1"
    [[ $(stat -f -c '%T' "$1") == tmpfs ]] \
        || fail 'plaintext capture work must be on a tmpfs filesystem'
}

assert_root_file()
{
    local path=$1 expected_mode=$2

    assert_no_symlink_components "$path"
    [[ -f $path && ! -L $path ]] || fail 'required regular file is unavailable'
    [[ $(file_uid "$path") == 0 && $(file_gid "$path") == 0 ]] \
        || fail 'security-sensitive file must be owned by root:root'
    [[ $(file_mode "$path") == "$expected_mode" ]] \
        || fail "security-sensitive file must have mode 0${expected_mode}"
}

validate_identifier()
{
    [[ $1 =~ ^[A-Za-z_][A-Za-z0-9_]{0,62}$ ]] || fail "$2 is not a safe identifier"
}

validate_container_name()
{
    [[ $1 =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]] \
        || fail 'database container name is invalid'
}

validate_operation_id()
{
    [[ $1 =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]] || fail 'operation id is invalid'
}

validate_host_identity()
{
    [[ $1 =~ ^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$ ]] || fail 'host identity is invalid'
}

validate_candidate_digest()
{
    local reference=$1 repository last_component digest

    [[ $reference =~ ^[a-z0-9][a-z0-9._:/-]*@sha256:[0-9a-f]{64}$ ]] \
        || fail 'candidate image must be an immutable repository@sha256 digest reference'
    repository=${reference%@sha256:*}
    digest=${reference##*@sha256:}
    last_component=${repository##*/}
    [[ $repository == */* && $last_component != *:* && ${#digest} -eq 64 ]] \
        || fail 'candidate image must not contain a mutable tag'
}

validate_port()
{
    [[ $1 =~ ^[1-9][0-9]{0,4}$ && $1 -le 65535 ]] || fail "$2 is not a valid port"
}

redis_cli()
{
    docker exec "$redis_container" sh -eu -c '
        port=$1
        shift
        exec env REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli --no-auth-warning --raw \
            -h 127.0.0.1 -p "$port" "$@"
    ' sh "$redis_port" "$@"
}

read_redis_identity()
{
    local expected_image_id observed_name

    [[ $(docker inspect --format '{{.State.Running}}' "$redis_container") == true ]] \
        || fail 'source Redis container is not running'
    source_redis_container_id=$(docker inspect --format '{{.Id}}' "$redis_container")
    source_redis_image_id=$(docker inspect --format '{{.Image}}' "$redis_container")
    source_redis_image_reference=$(docker inspect --format '{{.Config.Image}}' "$redis_container")
    observed_name=$(docker inspect --format '{{.Name}}' "$redis_container")
    expected_image_id=$(docker image inspect --format '{{.Id}}' "$expected_redis_image_digest")
    [[ $source_redis_container_id =~ ^[0-9a-f]{64}$ \
        && $source_redis_image_id =~ ^sha256:[0-9a-f]{64}$ \
        && $source_redis_image_id == "$expected_image_id" \
        && $observed_name == "/$redis_container" ]] \
        || fail 'source Redis container or immutable image identity is unexpected'
    source_redis_endpoint="$redis_container:$redis_port"
    [[ $source_redis_endpoint == "$expected_redis_endpoint" ]] \
        || fail 'derived source Redis endpoint differs from its independent pin'
    [[ $(redis_cli PING) == PONG ]] || fail 'source Redis did not authenticate and respond'
    source_redis_version=$(redis_cli INFO server \
        | awk -F: '$1 == "redis_version" { gsub(/\r/, "", $2); print $2; exit }')
    [[ $source_redis_version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] \
        || fail 'source Redis version is invalid'
}

capture_redis_snapshot()
{
    local redis_directory redis_filename redis_changes

    for _ in {1..60}; do
        [[ $(redis_cli INFO persistence \
            | awk -F: '$1 == "rdb_bgsave_in_progress" { gsub(/\r/, "", $2); print $2; exit }') \
            != 1 ]] || { sleep 1; continue; }
        break
    done
    [[ $(redis_cli INFO persistence \
        | awk -F: '$1 == "rdb_bgsave_in_progress" { gsub(/\r/, "", $2); print $2; exit }') \
        == 0 ]] || fail 'source Redis background save did not settle before capture'
    [[ $(redis_cli SAVE) == OK ]] || fail 'source Redis synchronous snapshot failed'
    source_redis_lastsave_unix=$(redis_cli LASTSAVE)
    source_redis_dbsize=$(redis_cli DBSIZE)
    [[ $source_redis_lastsave_unix =~ ^[1-9][0-9]*$ \
        && $source_redis_dbsize =~ ^[0-9]+$ ]] \
        || fail 'source Redis snapshot identity is invalid'
    source_redis_keyspace_sha256=$(redis_cli INFO keyspace | tr -d '\r' \
        | awk -F, '/^db[0-9]+:keys=[0-9]+,expires=[0-9]+/ { print $1 "," $2 }' \
        | LC_ALL=C sort | sha256sum | awk '{print $1}')
    redis_directory=$(redis_cli CONFIG GET dir | sed -n '2p')
    redis_filename=$(redis_cli CONFIG GET dbfilename | sed -n '2p')
    [[ $redis_directory == /* && $redis_directory != *$'\n'* \
        && $redis_filename =~ ^[A-Za-z0-9._-]+$ ]] \
        || fail 'source Redis snapshot path is unsafe'
    docker cp "$redis_container:$redis_directory/$redis_filename" "$redis_plaintext" \
        >/dev/null
    chmod 600 "$redis_plaintext"
    redis_changes=$(redis_cli INFO persistence \
        | awk -F: '$1 == "rdb_changes_since_last_save" { gsub(/\r/, "", $2); print $2; exit }')
    [[ $redis_changes == 0 \
        && $(redis_cli LASTSAVE) == "$source_redis_lastsave_unix" \
        && $(redis_cli DBSIZE) == "$source_redis_dbsize" \
        && $(redis_cli INFO keyspace | tr -d '\r' \
            | awk -F, '/^db[0-9]+:keys=[0-9]+,expires=[0-9]+/ { print $1 "," $2 }' \
            | LC_ALL=C sort | sha256sum | awk '{print $1}') \
            == "$source_redis_keyspace_sha256" ]] \
        || fail 'source Redis changed after its quiesced synchronous snapshot'
}

database_psql()
{
    docker exec "$database_container" psql --no-psqlrc --tuples-only --no-align --quiet \
        --set ON_ERROR_STOP=1 --username "$database_user" --dbname "$database_name" "$@"
}

read_database_identity()
{
    local identity

    identity=$(database_psql --field-separator '|' --command \
        "SELECT current_setting('server_version'), system_identifier::text,
                (SELECT oid::text FROM pg_database WHERE datname = current_database()),
                current_database()
         FROM pg_control_system()" | tr -d '[:space:]')
    IFS='|' read -r source_pg_version source_pg_system_identifier \
        source_pg_database_oid observed_database_name <<< "$identity"
    [[ $source_pg_version == "$REQUIRED_POSTGRES_VERSION" ]] \
        || fail "source PostgreSQL must be exactly ${REQUIRED_POSTGRES_VERSION}"
    [[ $source_pg_system_identifier =~ ^[0-9]+$ ]] \
        || fail 'source PostgreSQL system identifier is invalid'
    [[ $source_pg_database_oid =~ ^[1-9][0-9]*$ ]] \
        || fail 'source PostgreSQL database OID is invalid'
    [[ $observed_database_name == "$database_name" ]] \
        || fail 'source PostgreSQL database name changed'
}

archive_fingerprint()
{
    local section=$1 restrict_key=$2 archive=$3
    local -a section_options

    case $section in
        schema)
            section_options=(--schema-only)
            ;;
        data)
            section_options=(--data-only)
            ;;
        *)
            fail 'unknown PostgreSQL fingerprint section'
            ;;
    esac

    docker exec -i "$database_container" pg_restore --file=- --no-owner --no-privileges \
        "--restrict-key=$restrict_key" "${section_options[@]}" \
        < "$archive" \
        | sha256sum | awk '{print $1}'
}

validate_state_tree()
{
    local selected_path absolute_path discovered_path relative_path discovered_uid discovered_gid

    for selected_path in "${state_paths[@]}"; do
        [[ $selected_path =~ ^[A-Za-z0-9._-]+$ ]] \
            || fail 'state selections must be top-level names without path separators'
        absolute_path="$state_root/$selected_path"
        assert_no_symlink_components "$absolute_path"
        [[ -e $absolute_path && ! -L $absolute_path ]] \
            || fail 'selected control-plane state path is unavailable'
        while IFS= read -r -d '' discovered_path; do
            relative_path=${discovered_path#"$state_root/"}
            [[ $relative_path != *$'\n'* && $relative_path != *$'\r'* \
                && $relative_path != *$'\t'* ]] \
                || fail 'control-plane state contains an unsafe filename'
            [[ ! -L $discovered_path ]] || fail 'control-plane state must not contain symlinks'
            discovered_uid=$(file_uid "$discovered_path")
            discovered_gid=$(file_gid "$discovered_path")
            if [[ -S $discovered_path ]]; then
                [[ $relative_path =~ ^ssh/mux/mux_[a-z0-9]{24}$ \
                    && $discovered_uid == 9999 && $discovered_gid == 9999 \
                    && $(file_mode "$discovered_path") == 600 ]] \
                    || fail 'control-plane state contains an ungoverned socket'
                continue
            fi
            [[ -f $discovered_path || -d $discovered_path ]] \
                || fail 'control-plane state contains an unsupported file type'
            if [[ $discovered_path == "$absolute_path" ]]; then
                [[ $discovered_uid == 9999 && $discovered_gid == 0 \
                    && $(file_mode "$discovered_path") == 700 ]] \
                    || fail 'selected state roots must be service-owned uid 9999, gid 0, mode 0700'
            elif [[ -d $discovered_path ]]; then
                [[ $discovered_uid == 0 || $discovered_uid == 9999 ]] \
                    || fail 'nested state directory owner is not governed'
                [[ $discovered_gid == 0 || $discovered_gid == 9999 ]] \
                    || fail 'nested state directory group is not governed'
                [[ $(file_mode "$discovered_path") == 700 ]] \
                    || fail 'control-plane state directories must have mode 0700'
            else
                [[ $discovered_uid == 0 || $discovered_uid == 9999 ]] \
                    || fail 'nested state file owner is not governed'
                [[ $discovered_gid == 0 || $discovered_gid == 9999 ]] \
                    || fail 'nested state file group is not governed'
                [[ $(file_mode "$discovered_path") == 600 \
                    || $(file_mode "$discovered_path") == 644 ]] \
                    || fail 'control-plane state files must have mode 0600 or 0644'
            fi
        done < <(find "$absolute_path" -print0)
    done
}

write_state_members()
{
    local destination=$1 selected_path member

    : > "$destination"
    for selected_path in "${state_paths[@]}"; do
        while IFS= read -r -d '' member; do
            printf '%s\0' "${member#"$state_root/"}"
        done < <(find "$state_root/$selected_path" \( -type d -o -type f \) -print0)
    done | LC_ALL=C sort -z -u > "$destination"
}

write_state_selection()
{
    local destination=$1 selected_path

    : > "$destination"
    for selected_path in "${state_paths[@]}"; do
        printf '%s\0' "$selected_path" >> "$destination"
    done
}

create_state_archive()
{
    local destination=$1 selection_file=$2

    tar --create --format=gnu --sort=name --mtime='@0' --clamp-mtime \
        --numeric-owner --owner=9999 --group=9999 --mode='u+rwX,go-rwx' \
        --directory "$state_root" --no-recursion --null --files-from "$selection_file" \
        --file "$destination"
    chmod 600 "$destination"
}

encrypt_file()
{
    local plaintext=$1 ciphertext=$2

    gpg --batch --yes --quiet --trust-model always --recipient "$recipient_fingerprint" \
        --output "$ciphertext" --encrypt "$plaintext" 2>/dev/null \
        || fail 'OpenPGP encryption failed'
    chmod 600 "$ciphertext"
}

quiesce_command()
{
    local action=$1 output first second third fourth fifth
    local -a action_arguments

    [[ $(sha256_file "$quiesce_operator") == "$expected_quiesce_operator_sha256" ]] \
        || fail 'canonical backup quiesce operator drifted during capture'
    action_arguments=(backup-quiesce "$action" --operation-id "$operation_id" \
        --fencing-token "$quiesce_fencing_token")
    [[ $action != acquire ]] || action_arguments+=(--lease-seconds "$quiesce_lease_seconds")
    output=$("$quiesce_operator" "${action_arguments[@]}") \
        || fail "canonical backup quiesce $action failed"
    [[ $(wc -l <<< "$output" | tr -d '[:space:]') == 1 ]] \
        || fail "canonical backup quiesce $action emitted extra output"
    IFS=';' read -r first second third fourth fifth <<< "$output"
    [[ $second == "operation_id=$operation_id" \
        && $third == "fencing_token_sha256=$quiesce_fencing_token_sha256" ]] \
        || fail "canonical backup quiesce $action identity proof is invalid"
    case $action in
        acquire)
            [[ $first == backup-quiesce=acquired \
                && $fourth =~ ^lease_acquired_unix=[1-9][0-9]*$ \
                && $fifth =~ ^lease_expires_unix=[1-9][0-9]*$ ]] \
                || fail 'canonical backup quiesce acquire proof is invalid'
            quiesce_lease_acquired_unix=${fourth#*=}
            quiesce_lease_expires_unix=${fifth#*=}
            (( quiesce_lease_expires_unix - quiesce_lease_acquired_unix \
                == quiesce_lease_seconds )) \
                || fail 'canonical backup quiesce lease differs from the explicit request'
            quiesce_active=1
            ;;
        status)
            [[ $first == backup-quiesce=status-passed \
                && $fourth == "lease_expires_unix=$quiesce_lease_expires_unix" \
                && -z $fifth && $(date +%s) -lt $quiesce_lease_expires_unix ]] \
                || fail 'canonical backup quiesce status proof is invalid or expired'
            ;;
        release)
            [[ $first == backup-quiesce=released \
                && $fourth =~ ^released_unix=([1-9][0-9]*)$ && -z $fifth ]] \
                || fail 'canonical backup quiesce release proof is invalid'
            quiesce_released_unix=${BASH_REMATCH[1]}
            (( quiesce_released_unix < quiesce_lease_expires_unix )) \
                || fail 'canonical backup quiesce released after lease expiry'
            quiesce_active=0
            ;;
    esac
}

operation_id=
candidate_image_digest=
expected_source_host_identity=
database_container=
database_user=
database_name=
redis_container=
redis_port=
expected_redis_endpoint=
expected_redis_image_digest=
state_root=
plaintext_tmpfs_root=
output_root=
recipient_fingerprint=
restore_tool=
quiesce_operator=
expected_quiesce_operator_sha256=
quiesce_lease_seconds=
declare -a state_paths=()

while [[ $# -gt 0 ]]; do
    case $1 in
        --operation-id)
            [[ $# -ge 2 ]] || usage
            operation_id=$2
            shift 2
            ;;
        --candidate-image-digest)
            [[ $# -ge 2 ]] || usage
            candidate_image_digest=$2
            shift 2
            ;;
        --expected-source-host-identity)
            [[ $# -ge 2 ]] || usage
            expected_source_host_identity=$2
            shift 2
            ;;
        --database-container)
            [[ $# -ge 2 ]] || usage
            database_container=$2
            shift 2
            ;;
        --database-user)
            [[ $# -ge 2 ]] || usage
            database_user=$2
            shift 2
            ;;
        --database-name)
            [[ $# -ge 2 ]] || usage
            database_name=$2
            shift 2
            ;;
        --redis-container)
            [[ $# -ge 2 ]] || usage
            redis_container=$2
            shift 2
            ;;
        --redis-port)
            [[ $# -ge 2 ]] || usage
            redis_port=$2
            shift 2
            ;;
        --expected-redis-endpoint)
            [[ $# -ge 2 ]] || usage
            expected_redis_endpoint=$2
            shift 2
            ;;
        --expected-redis-image-digest)
            [[ $# -ge 2 ]] || usage
            expected_redis_image_digest=$2
            shift 2
            ;;
        --state-root)
            [[ $# -ge 2 ]] || usage
            state_root=$2
            shift 2
            ;;
        --state-path)
            [[ $# -ge 2 ]] || usage
            state_paths+=("$2")
            shift 2
            ;;
        --plaintext-tmpfs-root)
            [[ $# -ge 2 ]] || usage
            plaintext_tmpfs_root=$2
            shift 2
            ;;
        --output-root)
            [[ $# -ge 2 ]] || usage
            output_root=$2
            shift 2
            ;;
        --recipient-fingerprint)
            [[ $# -ge 2 ]] || usage
            recipient_fingerprint=${2^^}
            shift 2
            ;;
        --restore-tool)
            [[ $# -ge 2 ]] || usage
            restore_tool=$2
            shift 2
            ;;
        --quiesce-operator)
            [[ $# -ge 2 ]] || usage
            quiesce_operator=$2
            shift 2
            ;;
        --expected-quiesce-operator-sha256)
            [[ $# -ge 2 ]] || usage
            expected_quiesce_operator_sha256=$2
            shift 2
            ;;
        --quiesce-lease-seconds)
            [[ $# -ge 2 ]] || usage
            quiesce_lease_seconds=$2
            shift 2
            ;;
        --help|-h)
            usage
            ;;
        *)
            usage
            ;;
    esac
done

[[ $(id -u) == 0 && $(id -g) == 0 ]] || fail 'capture must run as root'
[[ -n $operation_id && -n $candidate_image_digest && -n $expected_source_host_identity \
    && -n $database_container && -n $database_user && -n $database_name \
    && -n $redis_container && -n $redis_port && -n $expected_redis_endpoint \
    && -n $expected_redis_image_digest \
    && -n $state_root && -n $plaintext_tmpfs_root && -n $output_root \
    && -n $recipient_fingerprint \
    && -n $restore_tool && -n $quiesce_operator \
    && -n $expected_quiesce_operator_sha256 && -n $quiesce_lease_seconds \
    && ${#state_paths[@]} -gt 0 ]] || usage

for required_command in awk date docker find gpg hostname openssl readlink sha256sum sleep sort stat sync tar tr wc; do
    require_command "$required_command"
done

validate_operation_id "$operation_id"
validate_candidate_digest "$candidate_image_digest"
validate_host_identity "$expected_source_host_identity"
validate_container_name "$database_container"
validate_identifier "$database_user" 'database user'
validate_identifier "$database_name" 'database name'
validate_container_name "$redis_container"
validate_port "$redis_port" 'Redis port'
validate_candidate_digest "$expected_redis_image_digest"
[[ $expected_redis_endpoint == "$redis_container:$redis_port" ]] \
    || fail 'expected Redis endpoint must exactly name the source container and port'
[[ $recipient_fingerprint =~ ^([A-F0-9]{40}|[A-F0-9]{64})$ ]] \
    || fail 'recipient fingerprint must be a full OpenPGP fingerprint'
[[ $state_root == /* && $plaintext_tmpfs_root == /* && $output_root == /* \
    && $restore_tool == /* && $quiesce_operator == /* ]] \
    || fail 'state, output, and tool paths must be absolute'

assert_no_symlink_components "$state_root"
[[ -d $state_root && ! -L $state_root ]] || fail 'state root must be a non-symlink directory'
assert_secure_directory "$output_root"
assert_tmpfs_directory "$plaintext_tmpfs_root"
assert_root_file "$restore_tool" 755
assert_root_file "$quiesce_operator" 755
[[ $expected_quiesce_operator_sha256 =~ ^[0-9a-f]{64}$ \
    && $(sha256_file "$quiesce_operator") == "$expected_quiesce_operator_sha256" ]] \
    || fail 'canonical backup quiesce operator differs from its independent pin'
[[ $quiesce_lease_seconds =~ ^[1-9][0-9]*$ ]] \
    || fail 'backup quiesce lease seconds must be a positive integer'

script_path=$(readlink -f "${BASH_SOURCE[0]}")
assert_root_file "$script_path" 755
backup_tool_sha256=$(sha256_file "$script_path")
restore_tool_sha256=$(sha256_file "$restore_tool")

gnupg_home=${GNUPGHOME:-}
[[ -n $gnupg_home && $gnupg_home == /* ]] || fail 'GNUPGHOME must be an absolute directory'
assert_secure_directory "$gnupg_home"
primary_fingerprint=$(gpg --batch --with-colons --fingerprint "$recipient_fingerprint" 2>/dev/null \
    | awk -F: '$1 == "fpr" { print toupper($10); exit }')
[[ $primary_fingerprint == "$recipient_fingerprint" ]] \
    || fail 'the exact OpenPGP recipient public key is unavailable'
if gpg --batch --with-colons --list-secret-keys "$recipient_fingerprint" 2>/dev/null \
    | grep -q '^sec:'; then
    fail 'the off-host OpenPGP private key must not exist on the source host'
fi

source_host_identity=$(hostname -f 2>/dev/null || hostname)
source_host_identity=${source_host_identity//$'\n'/}
validate_host_identity "$source_host_identity"
[[ $source_host_identity == "$expected_source_host_identity" ]] \
    || fail 'derived source host identity differs from the expected host'

mapfile -t sorted_state_paths < <(printf '%s\n' "${state_paths[@]}" | LC_ALL=C sort -u)
[[ ${#sorted_state_paths[@]} -eq ${#state_paths[@]} ]] \
    || fail 'control-plane state selections must be unique'
state_paths=("${sorted_state_paths[@]}")
[[ ${state_paths[*]} == 'applications databases services ssh' ]] \
    || fail 'state selection must be exactly applications, databases, services, and ssh'
validate_state_tree

operation_directory="$output_root/$operation_id"
[[ ! -e $operation_directory && ! -L $operation_directory ]] \
    || fail 'capture operation directory already exists'
mkdir "$operation_directory"
chmod 700 "$operation_directory"
assert_secure_directory "$operation_directory"

capture_succeeded=0
cleanup()
{
    if [[ ${quiesce_active:-0} == 1 ]]; then
        "$quiesce_operator" backup-quiesce release --operation-id "$operation_id" \
            --fencing-token "$quiesce_fencing_token" >/dev/null 2>&1 || true
    fi
    [[ -z ${work_directory:-} ]] || rm -rf -- "$work_directory"
    if [[ $capture_succeeded != 1 && -n ${operation_directory:-} \
        && $operation_directory == "$output_root/$operation_id" ]]; then
        rm -rf -- "$operation_directory"
    fi
}
trap cleanup EXIT
trap 'exit 130' HUP INT TERM

work_directory=$(mktemp -d "$plaintext_tmpfs_root/.control-plane-capture-${operation_id}.XXXXXX")
chmod 700 "$work_directory"
selection_file="$work_directory/state-selection"
member_file="$work_directory/state-members"
database_plaintext="$work_directory/database.pgdump"
redis_plaintext="$work_directory/redis.rdb"
state_plaintext="$work_directory/control-plane-state.tar"
state_plaintext_verify="$work_directory/control-plane-state.verify.tar"
database_ciphertext="$operation_directory/database.pgdump.gpg"
redis_ciphertext="$operation_directory/redis.rdb.gpg"
state_ciphertext="$operation_directory/control-plane-state.tar.gpg"
capture_manifest="$operation_directory/capture.manifest"
quiesce_fencing_token=$(openssl rand -hex 32)
[[ $quiesce_fencing_token =~ ^[0-9a-f]{64}$ ]] \
    || fail 'failed to generate backup quiesce fencing token'
quiesce_fencing_token_sha256=$(printf '%s' "$quiesce_fencing_token" | sha256sum | awk '{print $1}')
quiesce_operator_sha256=$(sha256_file "$quiesce_operator")
quiesce_active=0
CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID=$$
readonly CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID
export CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID

capture_started_unix=$(date +%s)
quiesce_command acquire
quiesce_command status
read_database_identity
read_redis_identity
initial_source_pg_version=$source_pg_version
initial_source_pg_system_identifier=$source_pg_system_identifier
initial_source_pg_database_oid=$source_pg_database_oid
initial_source_redis_container_id=$source_redis_container_id
initial_source_redis_image_id=$source_redis_image_id
initial_source_redis_version=$source_redis_version
fingerprint_restrict_key=$(printf '%s' "$operation_id" | sha256sum | awk '{print substr($1, 1, 32)}')

dump_application_name="control-plane-backup-${operation_id:0:40}"
docker exec --env "PGAPPNAME=$dump_application_name" "$database_container" \
    pg_dump --no-password --format=custom --compress=9 \
    --username "$database_user" --dbname "$database_name" > "$database_plaintext"
chmod 600 "$database_plaintext"
docker exec -i "$database_container" pg_restore --list < "$database_plaintext" >/dev/null
database_dump_created_unix=$(date +%s)
database_dump_plaintext_sha256=$(sha256_file "$database_plaintext")
database_dump_plaintext_size_bytes=$(file_size "$database_plaintext")
source_schema_fingerprint_sha256=$(archive_fingerprint schema "$fingerprint_restrict_key" \
    "$database_plaintext")
source_data_fingerprint_sha256=$(archive_fingerprint data "$fingerprint_restrict_key" \
    "$database_plaintext")
quiesce_command status

capture_redis_snapshot
redis_snapshot_created_unix=$(date +%s)
redis_snapshot_plaintext_sha256=$(sha256_file "$redis_plaintext")
redis_snapshot_plaintext_size_bytes=$(file_size "$redis_plaintext")
quiesce_command status

write_state_selection "$selection_file"
write_state_members "$member_file"
source_state_selection_sha256=$(sha256_file "$selection_file")
create_state_archive "$state_plaintext" "$member_file"
control_plane_state_archive_created_unix=$(date +%s)

if [[ ${CONTROL_PLANE_BACKUP_RESTORE_TEST_MODE:-0} == 1 \
    && ${CONTROL_PLANE_BACKUP_RESTORE_TEST_STATE_DRIFT_PATH:-} != '' ]]; then
    drift_path="$state_root/${CONTROL_PLANE_BACKUP_RESTORE_TEST_STATE_DRIFT_PATH}"
    assert_no_symlink_components "$drift_path"
    [[ -f $drift_path ]] || fail 'test drift path is not a regular file'
    printf '%s\n' 'deterministic-test-drift' >> "$drift_path"
elif [[ ${CONTROL_PLANE_BACKUP_RESTORE_TEST_MODE:-0} != 0 \
    || ${CONTROL_PLANE_BACKUP_RESTORE_TEST_STATE_DRIFT_PATH:-} != '' ]]; then
    fail 'backup/restore test fault inputs are invalid outside explicit test mode'
fi

validate_state_tree
write_state_members "$member_file"
create_state_archive "$state_plaintext_verify" "$member_file"
[[ $(sha256_file "$state_plaintext") == $(sha256_file "$state_plaintext_verify") ]] \
    || fail 'control-plane state changed during deterministic capture'
control_plane_state_archive_plaintext_sha256=$(sha256_file "$state_plaintext")
control_plane_state_archive_plaintext_size_bytes=$(file_size "$state_plaintext")
quiesce_command status

read_database_identity
[[ $source_pg_version == "$initial_source_pg_version" \
    && $source_pg_system_identifier == "$initial_source_pg_system_identifier" \
    && $source_pg_database_oid == "$initial_source_pg_database_oid" ]] \
    || fail 'source PostgreSQL identity changed during capture'
read_redis_identity
[[ $source_redis_container_id == "$initial_source_redis_container_id" \
    && $source_redis_image_id == "$initial_source_redis_image_id" \
    && $source_redis_version == "$initial_source_redis_version" \
    && $(redis_cli LASTSAVE) == "$source_redis_lastsave_unix" \
    && $(redis_cli INFO persistence \
        | awk -F: '$1 == "rdb_changes_since_last_save" { gsub(/\r/, "", $2); print $2; exit }') \
        == 0 ]] || fail 'source Redis identity or quiesced generation changed during capture'

encrypt_file "$database_plaintext" "$database_ciphertext"
encrypt_file "$redis_plaintext" "$redis_ciphertext"
encrypt_file "$state_plaintext" "$state_ciphertext"
sync "$database_ciphertext" "$redis_ciphertext" "$state_ciphertext" "$operation_directory"
quiesce_command status
quiesce_command release
database_dump_sha256=$(sha256_file "$database_ciphertext")
database_dump_size_bytes=$(file_size "$database_ciphertext")
redis_snapshot_sha256=$(sha256_file "$redis_ciphertext")
redis_snapshot_size_bytes=$(file_size "$redis_ciphertext")
control_plane_state_archive_sha256=$(sha256_file "$state_ciphertext")
control_plane_state_archive_size_bytes=$(file_size "$state_ciphertext")
capture_completed_unix=$(date +%s)
(( capture_completed_unix < quiesce_lease_expires_unix )) \
    || fail 'capture completion exceeded the explicit backup quiesce lease'

rm -rf -- "$work_directory"
work_directory=

{
    printf 'capture_manifest_version=2\n'
    printf 'operation_id=%s\n' "$operation_id"
    printf 'capture_started_unix=%s\n' "$capture_started_unix"
    printf 'database_dump_created_unix=%s\n' "$database_dump_created_unix"
    printf 'redis_snapshot_created_unix=%s\n' "$redis_snapshot_created_unix"
    printf 'control_plane_state_archive_created_unix=%s\n' "$control_plane_state_archive_created_unix"
    printf 'capture_completed_unix=%s\n' "$capture_completed_unix"
    printf 'source_host_identity=%s\n' "$source_host_identity"
    printf 'source_pg_system_identifier=%s\n' "$source_pg_system_identifier"
    printf 'source_pg_database_oid=%s\n' "$source_pg_database_oid"
    printf 'source_pg_version=%s\n' "$source_pg_version"
    printf 'source_database_name=%s\n' "$database_name"
    printf 'source_redis_container_name=%s\n' "$redis_container"
    printf 'source_redis_container_id=%s\n' "$source_redis_container_id"
    printf 'source_redis_image_id=%s\n' "$source_redis_image_id"
    printf 'source_redis_image_reference=%s\n' "$source_redis_image_reference"
    printf 'source_redis_image_digest=%s\n' "$expected_redis_image_digest"
    printf 'source_redis_endpoint=%s\n' "$source_redis_endpoint"
    printf 'source_redis_version=%s\n' "$source_redis_version"
    printf 'candidate_image_digest=%s\n' "$candidate_image_digest"
    printf 'encryption_recipient_fingerprint=%s\n' "$recipient_fingerprint"
    printf 'database_dump_format=postgresql-custom\n'
    printf 'database_dump_plaintext_sha256=%s\n' "$database_dump_plaintext_sha256"
    printf 'database_dump_plaintext_size_bytes=%s\n' "$database_dump_plaintext_size_bytes"
    printf 'database_dump_sha256=%s\n' "$database_dump_sha256"
    printf 'database_dump_size_bytes=%s\n' "$database_dump_size_bytes"
    printf 'database_dump_encryption=openpgp\n'
    printf 'redis_snapshot_format=redis-rdb\n'
    printf 'redis_snapshot_plaintext_sha256=%s\n' "$redis_snapshot_plaintext_sha256"
    printf 'redis_snapshot_plaintext_size_bytes=%s\n' "$redis_snapshot_plaintext_size_bytes"
    printf 'redis_snapshot_sha256=%s\n' "$redis_snapshot_sha256"
    printf 'redis_snapshot_size_bytes=%s\n' "$redis_snapshot_size_bytes"
    printf 'redis_snapshot_encryption=openpgp\n'
    printf 'source_redis_lastsave_unix=%s\n' "$source_redis_lastsave_unix"
    printf 'source_redis_dbsize=%s\n' "$source_redis_dbsize"
    printf 'source_redis_keyspace_sha256=%s\n' "$source_redis_keyspace_sha256"
    printf 'redis_recovery_semantics=quiesced-exact-rdb-no-worker-replay-v1\n'
    printf 'control_plane_state_archive_format=gnu-tar-deterministic-v2-service-normalized\n'
    printf 'control_plane_state_archive_plaintext_sha256=%s\n' \
        "$control_plane_state_archive_plaintext_sha256"
    printf 'control_plane_state_archive_plaintext_size_bytes=%s\n' \
        "$control_plane_state_archive_plaintext_size_bytes"
    printf 'control_plane_state_archive_sha256=%s\n' "$control_plane_state_archive_sha256"
    printf 'control_plane_state_archive_size_bytes=%s\n' "$control_plane_state_archive_size_bytes"
    printf 'control_plane_state_archive_encryption=openpgp\n'
    printf 'source_schema_fingerprint_sha256=%s\n' "$source_schema_fingerprint_sha256"
    printf 'source_data_fingerprint_sha256=%s\n' "$source_data_fingerprint_sha256"
    printf 'source_state_selection_sha256=%s\n' "$source_state_selection_sha256"
    printf 'quiesce_protocol=control-plane-backup-fenced-v1\n'
    printf 'quiesce_operator_sha256=%s\n' "$quiesce_operator_sha256"
    printf 'quiesce_fencing_token_sha256=%s\n' "$quiesce_fencing_token_sha256"
    printf 'quiesce_lease_requested_seconds=%s\n' "$quiesce_lease_seconds"
    printf 'quiesce_lease_acquired_unix=%s\n' "$quiesce_lease_acquired_unix"
    printf 'quiesce_lease_expires_unix=%s\n' "$quiesce_lease_expires_unix"
    printf 'quiesce_released_unix=%s\n' "$quiesce_released_unix"
    printf 'database_dump_uid=0\n'
    printf 'database_dump_gid=0\n'
    printf 'database_dump_mode=600\n'
    printf 'redis_snapshot_uid=0\n'
    printf 'redis_snapshot_gid=0\n'
    printf 'redis_snapshot_mode=600\n'
    printf 'control_plane_state_archive_uid=0\n'
    printf 'control_plane_state_archive_gid=0\n'
    printf 'control_plane_state_archive_mode=600\n'
    printf 'capture_manifest_uid=0\n'
    printf 'capture_manifest_gid=0\n'
    printf 'capture_manifest_mode=400\n'
    printf 'capture_directory_uid=0\n'
    printf 'capture_directory_gid=0\n'
    printf 'capture_directory_mode=700\n'
    printf 'backup_tool_sha256=%s\n' "$backup_tool_sha256"
    printf 'restore_tool_sha256=%s\n' "$restore_tool_sha256"
} > "$capture_manifest"
capture_manifest_payload_sha256=$(sha256_file "$capture_manifest")
printf 'capture_manifest_payload_sha256=%s\n' "$capture_manifest_payload_sha256" \
    >> "$capture_manifest"
chmod 400 "$capture_manifest"

assert_root_file "$database_ciphertext" 600
assert_root_file "$redis_ciphertext" 600
assert_root_file "$state_ciphertext" 600
assert_root_file "$capture_manifest" 400
sync

capture_manifest_sha256=$(sha256_file "$capture_manifest")
capture_manifest_size_bytes=$(file_size "$capture_manifest")
capture_succeeded=1
trap - EXIT HUP INT TERM
printf 'capture=passed\nsource_capture_manifest_sha256=%s\nsource_capture_manifest_size_bytes=%s\n' \
    "$capture_manifest_sha256" "$capture_manifest_size_bytes"
