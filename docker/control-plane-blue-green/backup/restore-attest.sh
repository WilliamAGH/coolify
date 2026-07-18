#!/usr/bin/env bash

set -Eeuo pipefail

umask 077

readonly REQUIRED_POSTGRES_VERSION=15.18
readonly MANIFEST_KEYS='capture_manifest_version operation_id capture_started_unix database_dump_created_unix redis_snapshot_created_unix control_plane_state_archive_created_unix capture_completed_unix source_host_identity source_pg_system_identifier source_pg_database_oid source_pg_version source_database_name source_redis_container_name source_redis_container_id source_redis_image_id source_redis_image_reference source_redis_image_digest source_redis_endpoint source_redis_version candidate_image_digest encryption_recipient_fingerprint database_dump_format database_dump_plaintext_sha256 database_dump_plaintext_size_bytes database_dump_sha256 database_dump_size_bytes database_dump_encryption redis_snapshot_format redis_snapshot_plaintext_sha256 redis_snapshot_plaintext_size_bytes redis_snapshot_sha256 redis_snapshot_size_bytes redis_snapshot_encryption source_redis_lastsave_unix source_redis_dbsize source_redis_keyspace_sha256 redis_recovery_semantics control_plane_state_archive_format control_plane_state_archive_plaintext_sha256 control_plane_state_archive_plaintext_size_bytes control_plane_state_archive_sha256 control_plane_state_archive_size_bytes control_plane_state_archive_encryption source_schema_fingerprint_sha256 source_data_fingerprint_sha256 source_state_selection_sha256 quiesce_protocol quiesce_operator_sha256 quiesce_fencing_token_sha256 quiesce_lease_requested_seconds quiesce_lease_acquired_unix quiesce_lease_expires_unix quiesce_released_unix database_dump_uid database_dump_gid database_dump_mode redis_snapshot_uid redis_snapshot_gid redis_snapshot_mode control_plane_state_archive_uid control_plane_state_archive_gid control_plane_state_archive_mode capture_manifest_uid capture_manifest_gid capture_manifest_mode capture_directory_uid capture_directory_gid capture_directory_mode backup_tool_sha256 restore_tool_sha256 capture_manifest_payload_sha256'
readonly ATTESTATION_KEYS='attestation_version operation_id source_pg_system_identifier source_pg_database_oid source_pg_version source_database_name source_host_identity source_redis_container_id source_redis_image_digest source_redis_endpoint source_redis_version quiesce_protocol quiesce_operator_sha256 quiesce_fencing_token_sha256 quiesce_lease_requested_seconds quiesce_lease_acquired_unix quiesce_lease_expires_unix quiesce_released_unix database_dump_sha256 database_dump_size_bytes database_dump_created_unix database_dump_encryption redis_snapshot_sha256 redis_snapshot_size_bytes redis_snapshot_created_unix redis_snapshot_encryption redis_recovery_semantics control_plane_state_archive_sha256 control_plane_state_archive_size_bytes control_plane_state_archive_encryption encryption_recipient_fingerprint source_capture_manifest_sha256 source_capture_manifest_size_bytes candidate_image_digest candidate_runtime_image_id restore_target_identity restore_pg_system_identifier restore_database_oid restore_pg_version restore_fresh_clone restore_redis_container_id restore_redis_image_id restore_redis_endpoint restore_redis_dbsize restore_redis_keyspace_sha256 restore_redis_fresh_clone restore_method restored_state_selection_sha256 restored_state_proof_sha256 candidate_observed_state_proof_sha256 control_plane_state_restore redis_restore candidate_clone_endpoints state_proof_tool_sha256 operator_rehearsal_hook_sha256 candidate_boot_hook_sha256 candidate_api_probe_hook_sha256 restore_rehearsal candidate_boot candidate_api_probe restore_verified_unix generated_unix backup_tool_sha256 restore_tool_sha256 attestation_payload_sha256'

fail()
{
    printf 'control-plane-backup-restore: %s\n' "$1" >&2
    exit 1
}

usage()
{
    cat >&2 <<'EOF'
Restore and attest:
  restore-attest.sh restore --operation-id ID \
    --database-dump FILE --redis-snapshot FILE --state-archive FILE --capture-manifest FILE \
    --expected-capture-manifest-sha256 HEX \
    --expected-source-pg-system-identifier NUMBER --expected-source-pg-database-oid NUMBER \
    --expected-source-pg-version 15.18 --expected-source-database-name NAME \
    --expected-source-host-identity HOST --expected-candidate-image-digest REPO@sha256:HEX \
    --expected-source-redis-container-id HEX \
    --expected-source-redis-image-digest REPO@sha256:HEX \
    --expected-source-redis-endpoint HOST:PORT \
    --expected-recipient-fingerprint OPENPGP_FINGERPRINT \
    --expected-quiesce-operator-sha256 HEX \
    --restore-target-container CONTAINER --restore-database-user USER \
    --restore-redis-container CONTAINER --restore-redis-volume VOLUME \
    --restore-redis-network NETWORK --restore-redis-password-file ROOT_0600_FILE \
    --expected-restore-target-identity HOST --max-age-seconds NUMBER \
    --operator-rehearse-migrations-hook FILE --candidate-boot-hook FILE \
    --candidate-api-probe-hook FILE --candidate-runtime-container CONTAINER \
    --restore-state-root EMPTY_DIRECTORY --plaintext-tmpfs-root TMPFS_DIRECTORY \
    --state-proof-tool FILE --expected-state-proof-tool-sha256 HEX \
    --stale-work-max-age-seconds NUMBER \
    --expected-operator-rehearsal-hook-sha256 HEX \
    --expected-candidate-boot-hook-sha256 HEX \
    --expected-candidate-api-probe-hook-sha256 HEX --attestation-output FILE

Validate an emitted attestation (same expected-source/artifact arguments):
  restore-attest.sh verify-attestation ... --expected-restore-target-identity HOST \
    --attestation FILE --expected-attestation-sha256 HEX --max-age-seconds NUMBER

GNUPGHOME must be a root-owned mode-0700 off-host keyring containing the exact
recipient private key. Restore refuses any non-disposable target, any target
other than PostgreSQL 15.18, and any live/restore system-identity collision.
Hooks receive only bound non-secret identities through CONTROL_PLANE_* variables
and must emit their one exact success line. No network transfer is implemented.
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

field_value()
{
    local file=$1 key=$2
    awk -F= -v requested_key="$key" '$1 == requested_key { print substr($0, length($1) + 2) }' "$file"
}

validate_key_order()
{
    local file=$1 keys=$2

    LC_ALL=C awk -F= -v expected_keys="$keys" '
        BEGIN { expected_count = split(expected_keys, expected, " ") }
        $0 !~ /^[a-z0-9_]+=[ -~]+$/ || NF < 2 || $1 != expected[NR] { exit 1 }
        END { if (NR != expected_count) exit 1 }
    ' "$file" || fail 'machine-readable artifact has missing, duplicate, reordered, or unknown fields'
}

assert_payload_digest()
{
    local file=$1 digest_key=$2 expected_digest observed_digest

    expected_digest=$(field_value "$file" "$digest_key")
    observed_digest=$(sed '$d' "$file" | sha256sum | awk '{print $1}')
    [[ $expected_digest =~ ^[0-9a-f]{64}$ && $observed_digest == "$expected_digest" ]] \
        || fail 'machine-readable artifact payload digest is invalid'
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
        || fail 'plaintext work directory must be on a tmpfs filesystem'
}

clear_directory_contents()
{
    local directory=$1

    assert_secure_directory "$directory"
    find "$directory" -xdev -mindepth 1 -delete
}

marker_is_stale()
{
    local marker=$1 marker_operation marker_created current_unix

    [[ -f $marker && ! -L $marker && $(file_uid "$marker") == 0 \
        && $(file_gid "$marker") == 0 && $(file_mode "$marker") == 400 ]] || return 1
    IFS=: read -r marker_operation marker_created < "$marker"
    [[ $marker_operation == "$operation_id" && $marker_created =~ ^[1-9][0-9]*$ ]] || return 1
    current_unix=$(date +%s)
    (( current_unix - marker_created > stale_work_max_age_seconds ))
}

prepare_restore_state_root()
{
    local staging_directory

    assert_secure_directory "$restore_state_root"
    staging_directory="$(dirname "$restore_state_root")/.control-plane-state-${operation_id}.staging"
    if [[ -e $staging_directory || -L $staging_directory ]]; then
        assert_secure_directory "$staging_directory"
        marker_is_stale "$staging_directory/.backup-restore-operation" \
            || fail 'state restore staging directory is active, unsafe, or not stale'
        clear_directory_contents "$staging_directory"
        rmdir "$staging_directory"
    fi
    if [[ -n $(find "$restore_state_root" -mindepth 1 -print -quit) ]]; then
        marker_is_stale "$restore_state_root/.backup-restore-operation" \
            || fail 'restore state root must be empty or contain this operation stale state'
        clear_directory_contents "$restore_state_root"
        stale_state_reaped=1
    fi
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
        || fail 'restore target container name is invalid'
}

validate_operation_id()
{
    [[ $1 =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]] || fail 'operation id is invalid'
}

validate_host_identity()
{
    [[ $1 =~ ^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$ ]] || fail 'host identity is invalid'
}

validate_sha256()
{
    [[ $1 =~ ^[0-9a-f]{64}$ ]] || fail "$2 is not a SHA-256 digest"
}

validate_positive_integer()
{
    [[ $1 =~ ^[1-9][0-9]*$ ]] || fail "$2 must be a positive integer"
}

validate_redis_endpoint()
{
    local endpoint=$1 host port

    [[ $endpoint == *:* ]] || fail "$2 is not a Redis host:port endpoint"
    host=${endpoint%:*}
    port=${endpoint##*:}
    validate_container_name "$host"
    [[ $port =~ ^[1-9][0-9]{0,4}$ && $port -le 65535 ]] \
        || fail "$2 contains an invalid Redis port"
}

validate_container_id()
{
    [[ $1 =~ ^[0-9a-f]{64}$ ]] || fail "$2 is not an exact container id"
}

validate_candidate_digest()
{
    local reference=$1 repository last_component

    [[ $reference =~ ^[a-z0-9][a-z0-9._:/-]*@sha256:[0-9a-f]{64}$ ]] \
        || fail 'candidate image must be an immutable repository@sha256 digest reference'
    repository=${reference%@sha256:*}
    last_component=${repository##*/}
    [[ $repository == */* && $last_component != *:* ]] \
        || fail 'candidate image must not contain a mutable tag'
}

assert_capture_inputs()
{
    assert_root_file "$database_dump" 600
    assert_root_file "$redis_snapshot" 600
    assert_root_file "$state_archive" 600
    assert_root_file "$capture_manifest" 400
    assert_secure_directory "$(dirname "$capture_manifest")"
    validate_sha256 "$expected_capture_manifest_sha256" 'expected capture manifest digest'
    [[ $(sha256_file "$capture_manifest") == "$expected_capture_manifest_sha256" ]] \
        || fail 'capture manifest does not match the out-of-band pinned digest'
    validate_key_order "$capture_manifest" "$MANIFEST_KEYS"
    assert_payload_digest "$capture_manifest" capture_manifest_payload_sha256

    [[ $(field_value "$capture_manifest" capture_manifest_version) == 2 ]] \
        || fail 'capture manifest version is unsupported'
    [[ $(field_value "$capture_manifest" operation_id) == "$operation_id" ]] \
        || fail 'capture manifest operation id differs from the requested operation'
    [[ $(field_value "$capture_manifest" source_pg_system_identifier) == \
        "$expected_source_pg_system_identifier" ]] \
        || fail 'capture manifest source PostgreSQL system identifier is unexpected'
    [[ $(field_value "$capture_manifest" source_pg_database_oid) == \
        "$expected_source_pg_database_oid" ]] \
        || fail 'capture manifest source database OID is unexpected'
    [[ $(field_value "$capture_manifest" source_pg_version) == \
        "$expected_source_pg_version" && $expected_source_pg_version == "$REQUIRED_POSTGRES_VERSION" ]] \
        || fail "source PostgreSQL version must be exactly ${REQUIRED_POSTGRES_VERSION}"
    [[ $(field_value "$capture_manifest" source_database_name) == \
        "$expected_source_database_name" ]] \
        || fail 'capture manifest source database name is unexpected'
    [[ $(field_value "$capture_manifest" source_host_identity) == \
        "$expected_source_host_identity" ]] \
        || fail 'capture manifest source host identity is unexpected'
    [[ $(field_value "$capture_manifest" source_redis_container_id) == \
        "$expected_source_redis_container_id" \
        && $(field_value "$capture_manifest" source_redis_image_digest) == \
            "$expected_source_redis_image_digest" \
        && $(field_value "$capture_manifest" source_redis_endpoint) == \
            "$expected_source_redis_endpoint" ]] \
        || fail 'capture manifest source Redis identity is unexpected'
    validate_container_name "$(field_value "$capture_manifest" source_redis_container_name)"
    [[ $(field_value "$capture_manifest" source_redis_container_name) == \
        "${expected_source_redis_endpoint%:*}" ]] \
        || fail 'capture manifest Redis container name differs from its endpoint'
    validate_container_id "$(field_value "$capture_manifest" source_redis_container_id)" \
        'capture manifest source Redis container id'
    validate_candidate_digest "$(field_value "$capture_manifest" source_redis_image_digest)"
    validate_redis_endpoint "$(field_value "$capture_manifest" source_redis_endpoint)" \
        'capture manifest source Redis endpoint'
    [[ $(field_value "$capture_manifest" source_redis_image_id) =~ ^sha256:[0-9a-f]{64}$ \
        && $(field_value "$capture_manifest" source_redis_image_reference) != *$'\n'* \
        && $(field_value "$capture_manifest" source_redis_version) \
            =~ ^[0-9]+\.[0-9]+\.[0-9]+$ \
        && $(field_value "$capture_manifest" source_redis_dbsize) =~ ^[0-9]+$ ]] \
        || fail 'capture manifest Redis metadata is malformed'
    [[ $(field_value "$capture_manifest" candidate_image_digest) == \
        "$expected_candidate_image_digest" ]] \
        || fail 'capture manifest candidate digest is unexpected'
    [[ $(field_value "$capture_manifest" encryption_recipient_fingerprint) == \
        "$expected_recipient_fingerprint" ]] \
        || fail 'capture manifest encryption recipient is unexpected'
    [[ $(field_value "$capture_manifest" database_dump_format) == postgresql-custom \
        && $(field_value "$capture_manifest" database_dump_encryption) == openpgp \
        && $(field_value "$capture_manifest" redis_snapshot_format) == redis-rdb \
        && $(field_value "$capture_manifest" redis_snapshot_encryption) == openpgp \
        && $(field_value "$capture_manifest" redis_recovery_semantics) == \
            quiesced-exact-rdb-no-worker-replay-v1 \
        && $(field_value "$capture_manifest" control_plane_state_archive_format) == \
            gnu-tar-deterministic-v2-service-normalized \
        && $(field_value "$capture_manifest" control_plane_state_archive_encryption) == openpgp ]] \
        || fail 'capture manifest format or encryption contract is invalid'

    for manifest_digest_key in database_dump_plaintext_sha256 database_dump_sha256 \
        redis_snapshot_plaintext_sha256 redis_snapshot_sha256 source_redis_keyspace_sha256 \
        control_plane_state_archive_plaintext_sha256 control_plane_state_archive_sha256 \
        source_schema_fingerprint_sha256 source_data_fingerprint_sha256 \
        source_state_selection_sha256 quiesce_operator_sha256 \
        quiesce_fencing_token_sha256 backup_tool_sha256 restore_tool_sha256; do
        validate_sha256 "$(field_value "$capture_manifest" "$manifest_digest_key")" \
            "capture manifest $manifest_digest_key"
    done
    for manifest_integer_key in capture_started_unix database_dump_created_unix \
        redis_snapshot_created_unix source_redis_lastsave_unix \
        control_plane_state_archive_created_unix capture_completed_unix \
        source_pg_system_identifier source_pg_database_oid \
        quiesce_lease_requested_seconds \
        quiesce_lease_acquired_unix quiesce_lease_expires_unix quiesce_released_unix \
        database_dump_plaintext_size_bytes database_dump_size_bytes \
        redis_snapshot_plaintext_size_bytes redis_snapshot_size_bytes \
        control_plane_state_archive_plaintext_size_bytes \
        control_plane_state_archive_size_bytes; do
        validate_positive_integer "$(field_value "$capture_manifest" "$manifest_integer_key")" \
            "capture manifest $manifest_integer_key"
    done
    [[ $(field_value "$capture_manifest" quiesce_protocol) == \
        control-plane-backup-fenced-v1 \
        && $(field_value "$capture_manifest" quiesce_lease_requested_seconds) -le 7200 \
        && $(field_value "$capture_manifest" quiesce_lease_acquired_unix) \
            -lt $(field_value "$capture_manifest" quiesce_released_unix) \
        && $(field_value "$capture_manifest" quiesce_released_unix) \
            -lt $(field_value "$capture_manifest" quiesce_lease_expires_unix) \
        && $(( $(field_value "$capture_manifest" quiesce_lease_expires_unix) \
            - $(field_value "$capture_manifest" quiesce_lease_acquired_unix) )) \
            -eq $(field_value "$capture_manifest" quiesce_lease_requested_seconds) ]] \
        || fail 'capture manifest quiesce lease proof is invalid'
    [[ $(field_value "$capture_manifest" database_dump_uid) == 0 \
        && $(field_value "$capture_manifest" database_dump_gid) == 0 \
        && $(field_value "$capture_manifest" database_dump_mode) == 600 \
        && $(field_value "$capture_manifest" redis_snapshot_uid) == 0 \
        && $(field_value "$capture_manifest" redis_snapshot_gid) == 0 \
        && $(field_value "$capture_manifest" redis_snapshot_mode) == 600 \
        && $(field_value "$capture_manifest" control_plane_state_archive_uid) == 0 \
        && $(field_value "$capture_manifest" control_plane_state_archive_gid) == 0 \
        && $(field_value "$capture_manifest" control_plane_state_archive_mode) == 600 \
        && $(field_value "$capture_manifest" capture_manifest_uid) == 0 \
        && $(field_value "$capture_manifest" capture_manifest_gid) == 0 \
        && $(field_value "$capture_manifest" capture_manifest_mode) == 400 \
        && $(field_value "$capture_manifest" capture_directory_uid) == 0 \
        && $(field_value "$capture_manifest" capture_directory_gid) == 0 \
        && $(field_value "$capture_manifest" capture_directory_mode) == 700 ]] \
        || fail 'capture manifest does not bind the required root ownership and modes'
    [[ $(field_value "$capture_manifest" restore_tool_sha256) == "$restore_tool_sha256" ]] \
        || fail 'capture manifest does not bind this exact restore tool'
    [[ $(field_value "$capture_manifest" quiesce_operator_sha256) == \
        "$expected_quiesce_operator_sha256" ]] \
        || fail 'capture manifest does not bind the independently pinned quiesce operator'
    [[ $(sha256_file "$database_dump") == \
        $(field_value "$capture_manifest" database_dump_sha256) \
        && $(file_size "$database_dump") == \
            $(field_value "$capture_manifest" database_dump_size_bytes) ]] \
        || fail 'encrypted database dump hash or size differs from capture'
    [[ $(sha256_file "$redis_snapshot") == \
        $(field_value "$capture_manifest" redis_snapshot_sha256) \
        && $(file_size "$redis_snapshot") == \
            $(field_value "$capture_manifest" redis_snapshot_size_bytes) ]] \
        || fail 'encrypted Redis snapshot hash or size differs from capture'
    [[ $(sha256_file "$state_archive") == \
        $(field_value "$capture_manifest" control_plane_state_archive_sha256) \
        && $(file_size "$state_archive") == \
            $(field_value "$capture_manifest" control_plane_state_archive_size_bytes) ]] \
        || fail 'encrypted control-plane state archive hash or size differs from capture'

    local capture_started dump_created redis_created archive_created capture_completed current_unix
    capture_started=$(field_value "$capture_manifest" capture_started_unix)
    dump_created=$(field_value "$capture_manifest" database_dump_created_unix)
    redis_created=$(field_value "$capture_manifest" redis_snapshot_created_unix)
    archive_created=$(field_value "$capture_manifest" control_plane_state_archive_created_unix)
    capture_completed=$(field_value "$capture_manifest" capture_completed_unix)
    current_unix=$(date +%s)
    (( capture_started <= $(field_value "$capture_manifest" quiesce_lease_acquired_unix) \
        && $(field_value "$capture_manifest" quiesce_lease_acquired_unix) <= dump_created \
        && dump_created <= redis_created && redis_created <= archive_created \
        && archive_created <= $(field_value "$capture_manifest" quiesce_released_unix) \
        && $(field_value "$capture_manifest" quiesce_released_unix) <= capture_completed \
        && capture_completed < $(field_value "$capture_manifest" quiesce_lease_expires_unix) \
        && capture_completed <= current_unix + 60 )) \
        || fail 'capture timestamps are inconsistent or in the future'
    (( current_unix - dump_created <= max_age_seconds )) \
        || fail 'capture is stale'
}

decrypt_file()
{
    local ciphertext=$1 plaintext=$2

    gpg --batch --yes --quiet --output "$plaintext" --decrypt "$ciphertext" 2>/dev/null \
        || fail 'OpenPGP decryption failed'
    chmod 600 "$plaintext"
}

validate_and_extract_state_archive()
{
    local archive=$1 listing_file verbose_listing_file
    local member top_level selection_file proof_output staging_parent
    local -a top_levels=()

    listing_file="$work_directory/state-archive.list"
    verbose_listing_file="$work_directory/state-archive.verbose-list"
    tar --list --numeric-owner --file "$archive" > "$listing_file"
    tar --list --verbose --numeric-owner --file "$archive" > "$verbose_listing_file"
    [[ -s $listing_file ]] || fail 'decrypted state archive is empty'
    if awk 'substr($0, 1, 1) !~ /^[-d]$/ { exit 1 }' "$verbose_listing_file"; then
        :
    else
        fail 'decrypted state archive contains links or unsupported entry types'
    fi
    LC_ALL=C awk '$1 != "drwx------" && $1 != "-rw-------" { exit 1 }
        $2 != "9999/9999" { exit 1 }' "$verbose_listing_file" \
        || fail 'decrypted state archive ownership or mode is not canonical service state'
    while IFS= read -r member; do
        [[ -n $member && $member != /* && $member != .. && $member != ../* \
            && $member != *'/../'* && $member != *'/..' \
            && $member != *$'\n'* && $member != *$'\r'* && $member != *$'\t'* ]] \
            || fail 'decrypted state archive contains an unsafe path'
        top_level=${member%%/*}
        [[ $top_level =~ ^[A-Za-z0-9._-]+$ ]] \
            || fail 'decrypted state archive contains an unsafe top-level selection'
        top_levels+=("$top_level")
    done < "$listing_file"

    mapfile -t top_levels < <(printf '%s\n' "${top_levels[@]}" | LC_ALL=C sort -u)
    selection_file="$work_directory/restored-state-selection"
    : > "$selection_file"
    for top_level in "${top_levels[@]}"; do
        printf '%s\0' "$top_level" >> "$selection_file"
    done
    [[ $(sha256_file "$selection_file") == \
        $(field_value "$capture_manifest" source_state_selection_sha256) ]] \
        || fail 'decrypted state archive selection differs from source capture'

    restored_state_selection_sha256=$(sha256_file "$selection_file")
    restored_state_selection=$(IFS=,; printf '%s' "${top_levels[*]}")

    staging_parent=$(dirname "$restore_state_root")
    restore_staging_directory="$staging_parent/.control-plane-state-${operation_id}.staging"
    [[ ! -e $restore_staging_directory && ! -L $restore_staging_directory ]] \
        || fail 'state restore staging directory already exists'
    mkdir -m 700 "$restore_staging_directory"
    printf '%s:%s\n' "$operation_id" "$(date +%s)" \
        > "$restore_staging_directory/.backup-restore-operation"
    chmod 400 "$restore_staging_directory/.backup-restore-operation"
    tar --extract --no-same-owner --no-same-permissions \
        --directory "$restore_staging_directory" --file "$archive"
    [[ -z $(find "$restore_staging_directory" -type l -print -quit) ]] \
        || fail 'restored state unexpectedly contains a symlink'
    while IFS= read -r -d '' restored_path; do
        [[ $restored_path != "$restore_staging_directory/.backup-restore-operation" ]] || continue
        if [[ -d $restored_path ]]; then
            chmod 700 "$restored_path"
        elif [[ -f $restored_path ]]; then
            chmod 600 "$restored_path"
        else
            fail 'restored state contains an unsupported entry type'
        fi
        chown 9999:9999 "$restored_path"
    done < <(find "$restore_staging_directory" -mindepth 1 -print0)
    [[ -z $(find "$restore_staging_directory" -type s -print -quit) ]] \
        || fail 'restored state must not contain ephemeral sockets'
    for top_level in applications databases services ssh; do
        [[ -d $restore_staging_directory/$top_level \
            && $(file_uid "$restore_staging_directory/$top_level") == 9999 \
            && $(file_gid "$restore_staging_directory/$top_level") == 9999 \
            && $(file_mode "$restore_staging_directory/$top_level") == 700 ]] \
            || fail 'restored runtime subtree ownership or mode is invalid'
    done
    mkdir -m 700 "$restore_staging_directory/backups"
    chown 9999:9999 "$restore_staging_directory/backups"
    chmod 400 "$restore_staging_directory/.backup-restore-operation"
    proof_output=$("$state_proof_tool" "$restore_staging_directory" "$restored_state_selection")
    [[ $proof_output =~ ^control_plane_state_proof_sha256=([0-9a-f]{64})$ ]] \
        || fail 'state proof tool emitted an invalid result'
    restored_state_proof_sha256=${BASH_REMATCH[1]}
    rmdir "$restore_state_root"
    mv -T "$restore_staging_directory" "$restore_state_root"
    restore_staging_directory=
    restore_state_committed=1
}

target_psql_admin()
{
    docker exec "$restore_target_container" psql --no-psqlrc --tuples-only --no-align --quiet \
        --set ON_ERROR_STOP=1 --username "$restore_database_user" --dbname postgres "$@"
}

target_psql_database()
{
    docker exec "$restore_target_container" psql --no-psqlrc --tuples-only --no-align --quiet \
        --set ON_ERROR_STOP=1 --username "$restore_database_user" \
        --dbname "$expected_source_database_name" "$@"
}

reap_stale_database()
{
    local database_exists

    database_exists=$(target_psql_admin --command \
        "SELECT count(*) FROM pg_database WHERE datname = '$expected_source_database_name'" \
        | tr -d '[:space:]')
    [[ $database_exists == 0 ]] && return 0
    [[ $database_exists == 1 && ${stale_state_reaped:-0} == 1 \
        && $(docker inspect --format \
            '{{index .Config.Labels "coolify.control-plane.backup-restore.operation"}}' \
            "$restore_target_container") == "$operation_id" ]] \
        || fail 'restore target contains an unowned or active database'
    docker exec "$restore_target_container" dropdb --force --no-password \
        --username "$restore_database_user" "$expected_source_database_name"
}

reap_stale_candidate_resources()
{
    local resource created current_unix

    current_unix=$(date +%s)
    for resource_type in volume network; do
        while IFS= read -r resource; do
            [[ -n $resource ]] || continue
            [[ $(docker "$resource_type" inspect --format \
                '{{index .Labels "coolify.control-plane.backup-restore.redis"}}' "$resource") \
                != true ]] || continue
            [[ $(docker "$resource_type" inspect --format \
                '{{index .Labels "coolify.control-plane.backup-restore.operation"}}' "$resource") \
                == "$operation_id" ]] || continue
            created=$(docker "$resource_type" inspect --format \
                '{{index .Labels "coolify.control-plane.backup-restore.created-unix"}}' "$resource")
            [[ $created =~ ^[1-9][0-9]*$ \
                && $((current_unix - created)) -gt $stale_work_max_age_seconds ]] \
                || fail 'candidate resource exists but is not safely stale'
        done < <(docker "$resource_type" ls --quiet --filter \
            label=coolify.control-plane.backup-restore.resource=true)
    done
    cleanup_owned_candidate_resources
}

reap_stale_candidate()
{
    local candidate_operation candidate_created current_unix

    if ! docker inspect "$candidate_runtime_container" >/dev/null 2>&1; then
        capture_orphaned_candidate_secrets \
            || fail 'orphaned candidate secret ownership is invalid'
        delete_captured_candidate_secrets \
            || fail 'orphaned candidate secrets could not be removed'
        reap_stale_candidate_resources
        return 0
    fi
    candidate_operation=$(docker inspect --format \
        '{{index .Config.Labels "coolify.control-plane.backup-restore.operation"}}' \
        "$candidate_runtime_container")
    candidate_created=$(docker inspect --format \
        '{{index .Config.Labels "coolify.control-plane.backup-restore.created-unix"}}' \
        "$candidate_runtime_container")
    [[ $(docker inspect --format \
        '{{index .Config.Labels "coolify.control-plane.backup-restore.candidate"}}' \
        "$candidate_runtime_container") == true \
        && $candidate_operation == "$operation_id" && $candidate_created =~ ^[1-9][0-9]*$ ]] \
        || fail 'candidate runtime container already exists without exact stale ownership labels'
    current_unix=$(date +%s)
    (( current_unix - candidate_created > stale_work_max_age_seconds )) \
        || fail 'candidate runtime container already exists and is not stale'
    capture_owned_candidate_secrets "$candidate_runtime_container" \
        || fail 'stale candidate secret ownership is invalid'
    docker rm -f "$candidate_runtime_container" >/dev/null \
        || fail 'stale candidate runtime container could not be removed'
    delete_captured_candidate_secrets \
        || fail 'stale candidate secrets could not be removed'
    reap_stale_candidate_resources
}

capture_owned_candidate_secrets()
{
    local candidate_container=$1 secret_directory owner_file direct_source ack_source entry
    local expected_secret_identity

    captured_candidate_secret_container=
    captured_candidate_secret_directory=
    captured_candidate_secret_owner_file=
    captured_candidate_direct_probe_source=
    captured_candidate_applied_ack_source=

    [[ $(docker inspect --format \
        '{{index .Config.Labels "coolify.control-plane.backup-restore.candidate"}}' \
        "$candidate_container" 2>/dev/null) == true \
        && $(docker inspect --format \
            '{{index .Config.Labels "coolify.control-plane.backup-restore.operation"}}' \
            "$candidate_container" 2>/dev/null) == "$operation_id" ]] || return 1
    secret_directory=$(docker inspect --format \
        '{{with index .Config.Labels "coolify.control-plane.backup-restore.candidate-secrets"}}{{.}}{{end}}' \
        "$candidate_container" 2>/dev/null) || return 1
    [[ -n $secret_directory ]] || return 0
    expected_secret_identity=$(printf '%s:%s' "$operation_id" "$candidate_container" \
        | sha256sum | awk '{print substr($1, 1, 40)}')
    [[ $secret_directory == /* \
        && ${secret_directory##*/} == ".candidate-secrets.$expected_secret_identity" ]] \
        || return 1
    (assert_secure_directory "$secret_directory") >/dev/null 2>&1 || return 1
    owner_file="$secret_directory/.backup-restore-candidate-owner"
    (assert_root_file "$owner_file" 400) >/dev/null 2>&1 || return 1
    (validate_key_order "$owner_file" 'operation_id candidate_runtime_container') \
        >/dev/null 2>&1 || return 1
    [[ $(field_value "$owner_file" operation_id) == "$operation_id" \
        && $(field_value "$owner_file" candidate_runtime_container) == "$candidate_container" ]] \
        || return 1
    direct_source=$(docker inspect --format \
        '{{range .Mounts}}{{if eq .Destination "/run/secrets/control-plane-direct-probe-token"}}{{.Source}}{{end}}{{end}}' \
        "$candidate_container" 2>/dev/null) || return 1
    ack_source=$(docker inspect --format \
        '{{range .Mounts}}{{if eq .Destination "/run/secrets/control-plane-applied-ack"}}{{.Source}}{{end}}{{end}}' \
        "$candidate_container" 2>/dev/null) || return 1
    [[ $direct_source == "$secret_directory/direct-probe-token" \
        && $ack_source == "$secret_directory/applied-ack" ]] || return 1
    (assert_root_file "$direct_source" 444) >/dev/null 2>&1 || return 1
    (assert_root_file "$ack_source" 444) >/dev/null 2>&1 || return 1
    while IFS= read -r entry; do
        case ${entry##*/} in
            .backup-restore-candidate-owner|direct-probe-token|applied-ack) ;;
            *) return 1 ;;
        esac
    done < <(find "$secret_directory" -xdev -mindepth 1 -maxdepth 1 -print)
    captured_candidate_secret_container=$candidate_container
    captured_candidate_secret_directory=$secret_directory
    captured_candidate_secret_owner_file=$owner_file
    captured_candidate_direct_probe_source=$direct_source
    captured_candidate_applied_ack_source=$ack_source
}

capture_orphaned_candidate_secrets()
{
    local token_file=${CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE:-}
    local secret_directory owner_file direct_source ack_source entry expected_secret_identity

    captured_candidate_secret_container=
    captured_candidate_secret_directory=
    captured_candidate_secret_owner_file=
    captured_candidate_direct_probe_source=
    captured_candidate_applied_ack_source=
    [[ -n $token_file ]] || return 0
    [[ $token_file == /* ]] || return 1
    expected_secret_identity=$(printf '%s:%s' "$operation_id" "$candidate_runtime_container" \
        | sha256sum | awk '{print substr($1, 1, 40)}')
    secret_directory="$(dirname "$token_file")/.candidate-secrets.$expected_secret_identity"
    [[ -e $secret_directory || -L $secret_directory ]] || return 0
    (assert_secure_directory "$secret_directory") >/dev/null 2>&1 || return 1
    owner_file="$secret_directory/.backup-restore-candidate-owner"
    (assert_root_file "$owner_file" 400) >/dev/null 2>&1 || return 1
    (validate_key_order "$owner_file" 'operation_id candidate_runtime_container') \
        >/dev/null 2>&1 || return 1
    [[ $(field_value "$owner_file" operation_id) == "$operation_id" \
        && $(field_value "$owner_file" candidate_runtime_container) == \
            "$candidate_runtime_container" ]] || return 1
    direct_source="$secret_directory/direct-probe-token"
    ack_source="$secret_directory/applied-ack"
    (assert_root_file "$direct_source" 444) >/dev/null 2>&1 || return 1
    (assert_root_file "$ack_source" 444) >/dev/null 2>&1 || return 1
    while IFS= read -r entry; do
        case ${entry##*/} in
            .backup-restore-candidate-owner|direct-probe-token|applied-ack) ;;
            *) return 1 ;;
        esac
    done < <(find "$secret_directory" -xdev -mindepth 1 -maxdepth 1 -print)
    captured_candidate_secret_container=$candidate_runtime_container
    captured_candidate_secret_directory=$secret_directory
    captured_candidate_secret_owner_file=$owner_file
    captured_candidate_direct_probe_source=$direct_source
    captured_candidate_applied_ack_source=$ack_source
}

delete_captured_candidate_secrets()
{
    local entry

    [[ -n $captured_candidate_secret_directory ]] || return 0
    (assert_secure_directory "$captured_candidate_secret_directory") \
        >/dev/null 2>&1 || return 1
    (assert_root_file "$captured_candidate_secret_owner_file" 400) \
        >/dev/null 2>&1 || return 1
    (validate_key_order "$captured_candidate_secret_owner_file" \
        'operation_id candidate_runtime_container') >/dev/null 2>&1 || return 1
    [[ $(field_value "$captured_candidate_secret_owner_file" operation_id) == "$operation_id" \
        && $(field_value "$captured_candidate_secret_owner_file" \
            candidate_runtime_container) == "$captured_candidate_secret_container" \
        && $captured_candidate_direct_probe_source == \
            "$captured_candidate_secret_directory/direct-probe-token" \
        && $captured_candidate_applied_ack_source == \
            "$captured_candidate_secret_directory/applied-ack" ]] || return 1
    (assert_root_file "$captured_candidate_direct_probe_source" 444) \
        >/dev/null 2>&1 || return 1
    (assert_root_file "$captured_candidate_applied_ack_source" 444) \
        >/dev/null 2>&1 || return 1
    while IFS= read -r entry; do
        case ${entry##*/} in
            .backup-restore-candidate-owner|direct-probe-token|applied-ack) ;;
            *) return 1 ;;
        esac
    done < <(find "$captured_candidate_secret_directory" -xdev -mindepth 1 \
        -maxdepth 1 -print)
    rm -f -- "$captured_candidate_direct_probe_source" \
        "$captured_candidate_applied_ack_source" "$captured_candidate_secret_owner_file"
    rmdir -- "$captured_candidate_secret_directory"
}

cleanup_owned_candidate_resources()
{
    local resource

    while IFS= read -r resource; do
        [[ -n $resource ]] || continue
        [[ $(docker volume inspect --format \
            '{{index .Labels "coolify.control-plane.backup-restore.redis"}}' "$resource") \
            != true ]] || continue
        [[ $(docker volume inspect --format \
            '{{index .Labels "coolify.control-plane.backup-restore.operation"}}' "$resource") \
            == "$operation_id" ]] || continue
        docker volume rm "$resource" >/dev/null 2>&1 || true
    done < <(docker volume ls --quiet --filter \
        label=coolify.control-plane.backup-restore.resource=true)
    while IFS= read -r resource; do
        [[ -n $resource ]] || continue
        [[ $(docker network inspect --format \
            '{{index .Labels "coolify.control-plane.backup-restore.redis"}}' "$resource") \
            != true ]] || continue
        [[ $(docker network inspect --format \
            '{{index .Labels "coolify.control-plane.backup-restore.operation"}}' "$resource") \
            == "$operation_id" ]] || continue
        docker network disconnect --force "$resource" "$restore_target_container" \
            >/dev/null 2>&1 || true
        docker network rm "$resource" >/dev/null 2>&1 || true
    done < <(docker network ls --quiet --filter \
        label=coolify.control-plane.backup-restore.resource=true)
}

cleanup_after_failure()
{
    local exit_status=$? candidate_secrets_captured=0

    trap - EXIT HUP INT TERM

    if [[ ${restore_succeeded:-0} != 1 && $command_name == restore ]]; then
        if [[ ${candidate_was_absent:-0} == 1 ]] \
            && docker inspect "$candidate_runtime_container" >/dev/null 2>&1; then
            if capture_owned_candidate_secrets "$candidate_runtime_container" \
                >/dev/null 2>&1; then
                candidate_secrets_captured=1
            fi
            if docker rm -f "$candidate_runtime_container" >/dev/null 2>&1 \
                && [[ $candidate_secrets_captured == 1 ]]; then
                delete_captured_candidate_secrets >/dev/null 2>&1 || true
            fi
        fi
        cleanup_restore_redis
        cleanup_owned_candidate_resources
        if [[ ${database_created:-0} == 1 ]]; then
            docker exec "$restore_target_container" dropdb --force --no-password \
                --username "$restore_database_user" "$expected_source_database_name" \
                >/dev/null 2>&1 || true
        fi
        if [[ ${restore_state_committed:-0} == 1 ]]; then
            clear_directory_contents "$restore_state_root" >/dev/null 2>&1 || true
        fi
        if [[ -n ${restore_staging_directory:-} \
            && $restore_staging_directory == \
                "$(dirname "$restore_state_root")/.control-plane-state-${operation_id}.staging" \
            && -d $restore_staging_directory && ! -L $restore_staging_directory \
            && -f $restore_staging_directory/.backup-restore-operation \
            && ! -L $restore_staging_directory/.backup-restore-operation \
            && $(<"$restore_staging_directory/.backup-restore-operation") == "$operation_id:"* ]]; then
            clear_directory_contents "$restore_staging_directory" >/dev/null 2>&1 || true
            rmdir "$restore_staging_directory" >/dev/null 2>&1 || true
        fi
    fi
    [[ -z ${work_directory:-} ]] || rm -rf -- "$work_directory"
    exit "$exit_status"
}

target_database_fingerprint()
{
    local section=$1 restrict_key=$2
    local -a section_options

    case $section in
        schema)
            section_options=(--schema-only)
            ;;
        data)
            section_options=(--data-only)
            ;;
        *)
            fail 'unknown restored PostgreSQL fingerprint section'
            ;;
    esac
    docker exec "$restore_target_container" pg_dump --no-password --format=custom --compress=9 \
        --username "$restore_database_user" --dbname "$expected_source_database_name" \
        | docker exec -i "$restore_target_container" pg_restore --file=- --no-owner --no-privileges \
            "--restrict-key=$restrict_key" "${section_options[@]}" \
        | sha256sum | awk '{print $1}'
}

restore_redis_cli()
{
    docker exec "$restore_redis_container" sh -eu -c '
        exec env REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli --no-auth-warning --raw \
            -h 127.0.0.1 -p 6379 "$@"
    ' sh "$@"
}

cleanup_restore_redis()
{
    [[ -z ${restore_redis_container:-} ]] \
        || docker rm -f "$restore_redis_container" >/dev/null 2>&1 || true
    [[ -z ${restore_redis_volume:-} ]] \
        || docker volume rm "$restore_redis_volume" >/dev/null 2>&1 || true
    [[ -z ${restore_redis_network:-} ]] \
        || docker network rm "$restore_redis_network" >/dev/null 2>&1 || true
}

restore_redis_clone()
{
    local password expected_image_id observed_keyspace_sha256

    for resource in "$restore_redis_container" "$restore_redis_volume" "$restore_redis_network"; do
        [[ $resource != "${expected_source_redis_endpoint%:*}" ]] \
            || fail 'restore Redis resource aliases the source Redis endpoint'
    done
    ! docker inspect "$restore_redis_container" >/dev/null 2>&1 \
        || fail 'restore Redis container must be freshly absent'
    ! docker volume inspect "$restore_redis_volume" >/dev/null 2>&1 \
        || fail 'restore Redis volume must be freshly absent'
    ! docker network inspect "$restore_redis_network" >/dev/null 2>&1 \
        || fail 'restore Redis network must be freshly absent'

    password=$(cat "$restore_redis_password_file")
    [[ -n $password && $password != *$'\n'* && $password != *$'\r'* ]] \
        || fail 'restore Redis password file is empty or multiline'
    created_unix=$(date +%s)
    docker volume create \
        --label coolify.control-plane.backup-restore.resource=true \
        --label coolify.control-plane.backup-restore.redis=true \
        --label "coolify.control-plane.backup-restore.operation=$operation_id" \
        --label "coolify.control-plane.backup-restore.created-unix=$created_unix" \
        "$restore_redis_volume" >/dev/null
    docker network create --internal \
        --label coolify.control-plane.backup-restore.resource=true \
        --label coolify.control-plane.backup-restore.redis=true \
        --label "coolify.control-plane.backup-restore.operation=$operation_id" \
        --label "coolify.control-plane.backup-restore.created-unix=$created_unix" \
        "$restore_redis_network" >/dev/null
    docker create --name "$restore_redis_container" --network "$restore_redis_network" \
        --label coolify.control-plane.backup-restore.disposable=true \
        --label coolify.control-plane.backup-restore.redis=true \
        --label "coolify.control-plane.backup-restore.operation=$operation_id" \
        --label "coolify.control-plane.backup-restore.created-unix=$created_unix" \
        --env "REDIS_PASSWORD=$password" --volume "$restore_redis_volume:/data" \
        --entrypoint /bin/sh "$expected_source_redis_image_digest" -eu -c '
            chown redis:redis /data/dump.rdb
            chmod 600 /data/dump.rdb
            exec setpriv --reuid=redis --regid=redis --init-groups \
                redis-server --dir /data --dbfilename dump.rdb \
                --save "" --appendonly no --protected-mode yes \
                --requirepass "$REDIS_PASSWORD"
        ' >/dev/null
    docker cp "$redis_plaintext" "$restore_redis_container:/data/dump.rdb" >/dev/null
    docker start "$restore_redis_container" >/dev/null
    for _ in $(seq 1 60); do
        [[ $(restore_redis_cli PING 2>/dev/null || true) != PONG ]] || break
        sleep 1
    done
    [[ $(restore_redis_cli PING) == PONG ]] || fail 'restored Redis clone did not become ready'

    restore_redis_container_id=$(docker inspect --format '{{.Id}}' "$restore_redis_container")
    restore_redis_image_id=$(docker inspect --format '{{.Image}}' "$restore_redis_container")
    expected_image_id=$(docker image inspect --format '{{.Id}}' "$expected_source_redis_image_digest")
    restore_redis_endpoint="$restore_redis_container:6379"
    observed_keyspace_sha256=$(restore_redis_cli INFO keyspace | tr -d '\r' \
        | awk -F, '/^db[0-9]+:keys=[0-9]+,expires=[0-9]+/ { print $1 "," $2 }' \
        | LC_ALL=C sort | sha256sum | awk '{print $1}')
    restore_redis_dbsize=$(restore_redis_cli DBSIZE)
    restore_redis_keyspace_sha256=$observed_keyspace_sha256
    [[ $restore_redis_container_id =~ ^[0-9a-f]{64}$ \
        && $restore_redis_image_id == "$expected_image_id" \
        && $restore_redis_image_id =~ ^sha256:[0-9a-f]{64}$ \
        && $restore_redis_dbsize == $(field_value "$capture_manifest" source_redis_dbsize) \
        && $restore_redis_keyspace_sha256 == \
            $(field_value "$capture_manifest" source_redis_keyspace_sha256) \
        && $(docker network inspect --format '{{.Internal}}' "$restore_redis_network") == true \
        && $(docker network inspect --format '{{len .Containers}}' "$restore_redis_network") == 1 ]] \
        || fail 'restored Redis clone identity, isolation, or captured keyspace differs'
    unset password
}

assert_disposable_target()
{
    [[ $(docker inspect --format '{{.State.Running}}' "$restore_target_container") == true ]] \
        || fail 'restore target container is not running'
    [[ $(docker inspect --format \
        '{{index .Config.Labels "coolify.control-plane.backup-restore.disposable"}}' \
        "$restore_target_container") == true ]] \
        || fail 'restore target is not explicitly labeled disposable'
    [[ $(docker inspect --format \
        '{{index .Config.Labels "coolify.control-plane.backup-restore.operation"}}' \
        "$restore_target_container") == "$operation_id" ]] \
        || fail 'restore target operation label differs from this operation'

    restore_pg_version=$(target_psql_admin --command 'SHOW server_version' | tr -d '[:space:]')
    [[ $restore_pg_version == "$REQUIRED_POSTGRES_VERSION" ]] \
        || fail "restore target PostgreSQL must be exactly ${REQUIRED_POSTGRES_VERSION}"
    restore_pg_system_identifier=$(target_psql_admin --command \
        'SELECT system_identifier::text FROM pg_control_system()' | tr -d '[:space:]')
    [[ $restore_pg_system_identifier =~ ^[0-9]+$ \
        && $restore_pg_system_identifier != "$expected_source_pg_system_identifier" ]] \
        || fail 'restore target must have a distinct PostgreSQL system identifier'
    user_database_count=$(target_psql_admin --command \
        "SELECT count(*) FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres'" \
        | tr -d '[:space:]')
    [[ $user_database_count == 0 ]] || fail 'restore target is not a fresh disposable cluster'
    relation_count=$(target_psql_admin --command \
        "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast')" \
        | tr -d '[:space:]')
    [[ $relation_count == 0 ]] || fail 'restore target postgres database is not empty'
}

run_strict_hook()
{
    local hook=$1 expected_line=$2 hook_name=$3 log_file

    log_file="$work_directory/${hook_name}.log"
    if ! "$hook" > "$log_file" 2>&1; then
        sed -n '1,20p' "$log_file" >&2
        fail "$hook_name hook failed"
    fi
    [[ $(wc -l < "$log_file" | tr -d '[:space:]') == 1 \
        && $(cat "$log_file") == "$expected_line" ]] \
        || fail "$hook_name hook did not emit its one exact success attestation"
}

assert_inputs_unchanged()
{
    assert_capture_inputs
    [[ $(sha256_file "$operator_rehearse_migrations_hook") == "$rehearsal_hook_sha256" \
        && $(sha256_file "$candidate_boot_hook") == "$candidate_boot_hook_sha256" \
        && $(sha256_file "$candidate_api_probe_hook") == "$candidate_probe_hook_sha256" \
        && $(sha256_file "$state_proof_tool") == "$state_proof_tool_sha256" \
        && $(sha256_file "$restore_redis_password_file") == "$restore_redis_password_sha256" ]] \
        || fail 'restore hook input drift was detected'
}

assert_candidate_runtime_identity()
{
    local expected_repository_digest expected_image_id observed_image_id repository_digests

    expected_repository_digest=${expected_candidate_image_digest##*@}
    expected_image_id=$(docker image inspect --format '{{.Id}}' "$expected_candidate_image_digest")
    observed_image_id=$(docker inspect --format '{{.Image}}' "$candidate_runtime_container")
    repository_digests=$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' \
        "$expected_candidate_image_digest")
    [[ $(docker inspect --format '{{.State.Running}}' "$candidate_runtime_container") == true \
        && $observed_image_id == "$expected_image_id" \
        && $observed_image_id =~ ^sha256:[0-9a-f]{64}$ ]] \
        || fail 'candidate runtime image identity differs from the expected immutable image'
    grep -Eq "@${expected_repository_digest}$" <<< "$repository_digests" \
        || fail 'candidate runtime image does not retain the expected repository digest'
    [[ $(docker inspect --format \
        '{{index .Config.Labels "coolify.control-plane.backup-restore.candidate"}}' \
        "$candidate_runtime_container") == true \
        && $(docker inspect --format \
            '{{index .Config.Labels "coolify.control-plane.backup-restore.operation"}}' \
            "$candidate_runtime_container") == "$operation_id" ]] \
        || fail 'candidate runtime is missing exact attested-operation ownership labels'
    candidate_runtime_image_id=$observed_image_id
}

candidate_env_value()
{
    local key=$1

    docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$candidate_runtime_container" \
        | awk -F= -v requested_key="$key" '$1 == requested_key { print substr($0, length($1) + 2) }'
}

assert_candidate_clone_endpoints()
{
    local candidate_database_host candidate_redis_host

    candidate_database_host=$(candidate_env_value DB_HOST)
    [[ -n $candidate_database_host ]] || candidate_database_host=$(candidate_env_value PGHOST)
    candidate_redis_host=$(candidate_env_value REDIS_HOST)
    [[ $candidate_database_host == "$restore_target_container" \
        && $candidate_redis_host == "$restore_redis_container" \
        && $(candidate_env_value CONTROL_PLANE_STARTUP_MODE) == web-only \
        && $candidate_database_host != "${expected_source_redis_endpoint%:*}" \
        && $candidate_redis_host != "${expected_source_redis_endpoint%:*}" ]] \
        || fail 'candidate runtime does not use the exact isolated PostgreSQL and Redis clones'
}

assert_candidate_state_proof()
{
    local proof_line

    assert_root_file "$candidate_state_proof_file" 400
    [[ $(wc -l < "$candidate_state_proof_file" | tr -d '[:space:]') == 1 ]] \
        || fail 'candidate-observed state proof must contain exactly one line'
    proof_line=$(cat "$candidate_state_proof_file")
    [[ $proof_line =~ ^control_plane_state_proof_sha256=([0-9a-f]{64})$ ]] \
        || fail 'candidate-observed state proof has an invalid grammar'
    candidate_observed_state_proof_sha256=${BASH_REMATCH[1]}
    [[ $candidate_observed_state_proof_sha256 == "$restored_state_proof_sha256" ]] \
        || fail 'candidate did not observe the exact restored control-plane state'
}

emit_attestation()
{
    local restore_verified_unix generated_unix payload_sha256

    restore_verified_unix=$(date +%s)
    generated_unix=$(date +%s)
    {
        printf 'attestation_version=2\n'
        printf 'operation_id=%s\n' "$operation_id"
        printf 'source_pg_system_identifier=%s\n' "$expected_source_pg_system_identifier"
        printf 'source_pg_database_oid=%s\n' "$expected_source_pg_database_oid"
        printf 'source_pg_version=%s\n' "$expected_source_pg_version"
        printf 'source_database_name=%s\n' "$expected_source_database_name"
        printf 'source_host_identity=%s\n' "$expected_source_host_identity"
        printf 'source_redis_container_id=%s\n' "$expected_source_redis_container_id"
        printf 'source_redis_image_digest=%s\n' "$expected_source_redis_image_digest"
        printf 'source_redis_endpoint=%s\n' "$expected_source_redis_endpoint"
        printf 'source_redis_version=%s\n' \
            "$(field_value "$capture_manifest" source_redis_version)"
        printf 'quiesce_protocol=%s\n' "$(field_value "$capture_manifest" quiesce_protocol)"
        printf 'quiesce_operator_sha256=%s\n' \
            "$(field_value "$capture_manifest" quiesce_operator_sha256)"
        printf 'quiesce_fencing_token_sha256=%s\n' \
            "$(field_value "$capture_manifest" quiesce_fencing_token_sha256)"
        printf 'quiesce_lease_requested_seconds=%s\n' \
            "$(field_value "$capture_manifest" quiesce_lease_requested_seconds)"
        printf 'quiesce_lease_acquired_unix=%s\n' \
            "$(field_value "$capture_manifest" quiesce_lease_acquired_unix)"
        printf 'quiesce_lease_expires_unix=%s\n' \
            "$(field_value "$capture_manifest" quiesce_lease_expires_unix)"
        printf 'quiesce_released_unix=%s\n' \
            "$(field_value "$capture_manifest" quiesce_released_unix)"
        printf 'database_dump_sha256=%s\n' "$(field_value "$capture_manifest" database_dump_sha256)"
        printf 'database_dump_size_bytes=%s\n' "$(field_value "$capture_manifest" database_dump_size_bytes)"
        printf 'database_dump_created_unix=%s\n' "$(field_value "$capture_manifest" database_dump_created_unix)"
        printf 'database_dump_encryption=openpgp\n'
        printf 'redis_snapshot_sha256=%s\n' \
            "$(field_value "$capture_manifest" redis_snapshot_sha256)"
        printf 'redis_snapshot_size_bytes=%s\n' \
            "$(field_value "$capture_manifest" redis_snapshot_size_bytes)"
        printf 'redis_snapshot_created_unix=%s\n' \
            "$(field_value "$capture_manifest" redis_snapshot_created_unix)"
        printf 'redis_snapshot_encryption=openpgp\n'
        printf 'redis_recovery_semantics=quiesced-exact-rdb-no-worker-replay-v1\n'
        printf 'control_plane_state_archive_sha256=%s\n' \
            "$(field_value "$capture_manifest" control_plane_state_archive_sha256)"
        printf 'control_plane_state_archive_size_bytes=%s\n' \
            "$(field_value "$capture_manifest" control_plane_state_archive_size_bytes)"
        printf 'control_plane_state_archive_encryption=openpgp\n'
        printf 'encryption_recipient_fingerprint=%s\n' "$expected_recipient_fingerprint"
        printf 'source_capture_manifest_sha256=%s\n' "$expected_capture_manifest_sha256"
        printf 'source_capture_manifest_size_bytes=%s\n' "$(file_size "$capture_manifest")"
        printf 'candidate_image_digest=%s\n' "$expected_candidate_image_digest"
        printf 'candidate_runtime_image_id=%s\n' "$candidate_runtime_image_id"
        printf 'restore_target_identity=%s\n' "$restore_target_identity"
        printf 'restore_pg_system_identifier=%s\n' "$restore_pg_system_identifier"
        printf 'restore_database_oid=%s\n' "$restore_database_oid"
        printf 'restore_pg_version=%s\n' "$restore_pg_version"
        printf 'restore_fresh_clone=passed\n'
        printf 'restore_redis_container_id=%s\n' "$restore_redis_container_id"
        printf 'restore_redis_image_id=%s\n' "$restore_redis_image_id"
        printf 'restore_redis_endpoint=%s\n' "$restore_redis_endpoint"
        printf 'restore_redis_dbsize=%s\n' "$restore_redis_dbsize"
        printf 'restore_redis_keyspace_sha256=%s\n' "$restore_redis_keyspace_sha256"
        printf 'restore_redis_fresh_clone=passed\n'
        printf 'restore_method=operator-rehearse-migrations\n'
        printf 'restored_state_selection_sha256=%s\n' "$restored_state_selection_sha256"
        printf 'restored_state_proof_sha256=%s\n' "$restored_state_proof_sha256"
        printf 'candidate_observed_state_proof_sha256=%s\n' \
            "$candidate_observed_state_proof_sha256"
        printf 'control_plane_state_restore=passed\n'
        printf 'redis_restore=passed\n'
        printf 'candidate_clone_endpoints=passed\n'
        printf 'state_proof_tool_sha256=%s\n' "$state_proof_tool_sha256"
        printf 'operator_rehearsal_hook_sha256=%s\n' "$rehearsal_hook_sha256"
        printf 'candidate_boot_hook_sha256=%s\n' "$candidate_boot_hook_sha256"
        printf 'candidate_api_probe_hook_sha256=%s\n' "$candidate_probe_hook_sha256"
        printf 'restore_rehearsal=passed\n'
        printf 'candidate_boot=passed\n'
        printf 'candidate_api_probe=passed\n'
        printf 'restore_verified_unix=%s\n' "$restore_verified_unix"
        printf 'generated_unix=%s\n' "$generated_unix"
        printf 'backup_tool_sha256=%s\n' "$(field_value "$capture_manifest" backup_tool_sha256)"
        printf 'restore_tool_sha256=%s\n' "$restore_tool_sha256"
    } > "$attestation_output"
    payload_sha256=$(sha256_file "$attestation_output")
    printf 'attestation_payload_sha256=%s\n' "$payload_sha256" >> "$attestation_output"
    chmod 400 "$attestation_output"
    sync
    assert_root_file "$attestation_output" 400
    validate_key_order "$attestation_output" "$ATTESTATION_KEYS"
    assert_payload_digest "$attestation_output" attestation_payload_sha256
}

verify_attestation()
{
    local attestation_sha generated_unix restore_verified_unix current_unix

    assert_capture_inputs
    assert_root_file "$attestation" 400
    validate_sha256 "$expected_attestation_sha256" 'expected attestation digest'
    attestation_sha=$(sha256_file "$attestation")
    [[ $attestation_sha == "$expected_attestation_sha256" ]] \
        || fail 'attestation does not match its whole-file pinned digest'
    validate_key_order "$attestation" "$ATTESTATION_KEYS"
    assert_payload_digest "$attestation" attestation_payload_sha256
    [[ $(field_value "$attestation" attestation_version) == 2 \
        && $(field_value "$attestation" operation_id) == "$operation_id" \
        && $(field_value "$attestation" source_pg_system_identifier) == \
            "$expected_source_pg_system_identifier" \
        && $(field_value "$attestation" source_pg_database_oid) == \
            "$expected_source_pg_database_oid" \
        && $(field_value "$attestation" source_pg_version) == "$expected_source_pg_version" \
        && $(field_value "$attestation" source_database_name) == "$expected_source_database_name" \
        && $(field_value "$attestation" source_host_identity) == "$expected_source_host_identity" \
        && $(field_value "$attestation" source_redis_container_id) == \
            "$expected_source_redis_container_id" \
        && $(field_value "$attestation" source_redis_image_digest) == \
            "$expected_source_redis_image_digest" \
        && $(field_value "$attestation" source_redis_endpoint) == \
            "$expected_source_redis_endpoint" \
        && $(field_value "$attestation" quiesce_protocol) == \
            $(field_value "$capture_manifest" quiesce_protocol) \
        && $(field_value "$attestation" quiesce_operator_sha256) == \
            $(field_value "$capture_manifest" quiesce_operator_sha256) \
        && $(field_value "$attestation" quiesce_fencing_token_sha256) == \
            $(field_value "$capture_manifest" quiesce_fencing_token_sha256) \
        && $(field_value "$attestation" quiesce_lease_requested_seconds) == \
            $(field_value "$capture_manifest" quiesce_lease_requested_seconds) \
        && $(field_value "$attestation" quiesce_lease_acquired_unix) == \
            $(field_value "$capture_manifest" quiesce_lease_acquired_unix) \
        && $(field_value "$attestation" quiesce_lease_expires_unix) == \
            $(field_value "$capture_manifest" quiesce_lease_expires_unix) \
        && $(field_value "$attestation" quiesce_released_unix) == \
            $(field_value "$capture_manifest" quiesce_released_unix) \
        && $(field_value "$attestation" candidate_image_digest) == \
            "$expected_candidate_image_digest" \
        && $(field_value "$attestation" candidate_runtime_image_id) =~ ^sha256:[0-9a-f]{64}$ \
        && $(field_value "$attestation" restore_target_identity) == \
            "$expected_restore_target_identity" \
        && $(field_value "$attestation" encryption_recipient_fingerprint) == \
            "$expected_recipient_fingerprint" ]] \
        || fail 'attestation identity binding differs from expected inputs'
    [[ $(field_value "$attestation" database_dump_sha256) == $(sha256_file "$database_dump") \
        && $(field_value "$attestation" database_dump_size_bytes) == $(file_size "$database_dump") \
        && $(field_value "$attestation" database_dump_created_unix) == \
            $(field_value "$capture_manifest" database_dump_created_unix) \
        && $(field_value "$attestation" database_dump_encryption) == openpgp \
        && $(field_value "$attestation" redis_snapshot_sha256) == \
            $(sha256_file "$redis_snapshot") \
        && $(field_value "$attestation" redis_snapshot_size_bytes) == \
            $(file_size "$redis_snapshot") \
        && $(field_value "$attestation" redis_snapshot_created_unix) == \
            $(field_value "$capture_manifest" redis_snapshot_created_unix) \
        && $(field_value "$attestation" redis_snapshot_encryption) == openpgp \
        && $(field_value "$attestation" redis_recovery_semantics) == \
            quiesced-exact-rdb-no-worker-replay-v1 \
        && $(field_value "$attestation" control_plane_state_archive_sha256) == \
            $(sha256_file "$state_archive") \
        && $(field_value "$attestation" control_plane_state_archive_size_bytes) == \
            $(file_size "$state_archive") \
        && $(field_value "$attestation" control_plane_state_archive_encryption) == openpgp \
        && $(field_value "$attestation" source_capture_manifest_sha256) == \
            "$expected_capture_manifest_sha256" \
        && $(field_value "$attestation" source_capture_manifest_size_bytes) == \
            $(file_size "$capture_manifest") ]] \
        || fail 'attestation artifact binding is invalid'
    [[ $(field_value "$attestation" restore_pg_system_identifier) =~ ^[0-9]+$ \
        && $(field_value "$attestation" restore_pg_system_identifier) != \
            "$expected_source_pg_system_identifier" \
        && $(field_value "$attestation" restore_database_oid) =~ ^[1-9][0-9]*$ \
        && $(field_value "$attestation" restore_pg_version) == "$REQUIRED_POSTGRES_VERSION" \
        && $(field_value "$attestation" restore_fresh_clone) == passed \
        && $(field_value "$attestation" restore_redis_container_id) =~ ^[0-9a-f]{64}$ \
        && $(field_value "$attestation" restore_redis_image_id) =~ ^sha256:[0-9a-f]{64}$ \
        && $(field_value "$attestation" restore_redis_endpoint) != \
            "$expected_source_redis_endpoint" \
        && $(field_value "$attestation" restore_redis_dbsize) == \
            $(field_value "$capture_manifest" source_redis_dbsize) \
        && $(field_value "$attestation" restore_redis_keyspace_sha256) == \
            $(field_value "$capture_manifest" source_redis_keyspace_sha256) \
        && $(field_value "$attestation" restore_redis_fresh_clone) == passed \
        && $(field_value "$attestation" restore_method) == operator-rehearse-migrations \
        && $(field_value "$attestation" restored_state_selection_sha256) == \
            $(field_value "$capture_manifest" source_state_selection_sha256) \
        && $(field_value "$attestation" restored_state_proof_sha256) =~ ^[0-9a-f]{64}$ \
        && $(field_value "$attestation" candidate_observed_state_proof_sha256) == \
            $(field_value "$attestation" restored_state_proof_sha256) \
        && $(field_value "$attestation" control_plane_state_restore) == passed \
        && $(field_value "$attestation" redis_restore) == passed \
        && $(field_value "$attestation" candidate_clone_endpoints) == passed \
        && $(field_value "$attestation" state_proof_tool_sha256) == \
            "$expected_state_proof_tool_sha256" \
        && $(field_value "$attestation" operator_rehearsal_hook_sha256) == \
            "$expected_operator_rehearsal_hook_sha256" \
        && $(field_value "$attestation" candidate_boot_hook_sha256) == \
            "$expected_candidate_boot_hook_sha256" \
        && $(field_value "$attestation" candidate_api_probe_hook_sha256) == \
            "$expected_candidate_api_probe_hook_sha256" \
        && $(field_value "$attestation" restore_rehearsal) == passed \
        && $(field_value "$attestation" candidate_boot) == passed \
        && $(field_value "$attestation" candidate_api_probe) == passed ]] \
        || fail 'attestation does not prove the required restore, rehearsal, boot, and probe gates'
    [[ $(field_value "$attestation" backup_tool_sha256) == \
        $(field_value "$capture_manifest" backup_tool_sha256) \
        && $(field_value "$attestation" restore_tool_sha256) == "$restore_tool_sha256" ]] \
        || fail 'attestation tool binding is invalid'
    [[ $expected_source_host_identity != "$expected_restore_target_identity" ]] \
        || fail 'restore attestation must be generated on a distinct off-host target'

    generated_unix=$(field_value "$attestation" generated_unix)
    restore_verified_unix=$(field_value "$attestation" restore_verified_unix)
    validate_positive_integer "$generated_unix" 'attestation generated timestamp'
    validate_positive_integer "$restore_verified_unix" 'attestation restore timestamp'
    current_unix=$(date +%s)
    (( restore_verified_unix <= generated_unix && generated_unix <= current_unix + 60 \
        && current_unix - generated_unix <= max_age_seconds )) \
        || fail 'attestation is stale, future-dated, or timestamp-inconsistent'
    printf 'attestation-validation=passed\n'
}

command_name=${1:-}
[[ $command_name == restore || $command_name == verify-attestation ]] || usage
shift

operation_id=
database_dump=
redis_snapshot=
state_archive=
capture_manifest=
expected_capture_manifest_sha256=
expected_source_pg_system_identifier=
expected_source_pg_database_oid=
expected_source_pg_version=
expected_source_database_name=
expected_source_host_identity=
expected_source_redis_container_id=
expected_source_redis_image_digest=
expected_source_redis_endpoint=
expected_candidate_image_digest=
expected_recipient_fingerprint=
expected_quiesce_operator_sha256=
restore_target_container=
restore_database_user=
restore_redis_container=
restore_redis_volume=
restore_redis_network=
restore_redis_password_file=
expected_restore_target_identity=
max_age_seconds=
operator_rehearse_migrations_hook=
candidate_boot_hook=
candidate_api_probe_hook=
candidate_runtime_container=
expected_operator_rehearsal_hook_sha256=
expected_candidate_boot_hook_sha256=
expected_candidate_api_probe_hook_sha256=
restore_state_root=
plaintext_tmpfs_root=
state_proof_tool=
expected_state_proof_tool_sha256=
stale_work_max_age_seconds=
attestation_output=
attestation=
expected_attestation_sha256=

while [[ $# -gt 0 ]]; do
    case $1 in
        --operation-id|--database-dump|--redis-snapshot|--state-archive|--capture-manifest|--expected-capture-manifest-sha256|--expected-source-pg-system-identifier|--expected-source-pg-database-oid|--expected-source-pg-version|--expected-source-database-name|--expected-source-host-identity|--expected-source-redis-container-id|--expected-source-redis-image-digest|--expected-source-redis-endpoint|--expected-candidate-image-digest|--expected-recipient-fingerprint|--expected-quiesce-operator-sha256|--restore-target-container|--restore-database-user|--restore-redis-container|--restore-redis-volume|--restore-redis-network|--restore-redis-password-file|--expected-restore-target-identity|--max-age-seconds|--operator-rehearse-migrations-hook|--candidate-boot-hook|--candidate-api-probe-hook|--candidate-runtime-container|--expected-operator-rehearsal-hook-sha256|--expected-candidate-boot-hook-sha256|--expected-candidate-api-probe-hook-sha256|--restore-state-root|--plaintext-tmpfs-root|--state-proof-tool|--expected-state-proof-tool-sha256|--stale-work-max-age-seconds|--attestation-output|--attestation|--expected-attestation-sha256)
            [[ $# -ge 2 ]] || usage
            option_name=${1#--}
            option_name=${option_name//-/_}
            printf -v "$option_name" '%s' "$2"
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

[[ $(id -u) == 0 && $(id -g) == 0 ]] || fail 'restore and validation must run as root'
for required_command in awk date dirname docker find flock gpg grep hostname readlink sed seq sha256sum sort stat sync tar tr wc; do
    require_command "$required_command"
done

script_path=$(readlink -f "${BASH_SOURCE[0]}")
assert_root_file "$script_path" 755
restore_tool_sha256=$(sha256_file "$script_path")

[[ -n $operation_id && -n $database_dump && -n $redis_snapshot \
    && -n $state_archive && -n $capture_manifest \
    && -n $expected_capture_manifest_sha256 && -n $expected_source_pg_system_identifier \
    && -n $expected_source_pg_database_oid && -n $expected_source_pg_version \
    && -n $expected_source_database_name && -n $expected_source_host_identity \
    && -n $expected_source_redis_container_id \
    && -n $expected_source_redis_image_digest && -n $expected_source_redis_endpoint \
    && -n $expected_candidate_image_digest && -n $expected_recipient_fingerprint \
    && -n $expected_quiesce_operator_sha256 \
    && -n $expected_restore_target_identity && -n $max_age_seconds \
    && -n $expected_operator_rehearsal_hook_sha256 \
    && -n $expected_candidate_boot_hook_sha256 \
    && -n $expected_candidate_api_probe_hook_sha256 \
    && -n $expected_state_proof_tool_sha256 ]] || usage
validate_operation_id "$operation_id"
validate_identifier "$expected_source_database_name" 'source database name'
validate_host_identity "$expected_source_host_identity"
validate_container_id "$expected_source_redis_container_id" 'expected source Redis container id'
validate_candidate_digest "$expected_source_redis_image_digest"
validate_redis_endpoint "$expected_source_redis_endpoint" 'expected source Redis endpoint'
validate_host_identity "$expected_restore_target_identity"
validate_candidate_digest "$expected_candidate_image_digest"
validate_positive_integer "$expected_source_pg_system_identifier" 'source PostgreSQL system identifier'
validate_positive_integer "$expected_source_pg_database_oid" 'source database OID'
validate_positive_integer "$max_age_seconds" 'maximum age'
validate_sha256 "$expected_operator_rehearsal_hook_sha256" 'expected rehearsal hook digest'
validate_sha256 "$expected_candidate_boot_hook_sha256" 'expected candidate boot hook digest'
validate_sha256 "$expected_candidate_api_probe_hook_sha256" 'expected candidate API probe hook digest'
validate_sha256 "$expected_quiesce_operator_sha256" 'expected quiesce operator digest'
validate_sha256 "$expected_state_proof_tool_sha256" 'expected state proof tool digest'
expected_recipient_fingerprint=${expected_recipient_fingerprint^^}
[[ $expected_recipient_fingerprint =~ ^([A-F0-9]{40}|[A-F0-9]{64})$ ]] \
    || fail 'expected recipient fingerprint is invalid'

for input_path in "$database_dump" "$redis_snapshot" "$state_archive" "$capture_manifest"; do
    [[ $input_path == /* ]] || fail 'artifact paths must be absolute'
done

if [[ $command_name == verify-attestation ]]; then
    [[ -n $attestation && -n $expected_attestation_sha256 ]] || usage
    verify_attestation
    exit 0
fi

gnupg_home=${GNUPGHOME:-}
[[ -n $gnupg_home && $gnupg_home == /* ]] || fail 'GNUPGHOME must be an absolute directory'
assert_secure_directory "$gnupg_home"

[[ -n $restore_target_container && -n $restore_database_user \
    && -n $restore_redis_container && -n $restore_redis_volume \
    && -n $restore_redis_network && -n $restore_redis_password_file \
    && -n $operator_rehearse_migrations_hook && -n $candidate_boot_hook \
    && -n $candidate_api_probe_hook && -n $candidate_runtime_container \
    && -n $restore_state_root && -n $plaintext_tmpfs_root && -n $state_proof_tool \
    && -n $stale_work_max_age_seconds && -n $attestation_output ]] || usage
validate_container_name "$restore_target_container"
validate_container_name "$restore_redis_container"
validate_container_name "$restore_redis_volume"
validate_container_name "$restore_redis_network"
validate_container_name "$candidate_runtime_container"
validate_identifier "$restore_database_user" 'restore database user'
validate_positive_integer "$stale_work_max_age_seconds" 'stale work maximum age'
[[ $restore_state_root == /* ]] || fail 'restore state root must be absolute'
assert_tmpfs_directory "$plaintext_tmpfs_root"
assert_root_file "$restore_redis_password_file" 600
restore_redis_password_sha256=$(sha256_file "$restore_redis_password_file")
stale_state_reaped=0
prepare_restore_state_root
runtime_parent=$(dirname "$attestation_output")
[[ $runtime_parent == /* ]] || fail 'attestation output path must be absolute'
assert_secure_directory "$runtime_parent"
assert_root_file "$state_proof_tool" 755
state_proof_tool_sha256=$(sha256_file "$state_proof_tool")
[[ $state_proof_tool_sha256 == "$expected_state_proof_tool_sha256" ]] \
    || fail 'state proof tool does not match its independently pinned digest'
[[ ! -e $attestation_output && ! -L $attestation_output ]] \
    || fail 'attestation output already exists'
for hook in "$operator_rehearse_migrations_hook" "$candidate_boot_hook" \
    "$candidate_api_probe_hook"; do
    assert_root_file "$hook" 755
done

lock_file="$plaintext_tmpfs_root/.control-plane-backup-restore-${operation_id}.lock"
exec 9> "$lock_file"
chmod 600 "$lock_file"
flock --nonblock 9 || fail 'another restore for this operation is active'
for stale_directory in "$plaintext_tmpfs_root/.backup-restore-${operation_id}."*; do
    [[ -e $stale_directory || -L $stale_directory ]] || continue
    assert_secure_directory "$stale_directory"
    marker_is_stale "$stale_directory/.backup-restore-operation" \
        || fail 'plaintext work directory is active, unsafe, or not stale'
    clear_directory_contents "$stale_directory"
    rmdir "$stale_directory"
done
work_directory=$(mktemp -d "$plaintext_tmpfs_root/.backup-restore-${operation_id}.XXXXXX")
chmod 700 "$work_directory"
printf '%s:%s\n' "$operation_id" "$(date +%s)" \
    > "$work_directory/.backup-restore-operation"
chmod 400 "$work_directory/.backup-restore-operation"
candidate_state_proof_file="$work_directory/candidate-state-proof"
restore_succeeded=0
restore_state_committed=0
restore_staging_directory=
database_created=0
candidate_was_absent=0
trap cleanup_after_failure EXIT HUP INT TERM

restore_target_identity=$(hostname -f 2>/dev/null || hostname)
restore_target_identity=${restore_target_identity//$'\n'/}
validate_host_identity "$restore_target_identity"
[[ $restore_target_identity == "$expected_restore_target_identity" \
    && $restore_target_identity != "$expected_source_host_identity" ]] \
    || fail 'restore must run on the exact distinct off-host target'

primary_fingerprint=$(gpg --batch --with-colons --fingerprint "$expected_recipient_fingerprint" \
    2>/dev/null | awk -F: '$1 == "fpr" { print toupper($10); exit }')
[[ $primary_fingerprint == "$expected_recipient_fingerprint" ]] \
    || fail 'the exact OpenPGP recipient key is unavailable off-host'
gpg --batch --with-colons --list-secret-keys "$expected_recipient_fingerprint" 2>/dev/null \
    | grep -q '^sec:' || fail 'the exact OpenPGP private key is unavailable off-host'

assert_capture_inputs
rehearsal_hook_sha256=$(sha256_file "$operator_rehearse_migrations_hook")
candidate_boot_hook_sha256=$(sha256_file "$candidate_boot_hook")
candidate_probe_hook_sha256=$(sha256_file "$candidate_api_probe_hook")
[[ $rehearsal_hook_sha256 == "$expected_operator_rehearsal_hook_sha256" \
    && $candidate_boot_hook_sha256 == "$expected_candidate_boot_hook_sha256" \
    && $candidate_probe_hook_sha256 == "$expected_candidate_api_probe_hook_sha256" ]] \
    || fail 'restore hook does not match its independently pinned digest'

database_plaintext="$work_directory/database.pgdump"
redis_plaintext="$work_directory/redis.rdb"
state_plaintext="$work_directory/control-plane-state.tar"
decrypt_file "$database_dump" "$database_plaintext"
decrypt_file "$redis_snapshot" "$redis_plaintext"
decrypt_file "$state_archive" "$state_plaintext"
[[ $(sha256_file "$database_plaintext") == \
    $(field_value "$capture_manifest" database_dump_plaintext_sha256) \
    && $(file_size "$database_plaintext") == \
        $(field_value "$capture_manifest" database_dump_plaintext_size_bytes) ]] \
    || fail 'decrypted database dump fingerprint differs from source capture'
[[ $(sha256_file "$redis_plaintext") == \
    $(field_value "$capture_manifest" redis_snapshot_plaintext_sha256) \
    && $(file_size "$redis_plaintext") == \
        $(field_value "$capture_manifest" redis_snapshot_plaintext_size_bytes) ]] \
    || fail 'decrypted Redis snapshot fingerprint differs from source capture'
[[ $(sha256_file "$state_plaintext") == \
    $(field_value "$capture_manifest" control_plane_state_archive_plaintext_sha256) \
    && $(file_size "$state_plaintext") == \
        $(field_value "$capture_manifest" control_plane_state_archive_plaintext_size_bytes) ]] \
    || fail 'decrypted state archive fingerprint differs from source capture'

reap_stale_database
assert_disposable_target
docker exec -i "$restore_target_container" pg_restore --list \
    < "$database_plaintext" >/dev/null
validate_and_extract_state_archive "$state_plaintext"
restore_redis_clone

docker exec "$restore_target_container" createdb --no-password --template=template0 \
    --username "$restore_database_user" "$expected_source_database_name"
database_created=1
docker exec -i "$restore_target_container" pg_restore --exit-on-error --single-transaction \
    --no-owner --no-privileges --username "$restore_database_user" \
    --dbname "$expected_source_database_name" < "$database_plaintext" >/dev/null
restore_database_oid=$(target_psql_database --command \
    'SELECT oid::text FROM pg_database WHERE datname = current_database()' | tr -d '[:space:]')
[[ $restore_database_oid =~ ^[1-9][0-9]*$ ]] || fail 'restored database OID is invalid'
fingerprint_restrict_key=$(printf '%s' "$operation_id" | sha256sum | awk '{print substr($1, 1, 32)}')
[[ $(target_database_fingerprint schema "$fingerprint_restrict_key") == \
    $(field_value "$capture_manifest" source_schema_fingerprint_sha256) \
    && $(target_database_fingerprint data "$fingerprint_restrict_key") == \
        $(field_value "$capture_manifest" source_data_fingerprint_sha256) ]] \
    || fail 'restored PostgreSQL schema or data fingerprint differs from source capture'

export CONTROL_PLANE_OPERATION_ID="$operation_id"
export CONTROL_PLANE_CANDIDATE_IMAGE_DIGEST="$expected_candidate_image_digest"
export CONTROL_PLANE_RESTORE_DATABASE_CONTAINER="$restore_target_container"
export CONTROL_PLANE_RESTORE_DATABASE_USER="$restore_database_user"
export CONTROL_PLANE_RESTORE_DATABASE_NAME="$expected_source_database_name"
export CONTROL_PLANE_SOURCE_REDIS_ENDPOINT="$expected_source_redis_endpoint"
export CONTROL_PLANE_SOURCE_REDIS_IMAGE_DIGEST="$expected_source_redis_image_digest"
export CONTROL_PLANE_RESTORE_REDIS_CONTAINER="$restore_redis_container"
export CONTROL_PLANE_RESTORE_REDIS_ENDPOINT="$restore_redis_endpoint"
export CONTROL_PLANE_RESTORE_REDIS_NETWORK="$restore_redis_network"
export CONTROL_PLANE_RESTORE_REDIS_PASSWORD_FILE="$restore_redis_password_file"
export CONTROL_PLANE_SOURCE_PG_SYSTEM_IDENTIFIER="$expected_source_pg_system_identifier"
export CONTROL_PLANE_RESTORE_PG_SYSTEM_IDENTIFIER="$restore_pg_system_identifier"
export CONTROL_PLANE_RESTORE_TARGET_IDENTITY="$restore_target_identity"
export CONTROL_PLANE_CANDIDATE_RUNTIME_CONTAINER="$candidate_runtime_container"
export CONTROL_PLANE_RESTORED_STATE_ROOT="$restore_state_root"
export CONTROL_PLANE_RESTORED_STATE_SELECTION="$restored_state_selection"
export CONTROL_PLANE_RESTORED_STATE_SELECTION_SHA256="$restored_state_selection_sha256"
export CONTROL_PLANE_RESTORED_STATE_PROOF_SHA256="$restored_state_proof_sha256"
export CONTROL_PLANE_STATE_PROOF_TOOL="$state_proof_tool"
export CONTROL_PLANE_CANDIDATE_STATE_PROOF_FILE="$candidate_state_proof_file"
run_strict_hook "$operator_rehearse_migrations_hook" \
    'control-plane-restore-rehearsal=passed' rehearsal
reap_stale_candidate
if docker inspect "$candidate_runtime_container" >/dev/null 2>&1; then
    fail 'candidate runtime container existed before the attested boot hook'
fi
candidate_was_absent=1
run_strict_hook "$candidate_boot_hook" 'control-plane-candidate-boot=passed' candidate-boot
assert_candidate_runtime_identity
assert_candidate_clone_endpoints
run_strict_hook "$candidate_api_probe_hook" \
    'control-plane-candidate-api-probe=passed' candidate-api-probe
assert_candidate_state_proof
assert_inputs_unchanged
emit_attestation
restore_succeeded=1
printf 'restore-attestation=passed\nattestation_sha256=%s\n' "$(sha256_file "$attestation_output")"
