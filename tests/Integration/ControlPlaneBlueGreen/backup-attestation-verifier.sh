#!/bin/sh

set -eu

fail()
{
    printf '%s\n' "CONTROL_PLANE_BLUE_GREEN_BACKUP_VERIFIER_FAILURE $1" >&2
    exit 1
}

take_option()
{
    expected_option=$1
    shift
    [ "$#" -ge 2 ] && [ "$1" = "$expected_option" ] \
        || fail "expected option: $expected_option"
    parsed_option_value=$2
}

[ "${1:-}" = verify-attestation ] || fail 'expected verify-attestation command'
shift

take_option --operation-id "$@"
operation_id=$parsed_option_value
shift 2
take_option --database-dump "$@"
database_dump=$parsed_option_value
shift 2
take_option --redis-snapshot "$@"
redis_snapshot=$parsed_option_value
shift 2
take_option --state-archive "$@"
state_archive=$parsed_option_value
shift 2
take_option --capture-manifest "$@"
capture_manifest=$parsed_option_value
shift 2
take_option --expected-capture-manifest-sha256 "$@"
capture_manifest_sha256=$parsed_option_value
shift 2
take_option --expected-source-pg-system-identifier "$@"
source_pg_system_identifier=$parsed_option_value
shift 2
take_option --expected-source-pg-database-oid "$@"
source_pg_database_oid=$parsed_option_value
shift 2
take_option --expected-source-pg-version "$@"
source_pg_version=$parsed_option_value
shift 2
take_option --expected-source-database-name "$@"
source_database_name=$parsed_option_value
shift 2
take_option --expected-source-host-identity "$@"
source_host_identity=$parsed_option_value
shift 2
take_option --expected-source-redis-container-id "$@"
source_redis_container_id=$parsed_option_value
shift 2
take_option --expected-source-redis-image-digest "$@"
source_redis_image_digest=$parsed_option_value
shift 2
take_option --expected-source-redis-endpoint "$@"
source_redis_endpoint=$parsed_option_value
shift 2
take_option --expected-candidate-image-digest "$@"
candidate_image_digest=$parsed_option_value
shift 2
take_option --expected-recipient-fingerprint "$@"
recipient_fingerprint=$parsed_option_value
shift 2
take_option --expected-quiesce-operator-sha256 "$@"
quiesce_operator_sha256=$parsed_option_value
shift 2
take_option --expected-restore-target-identity "$@"
restore_target_identity=$parsed_option_value
shift 2
take_option --max-age-seconds "$@"
max_age_seconds=$parsed_option_value
shift 2
take_option --expected-operator-rehearsal-hook-sha256 "$@"
operator_rehearsal_hook_sha256=$parsed_option_value
shift 2
take_option --expected-candidate-boot-hook-sha256 "$@"
candidate_boot_hook_sha256=$parsed_option_value
shift 2
take_option --expected-candidate-api-probe-hook-sha256 "$@"
candidate_api_probe_hook_sha256=$parsed_option_value
shift 2
take_option --expected-state-proof-tool-sha256 "$@"
state_proof_tool_sha256=$parsed_option_value
shift 2
take_option --attestation "$@"
attestation=$parsed_option_value
shift 2
take_option --expected-attestation-sha256 "$@"
attestation_sha256=$parsed_option_value
shift 2
[ "$#" -eq 0 ] || fail 'unexpected trailing arguments'

[ "$operation_id" = "$CONTROL_PLANE_OPERATION_ID" ] \
    && [ "$database_dump" = "$CONTROL_PLANE_BACKUP_DATABASE_DUMP_FILE" ] \
    && [ "$redis_snapshot" = "$CONTROL_PLANE_BACKUP_REDIS_SNAPSHOT_FILE" ] \
    && [ "$state_archive" = "$CONTROL_PLANE_BACKUP_STATE_ARCHIVE_FILE" ] \
    && [ "$capture_manifest" = "$CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_FILE" ] \
    && [ "$capture_manifest_sha256" = "$CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_SHA256" ] \
    && [ "$source_pg_system_identifier" = "$CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_SYSTEM_IDENTIFIER" ] \
    && [ "$source_pg_database_oid" = "$CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_DATABASE_OID" ] \
    && [ "$source_pg_version" = "$CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_VERSION" ] \
    && [ "$source_database_name" = "$CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_DATABASE_NAME" ] \
    && [ "$source_host_identity" = "$CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_HOST_IDENTITY" ] \
    && [ "$source_redis_container_id" = "$CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_CONTAINER_ID" ] \
    && [ "$source_redis_image_digest" = "$CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_IMAGE_DIGEST" ] \
    && [ "$source_redis_endpoint" = "$CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_ENDPOINT" ] \
    && [ "$candidate_image_digest" = "$CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_IMAGE_DIGEST" ] \
    && [ "$recipient_fingerprint" = "$CONTROL_PLANE_BACKUP_EXPECTED_RECIPIENT_FINGERPRINT" ] \
    && [ "$quiesce_operator_sha256" = "$CONTROL_PLANE_BACKUP_EXPECTED_QUIESCE_OPERATOR_SHA256" ] \
    && [ "$restore_target_identity" = "$CONTROL_PLANE_BACKUP_EXPECTED_RESTORE_TARGET_IDENTITY" ] \
    && [ "$max_age_seconds" = "$CONTROL_PLANE_BACKUP_MAX_AGE_SECONDS" ] \
    && [ "$operator_rehearsal_hook_sha256" = "$CONTROL_PLANE_BACKUP_EXPECTED_OPERATOR_REHEARSAL_HOOK_SHA256" ] \
    && [ "$candidate_boot_hook_sha256" = "$CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_BOOT_HOOK_SHA256" ] \
    && [ "$candidate_api_probe_hook_sha256" = "$CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_API_PROBE_HOOK_SHA256" ] \
    && [ "$state_proof_tool_sha256" = "$CONTROL_PLANE_BACKUP_EXPECTED_STATE_PROOF_TOOL_SHA256" ] \
    && [ "$attestation" = "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE" ] \
    && [ "$attestation_sha256" = "$CONTROL_PLANE_BACKUP_ATTESTATION_SHA256" ] \
    || fail 'verifier arguments differ from the operator pins'

[ -f "$database_dump" ] && [ ! -L "$database_dump" ] \
    && [ -f "$redis_snapshot" ] && [ ! -L "$redis_snapshot" ] \
    && [ -f "$state_archive" ] && [ ! -L "$state_archive" ] \
    || fail 'backup artifacts are unavailable'
[ "$(sha256sum "$capture_manifest" | awk '{print $1}')" = "$capture_manifest_sha256" ] \
    && [ "$(sha256sum "$attestation" | awk '{print $1}')" = "$attestation_sha256" ] \
    || fail 'manifest or attestation whole-file digest differs'

case "${CONTROL_PLANE_TEST_BACKUP_VERIFIER_OUTPUT:-exact}" in
    exact)
        printf '%s\n' 'attestation-validation=passed'
        ;;
    extra)
        printf '%s\n' 'attestation-validation=passed' 'unexpected-extra-output'
        ;;
    missing-newline)
        printf '%s' 'attestation-validation=passed'
        ;;
    failure)
        fail 'injected verifier failure'
        ;;
    *)
        fail 'unknown verifier output injection'
        ;;
esac
