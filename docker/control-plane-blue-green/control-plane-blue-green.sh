#!/bin/sh

# Operator-only control-plane migration state machine. It deliberately has no
# `deploy`, `pull`, `build`, backup, or image-upgrade path.
set -eu

SCRIPT_DIRECTORY=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)
readonly SCRIPT_DIRECTORY
OPERATOR_PATH="$SCRIPT_DIRECTORY/${0##*/}"
readonly OPERATOR_PATH
readonly STATE_VERSION=5
readonly RELEASE_MANIFEST_VERSION=1
readonly RELEASE_REQUIRED_ASSET_ROLES='operator operator-compose rehearsal-compose ingress-controller backup-attestation-verifier backup-quiesce-controller backup-quiesce-service-unit backup-quiesce-timer-unit runtime-fence-provisioner runtime-fence-controller runtime-fence-controlmaster-reaper runtime-fence-provider-probe runtime-fence-queue-probe runtime-fence-terminal-probe runtime-fence-service-unit runtime-fence-watchdog-unit'
readonly GLOBAL_TRANSACTION_LOCK=/run/lock/coolify-control-plane-blue-green.lock
readonly PRODUCTION_RELEASE_MANIFEST=/etc/coolify-control-plane/release.manifest
readonly EXPECTED_PROXY_DIGEST=sha256:f5dba1e65167778cd5f8d1b463fc5d200f49d40c6458fc9f4b391a68ebfb9534
readonly WRITER_MARKER_PATH=/var/lib/coolify-control-plane/private/writer-epoch
readonly WEB_MARKER_PATH=/var/lib/coolify-control-plane/private/web-epoch
readonly ROUTE_DRAIN_MARKER_PATH=/var/lib/coolify-control-plane/private/route-drain-epoch
readonly MUTATION_FREEZE_MARKER_PATH=/var/lib/coolify-control-plane/coordination/mutation-freeze-epoch
readonly MUTATION_LEASE_PATH=/var/lib/coolify-control-plane/coordination/mutation-inflight.lock
readonly ROUTE_HEALTH_PATH=/api/control-plane/route-health
readonly POOL_LABEL_KEY=coolify.control-plane.pool
readonly PROXY_DYNAMIC_CONTAINER_PATH=/traefik/dynamic
readonly IMMUTABLE_BASELINE_SURPLUS_PATH=database/migrations/control-plane-immutable-baseline-surplus.list
readonly CONTROL_PLANE_MIGRATION_FINGERPRINT_PATH=database/migrations/control-plane-migration-inventory.fingerprint
readonly INSTALLED_BACKUP_QUIESCE_SERVICE_UNIT=/etc/systemd/system/control-plane-backup-quiesce-watchdog.service
readonly INSTALLED_BACKUP_QUIESCE_TIMER_UNIT=/etc/systemd/system/control-plane-backup-quiesce-watchdog.timer
proxy_enrollment_compose_override=
proxy_enrollment_override_active=0

fail()
{
    printf 'CONTROL_PLANE_OPERATOR_FAILURE %s\n' "$1" >&2
    exit 1
}

note()
{
    printf 'CONTROL_PLANE_OPERATOR %s\n' "$1"
}

require_command()
{
    command -v "$1" >/dev/null 2>&1 || fail "required command is unavailable: $1"
}

require_value()
{
    setting_name=$1
    setting_value=$2

    [ -n "$setting_value" ] || fail "required setting is empty: $setting_name"
}

validate_safe_token()
{
    token_value=$1
    token_name=$2

    if ! printf '%s' "$token_value" | grep -Eq '^[A-Za-z0-9._:-]{16,128}$'; then
        fail "$token_name must contain 16 to 128 safe token characters"
    fi
}

validate_identifier()
{
    identifier_value=$1
    identifier_name=$2

    if ! printf '%s' "$identifier_value" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$'; then
        fail "$identifier_name is not a safe identifier"
    fi
}

validate_host()
{
    host_value=$1
    host_name=$2

    if ! printf '%s' "$host_value" | awk '
        BEGIN { valid = 1 }
        {
            if (NR != 1 || length($0) == 0 || length($0) > 253) {
                valid = 0
                next
            }

            host = $0
            if (host ~ /^[0-9.]+$/) {
                if (split(host, octet, /\./) != 4) {
                    valid = 0
                    next
                }
                for (segment_index = 1; segment_index <= 4; segment_index++) {
                    if (octet[segment_index] !~ /^[0-9]+$/ ||
                        (length(octet[segment_index]) > 1 && substr(octet[segment_index], 1, 1) == "0") ||
                        octet[segment_index] + 0 > 255) {
                        valid = 0
                    }
                }
                next
            }

            label_count = split(host, label, /\./)
            for (segment_index = 1; segment_index <= label_count; segment_index++) {
                if (length(label[segment_index]) == 0 || length(label[segment_index]) > 63 ||
                    label[segment_index] !~ /^[A-Za-z0-9-]+$/ ||
                    substr(label[segment_index], 1, 1) !~ /^[A-Za-z0-9]$/ ||
                    substr(label[segment_index], length(label[segment_index]), 1) !~ /^[A-Za-z0-9]$/) {
                    valid = 0
                }
            }
        }
        END { exit !(valid && NR == 1) }
    '; then
        fail "$host_name is not a safe host name"
    fi
}

validate_globally_routable_ipv4()
{
    ipv4_value=$1
    ipv4_name=$2

    if ! printf '%s\n' "$ipv4_value" | awk '
        BEGIN { valid = 1 }
        NR != 1 { valid = 0; next }
        {
            if (split($0, octet, /\./) != 4) {
                valid = 0
                next
            }
            for (position = 1; position <= 4; position++) {
                if (octet[position] !~ /^[0-9]+$/ || length(octet[position]) > 3 ||
                    (length(octet[position]) > 1 && substr(octet[position], 1, 1) == "0") ||
                    octet[position] + 0 > 255) {
                    valid = 0
                }
                value[position] = octet[position] + 0
            }
            if (valid && (value[1] == 0 || value[1] == 10 || value[1] == 127 ||
                value[1] >= 224 ||
                (value[1] == 100 && value[2] >= 64 && value[2] <= 127) ||
                (value[1] == 169 && value[2] == 254) ||
                (value[1] == 172 && value[2] >= 16 && value[2] <= 31) ||
                (value[1] == 192 && value[2] == 0 &&
                    (value[3] == 0 || value[3] == 2)) ||
                (value[1] == 192 && value[2] == 31 && value[3] == 196) ||
                (value[1] == 192 && value[2] == 52 && value[3] == 193) ||
                (value[1] == 192 && value[2] == 88 && value[3] == 99) ||
                (value[1] == 192 && value[2] == 168) ||
                (value[1] == 192 && value[2] == 175 && value[3] == 48) ||
                (value[1] == 198 && (value[2] == 18 || value[2] == 19)) ||
                (value[1] == 198 && value[2] == 51 && value[3] == 100) ||
                (value[1] == 203 && value[2] == 0 && value[3] == 113))) {
                valid = 0
            }
        }
        END { exit !(valid && NR == 1) }
    '; then
        fail "$ipv4_name must be a globally routable public IPv4 address"
    fi
}

validate_path()
{
    path_value=$1
    path_name=$2

    case "$path_value" in
        /*)
            ;;
        *)
            fail "$path_name must be an absolute path"
            ;;
    esac

    case "$path_value" in
        */../*|*/./*|*/..|*/.)
            fail "$path_name must not contain dot segments"
            ;;
    esac
}

validate_port()
{
    port_value=$1
    port_name=$2

    if ! printf '%s' "$port_value" | grep -Eq '^[1-9][0-9]{0,4}$'; then
        fail "$port_name is not a valid TCP port"
    fi

    if [ "$port_value" -gt 65535 ]; then
        fail "$port_name is not a valid TCP port"
    fi
}

source_environment_app_port()
{
    source_environment_path=$1
    awk '
        BEGIN { matches = 0; invalid = 0 }
        /^[[:space:]]*APP_PORT[[:space:]]*=/ {
            matches++
            value = $0
            sub(/^[[:space:]]*APP_PORT[[:space:]]*=[[:space:]]*/, "", value)
            sub(/[[:space:]]*$/, "", value)
            if (value ~ /^"[0-9]+"$/ || value ~ /^\047[0-9]+\047$/) {
                value = substr(value, 2, length(value) - 2)
            }
            if (value !~ /^[0-9]+$/) {
                invalid = 1
            }
            result = value
        }
        END {
            if (matches > 1 || invalid) {
                exit 1
            }
            if (matches == 1) {
                print result
            }
        }
    ' "$source_environment_path"
}

derive_local_ingress_url()
{
    derived_public_url=$1
    derived_app_port=$2
    case "$derived_public_url" in
        https://*) ;;
        *) fail 'CONTROL_PLANE_PUBLIC_PROBE_URL must be a canonical HTTPS URL' ;;
    esac
    derived_authority_and_suffix=${derived_public_url#https://}
    case "$derived_authority_and_suffix" in
        ''|*'#'*) fail 'CONTROL_PLANE_PUBLIC_PROBE_URL must not contain a fragment' ;;
    esac
    case "$derived_authority_and_suffix" in
        */*)
            derived_authority=${derived_authority_and_suffix%%/*}
            derived_path_and_query=/${derived_authority_and_suffix#*/}
            case "$derived_path_and_query" in
                *'?') derived_path_and_query=${derived_path_and_query%?} ;;
            esac
            ;;
        *'?'*)
            derived_authority=${derived_authority_and_suffix%%\?*}
            derived_query=${derived_authority_and_suffix#*\?}
            if [ -n "$derived_query" ]; then
                derived_path_and_query="/?$derived_query"
            else
                derived_path_and_query=/
            fi
            ;;
        *)
            derived_authority=$derived_authority_and_suffix
            derived_path_and_query=/
            ;;
    esac
    case "$derived_authority" in
        ''|*@*|*'?'*) fail 'CONTROL_PLANE_PUBLIC_PROBE_URL has an invalid authority' ;;
    esac
    printf 'http://127.0.0.1:%s%s\n' "$derived_app_port" "$derived_path_and_query"
}

validate_positive_integer()
{
    integer_value=$1
    integer_name=$2

    if ! printf '%s' "$integer_value" | grep -Eq '^[1-9][0-9]*$'; then
        fail "$integer_name must be a positive integer"
    fi
}

validate_sha256()
{
    sha256_value=$1
    sha256_name=$2

    if ! printf '%s' "$sha256_value" | grep -Eq '^[0-9a-f]{64}$'; then
        fail "$sha256_name must be a lowercase SHA-256 digest"
    fi
}

validate_container_id()
{
    container_id_value=$1
    container_id_name=$2

    if ! printf '%s' "$container_id_value" | grep -Eq '^[0-9a-f]{64}$'; then
        fail "$container_id_name must be a full lowercase Docker container ID"
    fi
}

validate_image_id()
{
    image_id_value=$1
    image_id_name=$2

    if ! printf '%s' "$image_id_value" | grep -Eq '^sha256:[0-9a-f]{64}$'; then
        fail "$image_id_name must be a full lowercase Docker image ID"
    fi
}

validate_openpgp_fingerprint()
{
    fingerprint_value=$1
    fingerprint_name=$2

    if ! printf '%s' "$fingerprint_value" | grep -Eq '^([0-9A-F]{40}|[0-9A-F]{64})$'; then
        fail "$fingerprint_name must be an uppercase OpenPGP fingerprint"
    fi
}

validate_nonnegative_integer()
{
    integer_value=$1
    integer_name=$2

    if ! printf '%s' "$integer_value" | grep -Eq '^[0-9]+$'; then
        fail "$integer_name must be a nonnegative integer"
    fi
}

validate_migration_timeout()
{
    timeout_value=$1
    timeout_name=$2

    if ! printf '%s' "$timeout_value" | grep -Eq '^[1-9][0-9]*(ms|s)$'; then
        fail "$timeout_name must be a positive PostgreSQL millisecond or second duration"
    fi
}

validate_probe_path()
{
    probe_path=$1

    if ! printf '%s' "$probe_path" | grep -Eq '^/[A-Za-z0-9._/-]*$'; then
        fail 'CONTROL_PLANE_DIRECT_PROBE_PATH is not safe'
    fi
}

assert_regular_file()
{
    file_path=$1
    file_name=$2

    [ -f "$file_path" ] && [ -r "$file_path" ] || fail "$file_name must be a readable regular file"
}

assert_non_symlink_regular_file()
{
    file_path=$1
    file_name=$2

    [ -f "$file_path" ] && [ ! -L "$file_path" ] && [ -r "$file_path" ] \
        || fail "$file_name must be a readable regular non-symlink file"
}

file_uid()
{
    stat -c '%u' "$1" 2>/dev/null || stat -f '%u' "$1"
}

file_gid()
{
    stat -c '%g' "$1" 2>/dev/null || stat -f '%g' "$1"
}

file_mode()
{
    stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"
}

file_size()
{
    stat -c '%s' "$1" 2>/dev/null || stat -f '%z' "$1"
}

file_link_count()
{
    stat -c '%h' "$1" 2>/dev/null || stat -f '%l' "$1"
}

file_device_inode()
{
    stat -c '%d:%i' "$1" 2>/dev/null || stat -f '%d:%i' "$1"
}

canonical_file_path()
{
    canonical_file_parent=${1%/*}
    [ -n "$canonical_file_parent" ] || canonical_file_parent=/
    canonical_file_parent=$(canonical_directory "$canonical_file_parent")
    printf '%s/%s\n' "${canonical_file_parent%/}" "${1##*/}"
}

file_metadata()
{
    printf '%s:%s:%s:%s\n' \
        "$(file_uid "$1")" "$(file_gid "$1")" "$(file_mode "$1")" "$(file_size "$1")"
}

assert_release_safe_parent_chain()
{
    release_asset_path=$1
    release_parent=${release_asset_path%/*}
    [ -n "$release_parent" ] || release_parent=/
    [ "$(canonical_directory "$release_parent")" = "$release_parent" ] \
        || fail "release asset parent path contains a symlink: $release_parent"
    while [ "$release_parent" != / ]; do
        [ -d "$release_parent" ] && [ ! -L "$release_parent" ] \
            || fail "release asset parent is unsafe: $release_parent"
        if ! is_test_mode; then
            [ "$(file_uid "$release_parent"):$(file_gid "$release_parent")" = 0:0 ] \
                || fail "production release asset parent is not root-owned: $release_parent"
            release_parent_mode=$(file_mode "$release_parent")
            [ $((0$release_parent_mode & 0022)) -eq 0 ] \
                || fail "production release asset parent is group/world writable: $release_parent"
        fi
        release_parent=${release_parent%/*}
        [ -n "$release_parent" ] || release_parent=/
    done
}

release_manifest_asset_line()
{
    release_asset_role=$1
    awk -F'|' -v expected_role="$release_asset_role" '
        $1 == "asset" && $2 == expected_role { matches++; result = $0 }
        END { if (matches != 1) exit 1; print result }
    ' "$release_manifest_file" \
        || fail "release manifest asset is absent or duplicated: $release_asset_role"
}

release_manifest_asset_field()
{
    release_field_role=$1
    release_field_number=$2
    release_field_line=$(release_manifest_asset_line "$release_field_role")
    printf '%s\n' "$release_field_line" | awk -F'|' -v field="$release_field_number" \
        '{ print $field }'
}

configured_release_asset_path()
{
    case "$1" in
        operator) printf '%s\n' "$OPERATOR_PATH" ;;
        operator-compose) printf '%s\n' "$operator_compose_file" ;;
        rehearsal-compose) printf '%s\n' "$release_rehearsal_compose_file" ;;
        ingress-controller) printf '%s\n' "$ingress_controller" ;;
        backup-attestation-verifier) printf '%s\n' "$release_backup_attestation_verifier" ;;
        backup-quiesce-controller) printf '%s\n' "$release_backup_quiesce_controller" ;;
        backup-quiesce-service-unit) printf '%s\n' "$release_backup_quiesce_service_unit" ;;
        backup-quiesce-timer-unit) printf '%s\n' "$release_backup_quiesce_timer_unit" ;;
        runtime-fence-provisioner) printf '%s\n' "$release_runtime_fence_provisioner" ;;
        runtime-fence-controller) printf '%s\n' "$release_runtime_fence_controller" ;;
        runtime-fence-controlmaster-reaper) printf '%s\n' "$release_runtime_fence_reaper" ;;
        runtime-fence-provider-probe) printf '%s\n' "$release_runtime_fence_provider_probe" ;;
        runtime-fence-queue-probe) printf '%s\n' "$release_runtime_fence_queue_probe" ;;
        runtime-fence-terminal-probe) printf '%s\n' "$release_runtime_fence_terminal_probe" ;;
        runtime-fence-service-unit) printf '%s\n' "$release_runtime_fence_service_unit" ;;
        runtime-fence-watchdog-unit) printf '%s\n' "$release_runtime_fence_watchdog_unit" ;;
        *) fail "unknown configured release asset role: $1" ;;
    esac
}

configure_global_transaction_lock()
{
    if is_test_mode; then
        lock_file=${CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE:-}
        require_value CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE "$lock_file"
        validate_path "$lock_file" CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE
    else
        [ -z "${CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE:-}" ] \
            || fail 'production operator rejects a lab global transaction lock override'
        lock_file=$GLOBAL_TRANSACTION_LOCK
    fi
}

configure_and_verify_release_inventory()
{
    operator_compose_file=${CONTROL_PLANE_OPERATOR_COMPOSE_FILE:-$SCRIPT_DIRECTORY/compose.yaml}
    release_rehearsal_compose_file=${CONTROL_PLANE_REHEARSAL_COMPOSE_FILE:-$SCRIPT_DIRECTORY/compose.rehearsal.yaml}
    rehearsal_compose_file=${CONTROL_PLANE_TEST_REHEARSAL_COMPOSE_FILE:-$release_rehearsal_compose_file}
    rehearsal_compose_file_sha256=${CONTROL_PLANE_TEST_REHEARSAL_COMPOSE_FILE_SHA256:-}
    ingress_controller=${CONTROL_PLANE_INGRESS_CONTROLLER:-$SCRIPT_DIRECTORY/controllers/traefik-ingress.sh}
    release_backup_attestation_verifier=${CONTROL_PLANE_RELEASE_BACKUP_ATTESTATION_VERIFIER:-$SCRIPT_DIRECTORY/backup/restore-attest.sh}
    backup_attestation_verifier=${CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER:-$release_backup_attestation_verifier}
    release_runtime_fence_provisioner=$SCRIPT_DIRECTORY/controllers/provision-runtime-attestation-ssh-fence.sh
    runtime_fence_provisioner=${CONTROL_PLANE_RUNTIME_FENCE_PROVISIONER:-$release_runtime_fence_provisioner}
    release_backup_quiesce_service_unit=$SCRIPT_DIRECTORY/backup-quiesce/control-plane-backup-quiesce-watchdog.service
    release_backup_quiesce_timer_unit=$SCRIPT_DIRECTORY/backup-quiesce/control-plane-backup-quiesce-watchdog.timer
    release_runtime_fence_service_unit=$SCRIPT_DIRECTORY/controllers/coolify-runtime-attestation-ssh-fence.service
    release_runtime_fence_watchdog_unit=$SCRIPT_DIRECTORY/controllers/coolify-runtime-attestation-ssh-fence-watchdog.service
    if is_test_mode; then
        release_manifest_file=${CONTROL_PLANE_RELEASE_MANIFEST_FILE:-}
        require_value CONTROL_PLANE_RELEASE_MANIFEST_FILE "$release_manifest_file"
        release_backup_quiesce_controller=${CONTROL_PLANE_RELEASE_BACKUP_QUIESCE_CONTROLLER:-$SCRIPT_DIRECTORY/backup-quiesce/control-plane-backup-quiesce.sh}
        release_runtime_fence_controller=${CONTROL_PLANE_RELEASE_RUNTIME_FENCE_CONTROLLER:-$SCRIPT_DIRECTORY/controllers/runtime-attestation-ssh-fence.sh}
        release_runtime_fence_reaper=${CONTROL_PLANE_RELEASE_RUNTIME_FENCE_REAPER:-$SCRIPT_DIRECTORY/controllers/self-ssh-controlmaster-reaper.sh}
        release_runtime_fence_provider_probe=${CONTROL_PLANE_RELEASE_RUNTIME_FENCE_PROVIDER_PROBE:-$SCRIPT_DIRECTORY/controllers/traefik-docker-provider-freshness-probe.sh}
        release_runtime_fence_queue_probe=${CONTROL_PLANE_RELEASE_RUNTIME_FENCE_QUEUE_PROBE:-$SCRIPT_DIRECTORY/controllers/proxy-queue-zero-probe.sh}
        release_runtime_fence_terminal_probe=${CONTROL_PLANE_RELEASE_RUNTIME_FENCE_TERMINAL_PROBE:-$SCRIPT_DIRECTORY/controllers/control-plane-terminal-state-probe.sh}
    else
        [ -z "${CONTROL_PLANE_RELEASE_MANIFEST_FILE:-}" ] \
            || [ "$CONTROL_PLANE_RELEASE_MANIFEST_FILE" = "$PRODUCTION_RELEASE_MANIFEST" ] \
            || fail 'production operator release manifest path is fixed'
        release_manifest_file=$PRODUCTION_RELEASE_MANIFEST
        release_backup_quiesce_controller=${CONTROL_PLANE_RELEASE_BACKUP_QUIESCE_CONTROLLER:-$SCRIPT_DIRECTORY/backup-quiesce/control-plane-backup-quiesce.sh}
        release_runtime_fence_controller=${CONTROL_PLANE_RELEASE_RUNTIME_FENCE_CONTROLLER:-$SCRIPT_DIRECTORY/controllers/runtime-attestation-ssh-fence.sh}
        release_runtime_fence_reaper=${CONTROL_PLANE_RELEASE_RUNTIME_FENCE_REAPER:-$SCRIPT_DIRECTORY/controllers/self-ssh-controlmaster-reaper.sh}
        release_runtime_fence_provider_probe=${CONTROL_PLANE_RELEASE_RUNTIME_FENCE_PROVIDER_PROBE:-$SCRIPT_DIRECTORY/controllers/traefik-docker-provider-freshness-probe.sh}
        release_runtime_fence_queue_probe=${CONTROL_PLANE_RELEASE_RUNTIME_FENCE_QUEUE_PROBE:-$SCRIPT_DIRECTORY/controllers/proxy-queue-zero-probe.sh}
        release_runtime_fence_terminal_probe=${CONTROL_PLANE_RELEASE_RUNTIME_FENCE_TERMINAL_PROBE:-$SCRIPT_DIRECTORY/controllers/control-plane-terminal-state-probe.sh}
    fi
    release_manifest_sha256=${CONTROL_PLANE_RELEASE_MANIFEST_SHA256:-}
    release_id=${CONTROL_PLANE_RELEASE_ID:-}
    require_value CONTROL_PLANE_RELEASE_MANIFEST_SHA256 "$release_manifest_sha256"
    require_value CONTROL_PLANE_RELEASE_ID "$release_id"
    verify_release_manifest
}

release_asset_expected_mode()
{
    case "$1" in
        operator-compose|rehearsal-compose)
            printf '%s\n' 600
            ;;
        backup-quiesce-service-unit|backup-quiesce-timer-unit|runtime-fence-service-unit|runtime-fence-watchdog-unit)
            printf '%s\n' 644
            ;;
        operator|backup-attestation-verifier|backup-quiesce-controller)
            printf '%s\n' 755
            ;;
        ingress-controller|runtime-fence-provisioner|runtime-fence-controller|runtime-fence-controlmaster-reaper|runtime-fence-provider-probe|runtime-fence-queue-probe|runtime-fence-terminal-probe)
            printf '%s\n' 700
            ;;
        *)
            fail "release asset role has no mode contract: $1"
            ;;
    esac
}

assert_release_asset_identity()
{
    release_identity_role=$1
    release_identity_line=$(release_manifest_asset_line "$release_identity_role")
    [ "$(printf '%s\n' "$release_identity_line" | awk -F'|' '{ print NF }')" = 7 ] \
        && [ "$(printf '%s\n' "$release_identity_line" | awk -F'|' '{ print $1 }')" = asset ] \
        && [ "$(printf '%s\n' "$release_identity_line" | awk -F'|' '{ print $2 }')" \
            = "$release_identity_role" ] \
        || fail "release manifest asset record is malformed: $release_identity_role"
    release_identity_path=$(printf '%s\n' "$release_identity_line" | awk -F'|' '{ print $3 }')
    release_identity_sha256=$(printf '%s\n' "$release_identity_line" | awk -F'|' '{ print $4 }')
    release_identity_uid=$(printf '%s\n' "$release_identity_line" | awk -F'|' '{ print $5 }')
    release_identity_gid=$(printf '%s\n' "$release_identity_line" | awk -F'|' '{ print $6 }')
    release_identity_mode=$(printf '%s\n' "$release_identity_line" | awk -F'|' '{ print $7 }')
    [ "$release_identity_path" = "$(configured_release_asset_path "$release_identity_role")" ] \
        || fail "configured release asset path is not authorized: $release_identity_role"
    validate_path "$release_identity_path" "release asset $release_identity_role"
    validate_sha256 "$release_identity_sha256" "release asset $release_identity_role"
    assert_non_symlink_regular_file "$release_identity_path" \
        "release asset $release_identity_role"
    assert_release_safe_parent_chain "$release_identity_path"
    release_identity_canonical_path=$(canonical_file_path "$release_identity_path")
    release_identity_device_inode=$(file_device_inode "$release_identity_path")
    [ "$release_identity_canonical_path" = "$release_identity_path" ] \
        || fail "release asset path is not canonical: $release_identity_role"
    [ "$(file_link_count "$release_identity_path")" = 1 ] \
        || fail "release asset link count is not exactly one: $release_identity_role"
    [ "$(file_uid "$release_identity_path")" = "$release_identity_uid" ] \
        && [ "$(file_gid "$release_identity_path")" = "$release_identity_gid" ] \
        && [ "$(file_mode "$release_identity_path")" = "$release_identity_mode" ] \
        && [ "$(sha256_file "$release_identity_path")" = "$release_identity_sha256" ] \
        || fail "release asset bytes or metadata changed: $release_identity_role"
    [ "$release_identity_uid:$release_identity_gid" = "$immutable_uid:$immutable_gid" ] \
        || fail "release asset owner is not immutable-owner authorized: $release_identity_role"
    release_identity_expected_mode=$(release_asset_expected_mode "$release_identity_role")
    case "$release_identity_expected_mode" in
        600|644)
            [ $((0$release_identity_mode & 0133)) -eq 0 ] \
                || fail "release data asset mode is unsafe: $release_identity_role"
            ;;
        700|755)
            [ -x "$release_identity_path" ] \
                && [ $((0$release_identity_mode & 0022)) -eq 0 ] \
                || fail "release executable mode is unsafe: $release_identity_role"
            ;;
        *)
            fail "release asset mode contract is invalid: $release_identity_role"
            ;;
    esac
    [ "$test_mode" = 1 ] || [ "$release_identity_mode" = "$release_identity_expected_mode" ] \
        || fail "production release asset must have mode 0$release_identity_expected_mode: $release_identity_role"
}

verify_release_manifest()
{
    [ "${global_transaction_lock_held:-0}" = 1 ] \
        || fail 'host release manifest verification requires the global transaction lock'
    validate_path "$release_manifest_file" CONTROL_PLANE_RELEASE_MANIFEST_FILE
    validate_sha256 "$release_manifest_sha256" CONTROL_PLANE_RELEASE_MANIFEST_SHA256
    validate_safe_token "$release_id" CONTROL_PLANE_RELEASE_ID
    assert_non_symlink_regular_file "$release_manifest_file" 'host release manifest'
    assert_release_safe_parent_chain "$release_manifest_file"
    [ "$(canonical_file_path "$release_manifest_file")" = "$release_manifest_file" ] \
        && [ "$(file_link_count "$release_manifest_file")" = 1 ] \
        || fail 'host release manifest path or link count is unsafe'
    [ "$(file_uid "$release_manifest_file"):$(file_gid "$release_manifest_file")" \
        = "$immutable_uid:$immutable_gid" ] \
        && [ "$(file_mode "$release_manifest_file")" = 600 ] \
        || fail 'host release manifest owner or mode is unsafe'
    release_manifest_observed_sha256=$(sha256_file "$release_manifest_file")
    [ "$release_manifest_observed_sha256" = "$release_manifest_sha256" ] \
        || fail 'host release manifest differs from its expected out-of-band hash'
    release_expected_asset_count=$(printf '%s\n' "$RELEASE_REQUIRED_ASSET_ROLES" | wc -w | tr -d ' ')
    release_expected_manifest_line_count=$((release_expected_asset_count + 2))
    awk -F'|' -v expected_version="$RELEASE_MANIFEST_VERSION" \
        -v expected_line_count="$release_expected_manifest_line_count" \
        -v expected_release="$release_id" '
        NR == 1 { if ($0 != "version|" expected_version) { invalid = 1; exit }; next }
        NR == 2 { if ($0 != "release|" expected_release) { invalid = 1; exit }; next }
        NF != 7 || $1 != "asset" || $2 !~ /^[a-z][a-z0-9-]*$/ || seen[$2]++ \
            || $3 !~ /^\/[A-Za-z0-9_.\/-]+$/ || $4 !~ /^[a-f0-9]{64}$/ \
            || $5 !~ /^[0-9]+$/ || $6 !~ /^[0-9]+$/ || $7 !~ /^[0-7]{3,4}$/ \
            { invalid = 1; exit }
        END { exit(!invalid && NR == expected_line_count ? 0 : 1) }
    ' "$release_manifest_file" \
        || fail 'host release manifest structure or canonical inventory is invalid'
    release_observed_roles=$(sed -n 's/^asset|\([^|]*\)|.*/\1/p' \
        "$release_manifest_file" | LC_ALL=C sort | paste -sd' ' -)
    release_expected_roles=$(printf '%s\n' "$RELEASE_REQUIRED_ASSET_ROLES" | tr ' ' '\n' \
        | LC_ALL=C sort | paste -sd' ' -)
    [ "$release_observed_roles" = "$release_expected_roles" ] \
        || fail 'host release manifest required role inventory differs'
    release_seen_canonical_paths='|'
    release_seen_device_inodes='|'
    for release_required_role in $RELEASE_REQUIRED_ASSET_ROLES; do
        assert_release_asset_identity "$release_required_role"
        case "$release_seen_canonical_paths" in
            *"|$release_identity_canonical_path|"*)
                fail "release manifest duplicates a canonical path: $release_identity_canonical_path"
                ;;
        esac
        case "$release_seen_device_inodes" in
            *"|$release_identity_device_inode|"*)
                fail "release manifest duplicates a device/inode identity: $release_identity_device_inode"
                ;;
        esac
        release_seen_canonical_paths="$release_seen_canonical_paths$release_identity_canonical_path|"
        release_seen_device_inodes="$release_seen_device_inodes$release_identity_device_inode|"
    done
    release_assets_sha256=$(sed -n '/^asset|/p' "$release_manifest_file" \
        | LC_ALL=C sort | sha256sum | awk '{print $1}')
}

assert_secret_file_metadata()
{
    secret_path=$1
    secret_name=$2

    assert_non_symlink_regular_file "$secret_path" "$secret_name"
    [ "$(file_uid "$secret_path")" = "$secret_uid" ] \
        || fail "$secret_name must be owned by UID $secret_uid"
    [ "$(file_gid "$secret_path")" = "$secret_gid" ] \
        || fail "$secret_name must be owned by GID $secret_gid"
    [ "$(file_mode "$secret_path")" = 400 ] \
        || fail "$secret_name must have mode 0400"
}

path_presence()
{
    if [ -f "$1" ] && [ ! -L "$1" ]; then
        printf '%s\n' present
    elif [ ! -e "$1" ] && [ ! -L "$1" ]; then
        printf '%s\n' absent
    else
        fail "compose path is neither an absent path nor a regular non-symlink file: $1"
    fi
}

optional_file_checksum()
{
    if [ "$(path_presence "$1")" = present ]; then
        sha256_file "$1"
    else
        printf '%s\n' absent
    fi
}

canonical_directory()
{
    directory_path=$1
    CDPATH='' cd -- "$directory_path" && pwd -P
}

is_test_mode()
{
    [ "$test_mode" = 1 ]
}

image_id()
{
    docker image inspect --format '{{.Id}}' "$1"
}

image_digest_from_reference()
{
    printf '%s\n' "$1" | sed -n 's/.*@\(sha256:[a-f0-9]\{64\}\)$/\1/p'
}

image_repository_has_digest()
{
    inspected_image=$1
    expected_digest=$2

    docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$inspected_image" \
        | grep -F -q "@${expected_digest}"
}

assert_immutable_image()
{
    image_reference=$1
    image_name=$2

    if is_test_mode; then
        image_id "$image_reference" >/dev/null 2>&1 || fail "$image_name is not available locally"
        return
    fi

    if ! printf '%s' "$image_reference" | grep -Eq '^.+@sha256:[a-f0-9]{64}$'; then
        fail "$image_name must use an immutable sha256 digest reference"
    fi

    expected_digest=$(image_digest_from_reference "$image_reference")
    [ -n "$expected_digest" ] || fail "$image_name does not contain a sha256 digest"
    image_id "$image_reference" >/dev/null 2>&1 || fail "$image_name is not available locally; pre-stage it without this operator"
    image_repository_has_digest "$image_reference" "$expected_digest" \
        || fail "$image_name local repository digest does not match its requested digest"
}

container_id()
{
    docker inspect --format '{{.Id}}' "$1"
}

container_image_id()
{
    docker inspect --format '{{.Image}}' "$1"
}

container_is_running()
{
    docker_container_presence "$1"
    if [ "$container_presence" = present ]; then
        printf '%s\n' "$container_presence_running"
        [ "$container_presence_running" = true ]
        return
    fi
    printf '%s\n' false
    return 1
}

assert_container_absent()
{
    container_name=$1

    docker_container_presence "$container_name"
    [ "$container_presence" = absent ] \
        || fail "container must be absent: $container_name"
}

assert_container_running()
{
    container_name=$1

    docker_container_presence "$container_name"
    [ "$container_presence" = present ] && [ "$container_presence_running" = true ] \
        || fail "container is not running: $container_name"
}

assert_container_uses_image()
{
    container_name=$1
    expected_image=$2
    expected_image_id=$(image_id "$expected_image")

    [ "$(container_image_id "$container_name")" = "$expected_image_id" ] \
        || fail "container image identity changed: $container_name"
}

container_repository_digest()
{
    container_name=$1
    container_image=$(container_image_id "$container_name")
    docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$container_image" \
        | sed -n 's/.*@\(sha256:[a-f0-9]\{64\}\)$/\1/p' \
        | head -n 1
}

container_image_reference()
{
    docker inspect --format '{{.Config.Image}}' "$1"
}

container_label()
{
    container_name=$1
    label_name=$2

    docker inspect --format "{{index .Config.Labels \"${label_name}\"}}" "$container_name"
}

container_restart_policy()
{
    docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' "$1"
}

assert_proxy_identity()
{
    proxy_image=$(container_image_id "$proxy_container")
    image_repository_has_digest "$proxy_image" "$EXPECTED_PROXY_DIGEST" \
        || fail "proxy is not using the preserved Traefik digest: $EXPECTED_PROXY_DIGEST"
}

file_checksum()
{
    cksum < "$1" | awk '{print $1 "-" $2}'
}

sha256_file()
{
    sha256sum "$1" | awk '{print $1}'
}

fsync_replace()
{
    replacement_candidate=$1
    replacement_destination=$2
    replacement_directory=${replacement_destination%/*}

    sync -f "$replacement_candidate"
    test_crash after-migration-attempt-file-fsync
    mv -f "$replacement_candidate" "$replacement_destination"
    test_crash after-migration-attempt-state-rename
    sync -f "$replacement_directory"
    test_crash after-migration-attempt-directory-fsync
}

docker_container_presence()
{
    inspected_container=$1
    validate_identifier "$inspected_container" 'Docker container presence identity'
    presence_response="$state_directory/.docker-engine-presence-${operation_id}-$$.json"
    container_presence=unavailable
    container_presence_id=none
    container_presence_image_id=none
    container_presence_running=none

    [ ! -e "$presence_response" ] && [ ! -L "$presence_response" ] \
        || fail 'Docker Engine presence response path already exists'
    if ! (umask 077; set -C; : > "$presence_response") 2>/dev/null; then
        fail 'could not create the Docker Engine presence response file'
    fi
    if ! presence_status=$(curl --silent --show-error \
        --connect-timeout 5 --max-time 10 \
        --unix-socket /var/run/docker.sock \
        --output "$presence_response" --write-out '%{http_code}' \
        "http://localhost/containers/${inspected_container}/json"); then
        rm -f "$presence_response"
        migration_reconciliation_api_unavailable=1
        fail "Docker Engine API is unavailable while inspecting container: $inspected_container"
    fi

    case "$presence_status" in
        200)
            if ! jq --exit-status --arg container "$inspected_container" '
                type == "object"
                and (.Id | type == "string" and test("^[0-9a-f]{64}$"))
                and (.Image | type == "string" and test("^sha256:[0-9a-f]{64}$"))
                and (.Name | type == "string")
                and (.State.Running | type == "boolean")
                and (.Id == $container or .Name == ("/" + $container))
            ' "$presence_response" >/dev/null; then
                rm -f "$presence_response"
                migration_reconciliation_api_unavailable=1
                fail "Docker Engine returned malformed container identity: $inspected_container"
            fi
            container_presence_id=$(jq --raw-output '.Id' "$presence_response")
            container_presence_image_id=$(jq --raw-output '.Image' "$presence_response")
            container_presence_running=$(jq --raw-output '.State.Running' "$presence_response")
            container_presence=present
            ;;
        404)
            if ! jq --exit-status --arg message "No such container: $inspected_container" '
                type == "object" and keys == ["message"] and .message == $message
            ' "$presence_response" >/dev/null; then
                rm -f "$presence_response"
                migration_reconciliation_api_unavailable=1
                fail "Docker Engine returned an unvalidated 404 for container: $inspected_container"
            fi
            container_presence=absent
            ;;
        *)
            rm -f "$presence_response"
            migration_reconciliation_api_unavailable=1
            fail "Docker Engine API returned unavailable status $presence_status for container: $inspected_container"
            ;;
    esac
    rm -f "$presence_response"
    migration_runner_presence=$container_presence
}

assert_file_identity()
{
    identity_path=$1
    expected_path=$2
    expected_sha256=$3
    expected_uid=$4
    expected_gid=$5
    expected_mode=$6
    expected_size=$7
    identity_name=$8

    [ "$identity_path" = "$expected_path" ] \
        || fail "$identity_name path changed during this operation"
    assert_non_symlink_regular_file "$identity_path" "$identity_name"
    [ "$(sha256_file "$identity_path")" = "$expected_sha256" ] \
        && [ "$(file_uid "$identity_path")" = "$expected_uid" ] \
        && [ "$(file_gid "$identity_path")" = "$expected_gid" ] \
        && [ "$(file_mode "$identity_path")" = "$expected_mode" ] \
        && [ "$(file_size "$identity_path")" = "$expected_size" ] \
        || fail "$identity_name bytes or metadata changed during this operation"
}

state_key_type()
{
    case "$1" in
        version)
            printf '%s\n' version
            ;;
        writer_epoch|blue_writer_epoch|green_web_epoch|green_web_b_epoch|blue_web_epoch|\
        blue_web_b_epoch|green_web_a_route_drain_epoch|green_web_b_route_drain_epoch|\
        blue_web_a_route_drain_epoch|blue_web_b_route_drain_epoch|mutation_freeze_epoch|\
        reverse_mutation_freeze_epoch|green_web_a_route_identity|green_web_b_route_identity|\
        blue_web_a_route_identity|blue_web_b_route_identity|release_id)
            printf '%s\n' safe-token
            ;;
        blue_id|blue_original_id|blue_rollback_replacement_id|green_id|green_web_b_id|\
        replacement_blue_id|replacement_blue_web_b_id|proxy_id)
            printf '%s\n' container-id-or-none
            ;;
        blue_image_id|green_image_id|green_web_b_image_id|green_plan_image_id|\
        replacement_blue_plan_image_id|replacement_blue_image_id|\
        replacement_blue_web_b_image_id|proxy_image_id|migration_image_id)
            printf '%s\n' image-id-or-none
            ;;
        blue_image_digest|migration_image_digest)
            printf '%s\n' image-id-or-none
            ;;
        source_custom_sha256|source_postgres_sha256)
            printf '%s\n' sha256-or-absent
            ;;
        release_manifest_sha256|release_assets_sha256|\
        green_restart_policy_intent_sha256|green_web_b_restart_policy_intent_sha256|\
        replacement_blue_restart_policy_intent_sha256|\
        replacement_blue_web_b_restart_policy_intent_sha256|\
        operator_sha256|operator_compose_sha256|rehearsal_compose_sha256|\
        green_plan_network_id|green_container_runtime_sha256|green_web_b_container_runtime_sha256|\
        replacement_blue_plan_network_id|replacement_blue_container_runtime_sha256|\
        replacement_blue_web_b_container_runtime_sha256|\
        forward_pool_plan_sha256|forward_ingress_pool_sha256|\
        reverse_pool_plan_sha256|reverse_ingress_pool_sha256|\
        runtime_fence_provisioner_sha256|\
        runtime_fence_base_config_sha256|runtime_fence_environment_sha256|\
        runtime_fence_https_ack_sha256|\
        runtime_fence_provider_header_source_sha256|runtime_fence_provider_header_copy_sha256|\
        reverse_fence_provisioner_sha256|reverse_fence_environment_sha256|\
        reverse_fence_https_ack_sha256|\
        reverse_fence_provider_header_sha256|source_compose_checksum|source_runtime_sha256|\
        source_rendered_sha256|source_base_sha256|source_prod_sha256|source_env_sha256|\
        green_runtime_env_sha256|blue_runtime_env_sha256|green_ack_sha256|blue_ack_sha256|\
        green_ack_snapshot_sha256|blue_ack_snapshot_sha256|green_probe_token_sha256|\
        blue_probe_token_sha256|ingress_controller_sha256|\
        backup_attestation_configuration_sha256|\
        backup_attestation_sha256|drain_queue_sha256|migration_attempt_state_sha256|\
        migration_compatibility_sha256|migration_manifest_sha256|migration_ledger_before_sha256|\
        migration_relfilenode_before_sha256|migration_row_counts_before_sha256|migration_schema_before_sha256|\
        migration_pending_sha256|live_database_identity_artifact_sha256|\
        live_database_identity_sha256|migration_verification_sha256)
            printf '%s\n' sha256-or-none
            ;;
        release_manifest_path|green_restart_policy_intent_path|\
        green_web_b_restart_policy_intent_path|replacement_blue_restart_policy_intent_path|\
        replacement_blue_web_b_restart_policy_intent_path|\
        forward_pool_plan_path|forward_ingress_pool_path|\
        reverse_pool_plan_path|reverse_ingress_pool_path|\
        operator_path|operator_compose_path|rehearsal_compose_path|\
        runtime_fence_provisioner_path|runtime_fence_base_config_path|\
        runtime_fence_environment_path|runtime_fence_https_ack_path|\
        runtime_fence_provider_header_source_path|\
        runtime_fence_provider_header_copy_path|\
        reverse_fence_provisioner_path|\
        reverse_fence_environment_path|reverse_fence_https_ack_path|\
        reverse_fence_provider_header_path|\
        source_base_path|source_prod_path|source_custom_path|source_postgres_path|source_env_path|\
        green_runtime_env_path|blue_runtime_env_path|green_ack_path|blue_ack_path|\
        green_ack_snapshot_path|blue_ack_snapshot_path|green_probe_token_path|\
        blue_probe_token_path)
            printf '%s\n' path-or-none
            ;;
        release_manifest_uid|release_manifest_gid|release_manifest_size|\
        green_restart_policy_intent_uid|green_restart_policy_intent_gid|\
        green_restart_policy_intent_size|green_web_b_restart_policy_intent_uid|\
        green_web_b_restart_policy_intent_gid|green_web_b_restart_policy_intent_size|\
        replacement_blue_restart_policy_intent_uid|\
        replacement_blue_restart_policy_intent_gid|\
        replacement_blue_restart_policy_intent_size|\
        replacement_blue_web_b_restart_policy_intent_uid|\
        replacement_blue_web_b_restart_policy_intent_gid|\
        replacement_blue_web_b_restart_policy_intent_size|\
        forward_pool_plan_uid|forward_pool_plan_gid|forward_pool_plan_size|\
        forward_ingress_pool_uid|forward_ingress_pool_gid|forward_ingress_pool_size|\
        reverse_pool_plan_uid|reverse_pool_plan_gid|reverse_pool_plan_size|\
        reverse_ingress_pool_uid|reverse_ingress_pool_gid|reverse_ingress_pool_size|\
        routed_recovery_generation|\
        operator_uid|operator_gid|operator_size|operator_compose_uid|operator_compose_gid|\
        operator_compose_size|rehearsal_compose_uid|rehearsal_compose_gid|\
        rehearsal_compose_size|runtime_fence_provisioner_uid|runtime_fence_provisioner_gid|\
        runtime_fence_provisioner_size|runtime_fence_base_config_uid|\
        runtime_fence_base_config_gid|runtime_fence_base_config_size|\
        runtime_fence_environment_uid|runtime_fence_environment_gid|\
        runtime_fence_environment_size|runtime_fence_https_ack_uid|\
        runtime_fence_https_ack_gid|runtime_fence_https_ack_size|\
        runtime_fence_provider_header_source_uid|\
        runtime_fence_provider_header_source_gid|runtime_fence_provider_header_source_size|\
        runtime_fence_provider_header_copy_uid|runtime_fence_provider_header_copy_gid|\
        runtime_fence_provider_header_copy_size|reverse_fence_environment_uid|\
        reverse_fence_environment_gid|reverse_fence_environment_size|\
        reverse_fence_https_ack_uid|reverse_fence_https_ack_gid|reverse_fence_https_ack_size|\
        reverse_fence_provider_header_uid|\
        reverse_fence_provider_header_gid|reverse_fence_provider_header_size|\
        source_env_uid|source_env_gid|source_env_size|runtime_env_uid|runtime_env_gid|\
        green_ack_uid|green_ack_gid|green_ack_size|blue_ack_uid|blue_ack_gid|blue_ack_size|\
        green_ack_snapshot_uid|green_ack_snapshot_gid|green_ack_snapshot_size|\
        blue_ack_snapshot_uid|blue_ack_snapshot_gid|blue_ack_snapshot_size|\
        green_probe_token_uid|green_probe_token_gid|green_probe_token_size|\
        blue_probe_token_uid|blue_probe_token_gid|blue_probe_token_size|\
        backup_attestation_last_verified_unix|migration_attempt|migration_expected_batch|\
        live_database_system_identifier|\
        recovery_abort_generation)
            printf '%s\n' integer-or-none
            ;;
        release_manifest_mode|green_restart_policy_intent_mode|\
        green_web_b_restart_policy_intent_mode|replacement_blue_restart_policy_intent_mode|\
        replacement_blue_web_b_restart_policy_intent_mode|\
        forward_pool_plan_mode|forward_ingress_pool_mode|\
        reverse_pool_plan_mode|reverse_ingress_pool_mode|\
        operator_mode|operator_compose_mode|rehearsal_compose_mode|\
        runtime_fence_provisioner_mode|runtime_fence_base_config_mode|\
        runtime_fence_environment_mode|runtime_fence_https_ack_mode|\
        runtime_fence_provider_header_source_mode|\
        runtime_fence_provider_header_copy_mode|reverse_fence_environment_mode|\
        reverse_fence_https_ack_mode|reverse_fence_provider_header_mode|source_env_mode|\
        green_ack_mode|blue_ack_mode|\
        green_ack_snapshot_mode|blue_ack_snapshot_mode|green_probe_token_mode|\
        blue_probe_token_mode)
            printf '%s\n' mode-or-none
            ;;
        reverse_fence_generation)
            printf '%s\n' positive-integer-or-none
            ;;
        migration_lock_timeout|migration_statement_timeout)
            printf '%s\n' timeout-or-none
            ;;
        rollback_backup_checksum|route_original_checksum)
            printf '%s\n' checksum-or-none
            ;;
        blue_bridge_ip)
            printf '%s\n' host
            ;;
        phase|operation_id|green_restart_policy_status|green_web_b_restart_policy_status|\
        replacement_blue_restart_policy_status|\
        replacement_blue_web_b_restart_policy_status|green_writer_member|blue_writer_member|\
        routed_recovery_color|\
        routed_recovery_status|routed_recovery_from_phase|\
        blue_container|green_container|green_web_b_container|replacement_blue_container|\
        replacement_blue_web_b_container|green_state_volume|green_web_b_private_volume|\
        blue_state_volume|blue_web_b_private_volume|coordination_volume|\
        blue_restore_status|blue_compose_project|blue_compose_service|runtime_fence_operation_id|\
        reverse_fence_operation_id|recovery_abort_from_phase|\
        rollback_backup_status|route_target|https_route_target|\
        migration_status)
            printf '%s\n' atom
            ;;
        blue_image_reference|blue_compose_config_files|green_plan_image_reference|\
        replacement_blue_plan_image_reference)
            printf '%s\n' text
            ;;
        *)
            fail "operation state contains an unknown key: $1"
            ;;
    esac
}

validate_state_typed_value()
{
    typed_key=$1
    typed_value=$2
    typed_kind=$(state_key_type "$typed_key")

    case "$typed_kind" in
        version)
            [ "$typed_value" = "$STATE_VERSION" ]
            ;;
        safe-token)
            printf '%s' "$typed_value" | grep -Eq '^[A-Za-z0-9._:-]{16,128}$'
            ;;
        container-id-or-none)
            [ "$typed_value" = none ] \
                || printf '%s' "$typed_value" | grep -Eq '^[0-9a-f]{64}$'
            ;;
        image-id-or-none)
            [ "$typed_value" = none ] \
                || printf '%s' "$typed_value" | grep -Eq '^sha256:[0-9a-f]{64}$'
            ;;
        sha256-or-absent)
            [ "$typed_value" = absent ] \
                || printf '%s' "$typed_value" | grep -Eq '^[0-9a-f]{64}$'
            ;;
        sha256-or-none)
            [ "$typed_value" = none ] \
                || printf '%s' "$typed_value" | grep -Eq '^[0-9a-f]{64}$'
            ;;
        path-or-none)
            if [ "$typed_value" != none ]; then
                validate_path "$typed_value" "operation state key $typed_key"
            fi
            return
            ;;
        integer-or-none)
            [ "$typed_value" = none ] \
                || printf '%s' "$typed_value" | grep -Eq '^[0-9]+$'
            ;;
        positive-integer-or-none)
            [ "$typed_value" = none ] \
                || printf '%s' "$typed_value" | grep -Eq '^[1-9][0-9]*$'
            ;;
        mode-or-none)
            [ "$typed_value" = none ] \
                || printf '%s' "$typed_value" | grep -Eq '^[0-7]{3,4}$'
            ;;
        timeout-or-none)
            [ "$typed_value" = none ] \
                || printf '%s' "$typed_value" | grep -Eq '^[1-9][0-9]*(ms|s)$'
            ;;
        immutable-file-metadata)
            printf '%s' "$typed_value" \
                | grep -Eq "^${immutable_uid}:${immutable_gid}:600:[1-9][0-9]*$"
            ;;
        checksum-or-none)
            [ "$typed_value" = none ] || [ "$typed_value" = absent ] \
                || printf '%s' "$typed_value" | grep -Eq '^[0-9]+-[0-9]+$'
            ;;
        host)
            validate_host "$typed_value" "operation state key $typed_key"
            return
            ;;
        atom)
            printf '%s' "$typed_value" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._:@-]{0,255}$'
            ;;
        text)
            [ -n "$typed_value" ] \
                && ! printf '%s' "$typed_value" | grep -q '[[:cntrl:]]'
            ;;
        *)
            fail "operation state key has no validator: $typed_key"
            ;;
    esac || fail "operation state key has an invalid $typed_kind value: $typed_key"
}

validate_state_document()
{
    state_document=${1:-$state_file}
    assert_non_symlink_regular_file "$state_document" 'operation state'
    [ "$(file_uid "$state_document")" = "$operator_uid" ] \
        && [ "$(file_gid "$state_document")" = "$operator_gid" ] \
        && [ "$(file_mode "$state_document")" = 600 ] \
        || fail 'operation state ownership or mode is invalid'
    state_structure_error=$(awk '
        index($0, "=") == 0 || $0 ~ /\r/ { print "malformed-line"; exit }
        {
            key = substr($0, 1, index($0, "=") - 1)
            if (key !~ /^[a-z][a-z0-9_]*$/) { print "malformed-key"; exit }
            if (++seen[key] != 1) { print "duplicate-key:" key; exit }
        }
    ' "$state_document")
    [ -z "$state_structure_error" ] \
        || fail "operation state structure is invalid: $state_structure_error"

    while IFS= read -r state_line || [ -n "$state_line" ]; do
        state_line_key=${state_line%%=*}
        state_line_value=${state_line#*=}
        validate_state_typed_value "$state_line_key" "$state_line_value"
    done < "$state_document"
}

state_value()
{
    state_key=$1
    if ! state_result=$(awk -v expected_key="$state_key" '
        {
            key = substr($0, 1, index($0, "=") - 1)
            if (key == expected_key) {
                matches++
                result = substr($0, index($0, "=") + 1)
            }
        }
        END {
            if (matches != 1) { exit 1 }
            print result
        }
    ' "$state_file"); then
        fail "operation state key is absent or duplicated: $state_key"
    fi
    validate_state_typed_value "$state_key" "$state_result"
    printf '%s\n' "$state_result"
}

migration_attempt_state_value()
{
    attempt_state_key=$1
    attempt_state_result=$(awk -F= -v expected_key="$attempt_state_key" '
        $1 == expected_key { matches++; result = substr($0, index($0, "=") + 1) }
        END { if (matches != 1) exit 1; print result }
    ' "$migration_attempt_state_file") \
        || fail "migration attempt state key is absent or duplicated: $attempt_state_key"
    printf '%s\n' "$attempt_state_result"
}

derive_live_expand_migration_attempt_identity()
{
    validate_safe_token "$operation_id" 'live-expand migration operation ID'
    validate_positive_integer "$attempt_number" 'live-expand migration attempt number'
    attempt_identity_digest=$(printf '%s:%s\n' "$operation_id" "$attempt_number" \
        | sha256sum | awk '{print substr($1, 1, 32)}')
    attempt_backend_application_name="coolify-control-plane-expand-${attempt_identity_digest}"
    attempt_advisory_lock_identity="coolify-control-plane-expand-attempt:${attempt_identity_digest}"
}

validate_migration_attempt_state()
{
    validated_attempt_state=${1:-$migration_attempt_state_file}
    assert_non_symlink_regular_file "$validated_attempt_state" 'migration attempt state'
    [ "$(file_uid "$validated_attempt_state")" = "$operator_uid" ] \
        && [ "$(file_gid "$validated_attempt_state")" = "$operator_gid" ] \
        && [ "$(file_mode "$validated_attempt_state")" = 600 ] \
        || fail 'migration attempt state ownership or mode is invalid'
    awk -F= '
        BEGIN {
            expected[1] = "version"; expected[2] = "operation_id"; expected[3] = "attempt";
            expected[4] = "status"; expected[5] = "expected_batch";
            expected[6] = "ledger_before_sha256"; expected[7] = "pending_sha256";
            expected[8] = "image_id"; expected[9] = "image_digest";
            expected[10] = "compatibility_sha256"; expected[11] = "manifest_sha256";
            expected[12] = "database_identity_sha256"; expected[13] = "lock_timeout";
            expected[14] = "statement_timeout"; expected[15] = "backend_application_name";
            expected[16] = "runner_name"; expected[17] = "runner_id";
            expected[18] = "runner_exit_code"; expected[19] = "runner_log_sha256";
            expected[20] = "verification_sha256"; expected[21] = "database_outcome";
        }
        NF != 2 || $1 != expected[NR] || seen[$1]++ { exit 1 }
        END { exit(NR == 21 ? 0 : 1) }
    ' "$validated_attempt_state" \
        || fail 'migration attempt state has a malformed or noncanonical key inventory'

    migration_attempt_state_file=$validated_attempt_state
    [ "$(migration_attempt_state_value version)" = 1 ] \
        || fail 'migration attempt state version is unsupported'
    [ "$(migration_attempt_state_value operation_id)" = "$operation_id" ] \
        || fail 'migration attempt operation identity changed'
    attempt_number=$(migration_attempt_state_value attempt)
    attempt_status=$(migration_attempt_state_value status)
    attempt_expected_batch=$(migration_attempt_state_value expected_batch)
    attempt_ledger_before_sha256=$(migration_attempt_state_value ledger_before_sha256)
    attempt_pending_sha256=$(migration_attempt_state_value pending_sha256)
    attempt_image_id=$(migration_attempt_state_value image_id)
    attempt_image_digest=$(migration_attempt_state_value image_digest)
    attempt_compatibility_sha256=$(migration_attempt_state_value compatibility_sha256)
    attempt_manifest_sha256=$(migration_attempt_state_value manifest_sha256)
    attempt_database_identity_sha256=$(migration_attempt_state_value database_identity_sha256)
    attempt_lock_timeout=$(migration_attempt_state_value lock_timeout)
    attempt_statement_timeout=$(migration_attempt_state_value statement_timeout)
    attempt_backend_application_name=$(migration_attempt_state_value backend_application_name)
    attempt_runner_name=$(migration_attempt_state_value runner_name)
    attempt_runner_id=$(migration_attempt_state_value runner_id)
    attempt_runner_exit_code=$(migration_attempt_state_value runner_exit_code)
    attempt_runner_log_sha256=$(migration_attempt_state_value runner_log_sha256)
    attempt_verification_sha256=$(migration_attempt_state_value verification_sha256)
    attempt_database_outcome=$(migration_attempt_state_value database_outcome)

    if ! printf '%s' "$attempt_number" | grep -Eq '^[1-9][0-9]*$' \
        || ! printf '%s' "$attempt_expected_batch" | grep -Eq '^[1-9][0-9]*$'; then
        fail 'migration attempt number or expected batch is malformed'
    fi
    case "$attempt_status" in planned|created|start-intent|launched|active|applied|failed|blocked) ;; *)
        fail 'migration attempt status is invalid' ;;
    esac
    for attempt_sha256 in "$attempt_ledger_before_sha256" "$attempt_pending_sha256" \
        "$attempt_compatibility_sha256" "$attempt_manifest_sha256" \
        "$attempt_database_identity_sha256"
    do
        printf '%s' "$attempt_sha256" | grep -Eq '^[0-9a-f]{64}$' \
            || fail 'migration attempt contains a malformed SHA-256 identity'
    done
    if ! printf '%s' "$attempt_image_id" | grep -Eq '^sha256:[0-9a-f]{64}$' \
        || ! printf '%s' "$attempt_image_digest" | grep -Eq '^sha256:[0-9a-f]{64}$'; then
        fail 'migration attempt image identity is malformed'
    fi
    validate_migration_timeout "$attempt_lock_timeout" 'migration attempt lock timeout'
    validate_migration_timeout "$attempt_statement_timeout" \
        'migration attempt statement timeout'
    persisted_attempt_backend_application_name=$attempt_backend_application_name
    derive_live_expand_migration_attempt_identity
    [ "$persisted_attempt_backend_application_name" = "$attempt_backend_application_name" ] \
        || fail 'migration attempt backend application name changed'
    printf '%s' "$attempt_runner_name" | grep -Eq "^coolify-cp-migrate-[0-9a-f]{24}-a${attempt_number}$" \
        || fail 'migration attempt runner name is malformed'
    [ "$attempt_runner_id" = none ] \
        || printf '%s' "$attempt_runner_id" | grep -Eq '^[0-9a-f]{64}$' \
        || fail 'migration attempt runner ID is malformed'
    [ "$attempt_runner_exit_code" = none ] \
        || { printf '%s' "$attempt_runner_exit_code" | grep -Eq '^[0-9]+$' \
            && [ "$attempt_runner_exit_code" -le 255 ]; } \
        || fail 'migration attempt runner exit code is malformed'
    [ "$attempt_runner_log_sha256" = none ] \
        || printf '%s' "$attempt_runner_log_sha256" | grep -Eq '^[0-9a-f]{64}$' \
        || fail 'migration attempt runner log digest is malformed'
    [ "$attempt_verification_sha256" = none ] \
        || printf '%s' "$attempt_verification_sha256" | grep -Eq '^[0-9a-f]{64}$' \
        || fail 'migration attempt verification digest is malformed'
    case "$attempt_database_outcome" in unknown|unchanged|applied|drift) ;; *)
        fail 'migration attempt database outcome is invalid' ;;
    esac

    case "$attempt_status" in
        planned)
            [ "$attempt_runner_id:$attempt_runner_exit_code:$attempt_runner_log_sha256:$attempt_verification_sha256:$attempt_database_outcome" \
                = none:none:none:none:unknown ] \
                || fail 'planned migration attempt contains launched or terminal evidence'
            ;;
        created|start-intent|launched|active)
            [ "$attempt_runner_id" != none ] \
                && [ "$attempt_runner_exit_code:$attempt_runner_log_sha256:$attempt_verification_sha256:$attempt_database_outcome" \
                    = none:none:none:unknown ] \
                || fail 'nonterminal migration attempt evidence is inconsistent'
            ;;
        applied)
            [ "$attempt_runner_id" != none ] \
                && [ "$attempt_runner_exit_code" != none ] \
                && [ "$attempt_runner_log_sha256" != none ] \
                && [ "$attempt_verification_sha256" != none ] \
                && [ "$attempt_database_outcome" = applied ] \
                || fail 'applied migration attempt lacks durable applied evidence'
            ;;
        failed)
            [ "$attempt_runner_id" != none ] \
                && [ "$attempt_runner_exit_code" != none ] \
                && [ "$attempt_runner_log_sha256" != none ] \
                && [ "$attempt_verification_sha256" = none ] \
                && [ "$attempt_database_outcome" = unchanged ] \
                || fail 'failed migration attempt lacks durable unchanged evidence'
            ;;
        blocked)
            [ "$attempt_runner_id" != none ] \
                && [ "$attempt_runner_exit_code" != none ] \
                && [ "$attempt_runner_log_sha256" != none ] \
                && [ "$attempt_verification_sha256" = none ] \
                && [ "$attempt_database_outcome" = drift ] \
                || fail 'blocked migration attempt lacks exact terminal runner and database evidence'
            ;;
    esac
}

write_migration_attempt_state()
{
    next_attempt_status=$1
    migration_attempt_state_destination=$migration_attempt_state_file
    migration_attempt_state_candidate="${migration_attempt_state_file}.new"
    rm -f "$migration_attempt_state_candidate"
    if ! (umask 077; set -C; : > "$migration_attempt_state_candidate") 2>/dev/null; then
        fail 'could not create an atomic migration-attempt state candidate'
    fi
    {
        printf '%s\n' 'version=1'
        printf 'operation_id=%s\n' "$operation_id"
        printf 'attempt=%s\n' "$attempt_number"
        printf 'status=%s\n' "$next_attempt_status"
        printf 'expected_batch=%s\n' "$attempt_expected_batch"
        printf 'ledger_before_sha256=%s\n' "$attempt_ledger_before_sha256"
        printf 'pending_sha256=%s\n' "$attempt_pending_sha256"
        printf 'image_id=%s\n' "$attempt_image_id"
        printf 'image_digest=%s\n' "$attempt_image_digest"
        printf 'compatibility_sha256=%s\n' "$attempt_compatibility_sha256"
        printf 'manifest_sha256=%s\n' "$attempt_manifest_sha256"
        printf 'database_identity_sha256=%s\n' "$attempt_database_identity_sha256"
        printf 'lock_timeout=%s\n' "$attempt_lock_timeout"
        printf 'statement_timeout=%s\n' "$attempt_statement_timeout"
        printf 'backend_application_name=%s\n' "$attempt_backend_application_name"
        printf 'runner_name=%s\n' "$attempt_runner_name"
        printf 'runner_id=%s\n' "$attempt_runner_id"
        printf 'runner_exit_code=%s\n' "$attempt_runner_exit_code"
        printf 'runner_log_sha256=%s\n' "$attempt_runner_log_sha256"
        printf 'verification_sha256=%s\n' "$attempt_verification_sha256"
        printf 'database_outcome=%s\n' "$attempt_database_outcome"
    } > "$migration_attempt_state_candidate"
    attempt_status=$next_attempt_status
    validate_migration_attempt_state "$migration_attempt_state_candidate"
    migration_attempt_state_file=$migration_attempt_state_destination
    test_crash after-migration-attempt-state-candidate
    fsync_replace "$migration_attempt_state_candidate" "$migration_attempt_state_file"
    state_migration_attempt_state_sha256=$(sha256_file "$migration_attempt_state_file")
}

load_migration_attempt_state()
{
    attempt_to_load=$1
    migration_attempt_state_file="$migration_attempts_directory/attempt-${attempt_to_load}.state"
    migration_attempt_log_file="$migration_attempts_directory/attempt-${attempt_to_load}.log"
    validate_migration_attempt_state
    [ "$attempt_number" = "$attempt_to_load" ] \
        || fail 'migration attempt filename and document identity differ'
    state_migration_attempt=$attempt_number
    state_migration_attempt_state_sha256=$(sha256_file "$migration_attempt_state_file")
}

write_state()
{
    next_phase=$1
    state_candidate="$operation_directory/.state-${operation_id}-$$.new"

    if ! (umask 077; set -C; : > "$state_candidate") 2>/dev/null; then
        fail 'could not create an atomic operation-state candidate'
    fi

    {
        printf 'version=%s\n' "$STATE_VERSION"
        printf 'phase=%s\n' "$next_phase"
        printf 'operation_id=%s\n' "$operation_id"
        printf 'release_id=%s\n' "$state_release_id"
        printf 'release_manifest_path=%s\n' "$state_release_manifest_path"
        printf 'release_manifest_sha256=%s\n' "$state_release_manifest_sha256"
        printf 'release_manifest_uid=%s\n' "$state_release_manifest_uid"
        printf 'release_manifest_gid=%s\n' "$state_release_manifest_gid"
        printf 'release_manifest_mode=%s\n' "$state_release_manifest_mode"
        printf 'release_manifest_size=%s\n' "$state_release_manifest_size"
        printf 'release_assets_sha256=%s\n' "$state_release_assets_sha256"
        printf 'operator_path=%s\n' "$state_operator_path"
        printf 'operator_sha256=%s\n' "$state_operator_sha256"
        printf 'operator_uid=%s\n' "$state_operator_uid"
        printf 'operator_gid=%s\n' "$state_operator_gid"
        printf 'operator_mode=%s\n' "$state_operator_mode"
        printf 'operator_size=%s\n' "$state_operator_size"
        printf 'operator_compose_path=%s\n' "$state_operator_compose_path"
        printf 'operator_compose_sha256=%s\n' "$state_operator_compose_sha256"
        printf 'operator_compose_uid=%s\n' "$state_operator_compose_uid"
        printf 'operator_compose_gid=%s\n' "$state_operator_compose_gid"
        printf 'operator_compose_mode=%s\n' "$state_operator_compose_mode"
        printf 'operator_compose_size=%s\n' "$state_operator_compose_size"
        printf 'rehearsal_compose_path=%s\n' "$state_rehearsal_compose_path"
        printf 'rehearsal_compose_sha256=%s\n' "$state_rehearsal_compose_sha256"
        printf 'rehearsal_compose_uid=%s\n' "$state_rehearsal_compose_uid"
        printf 'rehearsal_compose_gid=%s\n' "$state_rehearsal_compose_gid"
        printf 'rehearsal_compose_mode=%s\n' "$state_rehearsal_compose_mode"
        printf 'rehearsal_compose_size=%s\n' "$state_rehearsal_compose_size"
        printf 'writer_epoch=%s\n' "$writer_epoch"
        printf 'blue_writer_epoch=%s\n' "$blue_writer_epoch"
        printf 'green_writer_member=%s\n' "$green_writer_member"
        printf 'blue_writer_member=%s\n' "$blue_writer_member"
        printf 'mutation_freeze_epoch=%s\n' "$mutation_freeze_epoch"
        printf 'reverse_mutation_freeze_epoch=%s\n' "$reverse_mutation_freeze_epoch"
        printf 'green_web_epoch=%s\n' "$green_web_epoch"
        printf 'green_web_b_epoch=%s\n' "$green_web_b_epoch"
        printf 'blue_web_epoch=%s\n' "$blue_web_epoch"
        printf 'blue_web_b_epoch=%s\n' "$blue_web_b_epoch"
        printf 'green_web_a_route_drain_epoch=%s\n' "$green_web_a_route_drain_epoch"
        printf 'green_web_b_route_drain_epoch=%s\n' "$green_web_b_route_drain_epoch"
        printf 'blue_web_a_route_drain_epoch=%s\n' "$blue_web_a_route_drain_epoch"
        printf 'blue_web_b_route_drain_epoch=%s\n' "$blue_web_b_route_drain_epoch"
        printf 'green_web_a_route_identity=%s\n' "$green_web_a_route_identity"
        printf 'green_web_b_route_identity=%s\n' "$green_web_b_route_identity"
        printf 'blue_web_a_route_identity=%s\n' "$blue_web_a_route_identity"
        printf 'blue_web_b_route_identity=%s\n' "$blue_web_b_route_identity"
        printf 'blue_container=%s\n' "$blue_container"
        printf 'green_container=%s\n' "$green_container"
        printf 'green_web_b_container=%s\n' "$green_web_b_container"
        printf 'replacement_blue_container=%s\n' "$replacement_blue_container"
        printf 'replacement_blue_web_b_container=%s\n' "$replacement_blue_web_b_container"
        printf 'green_state_volume=%s\n' "$green_state_volume"
        printf 'green_web_b_private_volume=%s\n' "$green_web_b_private_volume"
        printf 'blue_state_volume=%s\n' "$blue_state_volume"
        printf 'blue_web_b_private_volume=%s\n' "$blue_web_b_private_volume"
        printf 'coordination_volume=%s\n' "$coordination_volume"
        printf 'green_restart_policy_status=%s\n' "$state_green_restart_policy_status"
        printf 'green_restart_policy_intent_path=%s\n' "$state_green_restart_policy_intent_path"
        printf 'green_restart_policy_intent_sha256=%s\n' "$state_green_restart_policy_intent_sha256"
        printf 'green_restart_policy_intent_uid=%s\n' "$state_green_restart_policy_intent_uid"
        printf 'green_restart_policy_intent_gid=%s\n' "$state_green_restart_policy_intent_gid"
        printf 'green_restart_policy_intent_mode=%s\n' "$state_green_restart_policy_intent_mode"
        printf 'green_restart_policy_intent_size=%s\n' "$state_green_restart_policy_intent_size"
        printf 'green_web_b_restart_policy_status=%s\n' \
            "$state_green_web_b_restart_policy_status"
        printf 'green_web_b_restart_policy_intent_path=%s\n' \
            "$state_green_web_b_restart_policy_intent_path"
        printf 'green_web_b_restart_policy_intent_sha256=%s\n' \
            "$state_green_web_b_restart_policy_intent_sha256"
        printf 'green_web_b_restart_policy_intent_uid=%s\n' \
            "$state_green_web_b_restart_policy_intent_uid"
        printf 'green_web_b_restart_policy_intent_gid=%s\n' \
            "$state_green_web_b_restart_policy_intent_gid"
        printf 'green_web_b_restart_policy_intent_mode=%s\n' \
            "$state_green_web_b_restart_policy_intent_mode"
        printf 'green_web_b_restart_policy_intent_size=%s\n' \
            "$state_green_web_b_restart_policy_intent_size"
        printf 'replacement_blue_restart_policy_status=%s\n' \
            "$state_replacement_blue_restart_policy_status"
        printf 'replacement_blue_restart_policy_intent_path=%s\n' \
            "$state_replacement_blue_restart_policy_intent_path"
        printf 'replacement_blue_restart_policy_intent_sha256=%s\n' \
            "$state_replacement_blue_restart_policy_intent_sha256"
        printf 'replacement_blue_restart_policy_intent_uid=%s\n' \
            "$state_replacement_blue_restart_policy_intent_uid"
        printf 'replacement_blue_restart_policy_intent_gid=%s\n' \
            "$state_replacement_blue_restart_policy_intent_gid"
        printf 'replacement_blue_restart_policy_intent_mode=%s\n' \
            "$state_replacement_blue_restart_policy_intent_mode"
        printf 'replacement_blue_restart_policy_intent_size=%s\n' \
            "$state_replacement_blue_restart_policy_intent_size"
        printf 'replacement_blue_web_b_restart_policy_status=%s\n' \
            "$state_replacement_blue_web_b_restart_policy_status"
        printf 'replacement_blue_web_b_restart_policy_intent_path=%s\n' \
            "$state_replacement_blue_web_b_restart_policy_intent_path"
        printf 'replacement_blue_web_b_restart_policy_intent_sha256=%s\n' \
            "$state_replacement_blue_web_b_restart_policy_intent_sha256"
        printf 'replacement_blue_web_b_restart_policy_intent_uid=%s\n' \
            "$state_replacement_blue_web_b_restart_policy_intent_uid"
        printf 'replacement_blue_web_b_restart_policy_intent_gid=%s\n' \
            "$state_replacement_blue_web_b_restart_policy_intent_gid"
        printf 'replacement_blue_web_b_restart_policy_intent_mode=%s\n' \
            "$state_replacement_blue_web_b_restart_policy_intent_mode"
        printf 'replacement_blue_web_b_restart_policy_intent_size=%s\n' \
            "$state_replacement_blue_web_b_restart_policy_intent_size"
        printf 'routed_recovery_generation=%s\n' "$state_routed_recovery_generation"
        printf 'routed_recovery_color=%s\n' "$state_routed_recovery_color"
        printf 'routed_recovery_status=%s\n' "$state_routed_recovery_status"
        printf 'routed_recovery_from_phase=%s\n' "$state_routed_recovery_from_phase"
        printf 'blue_id=%s\n' "$state_blue_id"
        printf 'blue_original_id=%s\n' "$state_blue_original_id"
        printf 'blue_rollback_replacement_id=%s\n' "$state_blue_rollback_replacement_id"
        printf 'blue_restore_status=%s\n' "$state_blue_restore_status"
        printf 'blue_image_id=%s\n' "$state_blue_image_id"
        printf 'blue_image_digest=%s\n' "$state_blue_image_digest"
        printf 'blue_image_reference=%s\n' "$state_blue_image_reference"
        printf 'blue_bridge_ip=%s\n' "$state_blue_bridge_ip"
        printf 'blue_compose_project=%s\n' "$state_blue_compose_project"
        printf 'blue_compose_service=%s\n' "$state_blue_compose_service"
        printf 'blue_compose_config_files=%s\n' "$state_blue_compose_config_files"
        printf 'green_id=%s\n' "$state_green_id"
        printf 'green_web_b_id=%s\n' "$state_green_web_b_id"
        printf 'green_image_id=%s\n' "$state_green_image_id"
        printf 'green_web_b_image_id=%s\n' "$state_green_web_b_image_id"
        printf 'green_plan_image_reference=%s\n' "$state_green_plan_image_reference"
        printf 'green_plan_image_id=%s\n' "$state_green_plan_image_id"
        printf 'green_plan_network_id=%s\n' "$state_green_plan_network_id"
        printf 'green_container_runtime_sha256=%s\n' "$state_green_container_runtime_sha256"
        printf 'green_web_b_container_runtime_sha256=%s\n' \
            "$state_green_web_b_container_runtime_sha256"
        printf 'replacement_blue_plan_image_reference=%s\n' "$state_replacement_blue_plan_image_reference"
        printf 'replacement_blue_plan_image_id=%s\n' "$state_replacement_blue_plan_image_id"
        printf 'replacement_blue_plan_network_id=%s\n' "$state_replacement_blue_plan_network_id"
        printf 'replacement_blue_container_runtime_sha256=%s\n' \
            "$state_replacement_blue_container_runtime_sha256"
        printf 'replacement_blue_web_b_id=%s\n' "$state_replacement_blue_web_b_id"
        printf 'replacement_blue_web_b_image_id=%s\n' \
            "$state_replacement_blue_web_b_image_id"
        printf 'replacement_blue_web_b_container_runtime_sha256=%s\n' \
            "$state_replacement_blue_web_b_container_runtime_sha256"
        printf 'forward_pool_plan_path=%s\n' "$state_forward_pool_plan_path"
        printf 'forward_pool_plan_sha256=%s\n' "$state_forward_pool_plan_sha256"
        printf 'forward_pool_plan_uid=%s\n' "$state_forward_pool_plan_uid"
        printf 'forward_pool_plan_gid=%s\n' "$state_forward_pool_plan_gid"
        printf 'forward_pool_plan_mode=%s\n' "$state_forward_pool_plan_mode"
        printf 'forward_pool_plan_size=%s\n' "$state_forward_pool_plan_size"
        printf 'forward_ingress_pool_path=%s\n' "$state_forward_ingress_pool_path"
        printf 'forward_ingress_pool_sha256=%s\n' "$state_forward_ingress_pool_sha256"
        printf 'forward_ingress_pool_uid=%s\n' "$state_forward_ingress_pool_uid"
        printf 'forward_ingress_pool_gid=%s\n' "$state_forward_ingress_pool_gid"
        printf 'forward_ingress_pool_mode=%s\n' "$state_forward_ingress_pool_mode"
        printf 'forward_ingress_pool_size=%s\n' "$state_forward_ingress_pool_size"
        printf 'reverse_pool_plan_path=%s\n' "$state_reverse_pool_plan_path"
        printf 'reverse_pool_plan_sha256=%s\n' "$state_reverse_pool_plan_sha256"
        printf 'reverse_pool_plan_uid=%s\n' "$state_reverse_pool_plan_uid"
        printf 'reverse_pool_plan_gid=%s\n' "$state_reverse_pool_plan_gid"
        printf 'reverse_pool_plan_mode=%s\n' "$state_reverse_pool_plan_mode"
        printf 'reverse_pool_plan_size=%s\n' "$state_reverse_pool_plan_size"
        printf 'reverse_ingress_pool_path=%s\n' "$state_reverse_ingress_pool_path"
        printf 'reverse_ingress_pool_sha256=%s\n' "$state_reverse_ingress_pool_sha256"
        printf 'reverse_ingress_pool_uid=%s\n' "$state_reverse_ingress_pool_uid"
        printf 'reverse_ingress_pool_gid=%s\n' "$state_reverse_ingress_pool_gid"
        printf 'reverse_ingress_pool_mode=%s\n' "$state_reverse_ingress_pool_mode"
        printf 'reverse_ingress_pool_size=%s\n' "$state_reverse_ingress_pool_size"
        printf 'runtime_fence_operation_id=%s\n' "$state_runtime_fence_operation_id"
        printf 'runtime_fence_provisioner_path=%s\n' "$state_runtime_fence_provisioner_path"
        printf 'runtime_fence_provisioner_sha256=%s\n' "$state_runtime_fence_provisioner_sha256"
        printf 'runtime_fence_provisioner_uid=%s\n' "$state_runtime_fence_provisioner_uid"
        printf 'runtime_fence_provisioner_gid=%s\n' "$state_runtime_fence_provisioner_gid"
        printf 'runtime_fence_provisioner_mode=%s\n' "$state_runtime_fence_provisioner_mode"
        printf 'runtime_fence_provisioner_size=%s\n' "$state_runtime_fence_provisioner_size"
        printf 'runtime_fence_base_config_path=%s\n' "$state_runtime_fence_base_config_path"
        printf 'runtime_fence_base_config_sha256=%s\n' "$state_runtime_fence_base_config_sha256"
        printf 'runtime_fence_base_config_uid=%s\n' "$state_runtime_fence_base_config_uid"
        printf 'runtime_fence_base_config_gid=%s\n' "$state_runtime_fence_base_config_gid"
        printf 'runtime_fence_base_config_mode=%s\n' "$state_runtime_fence_base_config_mode"
        printf 'runtime_fence_base_config_size=%s\n' "$state_runtime_fence_base_config_size"
        printf 'runtime_fence_environment_path=%s\n' "$state_runtime_fence_environment_path"
        printf 'runtime_fence_environment_sha256=%s\n' "$state_runtime_fence_environment_sha256"
        printf 'runtime_fence_environment_uid=%s\n' "$state_runtime_fence_environment_uid"
        printf 'runtime_fence_environment_gid=%s\n' "$state_runtime_fence_environment_gid"
        printf 'runtime_fence_environment_mode=%s\n' "$state_runtime_fence_environment_mode"
        printf 'runtime_fence_environment_size=%s\n' "$state_runtime_fence_environment_size"
        printf 'runtime_fence_https_ack_path=%s\n' "$state_runtime_fence_https_ack_path"
        printf 'runtime_fence_https_ack_sha256=%s\n' "$state_runtime_fence_https_ack_sha256"
        printf 'runtime_fence_https_ack_uid=%s\n' "$state_runtime_fence_https_ack_uid"
        printf 'runtime_fence_https_ack_gid=%s\n' "$state_runtime_fence_https_ack_gid"
        printf 'runtime_fence_https_ack_mode=%s\n' "$state_runtime_fence_https_ack_mode"
        printf 'runtime_fence_https_ack_size=%s\n' "$state_runtime_fence_https_ack_size"
        printf 'runtime_fence_provider_header_source_path=%s\n' \
            "$state_runtime_fence_provider_header_source_path"
        printf 'runtime_fence_provider_header_source_sha256=%s\n' \
            "$state_runtime_fence_provider_header_source_sha256"
        printf 'runtime_fence_provider_header_source_uid=%s\n' \
            "$state_runtime_fence_provider_header_source_uid"
        printf 'runtime_fence_provider_header_source_gid=%s\n' \
            "$state_runtime_fence_provider_header_source_gid"
        printf 'runtime_fence_provider_header_source_mode=%s\n' \
            "$state_runtime_fence_provider_header_source_mode"
        printf 'runtime_fence_provider_header_source_size=%s\n' \
            "$state_runtime_fence_provider_header_source_size"
        printf 'runtime_fence_provider_header_copy_path=%s\n' \
            "$state_runtime_fence_provider_header_copy_path"
        printf 'runtime_fence_provider_header_copy_sha256=%s\n' \
            "$state_runtime_fence_provider_header_copy_sha256"
        printf 'runtime_fence_provider_header_copy_uid=%s\n' \
            "$state_runtime_fence_provider_header_copy_uid"
        printf 'runtime_fence_provider_header_copy_gid=%s\n' \
            "$state_runtime_fence_provider_header_copy_gid"
        printf 'runtime_fence_provider_header_copy_mode=%s\n' \
            "$state_runtime_fence_provider_header_copy_mode"
        printf 'runtime_fence_provider_header_copy_size=%s\n' \
            "$state_runtime_fence_provider_header_copy_size"
        printf 'reverse_fence_generation=%s\n' "$state_reverse_fence_generation"
        printf 'reverse_fence_operation_id=%s\n' "$state_reverse_fence_operation_id"
        printf 'reverse_fence_provisioner_path=%s\n' "$state_reverse_fence_provisioner_path"
        printf 'reverse_fence_provisioner_sha256=%s\n' "$state_reverse_fence_provisioner_sha256"
        printf 'reverse_fence_environment_path=%s\n' "$state_reverse_fence_environment_path"
        printf 'reverse_fence_environment_uid=%s\n' "$state_reverse_fence_environment_uid"
        printf 'reverse_fence_environment_gid=%s\n' "$state_reverse_fence_environment_gid"
        printf 'reverse_fence_environment_mode=%s\n' "$state_reverse_fence_environment_mode"
        printf 'reverse_fence_environment_size=%s\n' "$state_reverse_fence_environment_size"
        printf 'reverse_fence_https_ack_path=%s\n' "$state_reverse_fence_https_ack_path"
        printf 'reverse_fence_https_ack_sha256=%s\n' "$state_reverse_fence_https_ack_sha256"
        printf 'reverse_fence_https_ack_uid=%s\n' "$state_reverse_fence_https_ack_uid"
        printf 'reverse_fence_https_ack_gid=%s\n' "$state_reverse_fence_https_ack_gid"
        printf 'reverse_fence_https_ack_mode=%s\n' "$state_reverse_fence_https_ack_mode"
        printf 'reverse_fence_https_ack_size=%s\n' "$state_reverse_fence_https_ack_size"
        printf 'reverse_fence_provider_header_path=%s\n' "$state_reverse_fence_provider_header_path"
        printf 'reverse_fence_provider_header_sha256=%s\n' "$state_reverse_fence_provider_header_sha256"
        printf 'reverse_fence_provider_header_uid=%s\n' "$state_reverse_fence_provider_header_uid"
        printf 'reverse_fence_provider_header_gid=%s\n' "$state_reverse_fence_provider_header_gid"
        printf 'reverse_fence_provider_header_mode=%s\n' "$state_reverse_fence_provider_header_mode"
        printf 'reverse_fence_provider_header_size=%s\n' "$state_reverse_fence_provider_header_size"
        printf 'recovery_abort_generation=%s\n' "$state_recovery_abort_generation"
        printf 'recovery_abort_from_phase=%s\n' "$state_recovery_abort_from_phase"
        printf 'reverse_fence_environment_sha256=%s\n' "$state_reverse_fence_environment_sha256"
        printf 'replacement_blue_id=%s\n' "$state_replacement_blue_id"
        printf 'replacement_blue_image_id=%s\n' "$state_replacement_blue_image_id"
        printf 'proxy_id=%s\n' "$state_proxy_id"
        printf 'proxy_image_id=%s\n' "$state_proxy_image_id"
        printf 'source_compose_checksum=%s\n' "$state_source_compose_checksum"
        printf 'source_runtime_sha256=%s\n' "$state_source_runtime_sha256"
        printf 'source_rendered_sha256=%s\n' "$state_source_rendered_sha256"
        printf 'source_base_path=%s\n' "$state_source_base_path"
        printf 'source_base_sha256=%s\n' "$state_source_base_sha256"
        printf 'source_prod_path=%s\n' "$state_source_prod_path"
        printf 'source_prod_sha256=%s\n' "$state_source_prod_sha256"
        printf 'source_custom_path=%s\n' "$state_source_custom_path"
        printf 'source_custom_sha256=%s\n' "$state_source_custom_sha256"
        printf 'source_postgres_path=%s\n' "$state_source_postgres_path"
        printf 'source_postgres_sha256=%s\n' "$state_source_postgres_sha256"
        printf 'source_env_path=%s\n' "$state_source_env_path"
        printf 'source_env_sha256=%s\n' "$state_source_env_sha256"
        printf 'source_env_uid=%s\n' "$state_source_env_uid"
        printf 'source_env_gid=%s\n' "$state_source_env_gid"
        printf 'source_env_mode=%s\n' "$state_source_env_mode"
        printf 'source_env_size=%s\n' "$state_source_env_size"
        printf 'runtime_env_uid=%s\n' "$runtime_env_uid"
        printf 'runtime_env_gid=%s\n' "$runtime_env_gid"
        printf 'green_runtime_env_path=%s\n' "$state_green_runtime_env_path"
        printf 'green_runtime_env_sha256=%s\n' "$state_green_runtime_env_sha256"
        printf 'blue_runtime_env_path=%s\n' "$state_blue_runtime_env_path"
        printf 'blue_runtime_env_sha256=%s\n' "$state_blue_runtime_env_sha256"
        printf 'green_ack_path=%s\n' "$state_green_ack_path"
        printf 'green_ack_sha256=%s\n' "$state_green_ack_sha256"
        printf 'green_ack_uid=%s\n' "$state_green_ack_uid"
        printf 'green_ack_gid=%s\n' "$state_green_ack_gid"
        printf 'green_ack_mode=%s\n' "$state_green_ack_mode"
        printf 'green_ack_size=%s\n' "$state_green_ack_size"
        printf 'blue_ack_path=%s\n' "$state_blue_ack_path"
        printf 'blue_ack_sha256=%s\n' "$state_blue_ack_sha256"
        printf 'blue_ack_uid=%s\n' "$state_blue_ack_uid"
        printf 'blue_ack_gid=%s\n' "$state_blue_ack_gid"
        printf 'blue_ack_mode=%s\n' "$state_blue_ack_mode"
        printf 'blue_ack_size=%s\n' "$state_blue_ack_size"
        printf 'green_ack_snapshot_path=%s\n' "$state_green_ack_snapshot_path"
        printf 'green_ack_snapshot_sha256=%s\n' "$state_green_ack_snapshot_sha256"
        printf 'green_ack_snapshot_uid=%s\n' "$state_green_ack_snapshot_uid"
        printf 'green_ack_snapshot_gid=%s\n' "$state_green_ack_snapshot_gid"
        printf 'green_ack_snapshot_mode=%s\n' "$state_green_ack_snapshot_mode"
        printf 'green_ack_snapshot_size=%s\n' "$state_green_ack_snapshot_size"
        printf 'blue_ack_snapshot_path=%s\n' "$state_blue_ack_snapshot_path"
        printf 'blue_ack_snapshot_sha256=%s\n' "$state_blue_ack_snapshot_sha256"
        printf 'blue_ack_snapshot_uid=%s\n' "$state_blue_ack_snapshot_uid"
        printf 'blue_ack_snapshot_gid=%s\n' "$state_blue_ack_snapshot_gid"
        printf 'blue_ack_snapshot_mode=%s\n' "$state_blue_ack_snapshot_mode"
        printf 'blue_ack_snapshot_size=%s\n' "$state_blue_ack_snapshot_size"
        printf 'green_probe_token_path=%s\n' "$state_green_probe_token_path"
        printf 'green_probe_token_sha256=%s\n' "$state_green_probe_token_sha256"
        printf 'green_probe_token_uid=%s\n' "$state_green_probe_token_uid"
        printf 'green_probe_token_gid=%s\n' "$state_green_probe_token_gid"
        printf 'green_probe_token_mode=%s\n' "$state_green_probe_token_mode"
        printf 'green_probe_token_size=%s\n' "$state_green_probe_token_size"
        printf 'blue_probe_token_path=%s\n' "$state_blue_probe_token_path"
        printf 'blue_probe_token_sha256=%s\n' "$state_blue_probe_token_sha256"
        printf 'blue_probe_token_uid=%s\n' "$state_blue_probe_token_uid"
        printf 'blue_probe_token_gid=%s\n' "$state_blue_probe_token_gid"
        printf 'blue_probe_token_mode=%s\n' "$state_blue_probe_token_mode"
        printf 'blue_probe_token_size=%s\n' "$state_blue_probe_token_size"
        printf 'ingress_controller_sha256=%s\n' "$state_ingress_controller_sha256"
        printf 'backup_attestation_configuration_sha256=%s\n' \
            "$state_backup_attestation_configuration_sha256"
        printf 'backup_attestation_sha256=%s\n' "$state_backup_attestation_sha256"
        printf 'backup_attestation_last_verified_unix=%s\n' \
            "$state_backup_attestation_last_verified_unix"
        printf 'route_target=%s\n' "$state_route_target"
        printf 'https_route_target=%s\n' "$state_https_route_target"
        printf 'drain_queue_sha256=%s\n' "$state_drain_queue_sha256"
        printf 'migration_status=%s\n' "$state_migration_status"
        printf 'migration_attempt=%s\n' "$state_migration_attempt"
        printf 'migration_attempt_state_sha256=%s\n' "$state_migration_attempt_state_sha256"
        printf 'migration_compatibility_sha256=%s\n' "$state_migration_compatibility_sha256"
        printf 'migration_manifest_sha256=%s\n' "$state_migration_manifest_sha256"
        printf 'migration_ledger_before_sha256=%s\n' "$state_migration_ledger_before_sha256"
        printf 'migration_relfilenode_before_sha256=%s\n' "$state_migration_relfilenode_before_sha256"
        printf 'migration_row_counts_before_sha256=%s\n' "$state_migration_row_counts_before_sha256"
        printf 'migration_schema_before_sha256=%s\n' "$state_migration_schema_before_sha256"
        printf 'migration_pending_sha256=%s\n' "$state_migration_pending_sha256"
        printf 'migration_expected_batch=%s\n' "$state_migration_expected_batch"
        printf 'live_database_identity_artifact_sha256=%s\n' "$state_live_database_identity_artifact_sha256"
        printf 'live_database_identity_sha256=%s\n' "$state_live_database_identity_sha256"
        printf 'live_database_system_identifier=%s\n' "$state_live_database_system_identifier"
        printf 'migration_verification_sha256=%s\n' "$state_migration_verification_sha256"
        printf 'migration_image_id=%s\n' "$state_migration_image_id"
        printf 'migration_image_digest=%s\n' "$state_migration_image_digest"
        printf 'migration_lock_timeout=%s\n' "$state_migration_lock_timeout"
        printf 'migration_statement_timeout=%s\n' "$state_migration_statement_timeout"
    } > "$state_candidate"

    validate_state_document "$state_candidate"
    mv -f "$state_candidate" "$state_file"
    sync
}

set_reverse_runtime_fence_generation_paths()
{
    reverse_generation=$1
    validate_positive_integer "$reverse_generation" 'reverse runtime-fence generation'
    reverse_generation_token=$(printf 'rev%02d' "$reverse_generation")
    reverse_runtime_fence_environment_file="$operation_directory/runtime-fence-${reverse_generation_token}.env"
    reverse_runtime_fence_https_ack_file="$operation_directory/runtime-fence-${reverse_generation_token}-https-ack"
    reverse_pool_plan_file="$operation_directory/${reverse_generation_token}-pool-plan.manifest"
    reverse_ingress_pool_file="$operation_directory/${reverse_generation_token}-ingress-pool.manifest"
    reverse_route_health_root_file="$operation_directory/${reverse_generation_token}-route-health-token.root"
    reverse_pool_ack_root_file="$operation_directory/${reverse_generation_token}-pool-ack.root"
    reverse_web_a_direct_probe_root_file="$operation_directory/${reverse_generation_token}-web-a-direct-probe.root"
    reverse_web_a_applied_ack_root_file="$operation_directory/${reverse_generation_token}-web-a-applied-ack.root"
    reverse_web_b_direct_probe_root_file="$operation_directory/${reverse_generation_token}-web-b-direct-probe.root"
    reverse_web_b_applied_ack_root_file="$operation_directory/${reverse_generation_token}-web-b-applied-ack.root"
}

load_operator_configuration_identity_from_state()
{
    state_release_id=$(state_value release_id)
    state_release_manifest_path=$(state_value release_manifest_path)
    state_release_manifest_sha256=$(state_value release_manifest_sha256)
    state_release_manifest_uid=$(state_value release_manifest_uid)
    state_release_manifest_gid=$(state_value release_manifest_gid)
    state_release_manifest_mode=$(state_value release_manifest_mode)
    state_release_manifest_size=$(state_value release_manifest_size)
    state_release_assets_sha256=$(state_value release_assets_sha256)
    state_operator_path=$(state_value operator_path)
    state_operator_sha256=$(state_value operator_sha256)
    state_operator_uid=$(state_value operator_uid)
    state_operator_gid=$(state_value operator_gid)
    state_operator_mode=$(state_value operator_mode)
    state_operator_size=$(state_value operator_size)
    state_operator_compose_path=$(state_value operator_compose_path)
    state_operator_compose_sha256=$(state_value operator_compose_sha256)
    state_operator_compose_uid=$(state_value operator_compose_uid)
    state_operator_compose_gid=$(state_value operator_compose_gid)
    state_operator_compose_mode=$(state_value operator_compose_mode)
    state_operator_compose_size=$(state_value operator_compose_size)
    state_rehearsal_compose_path=$(state_value rehearsal_compose_path)
    state_rehearsal_compose_sha256=$(state_value rehearsal_compose_sha256)
    state_rehearsal_compose_uid=$(state_value rehearsal_compose_uid)
    state_rehearsal_compose_gid=$(state_value rehearsal_compose_gid)
    state_rehearsal_compose_mode=$(state_value rehearsal_compose_mode)
    state_rehearsal_compose_size=$(state_value rehearsal_compose_size)
}

assert_persisted_restart_policy_state()
{
    persisted_restart_color=$1
    case "$persisted_restart_color" in
        green|green-web-a)
            persisted_restart_status=$state_green_restart_policy_status
            persisted_restart_path=$state_green_restart_policy_intent_path
            persisted_restart_sha256=$state_green_restart_policy_intent_sha256
            persisted_restart_uid=$state_green_restart_policy_intent_uid
            persisted_restart_gid=$state_green_restart_policy_intent_gid
            persisted_restart_mode=$state_green_restart_policy_intent_mode
            persisted_restart_size=$state_green_restart_policy_intent_size
            persisted_restart_expected_path=$green_restart_policy_intent_file
            ;;
        green-web-b)
            persisted_restart_status=$state_green_web_b_restart_policy_status
            persisted_restart_path=$state_green_web_b_restart_policy_intent_path
            persisted_restart_sha256=$state_green_web_b_restart_policy_intent_sha256
            persisted_restart_uid=$state_green_web_b_restart_policy_intent_uid
            persisted_restart_gid=$state_green_web_b_restart_policy_intent_gid
            persisted_restart_mode=$state_green_web_b_restart_policy_intent_mode
            persisted_restart_size=$state_green_web_b_restart_policy_intent_size
            persisted_restart_expected_path=$green_web_b_restart_policy_intent_file
            ;;
        blue|blue-web-a)
            persisted_restart_status=$state_replacement_blue_restart_policy_status
            persisted_restart_path=$state_replacement_blue_restart_policy_intent_path
            persisted_restart_sha256=$state_replacement_blue_restart_policy_intent_sha256
            persisted_restart_uid=$state_replacement_blue_restart_policy_intent_uid
            persisted_restart_gid=$state_replacement_blue_restart_policy_intent_gid
            persisted_restart_mode=$state_replacement_blue_restart_policy_intent_mode
            persisted_restart_size=$state_replacement_blue_restart_policy_intent_size
            persisted_restart_expected_path=$replacement_blue_restart_policy_intent_file
            ;;
        blue-web-b)
            persisted_restart_status=$state_replacement_blue_web_b_restart_policy_status
            persisted_restart_path=$state_replacement_blue_web_b_restart_policy_intent_path
            persisted_restart_sha256=$state_replacement_blue_web_b_restart_policy_intent_sha256
            persisted_restart_uid=$state_replacement_blue_web_b_restart_policy_intent_uid
            persisted_restart_gid=$state_replacement_blue_web_b_restart_policy_intent_gid
            persisted_restart_mode=$state_replacement_blue_web_b_restart_policy_intent_mode
            persisted_restart_size=$state_replacement_blue_web_b_restart_policy_intent_size
            persisted_restart_expected_path=$replacement_blue_web_b_restart_policy_intent_file
            ;;
    esac
    case "$persisted_restart_status" in
        unplanned)
            [ "$persisted_restart_path:$persisted_restart_sha256:$persisted_restart_uid:$persisted_restart_gid:$persisted_restart_mode:$persisted_restart_size" \
                = none:none:none:none:none:none ] \
                || fail "$persisted_restart_color unplanned restart policy contains intent evidence"
            ;;
        intent|updated|repinned)
            assert_file_identity "$persisted_restart_expected_path" "$persisted_restart_path" \
                "$persisted_restart_sha256" "$persisted_restart_uid" "$persisted_restart_gid" \
                "$persisted_restart_mode" "$persisted_restart_size" \
                "$persisted_restart_color persisted restart-policy intent"
            [ "$persisted_restart_uid" = "$immutable_uid" ] \
                && [ "$persisted_restart_gid" = "$immutable_gid" ] \
                && [ "$persisted_restart_mode" = 600 ] \
                || fail "$persisted_restart_color persisted restart-policy intent metadata changed"
            ;;
        *)
            fail "$persisted_restart_color persisted restart-policy status is invalid"
            ;;
    esac
}

assert_persisted_routed_recovery_state()
{
    case "$state_routed_recovery_status" in
        none)
            [ "$state_routed_recovery_generation:$state_routed_recovery_color:$state_routed_recovery_from_phase" \
                = 0:none:none ] \
                || fail 'empty routed-recovery state contains generation evidence'
            ;;
        intent|started|runtime-verified|verified)
            [ "$state_routed_recovery_generation" -gt 0 ] \
                && { [ "$state_routed_recovery_color" = green ] \
                    || [ "$state_routed_recovery_color" = blue ]; } \
                && [ "$state_routed_recovery_from_phase" != none ] \
                || fail 'routed-recovery generation state is inconsistent'
            ;;
        *)
            fail 'routed-recovery status is invalid'
            ;;
    esac
}

assert_persisted_operator_configuration_identity()
{
    validate_state_document
    [ "$(state_value version)" = "$STATE_VERSION" ] \
        || fail 'operation state version is unsupported'
    load_operator_configuration_identity_from_state
    assert_operator_configuration_identity
}

load_state()
{
    [ -f "$state_file" ] || fail "operation state does not exist: $operation_id"
    assert_persisted_operator_configuration_identity

    state_phase=$(state_value phase)
    [ "$(state_value operation_id)" = "$operation_id" ] || fail 'operation state identity does not match'
    [ "$(state_value writer_epoch)" = "$writer_epoch" ] || fail 'writer epoch identity changed'
    [ "$(state_value blue_writer_epoch)" = "$blue_writer_epoch" ] || fail 'blue writer epoch identity changed'
    [ "$(state_value green_writer_member)" = "$green_writer_member" ] \
        || fail 'green writer member identity changed'
    [ "$(state_value blue_writer_member)" = "$blue_writer_member" ] \
        || fail 'blue writer member identity changed'
    [ "$(state_value mutation_freeze_epoch)" = "$mutation_freeze_epoch" ] \
        || fail 'forward mutation-freeze epoch changed'
    [ "$(state_value reverse_mutation_freeze_epoch)" = "$reverse_mutation_freeze_epoch" ] \
        || fail 'reverse mutation-freeze epoch changed'
    [ "$(state_value green_web_epoch)" = "$green_web_epoch" ] || fail 'green web epoch identity changed'
    [ "$(state_value green_web_b_epoch)" = "$green_web_b_epoch" ] \
        || fail 'green web-b epoch identity changed'
    [ "$(state_value blue_web_epoch)" = "$blue_web_epoch" ] || fail 'blue web epoch identity changed'
    [ "$(state_value blue_web_b_epoch)" = "$blue_web_b_epoch" ] \
        || fail 'blue web-b epoch identity changed'
    [ "$(state_value green_web_a_route_drain_epoch)" = "$green_web_a_route_drain_epoch" ] \
        && [ "$(state_value green_web_b_route_drain_epoch)" = "$green_web_b_route_drain_epoch" ] \
        && [ "$(state_value blue_web_a_route_drain_epoch)" = "$blue_web_a_route_drain_epoch" ] \
        && [ "$(state_value blue_web_b_route_drain_epoch)" = "$blue_web_b_route_drain_epoch" ] \
        && [ "$(state_value green_web_a_route_identity)" = "$green_web_a_route_identity" ] \
        && [ "$(state_value green_web_b_route_identity)" = "$green_web_b_route_identity" ] \
        && [ "$(state_value blue_web_a_route_identity)" = "$blue_web_a_route_identity" ] \
        && [ "$(state_value blue_web_b_route_identity)" = "$blue_web_b_route_identity" ] \
        || fail 'pool route-drain epoch or route identity changed'
    state_blue_container=$(state_value blue_container)
    state_green_container=$(state_value green_container)
    state_green_web_b_container=$(state_value green_web_b_container)
    state_replacement_blue_container=$(state_value replacement_blue_container)
    state_replacement_blue_web_b_container=$(state_value replacement_blue_web_b_container)
    [ "$state_blue_container" = "$blue_container" ] || fail 'blue container identity changed'
    [ "$state_green_container" = "$green_container" ] || fail 'green container identity changed'
    [ "$state_green_web_b_container" = "$green_web_b_container" ] \
        || fail 'green web-b container identity changed'
    [ "$state_replacement_blue_container" = "$replacement_blue_container" ] \
        || fail 'replacement blue container identity changed'
    [ "$state_replacement_blue_web_b_container" = "$replacement_blue_web_b_container" ] \
        || fail 'replacement blue web-b container identity changed'
    [ "$(state_value green_state_volume)" = "$green_state_volume" ] \
        && [ "$(state_value green_web_b_private_volume)" = "$green_web_b_private_volume" ] \
        && [ "$(state_value blue_state_volume)" = "$blue_state_volume" ] \
        && [ "$(state_value blue_web_b_private_volume)" = "$blue_web_b_private_volume" ] \
        && [ "$(state_value coordination_volume)" = "$coordination_volume" ] \
        || fail 'pool authority volume identity changed'

    state_green_restart_policy_status=$(state_value green_restart_policy_status)
    state_green_restart_policy_intent_path=$(state_value green_restart_policy_intent_path)
    state_green_restart_policy_intent_sha256=$(state_value green_restart_policy_intent_sha256)
    state_green_restart_policy_intent_uid=$(state_value green_restart_policy_intent_uid)
    state_green_restart_policy_intent_gid=$(state_value green_restart_policy_intent_gid)
    state_green_restart_policy_intent_mode=$(state_value green_restart_policy_intent_mode)
    state_green_restart_policy_intent_size=$(state_value green_restart_policy_intent_size)
    state_green_web_b_restart_policy_status=$(state_value green_web_b_restart_policy_status)
    state_green_web_b_restart_policy_intent_path=$(state_value \
        green_web_b_restart_policy_intent_path)
    state_green_web_b_restart_policy_intent_sha256=$(state_value \
        green_web_b_restart_policy_intent_sha256)
    state_green_web_b_restart_policy_intent_uid=$(state_value \
        green_web_b_restart_policy_intent_uid)
    state_green_web_b_restart_policy_intent_gid=$(state_value \
        green_web_b_restart_policy_intent_gid)
    state_green_web_b_restart_policy_intent_mode=$(state_value \
        green_web_b_restart_policy_intent_mode)
    state_green_web_b_restart_policy_intent_size=$(state_value \
        green_web_b_restart_policy_intent_size)
    state_replacement_blue_restart_policy_status=$(state_value \
        replacement_blue_restart_policy_status)
    state_replacement_blue_restart_policy_intent_path=$(state_value \
        replacement_blue_restart_policy_intent_path)
    state_replacement_blue_restart_policy_intent_sha256=$(state_value \
        replacement_blue_restart_policy_intent_sha256)
    state_replacement_blue_restart_policy_intent_uid=$(state_value \
        replacement_blue_restart_policy_intent_uid)
    state_replacement_blue_restart_policy_intent_gid=$(state_value \
        replacement_blue_restart_policy_intent_gid)
    state_replacement_blue_restart_policy_intent_mode=$(state_value \
        replacement_blue_restart_policy_intent_mode)
    state_replacement_blue_restart_policy_intent_size=$(state_value \
        replacement_blue_restart_policy_intent_size)
    state_replacement_blue_web_b_restart_policy_status=$(state_value \
        replacement_blue_web_b_restart_policy_status)
    state_replacement_blue_web_b_restart_policy_intent_path=$(state_value \
        replacement_blue_web_b_restart_policy_intent_path)
    state_replacement_blue_web_b_restart_policy_intent_sha256=$(state_value \
        replacement_blue_web_b_restart_policy_intent_sha256)
    state_replacement_blue_web_b_restart_policy_intent_uid=$(state_value \
        replacement_blue_web_b_restart_policy_intent_uid)
    state_replacement_blue_web_b_restart_policy_intent_gid=$(state_value \
        replacement_blue_web_b_restart_policy_intent_gid)
    state_replacement_blue_web_b_restart_policy_intent_mode=$(state_value \
        replacement_blue_web_b_restart_policy_intent_mode)
    state_replacement_blue_web_b_restart_policy_intent_size=$(state_value \
        replacement_blue_web_b_restart_policy_intent_size)
    state_routed_recovery_generation=$(state_value routed_recovery_generation)
    state_routed_recovery_color=$(state_value routed_recovery_color)
    state_routed_recovery_status=$(state_value routed_recovery_status)
    state_routed_recovery_from_phase=$(state_value routed_recovery_from_phase)

    state_blue_id=$(state_value blue_id)
    state_blue_original_id=$(state_value blue_original_id)
    state_blue_rollback_replacement_id=$(state_value blue_rollback_replacement_id)
    state_blue_restore_status=$(state_value blue_restore_status)
    state_blue_image_id=$(state_value blue_image_id)
    state_blue_image_digest=$(state_value blue_image_digest)
    state_blue_image_reference=$(state_value blue_image_reference)
    state_blue_bridge_ip=$(state_value blue_bridge_ip)
    state_blue_compose_project=$(state_value blue_compose_project)
    state_blue_compose_service=$(state_value blue_compose_service)
    state_blue_compose_config_files=$(state_value blue_compose_config_files)
    case "$state_blue_restore_status" in
        none)
            [ "$state_blue_id" = "$state_blue_original_id" ] \
                && [ "$state_blue_rollback_replacement_id" = none ] \
                || fail 'blue identity is inconsistent before rollback restoration'
            ;;
        intent)
            [ "$state_blue_id" = "$state_blue_original_id" ] \
                && [ "$state_blue_rollback_replacement_id" = none ] \
                || fail 'blue restore intent changed the incumbent before exact adoption'
            ;;
        adopted)
            [ "$state_blue_id" = "$state_blue_rollback_replacement_id" ] \
                && [ "$state_blue_id" != "$state_blue_original_id" ] \
                || fail 'restored blue identity is outside its append-only rollback lineage'
            ;;
        *)
            fail 'blue restore status is invalid'
            ;;
    esac
    state_green_id=$(state_value green_id)
    state_green_web_b_id=$(state_value green_web_b_id)
    state_green_image_id=$(state_value green_image_id)
    state_green_web_b_image_id=$(state_value green_web_b_image_id)
    state_green_plan_image_reference=$(state_value green_plan_image_reference)
    state_green_plan_image_id=$(state_value green_plan_image_id)
    state_green_plan_network_id=$(state_value green_plan_network_id)
    state_green_container_runtime_sha256=$(state_value green_container_runtime_sha256)
    state_green_web_b_container_runtime_sha256=$(state_value \
        green_web_b_container_runtime_sha256)
    state_replacement_blue_plan_image_reference=$(state_value replacement_blue_plan_image_reference)
    state_replacement_blue_plan_image_id=$(state_value replacement_blue_plan_image_id)
    state_replacement_blue_plan_network_id=$(state_value replacement_blue_plan_network_id)
    state_replacement_blue_container_runtime_sha256=$(state_value \
        replacement_blue_container_runtime_sha256)
    state_replacement_blue_web_b_id=$(state_value replacement_blue_web_b_id)
    state_replacement_blue_web_b_image_id=$(state_value replacement_blue_web_b_image_id)
    state_replacement_blue_web_b_container_runtime_sha256=$(state_value \
        replacement_blue_web_b_container_runtime_sha256)
    state_forward_pool_plan_path=$(state_value forward_pool_plan_path)
    state_forward_pool_plan_sha256=$(state_value forward_pool_plan_sha256)
    state_forward_pool_plan_uid=$(state_value forward_pool_plan_uid)
    state_forward_pool_plan_gid=$(state_value forward_pool_plan_gid)
    state_forward_pool_plan_mode=$(state_value forward_pool_plan_mode)
    state_forward_pool_plan_size=$(state_value forward_pool_plan_size)
    state_forward_ingress_pool_path=$(state_value forward_ingress_pool_path)
    state_forward_ingress_pool_sha256=$(state_value forward_ingress_pool_sha256)
    state_forward_ingress_pool_uid=$(state_value forward_ingress_pool_uid)
    state_forward_ingress_pool_gid=$(state_value forward_ingress_pool_gid)
    state_forward_ingress_pool_mode=$(state_value forward_ingress_pool_mode)
    state_forward_ingress_pool_size=$(state_value forward_ingress_pool_size)
    state_reverse_pool_plan_path=$(state_value reverse_pool_plan_path)
    state_reverse_pool_plan_sha256=$(state_value reverse_pool_plan_sha256)
    state_reverse_pool_plan_uid=$(state_value reverse_pool_plan_uid)
    state_reverse_pool_plan_gid=$(state_value reverse_pool_plan_gid)
    state_reverse_pool_plan_mode=$(state_value reverse_pool_plan_mode)
    state_reverse_pool_plan_size=$(state_value reverse_pool_plan_size)
    state_reverse_ingress_pool_path=$(state_value reverse_ingress_pool_path)
    state_reverse_ingress_pool_sha256=$(state_value reverse_ingress_pool_sha256)
    state_reverse_ingress_pool_uid=$(state_value reverse_ingress_pool_uid)
    state_reverse_ingress_pool_gid=$(state_value reverse_ingress_pool_gid)
    state_reverse_ingress_pool_mode=$(state_value reverse_ingress_pool_mode)
    state_reverse_ingress_pool_size=$(state_value reverse_ingress_pool_size)
    assert_persisted_restart_policy_state green-web-a
    assert_persisted_restart_policy_state green-web-b
    assert_persisted_restart_policy_state blue-web-a
    assert_persisted_restart_policy_state blue-web-b
    assert_persisted_routed_recovery_state
    state_runtime_fence_operation_id=$(state_value runtime_fence_operation_id)
    state_runtime_fence_provisioner_path=$(state_value runtime_fence_provisioner_path)
    state_runtime_fence_provisioner_sha256=$(state_value runtime_fence_provisioner_sha256)
    state_runtime_fence_provisioner_uid=$(state_value runtime_fence_provisioner_uid)
    state_runtime_fence_provisioner_gid=$(state_value runtime_fence_provisioner_gid)
    state_runtime_fence_provisioner_mode=$(state_value runtime_fence_provisioner_mode)
    state_runtime_fence_provisioner_size=$(state_value runtime_fence_provisioner_size)
    state_runtime_fence_base_config_path=$(state_value runtime_fence_base_config_path)
    state_runtime_fence_base_config_sha256=$(state_value runtime_fence_base_config_sha256)
    state_runtime_fence_base_config_uid=$(state_value runtime_fence_base_config_uid)
    state_runtime_fence_base_config_gid=$(state_value runtime_fence_base_config_gid)
    state_runtime_fence_base_config_mode=$(state_value runtime_fence_base_config_mode)
    state_runtime_fence_base_config_size=$(state_value runtime_fence_base_config_size)
    state_runtime_fence_environment_path=$(state_value runtime_fence_environment_path)
    state_runtime_fence_environment_sha256=$(state_value runtime_fence_environment_sha256)
    state_runtime_fence_environment_uid=$(state_value runtime_fence_environment_uid)
    state_runtime_fence_environment_gid=$(state_value runtime_fence_environment_gid)
    state_runtime_fence_environment_mode=$(state_value runtime_fence_environment_mode)
    state_runtime_fence_environment_size=$(state_value runtime_fence_environment_size)
    state_runtime_fence_https_ack_path=$(state_value runtime_fence_https_ack_path)
    state_runtime_fence_https_ack_sha256=$(state_value runtime_fence_https_ack_sha256)
    state_runtime_fence_https_ack_uid=$(state_value runtime_fence_https_ack_uid)
    state_runtime_fence_https_ack_gid=$(state_value runtime_fence_https_ack_gid)
    state_runtime_fence_https_ack_mode=$(state_value runtime_fence_https_ack_mode)
    state_runtime_fence_https_ack_size=$(state_value runtime_fence_https_ack_size)
    state_runtime_fence_provider_header_source_path=$(state_value \
        runtime_fence_provider_header_source_path)
    state_runtime_fence_provider_header_source_sha256=$(state_value \
        runtime_fence_provider_header_source_sha256)
    state_runtime_fence_provider_header_source_uid=$(state_value \
        runtime_fence_provider_header_source_uid)
    state_runtime_fence_provider_header_source_gid=$(state_value \
        runtime_fence_provider_header_source_gid)
    state_runtime_fence_provider_header_source_mode=$(state_value \
        runtime_fence_provider_header_source_mode)
    state_runtime_fence_provider_header_source_size=$(state_value \
        runtime_fence_provider_header_source_size)
    state_runtime_fence_provider_header_copy_path=$(state_value \
        runtime_fence_provider_header_copy_path)
    state_runtime_fence_provider_header_copy_sha256=$(state_value \
        runtime_fence_provider_header_copy_sha256)
    state_runtime_fence_provider_header_copy_uid=$(state_value \
        runtime_fence_provider_header_copy_uid)
    state_runtime_fence_provider_header_copy_gid=$(state_value \
        runtime_fence_provider_header_copy_gid)
    state_runtime_fence_provider_header_copy_mode=$(state_value \
        runtime_fence_provider_header_copy_mode)
    state_runtime_fence_provider_header_copy_size=$(state_value \
        runtime_fence_provider_header_copy_size)
    state_reverse_fence_generation=$(state_value reverse_fence_generation)
    state_reverse_fence_operation_id=$(state_value reverse_fence_operation_id)
    state_reverse_fence_provisioner_path=$(state_value reverse_fence_provisioner_path)
    state_reverse_fence_provisioner_sha256=$(state_value reverse_fence_provisioner_sha256)
    state_reverse_fence_environment_path=$(state_value reverse_fence_environment_path)
    state_reverse_fence_environment_uid=$(state_value reverse_fence_environment_uid)
    state_reverse_fence_environment_gid=$(state_value reverse_fence_environment_gid)
    state_reverse_fence_environment_mode=$(state_value reverse_fence_environment_mode)
    state_reverse_fence_environment_size=$(state_value reverse_fence_environment_size)
    state_reverse_fence_https_ack_path=$(state_value reverse_fence_https_ack_path)
    state_reverse_fence_https_ack_sha256=$(state_value reverse_fence_https_ack_sha256)
    state_reverse_fence_https_ack_uid=$(state_value reverse_fence_https_ack_uid)
    state_reverse_fence_https_ack_gid=$(state_value reverse_fence_https_ack_gid)
    state_reverse_fence_https_ack_mode=$(state_value reverse_fence_https_ack_mode)
    state_reverse_fence_https_ack_size=$(state_value reverse_fence_https_ack_size)
    state_reverse_fence_provider_header_path=$(state_value reverse_fence_provider_header_path)
    state_reverse_fence_provider_header_sha256=$(state_value reverse_fence_provider_header_sha256)
    state_reverse_fence_provider_header_uid=$(state_value reverse_fence_provider_header_uid)
    state_reverse_fence_provider_header_gid=$(state_value reverse_fence_provider_header_gid)
    state_reverse_fence_provider_header_mode=$(state_value reverse_fence_provider_header_mode)
    state_reverse_fence_provider_header_size=$(state_value reverse_fence_provider_header_size)
    state_green_ack_path=$(state_value green_ack_path)
    state_green_ack_sha256=$(state_value green_ack_sha256)
    state_green_ack_uid=$(state_value green_ack_uid)
    state_green_ack_gid=$(state_value green_ack_gid)
    state_green_ack_mode=$(state_value green_ack_mode)
    state_green_ack_size=$(state_value green_ack_size)
    state_blue_ack_path=$(state_value blue_ack_path)
    state_blue_ack_sha256=$(state_value blue_ack_sha256)
    state_blue_ack_uid=$(state_value blue_ack_uid)
    state_blue_ack_gid=$(state_value blue_ack_gid)
    state_blue_ack_mode=$(state_value blue_ack_mode)
    state_blue_ack_size=$(state_value blue_ack_size)
    state_green_ack_snapshot_path=$(state_value green_ack_snapshot_path)
    state_green_ack_snapshot_sha256=$(state_value green_ack_snapshot_sha256)
    state_green_ack_snapshot_uid=$(state_value green_ack_snapshot_uid)
    state_green_ack_snapshot_gid=$(state_value green_ack_snapshot_gid)
    state_green_ack_snapshot_mode=$(state_value green_ack_snapshot_mode)
    state_green_ack_snapshot_size=$(state_value green_ack_snapshot_size)
    state_blue_ack_snapshot_path=$(state_value blue_ack_snapshot_path)
    state_blue_ack_snapshot_sha256=$(state_value blue_ack_snapshot_sha256)
    state_blue_ack_snapshot_uid=$(state_value blue_ack_snapshot_uid)
    state_blue_ack_snapshot_gid=$(state_value blue_ack_snapshot_gid)
    state_blue_ack_snapshot_mode=$(state_value blue_ack_snapshot_mode)
    state_blue_ack_snapshot_size=$(state_value blue_ack_snapshot_size)
    state_recovery_abort_generation=$(state_value recovery_abort_generation)
    state_recovery_abort_from_phase=$(state_value recovery_abort_from_phase)
    [ "$state_recovery_abort_generation" = 0 ] || [ "$state_recovery_abort_generation" = 1 ] \
        || fail 'recovery-abort generation is invalid'
    state_reverse_fence_environment_sha256=$(state_value reverse_fence_environment_sha256)
    state_replacement_blue_id=$(state_value replacement_blue_id)
    state_replacement_blue_image_id=$(state_value replacement_blue_image_id)
    state_proxy_id=$(state_value proxy_id)
    state_proxy_image_id=$(state_value proxy_image_id)
    state_source_compose_checksum=$(state_value source_compose_checksum)
    state_source_runtime_sha256=$(state_value source_runtime_sha256)
    state_source_rendered_sha256=$(state_value source_rendered_sha256)
    [ "$state_green_plan_image_reference" = "$green_image" ] \
        && [ "$state_green_plan_image_id" = "$(image_id "$green_image")" ] \
        && [ "$state_green_plan_network_id" = "$(network_id "$control_plane_network")" ] \
        || fail 'green immutable pre-start plan changed during this operation'
    [ "$state_replacement_blue_plan_image_reference" = "$blue_image" ] \
        && [ "$state_replacement_blue_plan_image_id" = "$(image_id "$blue_image")" ] \
        && [ "$state_replacement_blue_plan_network_id" = "$(network_id "$control_plane_network")" ] \
        || fail 'replacement blue immutable pre-start plan changed during this operation'
    [ "$state_runtime_fence_operation_id" = "${operation_id}.fwd" ] \
        || fail 'runtime fence operation identity changed during this operation'
    assert_runtime_fence_artifacts
    case "$state_reverse_fence_generation" in
        none)
            [ "$state_reverse_fence_operation_id" = none ] \
                && [ "$state_reverse_fence_provisioner_path" = none ] \
                && [ "$state_reverse_fence_provisioner_sha256" = none ] \
                && [ "$state_reverse_fence_environment_path" = none ] \
                && [ "$state_reverse_fence_environment_sha256" = none ] \
                && [ "$state_reverse_fence_environment_uid" = none ] \
                && [ "$state_reverse_fence_environment_gid" = none ] \
                && [ "$state_reverse_fence_environment_mode" = none ] \
                && [ "$state_reverse_fence_environment_size" = none ] \
                && [ "$state_reverse_fence_https_ack_path" = none ] \
                && [ "$state_reverse_fence_https_ack_sha256" = none ] \
                && [ "$state_reverse_fence_https_ack_uid" = none ] \
                && [ "$state_reverse_fence_https_ack_gid" = none ] \
                && [ "$state_reverse_fence_https_ack_mode" = none ] \
                && [ "$state_reverse_fence_https_ack_size" = none ] \
                && [ "$state_reverse_fence_provider_header_path" = none ] \
                && [ "$state_reverse_fence_provider_header_sha256" = none ] \
                && [ "$state_reverse_fence_provider_header_uid" = none ] \
                && [ "$state_reverse_fence_provider_header_gid" = none ] \
                && [ "$state_reverse_fence_provider_header_mode" = none ] \
                && [ "$state_reverse_fence_provider_header_size" = none ] \
                || fail 'uninitialized reverse runtime-fence state is inconsistent'
            ;;
        *)
            validate_positive_integer "$state_reverse_fence_generation" \
                'persisted reverse runtime-fence generation'
            set_reverse_runtime_fence_generation_paths "$state_reverse_fence_generation"
            [ "$state_reverse_fence_operation_id" = "${operation_id}.${reverse_generation_token}" ] \
                && [ "$state_reverse_fence_provisioner_path" = "$runtime_fence_provisioner" ] \
                && [ "$state_reverse_fence_provisioner_sha256" = "$runtime_fence_provisioner_sha256" ] \
                && [ "$state_reverse_fence_environment_path" = "$reverse_runtime_fence_environment_file" ] \
                && [ "$state_reverse_fence_https_ack_path" = "$reverse_runtime_fence_https_ack_file" ] \
                || fail 'reverse runtime-fence operation identity changed during this operation'
            if [ "$state_runtime_fence_provider_header_copy_path" != none ]; then
                [ "$state_reverse_fence_provider_header_path" = \
                    "$state_runtime_fence_provider_header_copy_path" ] \
                    && [ "$state_reverse_fence_provider_header_sha256" = \
                        "$state_runtime_fence_provider_header_copy_sha256" ] \
                    && [ "$state_reverse_fence_provider_header_uid" = \
                        "$state_runtime_fence_provider_header_copy_uid" ] \
                    && [ "$state_reverse_fence_provider_header_gid" = \
                        "$state_runtime_fence_provider_header_copy_gid" ] \
                    && [ "$state_reverse_fence_provider_header_mode" = \
                        "$state_runtime_fence_provider_header_copy_mode" ] \
                    && [ "$state_reverse_fence_provider_header_size" = \
                        "$state_runtime_fence_provider_header_copy_size" ] \
                    || fail 'reverse runtime-fence provider-header path changed during this operation'
            else
                [ "$state_reverse_fence_provider_header_path" = none ] \
                    && [ "$state_reverse_fence_provider_header_sha256" = none ] \
                    && [ "$state_reverse_fence_provider_header_uid" = none ] \
                    && [ "$state_reverse_fence_provider_header_gid" = none ] \
                    && [ "$state_reverse_fence_provider_header_mode" = none ] \
                    && [ "$state_reverse_fence_provider_header_size" = none ] \
                    || fail 'reverse runtime-fence unexpectedly persisted a provider-header copy'
            fi
            case "$state_reverse_fence_environment_sha256" in
                none)
                    [ "$state_phase" = reverse-fence-artifacts-preparing ] \
                        && [ "$state_reverse_fence_https_ack_sha256" = none ] \
                        && [ "$state_reverse_fence_https_ack_uid" = none ] \
                        && [ "$state_reverse_fence_https_ack_gid" = none ] \
                        && [ "$state_reverse_fence_https_ack_mode" = none ] \
                        && [ "$state_reverse_fence_https_ack_size" = none ] \
                        && [ "$state_reverse_fence_environment_uid" = none ] \
                        && [ "$state_reverse_fence_environment_gid" = none ] \
                        && [ "$state_reverse_fence_environment_mode" = none ] \
                        && [ "$state_reverse_fence_environment_size" = none ] \
                        || fail 'reserved reverse runtime-fence generation has inconsistent artifacts'
                    ;;
                *)
                    assert_reverse_runtime_fence_artifacts
                    ;;
            esac
            ;;
    esac
    state_source_base_path=$(state_value source_base_path)
    state_source_base_sha256=$(state_value source_base_sha256)
    state_source_prod_path=$(state_value source_prod_path)
    state_source_prod_sha256=$(state_value source_prod_sha256)
    state_source_custom_path=$(state_value source_custom_path)
    state_source_custom_sha256=$(state_value source_custom_sha256)
    state_source_postgres_path=$(state_value source_postgres_path)
    state_source_postgres_sha256=$(state_value source_postgres_sha256)
    state_source_env_path=$(state_value source_env_path)
    state_source_env_sha256=$(state_value source_env_sha256)
    state_source_env_uid=$(state_value source_env_uid)
    state_source_env_gid=$(state_value source_env_gid)
    state_source_env_mode=$(state_value source_env_mode)
    state_source_env_size=$(state_value source_env_size)
    [ "$(state_value runtime_env_uid)" = "$runtime_env_uid" ] \
        && [ "$(state_value runtime_env_gid)" = "$runtime_env_gid" ] \
        || fail 'runtime environment artifact ownership changed'
    state_green_runtime_env_path=$(state_value green_runtime_env_path)
    state_green_runtime_env_sha256=$(state_value green_runtime_env_sha256)
    state_blue_runtime_env_path=$(state_value blue_runtime_env_path)
    state_blue_runtime_env_sha256=$(state_value blue_runtime_env_sha256)
    [ "$state_green_runtime_env_path" = "$green_runtime_env_file" ] \
        && [ "$state_blue_runtime_env_path" = "$blue_runtime_env_file" ] \
        || fail 'runtime environment artifact paths changed'
    state_green_probe_token_path=$(state_value green_probe_token_path)
    state_green_probe_token_sha256=$(state_value green_probe_token_sha256)
    state_green_probe_token_uid=$(state_value green_probe_token_uid)
    state_green_probe_token_gid=$(state_value green_probe_token_gid)
    state_green_probe_token_mode=$(state_value green_probe_token_mode)
    state_green_probe_token_size=$(state_value green_probe_token_size)
    state_blue_probe_token_path=$(state_value blue_probe_token_path)
    state_blue_probe_token_sha256=$(state_value blue_probe_token_sha256)
    state_blue_probe_token_uid=$(state_value blue_probe_token_uid)
    state_blue_probe_token_gid=$(state_value blue_probe_token_gid)
    state_blue_probe_token_mode=$(state_value blue_probe_token_mode)
    state_blue_probe_token_size=$(state_value blue_probe_token_size)
    state_ingress_controller_sha256=$(state_value ingress_controller_sha256)
    state_backup_attestation_configuration_sha256=$(state_value \
        backup_attestation_configuration_sha256)
    state_backup_attestation_sha256=$(state_value backup_attestation_sha256)
    state_backup_attestation_last_verified_unix=$(state_value backup_attestation_last_verified_unix)
    if ! printf '%s' "$state_backup_attestation_configuration_sha256" | grep -Eq '^[0-9a-f]{64}$' \
        || ! printf '%s' "$state_backup_attestation_sha256" | grep -Eq '^[0-9a-f]{64}$' \
        || ! printf '%s' "$state_backup_attestation_last_verified_unix" | grep -Eq '^[1-9][0-9]*$'; then
        fail 'backup/restore attestation verification state is malformed'
    fi
    state_route_target=$(state_value route_target)
    state_https_route_target=$(state_value https_route_target)
    state_drain_queue_sha256=$(state_value drain_queue_sha256)
    state_migration_status=$(state_value migration_status)
    state_migration_attempt=$(state_value migration_attempt)
    state_migration_attempt_state_sha256=$(state_value migration_attempt_state_sha256)
    state_migration_compatibility_sha256=$(state_value migration_compatibility_sha256)
    state_migration_manifest_sha256=$(state_value migration_manifest_sha256)
    state_migration_ledger_before_sha256=$(state_value migration_ledger_before_sha256)
    state_migration_relfilenode_before_sha256=$(state_value migration_relfilenode_before_sha256)
    state_migration_row_counts_before_sha256=$(state_value migration_row_counts_before_sha256)
    state_migration_schema_before_sha256=$(state_value migration_schema_before_sha256)
    state_migration_pending_sha256=$(state_value migration_pending_sha256)
    state_migration_expected_batch=$(state_value migration_expected_batch)
    state_live_database_identity_artifact_sha256=$(state_value live_database_identity_artifact_sha256)
    state_live_database_identity_sha256=$(state_value live_database_identity_sha256)
    state_live_database_system_identifier=$(state_value live_database_system_identifier)
    state_migration_verification_sha256=$(state_value migration_verification_sha256)
    state_migration_image_id=$(state_value migration_image_id)
    state_migration_image_digest=$(state_value migration_image_digest)
    state_migration_lock_timeout=$(state_value migration_lock_timeout)
    state_migration_statement_timeout=$(state_value migration_statement_timeout)
}

source_compose_without_proxy_enrollment_override()
{
    if [ "$(path_presence "$source_compose_custom")" = present ]; then
        if [ "$(path_presence "$source_compose_postgres")" = present ]; then
            APP_PORT="$app_port" docker compose --ansi never --project-name "$source_compose_project" \
                --env-file "$source_env_file" \
                --file "$source_compose_base" --file "$source_compose_prod" \
                --file "$source_compose_custom" --file "$source_compose_postgres" "$@"
        else
            APP_PORT="$app_port" docker compose --ansi never --project-name "$source_compose_project" \
                --env-file "$source_env_file" \
                --file "$source_compose_base" --file "$source_compose_prod" \
                --file "$source_compose_custom" "$@"
        fi
    elif [ "$(path_presence "$source_compose_postgres")" = present ]; then
        APP_PORT="$app_port" docker compose --ansi never --project-name "$source_compose_project" \
            --env-file "$source_env_file" \
            --file "$source_compose_base" --file "$source_compose_prod" \
            --file "$source_compose_postgres" "$@"
    else
        APP_PORT="$app_port" docker compose --ansi never --project-name "$source_compose_project" \
            --env-file "$source_env_file" \
            --file "$source_compose_base" --file "$source_compose_prod" "$@"
    fi
}

source_compose_with_proxy_enrollment_override()
{
    [ "$(path_presence "$proxy_enrollment_compose_override")" = present ] \
        || fail 'managed proxy-enrollment compose override is absent while enrolled'

    if [ "$(path_presence "$source_compose_custom")" = present ]; then
        if [ "$(path_presence "$source_compose_postgres")" = present ]; then
            APP_PORT="$app_port" docker compose --ansi never --project-name "$source_compose_project" \
                --env-file "$source_env_file" \
                --file "$source_compose_base" --file "$source_compose_prod" \
                --file "$source_compose_custom" --file "$source_compose_postgres" \
                --file "$proxy_enrollment_compose_override" "$@"
        else
            APP_PORT="$app_port" docker compose --ansi never --project-name "$source_compose_project" \
                --env-file "$source_env_file" \
                --file "$source_compose_base" --file "$source_compose_prod" \
                --file "$source_compose_custom" --file "$proxy_enrollment_compose_override" "$@"
        fi
    elif [ "$(path_presence "$source_compose_postgres")" = present ]; then
        APP_PORT="$app_port" docker compose --ansi never --project-name "$source_compose_project" \
            --env-file "$source_env_file" \
            --file "$source_compose_base" --file "$source_compose_prod" \
            --file "$source_compose_postgres" --file "$proxy_enrollment_compose_override" "$@"
    else
        APP_PORT="$app_port" docker compose --ansi never --project-name "$source_compose_project" \
            --env-file "$source_env_file" \
            --file "$source_compose_base" --file "$source_compose_prod" \
            --file "$proxy_enrollment_compose_override" "$@"
    fi
}

source_compose()
{
    case "$proxy_enrollment_override_active" in
        0) source_compose_without_proxy_enrollment_override "$@" ;;
        1) source_compose_with_proxy_enrollment_override "$@" ;;
        *) fail 'managed proxy-enrollment compose override mode is invalid' ;;
    esac
}

ordered_source_compose_paths_without_proxy_enrollment_override()
{
    printf '%s,%s' "$source_compose_base" "$source_compose_prod"
    if [ "$(path_presence "$source_compose_custom")" = present ]; then
        printf ',%s' "$source_compose_custom"
    fi
    if [ "$(path_presence "$source_compose_postgres")" = present ]; then
        printf ',%s' "$source_compose_postgres"
    fi
    printf '\n'
}

ordered_source_compose_paths()
{
    ordered_source_compose_paths_without_proxy_enrollment_override | tr -d '\n'
    if [ "$proxy_enrollment_override_active" = 1 ]; then
        [ "$(path_presence "$proxy_enrollment_compose_override")" = present ] \
            || fail 'managed proxy-enrollment compose override is absent while enrolled'
        printf ',%s' "$proxy_enrollment_compose_override"
    fi
    printf '\n'
}

source_service_image_reference()
{
    source_compose config --format json \
        | jq --exit-status --raw-output --arg service "$source_compose_service" \
            '.services[$service].image | select(type == "string" and length > 0)'
}

runtime_fence_container_runtime_sha256()
{
    runtime_container=$1
    validate_identifier "$runtime_container" 'runtime-fence container identity'
    assert_non_symlink_regular_file "$runtime_fence_provisioner" \
        CONTROL_PLANE_RUNTIME_FENCE_PROVISIONER
    [ "$(sha256_file "$runtime_fence_provisioner")" = "$runtime_fence_provisioner_sha256" ] \
        || fail 'runtime-fence provisioner differs from its configured pin'
    if [ "${state_runtime_fence_provisioner_path:-none}" != none ]; then
        assert_file_identity "$runtime_fence_provisioner" \
            "$state_runtime_fence_provisioner_path" "$state_runtime_fence_provisioner_sha256" \
            "$state_runtime_fence_provisioner_uid" "$state_runtime_fence_provisioner_gid" \
            "$state_runtime_fence_provisioner_mode" "$state_runtime_fence_provisioner_size" \
            'runtime-fence provisioner'
    fi
    assert_release_asset_identity runtime-fence-provisioner
    observed_runtime_sha256=$("$runtime_fence_provisioner" \
        container-runtime-sha256 "$runtime_container") \
        || fail "runtime-fence v4 identity inspection failed: $runtime_container"
    validate_sha256 "$observed_runtime_sha256" \
        "runtime-fence v4 identity for $runtime_container"
    printf '%s\n' "$observed_runtime_sha256"
}

network_id()
{
    docker network inspect --format '{{.Id}}' "$1"
}

pool_member_network_json()
{
    docker inspect "$1" | jq -S -c '
        [.[0].NetworkSettings.Networks
            | to_entries[]
            | {
                name: .key,
                network_id: .value.NetworkID,
                endpoint_id: (.value.EndpointID // ""),
                ipv4_address: (.value.IPAddress // ""),
                ipv4_gateway: (.value.Gateway // ""),
                ipv6_address: (.value.GlobalIPv6Address // ""),
                ipv6_gateway: (.value.IPv6Gateway // ""),
                mac_address: (.value.MacAddress // "")
            }]
        | sort_by(.network_id, .name)
    '
}

pool_member_network_sha256()
{
    pool_member_network_json "$1" | sha256sum | awk '{print $1}'
}

pool_member_bindings_sha256()
{
    docker inspect "$1" | jq -S -c '.[0] | {
        configured_bindings: (.HostConfig.PortBindings // {}),
        published_bindings: (.NetworkSettings.Ports // {})
    }' | sha256sum | awk '{print $1}'
}

pool_member_address()
{
    docker inspect "$1" | jq --exit-status --raw-output --arg network "$control_plane_network" '
        .[0].NetworkSettings.Networks[$network]
        | select(type == "object")
        | [.IPAddress, .GlobalIPv6Address]
        | map(select(type == "string" and length > 0))
        | first
    '
}

control_plane_proxy_peer_addresses()
{
    require_command docker
    require_command jq
    require_command paste
    require_command sort
    observed_network_id=$(docker network inspect "$control_plane_network" | jq --exit-status --raw-output '
        if length != 1
            or (.[0].Id | type) != "string"
            or (.[0].Id | test("^[a-f0-9]{64}$") | not)
        then
            error("control-plane network identity is invalid")
        else
            .[0].Id
        end
    ') || fail 'control-plane network identity could not be attested'
    gateway_addresses=$(docker network inspect "$control_plane_network" | jq --exit-status --raw-output '
        if length != 1 then
            error("control-plane network inspection is ambiguous")
        else
            [.[0].IPAM.Config[]?.Gateway? | select(. != null)] as $gateways
            | if ($gateways | length) == 0
                or ($gateways | any(type != "string" or test("^[0-9A-Fa-f:.]+$") | not))
            then
                error("control-plane network has no safe gateway address")
            else
                $gateways[]
            end
        end
    ') || fail 'control-plane proxy gateway addresses could not be attested'
    proxy_addresses=$(docker inspect "$proxy_container" | jq --exit-status --raw-output \
        --arg network "$control_plane_network" --arg network_id "$observed_network_id" '
            if length != 1 then
                error("proxy inspection is ambiguous")
            else
                .[0].NetworkSettings.Networks[$network] as $endpoint
                | if ($endpoint | type) != "object"
                    or $endpoint.NetworkID != $network_id
                then
                    error("proxy is not attached to the attested control-plane network")
                else
                    [$endpoint.IPAddress, $endpoint.GlobalIPv6Address]
                    | map(select(type == "string" and length > 0)) as $addresses
                    | if ($addresses | length) == 0
                        or ($addresses | any(test("^[0-9A-Fa-f:.]+$") | not))
                    then
                        error("proxy has no safe peer address")
                    else
                        $addresses[]
                    end
                end
            end
        ') || fail 'control-plane proxy peer addresses could not be attested'
    control_plane_proxy_addresses=$(printf '%s\n%s\n' "$gateway_addresses" "$proxy_addresses" \
        | LC_ALL=C sort -u | paste -sd, -)
    [ -n "$control_plane_proxy_addresses" ] \
        || fail 'control-plane proxy address allowlist is empty'
    printf '%s\n' "$control_plane_proxy_addresses"
}

assert_control_plane_proxy_peer_addresses()
{
    [ "$control_plane_trusted_proxy_addresses" = "$(control_plane_proxy_peer_addresses)" ] \
        || fail 'control-plane proxy peer address allowlist changed during this operation'
}

install_atomic_operation_copy()
{
    immutable_source=$1
    immutable_target=$2
    immutable_mode=$3
    immutable_name=$4
    immutable_candidate="${immutable_target}.new"
    immutable_source_sha256=$(sha256_file "$immutable_source")
    immutable_source_uid=$(file_uid "$immutable_source")
    immutable_source_gid=$(file_gid "$immutable_source")
    immutable_source_mode=$(file_mode "$immutable_source")
    immutable_source_size=$(file_size "$immutable_source")

    [ ! -e "$immutable_target" ] && [ ! -L "$immutable_target" ] \
        && [ ! -e "$immutable_candidate" ] && [ ! -L "$immutable_candidate" ] \
        || fail "$immutable_name already exists before atomic creation"
    (umask 077; cp -- "$immutable_source" "$immutable_candidate")
    chmod "$immutable_mode" "$immutable_candidate"
    [ "$(sha256_file "$immutable_candidate")" = "$immutable_source_sha256" ] \
        && [ "$(file_size "$immutable_candidate")" = "$immutable_source_size" ] \
        || fail "$immutable_name candidate differs from its pinned source"
    [ "$(sha256_file "$immutable_source")" = "$immutable_source_sha256" ] \
        && [ "$(file_uid "$immutable_source")" = "$immutable_source_uid" ] \
        && [ "$(file_gid "$immutable_source")" = "$immutable_source_gid" ] \
        && [ "$(file_mode "$immutable_source")" = "$immutable_source_mode" ] \
        && [ "$(file_size "$immutable_source")" = "$immutable_source_size" ] \
        || fail "$immutable_name source changed during its atomic snapshot"
    mv "$immutable_candidate" "$immutable_target"
    sync
}

render_runtime_fence_base_configuration()
{
    base_configuration_destination=$1
    base_provider_header_path=
    if [ "$state_runtime_fence_provider_header_copy_path" != none ]; then
        base_provider_header_path=$state_runtime_fence_provider_header_copy_path
    fi

    {
        printf 'CONTROL_PLANE_RUNTIME_PROXY_CONTAINER=%s\n' "$proxy_container"
        printf 'CONTROL_PLANE_RUNTIME_MANAGEMENT_ENDPOINTS=%s\n' "$runtime_fence_management_endpoints"
        printf 'CONTROL_PLANE_RUNTIME_ADDITIONAL_NETWORK_IDS=%s\n' "$runtime_fence_additional_network_ids"
        printf 'CONTROL_PLANE_RUNTIME_SELF_SSH_TARGET=%s\n' "$runtime_fence_self_ssh_target"
        printf 'CONTROL_PLANE_RUNTIME_PROBE_MAX_AGE_SECONDS=%s\n' "$runtime_fence_probe_max_age_seconds"
        printf 'CONTROL_PLANE_RUNTIME_QUEUE_STABLE_SECONDS=%s\n' "$runtime_fence_queue_stable_seconds"
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_API_URL=%s\n' "$runtime_fence_provider_api_url"
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER=%s\n' "$runtime_fence_provider_router"
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE=%s\n' "$runtime_fence_provider_service"
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT=%s\n' "$runtime_fence_provider_legacy_port"
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_HEADER_FILE=%s\n' "$base_provider_header_path"
        printf 'CONTROL_PLANE_RUNTIME_TERMINAL_HTTPS_URL=%s\n' "$public_probe_url"
        printf 'CONTROL_PLANE_RUNTIME_TERMINAL_LOCAL_INGRESS_URL=%s\n' "$local_ingress_url"
    } > "$base_configuration_destination"
}

prepare_runtime_fence_base_configuration()
{
    base_configuration_candidate="${runtime_fence_base_config_file}.new"
    [ ! -e "$runtime_fence_base_config_file" ] && [ ! -L "$runtime_fence_base_config_file" ] \
        && [ ! -e "$base_configuration_candidate" ] && [ ! -L "$base_configuration_candidate" ] \
        || fail 'runtime-fence base semantic configuration already exists'
    (umask 077; render_runtime_fence_base_configuration "$base_configuration_candidate")
    chmod 600 "$base_configuration_candidate"
    mv "$base_configuration_candidate" "$runtime_fence_base_config_file"
    sync
    state_runtime_fence_base_config_path=$runtime_fence_base_config_file
    state_runtime_fence_base_config_sha256=$(sha256_file "$runtime_fence_base_config_file")
    state_runtime_fence_base_config_uid=$(file_uid "$runtime_fence_base_config_file")
    state_runtime_fence_base_config_gid=$(file_gid "$runtime_fence_base_config_file")
    state_runtime_fence_base_config_mode=$(file_mode "$runtime_fence_base_config_file")
    state_runtime_fence_base_config_size=$(file_size "$runtime_fence_base_config_file")
}

assert_runtime_fence_base_configuration()
{
    assert_file_identity "$runtime_fence_base_config_file" \
        "$state_runtime_fence_base_config_path" "$state_runtime_fence_base_config_sha256" \
        "$state_runtime_fence_base_config_uid" "$state_runtime_fence_base_config_gid" \
        "$state_runtime_fence_base_config_mode" "$state_runtime_fence_base_config_size" \
        'runtime-fence base semantic configuration'
    [ "$state_runtime_fence_base_config_uid" = "$operator_uid" ] \
        && [ "$state_runtime_fence_base_config_gid" = "$operator_gid" ] \
        && [ "$state_runtime_fence_base_config_mode" = 600 ] \
        || fail 'runtime-fence base semantic configuration ownership or mode changed'

    # The refreshable IPv6 inventory is deliberately outside this stable digest.
    base_configuration_verifier="$operation_directory/.runtime-fence-base-config-verify.$$"
    (umask 077; render_runtime_fence_base_configuration "$base_configuration_verifier")
    [ "$(sha256_file "$base_configuration_verifier")" = \
        "$state_runtime_fence_base_config_sha256" ] \
        || fail 'runtime-fence base semantic configuration differs from current settings'
    rm -f "$base_configuration_verifier"
}

prepare_pool_root_artifacts()
{
    artifact_color=$1
    case "$artifact_color" in
        green)
            install_atomic_operation_copy "$green_route_health_token_file" \
                "$forward_route_health_root_file" 600 'forward route-health root copy'
            install_atomic_operation_copy "$green_pool_ack_file" \
                "$forward_pool_ack_root_file" 600 'forward pool-ack root copy'
            install_atomic_operation_copy "$green_direct_probe_token_file" \
                "$forward_web_a_direct_probe_root_file" 600 'forward web-a direct-probe root copy'
            install_atomic_operation_copy "$green_applied_ack_file" \
                "$forward_web_a_applied_ack_root_file" 600 'forward web-a applied-ack root copy'
            install_atomic_operation_copy "$green_web_b_direct_probe_token_file" \
                "$forward_web_b_direct_probe_root_file" 600 'forward web-b direct-probe root copy'
            install_atomic_operation_copy "$green_web_b_applied_ack_file" \
                "$forward_web_b_applied_ack_root_file" 600 'forward web-b applied-ack root copy'
            ;;
        blue)
            install_atomic_operation_copy "$blue_route_health_token_file" \
                "$reverse_route_health_root_file" 600 'reverse route-health root copy'
            install_atomic_operation_copy "$blue_pool_ack_file" \
                "$reverse_pool_ack_root_file" 600 'reverse pool-ack root copy'
            install_atomic_operation_copy "$blue_direct_probe_token_file" \
                "$reverse_web_a_direct_probe_root_file" 600 'reverse web-a direct-probe root copy'
            install_atomic_operation_copy "$blue_applied_ack_file" \
                "$reverse_web_a_applied_ack_root_file" 600 'reverse web-a applied-ack root copy'
            install_atomic_operation_copy "$blue_web_b_direct_probe_token_file" \
                "$reverse_web_b_direct_probe_root_file" 600 'reverse web-b direct-probe root copy'
            install_atomic_operation_copy "$blue_web_b_applied_ack_file" \
                "$reverse_web_b_applied_ack_root_file" 600 'reverse web-b applied-ack root copy'
            ;;
        *) fail "unknown pool artifact color: $artifact_color" ;;
    esac
}

render_pool_plan_member()
{
    plan_color=$1
    plan_member=$2
    case "$plan_color:$plan_member" in
        green:a)
            plan_role=web-a
            plan_name=$green_container
            plan_route_identity=$green_web_a_route_identity
            plan_image_reference=$state_green_plan_image_reference
            plan_image_id=$state_green_plan_image_id
            plan_network_ids=$state_green_plan_network_id
            plan_private_volume=$green_state_volume
            plan_loopback_port=$green_loopback_port
            plan_repin_intent=$green_restart_policy_intent_file
            plan_direct_root=$forward_web_a_direct_probe_root_file
            plan_direct_runtime=$green_direct_probe_token_file
            plan_ack_root=$forward_web_a_applied_ack_root_file
            plan_ack_runtime=$green_applied_ack_file
            plan_web_epoch=$green_web_epoch
            plan_drain_epoch=$green_web_a_route_drain_epoch
            plan_writer_member=$green_writer_member
            plan_writer_epoch=$writer_epoch
            ;;
        green:b)
            plan_role=web-b
            plan_name=$green_web_b_container
            plan_route_identity=$green_web_b_route_identity
            plan_image_reference=$state_green_plan_image_reference
            plan_image_id=$state_green_plan_image_id
            plan_network_ids=$state_green_plan_network_id
            plan_private_volume=$green_web_b_private_volume
            plan_loopback_port=$green_web_b_loopback_port
            plan_repin_intent=$green_web_b_restart_policy_intent_file
            plan_direct_root=$forward_web_b_direct_probe_root_file
            plan_direct_runtime=$green_web_b_direct_probe_token_file
            plan_ack_root=$forward_web_b_applied_ack_root_file
            plan_ack_runtime=$green_web_b_applied_ack_file
            plan_web_epoch=$green_web_b_epoch
            plan_drain_epoch=$green_web_b_route_drain_epoch
            plan_writer_member=$green_writer_member
            plan_writer_epoch=$writer_epoch
            ;;
        blue:a)
            plan_role=web-a
            plan_name=$replacement_blue_container
            plan_route_identity=$blue_web_a_route_identity
            plan_image_reference=$state_replacement_blue_plan_image_reference
            plan_image_id=$state_replacement_blue_plan_image_id
            plan_network_ids=$state_replacement_blue_plan_network_id
            plan_private_volume=$blue_state_volume
            plan_loopback_port=$blue_loopback_port
            plan_repin_intent=$replacement_blue_restart_policy_intent_file
            plan_direct_root=$reverse_web_a_direct_probe_root_file
            plan_direct_runtime=$blue_direct_probe_token_file
            plan_ack_root=$reverse_web_a_applied_ack_root_file
            plan_ack_runtime=$blue_applied_ack_file
            plan_web_epoch=$blue_web_epoch
            plan_drain_epoch=$blue_web_a_route_drain_epoch
            plan_writer_member=$blue_writer_member
            plan_writer_epoch=$blue_writer_epoch
            ;;
        blue:b)
            plan_role=web-b
            plan_name=$replacement_blue_web_b_container
            plan_route_identity=$blue_web_b_route_identity
            plan_image_reference=$state_replacement_blue_plan_image_reference
            plan_image_id=$state_replacement_blue_plan_image_id
            plan_network_ids=$state_replacement_blue_plan_network_id
            plan_private_volume=$blue_web_b_private_volume
            plan_loopback_port=$blue_web_b_loopback_port
            plan_repin_intent=$replacement_blue_web_b_restart_policy_intent_file
            plan_direct_root=$reverse_web_b_direct_probe_root_file
            plan_direct_runtime=$blue_web_b_direct_probe_token_file
            plan_ack_root=$reverse_web_b_applied_ack_root_file
            plan_ack_runtime=$blue_web_b_applied_ack_file
            plan_web_epoch=$blue_web_b_epoch
            plan_drain_epoch=$blue_web_b_route_drain_epoch
            plan_writer_member=$blue_writer_member
            plan_writer_epoch=$blue_writer_epoch
            ;;
        *) fail "unknown pool plan member: $plan_color/$plan_member" ;;
    esac
    plan_writer_value=absent
    plan_writer_path=absent
    if [ "$plan_role" = "$plan_writer_member" ]; then
        plan_writer_value=$plan_writer_epoch
        plan_writer_path=$WRITER_MARKER_PATH
    fi
    printf 'member_%s_role=%s\n' "$plan_member" "$plan_role"
    printf 'member_%s_name=%s\n' "$plan_member" "$plan_name"
    printf 'member_%s_route_identity=%s\n' "$plan_member" "$plan_route_identity"
    printf 'member_%s_image_reference=%s\n' "$plan_member" "$plan_image_reference"
    printf 'member_%s_image_id=%s\n' "$plan_member" "$plan_image_id"
    printf 'member_%s_network_ids=%s\n' "$plan_member" "$plan_network_ids"
    printf 'member_%s_private_volume=%s\n' "$plan_member" "$plan_private_volume"
    printf 'member_%s_expected_loopback_port=%s\n' "$plan_member" "$plan_loopback_port"
    printf 'member_%s_repin_intent_file=%s\n' "$plan_member" "$plan_repin_intent"
    printf 'member_%s_direct_probe_token_file=%s\n' "$plan_member" "$plan_direct_root"
    printf 'member_%s_direct_probe_token_sha256=%s\n' "$plan_member" \
        "$(sha256_file "$plan_direct_root")"
    printf 'member_%s_direct_probe_token_metadata=%s\n' "$plan_member" \
        "$(file_metadata "$plan_direct_root")"
    printf 'member_%s_direct_probe_runtime_file=%s\n' "$plan_member" "$plan_direct_runtime"
    printf 'member_%s_direct_probe_runtime_sha256=%s\n' "$plan_member" \
        "$(sha256_file "$plan_direct_runtime")"
    printf 'member_%s_direct_probe_runtime_metadata=%s\n' "$plan_member" \
        "$(file_metadata "$plan_direct_runtime")"
    printf 'member_%s_applied_ack_file=%s\n' "$plan_member" "$plan_ack_root"
    printf 'member_%s_applied_ack_sha256=%s\n' "$plan_member" \
        "$(sha256_file "$plan_ack_root")"
    printf 'member_%s_applied_ack_metadata=%s\n' "$plan_member" \
        "$(file_metadata "$plan_ack_root")"
    printf 'member_%s_applied_ack_runtime_file=%s\n' "$plan_member" "$plan_ack_runtime"
    printf 'member_%s_applied_ack_runtime_sha256=%s\n' "$plan_member" \
        "$(sha256_file "$plan_ack_runtime")"
    printf 'member_%s_applied_ack_runtime_metadata=%s\n' "$plan_member" \
        "$(file_metadata "$plan_ack_runtime")"
    printf 'member_%s_web_epoch=%s\n' "$plan_member" "$plan_web_epoch"
    printf 'member_%s_web_marker_path=%s\n' "$plan_member" "$WEB_MARKER_PATH"
    printf 'member_%s_route_drain_epoch=%s\n' "$plan_member" "$plan_drain_epoch"
    printf 'member_%s_route_drain_marker_path=%s\n' "$plan_member" "$ROUTE_DRAIN_MARKER_PATH"
    printf 'member_%s_writer_epoch=%s\n' "$plan_member" "$plan_writer_value"
    printf 'member_%s_writer_marker_path=%s\n' "$plan_member" "$plan_writer_path"
}

pool_plan_set_sha256_from_candidate()
{
    plan_candidate=$1
    plan_writer=$2
    plan_member_lines="${plan_candidate}.members"
    sed -n '/^member_a_role=/,/^member_b_writer_marker_path=/p' "$plan_candidate" \
        > "$plan_member_lines"
    plan_set_sha256=$(
        {
            while IFS= read -r plan_line || [ -n "$plan_line" ]; do
                printf '%s\0' "${plan_line#*=}"
            done < "$plan_member_lines"
            printf '%s\0' "$plan_writer"
        } | sha256sum | awk '{print $1}'
    )
    rm -f "$plan_member_lines"
    printf '%s\n' "$plan_set_sha256"
}

prepare_forward_pool_plan()
{
    prepare_pool_root_artifacts green
    plan_candidate="${forward_pool_plan_file}.new.$$"
    [ ! -L "$forward_pool_plan_file" ] \
        && [ ! -e "$plan_candidate" ] && [ ! -L "$plan_candidate" ] \
        || fail 'forward pool plan path is unsafe'
    {
        printf '%s\n' 'version=2'
        printf 'operation_id=%s\n' "$state_runtime_fence_operation_id"
        printf '%s\n' 'direction=bootstrap-forward' 'color=green'
        printf 'generation=%s\n' "$forward_pool_generation"
        printf 'coordination_volume=%s\n' "$coordination_volume"
        printf 'mutation_freeze_epoch=%s\n' "$mutation_freeze_epoch"
        printf 'mutation_freeze_marker_path=%s\n' "$MUTATION_FREEZE_MARKER_PATH"
        printf 'mutation_lease_path=%s\n' "$MUTATION_LEASE_PATH"
        printf 'route_health_path=%s\n' "$ROUTE_HEALTH_PATH"
        printf 'route_health_token_file=%s\n' "$forward_route_health_root_file"
        printf 'route_health_token_sha256=%s\n' "$(sha256_file "$forward_route_health_root_file")"
        printf 'route_health_token_metadata=%s\n' "$(file_metadata "$forward_route_health_root_file")"
        printf 'route_health_runtime_file=%s\n' "$green_route_health_token_file"
        printf 'route_health_runtime_sha256=%s\n' "$(sha256_file "$green_route_health_token_file")"
        printf 'route_health_runtime_metadata=%s\n' "$(file_metadata "$green_route_health_token_file")"
        printf 'pool_ack_file=%s\n' "$forward_pool_ack_root_file"
        printf 'pool_ack_sha256=%s\n' "$(sha256_file "$forward_pool_ack_root_file")"
        printf 'pool_ack_metadata=%s\n' "$(file_metadata "$forward_pool_ack_root_file")"
        printf 'pool_ack_runtime_file=%s\n' "$green_pool_ack_file"
        printf 'pool_ack_runtime_sha256=%s\n' "$(sha256_file "$green_pool_ack_file")"
        printf 'pool_ack_runtime_metadata=%s\n' "$(file_metadata "$green_pool_ack_file")"
        printf 'backend_port=%s\n' "$backend_port"
        printf '%s\n' 'member_count=2'
        render_pool_plan_member green a
        render_pool_plan_member green b
    } > "$plan_candidate"
    plan_set_sha256=$(pool_plan_set_sha256_from_candidate "$plan_candidate" "$green_writer_member")
    {
        printf 'writer_member=%s\n' "$green_writer_member"
        printf 'pool_label_key=%s\n' "$POOL_LABEL_KEY"
        printf 'pool_label_value=%s\n' "$green_pool_label_value"
        printf '%s\n' 'retired_member_count=1'
        printf 'retired_member_a_name=%s\n' "$blue_container"
        printf 'retired_member_a_id=%s\n' "$state_blue_id"
        printf 'ingress_pool_manifest_path=%s\n' "$forward_ingress_pool_file"
        printf 'pool_plan_set_sha256=%s\n' "$plan_set_sha256"
    } >> "$plan_candidate"
    chmod 600 "$plan_candidate"
    chown "$immutable_uid:$immutable_gid" "$plan_candidate"
    if [ -e "$forward_pool_plan_file" ]; then
        assert_non_symlink_regular_file "$forward_pool_plan_file" 'forward pool plan manifest'
        cmp -s "$plan_candidate" "$forward_pool_plan_file" \
            || fail 'existing forward pool plan differs from immutable candidate plan'
        rm -f "$plan_candidate"
    else
        mv "$plan_candidate" "$forward_pool_plan_file"
    fi
    sync
    state_forward_pool_plan_path=$forward_pool_plan_file
    state_forward_pool_plan_sha256=$(sha256_file "$forward_pool_plan_file")
    state_forward_pool_plan_uid=$(file_uid "$forward_pool_plan_file")
    state_forward_pool_plan_gid=$(file_gid "$forward_pool_plan_file")
    state_forward_pool_plan_mode=$(file_mode "$forward_pool_plan_file")
    state_forward_pool_plan_size=$(file_size "$forward_pool_plan_file")
    [ "$state_forward_pool_plan_uid" = "$immutable_uid" ] \
        && [ "$state_forward_pool_plan_gid" = "$immutable_gid" ] \
        && [ "$state_forward_pool_plan_mode" = 600 ] \
        || fail 'forward pool plan metadata is not immutable'
}

prepare_forward_ingress_pool()
{
    [ "$state_green_id" != none ] && [ "$state_green_web_b_id" != none ] \
        && [ "$state_green_container_runtime_sha256" != none ] \
        && [ "$state_green_web_b_container_runtime_sha256" != none ] \
        || fail 'forward ingress pool requires both exact denied member identities'
    ingress_candidate="${forward_ingress_pool_file}.new.$$"
    [ ! -L "$forward_ingress_pool_file" ] \
        && [ ! -e "$ingress_candidate" ] && [ ! -L "$ingress_candidate" ] \
        || fail 'forward ingress pool path is unsafe'
    ingress_a_address=$(pool_member_address "$green_container")
    ingress_b_address=$(pool_member_address "$green_web_b_container")
    ingress_a_network_sha256=$(pool_member_network_sha256 "$green_container")
    ingress_b_network_sha256=$(pool_member_network_sha256 "$green_web_b_container")
    ingress_a_bindings_sha256=$(pool_member_bindings_sha256 "$green_container")
    ingress_b_bindings_sha256=$(pool_member_bindings_sha256 "$green_web_b_container")
    ingress_member_set_sha256=$(
        printf '%s\0' \
            "$forward_pool_generation" "$state_forward_pool_plan_sha256" \
            web-a "$green_container" "$green_web_a_route_identity" "$state_green_id" \
            "$ingress_a_address" "$backend_port" "$state_green_plan_image_reference" \
            "$state_green_plan_image_id" "$state_green_container_runtime_sha256" \
            "$ingress_a_network_sha256" "$ingress_a_bindings_sha256" \
            "$forward_web_a_applied_ack_root_file" \
            "$(sha256_file "$forward_web_a_applied_ack_root_file")" \
            "$(file_metadata "$forward_web_a_applied_ack_root_file")" \
            web-b "$green_web_b_container" "$green_web_b_route_identity" \
            "$state_green_web_b_id" "$ingress_b_address" "$backend_port" \
            "$state_green_plan_image_reference" "$state_green_plan_image_id" \
            "$state_green_web_b_container_runtime_sha256" "$ingress_b_network_sha256" \
            "$ingress_b_bindings_sha256" "$forward_web_b_applied_ack_root_file" \
            "$(sha256_file "$forward_web_b_applied_ack_root_file")" \
            "$(file_metadata "$forward_web_b_applied_ack_root_file")" \
            "$green_writer_member" "$POOL_LABEL_KEY" "$green_pool_label_value" \
            | sha256sum | awk '{print $1}'
    )
    {
        printf '%s\n' 'version=2'
        printf 'operation_id=%s\n' "$state_runtime_fence_operation_id"
        printf '%s\n' 'direction=bootstrap-forward' 'color=green'
        printf 'generation=%s\n' "$forward_pool_generation"
        printf 'parent_pool_plan_sha256=%s\n' "$state_forward_pool_plan_sha256"
        printf 'pool_ack_file=%s\n' "$forward_pool_ack_root_file"
        printf 'pool_ack_sha256=%s\n' "$(sha256_file "$forward_pool_ack_root_file")"
        printf 'pool_ack_metadata=%s\n' "$(file_metadata "$forward_pool_ack_root_file")"
        printf '%s\n' 'member_count=2'
        printf '%s\n' 'member_a_role=web-a'
        printf 'member_a_name=%s\n' "$green_container"
        printf 'member_a_route_identity=%s\n' "$green_web_a_route_identity"
        printf 'member_a_id=%s\n' "$state_green_id"
        printf 'member_a_address=%s\n' "$ingress_a_address"
        printf 'member_a_port=%s\n' "$backend_port"
        printf 'member_a_image_reference=%s\n' "$state_green_plan_image_reference"
        printf 'member_a_image_id=%s\n' "$state_green_plan_image_id"
        printf 'member_a_runtime_sha256=%s\n' "$state_green_container_runtime_sha256"
        printf 'member_a_network_sha256=%s\n' "$ingress_a_network_sha256"
        printf 'member_a_bindings_sha256=%s\n' "$ingress_a_bindings_sha256"
        printf 'member_a_applied_ack_file=%s\n' "$forward_web_a_applied_ack_root_file"
        printf 'member_a_applied_ack_sha256=%s\n' \
            "$(sha256_file "$forward_web_a_applied_ack_root_file")"
        printf 'member_a_applied_ack_metadata=%s\n' \
            "$(file_metadata "$forward_web_a_applied_ack_root_file")"
        printf '%s\n' 'member_b_role=web-b'
        printf 'member_b_name=%s\n' "$green_web_b_container"
        printf 'member_b_route_identity=%s\n' "$green_web_b_route_identity"
        printf 'member_b_id=%s\n' "$state_green_web_b_id"
        printf 'member_b_address=%s\n' "$ingress_b_address"
        printf 'member_b_port=%s\n' "$backend_port"
        printf 'member_b_image_reference=%s\n' "$state_green_plan_image_reference"
        printf 'member_b_image_id=%s\n' "$state_green_plan_image_id"
        printf 'member_b_runtime_sha256=%s\n' "$state_green_web_b_container_runtime_sha256"
        printf 'member_b_network_sha256=%s\n' "$ingress_b_network_sha256"
        printf 'member_b_bindings_sha256=%s\n' "$ingress_b_bindings_sha256"
        printf 'member_b_applied_ack_file=%s\n' "$forward_web_b_applied_ack_root_file"
        printf 'member_b_applied_ack_sha256=%s\n' \
            "$(sha256_file "$forward_web_b_applied_ack_root_file")"
        printf 'member_b_applied_ack_metadata=%s\n' \
            "$(file_metadata "$forward_web_b_applied_ack_root_file")"
        printf 'writer_member=%s\n' "$green_writer_member"
        printf 'pool_label_key=%s\n' "$POOL_LABEL_KEY"
        printf 'pool_label_value=%s\n' "$green_pool_label_value"
        printf 'member_set_sha256=%s\n' "$ingress_member_set_sha256"
        printf 'route_health_path=%s\n' "$ROUTE_HEALTH_PATH"
        printf 'route_health_token_file=%s\n' "$forward_route_health_root_file"
        printf 'route_health_token_sha256=%s\n' "$(sha256_file "$forward_route_health_root_file")"
        printf 'route_health_token_metadata=%s\n' "$(file_metadata "$forward_route_health_root_file")"
    } > "$ingress_candidate"
    chmod 600 "$ingress_candidate"
    chown "$immutable_uid:$immutable_gid" "$ingress_candidate"
    if [ -e "$forward_ingress_pool_file" ]; then
        assert_non_symlink_regular_file "$forward_ingress_pool_file" \
            'forward ingress pool manifest'
        cmp -s "$ingress_candidate" "$forward_ingress_pool_file" \
            || fail 'existing forward ingress pool differs from exact observed members'
        rm -f "$ingress_candidate"
    else
        mv "$ingress_candidate" "$forward_ingress_pool_file"
    fi
    sync
    state_forward_ingress_pool_path=$forward_ingress_pool_file
    state_forward_ingress_pool_sha256=$(sha256_file "$forward_ingress_pool_file")
    state_forward_ingress_pool_uid=$(file_uid "$forward_ingress_pool_file")
    state_forward_ingress_pool_gid=$(file_gid "$forward_ingress_pool_file")
    state_forward_ingress_pool_mode=$(file_mode "$forward_ingress_pool_file")
    state_forward_ingress_pool_size=$(file_size "$forward_ingress_pool_file")
    [ "$state_forward_ingress_pool_uid" = "$immutable_uid" ] \
        && [ "$state_forward_ingress_pool_gid" = "$immutable_gid" ] \
        && [ "$state_forward_ingress_pool_mode" = 600 ] \
        || fail 'forward ingress pool metadata is not immutable'
}

prepare_runtime_fence_artifacts()
{
    state_green_ack_path=$green_applied_ack_file
    state_green_ack_sha256=$(sha256_file "$green_applied_ack_file")
    state_green_ack_uid=$(file_uid "$green_applied_ack_file")
    state_green_ack_gid=$(file_gid "$green_applied_ack_file")
    state_green_ack_mode=$(file_mode "$green_applied_ack_file")
    state_green_ack_size=$(file_size "$green_applied_ack_file")
    state_blue_ack_path=$blue_applied_ack_file
    state_blue_ack_sha256=$(sha256_file "$blue_applied_ack_file")
    state_blue_ack_uid=$(file_uid "$blue_applied_ack_file")
    state_blue_ack_gid=$(file_gid "$blue_applied_ack_file")
    state_blue_ack_mode=$(file_mode "$blue_applied_ack_file")
    state_blue_ack_size=$(file_size "$blue_applied_ack_file")

    install_atomic_operation_copy "$green_applied_ack_file" "$green_ack_snapshot_file" 600 \
        'green applied-acknowledgement snapshot'
    install_atomic_operation_copy "$blue_applied_ack_file" "$blue_ack_snapshot_file" 600 \
        'blue applied-acknowledgement snapshot'
    state_green_ack_snapshot_path=$green_ack_snapshot_file
    state_green_ack_snapshot_sha256=$(sha256_file "$green_ack_snapshot_file")
    state_green_ack_snapshot_uid=$(file_uid "$green_ack_snapshot_file")
    state_green_ack_snapshot_gid=$(file_gid "$green_ack_snapshot_file")
    state_green_ack_snapshot_mode=$(file_mode "$green_ack_snapshot_file")
    state_green_ack_snapshot_size=$(file_size "$green_ack_snapshot_file")
    state_blue_ack_snapshot_path=$blue_ack_snapshot_file
    state_blue_ack_snapshot_sha256=$(sha256_file "$blue_ack_snapshot_file")
    state_blue_ack_snapshot_uid=$(file_uid "$blue_ack_snapshot_file")
    state_blue_ack_snapshot_gid=$(file_gid "$blue_ack_snapshot_file")
    state_blue_ack_snapshot_mode=$(file_mode "$blue_ack_snapshot_file")
    state_blue_ack_snapshot_size=$(file_size "$blue_ack_snapshot_file")

    install_atomic_operation_copy "$green_ack_snapshot_file" "$runtime_fence_https_ack_file" \
        600 'forward runtime-fence HTTPS acknowledgement'
    state_runtime_fence_https_ack_path=$runtime_fence_https_ack_file
    state_runtime_fence_https_ack_sha256=$(sha256_file "$runtime_fence_https_ack_file")
    state_runtime_fence_https_ack_uid=$(file_uid "$runtime_fence_https_ack_file")
    state_runtime_fence_https_ack_gid=$(file_gid "$runtime_fence_https_ack_file")
    state_runtime_fence_https_ack_mode=$(file_mode "$runtime_fence_https_ack_file")
    state_runtime_fence_https_ack_size=$(file_size "$runtime_fence_https_ack_file")

    if [ -n "$runtime_fence_provider_header_file" ]; then
        assert_non_symlink_regular_file "$runtime_fence_provider_header_file" \
            CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_HEADER_FILE
        state_runtime_fence_provider_header_source_path=$runtime_fence_provider_header_file
        state_runtime_fence_provider_header_source_sha256=$(sha256_file \
            "$runtime_fence_provider_header_file")
        state_runtime_fence_provider_header_source_uid=$(file_uid \
            "$runtime_fence_provider_header_file")
        state_runtime_fence_provider_header_source_gid=$(file_gid \
            "$runtime_fence_provider_header_file")
        state_runtime_fence_provider_header_source_mode=$(file_mode \
            "$runtime_fence_provider_header_file")
        state_runtime_fence_provider_header_source_size=$(file_size \
            "$runtime_fence_provider_header_file")
        install_atomic_operation_copy "$runtime_fence_provider_header_file" \
            "$runtime_fence_provider_header_copy" 600 'runtime-fence provider-header snapshot'
        state_runtime_fence_provider_header_copy_path=$runtime_fence_provider_header_copy
        state_runtime_fence_provider_header_copy_sha256=$(sha256_file \
            "$runtime_fence_provider_header_copy")
        state_runtime_fence_provider_header_copy_uid=$(file_uid \
            "$runtime_fence_provider_header_copy")
        state_runtime_fence_provider_header_copy_gid=$(file_gid \
            "$runtime_fence_provider_header_copy")
        state_runtime_fence_provider_header_copy_mode=$(file_mode \
            "$runtime_fence_provider_header_copy")
        state_runtime_fence_provider_header_copy_size=$(file_size \
            "$runtime_fence_provider_header_copy")
    else
        state_runtime_fence_provider_header_source_path=none
        state_runtime_fence_provider_header_source_sha256=none
        state_runtime_fence_provider_header_source_uid=none
        state_runtime_fence_provider_header_source_gid=none
        state_runtime_fence_provider_header_source_mode=none
        state_runtime_fence_provider_header_source_size=none
        state_runtime_fence_provider_header_copy_path=none
        state_runtime_fence_provider_header_copy_sha256=none
        state_runtime_fence_provider_header_copy_uid=none
        state_runtime_fence_provider_header_copy_gid=none
        state_runtime_fence_provider_header_copy_mode=none
        state_runtime_fence_provider_header_copy_size=none
    fi

    prepare_runtime_fence_base_configuration
    runtime_fence_environment_candidate="${runtime_fence_environment_file}.new"
    [ ! -e "$runtime_fence_environment_file" ] && [ ! -L "$runtime_fence_environment_file" ] \
        && [ ! -e "$runtime_fence_environment_candidate" ] \
        && [ ! -L "$runtime_fence_environment_candidate" ] \
        || fail 'forward runtime-fence environment already exists'
    {
        cat "$runtime_fence_base_config_file"
        printf 'CONTROL_PLANE_RUNTIME_OPERATION_ID=%s\n' "$state_runtime_fence_operation_id"
        printf 'CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST=%s\n' "$state_forward_pool_plan_path"
        printf 'CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256=%s\n' \
            "$state_forward_pool_plan_sha256"
        printf 'CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA=%s:%s:%s:%s\n' \
            "$state_forward_pool_plan_uid" "$state_forward_pool_plan_gid" \
            "$state_forward_pool_plan_mode" "$state_forward_pool_plan_size"
        printf '%s\n' 'CONTROL_PLANE_RUNTIME_PROVIDER_CAPTURE_EXPECTATION=incumbent'
    } > "$runtime_fence_environment_candidate"
    chmod 600 "$runtime_fence_environment_candidate"
    mv "$runtime_fence_environment_candidate" "$runtime_fence_environment_file"
    sync
    state_runtime_fence_provisioner_path=$runtime_fence_provisioner
    state_runtime_fence_provisioner_sha256=$runtime_fence_provisioner_sha256
    state_runtime_fence_provisioner_uid=$(file_uid "$runtime_fence_provisioner")
    state_runtime_fence_provisioner_gid=$(file_gid "$runtime_fence_provisioner")
    state_runtime_fence_provisioner_mode=$(file_mode "$runtime_fence_provisioner")
    state_runtime_fence_provisioner_size=$(file_size "$runtime_fence_provisioner")
    state_runtime_fence_environment_path=$runtime_fence_environment_file
    state_runtime_fence_environment_sha256=$(sha256_file "$runtime_fence_environment_file")
    state_runtime_fence_environment_uid=$(file_uid "$runtime_fence_environment_file")
    state_runtime_fence_environment_gid=$(file_gid "$runtime_fence_environment_file")
    state_runtime_fence_environment_mode=$(file_mode "$runtime_fence_environment_file")
    state_runtime_fence_environment_size=$(file_size "$runtime_fence_environment_file")
    assert_runtime_fence_artifacts
}

assert_runtime_fence_artifacts()
{
    assert_operator_configuration_identity
    assert_file_identity "$forward_pool_plan_file" "$state_forward_pool_plan_path" \
        "$state_forward_pool_plan_sha256" "$state_forward_pool_plan_uid" \
        "$state_forward_pool_plan_gid" "$state_forward_pool_plan_mode" \
        "$state_forward_pool_plan_size" 'forward immutable pool plan'
    [ "$state_forward_pool_plan_uid" = "$immutable_uid" ] \
        && [ "$state_forward_pool_plan_gid" = "$immutable_gid" ] \
        && [ "$state_forward_pool_plan_mode" = 600 ] \
        || fail 'forward pool plan ownership or mode changed'
    assert_file_identity "$runtime_fence_provisioner" \
        "$state_runtime_fence_provisioner_path" "$state_runtime_fence_provisioner_sha256" \
        "$state_runtime_fence_provisioner_uid" "$state_runtime_fence_provisioner_gid" \
        "$state_runtime_fence_provisioner_mode" "$state_runtime_fence_provisioner_size" \
        'runtime-fence provisioner'
    [ "$state_runtime_fence_provisioner_sha256" = "$runtime_fence_provisioner_sha256" ] \
        || fail 'runtime-fence provisioner digest differs from its configured pin'
    assert_file_identity "$green_applied_ack_file" "$state_green_ack_path" \
        "$state_green_ack_sha256" "$state_green_ack_uid" "$state_green_ack_gid" \
        "$state_green_ack_mode" "$state_green_ack_size" 'green acknowledgement source'
    assert_file_identity "$blue_applied_ack_file" "$state_blue_ack_path" \
        "$state_blue_ack_sha256" "$state_blue_ack_uid" "$state_blue_ack_gid" \
        "$state_blue_ack_mode" "$state_blue_ack_size" 'blue acknowledgement source'
    assert_file_identity "$green_ack_snapshot_file" "$state_green_ack_snapshot_path" \
        "$state_green_ack_snapshot_sha256" "$state_green_ack_snapshot_uid" \
        "$state_green_ack_snapshot_gid" "$state_green_ack_snapshot_mode" \
        "$state_green_ack_snapshot_size" 'green acknowledgement snapshot'
    assert_file_identity "$blue_ack_snapshot_file" "$state_blue_ack_snapshot_path" \
        "$state_blue_ack_snapshot_sha256" "$state_blue_ack_snapshot_uid" \
        "$state_blue_ack_snapshot_gid" "$state_blue_ack_snapshot_mode" \
        "$state_blue_ack_snapshot_size" 'blue acknowledgement snapshot'
    [ "$state_green_ack_snapshot_sha256" = "$state_green_ack_sha256" ] \
        && [ "$state_blue_ack_snapshot_sha256" = "$state_blue_ack_sha256" ] \
        && [ "$state_green_ack_snapshot_uid" = "$operator_uid" ] \
        && [ "$state_green_ack_snapshot_gid" = "$operator_gid" ] \
        && [ "$state_green_ack_snapshot_mode" = 600 ] \
        && [ "$state_blue_ack_snapshot_uid" = "$operator_uid" ] \
        && [ "$state_blue_ack_snapshot_gid" = "$operator_gid" ] \
        && [ "$state_blue_ack_snapshot_mode" = 600 ] \
        || fail 'acknowledgement snapshots differ from their pinned sources'
    assert_file_identity "$runtime_fence_https_ack_file" \
        "$state_runtime_fence_https_ack_path" "$state_runtime_fence_https_ack_sha256" \
        "$state_runtime_fence_https_ack_uid" "$state_runtime_fence_https_ack_gid" \
        "$state_runtime_fence_https_ack_mode" "$state_runtime_fence_https_ack_size" \
        'forward runtime-fence HTTPS acknowledgement'
    [ "$state_runtime_fence_https_ack_sha256" = "$state_green_ack_sha256" ] \
        || fail 'forward runtime-fence acknowledgements differ from pinned green bytes'
    if [ "$state_runtime_fence_provider_header_source_path" != none ]; then
        [ "$runtime_fence_provider_header_file" = \
            "$state_runtime_fence_provider_header_source_path" ] \
            || fail 'runtime-fence provider-header source path changed'
        assert_file_identity "$runtime_fence_provider_header_file" \
            "$state_runtime_fence_provider_header_source_path" \
            "$state_runtime_fence_provider_header_source_sha256" \
            "$state_runtime_fence_provider_header_source_uid" \
            "$state_runtime_fence_provider_header_source_gid" \
            "$state_runtime_fence_provider_header_source_mode" \
            "$state_runtime_fence_provider_header_source_size" \
            'runtime-fence provider-header source'
        assert_file_identity "$runtime_fence_provider_header_copy" \
            "$state_runtime_fence_provider_header_copy_path" \
            "$state_runtime_fence_provider_header_copy_sha256" \
            "$state_runtime_fence_provider_header_copy_uid" \
            "$state_runtime_fence_provider_header_copy_gid" \
            "$state_runtime_fence_provider_header_copy_mode" \
            "$state_runtime_fence_provider_header_copy_size" \
            'runtime-fence provider-header snapshot'
        [ "$state_runtime_fence_provider_header_copy_sha256" = \
            "$state_runtime_fence_provider_header_source_sha256" ] \
            && [ "$state_runtime_fence_provider_header_copy_uid" = "$operator_uid" ] \
            && [ "$state_runtime_fence_provider_header_copy_gid" = "$operator_gid" ] \
            && [ "$state_runtime_fence_provider_header_copy_mode" = 600 ] \
            || fail 'runtime-fence provider-header snapshot differs from its pinned source'
    else
        [ -z "$runtime_fence_provider_header_file" ] \
            && [ "$state_runtime_fence_provider_header_source_sha256" = none ] \
            && [ "$state_runtime_fence_provider_header_source_uid" = none ] \
            && [ "$state_runtime_fence_provider_header_source_gid" = none ] \
            && [ "$state_runtime_fence_provider_header_source_mode" = none ] \
            && [ "$state_runtime_fence_provider_header_source_size" = none ] \
            && [ "$state_runtime_fence_provider_header_copy_path" = none ] \
            && [ "$state_runtime_fence_provider_header_copy_sha256" = none ] \
            && [ "$state_runtime_fence_provider_header_copy_uid" = none ] \
            && [ "$state_runtime_fence_provider_header_copy_gid" = none ] \
            && [ "$state_runtime_fence_provider_header_copy_mode" = none ] \
            && [ "$state_runtime_fence_provider_header_copy_size" = none ] \
            || fail 'absent runtime-fence provider-header state is inconsistent'
    fi
    assert_runtime_fence_base_configuration
    assert_file_identity "$runtime_fence_environment_file" \
        "$state_runtime_fence_environment_path" "$state_runtime_fence_environment_sha256" \
        "$state_runtime_fence_environment_uid" "$state_runtime_fence_environment_gid" \
        "$state_runtime_fence_environment_mode" "$state_runtime_fence_environment_size" \
        'forward runtime-fence environment'
    [ "$state_runtime_fence_environment_uid" = "$operator_uid" ] \
        && [ "$state_runtime_fence_environment_gid" = "$operator_gid" ] \
        && [ "$state_runtime_fence_environment_mode" = 600 ] \
        && [ "$state_runtime_fence_https_ack_uid" = "$operator_uid" ] \
        && [ "$state_runtime_fence_https_ack_gid" = "$operator_gid" ] \
        && [ "$state_runtime_fence_https_ack_mode" = 600 ] \
        || fail 'forward runtime-fence artifact ownership or mode changed'
}

runtime_fence_call()
{
    assert_runtime_fence_artifacts
    assert_release_asset_identity runtime-fence-provisioner
    "$runtime_fence_provisioner" "$@"
}

complete_forward_runtime_fence()
{
    case "$state_phase" in
        preflight-started)
            state_phase=fence-prepare-intent
            write_state "$state_phase"
            ;;
    esac
    case "$state_phase" in
        fence-prepare-intent)
            runtime_fence_call prepare "$runtime_fence_environment_file"
            state_phase=fence-prepared
            write_state "$state_phase"
            test_crash after-fence-prepare
            ;;
    esac
    case "$state_phase" in
        fence-prepared)
            runtime_fence_call capture
            state_phase=fence-captured
            write_state "$state_phase"
            test_crash after-fence-capture
            ;;
    esac
    case "$state_phase" in
        fence-captured)
            runtime_fence_call arm
            state_phase=fence-armed
            write_state "$state_phase"
            test_crash after-fence-arm
            ;;
    esac
    case "$state_phase" in
        fence-armed)
            runtime_fence_call verify
            state_phase=fence-active
            write_state "$state_phase"
            ;;
        fence-active|green-starting|green-started-unproven|green-started|preflight-ready)
            runtime_fence_call verify
            ;;
    esac
}

allocate_reverse_runtime_fence_generation()
{
    case "$state_reverse_fence_generation" in
        none)
            next_reverse_generation=1
            ;;
        *)
            validate_positive_integer "$state_reverse_fence_generation" \
                'persisted reverse runtime-fence generation'
            next_reverse_generation=$((state_reverse_fence_generation + 1))
            [ "$next_reverse_generation" -gt "$state_reverse_fence_generation" ] \
                || fail 'reverse runtime-fence generation cannot advance monotonically'
            ;;
    esac

    set_reverse_runtime_fence_generation_paths "$next_reverse_generation"
    state_reverse_fence_generation=$next_reverse_generation
    state_replacement_blue_container_runtime_sha256=none
    state_replacement_blue_web_b_container_runtime_sha256=none
    state_reverse_pool_plan_path=none
    state_reverse_pool_plan_sha256=none
    state_reverse_pool_plan_uid=none
    state_reverse_pool_plan_gid=none
    state_reverse_pool_plan_mode=none
    state_reverse_pool_plan_size=none
    state_reverse_ingress_pool_path=none
    state_reverse_ingress_pool_sha256=none
    state_reverse_ingress_pool_uid=none
    state_reverse_ingress_pool_gid=none
    state_reverse_ingress_pool_mode=none
    state_reverse_ingress_pool_size=none
    state_reverse_fence_operation_id="${operation_id}.${reverse_generation_token}"
    state_reverse_fence_provisioner_path=$runtime_fence_provisioner
    state_reverse_fence_provisioner_sha256=$runtime_fence_provisioner_sha256
    state_reverse_fence_environment_path=$reverse_runtime_fence_environment_file
    state_reverse_fence_environment_sha256=none
    state_reverse_fence_environment_uid=none
    state_reverse_fence_environment_gid=none
    state_reverse_fence_environment_mode=none
    state_reverse_fence_environment_size=none
    state_reverse_fence_https_ack_path=$reverse_runtime_fence_https_ack_file
    state_reverse_fence_https_ack_sha256=none
    state_reverse_fence_https_ack_uid=none
    state_reverse_fence_https_ack_gid=none
    state_reverse_fence_https_ack_mode=none
    state_reverse_fence_https_ack_size=none
    if [ "$state_runtime_fence_provider_header_copy_path" != none ]; then
        state_reverse_fence_provider_header_path=$state_runtime_fence_provider_header_copy_path
        state_reverse_fence_provider_header_sha256=$state_runtime_fence_provider_header_copy_sha256
        state_reverse_fence_provider_header_uid=$state_runtime_fence_provider_header_copy_uid
        state_reverse_fence_provider_header_gid=$state_runtime_fence_provider_header_copy_gid
        state_reverse_fence_provider_header_mode=$state_runtime_fence_provider_header_copy_mode
        state_reverse_fence_provider_header_size=$state_runtime_fence_provider_header_copy_size
    else
        state_reverse_fence_provider_header_path=none
        state_reverse_fence_provider_header_sha256=none
        state_reverse_fence_provider_header_uid=none
        state_reverse_fence_provider_header_gid=none
        state_reverse_fence_provider_header_mode=none
        state_reverse_fence_provider_header_size=none
    fi
    state_phase=reverse-fence-artifacts-preparing
    write_state "$state_phase"
    test_crash after-reverse-fence-generation-allocation
}

install_immutable_reverse_runtime_fence_artifact()
{
    immutable_candidate=$1
    immutable_target=$2
    immutable_name=$3
    immutable_checksum=$(sha256_file "$immutable_candidate")

    if [ -e "$immutable_target" ] || [ -L "$immutable_target" ]; then
        assert_non_symlink_regular_file "$immutable_target" "$immutable_name"
        [ "$(sha256_file "$immutable_target")" = "$immutable_checksum" ] \
            || fail "$immutable_name changed after its generation was reserved"
    elif ! ln "$immutable_candidate" "$immutable_target" 2>/dev/null; then
        assert_non_symlink_regular_file "$immutable_target" "$immutable_name"
        [ "$(sha256_file "$immutable_target")" = "$immutable_checksum" ] \
            || fail "$immutable_name was replaced during atomic installation"
    fi
    rm -f "$immutable_candidate"
}

prepare_reverse_pool_plan()
{
    prepare_pool_root_artifacts blue
    plan_candidate="${reverse_pool_plan_file}.new.$$"
    [ ! -L "$reverse_pool_plan_file" ] \
        && [ ! -e "$plan_candidate" ] && [ ! -L "$plan_candidate" ] \
        || fail 'reverse pool plan path is unsafe'
    {
        printf '%s\n' 'version=2'
        printf 'operation_id=%s\n' "$state_reverse_fence_operation_id"
        printf '%s\n' 'direction=reverse' 'color=blue'
        printf 'generation=%s\n' "$state_reverse_fence_generation"
        printf 'coordination_volume=%s\n' "$coordination_volume"
        printf 'mutation_freeze_epoch=%s\n' "$reverse_mutation_freeze_epoch"
        printf 'mutation_freeze_marker_path=%s\n' "$MUTATION_FREEZE_MARKER_PATH"
        printf 'mutation_lease_path=%s\n' "$MUTATION_LEASE_PATH"
        printf 'route_health_path=%s\n' "$ROUTE_HEALTH_PATH"
        printf 'route_health_token_file=%s\n' "$reverse_route_health_root_file"
        printf 'route_health_token_sha256=%s\n' "$(sha256_file "$reverse_route_health_root_file")"
        printf 'route_health_token_metadata=%s\n' "$(file_metadata "$reverse_route_health_root_file")"
        printf 'route_health_runtime_file=%s\n' "$blue_route_health_token_file"
        printf 'route_health_runtime_sha256=%s\n' "$(sha256_file "$blue_route_health_token_file")"
        printf 'route_health_runtime_metadata=%s\n' "$(file_metadata "$blue_route_health_token_file")"
        printf 'pool_ack_file=%s\n' "$reverse_pool_ack_root_file"
        printf 'pool_ack_sha256=%s\n' "$(sha256_file "$reverse_pool_ack_root_file")"
        printf 'pool_ack_metadata=%s\n' "$(file_metadata "$reverse_pool_ack_root_file")"
        printf 'pool_ack_runtime_file=%s\n' "$blue_pool_ack_file"
        printf 'pool_ack_runtime_sha256=%s\n' "$(sha256_file "$blue_pool_ack_file")"
        printf 'pool_ack_runtime_metadata=%s\n' "$(file_metadata "$blue_pool_ack_file")"
        printf 'backend_port=%s\n' "$backend_port"
        printf '%s\n' 'member_count=2'
        render_pool_plan_member blue a
        render_pool_plan_member blue b
    } > "$plan_candidate"
    plan_set_sha256=$(pool_plan_set_sha256_from_candidate "$plan_candidate" "$blue_writer_member")
    {
        printf 'writer_member=%s\n' "$blue_writer_member"
        printf 'pool_label_key=%s\n' "$POOL_LABEL_KEY"
        printf 'pool_label_value=%s\n' "$blue_pool_label_value"
        printf '%s\n' 'retired_member_count=2'
        printf 'retired_member_a_name=%s\n' "$green_container"
        printf 'retired_member_a_id=%s\n' "$state_green_id"
        printf 'retired_member_b_name=%s\n' "$green_web_b_container"
        printf 'retired_member_b_id=%s\n' "$state_green_web_b_id"
        printf 'ingress_pool_manifest_path=%s\n' "$reverse_ingress_pool_file"
        printf 'pool_plan_set_sha256=%s\n' "$plan_set_sha256"
    } >> "$plan_candidate"
    chmod 600 "$plan_candidate"
    chown "$immutable_uid:$immutable_gid" "$plan_candidate"
    if [ -e "$reverse_pool_plan_file" ]; then
        assert_non_symlink_regular_file "$reverse_pool_plan_file" 'reverse pool plan manifest'
        cmp -s "$plan_candidate" "$reverse_pool_plan_file" \
            || fail 'existing reverse pool plan differs from immutable candidate plan'
        rm -f "$plan_candidate"
    else
        mv "$plan_candidate" "$reverse_pool_plan_file"
    fi
    sync
    state_reverse_pool_plan_path=$reverse_pool_plan_file
    state_reverse_pool_plan_sha256=$(sha256_file "$reverse_pool_plan_file")
    state_reverse_pool_plan_uid=$(file_uid "$reverse_pool_plan_file")
    state_reverse_pool_plan_gid=$(file_gid "$reverse_pool_plan_file")
    state_reverse_pool_plan_mode=$(file_mode "$reverse_pool_plan_file")
    state_reverse_pool_plan_size=$(file_size "$reverse_pool_plan_file")
    [ "$state_reverse_pool_plan_uid" = "$immutable_uid" ] \
        && [ "$state_reverse_pool_plan_gid" = "$immutable_gid" ] \
        && [ "$state_reverse_pool_plan_mode" = 600 ] \
        || fail 'reverse pool plan metadata is not immutable'
}

prepare_reverse_ingress_pool()
{
    [ "$state_replacement_blue_id" != none ] \
        && [ "$state_replacement_blue_web_b_id" != none ] \
        && [ "$state_replacement_blue_container_runtime_sha256" != none ] \
        && [ "$state_replacement_blue_web_b_container_runtime_sha256" != none ] \
        || fail 'reverse ingress pool requires both exact denied member identities'
    ingress_candidate="${reverse_ingress_pool_file}.new.$$"
    [ ! -L "$reverse_ingress_pool_file" ] \
        && [ ! -e "$ingress_candidate" ] && [ ! -L "$ingress_candidate" ] \
        || fail 'reverse ingress pool path is unsafe'
    ingress_a_address=$(pool_member_address "$replacement_blue_container")
    ingress_b_address=$(pool_member_address "$replacement_blue_web_b_container")
    ingress_a_network_sha256=$(pool_member_network_sha256 "$replacement_blue_container")
    ingress_b_network_sha256=$(pool_member_network_sha256 "$replacement_blue_web_b_container")
    ingress_a_bindings_sha256=$(pool_member_bindings_sha256 "$replacement_blue_container")
    ingress_b_bindings_sha256=$(pool_member_bindings_sha256 "$replacement_blue_web_b_container")
    ingress_member_set_sha256=$(
        printf '%s\0' \
            "$state_reverse_fence_generation" "$state_reverse_pool_plan_sha256" \
            web-a "$replacement_blue_container" "$blue_web_a_route_identity" \
            "$state_replacement_blue_id" "$ingress_a_address" "$backend_port" \
            "$state_replacement_blue_plan_image_reference" \
            "$state_replacement_blue_plan_image_id" \
            "$state_replacement_blue_container_runtime_sha256" \
            "$ingress_a_network_sha256" "$ingress_a_bindings_sha256" \
            "$reverse_web_a_applied_ack_root_file" \
            "$(sha256_file "$reverse_web_a_applied_ack_root_file")" \
            "$(file_metadata "$reverse_web_a_applied_ack_root_file")" \
            web-b "$replacement_blue_web_b_container" "$blue_web_b_route_identity" \
            "$state_replacement_blue_web_b_id" "$ingress_b_address" "$backend_port" \
            "$state_replacement_blue_plan_image_reference" \
            "$state_replacement_blue_plan_image_id" \
            "$state_replacement_blue_web_b_container_runtime_sha256" \
            "$ingress_b_network_sha256" "$ingress_b_bindings_sha256" \
            "$reverse_web_b_applied_ack_root_file" \
            "$(sha256_file "$reverse_web_b_applied_ack_root_file")" \
            "$(file_metadata "$reverse_web_b_applied_ack_root_file")" \
            "$blue_writer_member" "$POOL_LABEL_KEY" "$blue_pool_label_value" \
            | sha256sum | awk '{print $1}'
    )
    {
        printf '%s\n' 'version=2'
        printf 'operation_id=%s\n' "$state_reverse_fence_operation_id"
        printf '%s\n' 'direction=reverse' 'color=blue'
        printf 'generation=%s\n' "$state_reverse_fence_generation"
        printf 'parent_pool_plan_sha256=%s\n' "$state_reverse_pool_plan_sha256"
        printf 'pool_ack_file=%s\n' "$reverse_pool_ack_root_file"
        printf 'pool_ack_sha256=%s\n' "$(sha256_file "$reverse_pool_ack_root_file")"
        printf 'pool_ack_metadata=%s\n' "$(file_metadata "$reverse_pool_ack_root_file")"
        printf '%s\n' 'member_count=2' 'member_a_role=web-a'
        printf 'member_a_name=%s\n' "$replacement_blue_container"
        printf 'member_a_route_identity=%s\n' "$blue_web_a_route_identity"
        printf 'member_a_id=%s\n' "$state_replacement_blue_id"
        printf 'member_a_address=%s\n' "$ingress_a_address"
        printf 'member_a_port=%s\n' "$backend_port"
        printf 'member_a_image_reference=%s\n' "$state_replacement_blue_plan_image_reference"
        printf 'member_a_image_id=%s\n' "$state_replacement_blue_plan_image_id"
        printf 'member_a_runtime_sha256=%s\n' "$state_replacement_blue_container_runtime_sha256"
        printf 'member_a_network_sha256=%s\n' "$ingress_a_network_sha256"
        printf 'member_a_bindings_sha256=%s\n' "$ingress_a_bindings_sha256"
        printf 'member_a_applied_ack_file=%s\n' "$reverse_web_a_applied_ack_root_file"
        printf 'member_a_applied_ack_sha256=%s\n' "$(sha256_file "$reverse_web_a_applied_ack_root_file")"
        printf 'member_a_applied_ack_metadata=%s\n' "$(file_metadata "$reverse_web_a_applied_ack_root_file")"
        printf '%s\n' 'member_b_role=web-b'
        printf 'member_b_name=%s\n' "$replacement_blue_web_b_container"
        printf 'member_b_route_identity=%s\n' "$blue_web_b_route_identity"
        printf 'member_b_id=%s\n' "$state_replacement_blue_web_b_id"
        printf 'member_b_address=%s\n' "$ingress_b_address"
        printf 'member_b_port=%s\n' "$backend_port"
        printf 'member_b_image_reference=%s\n' "$state_replacement_blue_plan_image_reference"
        printf 'member_b_image_id=%s\n' "$state_replacement_blue_plan_image_id"
        printf 'member_b_runtime_sha256=%s\n' "$state_replacement_blue_web_b_container_runtime_sha256"
        printf 'member_b_network_sha256=%s\n' "$ingress_b_network_sha256"
        printf 'member_b_bindings_sha256=%s\n' "$ingress_b_bindings_sha256"
        printf 'member_b_applied_ack_file=%s\n' "$reverse_web_b_applied_ack_root_file"
        printf 'member_b_applied_ack_sha256=%s\n' "$(sha256_file "$reverse_web_b_applied_ack_root_file")"
        printf 'member_b_applied_ack_metadata=%s\n' "$(file_metadata "$reverse_web_b_applied_ack_root_file")"
        printf 'writer_member=%s\n' "$blue_writer_member"
        printf 'pool_label_key=%s\n' "$POOL_LABEL_KEY"
        printf 'pool_label_value=%s\n' "$blue_pool_label_value"
        printf 'member_set_sha256=%s\n' "$ingress_member_set_sha256"
        printf 'route_health_path=%s\n' "$ROUTE_HEALTH_PATH"
        printf 'route_health_token_file=%s\n' "$reverse_route_health_root_file"
        printf 'route_health_token_sha256=%s\n' "$(sha256_file "$reverse_route_health_root_file")"
        printf 'route_health_token_metadata=%s\n' "$(file_metadata "$reverse_route_health_root_file")"
    } > "$ingress_candidate"
    chmod 600 "$ingress_candidate"
    chown "$immutable_uid:$immutable_gid" "$ingress_candidate"
    if [ -e "$reverse_ingress_pool_file" ]; then
        assert_non_symlink_regular_file "$reverse_ingress_pool_file" \
            'reverse ingress pool manifest'
        cmp -s "$ingress_candidate" "$reverse_ingress_pool_file" \
            || fail 'existing reverse ingress pool differs from exact observed members'
        rm -f "$ingress_candidate"
    else
        mv "$ingress_candidate" "$reverse_ingress_pool_file"
    fi
    sync
    state_reverse_ingress_pool_path=$reverse_ingress_pool_file
    state_reverse_ingress_pool_sha256=$(sha256_file "$reverse_ingress_pool_file")
    state_reverse_ingress_pool_uid=$(file_uid "$reverse_ingress_pool_file")
    state_reverse_ingress_pool_gid=$(file_gid "$reverse_ingress_pool_file")
    state_reverse_ingress_pool_mode=$(file_mode "$reverse_ingress_pool_file")
    state_reverse_ingress_pool_size=$(file_size "$reverse_ingress_pool_file")
    [ "$state_reverse_ingress_pool_uid" = "$immutable_uid" ] \
        && [ "$state_reverse_ingress_pool_gid" = "$immutable_gid" ] \
        && [ "$state_reverse_ingress_pool_mode" = 600 ] \
        || fail 'reverse ingress pool metadata is not immutable'
}

prepare_reverse_runtime_fence_artifacts()
{
    [ "$state_phase" = reverse-fence-artifacts-preparing ] \
        || fail 'reverse runtime-fence artifacts require a reserved generation'
    set_reverse_runtime_fence_generation_paths "$state_reverse_fence_generation"
    prepare_reverse_pool_plan
    reverse_https_candidate="${reverse_runtime_fence_https_ack_file}.new.$$"
    reverse_environment_candidate="${reverse_runtime_fence_environment_file}.new.$$"
    [ ! -e "$reverse_https_candidate" ] && [ ! -L "$reverse_https_candidate" ] \
        && [ ! -e "$reverse_environment_candidate" ] && [ ! -L "$reverse_environment_candidate" ] \
        || fail 'reverse runtime-fence artifact candidate already exists'
    assert_file_identity "$blue_ack_snapshot_file" "$state_blue_ack_snapshot_path" \
        "$state_blue_ack_snapshot_sha256" "$state_blue_ack_snapshot_uid" \
        "$state_blue_ack_snapshot_gid" "$state_blue_ack_snapshot_mode" \
        "$state_blue_ack_snapshot_size" 'blue acknowledgement snapshot'
    (umask 077; cp -- "$blue_ack_snapshot_file" "$reverse_https_candidate")
    chmod 600 "$reverse_https_candidate"
    [ "$(sha256_file "$reverse_https_candidate")" = "$state_blue_ack_sha256" ] \
        || fail 'reverse runtime-fence acknowledgement bytes changed before persistence'
    install_immutable_reverse_runtime_fence_artifact "$reverse_https_candidate" \
        "$reverse_runtime_fence_https_ack_file" 'reverse runtime-fence HTTPS acknowledgement'

    {
        cat "$runtime_fence_base_config_file"
        printf 'CONTROL_PLANE_RUNTIME_OPERATION_ID=%s\n' "$state_reverse_fence_operation_id"
        printf 'CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST=%s\n' "$state_reverse_pool_plan_path"
        printf 'CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256=%s\n' \
            "$state_reverse_pool_plan_sha256"
        printf 'CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA=%s:%s:%s:%s\n' \
            "$state_reverse_pool_plan_uid" "$state_reverse_pool_plan_gid" \
            "$state_reverse_pool_plan_mode" "$state_reverse_pool_plan_size"
        printf '%s\n' 'CONTROL_PLANE_RUNTIME_PROVIDER_CAPTURE_EXPECTATION=absent'
    } > "$reverse_environment_candidate"
    chmod 600 "$reverse_environment_candidate"
    install_immutable_reverse_runtime_fence_artifact "$reverse_environment_candidate" \
        "$reverse_runtime_fence_environment_file" 'reverse runtime-fence manifest'
    sync

    state_reverse_fence_environment_sha256=$(sha256_file "$reverse_runtime_fence_environment_file")
    state_reverse_fence_environment_uid=$(file_uid "$reverse_runtime_fence_environment_file")
    state_reverse_fence_environment_gid=$(file_gid "$reverse_runtime_fence_environment_file")
    state_reverse_fence_environment_mode=$(file_mode "$reverse_runtime_fence_environment_file")
    state_reverse_fence_environment_size=$(file_size "$reverse_runtime_fence_environment_file")
    state_reverse_fence_https_ack_sha256=$(sha256_file "$reverse_runtime_fence_https_ack_file")
    state_reverse_fence_https_ack_uid=$(file_uid "$reverse_runtime_fence_https_ack_file")
    state_reverse_fence_https_ack_gid=$(file_gid "$reverse_runtime_fence_https_ack_file")
    state_reverse_fence_https_ack_mode=$(file_mode "$reverse_runtime_fence_https_ack_file")
    state_reverse_fence_https_ack_size=$(file_size "$reverse_runtime_fence_https_ack_file")
    assert_reverse_runtime_fence_artifacts
}

assert_reverse_runtime_fence_artifacts()
{
    assert_runtime_fence_artifacts
    assert_file_identity "$reverse_pool_plan_file" "$state_reverse_pool_plan_path" \
        "$state_reverse_pool_plan_sha256" "$state_reverse_pool_plan_uid" \
        "$state_reverse_pool_plan_gid" "$state_reverse_pool_plan_mode" \
        "$state_reverse_pool_plan_size" 'reverse immutable pool plan'
    [ "$state_reverse_pool_plan_uid" = "$immutable_uid" ] \
        && [ "$state_reverse_pool_plan_gid" = "$immutable_gid" ] \
        && [ "$state_reverse_pool_plan_mode" = 600 ] \
        || fail 'reverse pool plan ownership or mode changed'
    if [ "$state_reverse_ingress_pool_path" != none ]; then
        assert_file_identity "$reverse_ingress_pool_file" \
            "$state_reverse_ingress_pool_path" "$state_reverse_ingress_pool_sha256" \
            "$state_reverse_ingress_pool_uid" "$state_reverse_ingress_pool_gid" \
            "$state_reverse_ingress_pool_mode" "$state_reverse_ingress_pool_size" \
            'reverse immutable ingress pool'
        [ "$state_reverse_ingress_pool_uid" = "$immutable_uid" ] \
            && [ "$state_reverse_ingress_pool_gid" = "$immutable_gid" ] \
            && [ "$state_reverse_ingress_pool_mode" = 600 ] \
            || fail 'reverse ingress pool ownership or mode changed'
    fi
    [ "$state_reverse_fence_provisioner_path" = "$state_runtime_fence_provisioner_path" ] \
        && [ "$state_reverse_fence_provisioner_sha256" = \
            "$state_runtime_fence_provisioner_sha256" ] \
        || fail 'reverse runtime-fence provisioner differs from the pinned forward operator'
    assert_file_identity "$reverse_runtime_fence_environment_file" \
        "$state_reverse_fence_environment_path" "$state_reverse_fence_environment_sha256" \
        "$state_reverse_fence_environment_uid" "$state_reverse_fence_environment_gid" \
        "$state_reverse_fence_environment_mode" "$state_reverse_fence_environment_size" \
        'reverse runtime-fence environment'
    assert_file_identity "$reverse_runtime_fence_https_ack_file" \
        "$state_reverse_fence_https_ack_path" "$state_reverse_fence_https_ack_sha256" \
        "$state_reverse_fence_https_ack_uid" "$state_reverse_fence_https_ack_gid" \
        "$state_reverse_fence_https_ack_mode" "$state_reverse_fence_https_ack_size" \
        'reverse runtime-fence HTTPS acknowledgement'
    [ "$state_reverse_fence_https_ack_sha256" = "$state_blue_ack_snapshot_sha256" ] \
        || fail 'reverse runtime-fence acknowledgements differ from the immutable blue snapshot'
    [ "$state_reverse_fence_environment_uid" = "$operator_uid" ] \
        && [ "$state_reverse_fence_environment_gid" = "$operator_gid" ] \
        && [ "$state_reverse_fence_environment_mode" = 600 ] \
        && [ "$state_reverse_fence_https_ack_uid" = "$operator_uid" ] \
        && [ "$state_reverse_fence_https_ack_gid" = "$operator_gid" ] \
        && [ "$state_reverse_fence_https_ack_mode" = 600 ] \
        || fail 'reverse runtime-fence artifact ownership or mode changed'
    if [ "$state_runtime_fence_provider_header_copy_path" != none ]; then
        [ "$state_reverse_fence_provider_header_path" = \
            "$state_runtime_fence_provider_header_copy_path" ] \
            && [ "$state_reverse_fence_provider_header_sha256" = \
                "$state_runtime_fence_provider_header_copy_sha256" ] \
            && [ "$state_reverse_fence_provider_header_uid" = \
                "$state_runtime_fence_provider_header_copy_uid" ] \
            && [ "$state_reverse_fence_provider_header_gid" = \
                "$state_runtime_fence_provider_header_copy_gid" ] \
            && [ "$state_reverse_fence_provider_header_mode" = \
                "$state_runtime_fence_provider_header_copy_mode" ] \
            && [ "$state_reverse_fence_provider_header_size" = \
                "$state_runtime_fence_provider_header_copy_size" ] \
            || fail 'reverse runtime-fence provider header differs from the stable snapshot'
    else
        [ "$state_reverse_fence_provider_header_path" = none ] \
            && [ "$state_reverse_fence_provider_header_sha256" = none ] \
            && [ "$state_reverse_fence_provider_header_uid" = none ] \
            && [ "$state_reverse_fence_provider_header_gid" = none ] \
            && [ "$state_reverse_fence_provider_header_mode" = none ] \
            && [ "$state_reverse_fence_provider_header_size" = none ] \
            || fail 'reverse runtime-fence provider-header state is inconsistent'
    fi
}

reverse_runtime_fence_call()
{
    assert_reverse_runtime_fence_artifacts
    "$runtime_fence_provisioner" "$@"
}

fence_status_phase()
{
    status_output=$1
    status_phase_count=$(printf '%s\n' "$status_output" | tr ' ' '\n' \
        | grep -E -c '^phase=' || true)
    [ "$status_phase_count" -eq 1 ] \
        || fail 'runtime-fence status did not report exactly one phase'
    printf '%s\n' "$status_output" | tr ' ' '\n' | sed -n 's/^phase=//p'
}

converge_fence_abort_intent()
{
    abort_direction=$1
    case "$abort_direction:$state_phase" in
        forward:fence-abort-intent) abort_terminal_phase=rolled-back ;;
        reverse:reverse-fence-abort-intent) abort_terminal_phase=green-writer-promoted ;;
        *) fail 'runtime-fence abort convergence requires its exact durable operator intent' ;;
    esac

    abort_status_attempt=0
    while :; do
        case "$abort_direction" in
            forward) abort_status=$(runtime_fence_call status) ;;
            reverse) abort_status=$(reverse_runtime_fence_call status) ;;
        esac
        abort_controller_phase=$(fence_status_phase "$abort_status")
        [ "$abort_controller_phase" = abort-in-progress ] || break
        abort_status_attempt=$((abort_status_attempt + 1))
        [ "$abort_status_attempt" -lt "$drain_attempts" ] \
            || fail 'interrupted runtime-fence abort did not converge through its watchdog'
        sleep 1
    done

    case "$abort_controller_phase" in
        preparing|active)
            case "$abort_direction" in
                forward) runtime_fence_call abort ;;
                reverse) reverse_runtime_fence_call abort ;;
            esac
            ;;
        aborted)
            test_crash "after-${abort_direction}-fence-controller-aborted"
            case "$abort_direction" in
                forward) runtime_fence_call abort ;;
                reverse) reverse_runtime_fence_call abort ;;
            esac
            ;;
        finalized)
            ;;
        *)
            fail "runtime-fence abort intent found an unsafe subordinate phase: $abort_controller_phase"
            ;;
    esac
    test_crash "after-${abort_direction}-fence-provisioner-finalization"

    case "$abort_direction" in
        forward) abort_status=$(runtime_fence_call status) ;;
        reverse) abort_status=$(reverse_runtime_fence_call status) ;;
    esac
    abort_controller_phase=$(fence_status_phase "$abort_status")
    case "$abort_controller_phase" in
        aborted|finalized) ;;
        *) fail 'runtime-fence provisioner did not reach an exact abort terminal state' ;;
    esac
    if [ "$abort_direction" = forward ]; then
        preserve_proxy_enrollment_after_state_creation \
            || fail 'forward runtime-fence abort could not retain native Traefik enrollment after restoring the legacy dynamic route'
        test_crash after-forward-proxy-enrollment-preserve
    fi
    state_phase=$abort_terminal_phase
    write_state "$state_phase"
}

begin_fence_abort_intent()
{
    abort_direction=$1
    case "$abort_direction" in
        forward) state_phase=fence-abort-intent ;;
        reverse) state_phase=reverse-fence-abort-intent ;;
        *) fail 'runtime-fence abort intent direction is invalid' ;;
    esac
    write_state "$state_phase"
    test_crash "after-${abort_direction}-fence-abort-intent"
    converge_fence_abort_intent "$abort_direction"
}

complete_reverse_runtime_fence()
{
    case "$state_phase" in
        reverse-fence-prepare-intent)
            reverse_runtime_fence_call prepare "$reverse_runtime_fence_environment_file"
            state_phase=reverse-fence-prepared
            write_state "$state_phase"
            test_crash after-reverse-fence-prepare
            ;;
    esac
    case "$state_phase" in
        reverse-fence-prepared)
            assert_green_state
            assert_container_absent "$replacement_blue_container"
            assert_container_absent "$replacement_blue_web_b_container"
            reverse_runtime_fence_call capture
            state_phase=reverse-fence-captured
            write_state "$state_phase"
            test_crash after-reverse-fence-capture
            ;;
    esac
    case "$state_phase" in
        reverse-fence-captured)
            assert_green_state
            assert_container_absent "$replacement_blue_container"
            assert_container_absent "$replacement_blue_web_b_container"
            reverse_runtime_fence_call arm
            state_phase=reverse-fence-armed
            write_state "$state_phase"
            test_crash after-reverse-fence-arm
            ;;
    esac
    case "$state_phase" in
        reverse-fence-armed)
            reverse_runtime_fence_call verify
            state_phase=reverse-fence-active
            write_state "$state_phase"
            ;;
        reverse-fence-active)
            reverse_runtime_fence_call verify
            ;;
    esac
}

record_source_identity()
{
    state_source_base_path=$source_compose_base
    state_source_base_sha256=$(sha256_file "$source_compose_base")
    state_source_prod_path=$source_compose_prod
    state_source_prod_sha256=$(sha256_file "$source_compose_prod")
    state_source_custom_path=$source_compose_custom
    state_source_custom_sha256=$(optional_file_checksum "$source_compose_custom")
    state_source_postgres_path=$source_compose_postgres
    state_source_postgres_sha256=$(optional_file_checksum "$source_compose_postgres")
    state_source_env_path=$source_env_file
    state_source_env_sha256=$(sha256_file "$source_env_file")
    state_source_env_uid=$(file_uid "$source_env_file")
    state_source_env_gid=$(file_gid "$source_env_file")
    state_source_env_mode=$(file_mode "$source_env_file")
    state_source_env_size=$(file_size "$source_env_file")
    state_source_compose_checksum=$(
        printf '%s\n%s\n%s\n%s\n' \
            "$state_source_base_sha256" "$state_source_prod_sha256" \
            "$state_source_custom_sha256" "$state_source_postgres_sha256" \
            | sha256sum | awk '{print $1}'
    )
    runtime_fence_container_runtime_sha256 "$blue_container" > "$source_runtime_artifact_file"
    state_source_runtime_sha256=$(cat "$source_runtime_artifact_file")
    source_compose config --format json \
        | jq --sort-keys --arg service "$source_compose_service" \
            '.services[$service]' \
        | sha256sum | awk '{print $1}' > "$source_rendered_artifact_file"
    state_source_rendered_sha256=$(cat "$source_rendered_artifact_file")
}

assert_runtime_environment_excludes_writer_faults()
{
    environment_file=$1

    awk '
        /^[[:space:]]*(#|$)/ { next }
        {
            key = $0
            sub(/=.*/, "", key)
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", key)
            sub(/^export[[:space:]]+/, "", key)
            if (key == "CONTROL_PLANE_TEST_PROMOTION_FAIL_AFTER_SERVICE" \
                || key == "CONTROL_PLANE_TEST_FORCE_DOWN_FAILURE") {
                exit 1
            }
        }
    ' "$environment_file" \
        || fail 'production runtime environment contains writer-promotion fault injection'
}

assert_operation_directory_security()
{
    [ -d "$operation_directory" ] && [ ! -L "$operation_directory" ] \
        || fail 'operation directory must be a non-symlink directory'
    [ "$(file_uid "$operation_directory")" = "$operator_uid" ] \
        && [ "$(file_gid "$operation_directory")" = "$operator_gid" ] \
        && [ "$(file_mode "$operation_directory")" = 700 ] \
        || fail 'operation directory ownership or mode changed'
}

assert_runtime_env_artifact()
{
    artifact_path=$1
    expected_path=$2
    expected_sha256=$3
    artifact_name=$4

    [ "$artifact_path" = "$expected_path" ] \
        || fail "$artifact_name path differs from the operation-owned path"
    assert_non_symlink_regular_file "$artifact_path" "$artifact_name"
    [ "$(file_uid "$artifact_path")" = "$runtime_env_uid" ] \
        && [ "$(file_gid "$artifact_path")" = "$runtime_env_gid" ] \
        && [ "$(file_mode "$artifact_path")" = 400 ] \
        && [ "$(file_size "$artifact_path")" = "$state_source_env_size" ] \
        || fail "$artifact_name ownership, mode, or size changed"
    [ "$(sha256_file "$artifact_path")" = "$expected_sha256" ] \
        && [ "$expected_sha256" = "$state_source_env_sha256" ] \
        || fail "$artifact_name bytes differ from the attested source environment"
}

prepare_runtime_env_artifact()
{
    artifact_path=$1
    artifact_name=$2
    artifact_candidate="${artifact_path}.new"

    [ ! -e "$artifact_path" ] && [ ! -L "$artifact_path" ] \
        || fail "$artifact_name already exists before atomic creation"
    [ ! -e "$artifact_candidate" ] && [ ! -L "$artifact_candidate" ] \
        || fail "$artifact_name candidate already exists before atomic creation"
    (umask 077; cp "$source_env_file" "$artifact_candidate")
    chown "$runtime_env_uid:$runtime_env_gid" "$artifact_candidate"
    chmod 400 "$artifact_candidate"
    [ "$(sha256_file "$artifact_candidate")" = "$state_source_env_sha256" ] \
        && [ "$(file_size "$artifact_candidate")" = "$state_source_env_size" ] \
        || fail "$artifact_name candidate bytes differ from the source environment"
    mv "$artifact_candidate" "$artifact_path"
    sync
}

prepare_runtime_env_artifacts()
{
    state_green_runtime_env_path=$green_runtime_env_file
    state_blue_runtime_env_path=$blue_runtime_env_file
    prepare_runtime_env_artifact "$green_runtime_env_file" 'green runtime environment artifact'
    prepare_runtime_env_artifact "$blue_runtime_env_file" 'blue runtime environment artifact'
    state_green_runtime_env_sha256=$(sha256_file "$green_runtime_env_file")
    state_blue_runtime_env_sha256=$(sha256_file "$blue_runtime_env_file")
    assert_runtime_env_artifact "$green_runtime_env_file" "$state_green_runtime_env_path" \
        "$state_green_runtime_env_sha256" 'green runtime environment artifact'
    assert_runtime_env_artifact "$blue_runtime_env_file" "$state_blue_runtime_env_path" \
        "$state_blue_runtime_env_sha256" 'blue runtime environment artifact'
}

reset_uncommitted_operation_directory()
{
    for generated_path in \
        "$green_runtime_env_file" "$green_runtime_env_file.new" \
        "$blue_runtime_env_file" "$blue_runtime_env_file.new" \
        "$green_restart_policy_intent_file" "$green_restart_policy_intent_file.new" \
        "$replacement_blue_restart_policy_intent_file" \
        "$replacement_blue_restart_policy_intent_file.new" \
        "$green_ack_snapshot_file" "$green_ack_snapshot_file.new" \
        "$blue_ack_snapshot_file" "$blue_ack_snapshot_file.new" \
        "$source_runtime_artifact_file" "$source_rendered_artifact_file" \
        "$runtime_fence_base_config_file" "$runtime_fence_base_config_file.new" \
        "$runtime_fence_environment_file" "$runtime_fence_environment_file.new" \
        "$runtime_fence_https_ack_file" "$runtime_fence_https_ack_file.new" \
        "$runtime_fence_provider_header_copy" "$runtime_fence_provider_header_copy.new"
    do
        if [ -e "$generated_path" ] || [ -L "$generated_path" ]; then
            [ -f "$generated_path" ] && [ ! -L "$generated_path" ] \
                || fail 'uncommitted operation directory contains a non-regular generated path'
            rm -f -- "$generated_path"
        fi
    done
    rmdir "$operation_directory" 2>/dev/null \
        || fail "incomplete operation directory contains unknown state: $operation_id"
}

record_host_release_identity()
{
    verify_release_manifest
    state_release_id=$release_id
    state_release_manifest_path=$release_manifest_file
    state_release_manifest_sha256=$release_manifest_observed_sha256
    state_release_manifest_uid=$(file_uid "$release_manifest_file")
    state_release_manifest_gid=$(file_gid "$release_manifest_file")
    state_release_manifest_mode=$(file_mode "$release_manifest_file")
    state_release_manifest_size=$(file_size "$release_manifest_file")
    state_release_assets_sha256=$release_assets_sha256

    state_operator_path=$(release_manifest_asset_field operator 3)
    state_operator_sha256=$(release_manifest_asset_field operator 4)
    state_operator_uid=$(release_manifest_asset_field operator 5)
    state_operator_gid=$(release_manifest_asset_field operator 6)
    state_operator_mode=$(release_manifest_asset_field operator 7)
    state_operator_size=$(file_size "$state_operator_path")
    state_operator_compose_path=$(release_manifest_asset_field operator-compose 3)
    state_operator_compose_sha256=$(release_manifest_asset_field operator-compose 4)
    state_operator_compose_uid=$(release_manifest_asset_field operator-compose 5)
    state_operator_compose_gid=$(release_manifest_asset_field operator-compose 6)
    state_operator_compose_mode=$(release_manifest_asset_field operator-compose 7)
    state_operator_compose_size=$(file_size "$state_operator_compose_path")
    state_rehearsal_compose_path=$(release_manifest_asset_field rehearsal-compose 3)
    state_rehearsal_compose_sha256=$(release_manifest_asset_field rehearsal-compose 4)
    state_rehearsal_compose_uid=$(release_manifest_asset_field rehearsal-compose 5)
    state_rehearsal_compose_gid=$(release_manifest_asset_field rehearsal-compose 6)
    state_rehearsal_compose_mode=$(release_manifest_asset_field rehearsal-compose 7)
    state_rehearsal_compose_size=$(file_size "$state_rehearsal_compose_path")
}

assert_operator_configuration_identity()
{
    verify_release_manifest
    [ "$state_release_id" = "$release_id" ] \
        && [ "$state_release_manifest_path" = "$release_manifest_file" ] \
        && [ "$state_release_manifest_sha256" = "$release_manifest_observed_sha256" ] \
        && [ "$state_release_manifest_uid" = "$(file_uid "$release_manifest_file")" ] \
        && [ "$state_release_manifest_gid" = "$(file_gid "$release_manifest_file")" ] \
        && [ "$state_release_manifest_mode" = "$(file_mode "$release_manifest_file")" ] \
        && [ "$state_release_manifest_size" = "$(file_size "$release_manifest_file")" ] \
        && [ "$state_release_assets_sha256" = "$release_assets_sha256" ] \
        || fail 'host release manifest identity changed during this operation'
    assert_file_identity "$OPERATOR_PATH" "$state_operator_path" "$state_operator_sha256" \
        "$state_operator_uid" "$state_operator_gid" "$state_operator_mode" \
        "$state_operator_size" 'executing control-plane operator'
    assert_file_identity "$operator_compose_file" "$state_operator_compose_path" \
        "$state_operator_compose_sha256" "$state_operator_compose_uid" \
        "$state_operator_compose_gid" "$state_operator_compose_mode" \
        "$state_operator_compose_size" 'candidate Compose file'
    assert_file_identity "$release_rehearsal_compose_file" "$state_rehearsal_compose_path" \
        "$state_rehearsal_compose_sha256" "$state_rehearsal_compose_uid" \
        "$state_rehearsal_compose_gid" "$state_rehearsal_compose_mode" \
        "$state_rehearsal_compose_size" 'migration-rehearsal Compose file'
}

assert_source_identity()
{
    assert_operator_configuration_identity
    assert_operation_directory_security
    assert_secret_file_metadata "$green_direct_probe_token_file" \
        CONTROL_PLANE_GREEN_WEB_A_DIRECT_PROBE_RUNTIME_FILE
    assert_secret_file_metadata "$green_applied_ack_file" \
        CONTROL_PLANE_GREEN_WEB_A_APPLIED_ACK_RUNTIME_FILE
    assert_secret_file_metadata "$green_web_b_direct_probe_token_file" \
        CONTROL_PLANE_GREEN_WEB_B_DIRECT_PROBE_RUNTIME_FILE
    assert_secret_file_metadata "$green_web_b_applied_ack_file" \
        CONTROL_PLANE_GREEN_WEB_B_APPLIED_ACK_RUNTIME_FILE
    assert_secret_file_metadata "$green_route_health_token_file" \
        CONTROL_PLANE_GREEN_ROUTE_HEALTH_RUNTIME_FILE
    assert_secret_file_metadata "$green_pool_ack_file" CONTROL_PLANE_GREEN_POOL_ACK_RUNTIME_FILE
    assert_secret_file_metadata "$blue_direct_probe_token_file" \
        CONTROL_PLANE_BLUE_WEB_A_DIRECT_PROBE_RUNTIME_FILE
    assert_secret_file_metadata "$blue_applied_ack_file" CONTROL_PLANE_BLUE_WEB_A_APPLIED_ACK_RUNTIME_FILE
    assert_secret_file_metadata "$blue_web_b_direct_probe_token_file" \
        CONTROL_PLANE_BLUE_WEB_B_DIRECT_PROBE_RUNTIME_FILE
    assert_secret_file_metadata "$blue_web_b_applied_ack_file" \
        CONTROL_PLANE_BLUE_WEB_B_APPLIED_ACK_RUNTIME_FILE
    assert_secret_file_metadata "$blue_route_health_token_file" \
        CONTROL_PLANE_BLUE_ROUTE_HEALTH_RUNTIME_FILE
    assert_secret_file_metadata "$blue_pool_ack_file" CONTROL_PLANE_BLUE_POOL_ACK_RUNTIME_FILE
    assert_non_symlink_regular_file "$source_compose_base" CONTROL_PLANE_SOURCE_COMPOSE_BASE
    assert_non_symlink_regular_file "$source_compose_prod" CONTROL_PLANE_SOURCE_COMPOSE_PROD
    assert_non_symlink_regular_file "$source_env_file" CONTROL_PLANE_SOURCE_ENV_FILE
    [ "$source_compose_base" = "$state_source_base_path" ] \
        && [ "$(sha256_file "$source_compose_base")" = "$state_source_base_sha256" ] \
        || fail 'base source compose identity changed during this operation'
    [ "$source_compose_prod" = "$state_source_prod_path" ] \
        && [ "$(sha256_file "$source_compose_prod")" = "$state_source_prod_sha256" ] \
        || fail 'production source compose identity changed during this operation'
    [ "$source_compose_custom" = "$state_source_custom_path" ] \
        && [ "$(optional_file_checksum "$source_compose_custom")" = "$state_source_custom_sha256" ] \
        || fail 'custom source compose identity changed during this operation'
    [ "$source_compose_postgres" = "$state_source_postgres_path" ] \
        && [ "$(optional_file_checksum "$source_compose_postgres")" = "$state_source_postgres_sha256" ] \
        || fail 'PostgreSQL-upgrade source compose identity changed during this operation'
    [ "$source_env_file" = "$state_source_env_path" ] \
        && [ "$(sha256_file "$source_env_file")" = "$state_source_env_sha256" ] \
        && [ "$(file_uid "$source_env_file")" = "$state_source_env_uid" ] \
        && [ "$(file_gid "$source_env_file")" = "$state_source_env_gid" ] \
        && [ "$(file_mode "$source_env_file")" = "$state_source_env_mode" ] \
        && [ "$(file_size "$source_env_file")" = "$state_source_env_size" ] \
        || fail 'source environment checksum or metadata changed during this operation'
    assert_runtime_env_artifact "$green_runtime_env_file" "$state_green_runtime_env_path" \
        "$state_green_runtime_env_sha256" 'green runtime environment artifact'
    assert_runtime_env_artifact "$blue_runtime_env_file" "$state_blue_runtime_env_path" \
        "$state_blue_runtime_env_sha256" 'blue runtime environment artifact'
    assert_regular_file "$source_runtime_artifact_file" 'persisted runtime-fence v4 source identity artifact'
    [ "$(cat "$source_runtime_artifact_file")" = "$state_source_runtime_sha256" ] \
        || fail 'persisted runtime-fence v4 source identity artifact changed'
    docker_container_presence "$blue_container"
    if [ "$container_presence" = present ]; then
        [ "$(runtime_fence_container_runtime_sha256 "$blue_container")" = \
            "$state_source_runtime_sha256" ] \
            || fail 'source container no longer matches its runtime-fence v4 identity'
    fi
    assert_regular_file "$source_rendered_artifact_file" 'persisted rendered source compose artifact'
    [ "$(cat "$source_rendered_artifact_file")" = "$state_source_rendered_sha256" ] \
        || fail 'persisted rendered source compose hash artifact changed'
    source_rendered_candidate="$operation_directory/.source-rendered-verify.$$"
    source_compose config --format json \
        | jq --sort-keys --arg service "$source_compose_service" \
            '.services[$service]' \
        | sha256sum | awk '{print $1}' > "$source_rendered_candidate"
    [ "$(cat "$source_rendered_candidate")" = "$state_source_rendered_sha256" ] \
        || fail 'rendered source compose service changed during this operation'
    rm -f "$source_rendered_candidate"
    assert_file_identity "$green_applied_ack_file" "$state_green_ack_path" \
        "$state_green_ack_sha256" "$state_green_ack_uid" "$state_green_ack_gid" \
        "$state_green_ack_mode" "$state_green_ack_size" 'green acknowledgement source'
    assert_file_identity "$blue_applied_ack_file" "$state_blue_ack_path" \
        "$state_blue_ack_sha256" "$state_blue_ack_uid" "$state_blue_ack_gid" \
        "$state_blue_ack_mode" "$state_blue_ack_size" 'blue acknowledgement source'
    [ "$green_direct_probe_token_file" = "$state_green_probe_token_path" ] \
        && [ "$(sha256_file "$green_direct_probe_token_file")" = "$state_green_probe_token_sha256" ] \
        && [ "$(file_uid "$green_direct_probe_token_file")" = "$state_green_probe_token_uid" ] \
        && [ "$(file_gid "$green_direct_probe_token_file")" = "$state_green_probe_token_gid" ] \
        && [ "$(file_mode "$green_direct_probe_token_file")" = "$state_green_probe_token_mode" ] \
        && [ "$(file_size "$green_direct_probe_token_file")" = "$state_green_probe_token_size" ] \
        || fail 'green direct-probe token identity or metadata changed during this operation'
    [ "$blue_direct_probe_token_file" = "$state_blue_probe_token_path" ] \
        && [ "$(sha256_file "$blue_direct_probe_token_file")" = "$state_blue_probe_token_sha256" ] \
        && [ "$(file_uid "$blue_direct_probe_token_file")" = "$state_blue_probe_token_uid" ] \
        && [ "$(file_gid "$blue_direct_probe_token_file")" = "$state_blue_probe_token_gid" ] \
        && [ "$(file_mode "$blue_direct_probe_token_file")" = "$state_blue_probe_token_mode" ] \
        && [ "$(file_size "$blue_direct_probe_token_file")" = "$state_blue_probe_token_size" ] \
        || fail 'blue direct-probe token identity or metadata changed during this operation'
    [ "$(sha256_file "$ingress_controller")" = "$state_ingress_controller_sha256" ] \
        || fail 'Traefik ingress controller identity changed during this operation'
}

assert_common_state_identity()
{
    assert_proxy_enrollment_active
    assert_source_identity
    [ "$(container_id "$proxy_container")" = "$state_proxy_id" ] \
        || fail 'proxy container identity changed during this operation'
    [ "$(container_image_id "$proxy_container")" = "$state_proxy_image_id" ] \
        || fail 'proxy image identity changed during this operation'
    assert_proxy_identity
    assert_proxy_dynamic_mount
}

assert_blue_state()
{
    assert_container_running "$blue_container"
    [ "$(container_id "$blue_container")" = "$state_blue_id" ] \
        || fail 'blue container identity changed during this operation'
    [ "$(container_image_id "$blue_container")" = "$state_blue_image_id" ] \
        || fail 'blue image identity changed during this operation'
    [ "$(container_image_reference "$blue_container")" = "$state_blue_image_reference" ] \
        || fail 'blue image reference changed during this operation'
    [ "$(container_label "$blue_container" com.docker.compose.project)" = "$state_blue_compose_project" ] \
        || fail 'blue compose project identity changed during this operation'
    [ "$(container_label "$blue_container" com.docker.compose.service)" = "$state_blue_compose_service" ] \
        || fail 'blue compose service identity changed during this operation'
    [ "$(container_label "$blue_container" com.docker.compose.project.config_files)" = "$state_blue_compose_config_files" ] \
        || fail 'blue ordered compose invocation changed during this operation'
}

assert_green_state()
{
    assert_container_running "$green_container"
    assert_container_running "$green_web_b_container"
    [ "$(container_id "$green_container")" = "$state_green_id" ] \
        && [ "$(container_id "$green_web_b_container")" = "$state_green_web_b_id" ] \
        || fail 'green pool container identity changed during this operation'
    [ "$(container_image_id "$green_container")" = "$state_green_image_id" ] \
        && [ "$(container_image_id "$green_web_b_container")" = \
            "$state_green_web_b_image_id" ] \
        || fail 'green pool image identity changed during this operation'
    [ "$(container_image_reference "$green_container")" = "$state_green_plan_image_reference" ] \
        && [ "$(container_image_reference "$green_web_b_container")" = \
            "$state_green_plan_image_reference" ] \
        && [ "$state_green_image_id" = "$state_green_plan_image_id" ] \
        && [ "$state_green_web_b_image_id" = "$state_green_plan_image_id" ] \
        && docker inspect "$green_container" | jq --exit-status --arg network "$control_plane_network" \
            '.[0].NetworkSettings.Networks | keys == [$network]' >/dev/null \
        && docker inspect "$green_web_b_container" \
            | jq --exit-status --arg network "$control_plane_network" \
                '.[0].NetworkSettings.Networks | keys == [$network]' >/dev/null \
        && [ "$(docker inspect "$green_container" | jq -r --arg network "$control_plane_network" \
            '.[0].NetworkSettings.Networks[$network].NetworkID')" = "$state_green_plan_network_id" ] \
        && [ "$(docker inspect "$green_web_b_container" \
            | jq -r --arg network "$control_plane_network" \
                '.[0].NetworkSettings.Networks[$network].NetworkID')" = \
            "$state_green_plan_network_id" ] \
        && [ "$(container_label "$green_container" "$POOL_LABEL_KEY")" = \
            "$green_pool_label_value" ] \
        && [ "$(container_label "$green_web_b_container" "$POOL_LABEL_KEY")" = \
            "$green_pool_label_value" ] \
        || fail 'green pool no longer matches its immutable pre-start plan'
    case "$state_green_container_runtime_sha256" in
        none)
            [ "$state_phase" = green-started-unproven ] \
                && [ "$state_green_web_b_container_runtime_sha256" = none ] \
                || fail 'green pool runtime identity is absent outside denial proof'
            ;;
        *)
            [ "$state_green_web_b_container_runtime_sha256" != none ] \
                || fail 'green pool has only one pinned runtime identity'
            [ "$(runtime_fence_container_runtime_sha256 "$green_container")" = \
                "$state_green_container_runtime_sha256" ] \
                && [ "$(runtime_fence_container_runtime_sha256 "$green_web_b_container")" = \
                    "$state_green_web_b_container_runtime_sha256" ] \
                || fail 'green pool runtime identity changed after denial proof'
            ;;
    esac
    assert_candidate_restart_policy_state green
}

assert_replacement_blue_state()
{
    assert_container_running "$replacement_blue_container"
    assert_container_running "$replacement_blue_web_b_container"
    [ "$(container_id "$replacement_blue_container")" = "$state_replacement_blue_id" ] \
        && [ "$(container_id "$replacement_blue_web_b_container")" = \
            "$state_replacement_blue_web_b_id" ] \
        || fail 'replacement blue pool container identity changed during this operation'
    [ "$(container_image_id "$replacement_blue_container")" = "$state_replacement_blue_image_id" ] \
        && [ "$(container_image_id "$replacement_blue_web_b_container")" = \
            "$state_replacement_blue_web_b_image_id" ] \
        || fail 'replacement blue pool image identity changed during this operation'
    [ "$(container_image_reference "$replacement_blue_container")" = "$state_replacement_blue_plan_image_reference" ] \
        && [ "$(container_image_reference "$replacement_blue_web_b_container")" = \
            "$state_replacement_blue_plan_image_reference" ] \
        && [ "$state_replacement_blue_image_id" = "$state_replacement_blue_plan_image_id" ] \
        && [ "$state_replacement_blue_web_b_image_id" = \
            "$state_replacement_blue_plan_image_id" ] \
        && docker inspect "$replacement_blue_container" \
            | jq --exit-status --arg network "$control_plane_network" \
                '.[0].NetworkSettings.Networks | keys == [$network]' >/dev/null \
        && docker inspect "$replacement_blue_web_b_container" \
            | jq --exit-status --arg network "$control_plane_network" \
                '.[0].NetworkSettings.Networks | keys == [$network]' >/dev/null \
        && [ "$(docker inspect "$replacement_blue_container" | jq -r --arg network "$control_plane_network" \
            '.[0].NetworkSettings.Networks[$network].NetworkID')" = "$state_replacement_blue_plan_network_id" ] \
        && [ "$(docker inspect "$replacement_blue_web_b_container" \
            | jq -r --arg network "$control_plane_network" \
                '.[0].NetworkSettings.Networks[$network].NetworkID')" = \
            "$state_replacement_blue_plan_network_id" ] \
        && [ "$(container_label "$replacement_blue_container" "$POOL_LABEL_KEY")" = \
            "$blue_pool_label_value" ] \
        && [ "$(container_label "$replacement_blue_web_b_container" "$POOL_LABEL_KEY")" = \
            "$blue_pool_label_value" ] \
        || fail 'replacement blue pool no longer matches its immutable pre-start plan'
    case "$state_replacement_blue_container_runtime_sha256" in
        none)
            [ "$state_phase" = failback-blue-started-unproven ] \
                && [ "$state_replacement_blue_web_b_container_runtime_sha256" = none ] \
                || fail 'replacement blue pool runtime identity is absent outside denial proof'
            ;;
        *)
            [ "$state_replacement_blue_web_b_container_runtime_sha256" != none ] \
                || fail 'replacement blue pool has only one pinned runtime identity'
            [ "$(runtime_fence_container_runtime_sha256 "$replacement_blue_container")" = \
                "$state_replacement_blue_container_runtime_sha256" ] \
                && [ "$(runtime_fence_container_runtime_sha256 \
                    "$replacement_blue_web_b_container")" = \
                    "$state_replacement_blue_web_b_container_runtime_sha256" ] \
                || fail 'replacement blue pool runtime identity changed after denial proof'
            ;;
    esac
    assert_candidate_restart_policy_state blue
}

reconcile_replacement_blue_state()
{
    assert_container_identity_present "$replacement_blue_container" \
        "$state_replacement_blue_id" "$state_replacement_blue_image_id"
    assert_container_identity_present "$replacement_blue_web_b_container" \
        "$state_replacement_blue_web_b_id" "$state_replacement_blue_web_b_image_id"
    for replacement_member_id in \
        "$state_replacement_blue_id" "$state_replacement_blue_web_b_id"; do
        if [ "$(container_is_running "$replacement_member_id")" != true ]; then
            docker start "$replacement_member_id" \
                >> "$operation_directory/replacement-blue-restart.log" 2>&1
        fi
    done
    assert_replacement_blue_state
    wait_for_container_health "$replacement_blue_container"
    wait_for_container_health "$replacement_blue_web_b_container"
}

acquire_lock()
{
    if [ "${global_transaction_lock_held:-0}" = 1 ]; then
        return
    fi
    umask 077
    lock_directory=$(dirname -- "$lock_file")
    [ -d "$lock_directory" ] && [ ! -L "$lock_directory" ] \
        || fail "operator lock directory is not a non-symlink directory: $lock_directory"
    if ! is_test_mode; then
        [ "$(id -u)" = 0 ] \
            && [ "$(file_uid "$lock_directory")" = 0 ] \
            && [ "$(file_gid "$lock_directory")" = 0 ] \
            || fail 'production operator lock requires a root-owned execution and directory'
    fi
    [ ! -e "$lock_file" ] || { [ -f "$lock_file" ] && [ ! -L "$lock_file" ]; } \
        || fail "operator lock path is not a non-symlink regular file: $lock_file"
    exec 9> "$lock_file"
    chmod 600 "$lock_file"
    if ! is_test_mode; then
        [ "$(file_uid "$lock_file")" = 0 ] && [ "$(file_gid "$lock_file")" = 0 ] \
            && [ "$(file_mode "$lock_file")" = 600 ] \
            || fail 'production operator lock ownership or mode is unsafe'
    fi
    flock -n 9 || fail "another control-plane operation holds the lock: $lock_file"
    global_transaction_lock_held=1
    trap release_lock EXIT HUP INT TERM

    if is_test_mode && [ "${CONTROL_PLANE_TEST_HOLD_LOCK_SECONDS:-0}" != 0 ]; then
        validate_positive_integer "${CONTROL_PLANE_TEST_HOLD_LOCK_SECONDS}" CONTROL_PLANE_TEST_HOLD_LOCK_SECONDS
        sleep "${CONTROL_PLANE_TEST_HOLD_LOCK_SECONDS}"
    fi
}

release_lock()
{
    cleanup_migration_candidates
    if [ "${migration_blue_quiesced:-0}" = 1 ] \
        && [ "${migration_reconciliation_api_unavailable:-0}" != 1 ] \
        && container_is_running "$blue_container"; then
        if resume_legacy_blue_background; then
            migration_blue_quiesced=0
        else
            note "legacy-blue-background-resume-failed operation=$operation_id"
        fi
    fi
    flock -u 9 >/dev/null 2>&1 || true
    exec 9>&-
    global_transaction_lock_held=0
}

cleanup_migration_candidates()
{
    for migration_candidate in \
        "${migration_names_candidate:-}" \
        "${authorized_candidate:-}" \
        "${ledger_candidate:-}" \
        "${database_identity_candidate:-}" \
        "${relfilenode_candidate:-}" \
        "${row_counts_candidate:-}" \
        "${schema_candidate:-}" \
        "${schema_after_candidate:-}" \
        "${schema_guard_candidate:-}" \
        "${pending_candidate:-}" \
        "${ledger_after_candidate:-}" \
        "${classification_ledger_candidate:-}" \
        "${classification_schema_candidate:-}" \
        "${classification_relfilenode_candidate:-}" \
        "${classification_schema_guard_candidate:-}" \
        "${source_rendered_candidate:-}" \
        "${replacement_schema_candidate:-}" \
        "${relfilenode_after_candidate:-}" \
        "${row_counts_after_candidate:-}" \
        "${baseline_surplus_candidate:-}" \
        "${fingerprint_candidate:-}" \
        "${migration_inventory_candidate:-}" \
        "${verification_candidate:-}"
    do
        [ -z "$migration_candidate" ] || rm -f "$migration_candidate"
    done
}

migration_ledger_matches_controlled_outcome()
{
    controlled_ledger_file=$1
    expected_ledger_outcome=$2

    if [ "$expected_ledger_outcome" = unchanged ]; then
        [ "$(sha256_file "$controlled_ledger_file")" = "$state_migration_ledger_before_sha256" ]
        return $?
    fi
    [ "$expected_ledger_outcome" = applied ] || return 1

    awk -F, \
        -v pre_file="$migration_ledger_before_file" \
        -v pending_file="$migration_pending_file" \
        -v expected_batch="$state_migration_expected_batch" \
        -v current_file="$controlled_ledger_file" '
        FILENAME == pre_file {
            if (NF != 2 || $1 == "" || $2 !~ /^[1-9][0-9]*$/ || pre_seen[$1]++) { exit 1 }
            pre_batch[$1] = $2
            next
        }
        FILENAME == pending_file {
            if (NF != 1 || $1 == "" || pending[$1]++) { exit 1 }
            next
        }
        FILENAME == current_file {
            if (NF != 2 || $1 == "" || $2 !~ /^[1-9][0-9]*$/ || current_seen[$1]++) { exit 1 }
            if ($1 in pre_batch) {
                if ($2 != pre_batch[$1]) { exit 1 }
                preserved[$1] = 1
                next
            }
            if (($1 in pending) && $2 == expected_batch) {
                controlled[$1] = 1
                next
            }
            exit 1
        }
        END {
            for (migration in pre_batch) {
                if (!(migration in preserved)) { exit 1 }
            }
            for (migration in pending) {
                if (!(migration in controlled)) { exit 1 }
            }
        }
    ' "$migration_ledger_before_file" "$migration_pending_file" "$controlled_ledger_file"
}

validate_controlled_migration_ledger()
{
    controlled_ledger_file=$1
    expected_ledger_outcome=$2
    assert_regular_file "$controlled_ledger_file" 'controlled migration ledger snapshot'
    validate_migration_ledger_inventory "$controlled_ledger_file"
    migration_ledger_matches_controlled_outcome "$controlled_ledger_file" \
        "$expected_ledger_outcome" \
        || fail "migration ledger does not match the exact $expected_ledger_outcome controlled outcome"
}

test_crash()
{
    crash_point=$1

    if is_test_mode && [ "${CONTROL_PLANE_TEST_CRASH_AT:-}" = "$crash_point" ]; then
        kill -KILL "$$"
    fi
}

assert_proxy_dynamic_mount()
{
    mounted_dynamic_directory=$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/traefik/dynamic"}}{{.Source}}{{end}}{{end}}' "$proxy_container")
    [ -n "$mounted_dynamic_directory" ] || fail "proxy does not mount $PROXY_DYNAMIC_CONTAINER_PATH"
    [ "$(canonical_directory "$mounted_dynamic_directory")" = "$(canonical_directory "$traefik_dynamic_directory")" ] \
        || fail 'configured Traefik dynamic directory is not mounted by the exact proxy container'
}

marker_status()
{
    private_volume=$1
    expected_epoch=$2
    marker_filename=${3:-writer-epoch}

    case "$marker_filename" in
        writer-epoch|web-epoch|route-drain-epoch)
            marker_path="/private/${marker_filename}"
            ;;
        mutation-freeze-epoch)
            marker_path=/coordination/mutation-freeze-epoch
            ;;
        *)
            fail "unsupported control-plane marker filename: $marker_filename"
            ;;
    esac

    docker run --rm --network none \
        --mount "type=volume,source=${private_volume},target=/private,readonly" \
        --mount "type=volume,source=${coordination_volume},target=/coordination,readonly" \
        --env "CONTROL_PLANE_EXPECTED_EPOCH=$expected_epoch" \
        --env "CONTROL_PLANE_MARKER_PATH=$marker_path" \
        --entrypoint /bin/sh "$green_image" -ec '
            test ! -L /private
            test -d /private
            test "$(stat -c %u:%g:%a /private)" = 0:9999:750
            test ! -L /coordination
            test -d /coordination
            test "$(stat -c %u:%g:%a /coordination)" = 0:9999:750

            lease=/coordination/mutation-inflight.lock
            test ! -L "$lease"
            test -f "$lease"
            test "$(stat -c %u:%g:%a:%h:%s "$lease")" = 0:9999:660:1:0
            lease_path_identity=$(stat -c %d:%i "$lease")
            exec 8< "$lease"
            lease_fd_identity=$(stat -Lc %d:%i /proc/self/fd/8)
            test "$lease_path_identity" = "$lease_fd_identity"

            marker=$CONTROL_PLANE_MARKER_PATH
            if [ ! -e "$marker" ] && [ ! -L "$marker" ]; then
                test "$(stat -c %d:%i "$lease")" = "$lease_path_identity"
                printf "%s\\n" absent
                exit 0
            fi
            test ! -L "$marker"
            test -f "$marker"
            test "$(stat -c %u:%g:%a:%h "$marker")" = 0:9999:440:1
            marker_path_identity=$(stat -c %d:%i "$marker")
            exec 9< "$marker"
            marker_fd_identity=$(stat -Lc %d:%i /proc/self/fd/9)
            test "$marker_path_identity" = "$marker_fd_identity"
            marker_size=$(stat -Lc %s /proc/self/fd/9)
            marker_bytes=$(cat <&9)
            test "$(stat -c %d:%i "$marker")" = "$marker_path_identity"
            test "$(stat -c %d:%i "$lease")" = "$lease_path_identity"
            if [ "$marker_size" = "${#CONTROL_PLANE_EXPECTED_EPOCH}" ] \
                && [ "$marker_bytes" = "$CONTROL_PLANE_EXPECTED_EPOCH" ]; then
                printf "%s\\n" matching
            else
                printf "%s\\n" mismatched
            fi
        '
}

assert_fresh_marker_volume()
{
    color_volume=$1
    color_name=$2
    color_epoch=$3
    marker_filename=${4:-writer-epoch}

    [ "$(marker_status "$color_volume" "$color_epoch" "$marker_filename")" = absent ] \
        || fail "$color_name marker volume contains a stale or already-authorizing $marker_filename marker"
}

revoke_marker()
{
    private_volume=$1
    color_name=$2
    color_epoch=$3
    expected_marker_state=$4
    marker_filename=${5:-writer-epoch}
    case "$marker_filename" in
        writer-epoch|web-epoch|route-drain-epoch)
            marker_path="/private/${marker_filename}"
            marker_directory=/private
            ;;
        mutation-freeze-epoch)
            marker_path=/coordination/mutation-freeze-epoch
            marker_directory=/coordination
            ;;
        *) fail "unsupported control-plane marker filename: $marker_filename" ;;
    esac
    observed_marker_state=$(marker_status "$private_volume" "$color_epoch" "$marker_filename")

    case "${expected_marker_state}:${observed_marker_state}" in
        absent:absent)
            return
            ;;
        matching:absent)
            ;;
        matching:matching)
            ;;
        *)
            fail "$color_name marker state is not safe to revoke"
            ;;
    esac

    marker_container_crash_point=
    if is_test_mode; then
        marker_container_crash_point=${CONTROL_PLANE_TEST_CRASH_AT:-}
    fi

    docker run --rm --user 0 --network none \
        --mount "type=volume,source=${private_volume},target=/private" \
        --mount "type=volume,source=${coordination_volume},target=/coordination" \
        --env "CONTROL_PLANE_EXPECTED_EPOCH=$color_epoch" \
        --env "CONTROL_PLANE_MARKER_PATH=$marker_path" \
        --env "CONTROL_PLANE_MARKER_DIRECTORY=$marker_directory" \
        --env "CONTROL_PLANE_MARKER_OBSERVED_STATE=$observed_marker_state" \
        --env "CONTROL_PLANE_MARKER_CRASH_POINT=$marker_container_crash_point" \
        --env "CONTROL_PLANE_MARKER_EXPECTED_CRASH_POINT=after-${color_name}-${marker_filename}-unlink" \
        --entrypoint /bin/sh "$green_image" -ec '
            test "$(id -u)" = 0
            test "$(stat -c %u:%g:%a /private)" = 0:9999:750
            test "$(stat -c %u:%g:%a /coordination)" = 0:9999:750
            lease=/coordination/mutation-inflight.lock
            test ! -L "$lease"
            test -f "$lease"
            test "$(stat -c %u:%g:%a:%h:%s "$lease")" = 0:9999:660:1:0
            lease_identity=$(stat -c %d:%i "$lease")
            exec 8<> "$lease"
            test "$(stat -Lc %d:%i /proc/self/fd/8)" = "$lease_identity"
            flock -x 8
            test "$(stat -c %d:%i "$lease")" = "$lease_identity"
            marker=$CONTROL_PLANE_MARKER_PATH
            if [ "$CONTROL_PLANE_MARKER_OBSERVED_STATE" = matching ]; then
                test ! -L "$marker"
                test -f "$marker"
                test "$(stat -c %u:%g:%a:%h "$marker")" = 0:9999:440:1
                marker_identity=$(stat -c %d:%i "$marker")
                exec 9< "$marker"
                test "$(stat -Lc %d:%i /proc/self/fd/9)" = "$marker_identity"
                test "$(stat -Lc %s /proc/self/fd/9)" = "${#CONTROL_PLANE_EXPECTED_EPOCH}"
                test "$(cat <&9)" = "$CONTROL_PLANE_EXPECTED_EPOCH"
                test "$(stat -c %d:%i "$marker")" = "$marker_identity"
                rm -f "$marker"
                test ! -e "$marker"
                test ! -L "$marker"
                if [ "$CONTROL_PLANE_MARKER_CRASH_POINT" = \
                    "$CONTROL_PLANE_MARKER_EXPECTED_CRASH_POINT" ]; then
                    exit 137
                fi
            else
                test ! -e "$marker"
                test ! -L "$marker"
            fi
            sync "$CONTROL_PLANE_MARKER_DIRECTORY"
            test "$(stat -c %d:%i "$lease")" = "$lease_identity"
        ' >/dev/null

    [ "$(marker_status "$private_volume" "$color_epoch" "$marker_filename")" = absent ] \
        || fail "$color_name marker revocation was not durable"
    test_crash "after-${color_name}-${marker_filename}-revoked"
}

activate_mutation_freeze_marker()
{
    private_volume=$1
    color_name=$2
    color_epoch=$3
    marker_container_crash_point=
    mutation_freeze_exclusive_lease_hold_seconds=0

    if is_test_mode; then
        marker_container_crash_point=${CONTROL_PLANE_TEST_CRASH_AT:-}
        mutation_freeze_exclusive_lease_hold_seconds=${CONTROL_PLANE_TEST_MUTATION_FREEZE_EXCLUSIVE_LEASE_HOLD_SECONDS:-0}
        validate_nonnegative_integer "$mutation_freeze_exclusive_lease_hold_seconds" \
            CONTROL_PLANE_TEST_MUTATION_FREEZE_EXCLUSIVE_LEASE_HOLD_SECONDS
    fi

    mutation_freeze_activation_outcome=$(docker run --rm --user 0 --network none \
        --mount "type=volume,source=${private_volume},target=/private" \
        --mount "type=volume,source=${coordination_volume},target=/state" \
        --env "CONTROL_PLANE_EXPECTED_EPOCH=$color_epoch" \
        --env "CONTROL_PLANE_MARKER_CRASH_POINT=$marker_container_crash_point" \
        --env "CONTROL_PLANE_MARKER_EXCLUSIVE_LEASE_HOLD_SECONDS=$mutation_freeze_exclusive_lease_hold_seconds" \
        --env "CONTROL_PLANE_MARKER_CANDIDATE_CRASH_POINT=after-${color_name}-mutation-freeze-epoch-candidate-fsync" \
        --env "CONTROL_PLANE_MARKER_EXCLUSIVE_LEASE_CRASH_POINT=after-${color_name}-mutation-freeze-epoch-exclusive-lease-acquired" \
        --env "CONTROL_PLANE_MARKER_PUBLISHED_CRASH_POINT=after-${color_name}-mutation-freeze-epoch-published-under-exclusive-lease" \
        --entrypoint /bin/sh "$green_image" -ec '
            test "$(id -u)" = 0
            test ! -L /state
            test -d /state
            test "$(stat -c %u:%g:%a /state)" = 0:9999:750

            lease=/state/mutation-inflight.lock
            marker=/state/mutation-freeze-epoch
            expected_lease_metadata=0:9999:660:1:0
            expected_marker_metadata=0:9999:440:1

            assert_lease_path() {
                test ! -L "$lease"
                test -f "$lease"
                test "$(stat -c %u:%g:%a:%h:%s "$lease")" = "$expected_lease_metadata"
            }

            assert_lease_fd() {
                test "$(stat -Lc %d:%i /proc/self/fd/8)" = "$lease_identity"
                test "$(stat -Lc %u:%g:%a:%h:%s /proc/self/fd/8)" = "$expected_lease_metadata"
                assert_lease_path
                test "$(stat -c %d:%i "$lease")" = "$lease_identity"
            }

            assert_matching_marker() {
                test ! -L "$marker"
                test -f "$marker"
                test "$(stat -c %u:%g:%a:%h "$marker")" = "$expected_marker_metadata"
                marker_identity=$(stat -c %d:%i "$marker")
                exec 9< "$marker"
                test "$(stat -Lc %d:%i /proc/self/fd/9)" = "$marker_identity"
                test "$(stat -Lc %u:%g:%a:%h /proc/self/fd/9)" = "$expected_marker_metadata"
                test "$(stat -Lc %s /proc/self/fd/9)" = "${#CONTROL_PLANE_EXPECTED_EPOCH}"
                test "$(cat <&9)" = "$CONTROL_PLANE_EXPECTED_EPOCH"
                test "$(stat -c %d:%i "$marker")" = "$marker_identity"
            }

            assert_lease_path
            lease_identity=$(stat -c %d:%i "$lease")
            exec 8<> "$lease"
            assert_lease_fd
            flock -x 8
            assert_lease_fd

            if [ "$CONTROL_PLANE_MARKER_CRASH_POINT" = \
                "$CONTROL_PLANE_MARKER_EXCLUSIVE_LEASE_CRASH_POINT" ]; then
                exit 137
            fi
            sleep "$CONTROL_PLANE_MARKER_EXCLUSIVE_LEASE_HOLD_SECONDS"
            assert_lease_fd

            if [ -e "$marker" ] || [ -L "$marker" ]; then
                assert_matching_marker
                marker_state=matching
            else
                marker_state=absent
            fi

            for stale_candidate in /state/.mutation-freeze-epoch.activate.*; do
                if [ -e "$stale_candidate" ] || [ -L "$stale_candidate" ]; then
                    rm -f "$stale_candidate"
                fi
            done
            sync /state
            assert_lease_fd

            if [ "$marker_state" = matching ]; then
                printf "%s\\n" already-active
                exit 0
            fi

            test ! -e "$marker"
            test ! -L "$marker"
            candidate=$(mktemp /state/.mutation-freeze-epoch.activate.XXXXXX)
            trap '\''if [ -n "${candidate:-}" ]; then rm -f "$candidate"; fi'\'' EXIT HUP INT TERM
            umask 077
            printf %s "$CONTROL_PLANE_EXPECTED_EPOCH" > "$candidate"
            chown 0:9999 "$candidate"
            chmod 0440 "$candidate"
            test "$(stat -c %u:%g:%a:%h:%s "$candidate")" = \
                "0:9999:440:1:${#CONTROL_PLANE_EXPECTED_EPOCH}"
            sync "$candidate"
            if [ "$CONTROL_PLANE_MARKER_CRASH_POINT" = \
                "$CONTROL_PLANE_MARKER_CANDIDATE_CRASH_POINT" ]; then
                trap - EXIT HUP INT TERM
                exit 137
            fi
            assert_lease_fd
            test ! -e "$marker"
            test ! -L "$marker"
            mv -f "$candidate" "$marker"
            candidate=
            sync /state
            assert_lease_fd
            assert_matching_marker

            if [ "$CONTROL_PLANE_MARKER_CRASH_POINT" = \
                "$CONTROL_PLANE_MARKER_PUBLISHED_CRASH_POINT" ]; then
                exit 137
            fi
            printf "%s\\n" activated
        ')

    case "$mutation_freeze_activation_outcome" in
        activated|already-active)
            ;;
        *)
            fail "$color_name mutation-freeze marker activation returned an invalid outcome"
            ;;
    esac
    [ "$(marker_status "$private_volume" "$color_epoch" mutation-freeze-epoch)" = matching ] \
        || fail "$color_name mutation-freeze marker activation was not durable"
    if [ "$mutation_freeze_activation_outcome" = activated ]; then
        test_crash "after-${color_name}-mutation-freeze-epoch-activated"
    fi
}

activate_marker()
{
    private_volume=$1
    color_name=$2
    color_epoch=$3
    marker_filename=$4

    if [ "$marker_filename" = mutation-freeze-epoch ]; then
        activate_mutation_freeze_marker "$private_volume" "$color_name" "$color_epoch"
        return
    fi

    case "$marker_filename" in
        writer-epoch|web-epoch|route-drain-epoch) ;;
        *) fail "unsupported private control-plane marker filename: $marker_filename" ;;
    esac

    observed_marker_state=$(marker_status "$private_volume" "$color_epoch" "$marker_filename")

    case "$observed_marker_state" in
        matching)
            ;;
        absent)
            ;;
        *)
            fail "$color_name marker state is not safe to activate"
            ;;
    esac

    marker_container_crash_point=
    if is_test_mode; then
        marker_container_crash_point=${CONTROL_PLANE_TEST_CRASH_AT:-}
    fi

    docker run --rm --user 0 --network none \
        --mount "type=volume,source=${private_volume},target=/state" \
        --mount "type=volume,source=${coordination_volume},target=/coordination" \
        --env "CONTROL_PLANE_EXPECTED_EPOCH=$color_epoch" \
        --env "CONTROL_PLANE_MARKER_FILENAME=$marker_filename" \
        --env "CONTROL_PLANE_MARKER_OBSERVED_STATE=$observed_marker_state" \
        --env "CONTROL_PLANE_MARKER_CRASH_POINT=$marker_container_crash_point" \
        --env "CONTROL_PLANE_MARKER_EXPECTED_CRASH_POINT=after-${color_name}-${marker_filename}-candidate-fsync" \
        --entrypoint /bin/sh "$green_image" -ec '
            test "$(id -u)" = 0
            test "$(stat -c %u:%g:%a /state)" = 0:9999:750
            lease=/coordination/mutation-inflight.lock
            test ! -L "$lease"
            test -f "$lease"
            test "$(stat -c %u:%g:%a:%h:%s "$lease")" = 0:9999:660:1:0
            lease_identity=$(stat -c %d:%i "$lease")
            exec 8<> "$lease"
            test "$(stat -Lc %d:%i /proc/self/fd/8)" = "$lease_identity"
            flock -x 8
            test "$(stat -c %d:%i "$lease")" = "$lease_identity"
            marker="/state/${CONTROL_PLANE_MARKER_FILENAME}"
            for stale_candidate in "/state/.${CONTROL_PLANE_MARKER_FILENAME}.activate."*; do
                if [ -e "$stale_candidate" ] || [ -L "$stale_candidate" ]; then
                    rm -f "$stale_candidate"
                fi
            done
            sync /state
            test "$(stat -c %d:%i "$lease")" = "$lease_identity"

            if [ "$CONTROL_PLANE_MARKER_OBSERVED_STATE" = matching ]; then
                exit 0
            fi
            test ! -e "$marker"
            test ! -L "$marker"
            candidate=$(mktemp "/state/.${CONTROL_PLANE_MARKER_FILENAME}.activate.XXXXXX")
            trap '\''if [ -n "${candidate:-}" ]; then rm -f "$candidate"; fi'\'' EXIT HUP INT TERM
            umask 077
            printf %s "$CONTROL_PLANE_EXPECTED_EPOCH" > "$candidate"
            chown 0:9999 "$candidate"
            chmod 0440 "$candidate"
            test "$(stat -c %u:%g:%a:%h:%s "$candidate")" = \
                "0:9999:440:1:${#CONTROL_PLANE_EXPECTED_EPOCH}"
            sync "$candidate"
            if [ "$CONTROL_PLANE_MARKER_CRASH_POINT" = \
                "$CONTROL_PLANE_MARKER_EXPECTED_CRASH_POINT" ]; then
                trap - EXIT HUP INT TERM
                exit 137
            fi
            test ! -e "$marker"
            test ! -L "$marker"
            mv -f "$candidate" "$marker"
            candidate=
            sync /state
            test "$(stat -c %d:%i "$lease")" = "$lease_identity"
        ' >/dev/null

    [ "$(marker_status "$private_volume" "$color_epoch" "$marker_filename")" = matching ] \
        || fail "$color_name marker activation was not durable"
    if [ "$observed_marker_state" = absent ]; then
        test_crash "after-${color_name}-${marker_filename}-activated"
    fi
}

prove_mutation_freeze_quiesced()
{
    mutation_container=$1
    mutation_epoch=$2
    mutation_lease_race=
    if is_test_mode; then
        mutation_lease_race=${CONTROL_PLANE_TEST_MUTATION_LEASE_RACE:-}
        case "$mutation_lease_race" in
            ''|missing|replacement) ;;
            *) fail 'mutation lease race injection is invalid' ;;
        esac
    fi

    docker exec --user 0 \
        --env "CONTROL_PLANE_MUTATION_FREEZE_EPOCH=$mutation_epoch" \
        --env "CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH=$MUTATION_FREEZE_MARKER_PATH" \
        --env "CONTROL_PLANE_MUTATION_LEASE_PATH=$MUTATION_LEASE_PATH" \
        --env "CONTROL_PLANE_MUTATION_LEASE_TIMEOUT=$drain_attempts" \
        --env "CONTROL_PLANE_TEST_MUTATION_LEASE_RACE=$mutation_lease_race" \
        "$mutation_container" php -r '
            $epoch = getenv("CONTROL_PLANE_MUTATION_FREEZE_EPOCH");
            $markerPath = getenv("CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH");
            $leasePath = getenv("CONTROL_PLANE_MUTATION_LEASE_PATH");
            $race = getenv("CONTROL_PLANE_TEST_MUTATION_LEASE_RACE");
            $timeout = filter_var(
                getenv("CONTROL_PLANE_MUTATION_LEASE_TIMEOUT"),
                FILTER_VALIDATE_INT,
                ["options" => ["min_range" => 1]]
            );
            $expectedLease = static function (array|false $identity): bool {
                return is_array($identity)
                    && ($identity["mode"] & 0170000) === 0100000
                    && ($identity["mode"] & 07777) === 0660
                    && $identity["uid"] === 0
                    && $identity["gid"] === 9999
                    && $identity["nlink"] === 1
                    && $identity["size"] === 0;
            };
            $sameLease = static function (array $left, array $right): bool {
                foreach (["dev", "ino", "mode", "uid", "gid", "nlink", "size"] as $key) {
                    if ($left[$key] !== $right[$key]) {
                        return false;
                    }
                }
                return true;
            };
            if (! is_string($epoch) || ! is_string($markerPath) || ! is_string($leasePath)
                || ! is_string($race) || $timeout === false || ! is_file($markerPath)
                || is_link($markerPath)
                || ! hash_equals($epoch, (string) file_get_contents($markerPath))) {
                exit(1);
            }
            clearstatcache(true, $leasePath);
            $before = lstat($leasePath);
            if (! $expectedLease($before)) {
                exit(1);
            }
            if ($race === "missing") {
                unlink($leasePath);
            } elseif ($race === "replacement") {
                $oldLease = $leasePath . ".race-old";
                rename($leasePath, $oldLease);
                touch($leasePath);
                chown($leasePath, 0);
                chgrp($leasePath, 9999);
                chmod($leasePath, 0660);
            }
            $lease = fopen($leasePath, "r+");
            if ($lease === false || ! $expectedLease($opened = fstat($lease))
                || ! $sameLease($before, $opened)) {
                is_resource($lease) && fclose($lease);
                exit(1);
            }
            $deadline = microtime(true) + $timeout;
            do {
                if (flock($lease, LOCK_EX | LOCK_NB)) {
                    clearstatcache(true, $leasePath);
                    $heldPath = lstat($leasePath);
                    $heldFd = fstat($lease);
                    $valid = $expectedLease($heldPath) && $expectedLease($heldFd)
                        && $sameLease($before, $heldPath) && $sameLease($before, $heldFd)
                        && is_file($markerPath) && ! is_link($markerPath)
                        && hash_equals($epoch, (string) file_get_contents($markerPath));
                    flock($lease, LOCK_UN);
                    fclose($lease);
                    exit($valid ? 0 : 1);
                }
                usleep(100000);
            } while (microtime(true) < $deadline);
            fclose($lease);
            exit(1);
        ' >/dev/null \
        || fail 'in-flight proxy mutations did not quiesce under the durable freeze'
}

ensure_private_volume()
{
    private_volume=$1
    member_name=$2

    if ! docker volume inspect "$private_volume" >/dev/null 2>&1; then
        docker volume create --label "coolify.control-plane.member=$member_name" \
            "$private_volume" >/dev/null
    fi

    docker run --rm --user 0 --network none \
        --mount "type=volume,source=${private_volume},target=/private" \
        --entrypoint /bin/sh "$green_image" -ec '
            test "$(id -u)" = 0
            test ! -L /private
            test -d /private
            chown 0:9999 /private
            chmod 0750 /private
            for private_entry in /private/* /private/.[!.]* /private/..?*; do
                if [ -e "$private_entry" ] || [ -L "$private_entry" ]; then
                    exit 1
                fi
            done
            sync /private
            test "$(stat -c %u:%g:%a /private)" = 0:9999:750
        ' >/dev/null \
        || fail "$member_name private marker volume is not exact and empty"
}

ensure_coordination_volume()
{
    if ! docker volume inspect "$coordination_volume" >/dev/null 2>&1; then
        docker volume create --label coolify.control-plane.coordination=true \
            "$coordination_volume" >/dev/null
    fi

    docker run --rm --user 0 --network none \
        --mount "type=volume,source=${coordination_volume},target=/coordination" \
        --entrypoint /bin/sh "$green_image" -ec '
            test "$(id -u)" = 0
            lease=/coordination/mutation-inflight.lock
            if [ ! -e "$lease" ] && [ ! -L "$lease" ]; then
                for coordination_entry in /coordination/* /coordination/.[!.]* \
                    /coordination/..?*; do
                    if [ -e "$coordination_entry" ] || [ -L "$coordination_entry" ]; then
                        exit 1
                    fi
                done
                chown 0:9999 /coordination
                chmod 0750 /coordination
                lease_candidate=$(mktemp /coordination/.mutation-inflight.lock.initialize.XXXXXX)
                trap '\''if [ -n "${lease_candidate:-}" ]; then rm -f "$lease_candidate"; fi'\'' \
                    EXIT HUP INT TERM
                chown 0:9999 "$lease_candidate"
                chmod 0660 "$lease_candidate"
                test "$(stat -c %u:%g:%a:%h:%s "$lease_candidate")" = 0:9999:660:1:0
                sync "$lease_candidate"
                test ! -e "$lease"
                test ! -L "$lease"
                mv -f "$lease_candidate" "$lease"
                lease_candidate=
                sync /coordination
            fi

            test ! -L /coordination
            test -d /coordination
            test "$(stat -c %u:%g:%a /coordination)" = 0:9999:750
            test ! -L "$lease"
            test -f "$lease"
            test "$(stat -c %u:%g:%a:%h:%s "$lease")" = 0:9999:660:1:0
            lease_identity=$(stat -c %d:%i "$lease")
            exec 8< "$lease"
            test "$(stat -Lc %d:%i /proc/self/fd/8)" = "$lease_identity"
            for coordination_entry in /coordination/* /coordination/.[!.]* \
                /coordination/..?*; do
                if [ "$coordination_entry" != "$lease" ] \
                    && { [ -e "$coordination_entry" ] || [ -L "$coordination_entry" ]; }; then
                    exit 1
                fi
            done
            sync /coordination
            test "$(stat -c %d:%i "$lease")" = "$lease_identity"
        ' >/dev/null \
        || fail 'coordination volume is not an exact root-owned lease volume'
}

assert_marker_mount()
{
    container_name=$1
    expected_private_volume=$2
    mounted_private_volume=$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/coolify-control-plane/private"}}{{.Name}}{{end}}{{end}}' "$container_name")
    mounted_coordination_volume=$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/coolify-control-plane/coordination"}}{{.Name}}{{end}}{{end}}' "$container_name")

    [ "$mounted_private_volume" = "$expected_private_volume" ] \
        && [ "$mounted_coordination_volume" = "$coordination_volume" ] \
        || fail "container does not use its exact private and shared coordination volumes: $container_name"
}

backup_attestation_configuration_sha256()
{
    {
        printf 'verifier_path=%s\n' "$backup_attestation_verifier"
        printf 'verifier_sha256=%s\n' "$backup_attestation_verifier_sha256"
        printf 'database_dump_path=%s\n' "$backup_database_dump_file"
        printf 'redis_snapshot_path=%s\n' "$backup_redis_snapshot_file"
        printf 'state_archive_path=%s\n' "$backup_state_archive_file"
        printf 'capture_manifest_path=%s\n' "$backup_capture_manifest_file"
        printf 'capture_manifest_sha256=%s\n' "$backup_capture_manifest_sha256"
        printf 'attestation_path=%s\n' "$backup_attestation_file"
        printf 'attestation_sha256=%s\n' "$backup_attestation_sha256"
        printf 'source_pg_system_identifier=%s\n' "$backup_expected_source_pg_system_identifier"
        printf 'source_pg_database_oid=%s\n' "$backup_expected_source_pg_database_oid"
        printf 'source_pg_version=%s\n' "$backup_expected_source_pg_version"
        printf 'source_database_name=%s\n' "$backup_expected_source_database_name"
        printf 'source_host_identity=%s\n' "$backup_expected_source_host_identity"
        printf 'source_redis_container_id=%s\n' "$backup_expected_source_redis_container_id"
        printf 'source_redis_image_id=%s\n' "$backup_expected_source_redis_image_id"
        printf 'source_redis_image_digest=%s\n' "$backup_expected_source_redis_image_digest"
        printf 'source_redis_endpoint=%s\n' "$backup_expected_source_redis_endpoint"
        printf 'candidate_image_digest=%s\n' "$backup_expected_candidate_image_digest"
        printf 'recipient_fingerprint=%s\n' "$backup_expected_recipient_fingerprint"
        printf 'quiesce_operator_sha256=%s\n' "$backup_expected_quiesce_operator_sha256"
        printf 'restore_target_identity=%s\n' "$backup_expected_restore_target_identity"
        printf 'max_age_seconds=%s\n' "$backup_max_age_seconds"
        printf 'operator_rehearsal_hook_sha256=%s\n' \
            "$backup_expected_operator_rehearsal_hook_sha256"
        printf 'candidate_boot_hook_sha256=%s\n' "$backup_expected_candidate_boot_hook_sha256"
        printf 'candidate_api_probe_hook_sha256=%s\n' \
            "$backup_expected_candidate_api_probe_hook_sha256"
        printf 'state_proof_tool_sha256=%s\n' "$backup_expected_state_proof_tool_sha256"
    } | sha256sum | awk '{print $1}'
}

assert_backup_attestation()
{
    require_value CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER "$backup_attestation_verifier"
    require_value CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER_SHA256 \
        "$backup_attestation_verifier_sha256"
    require_value CONTROL_PLANE_BACKUP_DATABASE_DUMP_FILE "$backup_database_dump_file"
    require_value CONTROL_PLANE_BACKUP_REDIS_SNAPSHOT_FILE "$backup_redis_snapshot_file"
    require_value CONTROL_PLANE_BACKUP_STATE_ARCHIVE_FILE "$backup_state_archive_file"
    require_value CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_FILE "$backup_capture_manifest_file"
    require_value CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_SHA256 "$backup_capture_manifest_sha256"
    require_value CONTROL_PLANE_BACKUP_ATTESTATION_FILE "$backup_attestation_file"
    require_value CONTROL_PLANE_BACKUP_ATTESTATION_SHA256 "$backup_attestation_sha256"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_SYSTEM_IDENTIFIER \
        "$backup_expected_source_pg_system_identifier"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_DATABASE_OID \
        "$backup_expected_source_pg_database_oid"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_VERSION \
        "$backup_expected_source_pg_version"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_DATABASE_NAME \
        "$backup_expected_source_database_name"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_HOST_IDENTITY \
        "$backup_expected_source_host_identity"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_CONTAINER_ID \
        "$backup_expected_source_redis_container_id"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_IMAGE_ID \
        "$backup_expected_source_redis_image_id"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_IMAGE_DIGEST \
        "$backup_expected_source_redis_image_digest"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_ENDPOINT \
        "$backup_expected_source_redis_endpoint"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_IMAGE_DIGEST \
        "$backup_expected_candidate_image_digest"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_RECIPIENT_FINGERPRINT \
        "$backup_expected_recipient_fingerprint"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_QUIESCE_OPERATOR_SHA256 \
        "$backup_expected_quiesce_operator_sha256"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_RESTORE_TARGET_IDENTITY \
        "$backup_expected_restore_target_identity"
    require_value CONTROL_PLANE_BACKUP_MAX_AGE_SECONDS "$backup_max_age_seconds"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_OPERATOR_REHEARSAL_HOOK_SHA256 \
        "$backup_expected_operator_rehearsal_hook_sha256"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_BOOT_HOOK_SHA256 \
        "$backup_expected_candidate_boot_hook_sha256"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_API_PROBE_HOOK_SHA256 \
        "$backup_expected_candidate_api_probe_hook_sha256"
    require_value CONTROL_PLANE_BACKUP_EXPECTED_STATE_PROOF_TOOL_SHA256 \
        "$backup_expected_state_proof_tool_sha256"
    validate_path "$backup_attestation_verifier" CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER
    validate_path "$backup_database_dump_file" CONTROL_PLANE_BACKUP_DATABASE_DUMP_FILE
    validate_path "$backup_redis_snapshot_file" CONTROL_PLANE_BACKUP_REDIS_SNAPSHOT_FILE
    validate_path "$backup_state_archive_file" CONTROL_PLANE_BACKUP_STATE_ARCHIVE_FILE
    validate_path "$backup_capture_manifest_file" CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_FILE
    validate_path "$backup_attestation_file" CONTROL_PLANE_BACKUP_ATTESTATION_FILE
    validate_sha256 "$backup_attestation_verifier_sha256" \
        CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER_SHA256
    validate_sha256 "$backup_capture_manifest_sha256" \
        CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_SHA256
    validate_sha256 "$backup_attestation_sha256" CONTROL_PLANE_BACKUP_ATTESTATION_SHA256
    validate_positive_integer "$backup_expected_source_pg_system_identifier" \
        CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_SYSTEM_IDENTIFIER
    validate_positive_integer "$backup_expected_source_pg_database_oid" \
        CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_DATABASE_OID
    validate_host "$backup_expected_source_host_identity" \
        CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_HOST_IDENTITY
    validate_container_id "$backup_expected_source_redis_container_id" \
        CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_CONTAINER_ID
    validate_image_id "$backup_expected_source_redis_image_id" \
        CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_IMAGE_ID
    validate_sha256 "$backup_expected_quiesce_operator_sha256" \
        CONTROL_PLANE_BACKUP_EXPECTED_QUIESCE_OPERATOR_SHA256
    validate_host "$backup_expected_restore_target_identity" \
        CONTROL_PLANE_BACKUP_EXPECTED_RESTORE_TARGET_IDENTITY
    validate_openpgp_fingerprint "$backup_expected_recipient_fingerprint" \
        CONTROL_PLANE_BACKUP_EXPECTED_RECIPIENT_FINGERPRINT
    validate_positive_integer "$backup_max_age_seconds" CONTROL_PLANE_BACKUP_MAX_AGE_SECONDS
    validate_sha256 "$backup_expected_operator_rehearsal_hook_sha256" \
        CONTROL_PLANE_BACKUP_EXPECTED_OPERATOR_REHEARSAL_HOOK_SHA256
    validate_sha256 "$backup_expected_candidate_boot_hook_sha256" \
        CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_BOOT_HOOK_SHA256
    validate_sha256 "$backup_expected_candidate_api_probe_hook_sha256" \
        CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_API_PROBE_HOOK_SHA256
    validate_sha256 "$backup_expected_state_proof_tool_sha256" \
        CONTROL_PLANE_BACKUP_EXPECTED_STATE_PROOF_TOOL_SHA256
    [ "$backup_expected_source_pg_version" = 15.18 ] \
        || fail 'CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_VERSION must be exactly 15.18'
    [ "$backup_expected_source_database_name" = "$database_name" ] \
        || fail 'backup source database name must equal CONTROL_PLANE_DATABASE_NAME'
    [ "$backup_expected_source_redis_endpoint" = "${redis_host}:${redis_port}" ] \
        || fail 'backup source Redis endpoint must equal CONTROL_PLANE_REDIS_HOST and CONTROL_PLANE_REDIS_PORT'
    [ "$backup_expected_candidate_image_digest" = "$green_image" ] \
        || fail 'backup candidate image digest must equal CONTROL_PLANE_GREEN_IMAGE'
    [ "$backup_expected_source_host_identity" != "$backup_expected_restore_target_identity" ] \
        || fail 'backup source and restore target identities must be distinct'
    if ! is_test_mode; then
        [ "$backup_attestation_verifier" = "$SCRIPT_DIRECTORY/backup/restore-attest.sh" ] \
            || fail 'production mode only accepts the bundled backup/restore attestation verifier'
        backup_attestation_verifier_expected_mode=$(release_asset_expected_mode backup-attestation-verifier)
        [ "$(file_uid "$backup_attestation_verifier")" = 0 ] \
            && [ "$(file_gid "$backup_attestation_verifier")" = 0 ] \
            && [ "$(file_mode "$backup_attestation_verifier")" = "$backup_attestation_verifier_expected_mode" ] \
            || fail "production backup/restore attestation verifier must be root:root mode 0$backup_attestation_verifier_expected_mode"
    fi
    require_migration_timeouts
    backup_configuration_sha256=$(backup_attestation_configuration_sha256)
    if [ -f "$state_file" ]; then
        [ "$state_backup_attestation_configuration_sha256" = "$backup_configuration_sha256" ] \
            && [ "$state_backup_attestation_sha256" = "$backup_attestation_sha256" ] \
            || fail 'backup/restore attestation pins changed during this operation'
    fi

    assert_non_symlink_regular_file "$backup_attestation_verifier" \
        CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER
    [ -x "$backup_attestation_verifier" ] \
        || fail 'CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER must be executable'
    [ "$(sha256_file "$backup_attestation_verifier")" = "$backup_attestation_verifier_sha256" ] \
        || fail 'backup/restore attestation verifier differs from its pinned digest'
    assert_non_symlink_regular_file "$backup_database_dump_file" \
        CONTROL_PLANE_BACKUP_DATABASE_DUMP_FILE
    assert_non_symlink_regular_file "$backup_redis_snapshot_file" \
        CONTROL_PLANE_BACKUP_REDIS_SNAPSHOT_FILE
    assert_non_symlink_regular_file "$backup_state_archive_file" \
        CONTROL_PLANE_BACKUP_STATE_ARCHIVE_FILE
    assert_non_symlink_regular_file "$backup_capture_manifest_file" \
        CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_FILE
    [ "$(sha256_file "$backup_capture_manifest_file")" = "$backup_capture_manifest_sha256" ] \
        || fail 'backup capture manifest differs from its out-of-band pinned digest'
    assert_non_symlink_regular_file "$backup_attestation_file" CONTROL_PLANE_BACKUP_ATTESTATION_FILE
    [ "$(sha256_file "$backup_attestation_file")" = "$backup_attestation_sha256" ] \
        || fail 'backup/restore attestation differs from its out-of-band pinned digest'

    assert_immutable_image "$backup_expected_source_redis_image_digest" \
        'backup source Redis image'
    assert_container_running "$redis_host"
    backup_live_source_redis_container_id=$(container_id "$redis_host")
    backup_live_source_redis_image_id=$(container_image_id "$redis_host")
    backup_pinned_source_redis_image_id=$(image_id "$backup_expected_source_redis_image_digest")
    [ "$backup_live_source_redis_container_id" = "$backup_expected_source_redis_container_id" ] \
        && [ "$backup_live_source_redis_image_id" = "$backup_expected_source_redis_image_id" ] \
        && [ "$backup_pinned_source_redis_image_id" = "$backup_expected_source_redis_image_id" ] \
        || fail 'live backup source Redis container or image identity differs from the out-of-band pins'
    if ! is_test_mode; then
        backup_expected_source_redis_digest=$(image_digest_from_reference \
            "$backup_expected_source_redis_image_digest")
        [ -n "$backup_expected_source_redis_digest" ] \
            || fail 'live backup source Redis image does not carry the pinned immutable repository digest'
        image_repository_has_digest "$backup_live_source_redis_image_id" \
            "$backup_expected_source_redis_digest" \
            || fail 'live backup source Redis image does not carry the pinned immutable repository digest'
    fi

    backup_live_source_pg_system_identifier=$(database_psql --command \
        'SELECT system_identifier::text FROM pg_control_system()' | tr -d '[:space:]')
    backup_live_source_pg_database_oid=$(database_psql --command \
        'SELECT oid::text FROM pg_database WHERE datname = current_database()' | tr -d '[:space:]')
    backup_live_source_pg_version=$(database_psql --command \
        "SELECT current_setting('server_version')" | tr -d '[:space:]')
    backup_live_source_database_name=$(database_psql --command \
        'SELECT current_database()' | tr -d '[:space:]')
    backup_live_source_host_identity=$(hostname -f 2>/dev/null || hostname)
    backup_live_source_host_identity=$(printf '%s' "$backup_live_source_host_identity" | tr -d '\r\n')
    [ "$backup_live_source_pg_system_identifier" = "$backup_expected_source_pg_system_identifier" ] \
        && [ "$backup_live_source_pg_database_oid" = "$backup_expected_source_pg_database_oid" ] \
        && [ "$backup_live_source_pg_version" = "$backup_expected_source_pg_version" ] \
        && [ "$backup_live_source_database_name" = "$backup_expected_source_database_name" ] \
        && [ "$backup_live_source_host_identity" = "$backup_expected_source_host_identity" ] \
        || fail 'live backup source identity differs from the out-of-band restore attestation pins'

    assert_release_asset_identity backup-attestation-verifier
    if ! backup_attestation_output=$(
        "$backup_attestation_verifier" verify-attestation \
            --operation-id "$operation_id" \
            --database-dump "$backup_database_dump_file" \
            --redis-snapshot "$backup_redis_snapshot_file" \
            --state-archive "$backup_state_archive_file" \
            --capture-manifest "$backup_capture_manifest_file" \
            --expected-capture-manifest-sha256 "$backup_capture_manifest_sha256" \
            --expected-source-pg-system-identifier "$backup_expected_source_pg_system_identifier" \
            --expected-source-pg-database-oid "$backup_expected_source_pg_database_oid" \
            --expected-source-pg-version "$backup_expected_source_pg_version" \
            --expected-source-database-name "$backup_expected_source_database_name" \
            --expected-source-host-identity "$backup_expected_source_host_identity" \
            --expected-source-redis-container-id "$backup_expected_source_redis_container_id" \
            --expected-source-redis-image-digest "$backup_expected_source_redis_image_digest" \
            --expected-source-redis-endpoint "$backup_expected_source_redis_endpoint" \
            --expected-candidate-image-digest "$backup_expected_candidate_image_digest" \
            --expected-recipient-fingerprint "$backup_expected_recipient_fingerprint" \
            --expected-quiesce-operator-sha256 "$backup_expected_quiesce_operator_sha256" \
            --expected-restore-target-identity "$backup_expected_restore_target_identity" \
            --max-age-seconds "$backup_max_age_seconds" \
            --expected-operator-rehearsal-hook-sha256 \
                "$backup_expected_operator_rehearsal_hook_sha256" \
            --expected-candidate-boot-hook-sha256 "$backup_expected_candidate_boot_hook_sha256" \
            --expected-candidate-api-probe-hook-sha256 \
                "$backup_expected_candidate_api_probe_hook_sha256" \
            --expected-state-proof-tool-sha256 "$backup_expected_state_proof_tool_sha256" \
            --attestation "$backup_attestation_file" \
            --expected-attestation-sha256 "$backup_attestation_sha256" \
            && printf '.'
    ); then
        fail 'strict backup/restore attestation validation failed'
    fi
    [ "$backup_attestation_output" = "$(printf 'attestation-validation=passed\n.')" ] \
        || fail 'backup/restore attestation verifier returned inexact success output'
    backup_manifest_source_redis_image_id=$(awk -F= \
        '$1 == "source_redis_image_id" { print substr($0, length($1) + 2) }' \
        "$backup_capture_manifest_file")
    [ "$backup_manifest_source_redis_image_id" = "$backup_expected_source_redis_image_id" ] \
        || fail 'backup capture manifest source Redis image ID differs from its out-of-band pin'

    state_backup_attestation_configuration_sha256=$backup_configuration_sha256
    state_backup_attestation_sha256=$backup_attestation_sha256
    state_backup_attestation_last_verified_unix=$(date -u +%s)
    [ ! -f "$state_file" ] || write_state "$state_phase"
}

compose()
{
    compose_color=$1
    shift
    compose_green_web_a_writer_epoch=
    compose_green_web_a_writer_marker_path=
    compose_green_web_b_writer_epoch=
    compose_green_web_b_writer_marker_path=
    compose_blue_web_a_writer_epoch=
    compose_blue_web_a_writer_marker_path=
    compose_blue_web_b_writer_epoch=
    compose_blue_web_b_writer_marker_path=
    if [ "$green_writer_member" = web-a ]; then
        compose_green_web_a_writer_epoch=$writer_epoch
        compose_green_web_a_writer_marker_path=$WRITER_MARKER_PATH
    else
        compose_green_web_b_writer_epoch=$writer_epoch
        compose_green_web_b_writer_marker_path=$WRITER_MARKER_PATH
    fi
    if [ "$blue_writer_member" = web-a ]; then
        compose_blue_web_a_writer_epoch=$blue_writer_epoch
        compose_blue_web_a_writer_marker_path=$WRITER_MARKER_PATH
    else
        compose_blue_web_b_writer_epoch=$blue_writer_epoch
        compose_blue_web_b_writer_marker_path=$WRITER_MARKER_PATH
    fi
    case "$compose_color" in
        green)
            compose_runtime_env_file=$green_runtime_env_file
            compose_mutation_freeze_epoch=$mutation_freeze_epoch
            ;;
        blue)
            compose_runtime_env_file=$blue_runtime_env_file
            compose_mutation_freeze_epoch=$reverse_mutation_freeze_epoch
            ;;
        *)
            fail "unknown candidate compose color: $compose_color"
            ;;
    esac

    assert_control_plane_proxy_peer_addresses
    CONTROL_PLANE_GREEN_IMAGE="$green_image" \
    CONTROL_PLANE_BLUE_IMAGE="$blue_image" \
    CONTROL_PLANE_NETWORK="$control_plane_network" \
    CONTROL_PLANE_BACKEND_PORT="$backend_port" \
    CONTROL_PLANE_HOST="$control_plane_host" \
    CONTROL_PLANE_LOCAL_INGRESS_ENTRYPOINT="$local_ingress_entrypoint" \
    CONTROL_PLANE_GREEN_ROUTE_HEALTH_TOKEN="$green_route_health_token" \
    CONTROL_PLANE_BLUE_ROUTE_HEALTH_TOKEN="$blue_route_health_token" \
    CONTROL_PLANE_DIRECT_PROBE_PATH="$direct_probe_path" \
    CONTROL_PLANE_SSH_DIRECTORY="$ssh_directory" \
    CONTROL_PLANE_APPLICATIONS_DIRECTORY="$applications_directory" \
    CONTROL_PLANE_DATABASES_DIRECTORY="$databases_directory" \
    CONTROL_PLANE_SERVICES_DIRECTORY="$services_directory" \
    CONTROL_PLANE_BACKUPS_DIRECTORY="$backups_directory" \
    CONTROL_PLANE_RUNTIME_ENV_FILE="$compose_runtime_env_file" \
    CONTROL_PLANE_MUTATION_FREEZE_EPOCH="$compose_mutation_freeze_epoch" \
    CONTROL_PLANE_TRUSTED_PROXY_ADDRESSES="$control_plane_trusted_proxy_addresses" \
    CONTROL_PLANE_GREEN_WEB_A_CONTAINER="$green_container" \
    CONTROL_PLANE_GREEN_WEB_B_CONTAINER="$green_web_b_container" \
    CONTROL_PLANE_BLUE_WEB_A_CONTAINER="$replacement_blue_container" \
    CONTROL_PLANE_BLUE_WEB_B_CONTAINER="$replacement_blue_web_b_container" \
    CONTROL_PLANE_GREEN_WEB_A_MEMBER_ID="$green_web_a_route_identity" \
    CONTROL_PLANE_GREEN_WEB_B_MEMBER_ID="$green_web_b_route_identity" \
    CONTROL_PLANE_BLUE_WEB_A_MEMBER_ID="$blue_web_a_route_identity" \
    CONTROL_PLANE_BLUE_WEB_B_MEMBER_ID="$blue_web_b_route_identity" \
    CONTROL_PLANE_GREEN_WEB_A_WRITER_EPOCH="$compose_green_web_a_writer_epoch" \
    CONTROL_PLANE_GREEN_WEB_A_WRITER_MARKER_PATH="$compose_green_web_a_writer_marker_path" \
    CONTROL_PLANE_GREEN_WEB_B_WRITER_EPOCH="$compose_green_web_b_writer_epoch" \
    CONTROL_PLANE_GREEN_WEB_B_WRITER_MARKER_PATH="$compose_green_web_b_writer_marker_path" \
    CONTROL_PLANE_BLUE_WEB_A_WRITER_EPOCH="$compose_blue_web_a_writer_epoch" \
    CONTROL_PLANE_BLUE_WEB_A_WRITER_MARKER_PATH="$compose_blue_web_a_writer_marker_path" \
    CONTROL_PLANE_BLUE_WEB_B_WRITER_EPOCH="$compose_blue_web_b_writer_epoch" \
    CONTROL_PLANE_BLUE_WEB_B_WRITER_MARKER_PATH="$compose_blue_web_b_writer_marker_path" \
    CONTROL_PLANE_GREEN_WEB_A_WEB_EPOCH="$green_web_epoch" \
    CONTROL_PLANE_GREEN_WEB_B_WEB_EPOCH="$green_web_b_epoch" \
    CONTROL_PLANE_BLUE_WEB_A_WEB_EPOCH="$blue_web_epoch" \
    CONTROL_PLANE_BLUE_WEB_B_WEB_EPOCH="$blue_web_b_epoch" \
    CONTROL_PLANE_GREEN_WEB_A_ROUTE_DRAIN_EPOCH="$green_web_a_route_drain_epoch" \
    CONTROL_PLANE_GREEN_WEB_B_ROUTE_DRAIN_EPOCH="$green_web_b_route_drain_epoch" \
    CONTROL_PLANE_BLUE_WEB_A_ROUTE_DRAIN_EPOCH="$blue_web_a_route_drain_epoch" \
    CONTROL_PLANE_BLUE_WEB_B_ROUTE_DRAIN_EPOCH="$blue_web_b_route_drain_epoch" \
    CONTROL_PLANE_GREEN_WEB_A_LOOPBACK_PORT="$green_loopback_port" \
    CONTROL_PLANE_GREEN_WEB_B_LOOPBACK_PORT="$green_web_b_loopback_port" \
    CONTROL_PLANE_BLUE_WEB_A_LOOPBACK_PORT="$blue_loopback_port" \
    CONTROL_PLANE_BLUE_WEB_B_LOOPBACK_PORT="$blue_web_b_loopback_port" \
    CONTROL_PLANE_GREEN_WEB_A_PRIVATE_VOLUME="$green_state_volume" \
    CONTROL_PLANE_GREEN_WEB_B_PRIVATE_VOLUME="$green_web_b_private_volume" \
    CONTROL_PLANE_BLUE_WEB_A_PRIVATE_VOLUME="$blue_state_volume" \
    CONTROL_PLANE_BLUE_WEB_B_PRIVATE_VOLUME="$blue_web_b_private_volume" \
    CONTROL_PLANE_COORDINATION_VOLUME="$coordination_volume" \
    CONTROL_PLANE_POOL_LABEL_KEY="$POOL_LABEL_KEY" \
    CONTROL_PLANE_GREEN_POOL_LABEL_VALUE="$green_pool_label_value" \
    CONTROL_PLANE_BLUE_POOL_LABEL_VALUE="$blue_pool_label_value" \
    CONTROL_PLANE_GREEN_WEB_A_DIRECT_PROBE_RUNTIME_FILE="$green_direct_probe_token_file" \
    CONTROL_PLANE_GREEN_WEB_A_APPLIED_ACK_RUNTIME_FILE="$green_applied_ack_file" \
    CONTROL_PLANE_GREEN_WEB_B_DIRECT_PROBE_RUNTIME_FILE="$green_web_b_direct_probe_token_file" \
    CONTROL_PLANE_GREEN_WEB_B_APPLIED_ACK_RUNTIME_FILE="$green_web_b_applied_ack_file" \
    CONTROL_PLANE_GREEN_ROUTE_HEALTH_RUNTIME_FILE="$green_route_health_token_file" \
    CONTROL_PLANE_GREEN_POOL_ACK_RUNTIME_FILE="$green_pool_ack_file" \
    CONTROL_PLANE_BLUE_WEB_A_DIRECT_PROBE_RUNTIME_FILE="$blue_direct_probe_token_file" \
    CONTROL_PLANE_BLUE_WEB_A_APPLIED_ACK_RUNTIME_FILE="$blue_applied_ack_file" \
    CONTROL_PLANE_BLUE_WEB_B_DIRECT_PROBE_RUNTIME_FILE="$blue_web_b_direct_probe_token_file" \
    CONTROL_PLANE_BLUE_WEB_B_APPLIED_ACK_RUNTIME_FILE="$blue_web_b_applied_ack_file" \
    CONTROL_PLANE_BLUE_ROUTE_HEALTH_RUNTIME_FILE="$blue_route_health_token_file" \
    CONTROL_PLANE_BLUE_POOL_ACK_RUNTIME_FILE="$blue_pool_ack_file" \
        docker compose --ansi never --project-name "$compose_project" \
            --file "$operator_compose_file" "$@"
}

inspect_mount_field()
{
    container_name=$1
    destination=$2
    field_name=$3

    docker inspect "$container_name" \
        | jq --exit-status --raw-output --arg destination "$destination" --arg field "$field_name" \
            '.[0].Mounts[] | select(.Destination == $destination) | .[$field]'
}

assert_bind_mount()
{
    container_name=$1
    destination=$2
    source=$3
    expected_rw=$4

    [ "$(inspect_mount_field "$container_name" "$destination" Type)" = bind ] \
        || fail "candidate mount is not a bind: $destination"
    [ "$(canonical_directory "$(dirname -- "$(inspect_mount_field "$container_name" "$destination" Source)")")/$(basename -- "$(inspect_mount_field "$container_name" "$destination" Source)")" \
        = "$(canonical_directory "$(dirname -- "$source")")/$(basename -- "$source")" ] \
        || fail "candidate bind source differs for $destination"
    [ "$(inspect_mount_field "$container_name" "$destination" RW)" = "$expected_rw" ] \
        || fail "candidate bind read/write mode differs for $destination"
}

assert_volume_mount()
{
    container_name=$1
    destination=$2
    volume_name=$3

    [ "$(inspect_mount_field "$container_name" "$destination" Type)" = volume ] \
        && [ "$(inspect_mount_field "$container_name" "$destination" Name)" = "$volume_name" ] \
        && [ "$(inspect_mount_field "$container_name" "$destination" RW)" = true ] \
        || fail "candidate volume identity differs for $destination"
}

wait_for_container_health()
{
    container_name=$1
    attempt=0

    while [ "$attempt" -lt "$candidate_health_attempts" ]; do
        health_status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' "$container_name")
        case "$health_status" in
            healthy)
                return
                ;;
            unhealthy)
                fail "candidate became unhealthy: $container_name"
                ;;
        esac
        attempt=$((attempt + 1))
        sleep 1
    done

    fail "candidate health was not proven: $container_name"
}

assert_candidate_reachability()
{
    container_name=$1

    if is_test_mode; then
        docker exec "$container_name" /bin/sh -ec '
            nc -z -w 2 "$1" "$2"
            nc -z -w 2 "$3" "$4"
            nc -z -w 2 "$5" "$6"
            nc -z -w 2 "$5" "$7"
        ' sh "$database_host" "$database_port" "$redis_host" "$redis_port" \
            "$soketi_host" "$soketi_port" "$soketi_metrics_port" >/dev/null
    else
        docker exec "$container_name" php -r '
            foreach (array_chunk(array_slice($argv, 1), 2) as [$host, $port]) {
                $socket = @fsockopen($host, (int) $port, $errorCode, $errorMessage, 2.0);
                if ($socket === false) {
                    fwrite(STDERR, "dependency reachability failed\n");
                    exit(1);
                }
                fclose($socket);
            }
        ' "$database_host" "$database_port" "$redis_host" "$redis_port" \
            "$soketi_host" "$soketi_port" "$soketi_host" "$soketi_metrics_port" >/dev/null
    fi
}

select_candidate_restart_policy_context()
{
    restart_policy_color=$1
    restart_policy_member=$2
    restart_policy_key="${restart_policy_color}-${restart_policy_member}"
    case "$restart_policy_key" in
        green-web-a)
            restart_policy_container=$green_container
            restart_policy_container_id=$state_green_id
            restart_policy_image_id=$state_green_image_id
            restart_policy_image_reference=$state_green_plan_image_reference
            restart_policy_runtime_sha256=$state_green_container_runtime_sha256
            restart_policy_status=$state_green_restart_policy_status
            restart_policy_intent_file=$green_restart_policy_intent_file
            restart_policy_intent_operation_id=$state_runtime_fence_operation_id
            ;;
        green-web-b)
            restart_policy_container=$green_web_b_container
            restart_policy_container_id=$state_green_web_b_id
            restart_policy_image_id=$state_green_web_b_image_id
            restart_policy_image_reference=$state_green_plan_image_reference
            restart_policy_runtime_sha256=$state_green_web_b_container_runtime_sha256
            restart_policy_status=$state_green_web_b_restart_policy_status
            restart_policy_intent_file=$green_web_b_restart_policy_intent_file
            restart_policy_intent_operation_id=$state_runtime_fence_operation_id
            ;;
        blue-web-a)
            restart_policy_container=$replacement_blue_container
            restart_policy_container_id=$state_replacement_blue_id
            restart_policy_image_id=$state_replacement_blue_image_id
            restart_policy_image_reference=$state_replacement_blue_plan_image_reference
            restart_policy_runtime_sha256=$state_replacement_blue_container_runtime_sha256
            restart_policy_status=$state_replacement_blue_restart_policy_status
            restart_policy_intent_file=$replacement_blue_restart_policy_intent_file
            restart_policy_intent_operation_id=$state_reverse_fence_operation_id
            ;;
        blue-web-b)
            restart_policy_container=$replacement_blue_web_b_container
            restart_policy_container_id=$state_replacement_blue_web_b_id
            restart_policy_image_id=$state_replacement_blue_web_b_image_id
            restart_policy_image_reference=$state_replacement_blue_plan_image_reference
            restart_policy_runtime_sha256=$state_replacement_blue_web_b_container_runtime_sha256
            restart_policy_status=$state_replacement_blue_web_b_restart_policy_status
            restart_policy_intent_file=$replacement_blue_web_b_restart_policy_intent_file
            restart_policy_intent_operation_id=$state_reverse_fence_operation_id
            ;;
        *)
            fail "unknown candidate restart-policy member: $restart_policy_key"
            ;;
    esac
}

set_candidate_restart_policy_state()
{
    next_restart_policy_status=$1
    case "$restart_policy_key" in
        green-web-a)
            state_green_restart_policy_status=$next_restart_policy_status
            state_green_restart_policy_intent_path=$restart_policy_intent_file
            state_green_restart_policy_intent_sha256=$(sha256_file "$restart_policy_intent_file")
            state_green_restart_policy_intent_uid=$(file_uid "$restart_policy_intent_file")
            state_green_restart_policy_intent_gid=$(file_gid "$restart_policy_intent_file")
            state_green_restart_policy_intent_mode=$(file_mode "$restart_policy_intent_file")
            state_green_restart_policy_intent_size=$(file_size "$restart_policy_intent_file")
            ;;
        green-web-b)
            state_green_web_b_restart_policy_status=$next_restart_policy_status
            state_green_web_b_restart_policy_intent_path=$restart_policy_intent_file
            state_green_web_b_restart_policy_intent_sha256=$(sha256_file \
                "$restart_policy_intent_file")
            state_green_web_b_restart_policy_intent_uid=$(file_uid \
                "$restart_policy_intent_file")
            state_green_web_b_restart_policy_intent_gid=$(file_gid \
                "$restart_policy_intent_file")
            state_green_web_b_restart_policy_intent_mode=$(file_mode \
                "$restart_policy_intent_file")
            state_green_web_b_restart_policy_intent_size=$(file_size \
                "$restart_policy_intent_file")
            ;;
        blue-web-a)
            state_replacement_blue_restart_policy_status=$next_restart_policy_status
            state_replacement_blue_restart_policy_intent_path=$restart_policy_intent_file
            state_replacement_blue_restart_policy_intent_sha256=$(sha256_file \
                "$restart_policy_intent_file")
            state_replacement_blue_restart_policy_intent_uid=$(file_uid \
                "$restart_policy_intent_file")
            state_replacement_blue_restart_policy_intent_gid=$(file_gid \
                "$restart_policy_intent_file")
            state_replacement_blue_restart_policy_intent_mode=$(file_mode \
                "$restart_policy_intent_file")
            state_replacement_blue_restart_policy_intent_size=$(file_size \
                "$restart_policy_intent_file")
            ;;
        blue-web-b)
            state_replacement_blue_web_b_restart_policy_status=$next_restart_policy_status
            state_replacement_blue_web_b_restart_policy_intent_path=$restart_policy_intent_file
            state_replacement_blue_web_b_restart_policy_intent_sha256=$(sha256_file \
                "$restart_policy_intent_file")
            state_replacement_blue_web_b_restart_policy_intent_uid=$(file_uid \
                "$restart_policy_intent_file")
            state_replacement_blue_web_b_restart_policy_intent_gid=$(file_gid \
                "$restart_policy_intent_file")
            state_replacement_blue_web_b_restart_policy_intent_mode=$(file_mode \
                "$restart_policy_intent_file")
            state_replacement_blue_web_b_restart_policy_intent_size=$(file_size \
                "$restart_policy_intent_file")
            ;;
    esac
    restart_policy_status=$next_restart_policy_status
}

assert_candidate_restart_policy_intent()
{
    case "$restart_policy_key" in
        green-web-a)
            expected_intent_path=$state_green_restart_policy_intent_path
            expected_intent_sha256=$state_green_restart_policy_intent_sha256
            expected_intent_uid=$state_green_restart_policy_intent_uid
            expected_intent_gid=$state_green_restart_policy_intent_gid
            expected_intent_mode=$state_green_restart_policy_intent_mode
            expected_intent_size=$state_green_restart_policy_intent_size
            ;;
        green-web-b)
            expected_intent_path=$state_green_web_b_restart_policy_intent_path
            expected_intent_sha256=$state_green_web_b_restart_policy_intent_sha256
            expected_intent_uid=$state_green_web_b_restart_policy_intent_uid
            expected_intent_gid=$state_green_web_b_restart_policy_intent_gid
            expected_intent_mode=$state_green_web_b_restart_policy_intent_mode
            expected_intent_size=$state_green_web_b_restart_policy_intent_size
            ;;
        blue-web-a)
            expected_intent_path=$state_replacement_blue_restart_policy_intent_path
            expected_intent_sha256=$state_replacement_blue_restart_policy_intent_sha256
            expected_intent_uid=$state_replacement_blue_restart_policy_intent_uid
            expected_intent_gid=$state_replacement_blue_restart_policy_intent_gid
            expected_intent_mode=$state_replacement_blue_restart_policy_intent_mode
            expected_intent_size=$state_replacement_blue_restart_policy_intent_size
            ;;
        blue-web-b)
            expected_intent_path=$state_replacement_blue_web_b_restart_policy_intent_path
            expected_intent_sha256=$state_replacement_blue_web_b_restart_policy_intent_sha256
            expected_intent_uid=$state_replacement_blue_web_b_restart_policy_intent_uid
            expected_intent_gid=$state_replacement_blue_web_b_restart_policy_intent_gid
            expected_intent_mode=$state_replacement_blue_web_b_restart_policy_intent_mode
            expected_intent_size=$state_replacement_blue_web_b_restart_policy_intent_size
            ;;
    esac
    assert_file_identity "$restart_policy_intent_file" "$expected_intent_path" \
        "$expected_intent_sha256" "$expected_intent_uid" "$expected_intent_gid" \
        "$expected_intent_mode" "$expected_intent_size" \
        "$restart_policy_key restart-policy intent"
    [ "$expected_intent_uid" = "$immutable_uid" ] \
        && [ "$expected_intent_gid" = "$immutable_gid" ] \
        && [ "$expected_intent_mode" = 600 ] \
        || fail "$restart_policy_key restart-policy intent metadata is not immutable"
    awk -v operation_id="$restart_policy_intent_operation_id" \
        -v candidate_name="$restart_policy_container" \
        -v candidate_id="$restart_policy_container_id" \
        -v image_id="$restart_policy_image_id" \
        -v image_reference="$restart_policy_image_reference" '
        BEGIN {
            expected[1] = "version=1"
            expected[2] = "operation_id=" operation_id
            expected[3] = "candidate_name=" candidate_name
            expected[4] = "candidate_id=" candidate_id
            expected[5] = "candidate_image_id=" image_id
            expected[6] = "candidate_image_reference=" image_reference
            expected[7] = "restart_policy=always"
        }
        $0 != expected[NR] { exit 1 }
        END { exit(NR == 7 ? 0 : 1) }
    ' "$restart_policy_intent_file" \
        || fail "$restart_policy_key restart-policy intent content is not canonical"
}

prepare_candidate_restart_policy_intent()
{
    [ "$restart_policy_status" = unplanned ] \
        || fail "$restart_policy_key restart-policy intent was already planned"
    restart_policy_intent_candidate="${restart_policy_intent_file}.new"
    [ ! -e "$restart_policy_intent_file" ] && [ ! -L "$restart_policy_intent_file" ] \
        && [ ! -e "$restart_policy_intent_candidate" ] \
        && [ ! -L "$restart_policy_intent_candidate" ] \
        || fail "$restart_policy_key restart-policy intent path already exists"
    if ! (umask 077; set -C; : > "$restart_policy_intent_candidate") 2>/dev/null; then
        fail "$restart_policy_key restart-policy intent could not be reserved"
    fi
    {
        printf '%s\n' 'version=1'
        printf 'operation_id=%s\n' "$restart_policy_intent_operation_id"
        printf 'candidate_name=%s\n' "$restart_policy_container"
        printf 'candidate_id=%s\n' "$restart_policy_container_id"
        printf 'candidate_image_id=%s\n' "$restart_policy_image_id"
        printf 'candidate_image_reference=%s\n' "$restart_policy_image_reference"
        printf '%s\n' 'restart_policy=always'
    } > "$restart_policy_intent_candidate"
    chown "$immutable_uid:$immutable_gid" "$restart_policy_intent_candidate"
    chmod 600 "$restart_policy_intent_candidate"
    sync -f "$restart_policy_intent_candidate"
    mv "$restart_policy_intent_candidate" "$restart_policy_intent_file"
    sync -f "$operation_directory"
    set_candidate_restart_policy_state intent
    assert_candidate_restart_policy_intent
    write_state "$state_phase"
    test_crash "after-${restart_policy_key}-restart-policy-intent"
}

candidate_restart_policy_fence_call()
{
    case "$restart_policy_color" in
        green) runtime_fence_call "$@" ;;
        blue) reverse_runtime_fence_call "$@" ;;
    esac
}

ensure_candidate_member_restart_policy_always()
{
    select_candidate_restart_policy_context "$1" "$2"
    validate_container_id "$restart_policy_container_id" "$restart_policy_key candidate"
    validate_image_id "$restart_policy_image_id" "$restart_policy_key candidate image"
    [ "$restart_policy_runtime_sha256" != none ] \
        || fail "$restart_policy_key cannot change restart policy before static denial pinning"
    case "$restart_policy_status" in
        unplanned)
            prepare_candidate_restart_policy_intent
            ;;
        intent|updated|repinned)
            assert_candidate_restart_policy_intent
            ;;
        *)
            fail "$restart_policy_key restart-policy state is invalid"
            ;;
    esac

    if [ "$restart_policy_status" = intent ]; then
        assert_container_identity_present "$restart_policy_container" \
            "$restart_policy_container_id" "$restart_policy_image_id"
        observed_restart_policy=$(container_restart_policy "$restart_policy_container_id")
        case "$observed_restart_policy" in
            unless-stopped)
                assert_container_identity_present "$restart_policy_container" \
                    "$restart_policy_container_id" "$restart_policy_image_id"
                docker update --restart always "$restart_policy_container_id" >/dev/null
                ;;
            always)
                ;;
            *)
                fail "$restart_policy_key restart policy changed outside its durable intent"
                ;;
        esac
        test_crash "after-${restart_policy_key}-restart-policy-update"
        assert_container_identity_present "$restart_policy_container" \
            "$restart_policy_container_id" "$restart_policy_image_id"
        [ "$(container_restart_policy "$restart_policy_container_id")" = always ] \
            || fail "$restart_policy_key restart-policy update did not persist"
        set_candidate_restart_policy_state updated
        write_state "$state_phase"
    fi

    [ "$restart_policy_status" = updated ] || [ "$restart_policy_status" = repinned ] \
        || fail "$restart_policy_key restart policy did not reach its durable update"
    assert_container_identity_present "$restart_policy_container" \
        "$restart_policy_container_id" "$restart_policy_image_id"
    [ "$(container_restart_policy "$restart_policy_container_id")" = always ] \
        || fail "$restart_policy_key restart policy differs from its durable state"
}

ensure_candidate_restart_policy_always()
{
    restart_pool_color=$1
    case "$restart_pool_color" in
        green)
            restart_pool_web_a_id=$state_green_id
            restart_pool_web_b_id=$state_green_web_b_id
            ;;
        blue)
            restart_pool_web_a_id=$state_replacement_blue_id
            restart_pool_web_b_id=$state_replacement_blue_web_b_id
            ;;
        *) fail "unknown restart-policy pool color: $restart_pool_color" ;;
    esac
    ensure_candidate_member_restart_policy_always "$restart_pool_color" web-a
    ensure_candidate_member_restart_policy_always "$restart_pool_color" web-b
    select_candidate_restart_policy_context "$restart_pool_color" web-a
    restart_web_a_status=$restart_policy_status
    select_candidate_restart_policy_context "$restart_pool_color" web-b
    restart_web_b_status=$restart_policy_status
    case "$restart_web_a_status:$restart_web_b_status" in
        updated:updated)
            candidate_restart_policy_fence_call repin-pool \
                "$restart_pool_web_a_id" "$restart_pool_web_b_id"
            select_candidate_restart_policy_context "$restart_pool_color" web-a
            set_candidate_restart_policy_state repinned
            select_candidate_restart_policy_context "$restart_pool_color" web-b
            set_candidate_restart_policy_state repinned
            write_state "$state_phase"
            test_crash "after-${restart_pool_color}-pool-restart-policy-repin"
            ;;
        repinned:repinned)
            candidate_restart_policy_fence_call repin-pool \
                "$restart_pool_web_a_id" "$restart_pool_web_b_id"
            ;;
        *) fail "$restart_pool_color pool restart-policy states are not convergent" ;;
    esac
    assert_candidate_restart_policy_state "$restart_pool_color"
}

assert_candidate_member_restart_policy_state()
{
    select_candidate_restart_policy_context "$1" "$2"
    case "$restart_policy_status" in
        unplanned)
            [ "$(container_restart_policy "$restart_policy_container_id")" = unless-stopped ] \
                || fail "$restart_policy_color unplanned candidate restart policy changed"
            ;;
        intent)
            assert_candidate_restart_policy_intent
            case "$(container_restart_policy "$restart_policy_container_id")" in
                unless-stopped|always) ;;
                *) fail "$restart_policy_key policy is outside its in-flight intent" ;;
            esac
            ;;
        updated|repinned)
            assert_candidate_restart_policy_intent
            [ "$(container_restart_policy "$restart_policy_container_id")" = always ] \
                || fail "$restart_policy_key policy differs from durable state"
            ;;
        *)
            fail "$restart_policy_key restart-policy status is invalid"
            ;;
    esac
}

assert_candidate_restart_policy_state()
{
    restart_pool_color=$1
    case "$restart_pool_color" in
        green)
            restart_pool_web_a_id=$state_green_id
            restart_pool_web_b_id=$state_green_web_b_id
            ;;
        blue)
            restart_pool_web_a_id=$state_replacement_blue_id
            restart_pool_web_b_id=$state_replacement_blue_web_b_id
            ;;
        *) fail "unknown restart-policy pool color: $restart_pool_color" ;;
    esac
    assert_candidate_member_restart_policy_state "$restart_pool_color" web-a
    assert_candidate_member_restart_policy_state "$restart_pool_color" web-b
}

select_routed_candidate_context()
{
    case "$state_https_route_target" in
        green)
            routed_color=green
            routed_container=$green_container
            routed_container_id=$state_green_id
            routed_image_id=$state_green_image_id
            routed_web_a_state_volume=$green_state_volume
            routed_web_epoch=$green_web_epoch
            routed_web_b_container=$green_web_b_container
            routed_web_b_container_id=$state_green_web_b_id
            routed_web_b_state_volume=$green_web_b_private_volume
            routed_web_b_epoch=$green_web_b_epoch
            routed_ack_file=$green_applied_ack_file
            routed_mutation_freeze_epoch=$mutation_freeze_epoch
            ;;
        blue)
            routed_color=blue
            routed_container=$replacement_blue_container
            routed_container_id=$state_replacement_blue_id
            routed_image_id=$state_replacement_blue_image_id
            routed_web_a_state_volume=$blue_state_volume
            routed_web_epoch=$blue_web_epoch
            routed_web_b_container=$replacement_blue_web_b_container
            routed_web_b_container_id=$state_replacement_blue_web_b_id
            routed_web_b_state_volume=$blue_web_b_private_volume
            routed_web_b_epoch=$blue_web_b_epoch
            routed_ack_file=$blue_applied_ack_file
            routed_mutation_freeze_epoch=$reverse_mutation_freeze_epoch
            ;;
        *)
            return 1
            ;;
    esac
    select_pool_writer_context "$routed_color"
    routed_state_volume=$writer_volume
    routed_writer_epoch=$writer_epoch_value
    routed_writer_container_id=$writer_container_id
}

routed_phase_requires_post_revoke()
{
    case "$routed_color:$state_phase" in
        green:blue-revoked|green:proxy-mutation-freeze-activating|\
        green:proxy-mutation-freeze-active|\
        green:fence-release-intent|\
        blue:failback-green-revoked|blue:reverse-proxy-mutation-freeze-activating|\
        blue:reverse-proxy-mutation-freeze-active|blue:reverse-fence-release-intent)
            return 0
            ;;
    esac
    return 1
}

routed_green_is_reverse_incumbent()
{
    case "$routed_color:$state_phase" in
        green:reverse-fence-armed|green:reverse-fence-active|green:failback-*|\
        green:reverse-rollback-*)
            return 0
            ;;
    esac
    return 1
}

routed_green_reverse_fence_is_transitioning()
{
    case "$routed_color:$state_phase" in
        green:reverse-fence-prepare-intent|green:reverse-fence-prepared|\
        green:reverse-fence-captured)
            return 0
            ;;
    esac
    return 1
}

reconcile_irreversible_revocation_phase()
{
    case "$state_phase" in
        blue-revoking)
            docker_container_presence "$blue_container"
            if [ "$container_presence" = absent ] \
                || [ "$container_presence_running" != true ]; then
                if [ "$container_presence" = present ]; then
                    revoke_legacy_blue \
                        || fail 'stopped recorded blue removal was not proven; green promotion is forbidden'
                fi
                assert_container_absent "$blue_container"
                state_phase=blue-revoked
                write_state "$state_phase"
                test_crash after-blue-revoked
            fi
            ;;
        failback-green-revoking)
            docker_container_presence "$green_container"
            revoking_green_a_presence=$container_presence
            revoking_green_a_running=$container_presence_running
            docker_container_presence "$green_web_b_container"
            revoking_green_b_presence=$container_presence
            revoking_green_b_running=$container_presence_running
            if [ "$revoking_green_a_presence:$revoking_green_a_running:$revoking_green_b_presence:$revoking_green_b_running" \
                != present:true:present:true ]; then
                if [ "$revoking_green_a_presence" = present ]; then
                    stop_and_remove_container "$green_container" green-web-a \
                        "$state_green_id" "$state_green_image_id"
                fi
                if [ "$revoking_green_b_presence" = present ]; then
                    stop_and_remove_container "$green_web_b_container" green-web-b \
                        "$state_green_web_b_id" "$state_green_web_b_image_id"
                fi
                assert_container_absent "$green_container"
                assert_container_absent "$green_web_b_container"
                select_pool_writer_context green
                revoke_marker "$writer_volume" green "$writer_epoch_value" matching
                revoke_marker "$green_state_volume" green "$green_web_epoch" matching web-epoch
                revoke_marker "$green_web_b_private_volume" green-web-b "$green_web_b_epoch" \
                    matching web-epoch
                state_phase=failback-green-revoked
                write_state "$state_phase"
                test_crash after-failback-green-revoked
            fi
            ;;
    esac
}

routed_phase_allows_released_fence()
{
    released_fence_operator_phase=${1:-$state_phase}
    case "$routed_color:$released_fence_operator_phase" in
        green:fence-release-intent|green:fence-released|green:green-writer-promoting|\
        green:green-writer-promoted-freeze-active|green:green-promoted-finalizing|\
        green:green-writer-promoted|green:reverse-fence-artifacts-preparing|\
        green:reverse-fence-prepare-intent|green:reverse-fence-prepared|\
        green:reverse-fence-captured|green:reverse-fence-armed|green:reverse-fence-active|\
        green:failback-blue-starting|green:failback-blue-started-unproven|\
        green:failback-blue-started|green:failback-blue-web-activating|\
        green:failback-blue-web-activated|green:failback-blue-https-routing|\
        green:failback-blue-https-routed|\
        green:blue-failback-routed|green:failback-green-scheduler-stopping|\
        green:failback-green-scheduler-stopped|green:failback-green-horizon-pausing|\
        green:failback-green-horizon-paused|\
        green:failback-green-drain-inventory-recording|\
        green:failback-green-drain-inventory-recorded|\
        green:failback-green-background-drain-first|\
        green:failback-green-background-zero-first|\
        green:failback-green-background-zero-proven|\
        green:failback-green-horizon-stopping|green:failback-green-horizon-stopped|\
        green:failback-green-nightwatch-stopping|green:failback-green-nightwatch-stopped|\
        green:failback-green-http-drain-first|green:failback-green-http-zero-first|\
        green:failback-green-drained|green:failback-blue-final-ingress-verifying|\
        green:failback-blue-final-ingress-acknowledged|green:failback-green-revoking|\
        green:reverse-rollback-routing-green|green:reverse-rollback-green-routed|\
        green:failback-green-revoked|green:reverse-proxy-mutation-freeze-activating|\
        green:reverse-proxy-mutation-freeze-active|green:reverse-fence-release-intent|\
        green:reverse-fence-released|green:blue-writer-promoting|\
        green:blue-writer-promoted-freeze-active|green:blue-promoted-finalizing|\
        green:blue-writer-promoted|\
        blue:reverse-fence-release-intent|blue:reverse-fence-released|\
        blue:blue-writer-promoting|blue:blue-writer-promoted-freeze-active|\
        blue:blue-promoted-finalizing|blue:blue-writer-promoted)
            return 0
            ;;
    esac
    return 1
}

routed_candidate_fence_phase()
{
    case "$routed_color" in
        green)
            if routed_green_is_reverse_incumbent; then
                routed_fence_status=$(reverse_runtime_fence_call status)
            else
                routed_fence_status=$(runtime_fence_call status)
            fi
            ;;
        blue) routed_fence_status=$(reverse_runtime_fence_call status) ;;
    esac
    routed_fence_phase=$(printf '%s\n' "$routed_fence_status" \
        | tr ' ' '\n' | sed -n 's/^phase=//p')
    [ -n "$routed_fence_phase" ] || fail 'routed candidate fence status omitted its phase'
}

verify_routed_candidate_fence()
{
    routed_green_reverse_fence_is_transitioning && return
    routed_candidate_fence_phase
    if routed_green_is_reverse_incumbent; then
        [ "$routed_fence_phase" = active ] \
            || fail 'reverse fence is not active while green remains the routed incumbent'
        reverse_runtime_fence_call verify
        return
    fi
    case "$routed_fence_phase" in
        active)
            if routed_phase_requires_post_revoke; then
                case "$routed_color" in
                    green)
                        runtime_fence_call repin-pool "$state_green_id" \
                            "$state_green_web_b_id"
                        runtime_fence_call verify-post-revoke
                        ;;
                    blue)
                        reverse_runtime_fence_call repin-pool "$state_replacement_blue_id" \
                            "$state_replacement_blue_web_b_id"
                        reverse_runtime_fence_call verify-post-revoke
                        ;;
                esac
            else
                case "$routed_color" in
                    green)
                        runtime_fence_call repin-pool "$state_green_id" \
                            "$state_green_web_b_id"
                        runtime_fence_call verify
                        ;;
                    blue)
                        reverse_runtime_fence_call repin-pool "$state_replacement_blue_id" \
                            "$state_replacement_blue_web_b_id"
                        reverse_runtime_fence_call verify
                        ;;
                esac
            fi
            ;;
        released|finalized)
            routed_phase_allows_released_fence \
                || fail "$routed_color candidate fence released before the operator release phase"
            case "$routed_color" in
                green) runtime_fence_call verify-released ;;
                blue) reverse_runtime_fence_call verify-released ;;
            esac
            ;;
        release-in-progress)
            routed_phase_allows_released_fence \
                || fail "$routed_color candidate fence began release before the operator release phase"
            case "$routed_color" in
                green) runtime_fence_call release ;;
                blue) reverse_runtime_fence_call release ;;
            esac
            routed_candidate_fence_phase
            [ "$routed_fence_phase" = released ] \
                || fail "$routed_color candidate fence did not converge its interrupted release"
            case "$routed_color" in
                green) runtime_fence_call verify-released ;;
                blue) reverse_runtime_fence_call verify-released ;;
            esac
            ;;
        aborted)
            case "$routed_color:$state_phase:$state_reverse_fence_generation" in
                green:green-writer-promoted:none|green:reverse-fence-artifacts-preparing:none)
                    fail 'green routed candidate has no reverse generation for an aborted fence'
                    ;;
                green:green-writer-promoted:*|green:reverse-fence-artifacts-preparing:*)
                    ;;
                *)
                    fail "$routed_color routed candidate fence is aborted outside a terminal reverse rollback"
                    ;;
            esac
            ;;
        *)
            fail "$routed_color routed candidate fence is not active or durably released: $routed_fence_phase"
            ;;
    esac
}

assert_routed_candidate_contract()
{
    case "$routed_color" in
        green) assert_green_state ;;
        blue) assert_replacement_blue_state ;;
    esac
    assert_candidate_restart_policy_state "$routed_color"
    verify_routed_candidate_fence
    wait_for_container_health "$routed_container_id"
    wait_for_container_health "$routed_web_b_container_id"
    direct_origin_probe "$routed_container"
    direct_origin_probe "$routed_web_b_container"
    if [ "$(marker_status "$routed_web_a_state_volume" "$routed_web_epoch" web-epoch)" \
        != matching ] \
        || ! docker exec "$routed_container_id" \
            /usr/local/bin/coolify-entrypoint web-activated \
        || [ "$(marker_status "$routed_web_b_state_volume" "$routed_web_b_epoch" web-epoch)" \
            != matching ] \
        || ! docker exec "$routed_web_b_container_id" \
            /usr/local/bin/coolify-entrypoint web-activated; then
        fail "$routed_color routed pool web activation is not durable"
    fi
    [ "$(docker exec "$routed_container_id" \
        /usr/local/bin/coolify-entrypoint mode)" = active ] \
        && [ "$(docker exec "$routed_web_b_container_id" \
            /usr/local/bin/coolify-entrypoint mode)" = active ] \
        || fail "$routed_color routed pool is not in active control-plane mode"
    case "$(marker_status "$routed_state_volume" "$routed_writer_epoch")" in
        absent)
            prove_candidate_background_services_down "$routed_container_id"
            prove_candidate_background_services_down "$routed_web_b_container_id"
            ;;
        matching)
            prove_promoted_background_services "$routed_writer_container_id"
            case "$routed_writer_container_id" in
                "$routed_container_id")
                    prove_candidate_background_services_down "$routed_web_b_container_id"
                    ;;
                "$routed_web_b_container_id")
                    prove_candidate_background_services_down "$routed_container_id"
                    ;;
                *) fail "$routed_color selected writer is outside the exact routed pool" ;;
            esac
            ;;
        *)
            fail "$routed_color routed candidate writer marker is stale or mismatched"
            ;;
    esac
    if [ "$state_https_route_target" = "$routed_color" ]; then
        ingress_controller_assert ingress "$routed_color" "$routed_container" \
            "$backend_port" "$routed_ack_file"
    fi
}

routed_recovery_direction()
{
    case "$routed_color" in
        green) printf '%s\n' forward ;;
        blue) printf '%s\n' reverse ;;
        *) fail 'routed daemon recovery color is invalid' ;;
    esac
}

routed_recovery_provider_expectation()
{
    recovery_context_phase=$1
    if routed_phase_allows_released_fence "$recovery_context_phase"; then
        printf '%s\n' absent
        return
    fi
    case "$routed_color:$recovery_context_phase" in
        green:blue-revoked|green:proxy-mutation-freeze-activating|\
        green:proxy-mutation-freeze-active|\
        blue:failback-green-revoked|blue:reverse-proxy-mutation-freeze-activating|\
        blue:reverse-proxy-mutation-freeze-active)
            printf '%s\n' absent
            ;;
        *)
            printf '%s\n' incumbent
            ;;
    esac
}

routed_runtime_fence_is_current()
{
    routed_green_reverse_fence_is_transitioning && return
    routed_candidate_fence_phase
    if routed_green_is_reverse_incumbent; then
        [ "$routed_fence_phase" = active ] || return 1
        reverse_runtime_fence_call verify
        return
    fi
    case "$routed_fence_phase" in
        active)
            if routed_phase_requires_post_revoke; then
                case "$routed_color" in
                    green) runtime_fence_call verify-post-revoke ;;
                    blue) reverse_runtime_fence_call verify-post-revoke ;;
                esac
            else
                case "$routed_color" in
                    green) runtime_fence_call verify ;;
                    blue) reverse_runtime_fence_call verify ;;
                esac
            fi
            ;;
        released|finalized)
            routed_phase_allows_released_fence || return 1
            case "$routed_color" in
                green) runtime_fence_call verify-released ;;
                blue) reverse_runtime_fence_call verify-released ;;
            esac
            ;;
        release-in-progress)
            routed_phase_allows_released_fence || return 1
            case "$routed_color" in
                green) runtime_fence_call release ;;
                blue) reverse_runtime_fence_call release ;;
            esac
            ;;
        aborted)
            case "$routed_color:$state_phase:$state_reverse_fence_generation" in
                green:green-writer-promoted:none|green:reverse-fence-artifacts-preparing:none)
                    return 1
                    ;;
                green:green-writer-promoted:*|green:reverse-fence-artifacts-preparing:*)
                    ;;
                *) return 1 ;;
            esac
            ;;
        *)
            return 1
            ;;
    esac
}

rewind_daemon_interrupted_drain()
{
    case "$routed_color:$state_routed_recovery_from_phase" in
        green:blue-scheduler-stopping|green:blue-scheduler-stopped|\
        green:blue-horizon-pausing|green:blue-horizon-paused|\
        green:blue-drain-inventory-recording|green:blue-drain-inventory-recorded|\
        green:blue-background-drain-first|green:blue-background-zero-first|\
        green:blue-background-zero-proven|green:blue-horizon-stopping|\
        green:blue-horizon-stopped|green:blue-nightwatch-stopping|\
        green:blue-nightwatch-stopped|green:blue-http-drain-first|\
        green:blue-http-zero-first|green:blue-drained|\
        green:green-final-ingress-verifying|green:green-final-ingress-acknowledged)
            state_phase=green-routed
            ;;
        blue:failback-green-scheduler-stopping|blue:failback-green-scheduler-stopped|\
        blue:failback-green-horizon-pausing|blue:failback-green-horizon-paused|\
        blue:failback-green-drain-inventory-recording|\
        blue:failback-green-drain-inventory-recorded|\
        blue:failback-green-background-drain-first|\
        blue:failback-green-background-zero-first|\
        blue:failback-green-background-zero-proven|\
        blue:failback-green-horizon-stopping|blue:failback-green-horizon-stopped|\
        blue:failback-green-nightwatch-stopping|blue:failback-green-nightwatch-stopped|\
        blue:failback-green-http-drain-first|blue:failback-green-http-zero-first|\
        blue:failback-green-drained|blue:failback-blue-final-ingress-verifying|\
        blue:failback-blue-final-ingress-acknowledged)
            state_phase=blue-failback-routed
            ;;
    esac
}

routed_daemon_recovery_call()
{
    routed_recovery_action=$1
    shift
    case "$routed_color" in
        green) runtime_fence_call "$routed_recovery_action" "$@" ;;
        blue) reverse_runtime_fence_call "$routed_recovery_action" "$@" ;;
    esac
}

route_proof_file()
{
    printf '%s/runtime-recovery-route-proof-g%06d.state\n' "$operation_directory" \
        "$state_routed_recovery_generation"
}

routed_route_proof_value()
{
    route_proof_document=$1
    route_proof_key=$2
    awk -F= -v expected_key="$route_proof_key" '
        $1 == expected_key { matches++; result = substr($0, length($1) + 2) }
        END { if (matches != 1) exit 1; print result }
    ' "$route_proof_document" \
        || fail "routed daemon recovery route proof key is absent or duplicated: $route_proof_key"
}

validate_routed_daemon_route_proof()
{
    route_proof_document=$1
    expected_writer_status=$2
    expected_freeze_status=$3
    expected_ack_sha256=$(sha256_file "$routed_ack_file")
    awk -F= '
        BEGIN {
            split("version operation_id generation direction color parent_phase provider_expectation candidate_id candidate_image_id writer_status mutation_freeze_status https_ack_sha256 proven_at_epoch", keys, /[[:space:]]+/)
            for (key_index in keys) allowed[keys[key_index]] = 1
        }
        NF < 2 || $1 == "" || !($1 in allowed) || seen[$1] { invalid = 1; exit }
        { seen[$1] = 1; seen_count++ }
        END { exit(!invalid && seen_count == 13 ? 0 : 1) }
    ' "$route_proof_document" \
        || fail 'routed daemon recovery route proof has an unknown, duplicate, or malformed record'
    [ "$(routed_route_proof_value "$route_proof_document" version)" = 1 ] \
        && [ "$(routed_route_proof_value "$route_proof_document" operation_id)" \
            = "$operation_id" ] \
        && [ "$(routed_route_proof_value "$route_proof_document" generation)" \
            = "$state_routed_recovery_generation" ] \
        && [ "$(routed_route_proof_value "$route_proof_document" direction)" \
            = "$(routed_recovery_direction)" ] \
        && [ "$(routed_route_proof_value "$route_proof_document" color)" \
            = "$routed_color" ] \
        && [ "$(routed_route_proof_value "$route_proof_document" parent_phase)" \
            = "$state_routed_recovery_from_phase" ] \
        && [ "$(routed_route_proof_value "$route_proof_document" provider_expectation)" \
            = "$(routed_recovery_provider_expectation "$state_routed_recovery_from_phase")" ] \
        && [ "$(routed_route_proof_value "$route_proof_document" candidate_id)" \
            = "$routed_container_id" ] \
        && [ "$(routed_route_proof_value "$route_proof_document" candidate_image_id)" \
            = "$routed_image_id" ] \
        && [ "$(routed_route_proof_value "$route_proof_document" writer_status)" \
            = "$expected_writer_status" ] \
        && [ "$(routed_route_proof_value "$route_proof_document" mutation_freeze_status)" \
            = "$expected_freeze_status" ] \
        && [ "$(routed_route_proof_value "$route_proof_document" https_ack_sha256)" \
            = "$expected_ack_sha256" ] \
        || fail 'routed daemon recovery route proof differs from its exact recovery context'
    printf '%s\n' "$(routed_route_proof_value "$route_proof_document" proven_at_epoch)" \
        | grep -E -q '^[1-9][0-9]*$' \
        || fail 'routed daemon recovery route proof timestamp is malformed'
}

reconcile_unpublished_routed_route_proof_candidate()
{
    unpublished_route_proof=$1
    unpublished_route_candidate=$2
    if [ ! -e "$unpublished_route_candidate" ] \
        && [ ! -L "$unpublished_route_candidate" ]; then
        return 0
    fi
    [ ! -e "$unpublished_route_proof" ] && [ ! -L "$unpublished_route_proof" ] \
        || fail 'routed daemon recovery route proof candidate exists beside its destination'
    assert_non_symlink_regular_file "$unpublished_route_candidate" \
        'unpublished routed daemon recovery route proof candidate'
    [ "$(file_uid "$unpublished_route_candidate")" = "$immutable_uid" ] \
        && [ "$(file_gid "$unpublished_route_candidate")" = "$immutable_gid" ] \
        && [ "$(file_mode "$unpublished_route_candidate")" = 600 ] \
        && [ "$(file_link_count "$unpublished_route_candidate")" = 1 ] \
        || fail 'unpublished routed daemon recovery route proof candidate is unsafe'
    rm -f -- "$unpublished_route_candidate" \
        || fail 'unpublished routed daemon recovery route proof candidate cleanup failed'
    sync -f "$operation_directory"
    [ ! -e "$unpublished_route_candidate" ] && [ ! -L "$unpublished_route_candidate" ] \
        && [ ! -e "$unpublished_route_proof" ] && [ ! -L "$unpublished_route_proof" ] \
        || fail 'unpublished routed daemon recovery route proof cleanup was incomplete'
}

write_routed_daemon_route_proof()
{
    recovery_route_proof=$(route_proof_file)
    recovery_route_candidate="${recovery_route_proof}.new"
    if [ ! -e "$recovery_route_proof" ] && [ ! -L "$recovery_route_proof" ]; then
        reconcile_unpublished_routed_route_proof_candidate "$recovery_route_proof" \
            "$recovery_route_candidate"
    fi
    recovery_writer_status=$(marker_status "$routed_state_volume" "$routed_writer_epoch")
    recovery_freeze_status=$(marker_status "$routed_state_volume" \
        "$routed_mutation_freeze_epoch" mutation-freeze-epoch)
    if [ -e "$recovery_route_proof" ] || [ -L "$recovery_route_proof" ]; then
        assert_non_symlink_regular_file "$recovery_route_proof" \
            'routed daemon recovery route proof'
        recovery_route_proof_identity=$(file_device_inode "$recovery_route_proof")
        recovery_route_proof_metadata=$(file_metadata "$recovery_route_proof")
        [ "$(file_uid "$recovery_route_proof")" = "$immutable_uid" ] \
            && [ "$(file_gid "$recovery_route_proof")" = "$immutable_gid" ] \
            && [ "$(file_mode "$recovery_route_proof")" = 600 ] \
            || fail 'routed daemon recovery route proof metadata changed'
        case "$(file_link_count "$recovery_route_proof")" in
            1)
                [ ! -e "$recovery_route_candidate" ] \
                    && [ ! -L "$recovery_route_candidate" ] \
                    || fail 'routed daemon recovery route proof has an unexpected detached candidate'
                validate_routed_daemon_route_proof "$recovery_route_proof" \
                    "$recovery_writer_status" "$recovery_freeze_status"
                sync -f "$operation_directory"
                [ "$(file_device_inode "$recovery_route_proof")" \
                    = "$recovery_route_proof_identity" ] \
                    && [ "$(file_metadata "$recovery_route_proof")" \
                        = "$recovery_route_proof_metadata" ] \
                    && [ "$(file_link_count "$recovery_route_proof")" = 1 ] \
                    || fail 'routed daemon recovery route proof did not remain one inode'
                return 0
                ;;
            2)
                assert_non_symlink_regular_file "$recovery_route_candidate" \
                    'routed daemon recovery route proof candidate'
                [ "$(file_device_inode "$recovery_route_candidate")" \
                    = "$recovery_route_proof_identity" ] \
                    && [ "$(file_metadata "$recovery_route_candidate")" \
                        = "$recovery_route_proof_metadata" ] \
                    && [ "$(file_link_count "$recovery_route_candidate")" = 2 ] \
                    || fail 'routed daemon recovery route proof has an unsafe second hard link'
                if ! rm -f -- "$recovery_route_candidate"; then
                    fail 'routed daemon recovery route proof candidate cleanup failed'
                fi
                sync -f "$operation_directory"
                [ "$(file_device_inode "$recovery_route_proof")" \
                    = "$recovery_route_proof_identity" ] \
                    && [ "$(file_metadata "$recovery_route_proof")" \
                        = "$recovery_route_proof_metadata" ] \
                    && [ "$(file_link_count "$recovery_route_proof")" = 1 ] \
                    || fail 'routed daemon recovery route proof did not converge one inode'
                return 0
                ;;
            *) fail 'routed daemon recovery route proof link count is unsafe' ;;
        esac
    fi
    [ "$state_https_route_target" = "$routed_color" ] \
        || fail 'daemon recovery cannot finalize before the persisted ingress target agrees'
    ingress_controller_assert ingress "$routed_color" "$routed_container" "$backend_port" \
        "$routed_ack_file" >&2
    case "$recovery_writer_status" in
        absent)
            [ "$recovery_freeze_status" = matching ] \
                || fail 'routed daemon recovery mutation freeze is not durable'
            prove_candidate_background_services_down "$routed_container_id"
            prove_mutation_freeze_quiesced "$routed_container_id" \
                "$routed_mutation_freeze_epoch"
            ;;
        matching)
            prove_promoted_background_services "$routed_container_id"
            case "$routed_color" in
                green)
                    assert_container_absent "$blue_container"
                    ;;
                blue)
                    assert_container_absent "$green_container"
                    assert_container_absent "$blue_container"
                    ;;
            esac
            ;;
        *) fail 'routed daemon recovery writer marker is stale or mismatched' ;;
    esac
    [ ! -e "$recovery_route_candidate" ] && [ ! -L "$recovery_route_candidate" ] \
        || fail 'routed daemon recovery route proof candidate already exists'
    if ! (umask 077; set -C; : > "$recovery_route_candidate") 2>/dev/null; then
        fail 'could not reserve routed daemon recovery route proof candidate'
    fi
    test_crash "after-${routed_color}-routed-recovery-route-proof-candidate-reserve"
    {
        printf 'version=1\noperation_id=%s\ngeneration=%s\n' "$operation_id" \
            "$state_routed_recovery_generation"
        printf 'direction=%s\ncolor=%s\nparent_phase=%s\nprovider_expectation=%s\n' \
            "$(routed_recovery_direction)" "$routed_color" \
            "$state_routed_recovery_from_phase" \
            "$(routed_recovery_provider_expectation "$state_routed_recovery_from_phase")"
        test_crash "after-${routed_color}-routed-recovery-route-proof-candidate-header"
        printf 'candidate_id=%s\ncandidate_image_id=%s\nwriter_status=%s\n' \
            "$routed_container_id" "$routed_image_id" "$recovery_writer_status"
        printf 'mutation_freeze_status=%s\nhttps_ack_sha256=%s\n' \
            "$recovery_freeze_status" "$(sha256_file "$routed_ack_file")"
        printf 'proven_at_epoch=%s\n' "$(date -u +%s)"
    } >> "$recovery_route_candidate"
    test_crash "after-${routed_color}-routed-recovery-route-proof-candidate-write"
    chown "$immutable_uid:$immutable_gid" "$recovery_route_candidate"
    chmod 600 "$recovery_route_candidate"
    test_crash "after-${routed_color}-routed-recovery-route-proof-candidate-metadata"
    sync -f "$recovery_route_candidate"
    test_crash "after-${routed_color}-routed-recovery-route-proof-candidate-fsync"
    validate_routed_daemon_route_proof "$recovery_route_candidate" \
        "$recovery_writer_status" "$recovery_freeze_status"
    recovery_route_candidate_identity=$(file_device_inode "$recovery_route_candidate")
    recovery_route_candidate_metadata=$(file_metadata "$recovery_route_candidate")
    [ "$(file_link_count "$recovery_route_candidate")" = 1 ] \
        || fail 'routed daemon recovery route proof candidate has extra hard links'
    [ ! -e "$recovery_route_proof" ] && [ ! -L "$recovery_route_proof" ] \
        || fail 'routed daemon recovery route proof destination appeared'
    ln "$recovery_route_candidate" "$recovery_route_proof" 2>/dev/null \
        || fail 'routed daemon recovery route proof no-replace publication failed'
    assert_non_symlink_regular_file "$recovery_route_proof" \
        'routed daemon recovery route proof'
    [ "$(file_device_inode "$recovery_route_proof")" \
            = "$recovery_route_candidate_identity" ] \
        && [ "$(file_metadata "$recovery_route_proof")" \
            = "$recovery_route_candidate_metadata" ] \
        && [ "$(file_link_count "$recovery_route_candidate")" = 2 ] \
        && [ "$(file_link_count "$recovery_route_proof")" = 2 ] \
        || fail 'routed daemon recovery route proof publication identity is unsafe'
    test_crash "after-${routed_color}-routed-recovery-route-proof-linked"
    if ! rm -f -- "$recovery_route_candidate"; then
        fail 'routed daemon recovery route proof candidate cleanup failed'
    fi
    test_crash "after-${routed_color}-routed-recovery-route-proof-candidate-unlinked"
    sync -f "$operation_directory"
    [ "$(file_device_inode "$recovery_route_proof")" \
            = "$recovery_route_candidate_identity" ] \
        && [ "$(file_metadata "$recovery_route_proof")" \
            = "$recovery_route_candidate_metadata" ] \
        && [ "$(file_link_count "$recovery_route_proof")" = 1 ] \
        || fail 'routed daemon recovery route proof did not freeze one inode'
}

finalize_routed_daemon_recovery_if_ready()
{
    [ "$state_routed_recovery_status" = runtime-verified ] || return 0
    [ "$state_https_route_target" = "$routed_color" ] || return 0
    write_routed_daemon_route_proof
    recovery_route_proof=$(route_proof_file)
    recovery_route_proof_sha256=$(sha256_file "$recovery_route_proof")
    routed_daemon_recovery_call finalize-routed-runtime-recovery \
        "$(routed_recovery_direction)" "$routed_color" \
        "$state_routed_recovery_from_phase" \
        "$(routed_recovery_provider_expectation "$state_routed_recovery_from_phase")" \
        "$recovery_route_proof_sha256"
    state_routed_recovery_status=verified
    write_state "$state_phase"
    test_crash "after-${routed_color}-routed-recovery-verified"
}

reconcile_routed_candidate()
{
    case "$state_phase" in
        fence-abort-intent|reverse-fence-abort-intent)
            fail 'runtime-fence abort intent must resume through rollback convergence'
            ;;
    esac
    reconcile_irreversible_revocation_phase
    select_routed_candidate_context || return 0
    validate_container_id "$routed_container_id" "$routed_color routed candidate"
    validate_image_id "$routed_image_id" "$routed_color routed candidate image"
    assert_container_identity_present "$routed_container" "$routed_container_id" "$routed_image_id"
    docker_container_presence "$routed_container"
    routed_recovery_required=false
    case "$state_routed_recovery_status" in
        intent|started|runtime-verified)
            [ "$state_routed_recovery_color" = "$routed_color" ] \
                || fail 'routed-recovery color changed during an unfinished generation'
            routed_recovery_required=true
            ;;
        none|verified)
            routed_recovery_classification=none
            if [ "$container_presence_running" != true ]; then
                routed_recovery_classification=stopped
            elif ! routed_runtime_fence_is_current >/dev/null 2>&1; then
                routed_recovery_classification=$(routed_daemon_recovery_call \
                    classify-routed-runtime "$(routed_recovery_direction)" \
                    "$routed_color" "$state_phase" \
                    "$(routed_recovery_provider_expectation "$state_phase")") \
                    || fail 'routed runtime drift classification failed'
                case "$routed_recovery_classification" in
                    current)
                        routed_runtime_fence_is_current
                        ;;
                    daemon|candidate)
                        test_crash "after-${routed_color}-routed-recovery-classified"
                        ;;
                    *)
                        fail 'routed runtime drift classification returned an unknown result'
                        ;;
                esac
            fi
            case "$routed_recovery_classification" in
                stopped|daemon|candidate)
                state_routed_recovery_generation=$((state_routed_recovery_generation + 1))
                state_routed_recovery_color=$routed_color
                state_routed_recovery_status=intent
                state_routed_recovery_from_phase=$state_phase
                rewind_daemon_interrupted_drain
                write_state "$state_phase"
                test_crash "after-${routed_color}-routed-recovery-intent"
                routed_recovery_required=true
                    ;;
                none|current)
                    ;;
            esac
            ;;
    esac
    if [ "$routed_recovery_required" = true ] \
        && [ "$container_presence_running" != true ]; then
        assert_container_identity_present "$routed_container" "$routed_container_id" \
            "$routed_image_id"
        docker start "$routed_container_id" \
            > "$operation_directory/${routed_color}-routed-recovery-start.log" 2>&1
        test_crash "after-${routed_color}-routed-recovery-start"
        assert_container_identity_present "$routed_container" "$routed_container_id" \
            "$routed_image_id"
        state_routed_recovery_status=started
        write_state "$state_phase"
    elif [ "$routed_recovery_required" = true ] \
        && [ "$state_routed_recovery_status" = intent ]; then
        [ "$state_routed_recovery_color" = "$routed_color" ] \
            || fail 'running routed candidate differs from unfinished recovery intent'
        state_routed_recovery_status=started
        write_state "$state_phase"
    fi
    if [ "$routed_recovery_required" = true ] \
        && [ "$state_routed_recovery_status" != runtime-verified ]; then
        routed_daemon_recovery_call recover-routed-runtime "$(routed_recovery_direction)" \
            "$routed_color" "$state_routed_recovery_from_phase" \
            "$(routed_recovery_provider_expectation "$state_routed_recovery_from_phase")"
        state_routed_recovery_status=runtime-verified
        write_state "$state_phase"
        test_crash "after-${routed_color}-routed-recovery-runtime-verified"
    fi
    assert_routed_candidate_contract
    finalize_routed_daemon_recovery_if_ready
}

assert_pool_member_runtime()
{
    container_name=$1
    expected_image=$2
    expected_private_volume=$3
    expected_loopback_port=$4
    expected_color=$5
    expected_role=$6
    expected_route_identity=$7
    expected_web_epoch=$8
    expected_route_drain_epoch=$9
    shift 9
    expected_direct_probe_token_file=$1
    expected_applied_ack_file=$2

    case "$expected_color" in
        green)
            expected_runtime_env_file=$green_runtime_env_file
            expected_runtime_env_path=$state_green_runtime_env_path
            expected_runtime_env_sha256=$state_green_runtime_env_sha256
            expected_mutation_freeze_epoch=$mutation_freeze_epoch
            expected_writer_member=$green_writer_member
            expected_writer_epoch=$writer_epoch
            expected_route_health_token_file=$green_route_health_token_file
            expected_pool_ack_file=$green_pool_ack_file
            expected_plan_image_reference=$state_green_plan_image_reference
            expected_plan_image_id=$state_green_plan_image_id
            expected_plan_network_id=$state_green_plan_network_id
            ;;
        blue)
            expected_runtime_env_file=$blue_runtime_env_file
            expected_runtime_env_path=$state_blue_runtime_env_path
            expected_runtime_env_sha256=$state_blue_runtime_env_sha256
            expected_mutation_freeze_epoch=$reverse_mutation_freeze_epoch
            expected_writer_member=$blue_writer_member
            expected_writer_epoch=$blue_writer_epoch
            expected_route_health_token_file=$blue_route_health_token_file
            expected_pool_ack_file=$blue_pool_ack_file
            expected_plan_image_reference=$state_replacement_blue_plan_image_reference
            expected_plan_image_id=$state_replacement_blue_plan_image_id
            expected_plan_network_id=$state_replacement_blue_plan_network_id
            ;;
        *) fail "unknown pool member runtime color: $expected_color" ;;
    esac
    if [ "$expected_role" = "$expected_writer_member" ]; then
        expected_member_writer_epoch=$expected_writer_epoch
        expected_member_writer_path=$WRITER_MARKER_PATH
    else
        expected_member_writer_epoch=
        expected_member_writer_path=
    fi

    assert_control_plane_proxy_peer_addresses
    assert_container_running "$container_name"
    assert_container_uses_image "$container_name" "$expected_image"
    [ "$(container_restart_policy "$container_name")" = unless-stopped ] \
        || fail "$expected_color $expected_role restart policy changed before promotion"
    [ "$(docker exec "$container_name" id -u)" = 9999 ] \
        && [ "$(docker exec "$container_name" id -g)" = 9999 ] \
        || fail "$expected_color $expected_role is not running as UID/GID 9999"
    assert_runtime_env_artifact "$expected_runtime_env_file" "$expected_runtime_env_path" \
        "$expected_runtime_env_sha256" "$expected_color runtime environment artifact"
    assert_bind_mount "$container_name" /var/www/html/.env "$expected_runtime_env_file" false
    docker exec "$container_name" test -r /var/www/html/.env \
        || fail "$expected_color $expected_role cannot read its runtime environment artifact"
    candidate_runtime_env_sha256=$(docker exec "$container_name" \
        sha256sum /var/www/html/.env | awk '{print $1}')
    [ "$candidate_runtime_env_sha256" = "$state_source_env_sha256" ] \
        || fail "$expected_color $expected_role sees runtime environment bytes that differ from source"
    assert_bind_mount "$container_name" /var/www/html/storage/app/ssh "$ssh_directory" true
    assert_bind_mount "$container_name" /var/www/html/storage/app/applications "$applications_directory" true
    assert_bind_mount "$container_name" /var/www/html/storage/app/databases "$databases_directory" true
    assert_bind_mount "$container_name" /var/www/html/storage/app/services "$services_directory" true
    assert_bind_mount "$container_name" /var/www/html/storage/app/backups "$backups_directory" true
    assert_volume_mount "$container_name" /var/lib/coolify-control-plane/private \
        "$expected_private_volume"
    assert_volume_mount "$container_name" /var/lib/coolify-control-plane/coordination \
        "$coordination_volume"
    docker inspect "$container_name" | jq --exit-status '
        .[0].Mounts | all(.Source != "/var/run/docker.sock" and .Destination != "/var/run/docker.sock")
    ' >/dev/null || fail "$expected_color $expected_role has a Docker socket mount"
    docker inspect "$container_name" \
        | jq --exit-status --arg network "$control_plane_network" \
            '.[0].NetworkSettings.Networks | keys == [$network]' >/dev/null \
        || fail "$expected_color $expected_role is not exclusively on the control-plane network"
    docker inspect "$container_name" \
        | jq --exit-status --arg color "$expected_color" \
            --arg member "$expected_route_identity" \
            --arg writer_epoch "$expected_member_writer_epoch" \
            --arg writer_path "$expected_member_writer_path" \
            --arg web_epoch "$expected_web_epoch" \
            --arg web_path "$WEB_MARKER_PATH" \
            --arg drain_epoch "$expected_route_drain_epoch" \
            --arg drain_path "$ROUTE_DRAIN_MARKER_PATH" \
            --arg freeze_epoch "$expected_mutation_freeze_epoch" \
            --arg freeze_path "$MUTATION_FREEZE_MARKER_PATH" \
            --arg pool_ack_path /run/secrets/control-plane-pool-ack \
            --arg trusted_proxy_addresses "$control_plane_trusted_proxy_addresses" \
            --arg backend_port "$backend_port" \
            --arg probe_path "$direct_probe_path" '
            ([.[0].Config.Env[] | select(startswith("CONTROL_PLANE_"))] | sort) == ([
                "CONTROL_PLANE_BACKEND_PORT=" + $backend_port,
                "CONTROL_PLANE_COLOR=" + $color,
                "CONTROL_PLANE_DIRECT_PROBE_PATH=" + $probe_path,
                "CONTROL_PLANE_MEMBER_ID=" + $member,
                "CONTROL_PLANE_MODE=active",
                "CONTROL_PLANE_MUTATION_FREEZE_EPOCH=" + $freeze_epoch,
                "CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH=" + $freeze_path,
                "CONTROL_PLANE_POOL_ACK_PATH=" + $pool_ack_path,
                "CONTROL_PLANE_ROUTE_DRAIN_EPOCH=" + $drain_epoch,
                "CONTROL_PLANE_ROUTE_DRAIN_MARKER_PATH=" + $drain_path,
                "CONTROL_PLANE_STARTUP_MODE=web-only",
                "CONTROL_PLANE_TRUSTED_PROXY_ADDRESSES=" + $trusted_proxy_addresses,
                "CONTROL_PLANE_WEB_EPOCH=" + $web_epoch,
                "CONTROL_PLANE_WEB_MARKER_PATH=" + $web_path,
                "CONTROL_PLANE_WRITER_EPOCH=" + $writer_epoch,
                "CONTROL_PLANE_WRITER_MARKER_PATH=" + $writer_path
            ] | sort)
        ' >/dev/null || fail "$expected_color $expected_role control-plane environment differs"
    docker inspect "$container_name" | jq --exit-status '
        .[0].HostConfig.Privileged == false
        and ((.[0].HostConfig.CapAdd // []) | length) == 0
        and ((.[0].HostConfig.Devices // []) | length) == 0
        and (.[0].HostConfig.PidMode == "" or .[0].HostConfig.PidMode == "private")
        and (.[0].HostConfig.IpcMode == "" or .[0].HostConfig.IpcMode == "private")
    ' >/dev/null || fail "$expected_color $expected_role has unsafe host privileges"
    docker inspect "$container_name" \
        | jq --exit-status \
            '.[0].HostConfig.ExtraHosts | index("host.docker.internal:host-gateway") != null' >/dev/null \
        || fail "$expected_color $expected_role lacks host.docker.internal:host-gateway"
    docker inspect "$container_name" \
        | jq --exit-status --arg port "${backend_port}/tcp" --arg host_port "$expected_loopback_port" '
            .[0].HostConfig.PortBindings as $bindings
            | ($bindings | keys) == [$port]
            and ($bindings[$port] | length) == 1
            and $bindings[$port][0].HostIp == "127.0.0.1"
            and $bindings[$port][0].HostPort == $host_port
        ' >/dev/null || fail "$expected_color $expected_role loopback port ownership differs"
    docker inspect "$container_name" | jq --exit-status '
        .[0].Config.Healthcheck.Test
            == ["CMD", "/usr/local/bin/control-plane-direct-probe-healthcheck"]
    ' >/dev/null || fail "$expected_color $expected_role readiness healthcheck differs"
    prove_candidate_background_services_down "$container_name"
    [ "$(container_image_reference "$container_name")" = "$expected_plan_image_reference" ] \
        && [ "$(container_image_id "$container_name")" = "$expected_plan_image_id" ] \
        && [ "$(docker inspect "$container_name" | jq -r --arg network "$control_plane_network" \
            '.[0].NetworkSettings.Networks[$network].NetworkID')" = "$expected_plan_network_id" ] \
        || fail "$expected_color $expected_role differs from its immutable pre-start plan"
    assert_container_secret_identity "$container_name" \
        /run/secrets/control-plane-direct-probe-token "$expected_direct_probe_token_file" \
        "$expected_color $expected_role direct-probe token"
    assert_container_secret_identity "$container_name" \
        /run/secrets/control-plane-applied-ack "$expected_applied_ack_file" \
        "$expected_color $expected_role applied acknowledgement"
    assert_container_secret_identity "$container_name" \
        /run/secrets/control-plane-route-health-token "$expected_route_health_token_file" \
        "$expected_color pool route-health token"
    assert_container_secret_identity "$container_name" \
        /run/secrets/control-plane-pool-ack "$expected_pool_ack_file" \
        "$expected_color pool acknowledgement"
    wait_for_container_health "$container_name"
    assert_candidate_reachability "$container_name"
    docker exec "$container_name" test ! -e "$WRITER_MARKER_PATH" \
        || fail "$expected_color $expected_role started with a writer marker"
    docker exec "$container_name" test ! -e "$WEB_MARKER_PATH" \
        || fail "$expected_color $expected_role started with a web marker"
    docker exec "$container_name" test ! -e "$ROUTE_DRAIN_MARKER_PATH" \
        || fail "$expected_color $expected_role started with a route-drain marker"
    docker exec "$container_name" test ! -e "$MUTATION_FREEZE_MARKER_PATH" \
        || fail "$expected_color pool started with a mutation-freeze marker"
}

assert_container_secret_identity()
{
    secret_container=$1
    secret_path=$2
    secret_source=$3
    secret_label=$4
    [ "$(docker exec "$secret_container" stat -c '%u:%g:%a' "$secret_path")" = 9999:9999:400 ] \
        && [ "$(docker exec "$secret_container" sha256sum "$secret_path" | awk '{print $1}')" \
            = "$(sha256_file "$secret_source")" ] \
        || fail "$secret_label identity differs"
}

assert_candidate_pool_runtime()
{
    pool_color=$1
    case "$pool_color" in
        green)
            runtime_fence_call assert-pool-baseline
            assert_pool_member_runtime "$green_container" "$green_image" "$green_state_volume" \
                "$green_loopback_port" green web-a "$green_web_a_route_identity" \
                "$green_web_epoch" "$green_web_a_route_drain_epoch" \
                "$green_direct_probe_token_file" "$green_applied_ack_file"
            assert_pool_member_runtime "$green_web_b_container" "$green_image" \
                "$green_web_b_private_volume" "$green_web_b_loopback_port" green web-b \
                "$green_web_b_route_identity" "$green_web_b_epoch" \
                "$green_web_b_route_drain_epoch" "$green_web_b_direct_probe_token_file" \
                "$green_web_b_applied_ack_file"
            ;;
        blue)
            reverse_runtime_fence_call assert-pool-baseline
            assert_pool_member_runtime "$replacement_blue_container" "$blue_image" \
                "$blue_state_volume" "$blue_loopback_port" blue web-a \
                "$blue_web_a_route_identity" "$blue_web_epoch" \
                "$blue_web_a_route_drain_epoch" "$blue_direct_probe_token_file" \
                "$blue_applied_ack_file"
            assert_pool_member_runtime "$replacement_blue_web_b_container" "$blue_image" \
                "$blue_web_b_private_volume" "$blue_web_b_loopback_port" blue web-b \
                "$blue_web_b_route_identity" "$blue_web_b_epoch" \
                "$blue_web_b_route_drain_epoch" "$blue_web_b_direct_probe_token_file" \
                "$blue_web_b_applied_ack_file"
            ;;
        *) fail "unknown candidate pool color: $pool_color" ;;
    esac
}

prove_candidate_background_services_down()
{
    candidate_container=$1

    for candidate_service in horizon scheduler-worker nightwatch-agent; do
        if is_test_mode; then
            [ "$(docker exec "$candidate_container" /usr/local/bin/control-plane-lab-service \
                service-state "$candidate_service")" = down ] \
                || fail "candidate background service is supervised-up before promotion: $candidate_service"
        else
            docker exec --user 0 "$candidate_container" /command/s6-svstat -d \
                "/run/service/$candidate_service" >/dev/null \
                || fail "candidate background service is supervised-up before promotion: $candidate_service"
        fi
    done
}

start_green_candidate()
{
    compose green up --detach --no-build --pull never green-web-a green-web-b \
        > "$operation_directory/green-start.log" 2>&1
    test_crash after-green-compose-up
    assert_candidate_pool_runtime green
}

reconcile_green_candidate_start()
{
    docker_container_presence "$green_container"
    green_web_a_presence=$container_presence
    docker_container_presence "$green_web_b_container"
    green_web_b_presence=$container_presence
    if [ "$green_web_a_presence:$green_web_b_presence" = absent:absent ]; then
        start_green_candidate
    elif [ "$green_web_a_presence:$green_web_b_presence" = present:present ]; then
        assert_candidate_pool_runtime green
    else
        fail 'green start recovery found a partial two-member pool; fence remains active'
    fi
    state_green_id=$(container_id "$green_container")
    state_green_web_b_id=$(container_id "$green_web_b_container")
    state_green_image_id=$(container_image_id "$green_container")
    state_green_web_b_image_id=$(container_image_id "$green_web_b_container")
    [ "$state_green_image_id" = "$state_green_plan_image_id" ] \
        && [ "$state_green_web_b_image_id" = "$state_green_plan_image_id" ] \
        || fail 'green pool image changed while recording exact member identities'
    state_phase=green-started-unproven
    write_state "$state_phase"
}

complete_green_preflight()
{
    case "$state_phase" in
        fence-active)
            assert_container_absent "$green_container"
            assert_container_absent "$green_web_b_container"
            assert_container_absent "$replacement_blue_container"
            assert_container_absent "$replacement_blue_web_b_container"
            ensure_coordination_volume
            ensure_private_volume "$blue_state_volume" blue-web-a
            ensure_private_volume "$blue_web_b_private_volume" blue-web-b
            ensure_private_volume "$green_state_volume" green-web-a
            ensure_private_volume "$green_web_b_private_volume" green-web-b
            assert_fresh_marker_volume "$blue_state_volume" blue "$blue_writer_epoch"
            assert_fresh_marker_volume "$blue_state_volume" blue "$blue_web_epoch" web-epoch
            assert_fresh_marker_volume "$blue_state_volume" blue \
                "$blue_web_a_route_drain_epoch" route-drain-epoch
            assert_fresh_marker_volume "$blue_web_b_private_volume" blue-web-b "$blue_writer_epoch"
            assert_fresh_marker_volume "$blue_web_b_private_volume" blue-web-b \
                "$blue_web_b_epoch" web-epoch
            assert_fresh_marker_volume "$blue_web_b_private_volume" blue-web-b \
                "$blue_web_b_route_drain_epoch" route-drain-epoch
            assert_fresh_marker_volume "$green_state_volume" green "$writer_epoch"
            assert_fresh_marker_volume "$green_state_volume" green "$green_web_epoch" web-epoch
            assert_fresh_marker_volume "$green_state_volume" green \
                "$green_web_a_route_drain_epoch" route-drain-epoch
            assert_fresh_marker_volume "$green_web_b_private_volume" green-web-b "$writer_epoch"
            assert_fresh_marker_volume "$green_web_b_private_volume" green-web-b \
                "$green_web_b_epoch" web-epoch
            assert_fresh_marker_volume "$green_web_b_private_volume" green-web-b \
                "$green_web_b_route_drain_epoch" route-drain-epoch
            assert_fresh_marker_volume "$green_state_volume" coordination \
                "$mutation_freeze_epoch" mutation-freeze-epoch
            state_phase=green-starting
            write_state "$state_phase"
            test_crash after-green-start-intent
            ;;
        green-starting|green-started-unproven|green-started|preflight-ready)
            ;;
        *)
            fail "preflight cannot resume operation phase: $state_phase"
            ;;
    esac

    if [ "$state_phase" = green-starting ]; then
        runtime_fence_call verify
        reconcile_green_candidate_start
    fi
    if [ "$state_phase" = green-started-unproven ]; then
        assert_green_state
        runtime_fence_call verify
        runtime_fence_call prove-pool-denied
        state_green_container_runtime_sha256=$(runtime_fence_container_runtime_sha256 \
            "$green_container")
        state_green_web_b_container_runtime_sha256=$(runtime_fence_container_runtime_sha256 \
            "$green_web_b_container")
        prepare_forward_ingress_pool
        runtime_fence_call pin-ingress-pool-manifest "$state_forward_ingress_pool_sha256"
        state_phase=green-started
        write_state "$state_phase"
    fi
    if [ "$state_phase" = green-started ]; then
        assert_green_state
        ensure_candidate_restart_policy_always green
        runtime_fence_call verify
        direct_origin_probe "$green_container"
        direct_origin_probe "$green_web_b_container"
        state_phase=preflight-ready
        write_state "$state_phase"
    fi
    assert_green_state
    note "preflight-ready operation=$operation_id green=$green_container image=$green_image"
}

start_replacement_blue()
{
    assert_persisted_live_expand_migration
    replacement_schema_candidate="$operation_directory/.replacement-blue-schema.$$"
    verify_blue_green_schema > "$replacement_schema_candidate"
    [ "$(grep -F -c '=true' "$replacement_schema_candidate")" = 9 ] \
        || fail 'replacement blue schema compatibility is no longer exact'
    rm -f "$replacement_schema_candidate"
    assert_replacement_blue_capability
    compose blue --profile replacement-blue up --detach --no-build --pull never \
        blue-web-a blue-web-b \
        > "$operation_directory/blue-replacement-start.log" 2>&1
    assert_candidate_pool_runtime blue
}

assert_replacement_blue_capability()
{
    capability_container="${compose_project}-blue-capability-${operation_id}"
    docker rm --force "$capability_container" >/dev/null 2>&1 || true
    capability_status=0
    docker run --name "$capability_container" --network none --read-only \
        --user 0 \
        --tmpfs /var/lib/coolify-control-plane:rw,noexec,nosuid,size=64k,mode=0750 \
        --env CONTROL_PLANE_MODE=active \
        --env CONTROL_PLANE_STARTUP_MODE=web-only \
        --env "CONTROL_PLANE_WRITER_EPOCH=$blue_writer_epoch" \
        --env CONTROL_PLANE_WRITER_MARKER_PATH="$WRITER_MARKER_PATH" \
        --env "CONTROL_PLANE_WEB_EPOCH=$blue_web_epoch" \
        --env CONTROL_PLANE_WEB_MARKER_PATH="$WEB_MARKER_PATH" \
        --env CONTROL_PLANE_MUTATION_LEASE_PATH="$MUTATION_LEASE_PATH" \
        --env CONTROL_PLANE_WEB_ACTIVATION_CONFIRM=route-switch-pending \
        --entrypoint /bin/sh "$blue_image" -ec '
            state=/var/lib/coolify-control-plane
            private=${CONTROL_PLANE_WEB_MARKER_PATH%/*}
            coordination=${CONTROL_PLANE_MUTATION_LEASE_PATH%/*}
            chown 0:9999 "$state"
            chmod 0750 "$state"
            install -d -m 0750 -o root -g 9999 "$private" "$coordination"
            install -m 0660 -o root -g 9999 /dev/null "$CONTROL_PLANE_MUTATION_LEASE_PATH"
            printf %s "$CONTROL_PLANE_WEB_EPOCH" > "$CONTROL_PLANE_WEB_MARKER_PATH"
            chown 0:9999 "$CONTROL_PLANE_WEB_MARKER_PATH"
            chmod 0440 "$CONTROL_PLANE_WEB_MARKER_PATH"
            sync "$CONTROL_PLANE_WEB_MARKER_PATH"
            sync "$private" "$coordination" "$state"
            test "$(stat -c %u:%g:%a "$private")" = 0:9999:750
            test "$(stat -c %u:%g:%a "$coordination")" = 0:9999:750
            test "$(stat -c %u:%g:%a "$CONTROL_PLANE_MUTATION_LEASE_PATH")" = 0:9999:660
            test "$(stat -c %u:%g:%a "$CONTROL_PLANE_WEB_MARKER_PATH")" = 0:9999:440
            test "$(su-exec 9999:9999 /usr/local/bin/coolify-entrypoint startup-mode)" = web-only
            su-exec 9999:9999 /usr/local/bin/coolify-entrypoint activate-web >/dev/null
            su-exec 9999:9999 /usr/local/bin/coolify-entrypoint web-activated
            test ! -e "$CONTROL_PLANE_WRITER_MARKER_PATH"
        ' || capability_status=$?
    docker rm --force "$capability_container" >/dev/null 2>&1 || true
    [ "$capability_status" -eq 0 ] \
        || fail 'replacement blue image lacks the immutable web/background fence capability; legacy v4.1.2 cannot join the live network'
}

http_headers_match_ack()
{
    ack_header_file=$1
    ack_header_name=$2
    ack_expectation=$3
    ack_expected_value=${4:-}
    case "$ack_expectation" in
        present) ack_expected_count=1 ;;
        absent) ack_expected_count=0 ;;
        *) fail "unknown HTTP acknowledgement expectation: $ack_expectation" ;;
    esac

    awk -v expected_name="$ack_header_name" -v expected_value="$ack_expected_value" \
        -v expected_count="$ack_expected_count" '
        BEGIN { expected_name = tolower(expected_name) }
        {
            sub(/\r$/, "")
            if ($0 ~ /^[ \t]/) {
                invalid = 1
                next
            }
            separator = index($0, ":")
            if (separator == 0) next
            observed_name = tolower(substr($0, 1, separator - 1))
            observed_value = substr($0, separator + 1)
            gsub(/^[ \t]+|[ \t]+$/, "", observed_value)
            if (observed_name == expected_name) {
                count++
                if (expected_count == 1 && observed_value != expected_value) invalid = 1
            } else if (index(observed_name, expected_name) > 0 \
                || index(expected_name, observed_name) > 0) {
                invalid = 1
            }
        }
        END { exit invalid || count != expected_count }
    ' "$ack_header_file"
}

direct_origin_probe()
{
    probe_container=$1
    case "$probe_container" in
        "$green_container")
            direct_probe_acknowledgement=$green_applied_acknowledgement
            ;;
        "$green_web_b_container")
            direct_probe_acknowledgement=$green_web_b_applied_acknowledgement
            ;;
        "$blue_container"|"$replacement_blue_container")
            direct_probe_acknowledgement=$blue_applied_acknowledgement
            ;;
        "$replacement_blue_web_b_container")
            direct_probe_acknowledgement=$blue_web_b_applied_acknowledgement
            ;;
        *) fail "direct-origin probe has no acknowledgement owner: $probe_container" ;;
    esac
    direct_probe_header_file="$operation_directory/direct-origin-${probe_container}.headers"
    rm -f "$direct_probe_header_file"

    docker exec \
        --env "CONTROL_PLANE_BACKEND_PORT=$backend_port" \
        --env "CONTROL_PLANE_DIRECT_PROBE_PATH=$direct_probe_path" \
        "$probe_container" /bin/sh -ec '
            token=$(cat /run/secrets/control-plane-direct-probe-token)
            test -n "$token"
            curl --fail --silent --show-error --max-time 5 \
                --header "X-Control-Plane-Probe: $token" \
                --dump-header - --output /dev/null \
                "http://127.0.0.1:${CONTROL_PLANE_BACKEND_PORT}${CONTROL_PLANE_DIRECT_PROBE_PATH}"
        ' > "$direct_probe_header_file"
    if ! http_headers_match_ack "$direct_probe_header_file" \
        X-Control-Plane-Applied-Config present "$direct_probe_acknowledgement"; then
        rm -f "$direct_probe_header_file"
        fail "direct-origin response did not contain exactly one exact acknowledgement: $probe_container"
    fi
    rm -f "$direct_probe_header_file"
}

ingress_controller_path()
{
    case "$1" in
        ingress)
            printf '%s\n' "$ingress_controller"
            ;;
        *)
            fail "unknown ingress controller: $1"
            ;;
    esac
}

ingress_controller_call()
{
    ingress_name=$1
    ingress_action=$2
    ingress_color=${3:-legacy}
    ingress_backend=${4:-none}
    ingress_backend_port=${5:-0}
    ingress_ack_file=${6:-none}
    ingress_drain_member=${8:-}
    controller=$(ingress_controller_path "$ingress_name")
    [ "$ingress_name" = ingress ] || fail "unknown ingress controller role: $ingress_name"
    controller_role=ingress-controller
    ingress_public_url=$public_probe_url
    ingress_direct_probe_token_file=none

    case "$ingress_color" in
        green)
            ingress_generation=$forward_pool_generation
            ingress_pool_manifest=$state_forward_ingress_pool_path
            ingress_pool_manifest_sha256=$state_forward_ingress_pool_sha256
            ingress_pool_plan=$state_forward_pool_plan_path
            ingress_pool_plan_sha256=$state_forward_pool_plan_sha256
            ingress_operation_id=$state_runtime_fence_operation_id
            ingress_operation_directory="$operation_directory/ingress-$ingress_name"
            ingress_predecessor_operation_id=
            ingress_predecessor_manifest_sha256=
            ingress_predecessor_pool_plan_sha256=
            ingress_predecessor_color=
            ingress_predecessor_generation=
            ;;
        blue)
            ingress_generation=$state_reverse_fence_generation
            ingress_pool_manifest=$state_reverse_ingress_pool_path
            ingress_pool_manifest_sha256=$state_reverse_ingress_pool_sha256
            ingress_pool_plan=$state_reverse_pool_plan_path
            ingress_pool_plan_sha256=$state_reverse_pool_plan_sha256
            ingress_operation_id=$state_reverse_fence_operation_id
            ingress_operation_directory="$operation_directory/ingress-$ingress_name-$reverse_generation_token"
            ingress_predecessor_operation_id=$state_runtime_fence_operation_id
            ingress_predecessor_manifest_sha256=$state_forward_ingress_pool_sha256
            ingress_predecessor_pool_plan_sha256=$state_forward_pool_plan_sha256
            ingress_predecessor_color=green
            ingress_predecessor_generation=$forward_pool_generation
            ;;
        legacy)
            ingress_generation=
            ingress_pool_manifest=
            ingress_pool_manifest_sha256=
            ingress_pool_plan=
            ingress_pool_plan_sha256=
            ingress_operation_id=${state_runtime_fence_operation_id:-$operation_id}
            [ "$ingress_operation_id" != none ] || ingress_operation_id=$operation_id
            ingress_operation_directory="$operation_directory/ingress-$ingress_name"
            ingress_predecessor_operation_id=
            ingress_predecessor_manifest_sha256=
            ingress_predecessor_pool_plan_sha256=
            ingress_predecessor_color=
            ingress_predecessor_generation=
            ;;
        *) fail "unknown ingress pool color: $ingress_color" ;;
    esac

    assert_release_asset_identity "$controller_role"
    CONTROL_PLANE_INGRESS_NAME="$ingress_name" \
    CONTROL_PLANE_INGRESS_ACTION="$ingress_action" \
    CONTROL_PLANE_INGRESS_COLOR="$ingress_color" \
    CONTROL_PLANE_INGRESS_BACKEND="$ingress_backend" \
    CONTROL_PLANE_INGRESS_BACKEND_PORT="$ingress_backend_port" \
    CONTROL_PLANE_INGRESS_ACK_FILE="$ingress_ack_file" \
    CONTROL_PLANE_INGRESS_DIRECT_PROBE_TOKEN_FILE="$ingress_direct_probe_token_file" \
    CONTROL_PLANE_INGRESS_DIRECT_PROBE_PATH="$direct_probe_path" \
    CONTROL_PLANE_INGRESS_OPERATION_ID="$ingress_operation_id" \
    CONTROL_PLANE_INGRESS_GENERATION="$ingress_generation" \
    CONTROL_PLANE_INGRESS_POOL_MANIFEST="$ingress_pool_manifest" \
    CONTROL_PLANE_INGRESS_POOL_MANIFEST_SHA256="$ingress_pool_manifest_sha256" \
    CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST="$ingress_pool_plan" \
    CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST_SHA256="$ingress_pool_plan_sha256" \
    CONTROL_PLANE_INGRESS_PREDECESSOR_OPERATION_ID="$ingress_predecessor_operation_id" \
    CONTROL_PLANE_INGRESS_PREDECESSOR_MANIFEST_SHA256="$ingress_predecessor_manifest_sha256" \
    CONTROL_PLANE_INGRESS_PREDECESSOR_POOL_PLAN_SHA256="$ingress_predecessor_pool_plan_sha256" \
    CONTROL_PLANE_INGRESS_PREDECESSOR_COLOR="$ingress_predecessor_color" \
    CONTROL_PLANE_INGRESS_PREDECESSOR_GENERATION="$ingress_predecessor_generation" \
    CONTROL_PLANE_INGRESS_DRAIN_MEMBER="$ingress_drain_member" \
    CONTROL_PLANE_INGRESS_OPERATION_DIR="$ingress_operation_directory" \
    CONTROL_PLANE_INGRESS_PUBLIC_URL="$ingress_public_url" \
    CONTROL_PLANE_INGRESS_LOCAL_URL="$local_ingress_url" \
    CONTROL_PLANE_INGRESS_APP_PORT="$app_port" \
    CONTROL_PLANE_INGRESS_PUBLIC_HOST_HEADER="$public_probe_host_header" \
    CONTROL_PLANE_INGRESS_EXPECTED_IPV4="$expected_ipv4" \
    CONTROL_PLANE_INGRESS_PROBE_ATTEMPTS="$public_probe_attempts" \
    CONTROL_PLANE_INGRESS_PROXY_CONTAINER="$proxy_container" \
    CONTROL_PLANE_INGRESS_DYNAMIC_DIR="$traefik_dynamic_directory" \
    CONTROL_PLANE_INGRESS_DYNAMIC_FILENAME="$dynamic_filename" \
    CONTROL_PLANE_INGRESS_HOST="$control_plane_host" \
    CONTROL_PLANE_INGRESS_TRAEFIK_ENTRYPOINT="$traefik_entrypoint" \
    CONTROL_PLANE_INGRESS_TRAEFIK_LOCAL_ENTRYPOINT="$local_ingress_entrypoint" \
    CONTROL_PLANE_INGRESS_TRAEFIK_TLS="$traefik_tls" \
    CONTROL_PLANE_INGRESS_TRAEFIK_CERT_RESOLVER="$traefik_cert_resolver" \
    CONTROL_PLANE_INGRESS_TRAEFIK_ROUTER_PRIORITY="$traefik_router_priority" \
    CONTROL_PLANE_INGRESS_TEST_MODE="$test_mode" \
    CONTROL_PLANE_INGRESS_TEST_INVALID_ROUTE="${CONTROL_PLANE_TEST_INVALID_ROUTE:-0}" \
    "$controller" "$ingress_action"
}

assert_ingress_v2_capability_for_target()
{
    assert_release_asset_identity ingress-controller
    set -- "$ingress_controller"
    for capability_controller in "$@"; do
        if ! grep -Fq 'CONTROL_PLANE_INGRESS_POOL_MANIFEST' "$capability_controller" \
            || ! grep -Fq 'CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST' "$capability_controller" \
            || ! grep -Fq 'CONTROL_PLANE_INGRESS_DRAIN_MEMBER' "$capability_controller" \
            || ! grep -Fq 'verify-drained' "$capability_controller" \
            || ! grep -Fq 'managed routing requires ingress-pool.manifest v2' \
                "$capability_controller"; then
            fail "pinned ingress controller lacks exact pooled-v2 capability: $capability_controller"
        fi
    done
}

ingress_controller_preflight()
{
    ingress_controller_call "$1" preflight legacy none 0 none
}

ingress_controller_prepare()
{
    ingress_controller_call "$1" prepare legacy none 0 none
}

ingress_controller_prepare_managed()
{
    managed_ingress_name=$1
    managed_ingress_color=$2
    managed_ingress_backend=$3
    managed_ingress_port=$4
    managed_ingress_ack=$5
    ingress_controller_call "$managed_ingress_name" preflight "$managed_ingress_color" \
        "$managed_ingress_backend" "$managed_ingress_port" "$managed_ingress_ack"
    ingress_controller_call "$managed_ingress_name" prepare "$managed_ingress_color" \
        "$managed_ingress_backend" "$managed_ingress_port" "$managed_ingress_ack"
}

ingress_controller_switch()
{
    ingress_name=$1
    ingress_color=$2
    ingress_backend=$3
    ingress_backend_port=$4
    ingress_ack_file=$5

    ingress_controller_call "$ingress_name" switch "$ingress_color" \
        "$ingress_backend" "$ingress_backend_port" "$ingress_ack_file" || return 1
    ingress_controller_call "$ingress_name" ack "$ingress_color" \
        "$ingress_backend" "$ingress_backend_port" "$ingress_ack_file" || return 1
}

ingress_controller_assert()
{
    ingress_controller_call "$1" assert "$2" "$3" "$4" "$5" || return 1
    ingress_controller_call "$1" ack "$2" "$3" "$4" "$5" || return 1
}

ingress_controller_verify_ack()
{
    ingress_controller_call "$1" verify "$2" "$3" "$4" "$5"
    ingress_controller_call "$1" ack "$2" "$3" "$4" "$5"
}

ingress_controller_drain_member()
{
    ingress_name=$1
    ingress_color=$2
    ingress_member=$3
    ingress_backend=$4
    ingress_backend_port=$5
    ingress_ack_file=$6
    ingress_controller_call "$ingress_name" drain "$ingress_color" \
        "$ingress_backend" "$ingress_backend_port" "$ingress_ack_file" none \
        "$ingress_member" || return 1
    ingress_controller_call "$ingress_name" verify-drained "$ingress_color" \
        "$ingress_backend" "$ingress_backend_port" "$ingress_ack_file" none \
        "$ingress_member"
}

ingress_controller_restore()
{
    ingress_controller_call "$1" restore legacy none 0 none
    ingress_controller_call "$1" ack legacy none 0 none
}

ingress_controller_restore_reverse_generation()
{
    reverse_restore_name=$1
    reverse_restore_backend=$2
    reverse_restore_port=$3
    reverse_restore_ack=$4
    ingress_controller_call "$reverse_restore_name" restore blue \
        "$reverse_restore_backend" "$reverse_restore_port" "$reverse_restore_ack"
    ingress_controller_call "$reverse_restore_name" ack blue \
        "$reverse_restore_backend" "$reverse_restore_port" "$reverse_restore_ack"
}

reconcile_proxy_enrollment_credential_candidates()
{
    proxy_enrollment_credential_file=$1
    for proxy_enrollment_credential_random in \
        "${proxy_enrollment_credential_file}".new.*.random
    do
        [ -e "$proxy_enrollment_credential_random" ] \
            || [ -L "$proxy_enrollment_credential_random" ] \
            || continue
        assert_non_symlink_regular_file "$proxy_enrollment_credential_random" \
            'native Traefik enrollment credential entropy candidate'
        [ "$(file_uid "$proxy_enrollment_credential_random")" = "$operator_uid" ] \
            && [ "$(file_gid "$proxy_enrollment_credential_random")" = "$operator_gid" ] \
            && [ "$(file_mode "$proxy_enrollment_credential_random")" = 600 ] \
            && [ "$(file_link_count "$proxy_enrollment_credential_random")" = 1 ] \
            || fail 'native Traefik enrollment credential entropy candidate metadata is unsafe'
        rm -f "$proxy_enrollment_credential_random"
    done
    for proxy_enrollment_credential_candidate in \
        "${proxy_enrollment_credential_file}".new.*
    do
        [ -e "$proxy_enrollment_credential_candidate" ] \
            || [ -L "$proxy_enrollment_credential_candidate" ] \
            || continue
        case "$proxy_enrollment_credential_candidate" in
            *.random)
                continue
                ;;
        esac
        assert_non_symlink_regular_file "$proxy_enrollment_credential_candidate" \
            'native Traefik enrollment credential candidate'
        [ "$(file_uid "$proxy_enrollment_credential_candidate")" = "$operator_uid" ] \
            && [ "$(file_gid "$proxy_enrollment_credential_candidate")" = "$operator_gid" ] \
            && [ "$(file_mode "$proxy_enrollment_credential_candidate")" = 600 ] \
            || fail 'native Traefik enrollment credential candidate metadata is unsafe'
        if [ -e "$proxy_enrollment_credential_file" ] \
            || [ -L "$proxy_enrollment_credential_file" ]; then
            assert_non_symlink_regular_file "$proxy_enrollment_credential_file" \
                'native Traefik enrollment credential'
            if [ "$(file_device_inode "$proxy_enrollment_credential_candidate")" = \
                "$(file_device_inode "$proxy_enrollment_credential_file")" ]; then
                [ "$(file_link_count "$proxy_enrollment_credential_candidate")" -ge 2 ] \
                    || fail 'linked native Traefik enrollment credential candidate has an invalid link count'
                proxy_enrollment_candidate_token=$(cat "$proxy_enrollment_credential_candidate")
                validate_safe_token "$proxy_enrollment_candidate_token" \
                    'linked native Traefik enrollment credential candidate'
                rm -f "$proxy_enrollment_credential_candidate"
            else
                [ "$(file_link_count "$proxy_enrollment_credential_candidate")" = 1 ] \
                    || fail 'stale native Traefik enrollment credential candidate has an invalid link count'
                rm -f "$proxy_enrollment_credential_candidate"
            fi
        else
            rm -f "$proxy_enrollment_credential_candidate"
        fi
    done
}

proxy_enrollment_operation_context()
{
    proxy_enrollment_operation_id=native-traefik-enrollment-v1
    proxy_enrollment_credential_file="$state_directory/$proxy_enrollment_operation_id.token"
    validate_path "$proxy_enrollment_credential_file" 'native Traefik enrollment credential path'
    [ -d "$state_directory" ] && [ ! -L "$state_directory" ] \
        || fail 'operator state directory must be a non-symlink directory before native Traefik enrollment'
    chmod 700 "$state_directory"
    [ "$(file_uid "$state_directory")" = "$operator_uid" ] \
        && [ "$(file_gid "$state_directory")" = "$operator_gid" ] \
        && [ "$(file_mode "$state_directory")" = 700 ] \
        || fail 'operator state directory ownership or mode is unsafe for native Traefik enrollment'
    reconcile_proxy_enrollment_credential_candidates "$proxy_enrollment_credential_file"

    if [ ! -e "$proxy_enrollment_credential_file" ] \
        && [ ! -L "$proxy_enrollment_credential_file" ]; then
        proxy_enrollment_credential_candidate="${proxy_enrollment_credential_file}.new.$$"
        proxy_enrollment_credential_random="${proxy_enrollment_credential_candidate}.random"
        [ ! -e "$proxy_enrollment_credential_candidate" ] \
            && [ ! -L "$proxy_enrollment_credential_candidate" ] \
            && [ ! -e "$proxy_enrollment_credential_random" ] \
            && [ ! -L "$proxy_enrollment_credential_random" ] \
            || fail 'native Traefik enrollment credential staging path is unexpectedly occupied'
        umask 077
        : > "$proxy_enrollment_credential_candidate"
        chmod 600 "$proxy_enrollment_credential_candidate"
        assert_non_symlink_regular_file "$proxy_enrollment_credential_candidate" \
            'native Traefik enrollment credential candidate'
        [ "$(file_uid "$proxy_enrollment_credential_candidate")" = "$operator_uid" ] \
            && [ "$(file_gid "$proxy_enrollment_credential_candidate")" = "$operator_gid" ] \
            && [ "$(file_mode "$proxy_enrollment_credential_candidate")" = 600 ] \
            && [ "$(file_link_count "$proxy_enrollment_credential_candidate")" = 1 ] \
            || fail 'native Traefik enrollment credential candidate metadata is unsafe'
        test_crash after-proxy-enrollment-credential-candidate-created
        dd if=/dev/urandom of="$proxy_enrollment_credential_random" bs=32 count=1 2>/dev/null
        [ "$(file_size "$proxy_enrollment_credential_random")" = 32 ] \
            || fail 'native Traefik enrollment credential entropy generation was incomplete'
        sha256sum "$proxy_enrollment_credential_random" | awk '{print $1}' \
            > "$proxy_enrollment_credential_candidate"
        rm -f "$proxy_enrollment_credential_random"
        chmod 600 "$proxy_enrollment_credential_candidate"
        assert_non_symlink_regular_file "$proxy_enrollment_credential_candidate" \
            'native Traefik enrollment credential candidate'
        [ "$(file_uid "$proxy_enrollment_credential_candidate")" = "$operator_uid" ] \
            && [ "$(file_gid "$proxy_enrollment_credential_candidate")" = "$operator_gid" ] \
            && [ "$(file_mode "$proxy_enrollment_credential_candidate")" = 600 ] \
            && [ "$(file_link_count "$proxy_enrollment_credential_candidate")" = 1 ] \
            || fail 'native Traefik enrollment credential candidate metadata is unsafe'
        proxy_enrollment_token=$(cat "$proxy_enrollment_credential_candidate")
        validate_safe_token "$proxy_enrollment_token" 'native Traefik enrollment credential candidate'
        if ln "$proxy_enrollment_credential_candidate" \
            "$proxy_enrollment_credential_file" 2>/dev/null; then
            test_crash after-proxy-enrollment-credential-linked
            rm -f "$proxy_enrollment_credential_candidate"
        else
            rm -f "$proxy_enrollment_credential_candidate"
        fi
    fi
    assert_non_symlink_regular_file "$proxy_enrollment_credential_file" \
        'native Traefik enrollment credential'
    [ "$(file_uid "$proxy_enrollment_credential_file")" = "$operator_uid" ] \
        && [ "$(file_gid "$proxy_enrollment_credential_file")" = "$operator_gid" ] \
        && [ "$(file_mode "$proxy_enrollment_credential_file")" = 600 ] \
        && [ "$(file_link_count "$proxy_enrollment_credential_file")" = 1 ] \
        || fail 'native Traefik enrollment credential metadata is unsafe'
    proxy_enrollment_token=$(cat "$proxy_enrollment_credential_file")
    proxy_enrollment_public_host=${public_probe_host_header:-$control_plane_host}
    proxy_enrollment_public_host=$(printf '%s' "$proxy_enrollment_public_host" \
        | tr '[:upper:]' '[:lower:]')
    validate_identifier "$proxy_enrollment_operation_id" 'proxy enrollment operation ID'
    validate_safe_token "$proxy_enrollment_token" 'proxy enrollment token'
    validate_host "$proxy_enrollment_public_host" 'proxy enrollment public Host header'
}

proxy_enrollment_runner_call()
{
    runner_action=$1
    runner_dynamic_sha256=$2
    assert_immutable_image "$green_image" CONTROL_PLANE_GREEN_IMAGE
    runner_identity=$(printf '%s\0%s\0' "$proxy_enrollment_operation_id" "$runner_action" \
        | sha256sum | awk '{print substr($1, 1, 24)}')
    runner_name="coolify-proxy-enrollment-${runner_identity}"
    validate_identifier "$runner_name" 'proxy enrollment runner name'
    docker_container_presence "$runner_name"
    [ "$container_presence" = absent ] \
        || fail 'a proxy-enrollment runner already exists for this exact lifecycle action'

    set -- artisan control-plane:proxy-enrollment "$runner_action" \
        "--operation=$proxy_enrollment_operation_id" \
        "--token=$proxy_enrollment_token"
    case "$runner_action" in
        prepare)
            set -- "$@" "--app-port=$app_port" "--public-url=$public_probe_url" \
                "--public-host=$proxy_enrollment_public_host" \
                "--dynamic-filename=$dynamic_filename"
            ;;
        finalize)
            set -- "$@" "--app-port=$app_port" \
                "--dynamic-sha256=$runner_dynamic_sha256"
            ;;
        activate|rollback)
            set -- "$@" "--app-port=$app_port"
            ;;
        status) ;;
    esac

    docker run --rm --pull never --name "$runner_name" --user 0:0 \
        --label 'coolify.control-plane.proxy-enrollment-runner=true' \
        --label "coolify.control-plane.operation-id=$operation_id" \
        --network "$control_plane_network" \
        --add-host host.docker.internal:host-gateway \
        --env-file "$source_env_file" \
        --env "APP_PORT=$app_port" \
        --env CONTROL_PLANE_MODE=active \
        --env CONTROL_PLANE_STARTUP_MODE=web-only \
        --mount "type=bind,source=$source_env_file,target=/var/www/html/.env,readonly" \
        --mount "type=bind,source=$proxy_enrollment_docker_socket,target=/var/run/docker.sock" \
        --volume "$ssh_directory:/var/www/html/storage/app/ssh" \
        --volume "$applications_directory:/var/www/html/storage/app/applications" \
        --volume "$databases_directory:/var/www/html/storage/app/databases" \
        --volume "$services_directory:/var/www/html/storage/app/services" \
        --volume "$backups_directory:/var/www/html/storage/app/backups" \
        --workdir /var/www/html --entrypoint php "$green_image" "$@"
}

validate_proxy_enrollment_output()
{
    validated_enrollment_action=$1
    proxy_enrollment_token_sha256=$(printf '%s' "$proxy_enrollment_token" \
        | sha256sum | awk '{print $1}')
    if ! proxy_enrollment_output=$(printf '%s\n' "$proxy_enrollment_output" \
        | jq --exit-status --compact-output --slurp \
            --arg operation "$proxy_enrollment_operation_id" \
            --arg token_sha256 "$proxy_enrollment_token_sha256" \
            --arg public_url "$public_probe_url" \
            --arg public_host "$proxy_enrollment_public_host" \
            --arg local_url "$local_ingress_url" \
            --arg dynamic_filename "$dynamic_filename" \
            --arg compose_override_path "$proxy_enrollment_compose_override" \
            --argjson app_port "$app_port" '
            if length == 1
                and (.[0] | type == "object")
                and .[0].ok == true
                and .[0].operation_id == $operation
                and .[0].token_sha256 == $token_sha256
                and .[0].app_port == $app_port
                and .[0].public_url == $public_url
                and .[0].public_host == $public_host
                and .[0].local_url == $local_url
                and .[0].dynamic_filename == $dynamic_filename
                and .[0].compose_override_path == $compose_override_path
                and (.[0].phase | type == "string")
            then .[0]
            else error("invalid proxy enrollment response")
            end
        '); then
        return 1
    fi
    proxy_enrollment_phase=$(printf '%s\n' "$proxy_enrollment_output" | jq -r '.phase')
    case "$proxy_enrollment_phase" in
        reserving|prepare-failed|preparing|prepared|activating|activated|enrolled|rolling-back|\
        rollback-required|intervention-required|rollback-pending-legacy|rolled-back)
            ;;
        *) return 1 ;;
    esac
    case "$validated_enrollment_action:$proxy_enrollment_phase" in
        prepare:prepared|activate:activated|finalize:enrolled|\
        status:*|rollback:rolling-back|rollback:rollback-pending-legacy|rollback:rolled-back)
            ;;
        *) return 1 ;;
    esac
}

proxy_enrollment_call()
{
    proxy_enrollment_action=$1
    proxy_enrollment_dynamic_sha256=${2:-}
    case "$proxy_enrollment_action" in
        prepare|activate|finalize|status|rollback)
            ;;
        *)
            fail 'proxy enrollment action is invalid'
            ;;
    esac
    if [ -n "$proxy_enrollment_dynamic_sha256" ]; then
        validate_sha256 "$proxy_enrollment_dynamic_sha256" 'proxy enrollment dynamic configuration SHA-256'
    fi
    proxy_enrollment_operation_context

    if is_test_mode && [ -n "$proxy_enrollment_command" ]; then
        if ! proxy_enrollment_output=$( \
            CONTROL_PLANE_PROXY_ENROLLMENT_ACTION="$proxy_enrollment_action" \
            CONTROL_PLANE_PROXY_ENROLLMENT_OPERATION="$proxy_enrollment_operation_id" \
            CONTROL_PLANE_PROXY_ENROLLMENT_TOKEN="$proxy_enrollment_token" \
            CONTROL_PLANE_PROXY_ENROLLMENT_APP_PORT="$app_port" \
            CONTROL_PLANE_PROXY_ENROLLMENT_PUBLIC_URL="$public_probe_url" \
            CONTROL_PLANE_PROXY_ENROLLMENT_PUBLIC_HOST="$proxy_enrollment_public_host" \
            CONTROL_PLANE_PROXY_ENROLLMENT_DYNAMIC_FILENAME="$dynamic_filename" \
            CONTROL_PLANE_PROXY_ENROLLMENT_DYNAMIC_SHA256="$proxy_enrollment_dynamic_sha256" \
            CONTROL_PLANE_PROXY_ENROLLMENT_OPERATOR_PID="$$" \
            "$proxy_enrollment_command" 2>&1
        ); then
            return 1
        fi
    elif ! proxy_enrollment_output=$(proxy_enrollment_runner_call \
        "$proxy_enrollment_action" "$proxy_enrollment_dynamic_sha256" 2>&1); then
        return 1
    fi
    validate_proxy_enrollment_output "$proxy_enrollment_action"
}

proxy_enrollment_status()
{
    proxy_enrollment_call status || return 1
    printf '%s\n' "$proxy_enrollment_phase"
}

proxy_enrollment_status_is_absent()
{
    printf '%s\n' "$proxy_enrollment_output" | jq --exit-status --slurp '
        length == 1
        and (.[0] | type == "object")
        and .[0].ok == false
        and .[0].error == "No exact token-owned managed proxy enrollment state exists."
    ' >/dev/null
}

validate_proxy_enrollment_prepared_contract()
{
    expected_source_compose_files=$(ordered_source_compose_paths_without_proxy_enrollment_override)
    printf '%s\n' "$proxy_enrollment_output" | jq --exit-status \
        --arg expected_source_compose_files "$expected_source_compose_files" '
        (.static_config_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
        and (.compose_override_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
        and (.proxy_rendered_config_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
        and (.source_rendered_config_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
        and (.source_compose_files | type == "array" and length >= 2)
        and ((.source_compose_files | join(",")) == $expected_source_compose_files)
        and (.proxy_before | type == "object" and .running == true)
        and (.legacy_before | type == "object" and .running == true)
        and (.legacy_binding_before | type == "array" and length >= 1)
        and (.public_proof_before | type == "object")
        and (.local_proof_before | type == "object")
    ' >/dev/null
}

assert_proxy_enrollment_override_identity()
{
    validate_proxy_enrollment_prepared_contract \
        || fail 'durable proxy-enrollment preparation contract is invalid'
    enrollment_override_sha256=$(printf '%s\n' "$proxy_enrollment_output" \
        | jq --raw-output '.compose_override_sha256')
    assert_non_symlink_regular_file "$proxy_enrollment_compose_override" \
        'managed proxy-enrollment compose override'
    [ "$(sha256_file "$proxy_enrollment_compose_override")" = \
        "$enrollment_override_sha256" ] \
        && [ "$(file_mode "$proxy_enrollment_compose_override")" = 600 ] \
        || fail 'managed proxy-enrollment compose override bytes or mode differ from durable state'
    if is_test_mode; then
        [ "$(file_uid "$proxy_enrollment_compose_override")" = "$operator_uid" ] \
            && [ "$(file_gid "$proxy_enrollment_compose_override")" = "$operator_gid" ] \
            || fail 'lab proxy-enrollment compose override owner differs from durable state'
    else
        [ "$(file_uid "$proxy_enrollment_compose_override")" = 0 ] \
            && [ "$(file_gid "$proxy_enrollment_compose_override")" = 0 ] \
            || fail 'production proxy-enrollment compose override is not root-owned'
    fi
}

source_compose_recreate_from_proxy_enrollment_output()
{
    enrollment_include_override=$1
    case "$enrollment_include_override" in
        0|1) ;;
        *) fail 'proxy-enrollment source compose override selection is invalid' ;;
    esac
    validate_proxy_enrollment_prepared_contract \
        || fail 'proxy-enrollment source compose inventory is invalid'
    set -- env "APP_PORT=$app_port" docker compose --ansi never \
        --project-name "$source_compose_project" --env-file "$source_env_file"
    enrollment_source_file_count=$(printf '%s\n' "$proxy_enrollment_output" \
        | jq '.source_compose_files | length')
    enrollment_source_file_index=0
    while [ "$enrollment_source_file_index" -lt "$enrollment_source_file_count" ]; do
        enrollment_source_file=$(printf '%s\n' "$proxy_enrollment_output" \
            | jq --exit-status --raw-output \
                --argjson index "$enrollment_source_file_index" \
                '.source_compose_files[$index] | select(type == "string")') \
            || fail 'proxy-enrollment source compose path is invalid'
        validate_path "$enrollment_source_file" 'proxy-enrollment source compose path'
        assert_non_symlink_regular_file "$enrollment_source_file" \
            'proxy-enrollment source compose path'
        set -- "$@" --file "$enrollment_source_file"
        enrollment_source_file_index=$((enrollment_source_file_index + 1))
    done
    if [ "$enrollment_include_override" = 1 ]; then
        assert_proxy_enrollment_override_identity
        set -- "$@" --file "$proxy_enrollment_compose_override"
    fi
    "$@" up --detach --no-deps --force-recreate --wait --no-build --pull never \
        "$source_compose_service"
}

proxy_enrollment_running_port_bindings()
{
    enrollment_port_inventory='[]'
    enrollment_container_ids=$(docker ps --no-trunc --quiet)
    [ -n "$enrollment_container_ids" ] \
        || fail 'proxy-enrollment Docker binding inventory is empty'
    for enrollment_container_id in $enrollment_container_ids; do
        validate_container_id "$enrollment_container_id" \
            'proxy-enrollment Docker inventory container ID'
        enrollment_container_bindings=$(docker inspect "$enrollment_container_id" \
            | jq --exit-status --compact-output '
                .[0] as $container
                | [($container.NetworkSettings.Ports // {})
                    | to_entries[]
                    | .key as $container_port
                    | .value[]?
                    | {
                        container: ($container.Name | ltrimstr("/")),
                        container_id: $container.Id,
                        container_port: $container_port,
                        host_ip: .HostIp,
                        host_port: .HostPort
                    }]
            ') || fail 'proxy-enrollment Docker binding inventory is malformed'
        enrollment_port_inventory=$(jq --null-input --compact-output \
            --argjson inventory "$enrollment_port_inventory" \
            --argjson bindings "$enrollment_container_bindings" \
            '$inventory + $bindings')
    done
    printf '%s\n' "$enrollment_port_inventory"
}

proxy_enrollment_app_port_is_unowned()
{
    enrollment_port_inventory=$(proxy_enrollment_running_port_bindings)
    printf '%s\n' "$enrollment_port_inventory" | jq --exit-status \
        --arg app_port "$app_port" '
        [.[] | select(.container_port == "8000/tcp" or .host_port == $app_port)] == []
    ' >/dev/null
}

proxy_enrollment_has_native_binding()
{
    enrollment_binding_tuple="$proxy_container|8000/tcp|127.0.0.1|$app_port"
    printf '%s\n' "$proxy_enrollment_output" | jq --exit-status \
        --arg binding_tuple "$enrollment_binding_tuple" '
        .proxy_after.binding_tuple == $binding_tuple
    ' >/dev/null || return 1
    assert_container_running "$proxy_container"
    enrollment_port_inventory=$(proxy_enrollment_running_port_bindings)
    printf '%s\n' "$enrollment_port_inventory" | jq --exit-status \
        --arg container "$proxy_container" --arg app_port "$app_port" '
        [.[] | select(.container_port == "8000/tcp" or .host_port == $app_port)] as $owners
        | ($owners | length) == 1
        and $owners[0].container == $container
        and $owners[0].container_port == "8000/tcp"
        and $owners[0].host_ip == "127.0.0.1"
        and $owners[0].host_port == $app_port
    ' >/dev/null
}

proxy_enrollment_has_legacy_binding()
{
    assert_container_running "$blue_container"
    enrollment_port_inventory=$(proxy_enrollment_running_port_bindings)
    enrollment_expected_legacy_bindings=$(printf '%s\n' "$proxy_enrollment_output" \
        | jq --compact-output '.legacy_binding_before')
    printf '%s\n' "$enrollment_port_inventory" | jq --exit-status \
        --arg container "$blue_container" --arg app_port "$app_port" \
        --argjson expected "$enrollment_expected_legacy_bindings" '
        ([.[] | select(.host_port == $app_port) | {
            container,
            container_port,
            host_ip,
            host_port
        }] | sort_by(.container, .container_port, .host_ip, .host_port))
            == ($expected | sort_by(.container, .container_port, .host_ip, .host_port))
        and ($expected | length) >= 1
        and all($expected[];
            .container == $container
            and .container_port == "8080/tcp"
            and .host_port == $app_port)
        and ([.[] | select(.container_port == "8000/tcp")] | length) == 0
    ' >/dev/null
}

assert_proxy_enrollment_rolled_back()
{
    printf '%s\n' "$proxy_enrollment_output" | jq --exit-status '
        .phase == "rolled-back"
        and .legacy_restore_required == false
        and .legacy_binding_rollback_observed == .legacy_binding_before
        and (.rollback_proxy | type == "object")
        and (.rollback_proxy.id | type == "string" and length > 0)
        and .rollback_proxy.running == true
        and (.rollback_proxy.command_sha256
            | type == "string" and test("^[a-f0-9]{64}$"))
        and (.public_proof_rollback.status == .public_proof_before.status)
        and (.public_proof_rollback.response_fingerprint_sha256
            == .public_proof_before.response_fingerprint_sha256)
        and (.local_proof_rollback.status == .local_proof_before.status)
        and (.local_proof_rollback.response_fingerprint_sha256
            == .local_proof_before.response_fingerprint_sha256)
    ' >/dev/null || fail 'terminal proxy-enrollment rollback evidence is incomplete or changed'
    enrollment_rollback_proxy_id=$(printf '%s\n' "$proxy_enrollment_output" \
        | jq --exit-status --raw-output '.rollback_proxy.id') \
        || fail 'terminal proxy-enrollment rollback proxy identity is invalid'
    [ "$(container_id "$proxy_container")" = "$enrollment_rollback_proxy_id" ] \
        || fail 'terminal proxy-enrollment rollback proxy identity differs from the running proxy'
    proxy_enrollment_has_legacy_binding \
        || fail 'terminal proxy-enrollment rollback did not restore exact legacy APP_PORT ownership'
}

adopt_proxy_enrollment_legacy_recreation()
{
    [ -e "$state_file" ] || return
    [ "$(container_image_id "$blue_container")" = "$state_blue_image_id" ] \
        && [ "$(container_image_reference "$blue_container")" = "$state_blue_image_reference" ] \
        || fail 'proxy-enrollment legacy recreation changed the recorded immutable image'
    [ "$(container_label "$blue_container" com.docker.compose.project)" = \
        "$state_blue_compose_project" ] \
        && [ "$(container_label "$blue_container" com.docker.compose.service)" = \
            "$state_blue_compose_service" ] \
        && [ "$(container_label "$blue_container" com.docker.compose.project.config_files)" = \
            "$(ordered_source_compose_paths_without_proxy_enrollment_override)" ] \
        || fail 'proxy-enrollment legacy recreation changed its source compose identity'
    state_blue_id=$(container_id "$blue_container")
    state_blue_rollback_replacement_id=$state_blue_id
    state_blue_compose_config_files=$(ordered_source_compose_paths_without_proxy_enrollment_override)
    state_blue_bridge_ip=$(docker inspect --format \
        "{{with index .NetworkSettings.Networks \"${control_plane_network}\"}}{{.IPAddress}}{{end}}" \
        "$blue_container")
    [ -n "$state_blue_bridge_ip" ] \
        || fail 'proxy-enrollment legacy recreation has no control-plane network address'
    state_blue_restore_status=adopted
    write_state "$state_phase"
}

preserve_proxy_enrollment_after_state_creation()
{
    proxy_enrollment_status >/dev/null || return 1
    case "$proxy_enrollment_phase" in
        enrolled)
            assert_proxy_enrollment_active
            ;;
        activated)
            finalize_proxy_enrollment_if_owned || return 1
            assert_proxy_enrollment_active
            ;;
        activating)
            proxy_enrollment_override_active=1
            assert_proxy_enrollment_override_identity || return 1
            proxy_enrollment_call activate || return 1
            proxy_enrollment_has_native_binding || return 1
            finalize_proxy_enrollment_if_owned || return 1
            assert_proxy_enrollment_active
            ;;
        *)
            return 1
            ;;
    esac
}

rollback_proxy_enrollment_if_owned()
{
    [ ! -e "$state_file" ] && [ ! -L "$state_file" ] \
        || fail 'destructive native Traefik enrollment rollback is only permitted before durable operator state exists'
    proxy_enrollment_status >/dev/null \
        || fail 'exact token-owned proxy-enrollment state is unavailable during rollback'
    case "$proxy_enrollment_phase" in
        rolled-back)
            proxy_enrollment_override_active=0
            assert_proxy_enrollment_rolled_back
            return
            ;;
        reserving|prepare-failed|preparing)
            proxy_enrollment_call prepare \
                || fail 'interrupted proxy-enrollment preparation could not converge before rollback'
            ;;
        prepared|activating|activated|enrolled|rolling-back|rollback-required|\
        intervention-required|rollback-pending-legacy)
            ;;
        *)
            return 1
            ;;
    esac

    proxy_enrollment_rollback_attempt=0
    while [ "$proxy_enrollment_phase" != rollback-pending-legacy ] \
        && [ "$proxy_enrollment_phase" != rolled-back ]; do
        proxy_enrollment_rollback_attempt=$((proxy_enrollment_rollback_attempt + 1))
        [ "$proxy_enrollment_rollback_attempt" -le 2 ] || return 1
        proxy_enrollment_call rollback || return 1
        case "$proxy_enrollment_phase" in
            rolling-back|rollback-required|intervention-required|rollback-pending-legacy|rolled-back)
                ;;
            *)
                return 1
                ;;
        esac
    done
    if [ "$proxy_enrollment_phase" = rollback-pending-legacy ]; then
        proxy_enrollment_override_active=0
        test_crash after-proxy-enrollment-rollback-pending-legacy
        source_compose_recreate_from_proxy_enrollment_output 0 || return 1
        assert_container_running "$blue_container"
        proxy_enrollment_has_legacy_binding || return 1
        adopt_proxy_enrollment_legacy_recreation
        test_crash after-proxy-enrollment-legacy-recreate
        proxy_enrollment_call rollback || return 1
    fi
    [ "$proxy_enrollment_phase" = rolled-back ] || return 1
    proxy_enrollment_override_active=0
    assert_proxy_enrollment_rolled_back
}

reconcile_proxy_enrollment_before_preflight()
{
    if ! proxy_enrollment_status >/dev/null; then
        if [ -e "$state_file" ]; then
            fail 'persisted operation lost its exact token-owned native Traefik enrollment state'
        fi
        proxy_enrollment_status_is_absent \
            || fail 'proxy-enrollment status failed for a reason other than absent owned state'
        return
    fi

    if [ -e "$state_file" ]; then
        case "$proxy_enrollment_phase" in
            activated|enrolled)
                assert_proxy_enrollment_active
                ;;
            activating)
                proxy_enrollment_override_active=1
                assert_proxy_enrollment_override_identity
                proxy_enrollment_call activate \
                    || fail 'persisted native Traefik activation could not converge before preflight'
                proxy_enrollment_has_native_binding \
                    || fail 'persisted native Traefik activation did not restore its loopback APP_PORT binding'
                ;;
            rolling-back|rollback-pending-legacy|rollback-required|intervention-required|rolled-back)
                fail 'persisted operation has an unsafe destructive native Traefik rollback phase; retain native enrollment and repair the durable action state explicitly'
            ;;
            *)
                fail 'persisted operation has an unsupported native Traefik enrollment phase before preflight'
                ;;
        esac
        return
    fi

    case "$proxy_enrollment_phase" in
        rolling-back|rollback-pending-legacy|rollback-required|intervention-required)
            proxy_enrollment_override_active=0
            rollback_proxy_enrollment_if_owned \
                || fail 'native Traefik enrollment could not finish its durable legacy rollback before a new preflight'
            ;;
        activating)
            proxy_enrollment_override_active=1
            assert_proxy_enrollment_override_identity
            proxy_enrollment_call activate \
                || fail 'interrupted native Traefik activation could not converge before proxy identity checks'
            proxy_enrollment_has_native_binding \
                || fail 'interrupted native Traefik activation did not restore its loopback APP_PORT binding'
            ;;
        activated|enrolled)
            assert_proxy_enrollment_active
            ;;
        reserving|prepare-failed|preparing|prepared|rolled-back)
            proxy_enrollment_override_active=0
            ;;
        *)
            fail 'native Traefik enrollment has an unsupported durable phase before preflight'
            ;;
    esac
}

enroll_proxy_if_needed()
{
    if proxy_enrollment_status >/dev/null; then
        case "$proxy_enrollment_phase" in
            activated|enrolled)
                assert_proxy_enrollment_active
                return
                ;;
            activating)
                proxy_enrollment_override_active=1
                assert_proxy_enrollment_override_identity
                if ! proxy_enrollment_call activate; then
                    rollback_proxy_enrollment_if_owned \
                        || fail 'activation recovery also failed to restore the legacy listener'
                    fail 'interrupted native Traefik activation could not converge'
                fi
                proxy_enrollment_has_native_binding \
                    || fail 'activation recovery did not prove the exact loopback native Traefik binding'
                return
                ;;
            prepared)
                ;;
            reserving|prepare-failed|preparing|rolled-back)
                if ! proxy_enrollment_call prepare; then
                    if proxy_enrollment_status >/dev/null \
                        && [ "$proxy_enrollment_phase" = intervention-required ]; then
                        rollback_proxy_enrollment_if_owned \
                            || fail 'failed proxy-enrollment preparation also failed cleanup'
                    fi
                    fail 'native Traefik enrollment could not capture the exact legacy pre-state'
                fi
                ;;
            rolling-back|rollback-pending-legacy|rollback-required|intervention-required)
                rollback_proxy_enrollment_if_owned \
                    || fail 'native Traefik enrollment could not complete interrupted rollback'
                proxy_enrollment_call prepare \
                    || fail 'native Traefik enrollment could not restart after exact rollback'
                ;;
            *) fail 'native Traefik enrollment has an unsupported preflight phase' ;;
        esac
    else
        proxy_enrollment_status_is_absent \
            || fail 'proxy-enrollment status failed for a reason other than absent owned state'
        proxy_enrollment_call prepare \
            || fail 'native Traefik enrollment could not capture the exact legacy pre-state'
    fi

    [ "$proxy_enrollment_phase" = prepared ] \
        || fail 'native Traefik enrollment did not reach exact prepared state'
    assert_proxy_enrollment_override_identity
    test_crash after-proxy-enrollment-prepare
    proxy_enrollment_override_active=1
    if ! source_compose_recreate_from_proxy_enrollment_output 1; then
        rollback_proxy_enrollment_if_owned \
            || fail 'native Traefik enrollment failed to restore the legacy listener after source recreation failure'
        fail 'native Traefik enrollment could not release the legacy APP_PORT listener'
    fi
    assert_container_running "$blue_container"
    proxy_enrollment_app_port_is_unowned \
        || fail 'native Traefik enrollment source handoff did not release every APP_PORT or managed container-port owner'
    test_crash after-proxy-enrollment-source-recreate
    if ! proxy_enrollment_call activate; then
        rollback_proxy_enrollment_if_owned \
            || fail 'native Traefik enrollment activation failed and legacy restoration also failed'
        fail 'native Traefik enrollment could not atomically recreate the proxy static configuration'
    fi
    proxy_enrollment_has_native_binding \
        || fail 'native Traefik enrollment did not establish the exact loopback APP_PORT binding'
    test_crash after-proxy-enrollment-activate
}

assert_proxy_enrollment_active()
{
    proxy_enrollment_status >/dev/null \
        || fail 'durable native Traefik enrollment state is unavailable'
    case "$proxy_enrollment_phase" in
        activated|enrolled) ;;
        *) fail 'durable native Traefik enrollment is not active' ;;
    esac
    proxy_enrollment_override_active=1
    assert_proxy_enrollment_override_identity
    proxy_enrollment_has_native_binding \
        || fail 'durable native Traefik enrollment does not own the exact loopback APP_PORT binding'
}

validate_proxy_enrollment_finalized_contract()
{
    printf '%s\n' "$proxy_enrollment_output" | jq --exit-status '
        .phase == "enrolled"
        and (.dynamic_config_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
        and (.route_acknowledgement_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
        and (.public_proof_after | type == "object")
        and (.local_proof_after | type == "object")
    ' >/dev/null
}

finalize_proxy_enrollment_if_owned()
{
    if ! proxy_enrollment_status >/dev/null; then
        return 1
    fi
    case "$proxy_enrollment_phase" in
        enrolled)
            validate_proxy_enrollment_finalized_contract || return 1
            proxy_enrollment_has_native_binding
            return
            ;;
        activated)
            ;;
        *)
            return 1
            ;;
    esac
    proxy_enrollment_dynamic_path="$traefik_dynamic_directory/$dynamic_filename"
    assert_non_symlink_regular_file "$proxy_enrollment_dynamic_path" \
        'managed Traefik dynamic configuration'
    proxy_enrollment_dynamic_sha256=$(sha256_file "$proxy_enrollment_dynamic_path")
    proxy_enrollment_call finalize "$proxy_enrollment_dynamic_sha256" || return 1
    validate_proxy_enrollment_finalized_contract || return 1
    proxy_enrollment_has_native_binding || return 1
    proxy_enrollment_status >/dev/null && [ "$proxy_enrollment_phase" = enrolled ]
}

assert_container_identity_present()
{
    identity_container=$1
    identity_container_id=$2
    identity_image_id=$3

    docker_container_presence "$identity_container"
    [ "$container_presence" = present ] \
        || fail "recorded container is absent before revocation: $identity_container"
    [ "$container_presence_id" = "$identity_container_id" ] \
        || fail "container identity changed before revocation: $identity_container"
    [ "$container_presence_image_id" = "$identity_image_id" ] \
        || fail "container image identity changed before revocation: $identity_container"
}

stop_and_remove_container()
{
    container_name=$1
    log_name=$2
    expected_container_id=$3
    expected_image_id=$4

    assert_container_identity_present "$container_name" "$expected_container_id" "$expected_image_id"
    observed_container_running=$(container_is_running "$container_name")
    assert_container_identity_present "$container_name" "$expected_container_id" "$expected_image_id"
    if [ "$observed_container_running" = true ]; then
        docker stop --time "$container_stop_timeout" "$expected_container_id" \
            > "$operation_directory/${log_name}-stop.log" 2>&1
        assert_container_identity_present "$container_name" "$expected_container_id" "$expected_image_id"
        test_crash "after-${log_name}-stop"
    fi
    assert_container_identity_present "$container_name" "$expected_container_id" "$expected_image_id"
    docker rm "$expected_container_id" > "$operation_directory/${log_name}-remove.log" 2>&1
    test_crash "after-${log_name}-remove"
    assert_container_absent "$container_name"
}

revoke_legacy_blue()
{
    assert_container_identity_present "$blue_container" "$state_blue_id" "$state_blue_image_id"
    [ "$(container_image_reference "$blue_container")" = "$state_blue_image_reference" ] \
        || fail 'blue image reference changed before revocation'
    [ "$(container_label "$blue_container" com.docker.compose.project)" = "$state_blue_compose_project" ] \
        && [ "$(container_label "$blue_container" com.docker.compose.service)" = "$state_blue_compose_service" ] \
        && [ "$(container_label "$blue_container" com.docker.compose.project.config_files)" = "$state_blue_compose_config_files" ] \
        || fail 'blue compose identity changed before revocation'
    if is_test_mode && [ "${CONTROL_PLANE_TEST_BLUE_STOP_FAILURE:-0}" = 1 ]; then
        return 1
    fi
    stop_and_remove_container "$blue_container" blue "$state_blue_id" "$state_blue_image_id"
    assert_container_absent "$blue_container"
}

restart_stopped_legacy_blue_incumbent()
{
    assert_container_identity_present "$blue_container" "$state_blue_id" "$state_blue_image_id"
    if [ "$(container_is_running "$state_blue_id")" != true ]; then
        docker start "$state_blue_id" \
            > "$operation_directory/legacy-blue-exact-restart.log" 2>&1
        assert_container_identity_present "$blue_container" "$state_blue_id" \
            "$state_blue_image_id"
    fi
}

restore_legacy_blue()
{
    assert_source_identity
    [ "$(source_service_image_reference)" = "$state_blue_image_reference" ] \
        || fail 'legacy restore compose no longer resolves to the recorded image reference'
    [ "$(image_id "$state_blue_image_reference")" = "$state_blue_image_id" ] \
        || fail 'legacy restore image reference no longer resolves to the recorded immutable image ID'

    case "$state_blue_restore_status" in
        none)
            assert_container_absent "$blue_container"
            state_blue_restore_status=intent
            write_state "$state_phase"
            test_crash after-legacy-blue-restore-intent
            ;;
        intent)
            ;;
        adopted)
            assert_blue_state
            return
            ;;
        *)
            fail 'legacy blue restore status is invalid'
            ;;
    esac

    restored_blue_preexisted=false
    restored_blue_preexisting_id=none
    docker_container_presence "$blue_container"
    if [ "$container_presence" = present ]; then
        restored_blue_preexisted=true
        restored_blue_preexisting_id=$container_presence_id
        [ "$container_presence_id" != "$state_blue_id" ] \
            && [ "$container_presence_image_id" = "$state_blue_image_id" ] \
            && [ "$(container_image_reference "$blue_container")" = "$state_blue_image_reference" ] \
            && [ "$(container_label "$blue_container" com.docker.compose.project)" = "$state_blue_compose_project" ] \
            && [ "$(container_label "$blue_container" com.docker.compose.service)" = "$state_blue_compose_service" ] \
            && [ "$(container_label "$blue_container" com.docker.compose.project.config_files)" = "$state_blue_compose_config_files" ] \
            || fail 'partially restored legacy blue differs from its immutable write-ahead plan'
    fi
    source_compose up --detach --no-build --pull never --no-deps "$source_compose_service" \
        > "$operation_directory/legacy-blue-restore.log" 2>&1
    [ "$restored_blue_preexisted" = true ] || test_crash after-legacy-blue-restore-create
    [ "$restored_blue_preexisted" != true ] \
        || [ "$(container_id "$blue_container")" = "$restored_blue_preexisting_id" ] \
        || fail 'legacy blue restore reconciliation replaced its crash-created exact identity'
    assert_container_running "$blue_container"
    [ "$(container_id "$blue_container")" != "$state_blue_id" ] \
        || fail 'legacy blue restore did not create a replacement container'
    [ "$(container_image_id "$blue_container")" = "$state_blue_image_id" ] \
        || fail 'restored legacy blue image identity changed'
    [ "$(container_image_reference "$blue_container")" = "$state_blue_image_reference" ] \
        || fail 'restored legacy blue image reference changed'
    [ "$(container_label "$blue_container" com.docker.compose.project)" = "$state_blue_compose_project" ] \
        && [ "$(container_label "$blue_container" com.docker.compose.service)" = "$state_blue_compose_service" ] \
        && [ "$(container_label "$blue_container" com.docker.compose.project.config_files)" = "$state_blue_compose_config_files" ] \
        || fail 'restored legacy blue compose invocation identity changed'
    if ! is_test_mode; then
        [ "$(container_repository_digest "$blue_container")" = "$state_blue_image_digest" ] \
            || fail 'restored legacy blue repository digest changed'
    fi
    [ "$(runtime_fence_container_runtime_sha256 "$blue_container")" = \
        "$state_source_runtime_sha256" ] \
        || fail 'restored legacy blue differs from its pinned runtime-fence v4 identity'

    state_blue_id=$(container_id "$blue_container")
    state_blue_rollback_replacement_id=$state_blue_id
    state_blue_bridge_ip=$(docker inspect --format "{{with index .NetworkSettings.Networks \"${control_plane_network}\"}}{{.IPAddress}}{{end}}" "$blue_container")
    [ -n "$state_blue_bridge_ip" ] || fail 'restored legacy blue bridge address was not found'
    state_blue_restore_status=adopted
    write_state "$state_phase"
}

select_pool_writer_context()
{
    writer_color=$1
    case "$writer_color" in
        green)
            writer_member=$green_writer_member
            writer_epoch_value=$writer_epoch
            case "$writer_member" in
                web-a)
                    writer_container=$green_container
                    writer_container_id=$state_green_id
                    writer_image_id=$state_green_image_id
                    writer_volume=$green_state_volume
                    ;;
                web-b)
                    writer_container=$green_web_b_container
                    writer_container_id=$state_green_web_b_id
                    writer_image_id=$state_green_web_b_image_id
                    writer_volume=$green_web_b_private_volume
                    ;;
                *) fail "unknown green writer member: $writer_member" ;;
            esac
            ;;
        blue)
            writer_member=$blue_writer_member
            writer_epoch_value=$blue_writer_epoch
            case "$writer_member" in
                web-a)
                    writer_container=$replacement_blue_container
                    writer_container_id=$state_replacement_blue_id
                    writer_image_id=$state_replacement_blue_image_id
                    writer_volume=$blue_state_volume
                    ;;
                web-b)
                    writer_container=$replacement_blue_web_b_container
                    writer_container_id=$state_replacement_blue_web_b_id
                    writer_image_id=$state_replacement_blue_web_b_image_id
                    writer_volume=$blue_web_b_private_volume
                    ;;
                *) fail "unknown blue writer member: $writer_member" ;;
            esac
            ;;
        *) fail "unknown pool writer color: $writer_color" ;;
    esac
}

assert_pool_web_markers()
{
    marker_color=$1
    marker_expectation=$2
    case "$marker_color" in
        green)
            marker_a_volume=$green_state_volume
            marker_a_epoch=$green_web_epoch
            marker_b_volume=$green_web_b_private_volume
            marker_b_epoch=$green_web_b_epoch
            ;;
        blue)
            marker_a_volume=$blue_state_volume
            marker_a_epoch=$blue_web_epoch
            marker_b_volume=$blue_web_b_private_volume
            marker_b_epoch=$blue_web_b_epoch
            ;;
        *) fail "unknown pool web-marker color: $marker_color" ;;
    esac
    [ "$(marker_status "$marker_a_volume" "$marker_a_epoch" web-epoch)" = \
        "$marker_expectation" ] \
        && [ "$(marker_status "$marker_b_volume" "$marker_b_epoch" web-epoch)" = \
            "$marker_expectation" ] \
        || fail "$marker_color pool web markers are not exactly $marker_expectation"
}

assert_pool_writer_authority()
{
    authority_color=$1
    authority_expected=$2
    select_pool_writer_context "$authority_color"
    [ "$(marker_status "$writer_volume" "$writer_epoch_value")" = "$authority_expected" ] \
        || fail "$authority_color selected writer marker is not exactly $authority_expected"
    case "$authority_color:$writer_member" in
        green:web-a) non_writer_volume=$green_web_b_private_volume ;;
        green:web-b) non_writer_volume=$green_state_volume ;;
        blue:web-a) non_writer_volume=$blue_web_b_private_volume ;;
        blue:web-b) non_writer_volume=$blue_state_volume ;;
        *) fail 'pool writer authority selection is invalid' ;;
    esac
    [ "$(marker_status "$non_writer_volume" "$writer_epoch_value")" = absent ] \
        || fail "$authority_color non-writer member has writer authority"
}

revoke_pool_web_markers_if_safe()
{
    revoke_color=$1
    case "$revoke_color" in
        green)
            revoke_a_volume=$green_state_volume
            revoke_a_epoch=$green_web_epoch
            revoke_b_volume=$green_web_b_private_volume
            revoke_b_epoch=$green_web_b_epoch
            ;;
        blue)
            revoke_a_volume=$blue_state_volume
            revoke_a_epoch=$blue_web_epoch
            revoke_b_volume=$blue_web_b_private_volume
            revoke_b_epoch=$blue_web_b_epoch
            ;;
        *) fail "unknown pool web-marker revocation color: $revoke_color" ;;
    esac
    for revoke_member in a b; do
        case "$revoke_member" in
            a) revoke_volume=$revoke_a_volume; revoke_epoch=$revoke_a_epoch ;;
            b) revoke_volume=$revoke_b_volume; revoke_epoch=$revoke_b_epoch ;;
        esac
        case "$(marker_status "$revoke_volume" "$revoke_epoch" web-epoch)" in
            absent) ;;
            matching)
                revoke_marker "$revoke_volume" "$revoke_color-web-$revoke_member" \
                    "$revoke_epoch" matching web-epoch
                ;;
            *) fail "$revoke_color web-$revoke_member marker is unsafe to revoke" ;;
        esac
    done
}

assert_green_pool_volumes_reusable()
{
    assert_fresh_marker_volume "$green_state_volume" green-web-a "$writer_epoch"
    assert_fresh_marker_volume "$green_state_volume" green-web-a "$green_web_epoch" web-epoch
    assert_fresh_marker_volume "$green_state_volume" green-web-a \
        "$green_web_a_route_drain_epoch" route-drain-epoch
    assert_fresh_marker_volume "$green_web_b_private_volume" green-web-b "$writer_epoch"
    assert_fresh_marker_volume "$green_web_b_private_volume" green-web-b \
        "$green_web_b_epoch" web-epoch
    assert_fresh_marker_volume "$green_web_b_private_volume" green-web-b \
        "$green_web_b_route_drain_epoch" route-drain-epoch
    assert_fresh_marker_volume "$green_state_volume" coordination "$mutation_freeze_epoch" \
        mutation-freeze-epoch
}

promote_writer()
{
    promotion_container=$1
    promotion_volume=$2
    promotion_name=$3
    promotion_epoch=$4
    promotion_log="$operation_directory/${promotion_name}-promotion.log"
    select_pool_writer_context "$promotion_name"
    [ "$promotion_container" = "$writer_container" ] \
        && [ "$promotion_volume" = "$writer_volume" ] \
        && [ "$promotion_epoch" = "$writer_epoch_value" ] \
        || fail "$promotion_name writer promotion does not target the selected pool member"
    case "$promotion_name" in
        green)
            [ "$state_green_restart_policy_status" = repinned ] \
                && [ "$state_green_web_b_restart_policy_status" = repinned ] \
                || fail 'green pool cannot promote before both restart policies are repinned'
            ;;
        blue)
            [ "$state_replacement_blue_restart_policy_status" = repinned ] \
                && [ "$state_replacement_blue_web_b_restart_policy_status" = repinned ] \
                || fail 'blue pool cannot promote before both restart policies are repinned'
            ;;
        *)
            fail "unknown writer-promotion color: $promotion_name"
            ;;
    esac
    promotion_container_id=$writer_container_id
    promotion_image_id=$writer_image_id
    assert_pool_web_markers "$promotion_name" matching
    assert_container_identity_present "$promotion_container" "$promotion_container_id" \
        "$promotion_image_id"
    [ "$(container_restart_policy "$promotion_container_id")" = always ] \
        || fail "$promotion_name writer restart policy is not always before promotion"

    observed_marker_status=$(marker_status "$promotion_volume" "$promotion_epoch")
    case "$observed_marker_status" in
        absent|matching)
            ;;
        *)
            fail "$promotion_name writer marker is stale or mismatched"
            ;;
    esac
    activate_marker "$promotion_volume" "$promotion_name" "$promotion_epoch" writer-epoch
    test_crash "after-${promotion_name}-marker-fsync"

    promotion_status=0
    if is_test_mode && [ "${CONTROL_PLANE_TEST_GREEN_PROMOTION_UNPROVEN:-0}" = 1 ] \
        && [ "$promotion_name" = green ]; then
        docker exec --env CONTROL_PLANE_PROMOTION_CONFIRM=blue-stopped \
            --env CONTROL_PLANE_LAB_FAIL_PROMOTION_UNPROVEN=1 "$promotion_container" \
            /usr/local/bin/coolify-entrypoint promote-writer > "$promotion_log" 2>&1 \
            || promotion_status=$?
    elif is_test_mode && [ "${CONTROL_PLANE_TEST_GREEN_PROMOTION_FAILURE:-0}" = 1 ] \
        && [ "$promotion_name" = green ]; then
        docker exec --env CONTROL_PLANE_PROMOTION_CONFIRM=blue-stopped \
            --env CONTROL_PLANE_LAB_FAIL_PROMOTION=1 "$promotion_container" \
            /usr/local/bin/coolify-entrypoint promote-writer > "$promotion_log" 2>&1 \
            || promotion_status=$?
    else
        docker exec --env CONTROL_PLANE_PROMOTION_CONFIRM=blue-stopped "$promotion_container" \
            /usr/local/bin/coolify-entrypoint promote-writer > "$promotion_log" 2>&1 \
            || promotion_status=$?
    fi
    if [ "$promotion_status" -ne 0 ]; then
        promotion_stopped_before_revocation=0
        if [ "$promotion_status" -ne 1 ]; then
            assert_container_identity_present "$promotion_container" "$promotion_container_id" \
                "$promotion_image_id"
            docker stop --time "$container_stop_timeout" "$promotion_container_id" \
                >> "$promotion_log" 2>&1 \
                || fail "$promotion_name failed-promotion candidate could not be stopped before unproven rollback revocation"
            assert_container_identity_present "$promotion_container" "$promotion_container_id" \
                "$promotion_image_id"
            [ "$(container_is_running "$promotion_container")" = false ] \
                || fail "$promotion_name failed-promotion candidate remained running before unproven rollback revocation"
            promotion_stopped_before_revocation=1
            test_crash "after-${promotion_name}-unproven-stop-before-marker-revoke"
        fi
        revoke_marker "$promotion_volume" "$promotion_name" "$promotion_epoch" matching writer-epoch
        [ "$(marker_status "$promotion_volume" "$promotion_epoch")" = absent ] \
            || fail "$promotion_name writer marker remained after failed promotion revocation"
        fencing_status=0
        if [ "$promotion_stopped_before_revocation" -eq 0 ]; then
            if is_test_mode && [ "${CONTROL_PLANE_TEST_GREEN_FENCE_FAILURE:-0}" = 1 ] \
                && [ "$promotion_name" = green ]; then
                docker exec --env CONTROL_PLANE_LAB_FAIL_FENCE=1 "$promotion_container" \
                    /usr/local/bin/coolify-entrypoint fence-writer >> "$promotion_log" 2>&1 \
                    || fencing_status=$?
            else
                docker exec "$promotion_container" /usr/local/bin/coolify-entrypoint fence-writer \
                    >> "$promotion_log" 2>&1 || fencing_status=$?
            fi
            if [ "$fencing_status" -eq 0 ]; then
                docker exec "$promotion_container" /usr/local/bin/coolify-entrypoint writer-fenced \
                    >> "$promotion_log" 2>&1 || fencing_status=$?
            fi
        fi
        if [ "$promotion_stopped_before_revocation" -eq 0 ] \
            && [ "$fencing_status" -ne 0 ]; then
            assert_container_identity_present "$promotion_container" "$promotion_container_id" \
                "$promotion_image_id"
            docker stop --time "$container_stop_timeout" "$promotion_container_id" \
                >> "$promotion_log" 2>&1 \
                || fail "$promotion_name failed-promotion candidate could not be stopped after writer fencing failed"
            assert_container_identity_present "$promotion_container" "$promotion_container_id" \
                "$promotion_image_id"
            [ "$(container_is_running "$promotion_container")" = false ] \
                || fail "$promotion_name failed-promotion candidate remained running after writer fencing failed"
        fi
        fail "$promotion_name application did not acknowledge its operator-owned writer marker"
    fi
    [ "$(marker_status "$promotion_volume" "$promotion_epoch")" = matching ] \
        || fail "$promotion_name writer marker bytes do not equal its fresh epoch"
    assert_container_identity_present "$promotion_container" "$promotion_container_id" \
        "$promotion_image_id"
    [ "$(container_restart_policy "$promotion_container_id")" = always ] \
        || fail "$promotion_name promoted container restart policy changed"
    wait_for_container_health "$promotion_container"
    prove_promoted_background_services "$promotion_container"
}

promote_selected_writer()
{
    selected_color=$1
    select_pool_writer_context "$selected_color"
    promote_writer "$writer_container" "$writer_volume" "$selected_color" "$writer_epoch_value"
}

prove_promoted_background_service()
{
    promotion_container=$1
    setting_name=$2
    default_value=$3
    service_name=$4

    if docker exec "$promotion_container" /usr/local/bin/coolify-entrypoint \
        service-enabled "$setting_name" "$default_value"; then
        if is_test_mode; then
            docker exec "$promotion_container" /usr/local/bin/control-plane-lab-service \
                service-up "$service_name" \
                || fail "promoted configured service is not supervised-up: $service_name"
        else
            docker exec --user 0 "$promotion_container" /command/s6-svstat -u \
                "/run/service/$service_name" >/dev/null \
                || fail "promoted configured service is not supervised-up: $service_name"
        fi
    fi
}

prove_promoted_background_services()
{
    promotion_container=$1

    prove_promoted_background_service "$promotion_container" HORIZON_ENABLED true horizon
    prove_promoted_background_service "$promotion_container" SCHEDULER_ENABLED true scheduler-worker
    prove_promoted_background_service "$promotion_container" NIGHTWATCH_ENABLED false nightwatch-agent
}

assert_migration_compatibility_file()
{
    compatibility_file=$1
    compatibility_file_name=$2

    assert_regular_file "$compatibility_file" "$compatibility_file_name"
    grep -F -x -q "image-digest=${green_image_digest}" "$compatibility_file" \
        || fail 'migration compatibility gate does not attest the exact green image digest'
    grep -F -x -q "legacy-blue-image-digest=${blue_image_digest}" "$compatibility_file" \
        || fail 'migration compatibility gate does not attest the exact replacement blue image digest'
    grep -F -x -q 'migration-class=additive' "$compatibility_file" \
        || fail 'migration compatibility gate is not additive-only'
    grep -F -x -q 'legacy-blue-read-compatible=true' "$compatibility_file" \
        || fail 'migration compatibility gate does not preserve legacy blue reads'
    grep -F -x -q 'legacy-blue-write-compatible=true' "$compatibility_file" \
        || fail 'migration compatibility gate does not preserve legacy blue writes'
}

assert_migration_compatibility()
{
    assert_migration_compatibility_file "$migration_compatibility_file" CONTROL_PLANE_MIGRATION_COMPATIBILITY_FILE
}

require_migration_timeouts()
{
    require_value CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT "$migration_lock_timeout"
    require_value CONTROL_PLANE_MIGRATION_STATEMENT_TIMEOUT "$migration_statement_timeout"
    validate_migration_timeout "$migration_lock_timeout" CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT
    validate_migration_timeout "$migration_statement_timeout" CONTROL_PLANE_MIGRATION_STATEMENT_TIMEOUT
}

require_live_expand_migration_configuration()
{
    require_value CONTROL_PLANE_MIGRATION_COMPATIBILITY_FILE "$migration_compatibility_file"
    assert_regular_file "$migration_compatibility_file" CONTROL_PLANE_MIGRATION_COMPATIBILITY_FILE
    require_migration_timeouts
}

assert_migration_image_probe_runner()
{
    if ! migration_probe_json=$(docker inspect "$migration_probe_name"); then
        migration_reconciliation_api_unavailable=1
        fail 'Docker API is unavailable while reading migration image-probe identity'
    fi
    printf '%s' "$migration_probe_json" | jq --exit-status \
        --arg runner_name "/${migration_probe_name}" \
        --arg runner_id "$migration_probe_id" \
        --arg image_id "$(image_id "$green_image")" \
        --arg operation_id "$operation_id" \
        --arg role "$migration_probe_role" '
            .[0].Name == $runner_name
            and .[0].Id == $runner_id
            and .[0].Image == $image_id
            and .[0].Config.Labels["io.coolify.control-plane.operation-id"] == $operation_id
            and .[0].Config.Labels["io.coolify.control-plane.migration-probe"] == $role
        ' >/dev/null || fail 'migration image-probe runner identity differs from its plan'
}

write_migration_image_probe_state()
{
    migration_probe_status=$1
    migration_probe_state_file="$operation_directory/migration-${migration_probe_role}-probe.state"
    migration_probe_state_candidate="${migration_probe_state_file}.new"
    rm -f "$migration_probe_state_candidate"
    {
        printf '%s\n' 'version=1'
        printf 'operation_id=%s\n' "$operation_id"
        printf 'status=%s\n' "$migration_probe_status"
        printf 'role=%s\n' "$migration_probe_role"
        printf 'runner_name=%s\n' "$migration_probe_name"
        printf 'runner_id=%s\n' "$migration_probe_id"
        printf 'runner_exit_code=%s\n' "$migration_probe_exit_code"
        printf 'runner_log_sha256=%s\n' "$(sha256_file "$migration_probe_destination")"
    } > "$migration_probe_state_candidate"
    chmod 600 "$migration_probe_state_candidate"
    sync -f "$migration_probe_state_candidate"
    mv -f "$migration_probe_state_candidate" "$migration_probe_state_file"
    sync -f "$operation_directory"
}

remove_migration_image_probe_runner()
{
    docker_container_presence "$migration_probe_name"
    if [ "$migration_runner_presence" = present ]; then
        assert_migration_image_probe_runner
        [ "$(printf '%s' "$migration_probe_json" | jq --raw-output '.[0].State.Running')" = false ] \
            || fail 'refusing to remove a running migration image-probe runner'
        docker rm "$migration_probe_id" >/dev/null
    fi
    docker_container_presence "$migration_probe_name"
    [ "$migration_runner_presence" = absent ] \
        || fail 'migration image-probe runner remained after terminal proof cleanup'
}

start_migration_image_probe()
{
    migration_probe_role=$1
    migration_probe_destination=$2
    migration_probe_command=$3
    migration_probe_key=$(printf '%s:%s\n' "$operation_id" "$migration_probe_role" \
        | sha256sum | awk '{ print substr($1, 1, 20) }')
    migration_probe_name="coolify-cp-probe-${migration_probe_role}-${migration_probe_key}"
    docker_container_presence "$migration_probe_name"
    [ "$migration_runner_presence" = absent ] \
        || fail 'deterministic migration image-probe runner already exists'
    migration_probe_id=$(docker create --name "$migration_probe_name" --network none \
        --label "io.coolify.control-plane.operation-id=${operation_id}" \
        --label "io.coolify.control-plane.migration-probe=${migration_probe_role}" \
        --env "CONTROL_PLANE_IMMUTABLE_BASELINE_SURPLUS_PATH=${IMMUTABLE_BASELINE_SURPLUS_PATH}" \
        --env "CONTROL_PLANE_MIGRATION_FINGERPRINT_PATH=${CONTROL_PLANE_MIGRATION_FINGERPRINT_PATH}" \
        --entrypoint /bin/sh "$green_image" -ec "$migration_probe_command")
    validate_container_id "$migration_probe_id" 'migration image-probe runner ID'
    assert_migration_image_probe_runner
    docker start "$migration_probe_id" >/dev/null \
        || fail 'Docker API is unavailable while starting migration image probe'
    migration_probe_exit_code=$(docker wait "$migration_probe_id") \
        || fail 'Docker API is unavailable while waiting for migration image probe'
    printf '%s' "$migration_probe_exit_code" | grep -Eq '^[0-9]+$' \
        && [ "$migration_probe_exit_code" -le 255 ] \
        || fail 'migration image-probe runner exit code is malformed'
    docker logs "$migration_probe_id" > "$migration_probe_destination" 2>&1 \
        || fail 'Docker API is unavailable while capturing migration image-probe logs'
    chmod 600 "$migration_probe_destination"
    sync -f "$migration_probe_destination"
    if [ "$migration_probe_exit_code" -ne 0 ]; then
        write_migration_image_probe_state failed
        remove_migration_image_probe_runner
        fail "candidate image $migration_probe_role probe failed"
    fi
}

complete_migration_image_probe()
{
    write_migration_image_probe_state passed
    remove_migration_image_probe_runner
}

generate_migration_manifest()
{
    manifest_destination=$1

    # Expansion belongs to the candidate container, not the operator shell.
    # shellcheck disable=SC2016
    start_migration_image_probe manifest "$manifest_destination" '
        cd /var/www/html
        test -d database/migrations
        if find database/migrations -type l -name "2026_07_12_*.php" -print -quit | grep -q .; then
            exit 1
        fi
        find database/migrations -type f -name "*.php" -print | LC_ALL=C sort > /tmp/coolify-migration-files
        test -s /tmp/coolify-migration-files
        printf "%s\n" \
            "$CONTROL_PLANE_IMMUTABLE_BASELINE_SURPLUS_PATH" \
            "$CONTROL_PLANE_MIGRATION_FINGERPRINT_PATH" \
            config/database.php \
            vendor/laravel/framework/src/Illuminate/Database/Connection.php \
            vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php \
            vendor/laravel/framework/src/Illuminate/Database/Console/Migrations/MigrateCommand.php \
            vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/PostgresGrammar.php \
            >> /tmp/coolify-migration-files
        LC_ALL=C sort -u /tmp/coolify-migration-files -o /tmp/coolify-migration-files
        test ! -L "$CONTROL_PLANE_MIGRATION_FINGERPRINT_PATH"
        while IFS= read -r stable_migration_file; do
            test -f "$stable_migration_file"
            sha256sum "$stable_migration_file"
        done < /tmp/coolify-migration-files
    '
    [ -s "$manifest_destination" ] || fail 'candidate image did not produce a migration manifest'
    awk 'NF == 2 && $1 ~ /^[0-9a-f]{64}$/ && $2 ~ /^[-A-Za-z0-9_.\/]+$/ { valid++ }
        END { exit(valid == NR && NR > 0 ? 0 : 1) }' "$manifest_destination" \
        || fail 'candidate image produced a malformed migration manifest'
    complete_migration_image_probe
}

manifest_path_sha256()
{
    manifest_path=$1
    awk -v expected_path="$manifest_path" '
        NF != 2 || $1 !~ /^[0-9a-f]{64}$/ || $2 !~ /^[-A-Za-z0-9_.\/]+$/ { exit 1 }
        $2 == expected_path { matches++; checksum = $1 }
        END {
            if (matches != 1) { exit 1 }
            print checksum
        }
    ' "$migration_manifest_artifact_file"
}

validate_immutable_baseline_surplus_file()
{
    surplus_file=$1
    assert_regular_file "$surplus_file" 'immutable baseline migration surplus artifact'
    awk '
        NF != 1 || $1 !~ /^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+$/ { exit 1 }
        previous != "" && $1 <= previous { exit 1 }
        { previous = $1; count++ }
        END { exit(count == 3 ? 0 : 1) }
    ' "$surplus_file" \
        || fail 'immutable baseline migration surplus must contain exactly three ordered unique names'
}

assert_immutable_baseline_surplus_artifact()
{
    validate_immutable_baseline_surplus_file "$migration_baseline_surplus_file"
    baseline_surplus_manifest_sha256=$(manifest_path_sha256 "$IMMUTABLE_BASELINE_SURPLUS_PATH") \
        || fail 'candidate manifest does not bind the immutable baseline migration surplus'
    [ "$(sha256_file "$migration_baseline_surplus_file")" = "$baseline_surplus_manifest_sha256" ] \
        || fail 'immutable baseline migration surplus differs from the candidate manifest'
}

validate_control_plane_migration_fingerprint_file()
{
    fingerprint_file=$1
    assert_regular_file "$fingerprint_file" 'control-plane migration inventory fingerprint'
    awk '
        NR == 1 && $0 ~ /^count=[1-9][0-9]*$/ { count++; next }
        NR == 2 && $0 ~ /^sha256=[0-9a-f]{64}$/ { fingerprint++; next }
        { invalid = 1 }
        END { exit(NR == 2 && count == 1 && fingerprint == 1 && !invalid ? 0 : 1) }
    ' "$fingerprint_file" \
        || fail 'control-plane migration inventory fingerprint is malformed'
}

assert_control_plane_migration_inventory_fingerprint()
{
    migration_names_file=$1
    fingerprint_file=$2
    validate_control_plane_migration_fingerprint_file "$fingerprint_file"
    expected_migration_count=$(sed -n 's/^count=//p' "$fingerprint_file")
    expected_migration_sha256=$(sed -n 's/^sha256=//p' "$fingerprint_file")
    actual_migration_count=$(wc -l < "$migration_names_file" | tr -d '[:space:]')
    actual_migration_sha256=$(sed 's#^#database/migrations/#; s#$#.php#' \
        "$migration_names_file" | sha256sum | awk '{print $1}')
    [ "$actual_migration_count" = "$expected_migration_count" ] \
        && [ "$actual_migration_sha256" = "$expected_migration_sha256" ] \
        || fail 'candidate control-plane migration inventory does not match the reviewed fingerprint'
}

assert_control_plane_migration_fingerprint_artifact()
{
    validate_control_plane_migration_fingerprint_file "$migration_inventory_fingerprint_file"
    migration_fingerprint_manifest_sha256=$(manifest_path_sha256 \
        "$CONTROL_PLANE_MIGRATION_FINGERPRINT_PATH") \
        || fail 'candidate manifest does not bind the control-plane migration inventory fingerprint'
    [ "$(sha256_file "$migration_inventory_fingerprint_file")" \
        = "$migration_fingerprint_manifest_sha256" ] \
        || fail 'control-plane migration inventory fingerprint differs from the candidate manifest'
}

extract_candidate_immutable_baseline_surplus()
{
    surplus_destination=$1
    # Expansion belongs to the candidate container, not the operator shell.
    # shellcheck disable=SC2016
    start_migration_image_probe baseline "$surplus_destination" '
            cd /var/www/html
            test -f "$CONTROL_PLANE_IMMUTABLE_BASELINE_SURPLUS_PATH"
            cat "$CONTROL_PLANE_IMMUTABLE_BASELINE_SURPLUS_PATH"
        '
    validate_immutable_baseline_surplus_file "$surplus_destination"
    complete_migration_image_probe
}

extract_candidate_control_plane_migration_fingerprint()
{
    fingerprint_destination=$1
    # Expansion belongs to the candidate container, not the operator shell.
    # shellcheck disable=SC2016
    start_migration_image_probe fingerprint "$fingerprint_destination" '
            cd /var/www/html
            test -f "$CONTROL_PLANE_MIGRATION_FINGERPRINT_PATH"
            test ! -L "$CONTROL_PLANE_MIGRATION_FINGERPRINT_PATH"
            cat "$CONTROL_PLANE_MIGRATION_FINGERPRINT_PATH"
        '
    validate_control_plane_migration_fingerprint_file "$fingerprint_destination"
    complete_migration_image_probe
}

assert_persisted_live_expand_artifacts()
{
    [ "$state_migration_compatibility_sha256" != none ] \
        || fail 'live migration compatibility artifact was not persisted'
    [ "$state_migration_manifest_sha256" != none ] \
        || fail 'live migration manifest artifact was not persisted'
    [ "$state_migration_image_id" = "$state_green_image_id" ] \
        || fail 'live migration did not use the preflight green image identity'
    [ "$(image_id "$green_image")" = "$state_migration_image_id" ] \
        || fail 'live migration image identity no longer matches the requested green image'
    [ "$state_migration_image_digest" = "$green_image_digest" ] \
        || fail 'live migration image digest no longer matches the requested green image'
    [ "$state_migration_lock_timeout" = "$migration_lock_timeout" ] \
        || fail 'live migration lock timeout no longer matches the recorded artifact'
    [ "$state_migration_statement_timeout" = "$migration_statement_timeout" ] \
        || fail 'live migration statement timeout no longer matches the recorded artifact'
    assert_regular_file "$migration_compatibility_artifact_file" 'persisted live migration compatibility artifact'
    assert_regular_file "$migration_manifest_artifact_file" 'persisted live migration manifest artifact'
    assert_immutable_baseline_surplus_artifact
    assert_control_plane_migration_fingerprint_artifact
    [ "$(sha256_file "$migration_compatibility_artifact_file")" = "$state_migration_compatibility_sha256" ] \
        || fail 'persisted live migration compatibility artifact checksum changed'
    [ "$(sha256_file "$migration_manifest_artifact_file")" = "$state_migration_manifest_sha256" ] \
        || fail 'persisted live migration manifest artifact checksum changed'
    assert_migration_compatibility_file "$migration_compatibility_artifact_file" \
        'persisted live migration compatibility artifact'

    manifest_candidate="$operation_directory/.migration-manifest-verify-${operation_id}-$$.new"
    generate_migration_manifest "$manifest_candidate"
    [ "$(sha256_file "$manifest_candidate")" = "$state_migration_manifest_sha256" ] \
        || fail 'candidate image migration manifest no longer matches the persisted artifact'
    rm -f "$manifest_candidate"
}

prepare_live_expand_artifacts()
{
    compatibility_sha256=$(sha256_file "$migration_compatibility_file")
    manifest_candidate="$operation_directory/.migration-manifest-${operation_id}-$$.new"
    baseline_surplus_candidate="$operation_directory/.immutable-baseline-surplus-${operation_id}-$$.new"
    fingerprint_candidate="$operation_directory/.migration-inventory-fingerprint-${operation_id}-$$.new"
    generate_migration_manifest "$manifest_candidate"
    manifest_sha256=$(sha256_file "$manifest_candidate")
    extract_candidate_immutable_baseline_surplus "$baseline_surplus_candidate"
    extract_candidate_control_plane_migration_fingerprint "$fingerprint_candidate"
    baseline_surplus_manifest_sha256=$(awk -v expected_path="$IMMUTABLE_BASELINE_SURPLUS_PATH" '
        NF != 2 || $1 !~ /^[0-9a-f]{64}$/ || $2 !~ /^[-A-Za-z0-9_.\/]+$/ { exit 1 }
        $2 == expected_path { matches++; checksum = $1 }
        END {
            if (matches != 1) { exit 1 }
            print checksum
        }
    ' "$manifest_candidate") \
        || fail 'candidate manifest does not bind the immutable baseline migration surplus'
    [ "$(sha256_file "$baseline_surplus_candidate")" = "$baseline_surplus_manifest_sha256" ] \
        || fail 'candidate immutable baseline migration surplus does not match its manifest'
    migration_fingerprint_manifest_sha256=$(awk \
        -v expected_path="$CONTROL_PLANE_MIGRATION_FINGERPRINT_PATH" '
        NF != 2 || $1 !~ /^[0-9a-f]{64}$/ || $2 !~ /^[-A-Za-z0-9_.\/]+$/ { exit 1 }
        $2 == expected_path { matches++; checksum = $1 }
        END {
            if (matches != 1) { exit 1 }
            print checksum
        }
    ' "$manifest_candidate") \
        || fail 'candidate manifest does not bind the control-plane migration inventory fingerprint'
    [ "$(sha256_file "$fingerprint_candidate")" = "$migration_fingerprint_manifest_sha256" ] \
        || fail 'candidate control-plane migration inventory fingerprint does not match its manifest'

    if [ -e "$migration_artifact_directory" ]; then
        [ -d "$migration_artifact_directory" ] \
            || fail 'persisted live migration artifact path is not a directory'
        assert_regular_file "$migration_compatibility_artifact_file" 'persisted live migration compatibility artifact'
        assert_regular_file "$migration_manifest_artifact_file" 'persisted live migration manifest artifact'
        assert_immutable_baseline_surplus_artifact
        assert_control_plane_migration_fingerprint_artifact
        [ "$(sha256_file "$migration_compatibility_artifact_file")" = "$compatibility_sha256" ] \
            || fail 'persisted live migration compatibility artifact does not match the requested retry input'
        [ "$(sha256_file "$migration_manifest_artifact_file")" = "$manifest_sha256" ] \
            || fail 'persisted live migration manifest artifact does not match the candidate image'
    else
        artifact_candidate="$operation_directory/.live-expand-migration-artifact-${operation_id}-$$.new"
        (umask 077; mkdir "$artifact_candidate")
        cp -p "$migration_compatibility_file" "$artifact_candidate/compatibility"
        mv -f "$manifest_candidate" "$artifact_candidate/manifest"
        mv -f "$baseline_surplus_candidate" "$artifact_candidate/immutable-baseline-surplus"
        mv -f "$fingerprint_candidate" "$artifact_candidate/migration-inventory-fingerprint"
        [ "$(sha256_file "$artifact_candidate/compatibility")" = "$compatibility_sha256" ] \
            || fail 'could not persist the exact migration compatibility artifact'
        [ "$(sha256_file "$artifact_candidate/manifest")" = "$manifest_sha256" ] \
            || fail 'could not persist the exact candidate migration manifest artifact'
        [ "$(sha256_file "$artifact_candidate/immutable-baseline-surplus")" = "$baseline_surplus_manifest_sha256" ] \
            || fail 'could not persist the exact immutable baseline migration surplus artifact'
        [ "$(sha256_file "$artifact_candidate/migration-inventory-fingerprint")" \
            = "$migration_fingerprint_manifest_sha256" ] \
            || fail 'could not persist the exact control-plane migration inventory fingerprint'
        mv "$artifact_candidate" "$migration_artifact_directory"
        sync
    fi

    rm -f "$manifest_candidate" "$baseline_surplus_candidate" "$fingerprint_candidate"
    state_migration_compatibility_sha256=$compatibility_sha256
    state_migration_manifest_sha256=$manifest_sha256
    state_migration_image_id=$state_green_image_id
    state_migration_image_digest=$green_image_digest
    state_migration_lock_timeout=$migration_lock_timeout
    state_migration_statement_timeout=$migration_statement_timeout
}

database_psql()
{
    docker exec \
        --env "PGOPTIONS=-c lock_timeout=${migration_lock_timeout} -c statement_timeout=${migration_statement_timeout}" \
        "$database_container" psql --no-psqlrc --tuples-only --no-align --quiet \
        --set ON_ERROR_STOP=1 \
        --username "$database_user" --dbname "$database_name" "$@"
}

assert_control_plane_migration_lock_available()
{
    observe_control_plane_global_migration_activity
    [ "$migration_global_advisory_lock_count" = 0 ] \
        && [ "$migration_global_backend_count" = 0 ] \
        || fail 'control-plane migration global advisory lock or backend is still active'
}

observe_control_plane_global_migration_activity()
{
    migration_activity_candidate="$operation_directory/.migration-activity-${operation_id}-$$"
    if ! database_psql --field-separator=, --command "
WITH global_lock_key AS (
    SELECT hashtextextended('coolify-control-plane-expand', 0) AS value
), migration_backend AS (
    SELECT pid
    FROM pg_stat_activity
    WHERE pid <> pg_backend_pid()
      AND application_name LIKE 'coolify-control-plane-expand-%'
), global_lock AS (
    SELECT advisory_lock.pid
    FROM pg_locks AS advisory_lock
    CROSS JOIN global_lock_key
    WHERE advisory_lock.locktype = 'advisory'
      AND advisory_lock.granted
      AND advisory_lock.objsubid = 1
      AND advisory_lock.classid = (((global_lock_key.value >> 32) & 4294967295)::oid)
      AND advisory_lock.objid = ((global_lock_key.value & 4294967295)::oid)
)
SELECT (SELECT count(*) FROM migration_backend),
       (SELECT count(*) FROM global_lock),
       (SELECT count(*)
        FROM global_lock
        JOIN migration_backend USING (pid))" \
        > "$migration_activity_candidate"; then
        rm -f "$migration_activity_candidate"
        migration_reconciliation_api_unavailable=1
        fail 'PostgreSQL API is unavailable during migration activity reconciliation'
    fi
    migration_activity=$(tr -d '[:space:]' < "$migration_activity_candidate")
    rm -f "$migration_activity_candidate"
    printf '%s\n' "$migration_activity" | awk -F, '
        NF == 3 && $1 ~ /^[0-9]+$/ && $2 ~ /^[0-9]+$/ && $3 ~ /^[0-9]+$/ \
            && $3 <= $1 && $3 <= $2 { valid++ }
        END { exit(valid == 1 ? 0 : 1) }
    ' || fail 'PostgreSQL migration activity probe returned malformed backend or lock truth'
    migration_backend_count=$(printf '%s\n' "$migration_activity" | awk -F, '{ print $1 }')
    migration_global_advisory_lock_count=$(printf '%s\n' "$migration_activity" | awk -F, '{ print $2 }')
    migration_global_backend_count=$(printf '%s\n' "$migration_activity" | awk -F, '{ print $3 }')
}

observe_control_plane_migration_database_activity()
{
    migration_activity_candidate="$operation_directory/.migration-activity-${operation_id}-$$"
    if ! database_psql --field-separator=, --command "
WITH attempt_lock_key AS (
    SELECT hashtextextended('${attempt_advisory_lock_identity}', 0) AS value
), global_lock_key AS (
    SELECT hashtextextended('coolify-control-plane-expand', 0) AS value
), migration_backend AS (
    SELECT pid
    FROM pg_stat_activity
    WHERE pid <> pg_backend_pid()
      AND application_name = '${attempt_backend_application_name}'
), attempt_lock AS (
    SELECT advisory_lock.pid
    FROM pg_locks AS advisory_lock
    CROSS JOIN attempt_lock_key
    WHERE advisory_lock.locktype = 'advisory'
      AND advisory_lock.granted
      AND advisory_lock.objsubid = 1
      AND advisory_lock.classid = (((attempt_lock_key.value >> 32) & 4294967295)::oid)
      AND advisory_lock.objid = ((attempt_lock_key.value & 4294967295)::oid)
), global_lock AS (
    SELECT advisory_lock.pid
    FROM pg_locks AS advisory_lock
    CROSS JOIN global_lock_key
    WHERE advisory_lock.locktype = 'advisory'
      AND advisory_lock.granted
      AND advisory_lock.objsubid = 1
      AND advisory_lock.classid = (((global_lock_key.value >> 32) & 4294967295)::oid)
      AND advisory_lock.objid = ((global_lock_key.value & 4294967295)::oid)
)
SELECT (SELECT count(*) FROM migration_backend),
       (SELECT count(*) FROM attempt_lock),
       (SELECT count(*) FROM attempt_lock JOIN migration_backend USING (pid)),
       (SELECT count(*) FROM global_lock),
       (SELECT count(*) FROM global_lock JOIN migration_backend USING (pid))" \
        > "$migration_activity_candidate"; then
        rm -f "$migration_activity_candidate"
        migration_reconciliation_api_unavailable=1
        fail 'PostgreSQL API is unavailable during migration activity reconciliation'
    fi
    migration_activity=$(tr -d '[:space:]' < "$migration_activity_candidate")
    rm -f "$migration_activity_candidate"
    printf '%s\n' "$migration_activity" | awk -F, '
        NF == 5 && $1 ~ /^[0-9]+$/ && $2 ~ /^[0-9]+$/ && $3 ~ /^[0-9]+$/ \
            && $4 ~ /^[0-9]+$/ && $5 ~ /^[0-9]+$/ \
            && $3 <= $1 && $3 <= $2 && $5 <= $1 && $5 <= $4 { valid++ }
        END { exit(valid == 1 ? 0 : 1) }
    ' || fail 'PostgreSQL migration activity probe returned malformed backend or lock truth'
    migration_backend_count=$(printf '%s\n' "$migration_activity" | awk -F, '{ print $1 }')
    migration_advisory_lock_count=$(printf '%s\n' "$migration_activity" | awk -F, '{ print $2 }')
    migration_backend_lock_count=$(printf '%s\n' "$migration_activity" | awk -F, '{ print $3 }')
    migration_global_advisory_lock_count=$(printf '%s\n' "$migration_activity" | awk -F, '{ print $4 }')
    migration_backend_global_lock_count=$(printf '%s\n' "$migration_activity" | awk -F, '{ print $5 }')
}

wait_for_control_plane_migration_database_idle()
{
    migration_idle_attempt=0
    while [ "$migration_idle_attempt" -lt "$drain_attempts" ]; do
        observe_control_plane_migration_database_activity
        if [ "$migration_advisory_lock_count" = 0 ] \
            && [ "$migration_backend_count" = 0 ] \
            && [ "$migration_global_advisory_lock_count" = 0 ]; then
            return
        fi
        migration_idle_attempt=$((migration_idle_attempt + 1))
        sleep 1
    done
    fail 'control-plane migration lock, global lock, or exact application-name backend did not become idle'
}

database_identity_value()
{
    identity_key=$1
    identity_result=$(sed -n "s/^${identity_key}=//p" "$migration_database_identity_file")
    [ -n "$identity_result" ] || fail "live database identity artifact is missing $identity_key"
    printf '%s\n' "$identity_result"
}

capture_live_database_identity()
{
    database_identity_candidate="$operation_directory/.live-database-identity.$$"
    live_system_identifier=$(database_psql --command \
        'SELECT system_identifier::text FROM pg_control_system()' | tr -d '[:space:]')
    live_database_name=$(database_psql --command 'SELECT current_database()' | tr -d '[:space:]')
    live_database_oid=$(database_psql --command \
        'SELECT oid::text FROM pg_database WHERE datname = current_database()' | tr -d '[:space:]')
    live_database_address=$(docker inspect --format \
        "{{with index .NetworkSettings.Networks \"${control_plane_network}\"}}{{.IPAddress}}{{end}}" \
        "$database_container")
    printf '%s' "$live_system_identifier" | grep -Eq '^[0-9]+$' \
        || fail 'live database system identifier is invalid'
    [ "$live_database_name" = "$database_name" ] \
        || fail 'live database name differs from the configured migration database'
    printf '%s' "$live_database_oid" | grep -Eq '^[1-9][0-9]*$' \
        || fail 'live database OID is invalid'
    printf '%s' "$live_database_address" | grep -Eq '^[0-9a-fA-F:.]+$' \
        || fail 'live database server address is invalid'

    live_instance_marker=$(printf '%s' "${live_system_identifier}:${live_database_oid}" \
        | sha256sum | awk '{print $1}')
    live_identity_payload="system_identifier=${live_system_identifier};database=${live_database_name};server_address=${live_database_address};server_port=${database_port};instance_marker=${live_instance_marker}"
    live_identity_sha256=$(printf '%s' "$live_identity_payload" | sha256sum | awk '{print $1}')
    {
        printf 'system_identifier=%s\n' "$live_system_identifier"
        printf 'database=%s\n' "$live_database_name"
        printf 'server_address=%s\n' "$live_database_address"
        printf 'server_port=%s\n' "$database_port"
        printf 'instance_marker=%s\n' "$live_instance_marker"
        printf 'identity_sha256=%s\n' "$live_identity_sha256"
    } > "$database_identity_candidate"

    if [ -e "$migration_database_identity_file" ]; then
        [ "$(sha256_file "$migration_database_identity_file")" = "$(sha256_file "$database_identity_candidate")" ] \
            || fail 'live database identity changed during this operation'
        rm -f "$database_identity_candidate"
    else
        mv "$database_identity_candidate" "$migration_database_identity_file"
        sync
    fi
    state_live_database_identity_artifact_sha256=$(sha256_file "$migration_database_identity_file")
    state_live_database_identity_sha256=$live_identity_sha256
    state_live_database_system_identifier=$live_system_identifier
}

assert_live_database_identity()
{
    assert_regular_file "$migration_database_identity_file" 'persisted live database identity artifact'
    [ "$(sha256_file "$migration_database_identity_file")" = "$state_live_database_identity_artifact_sha256" ] \
        || fail 'persisted live database identity artifact changed'
    [ "$(database_identity_value identity_sha256)" = "$state_live_database_identity_sha256" ] \
        && [ "$(database_identity_value system_identifier)" = "$state_live_database_system_identifier" ] \
        || fail 'persisted live database identity state differs from its artifact'
    capture_live_database_identity
}

export_live_database_identity()
{
    assert_live_database_identity
    CONTROL_PLANE_EXPECTED_DATABASE_IDENTITY_SHA256=$state_live_database_identity_sha256
    CONTROL_PLANE_LIVE_DATABASE_SYSTEM_IDENTIFIER=$state_live_database_system_identifier
    export CONTROL_PLANE_EXPECTED_DATABASE_IDENTITY_SHA256
    export CONTROL_PLANE_LIVE_DATABASE_SYSTEM_IDENTIFIER
}

candidate_migration_names()
{
    awk '
        NF != 2 || $1 !~ /^[0-9a-f]{64}$/ || $2 !~ /^[-A-Za-z0-9_.\/]+$/ { exit 1 }
        $2 ~ /^database\/migrations\/[A-Za-z0-9_]+\.php$/ {
            migration = $2
            sub(/^database\/migrations\//, "", migration)
            sub(/\.php$/, "", migration)
            if (seen[migration]++) { exit 1 }
            print migration
        }
    ' "$migration_manifest_artifact_file"
}

candidate_control_plane_migration_names()
{
    awk '
        NF != 2 || $1 !~ /^[0-9a-f]{64}$/ || $2 !~ /^[-A-Za-z0-9_.\/]+$/ { exit 1 }
        $2 ~ /^database\/migrations\/2026_07_12_/ {
            migration = $2
            sub(/^database\/migrations\//, "", migration)
            sub(/\.php$/, "", migration)
            if (migration !~ /^2026_07_12_[0-9][0-9][0-9][0-9][0-9][0-9]_[a-z0-9_]+$/ \
                || seen[migration]++ \
                || (previous != "" && migration <= previous)) {
                invalid = 1
                next
            }
            previous = migration
            print migration
            count++
        }
        END { exit(invalid || count == 0 ? 1 : 0) }
    ' "$migration_manifest_artifact_file"
}

validate_migration_ledger_inventory()
{
    inventory_ledger_file=$1
    assert_regular_file "$inventory_ledger_file" 'migration ledger inventory snapshot'
    assert_immutable_baseline_surplus_artifact
    migration_inventory_candidate="$operation_directory/.candidate-migration-inventory.$$"
    candidate_migration_names > "$migration_inventory_candidate" \
        || fail 'candidate migration manifest contains an invalid or duplicate migration filename'
    awk -F, \
        -v candidate_file="$migration_inventory_candidate" \
        -v surplus_file="$migration_baseline_surplus_file" \
        -v ledger_file="$inventory_ledger_file" '
        FILENAME == candidate_file {
            if (NF != 1 || $1 == "" || candidate[$1]++) { exit 1 }
            next
        }
        FILENAME == surplus_file {
            if (NF != 1 || $1 == "" || surplus[$1]++ || ($1 in candidate)) { exit 1 }
            next
        }
        FILENAME == ledger_file {
            if (NF != 2 || $1 == "" || $2 !~ /^[1-9][0-9]*$/ || ledger[$1]++) { exit 1 }
            if (!($1 in candidate)) {
                if (!($1 in surplus)) { exit 1 }
                observed_surplus[$1]++
            }
            next
        }
        END {
            for (migration in surplus) {
                if (observed_surplus[migration] != 1) { exit 1 }
            }
        }
    ' "$migration_inventory_candidate" "$migration_baseline_surplus_file" "$inventory_ledger_file" \
        || fail 'migration ledger surplus differs from the exact immutable three-row baseline inventory'
    rm -f "$migration_inventory_candidate"
}

migration_ledger_snapshot()
{
    snapshot_destination=$1
    database_psql --command \
        "COPY (SELECT migration, batch FROM migrations ORDER BY migration) TO STDOUT WITH (FORMAT csv)" \
        > "$snapshot_destination"
}

migration_existing_relfilenode_snapshot()
{
    snapshot_destination=$1
    database_psql --command "
COPY (
    SELECT relname, relfilenode
    FROM pg_class
    WHERE oid IN (
        'application_deployment_queues'::regclass,
        'application_settings'::regclass
    )
    ORDER BY relname
) TO STDOUT WITH (FORMAT csv)" > "$snapshot_destination"
}

migration_all_relfilenode_snapshot()
{
    snapshot_destination=$1
    database_psql --command "
COPY (
    SELECT relname, relfilenode
    FROM pg_class
    WHERE relnamespace = current_schema()::regnamespace
      AND relname IN (
          'application_blue_green_deactivations',
          'application_blue_green_deployments',
          'application_deployment_queues',
          'application_settings'
      )
    ORDER BY relname
) TO STDOUT WITH (FORMAT csv)" > "$snapshot_destination"
}

migration_row_count_snapshot()
{
    snapshot_destination=$1
    database_psql --command "
COPY (
    SELECT 'application_deployment_queues', count(*) FROM application_deployment_queues
    UNION ALL
    SELECT 'application_settings', count(*) FROM application_settings
    ORDER BY 1
) TO STDOUT WITH (FORMAT csv)" > "$snapshot_destination"
}

migration_schema_snapshot()
{
    snapshot_destination=$1
    database_psql --command "
COPY (
    SELECT 'column' AS object_type, relation.relname AS relation_name,
           attribute.attnum::text AS object_position,
           concat_ws('|', attribute.attname, format_type(attribute.atttypid, attribute.atttypmod),
               attribute.attnotnull::text, attribute.attidentity::text,
               attribute.attgenerated::text,
               COALESCE(pg_get_expr(attribute_default.adbin, attribute_default.adrelid), '')) AS definition
    FROM pg_attribute AS attribute
    JOIN pg_class AS relation ON relation.oid = attribute.attrelid
    JOIN pg_namespace AS namespace ON namespace.oid = relation.relnamespace
    LEFT JOIN pg_attrdef AS attribute_default
      ON attribute_default.adrelid = attribute.attrelid
     AND attribute_default.adnum = attribute.attnum
    WHERE namespace.nspname = current_schema()
      AND relation.relname IN (
          'application_settings', 'application_deployment_queues',
          'application_blue_green_deployments', 'application_blue_green_deactivations'
      )
      AND attribute.attnum > 0 AND NOT attribute.attisdropped
    UNION ALL
    SELECT 'constraint', relation.relname, constraint_definition.oid::text,
           concat_ws('|', constraint_definition.conname, constraint_definition.contype,
               constraint_definition.condeferrable::text, constraint_definition.condeferred::text,
               constraint_definition.convalidated::text,
               pg_get_constraintdef(constraint_definition.oid, true))
    FROM pg_constraint AS constraint_definition
    JOIN pg_class AS relation ON relation.oid = constraint_definition.conrelid
    JOIN pg_namespace AS namespace ON namespace.oid = relation.relnamespace
    WHERE namespace.nspname = current_schema()
      AND relation.relname IN (
          'application_settings', 'application_deployment_queues',
          'application_blue_green_deployments', 'application_blue_green_deactivations'
      )
    UNION ALL
    SELECT 'index', table_relation.relname, index_relation.oid::text,
           concat_ws('|', index_relation.relname, index_definition.indisprimary::text,
               index_definition.indisunique::text, index_definition.indisvalid::text,
               index_definition.indisready::text, pg_get_indexdef(index_relation.oid))
    FROM pg_index AS index_definition
    JOIN pg_class AS table_relation ON table_relation.oid = index_definition.indrelid
    JOIN pg_class AS index_relation ON index_relation.oid = index_definition.indexrelid
    JOIN pg_namespace AS namespace ON namespace.oid = table_relation.relnamespace
    WHERE namespace.nspname = current_schema()
      AND table_relation.relname IN (
          'application_settings', 'application_deployment_queues',
          'application_blue_green_deployments', 'application_blue_green_deactivations'
      )
    ORDER BY 1, 2, 3, 4
) TO STDOUT WITH (FORMAT csv)" > "$snapshot_destination"
}

prepare_live_expand_verification()
{
    capture_live_database_identity
    migration_names_candidate="$operation_directory/.candidate-migration-names.$$"
    authorized_candidate="$operation_directory/.authorized-migration-names.$$"
    ledger_candidate="$operation_directory/.migration-ledger-before.$$"
    relfilenode_candidate="$operation_directory/.migration-relfilenode-before.$$"
    row_counts_candidate="$operation_directory/.migration-row-counts-before.$$"
    schema_candidate="$operation_directory/.migration-schema-before.$$"
    pending_candidate="$operation_directory/.pending-migrations.$$"
    candidate_migration_names > "$migration_names_candidate" \
        || fail 'candidate migration manifest contains an invalid or duplicate migration filename'
    candidate_control_plane_migration_names > "$authorized_candidate" \
        || fail 'candidate migration manifest contains an invalid or empty control-plane migration inventory'
    assert_control_plane_migration_inventory_fingerprint \
        "$authorized_candidate" "$migration_inventory_fingerprint_file"

    migration_ledger_snapshot "$ledger_candidate"
    validate_migration_ledger_inventory "$ledger_candidate"
    migration_existing_relfilenode_snapshot "$relfilenode_candidate"
    migration_row_count_snapshot "$row_counts_candidate"
    migration_schema_snapshot "$schema_candidate"
    [ "$(wc -l < "$relfilenode_candidate" | tr -d '[:space:]')" = 2 ] \
        || fail 'pre-migration relfilenode snapshot did not contain both existing tables'
    awk -F, '
        $1 == "application_deployment_queues" && $2 ~ /^[1-9][0-9]*$/ { queue++ }
        $1 == "application_settings" && $2 ~ /^[1-9][0-9]*$/ { settings++ }
        END { exit(queue == 1 && settings == 1 ? 0 : 1) }
    ' "$relfilenode_candidate" \
        || fail 'pre-migration relfilenode snapshot was malformed'
    awk -F, '
        $1 == "application_deployment_queues" && $2 ~ /^[0-9]+$/ { queue++ }
        $1 == "application_settings" && $2 ~ /^[0-9]+$/ { settings++ }
        END { exit(queue == 1 && settings == 1 ? 0 : 1) }
    ' "$row_counts_candidate" \
        || fail 'pre-migration row-count snapshot was malformed'
    : > "$pending_candidate"
    while IFS= read -r migration_name; do
        if ! awk -F, -v migration="$migration_name" \
            '$1 == migration && $2 ~ /^[1-9][0-9]*$/ { found++ } END { exit(found == 1 ? 0 : 1) }' \
            "$ledger_candidate"; then
            printf '%s\n' "$migration_name" >> "$pending_candidate"
        fi
    done < "$migration_names_candidate"

    state_migration_expected_batch=$(database_psql --command \
        'SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations' | tr -d '[:space:]')
    printf '%s' "$state_migration_expected_batch" | grep -Eq '^[1-9][0-9]*$' \
        || fail 'migration ledger did not provide a valid next batch'

    verification_artifact_presence="$(path_presence "$migration_ledger_before_file"):$(path_presence "$migration_relfilenode_before_file"):$(path_presence "$migration_row_counts_before_file"):$(path_presence "$migration_schema_before_file"):$(path_presence "$migration_pending_file")"
    case "$verification_artifact_presence" in
        absent:absent:absent:absent:absent)
            mv "$ledger_candidate" "$migration_ledger_before_file"
            mv "$relfilenode_candidate" "$migration_relfilenode_before_file"
            mv "$row_counts_candidate" "$migration_row_counts_before_file"
            mv "$schema_candidate" "$migration_schema_before_file"
            mv "$pending_candidate" "$migration_pending_file"
            ;;
        present:present:present:present:present)
            [ "$(sha256_file "$ledger_candidate")" = "$(sha256_file "$migration_ledger_before_file")" ] \
                && [ "$(sha256_file "$relfilenode_candidate")" = "$(sha256_file "$migration_relfilenode_before_file")" ] \
                && [ "$(sha256_file "$row_counts_candidate")" = "$(sha256_file "$migration_row_counts_before_file")" ] \
                && [ "$(sha256_file "$schema_candidate")" = "$(sha256_file "$migration_schema_before_file")" ] \
                && [ "$(sha256_file "$pending_candidate")" = "$(sha256_file "$migration_pending_file")" ] \
                || fail 'pre-migration verification inputs changed after artifact persistence'
            rm -f "$ledger_candidate" "$relfilenode_candidate" "$row_counts_candidate" \
                "$schema_candidate" "$pending_candidate"
            ;;
        *)
            fail 'pre-migration verification artifacts are only partially persisted'
            ;;
    esac
    sync
    state_migration_ledger_before_sha256=$(sha256_file "$migration_ledger_before_file")
    state_migration_relfilenode_before_sha256=$(sha256_file "$migration_relfilenode_before_file")
    state_migration_row_counts_before_sha256=$(sha256_file "$migration_row_counts_before_file")
    state_migration_schema_before_sha256=$(sha256_file "$migration_schema_before_file")
    state_migration_pending_sha256=$(sha256_file "$migration_pending_file")
    [ "$state_migration_pending_sha256" = "$(sha256_file "$authorized_candidate")" ] \
        || fail 'candidate ledger gap is not exactly the canonical control-plane migration inventory'
    authorized_pending_count=$(wc -l < "$authorized_candidate" | tr -d '[:space:]')
    grep -F -x -q "authorized-pending-count=${authorized_pending_count}" "$migration_compatibility_artifact_file" \
        || fail 'migration compatibility artifact does not bind the authorized pending count'
    grep -F -x -q "authorized-pending-sha256=${state_migration_pending_sha256}" \
        "$migration_compatibility_artifact_file" \
        || fail 'migration compatibility artifact does not bind the authorized pending hash'
    while IFS= read -r migration_name; do
        grep -F -x -q "authorized-pending-migration=${migration_name}" \
            "$migration_compatibility_artifact_file" \
            || fail "migration compatibility artifact does not bind: $migration_name"
    done < "$authorized_candidate"
    state_migration_verification_sha256=none
    cleanup_migration_candidates
}

assert_live_expand_verification_inputs()
{
    [ "$state_live_database_identity_artifact_sha256" != none ] \
        && [ "$state_live_database_identity_sha256" != none ] \
        && [ "$state_live_database_system_identifier" != none ] \
        || fail 'live database identity was not persisted'
    assert_live_database_identity
    assert_regular_file "$migration_ledger_before_file" 'pre-migration ledger artifact'
    assert_regular_file "$migration_relfilenode_before_file" 'pre-migration relfilenode artifact'
    assert_regular_file "$migration_row_counts_before_file" 'pre-migration row-count artifact'
    assert_regular_file "$migration_schema_before_file" 'pre-migration schema artifact'
    assert_regular_file "$migration_pending_file" 'pending migration artifact'
    [ "$(sha256_file "$migration_ledger_before_file")" = "$state_migration_ledger_before_sha256" ] \
        || fail 'pre-migration ledger artifact changed'
    [ "$(sha256_file "$migration_relfilenode_before_file")" = "$state_migration_relfilenode_before_sha256" ] \
        || fail 'pre-migration relfilenode artifact changed'
    [ "$(sha256_file "$migration_row_counts_before_file")" = "$state_migration_row_counts_before_sha256" ] \
        || fail 'pre-migration row-count artifact changed'
    [ "$(sha256_file "$migration_schema_before_file")" = "$state_migration_schema_before_sha256" ] \
        || fail 'pre-migration schema artifact changed'
    [ "$(sha256_file "$migration_pending_file")" = "$state_migration_pending_sha256" ] \
        || fail 'pending migration artifact changed'
    printf '%s' "$state_migration_expected_batch" | grep -Eq '^[1-9][0-9]*$' \
        || fail 'persisted expected migration batch is invalid'
}

verify_blue_green_schema()
{
    database_psql --command "
SELECT 'deployments_columns=' || ((
    SELECT array_agg(column_name::text ORDER BY ordinal_position) = ARRAY[
        'id', 'application_id', 'standalone_docker_id', 'active_color', 'pending_color',
        'blue_deployment_uuid', 'green_deployment_uuid', 'pending_deployment_uuid',
        'legacy_container_name', 'operation_deployment_uuid', 'operation_previous_active_color',
        'operation_previous_deployment_uuid', 'operation_previous_routing_revision',
        'operation_previous_container_name', 'operation_previous_container_id',
        'operation_candidate_container_name', 'operation_candidate_container_id',
        'operation_rollback_managed_filename', 'operation_routing_mutated_at',
        'operation_legacy_routing_snapshot_version', 'operation_legacy_routing_snapshot',
        'operation_legacy_routing_snapshot_sha256', 'phase',
        'routing_revision', 'created_at', 'updated_at', 'deactivation_operation_id',
        'deactivation_started_at'
    ]::text[]
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'application_blue_green_deployments'
)::text)
UNION ALL
SELECT 'deactivations_columns=' || ((
    SELECT array_agg(column_name::text ORDER BY ordinal_position) = ARRAY[
        'id', 'application_id', 'standalone_docker_id', 'operation_id', 'started_at',
        'queue_cutoff_id', 'phase', 'completed_at', 'created_at', 'updated_at',
        'proxy_snapshot'
    ]::text[]
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'application_blue_green_deactivations'
)::text)
UNION ALL
SELECT 'application_setting=' || ((
    SELECT count(*) = 1
      AND bool_and(data_type = 'boolean' AND is_nullable = 'NO' AND column_default IN ('false', '0'))
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'application_settings'
      AND column_name = 'is_blue_green_deployment_enabled'
)::text)
UNION ALL
SELECT 'queue_provenance_columns=' || ((
    SELECT array_agg(column_name::text ORDER BY ordinal_position) = ARRAY[
        'blue_green_color', 'blue_green_phase', 'blue_green_routing_revision',
        'blue_green_previous_container_id', 'blue_green_candidate_container_id',
        'blue_green_rollback_managed_filename', 'blue_green_routing_mutated_at'
    ]::text[]
      AND bool_and(is_nullable = 'YES')
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'application_deployment_queues'
      AND column_name IN (
        'blue_green_color', 'blue_green_phase', 'blue_green_routing_revision',
        'blue_green_previous_container_id', 'blue_green_candidate_container_id',
        'blue_green_rollback_managed_filename', 'blue_green_routing_mutated_at'
      )
)::text)
UNION ALL
SELECT 'deployment_snapshot_columns=' || ((
    SELECT count(*) = 3 AND bool_and(
        is_nullable = 'YES' AND (
            (column_name = 'operation_legacy_routing_snapshot_version' AND data_type = 'smallint')
            OR (column_name = 'operation_legacy_routing_snapshot' AND data_type = 'text')
            OR (column_name = 'operation_legacy_routing_snapshot_sha256'
                AND data_type = 'character varying' AND character_maximum_length = 64)
        )
    )
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'application_blue_green_deployments'
      AND column_name IN (
        'operation_legacy_routing_snapshot_version', 'operation_legacy_routing_snapshot',
        'operation_legacy_routing_snapshot_sha256'
      )
)::text)
UNION ALL
SELECT 'deactivation_state_columns=' || ((
    SELECT count(*) = 2 AND bool_and(
        (column_name = 'phase' AND data_type = 'character varying' AND is_nullable = 'NO'
            AND column_default LIKE '%deactivating%')
        OR (column_name = 'completed_at' AND data_type = 'timestamp without time zone'
            AND is_nullable = 'YES')
    )
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'application_blue_green_deactivations'
      AND column_name IN ('phase', 'completed_at')
)::text)
UNION ALL
SELECT 'deployments_constraints=' || ((
    SELECT count(*) = 4
      AND bool_and(
        (conname = 'application_blue_green_deployments_pkey' AND contype = 'p')
        OR (conname = 'app_blue_green_destination_unique' AND contype = 'u')
        OR (contype = 'f' AND confdeltype = 'c' AND confrelid = 'applications'::regclass
            AND conkey = ARRAY[(SELECT attnum FROM pg_attribute
                WHERE attrelid = 'application_blue_green_deployments'::regclass
                  AND attname = 'application_id')]::smallint[])
        OR (contype = 'f' AND confdeltype = 'c' AND confrelid = 'standalone_dockers'::regclass
            AND conkey = ARRAY[(SELECT attnum FROM pg_attribute
                WHERE attrelid = 'application_blue_green_deployments'::regclass
                  AND attname = 'standalone_docker_id')]::smallint[])
      )
    FROM pg_constraint
    WHERE conrelid = 'application_blue_green_deployments'::regclass
)::text)
UNION ALL
SELECT 'deactivations_constraints=' || ((
    SELECT count(*) = 4
      AND bool_and(
        (conname = 'application_blue_green_deactivations_pkey' AND contype = 'p')
        OR (conname = 'app_blue_green_deactivation_unique' AND contype = 'u')
        OR (contype = 'f' AND confdeltype = 'c' AND confrelid = 'applications'::regclass
            AND conkey = ARRAY[(SELECT attnum FROM pg_attribute
                WHERE attrelid = 'application_blue_green_deactivations'::regclass
                  AND attname = 'application_id')]::smallint[])
        OR (contype = 'f' AND confdeltype = 'c' AND confrelid = 'standalone_dockers'::regclass
            AND conkey = ARRAY[(SELECT attnum FROM pg_attribute
                WHERE attrelid = 'application_blue_green_deactivations'::regclass
                  AND attname = 'standalone_docker_id')]::smallint[])
      )
    FROM pg_constraint
    WHERE conrelid = 'application_blue_green_deactivations'::regclass
)::text)
UNION ALL
SELECT 'standalone_docker_indexes=' || ((
    to_regclass('app_blue_green_standalone_docker_index') IS NOT NULL
    AND to_regclass('app_blue_green_deactivation_docker_index') IS NOT NULL
    AND to_regclass('app_blue_green_deactivation_phase_index') IS NOT NULL
    AND pg_get_indexdef(to_regclass('app_blue_green_standalone_docker_index'))
        LIKE '%application_blue_green_deployments USING btree (standalone_docker_id)'
    AND pg_get_indexdef(to_regclass('app_blue_green_deactivation_docker_index'))
        LIKE '%application_blue_green_deactivations USING btree (standalone_docker_id)'
    AND pg_get_indexdef(to_regclass('app_blue_green_deactivation_phase_index'))
        LIKE '%application_blue_green_deactivations USING btree (phase, started_at)'
)::text)
ORDER BY 1"
}

verify_live_expand_migration()
{
    assert_live_expand_verification_inputs
    migration_names_candidate="$operation_directory/.candidate-migration-names-verify.$$"
    ledger_after_candidate="$operation_directory/.migration-ledger-after.$$"
    relfilenode_after_candidate="$operation_directory/.migration-relfilenode-after.$$"
    row_counts_after_candidate="$operation_directory/.migration-row-counts-after.$$"
    schema_guard_candidate="$operation_directory/.migration-schema-guard.$$"
    verification_candidate="$operation_directory/.migration-post-verification.$$"
    candidate_migration_names > "$migration_names_candidate" \
        || fail 'candidate migration names changed before post-verification'
    migration_ledger_snapshot "$ledger_after_candidate"
    migration_all_relfilenode_snapshot "$relfilenode_after_candidate"
    migration_row_count_snapshot "$row_counts_after_candidate"
    validate_controlled_migration_ledger "$ledger_after_candidate" applied
    awk -F, '
        $1 == "application_deployment_queues" && $2 ~ /^[0-9]+$/ { queue++ }
        $1 == "application_settings" && $2 ~ /^[0-9]+$/ { settings++ }
        END { exit(queue == 1 && settings == 1 ? 0 : 1) }
    ' "$row_counts_after_candidate" \
        || fail 'post-migration row-count observation was malformed'

    while IFS=, read -r relation_name relation_relfilenode; do
        awk -F, -v relation="$relation_name" -v relfilenode="$relation_relfilenode" \
            '$1 == relation && $2 == relfilenode { found++ } END { exit(found == 1 ? 0 : 1) }' \
            "$relfilenode_after_candidate" \
            || fail "existing relation was rewritten during additive migration: $relation_name"
    done < "$migration_relfilenode_before_file"
    awk -F, '
        $1 == "application_blue_green_deactivations" && $2 ~ /^[1-9][0-9]*$/ { deactivations++ }
        $1 == "application_blue_green_deployments" && $2 ~ /^[1-9][0-9]*$/ { deployments++ }
        END { exit(deactivations == 1 && deployments == 1 ? 0 : 1) }
    ' "$relfilenode_after_candidate" \
        || fail 'new blue-green tables did not have physical relation identities'

    while IFS= read -r migration_name; do
        awk -F, -v migration="$migration_name" \
            '$1 == migration && $2 ~ /^[1-9][0-9]*$/ { found++ } END { exit(found == 1 ? 0 : 1) }' \
            "$ledger_after_candidate" \
            || fail "candidate migration is absent or duplicated in the ledger: $migration_name"
    done < "$migration_names_candidate"

    while IFS= read -r migration_name; do
        awk -F, -v migration="$migration_name" \
            '$1 == migration && $2 ~ /^[1-9][0-9]*$/ { found++ } END { exit(found == 1 ? 0 : 1) }' \
            "$ledger_after_candidate" \
            || fail "pending migration was not recorded exactly once by a controlled attempt: $migration_name"
    done < "$migration_pending_file"

    verify_blue_green_schema > "$schema_guard_candidate"
    awk -F= '
        NF == 2 { observed++; if ($2 == "true") passed++ }
        END { exit(observed == 9 && passed == 9 ? 0 : 1) }
    ' "$schema_guard_candidate" \
        || fail 'blue-green post-migration schema or constraint verification failed'

    {
        printf 'initial_expected_batch=%s\n' "$state_migration_expected_batch"
        printf 'successful_attempt_batch=%s\n' "$attempt_expected_batch"
        printf 'attempt=%s\n' "$attempt_number"
        printf 'attempt_state_sha256=%s\n' "$(sha256_file "$migration_attempt_state_file")"
        printf 'live_database_identity_sha256=%s\n' "$state_live_database_identity_sha256"
        printf 'ledger_before_sha256=%s\n' "$state_migration_ledger_before_sha256"
        printf 'relfilenode_before_sha256=%s\n' "$state_migration_relfilenode_before_sha256"
        printf 'row_counts_before_sha256=%s\n' "$state_migration_row_counts_before_sha256"
        printf 'row_counts_after_sha256=%s\n' "$(sha256_file "$row_counts_after_candidate")"
        sed 's/^/row_counts_after=/' "$row_counts_after_candidate"
        printf 'pending_sha256=%s\n' "$state_migration_pending_sha256"
        sed 's/^/relfilenode_after=/' "$relfilenode_after_candidate"
        cat "$schema_guard_candidate"
    } > "$verification_candidate"
    sync -f "$verification_candidate"
    mv -f "$verification_candidate" "$migration_verification_file"
    sync -f "$migration_artifact_directory"
    state_migration_verification_sha256=$(sha256_file "$migration_verification_file")
    cleanup_migration_candidates
}

assert_live_expand_retry_inputs()
{
    require_live_expand_migration_configuration
    assert_migration_compatibility
    assert_persisted_live_expand_artifacts
    [ "$(sha256_file "$migration_compatibility_file")" = "$state_migration_compatibility_sha256" ] \
        || fail 'retry migration compatibility input differs from the persisted artifact'
    assert_live_expand_verification_inputs
}

set_current_migration_attempt_paths()
{
    current_attempt_number=${1:-$state_migration_attempt}
    migration_attempt_state_file="$migration_attempts_directory/attempt-${current_attempt_number}.state"
    migration_attempt_log_file="$migration_attempts_directory/attempt-${current_attempt_number}.log"
}

validate_migration_attempt_history()
{
    operation_attempt_pointer=$state_migration_attempt
    operation_attempt_state_sha256=$state_migration_attempt_state_sha256
    latest_attempt_number=0
    attempt_state_count=0

    if [ -d "$migration_attempts_directory" ]; then
        for attempt_state_path in "$migration_attempts_directory"/attempt-*.state; do
            [ -e "$attempt_state_path" ] || break
            attempt_state_name=${attempt_state_path##*/}
            attempt_state_index=${attempt_state_name#attempt-}
            attempt_state_index=${attempt_state_index%.state}
            printf '%s' "$attempt_state_name" | grep -Eq '^attempt-[1-9][0-9]*\.state$' \
                || fail 'migration attempt state filename is malformed'
            attempt_state_count=$((attempt_state_count + 1))
            if [ "$attempt_state_index" -gt "$latest_attempt_number" ]; then
                latest_attempt_number=$attempt_state_index
            fi
        done
    fi

    [ "$attempt_state_count" = "$latest_attempt_number" ] \
        || fail 'migration attempt state history is noncontiguous'
    if [ "$latest_attempt_number" = 0 ]; then
        [ "$operation_attempt_pointer" = 0 ] \
            && [ "$operation_attempt_state_sha256" = none ] \
            || fail 'operation state points to an absent migration attempt'
        return
    fi

    history_attempt=1
    while [ "$history_attempt" -le "$latest_attempt_number" ]; do
        load_migration_attempt_state "$history_attempt"
        if [ "$history_attempt" -lt "$latest_attempt_number" ]; then
            [ "$attempt_status" = failed ] \
                || fail 'only a failed migration attempt may have a successor'
        fi
        history_attempt=$((history_attempt + 1))
    done
    if [ "$operation_attempt_pointer" = "$latest_attempt_number" ]; then
        :
    elif [ "$latest_attempt_number" -eq $((operation_attempt_pointer + 1)) ]; then
        [ "$attempt_status" = planned ] \
            || fail 'only a crash-persisted planned attempt may lead operation state by one'
    else
        fail 'operation state and authoritative migration attempt history diverged'
    fi
    state_migration_attempt=$latest_attempt_number
}

assert_migration_attempt_inputs()
{
    [ "$attempt_expected_batch" = "$state_migration_expected_batch" ] \
        && [ "$attempt_ledger_before_sha256" = "$state_migration_ledger_before_sha256" ] \
        && [ "$attempt_pending_sha256" = "$state_migration_pending_sha256" ] \
        && [ "$attempt_image_id" = "$state_migration_image_id" ] \
        && [ "$attempt_image_digest" = "$state_migration_image_digest" ] \
        && [ "$attempt_compatibility_sha256" = "$state_migration_compatibility_sha256" ] \
        && [ "$attempt_manifest_sha256" = "$state_migration_manifest_sha256" ] \
        && [ "$attempt_database_identity_sha256" = "$state_live_database_identity_sha256" ] \
        && [ "$attempt_lock_timeout" = "$state_migration_lock_timeout" ] \
        && [ "$attempt_statement_timeout" = "$state_migration_statement_timeout" ] \
        || fail 'migration attempt inputs differ from the persisted operation artifacts'
}

classify_live_expand_database_outcome()
{
    classification_ledger_candidate="$operation_directory/.migration-classification-ledger.$$"
    classification_schema_candidate="$operation_directory/.migration-classification-schema.$$"
    classification_relfilenode_candidate="$operation_directory/.migration-classification-relfilenode.$$"
    classification_schema_guard_candidate="$operation_directory/.migration-classification-guard.$$"
    if ! migration_ledger_snapshot "$classification_ledger_candidate"; then
        migration_reconciliation_api_unavailable=1
        fail 'PostgreSQL API is unavailable while classifying the migration ledger'
    fi
    migration_classification_ledger_sha256=$(sha256_file "$classification_ledger_candidate")

    if migration_ledger_matches_controlled_outcome "$classification_ledger_candidate" unchanged; then
        if ! migration_schema_snapshot "$classification_schema_candidate" \
            || ! migration_existing_relfilenode_snapshot "$classification_relfilenode_candidate"; then
            migration_reconciliation_api_unavailable=1
            fail 'PostgreSQL API is unavailable while classifying the unchanged schema'
        fi
        if [ "$(sha256_file "$classification_schema_candidate")" = \
                "$state_migration_schema_before_sha256" ] \
            && [ "$(sha256_file "$classification_relfilenode_candidate")" = \
                "$state_migration_relfilenode_before_sha256" ]; then
            migration_database_outcome=unchanged
        else
            migration_database_outcome=drift
        fi
    elif migration_ledger_matches_controlled_outcome "$classification_ledger_candidate" applied; then
        if ! migration_all_relfilenode_snapshot "$classification_relfilenode_candidate"; then
            migration_reconciliation_api_unavailable=1
            fail 'PostgreSQL API is unavailable while classifying applied relation identities'
        fi
        migration_applied_relations_exact=0
        if awk -F, \
            -v pre_file="$migration_relfilenode_before_file" \
            -v current_file="$classification_relfilenode_candidate" '
            FILENAME == pre_file {
                if (NF != 2 || $2 !~ /^[1-9][0-9]*$/ || pre[$1]++) { exit 1 }
                pre_id[$1] = $2
                pre_count++
                next
            }
            FILENAME == current_file {
                if (NF != 2 || $2 !~ /^[1-9][0-9]*$/ || current[$1]++) { exit 1 }
                current_count++
                if ($1 in pre_id) {
                    if ($2 != pre_id[$1]) { exit 1 }
                    preserved[$1] = 1
                    preserved_count++
                } else if ($1 == "application_blue_green_deactivations" ||
                           $1 == "application_blue_green_deployments") {
                    created[$1] = 1
                    created_count++
                } else { exit 1 }
                next
            }
            END {
                exit(pre_count == 2 && preserved_count == 2 &&
                     created_count == 2 && current_count == 4 ? 0 : 1)
            }
        ' "$migration_relfilenode_before_file" "$classification_relfilenode_candidate"; then
            migration_applied_relations_exact=1
        fi
        if [ "$migration_applied_relations_exact" = 1 ]; then
            if ! verify_blue_green_schema > "$classification_schema_guard_candidate"; then
                migration_reconciliation_api_unavailable=1
                fail 'PostgreSQL API is unavailable while classifying the applied schema'
            fi
            if awk -F= '
                NF == 2 { observed++; if ($2 == "true") passed++ }
                END { exit(observed == 9 && passed == 9 ? 0 : 1) }
            ' "$classification_schema_guard_candidate"; then
                migration_database_outcome=applied
            else
                migration_database_outcome=drift
            fi
        else
            migration_database_outcome=drift
        fi
    else
        migration_database_outcome=drift
    fi
    rm -f "$classification_ledger_candidate" "$classification_schema_candidate" \
        "$classification_relfilenode_candidate" "$classification_schema_guard_candidate"
}

begin_live_expand_migration_attempt()
{
    mkdir -p "$migration_attempts_directory"
    validate_migration_attempt_history
    if [ "$latest_attempt_number" -gt 0 ]; then
        [ "$attempt_status" = failed ] \
            || fail 'a new migration attempt requires the previous authoritative attempt to be failed'
    fi
    next_migration_attempt=$((state_migration_attempt + 1))
    attempt_number=$next_migration_attempt
    derive_live_expand_migration_attempt_identity
    observe_control_plane_migration_database_activity
    [ "$migration_advisory_lock_count" = 0 ] && [ "$migration_backend_count" = 0 ] \
        && [ "$migration_global_advisory_lock_count" = 0 ] \
        || fail 'a new migration attempt is forbidden while its database execution or global lock is active'
    classify_live_expand_database_outcome
    [ "$migration_database_outcome" = unchanged ] \
        || fail 'a new migration attempt requires the exact unchanged pre-gap database state'

    set_current_migration_attempt_paths "$next_migration_attempt"
    migration_runner_key=$(printf '%s:%s\n' "$operation_id" "$next_migration_attempt" \
        | sha256sum | awk '{print substr($1, 1, 24)}')
    attempt_expected_batch=$state_migration_expected_batch
    attempt_ledger_before_sha256=$state_migration_ledger_before_sha256
    attempt_pending_sha256=$state_migration_pending_sha256
    attempt_image_id=$state_migration_image_id
    attempt_image_digest=$state_migration_image_digest
    attempt_compatibility_sha256=$state_migration_compatibility_sha256
    attempt_manifest_sha256=$state_migration_manifest_sha256
    attempt_database_identity_sha256=$state_live_database_identity_sha256
    attempt_lock_timeout=$state_migration_lock_timeout
    attempt_statement_timeout=$state_migration_statement_timeout
    attempt_runner_name="coolify-cp-migrate-${migration_runner_key}-a${attempt_number}"
    attempt_runner_id=none
    attempt_runner_exit_code=none
    attempt_runner_log_sha256=none
    attempt_verification_sha256=none
    attempt_database_outcome=unknown
    docker_container_presence "$attempt_runner_name"
    [ "$migration_runner_presence" = absent ] \
        || fail 'next deterministic live migration runner already exists'
    [ ! -e "$migration_attempt_state_file" ] && [ ! -e "$migration_attempt_log_file" ] \
        || fail 'next live migration attempt artifacts already exist'
    write_migration_attempt_state planned
    state_migration_attempt=$attempt_number
    state_migration_status=running
    state_phase=live-expand-migrations-planned
    write_state "$state_phase"
    test_crash after-live-expand-migration-planned
}

assert_legacy_blue_scheduler_stopped()
{
    if is_test_mode; then
        [ "$(docker exec "$blue_container" /usr/local/bin/control-plane-lab-service \
            service-state scheduler-worker)" = down ] \
            || fail 'legacy scheduler remained supervised-up during live migration quiescence'
        return
    fi

    docker exec --user 0 "$blue_container" /command/s6-svstat -d \
        /run/service/scheduler-worker >/dev/null \
        || fail 'legacy scheduler remained supervised-up during live migration quiescence'
}

assert_live_expand_background_quiesced()
{
    [ "$state_phase" = live-expand-migrations-quiesced ] \
        || fail 'live-expand migration background quiescence is not durably recorded'
    runtime_fence_call verify
    assert_blue_state
    assert_legacy_blue_scheduler_stopped
    assert_horizon_paused "$blue_container"
    assert_no_active_old_work "$blue_container" \
        || fail 'old reserved/globally-active work reappeared before additive DDL'
    sleep "$drain_stable_seconds"
    assert_no_active_old_work "$blue_container" \
        || fail 'old reserved/globally-active work was not zero on the retry-stable proof'
    runtime_fence_call verify
}

quiesce_legacy_blue_for_live_expand()
{
    case "$state_phase" in
        live-expand-migrations-planned)
            runtime_fence_call verify
            assert_blue_state
            state_phase=live-expand-migrations-quiescing
            write_state "$state_phase"
            test_crash after-live-expand-background-quiescing
            ;;
    esac

    case "$state_phase" in
        live-expand-migrations-quiescing)
            runtime_fence_call verify
            assert_blue_state
            control_s6_service_down "$blue_container" scheduler-worker
            assert_legacy_blue_scheduler_stopped
            state_phase=live-expand-migrations-scheduler-stopped
            write_state "$state_phase"
            test_crash after-live-expand-scheduler-stopped
            ;;
    esac

    case "$state_phase" in
        live-expand-migrations-scheduler-stopped)
            runtime_fence_call verify
            assert_blue_state
            assert_legacy_blue_scheduler_stopped
            wait_for_no_schedule_run "$blue_container"
            state_phase=live-expand-migrations-schedule-idle
            write_state "$state_phase"
            test_crash after-live-expand-schedule-idle
            ;;
    esac

    case "$state_phase" in
        live-expand-migrations-schedule-idle)
            runtime_fence_call verify
            assert_blue_state
            assert_legacy_blue_scheduler_stopped
            wait_for_no_schedule_run "$blue_container"
            pause_horizon "$blue_container"
            assert_horizon_paused "$blue_container"
            state_phase=live-expand-migrations-horizon-paused
            write_state "$state_phase"
            test_crash after-live-expand-horizon-paused
            ;;
    esac

    case "$state_phase" in
        live-expand-migrations-horizon-paused)
            runtime_fence_call verify
            assert_blue_state
            assert_legacy_blue_scheduler_stopped
            wait_for_no_schedule_run "$blue_container"
            assert_horizon_paused "$blue_container"
            persist_drain_inventory "$blue_container"
            state_phase=live-expand-migrations-drain-inventory-recorded
            write_state "$state_phase"
            test_crash after-live-expand-drain-inventory-persisted
            ;;
    esac

    case "$state_phase" in
        live-expand-migrations-drain-inventory-recorded)
            runtime_fence_call verify
            assert_blue_state
            assert_legacy_blue_scheduler_stopped
            wait_for_no_schedule_run "$blue_container"
            assert_horizon_paused "$blue_container"
            assert_drain_inventory "$blue_container"
            state_phase=live-expand-migrations-quiesced
            write_state "$state_phase"
            test_crash after-live-expand-background-quiesced
            ;;
    esac

    assert_live_expand_background_quiesced
}

assert_live_expand_migration_runner()
{
    runner_name=$1
    runner_id=$2
    if ! runner_json=$(docker inspect "$runner_name"); then
        migration_reconciliation_api_unavailable=1
        fail 'Docker API is unavailable while reading migration runner identity'
    fi
    printf '%s' "$runner_json" | jq --exit-status \
        --arg runner_name "/${runner_name}" \
        --arg runner_id "$runner_id" \
        --arg project "${compose_project}-live-expand" \
        --arg service live-expand-migrations \
        --arg image_id "$attempt_image_id" \
        --arg operation_id "$operation_id" \
        --arg attempt "$attempt_number" \
        --arg application_name "$attempt_backend_application_name" \
        --arg attempt_lock_identity "$attempt_advisory_lock_identity" '
            .[0].Name == $runner_name
            and ($runner_id == "none" or .[0].Id == $runner_id)
            and .[0].Config.Labels["com.docker.compose.project"] == $project
            and .[0].Config.Labels["com.docker.compose.service"] == $service
            and .[0].Config.Labels["io.coolify.control-plane.operation-id"] == $operation_id
            and .[0].Config.Labels["io.coolify.control-plane.migration-attempt"] == $attempt
            and .[0].Config.Labels["io.coolify.control-plane.pg-application-name"] == $application_name
            and .[0].Config.Labels["io.coolify.control-plane.migration-attempt-lock"]
                == $attempt_lock_identity
            and ([.[0].Config.Env[] | select(startswith("CONTROL_PLANE_MIGRATION_OPERATION_ID="))]
                 == ["CONTROL_PLANE_MIGRATION_OPERATION_ID=" + $operation_id])
            and ([.[0].Config.Env[] | select(startswith("CONTROL_PLANE_MIGRATION_ATTEMPT="))]
                 == ["CONTROL_PLANE_MIGRATION_ATTEMPT=" + $attempt])
            and ([.[0].Config.Env[] | select(startswith("CONTROL_PLANE_MIGRATION_APPLICATION_NAME="))]
                 == ["CONTROL_PLANE_MIGRATION_APPLICATION_NAME=" + $application_name])
            and ([.[0].Config.Env[] | select(startswith("CONTROL_PLANE_MIGRATION_ATTEMPT_LOCK_IDENTITY="))]
                 == ["CONTROL_PLANE_MIGRATION_ATTEMPT_LOCK_IDENTITY=" + $attempt_lock_identity])
            and .[0].Image == $image_id
        ' >/dev/null || fail 'live migration runner identity differs from its durable plan'
}

observe_live_expand_migration_runner()
{
    docker_container_presence "$attempt_runner_name"
    migration_runner_observed_id=none
    migration_runner_observed_status=absent
    migration_runner_running=none
    migration_runner_observed_exit_code=none
    [ "$migration_runner_presence" = present ] || return 0
    assert_live_expand_migration_runner "$attempt_runner_name" "$attempt_runner_id"
    migration_runner_observed_id=$(printf '%s' "$runner_json" | jq --raw-output '.[0].Id')
    validate_container_id "$migration_runner_observed_id" 'observed migration runner ID'
    migration_runner_observed_status=$(printf '%s' "$runner_json" | jq --raw-output '.[0].State.Status')
    migration_runner_running=$(printf '%s' "$runner_json" | jq --raw-output '.[0].State.Running')
    case "$migration_runner_observed_status:$migration_runner_running" in
        created:false)
            ;;
        running:true)
            ;;
        exited:false)
            migration_runner_observed_exit_code=$(printf '%s' "$runner_json" \
                | jq --raw-output '.[0].State.ExitCode')
            printf '%s' "$migration_runner_observed_exit_code" | grep -Eq '^[0-9]+$' \
                && [ "$migration_runner_observed_exit_code" -le 255 ] \
                || fail 'observed migration runner exit code is malformed'
            ;;
        *) fail 'observed migration runner lifecycle status is malformed or unsafe' ;;
    esac
}

create_live_expand_migration_runner()
{
    live_expand_compose create --no-build --pull never live-expand-migrations \
        > "$operation_directory/live-expand-migration-create.log" 2>&1
    test_crash after-live-expand-runner-create
    observe_live_expand_migration_runner
    [ "$migration_runner_presence:$migration_runner_observed_status" = present:created ] \
        || fail 'live migration runner was not created in the exact stopped state'
    attempt_runner_id=$migration_runner_observed_id
    write_migration_attempt_state created
    test_crash after-live-expand-runner-id-persist
}

start_created_live_expand_migration_runner()
{
    case "$attempt_status" in created|start-intent) ;; *)
        fail 'live migration runner start requires a durable start-intent identity' ;;
    esac
    [ "$attempt_runner_id" != none ] \
        || fail 'live migration runner start requires a durable stopped runner identity'
    observe_live_expand_migration_runner
    [ "$migration_runner_presence:$migration_runner_observed_id:$migration_runner_observed_status" \
        = "present:${attempt_runner_id}:created" ] \
        || fail 'durable live migration runner is not the exact created container'
    if [ "$attempt_status" = created ]; then
        write_migration_attempt_state start-intent
        test_crash after-live-expand-runner-start-intent
    fi
    docker start "$attempt_runner_id" >/dev/null \
        || fail 'Docker Engine could not start the durable live migration runner'
    test_crash after-live-expand-runner-start
    observe_live_expand_migration_runner
    record_live_expand_migration_runner_started
}

start_live_expand_migration_runner()
{
    create_live_expand_migration_runner
    start_created_live_expand_migration_runner
}

write_live_expand_migration_attempt_log()
{
    terminal_log_candidate="${migration_attempt_log_file}.new"
    rm -f "$terminal_log_candidate"
    if ! (umask 077; set -C; : > "$terminal_log_candidate") 2>/dev/null; then
        fail 'could not create an atomic migration-attempt log candidate'
    fi
    [ "$migration_runner_presence" = present ] \
        || fail 'terminal migration attempt log requires its exact stopped runner'
    if ! docker logs "$migration_runner_observed_id" > "$terminal_log_candidate" 2>&1; then
        rm -f "$terminal_log_candidate"
        migration_reconciliation_api_unavailable=1
        fail 'Docker API is unavailable while capturing migration runner logs'
    fi
    {
        printf '\noperator_attempt=%s\n' "$attempt_number"
        printf 'operator_runner_id=%s\n' "$attempt_runner_id"
        printf 'operator_runner_exit_code=%s\n' "$attempt_runner_exit_code"
        printf 'operator_postgres_backend_count=%s\n' "$migration_backend_count"
        printf 'operator_postgres_advisory_lock_count=%s\n' "$migration_advisory_lock_count"
        printf 'operator_postgres_backend_lock_count=%s\n' "$migration_backend_lock_count"
        printf 'operator_postgres_backend_global_lock_count=%s\n' "$migration_backend_global_lock_count"
        printf 'operator_ledger_sha256=%s\n' "$migration_classification_ledger_sha256"
        printf 'operator_database_outcome=%s\n' "$attempt_database_outcome"
        printf 'operator_verification_sha256=%s\n' "$attempt_verification_sha256"
    } >> "$terminal_log_candidate"
    sync -f "$terminal_log_candidate"
    test_crash after-migration-attempt-log-fsync
    mv -f "$terminal_log_candidate" "$migration_attempt_log_file"
    test_crash after-migration-attempt-log-rename
    sync -f "$migration_attempts_directory"
    attempt_runner_log_sha256=$(sha256_file "$migration_attempt_log_file")
}

record_live_expand_migration_runner_started()
{
    case "$migration_runner_observed_status" in running|exited) ;; *)
        fail 'live migration runner start did not reach running or exited Docker state' ;;
    esac
    case "$attempt_status" in
        created|start-intent)
            write_migration_attempt_state launched
            sync_operation_state_from_migration_attempt
            ;;
        launched|active)
            ;;
        *) fail 'live migration runner start has no durable attempt state' ;;
    esac
    if [ "$migration_runner_observed_status" = running ] \
        && [ "$attempt_status" != active ]; then
        write_migration_attempt_state active
    fi
}

remove_live_expand_migration_runner()
{
    expected_runner_status=${1:-exited}
    observe_live_expand_migration_runner
    if [ "$migration_runner_presence" = present ]; then
        [ "$migration_runner_observed_status" = "$expected_runner_status" ] \
            || fail 'refusing to remove a live migration runner outside its exact lifecycle state'
        docker rm "$migration_runner_observed_id" >/dev/null
    fi
    docker_container_presence "$attempt_runner_name"
    [ "$migration_runner_presence" = absent ] \
        || fail 'live migration runner remained after exact removal'
}

run_live_expand_migration()
{
    assert_live_expand_background_quiesced
    export_live_database_identity
    CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256=$attempt_ledger_before_sha256
    CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256=$attempt_pending_sha256
    CONTROL_PLANE_EXPECTED_MIGRATION_BATCH=$attempt_expected_batch
    CONTROL_PLANE_MIGRATION_OPERATION_ID=$operation_id
    CONTROL_PLANE_MIGRATION_ATTEMPT=$attempt_number
    CONTROL_PLANE_MIGRATION_APPLICATION_NAME=$attempt_backend_application_name
    CONTROL_PLANE_MIGRATION_ATTEMPT_LOCK_IDENTITY=$attempt_advisory_lock_identity
    export CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256
    export CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256
    export CONTROL_PLANE_EXPECTED_MIGRATION_BATCH
    export CONTROL_PLANE_MIGRATION_OPERATION_ID
    export CONTROL_PLANE_MIGRATION_ATTEMPT
    export CONTROL_PLANE_MIGRATION_APPLICATION_NAME
    export CONTROL_PLANE_MIGRATION_ATTEMPT_LOCK_IDENTITY
    start_live_expand_migration_runner
    if is_test_mode && [ "${CONTROL_PLANE_TEST_CRASH_AT:-}" = during-live-expand-migrations ]; then
        sleep 1
        kill -KILL "$$"
    fi
    reconcile_live_expand_migration_runner wait
}

sync_operation_state_from_migration_attempt()
{
    state_migration_attempt=$attempt_number
    state_migration_attempt_state_sha256=$(sha256_file "$migration_attempt_state_file")
    case "$attempt_status" in
        applied)
            state_migration_status=applied
            state_migration_verification_sha256=$attempt_verification_sha256
            state_phase=live-expand-migrations-applied
            ;;
        failed)
            state_migration_status=failed
            state_migration_verification_sha256=none
            state_phase=live-expand-migrations-failed
            ;;
        blocked)
            state_migration_status=blocked
            state_migration_verification_sha256=none
            state_phase=live-expand-migrations-blocked
            ;;
        *)
            state_migration_status=running
            state_phase=live-expand-migrations-running
            ;;
    esac
    write_state "$state_phase"
}

resume_legacy_blue_background_after_ordinary_migration_failure()
{
    case "$state_phase" in
        live-expand-migrations-running|live-expand-migrations-resuming)
            state_phase=live-expand-migrations-resuming
            write_state "$state_phase"
            runtime_fence_call verify
            resume_legacy_blue_background \
                || fail 'legacy blue background services could not resume after migration failure'
            prove_promoted_background_services "$blue_container" \
                || fail 'legacy blue background services did not prove healthy after migration failure'
            runtime_fence_call verify
            state_phase=live-expand-migrations-resumed
            write_state "$state_phase"
            test_crash after-live-expand-background-resumed
            ;;
        live-expand-migrations-resumed)
            runtime_fence_call verify
            prove_promoted_background_services "$blue_container" \
                || fail 'legacy blue background services did not remain healthy after migration failure'
            runtime_fence_call verify
            ;;
        *)
            fail 'ordinary migration failure cannot restore background services from this phase'
            ;;
    esac
}

finish_live_expand_migration_attempt()
{
    [ "$migration_runner_presence:$migration_runner_observed_status" = present:exited ] \
        && [ "$migration_runner_observed_id" = "$attempt_runner_id" ] \
        && [ "$migration_runner_observed_exit_code" != none ] \
        || fail 'terminal migration attempt requires exact stopped runner ID and exit evidence'
    classify_live_expand_database_outcome
    attempt_database_outcome=$migration_database_outcome
    attempt_verification_sha256=none
    case "$attempt_database_outcome" in
        applied)
            verify_live_expand_migration
            attempt_verification_sha256=$state_migration_verification_sha256
            terminal_attempt_status=applied
            ;;
        unchanged)
            terminal_attempt_status=failed
            ;;
        drift)
            terminal_attempt_status=blocked
            ;;
        *) fail 'migration database classification returned an invalid outcome' ;;
    esac
    if [ "$terminal_attempt_status" = failed ]; then
        resume_legacy_blue_background_after_ordinary_migration_failure
    fi
    migration_blue_quiesced=0
    write_live_expand_migration_attempt_log
    write_migration_attempt_state "$terminal_attempt_status"
    test_crash after-migration-attempt-terminal-state
    sync_operation_state_from_migration_attempt
    test_crash after-migration-attempt-operation-state
    remove_live_expand_migration_runner
    case "$terminal_attempt_status" in
        applied)
            note "live-expand-migrations-applied operation=$operation_id attempt=$attempt_number lock-timeout=$migration_lock_timeout statement-timeout=$migration_statement_timeout"
            return 0
            ;;
        failed)
            note "live-expand-migrations-failed operation=$operation_id attempt=$attempt_number exit-code=$attempt_runner_exit_code"
            return 1
            ;;
        blocked)
            note "live-expand-migrations-blocked operation=$operation_id attempt=$attempt_number database-outcome=drift"
            return 1
            ;;
    esac
}

assert_terminal_migration_attempt()
{
    assert_regular_file "$migration_attempt_log_file" 'terminal migration attempt log'
    [ "$(sha256_file "$migration_attempt_log_file")" = "$attempt_runner_log_sha256" ] \
        || fail 'terminal migration attempt log digest changed'
    classify_live_expand_database_outcome
    [ "$migration_database_outcome" = "$attempt_database_outcome" ] \
        || fail 'terminal migration attempt no longer matches exact database truth'
    if [ "$attempt_status" = applied ]; then
        assert_regular_file "$migration_verification_file" \
            'live migration post-verification artifact'
        [ "$(sha256_file "$migration_verification_file")" = "$attempt_verification_sha256" ] \
            || fail 'applied migration verification digest changed'
    fi
}

reconcile_live_expand_migration_runner()
{
    reconciliation_mode=$1
    validate_migration_attempt_history
    [ "$latest_attempt_number" -gt 0 ] \
        || fail 'migration attempt reconciliation requires an authoritative attempt state'
    assert_migration_attempt_inputs
    observe_live_expand_migration_runner
    observe_control_plane_migration_database_activity

    case "$attempt_status" in
        applied|failed|blocked)
            case "$migration_runner_presence:$migration_runner_observed_status" in
                absent:absent|present:exited) ;;
                *) fail 'terminal migration attempt has a nonterminal runner lifecycle state' ;;
            esac
            [ "$migration_runner_running" != true ] \
                && [ "$migration_advisory_lock_count" = 0 ] \
                && [ "$migration_backend_count" = 0 ] \
                && [ "$migration_global_advisory_lock_count" = 0 ] \
                || fail 'terminal migration attempt still has an active runner, lock, or backend'
            assert_terminal_migration_attempt
            remove_live_expand_migration_runner
            case "$state_phase" in
                preflight-ready|live-expand-migrations-planned|live-expand-migrations-quiescing|live-expand-migrations-scheduler-stopped|live-expand-migrations-schedule-idle|live-expand-migrations-horizon-paused|live-expand-migrations-drain-inventory-recorded|live-expand-migrations-quiesced|live-expand-migrations-running|live-expand-migrations-resuming|live-expand-migrations-resumed|live-expand-migrations-failed|live-expand-migrations-blocked|live-expand-migrations-applied)
                    sync_operation_state_from_migration_attempt
                    ;;
            esac
            [ "$attempt_status" = applied ]
            return $?
            ;;
    esac

    case "$attempt_status:$migration_runner_presence" in
        planned:absent)
            [ "$migration_advisory_lock_count" = 0 ] \
                && [ "$migration_backend_count" = 0 ] \
                && [ "$migration_global_advisory_lock_count" = 0 ] \
                || fail 'a planned live migration runner has active database execution'
            classify_live_expand_database_outcome
            if [ "$migration_database_outcome" = unchanged ] \
                && [ "$reconciliation_mode" = retry ]; then
                [ "$state_phase" = live-expand-migrations-quiesced ] \
                    || fail 'planned migration retry requires durable incumbent quiescence'
                run_live_expand_migration
                return $?
            fi
            [ "$reconciliation_mode" = abort ] \
                || fail 'planned migration runner has no exact container exit evidence'
            return
            ;;
        planned:present)
            fail 'live migration runner is present without a durable runner ID pin'
            ;;
        created:absent|start-intent:absent)
            [ "$reconciliation_mode" = abort ] \
                || fail 'durably identified stopped migration runner disappeared before start'
            [ "$migration_advisory_lock_count" = 0 ] \
                && [ "$migration_backend_count" = 0 ] \
                && [ "$migration_global_advisory_lock_count" = 0 ] \
                || fail 'recovery-abort is forbidden while a created migration runner has database activity'
            return
            ;;
        created:present|start-intent:present)
            if [ "$reconciliation_mode" = abort ]; then
                [ "$migration_advisory_lock_count" = 0 ] \
                    && [ "$migration_backend_count" = 0 ] \
                    && [ "$migration_global_advisory_lock_count" = 0 ] \
                    || fail 'recovery-abort is forbidden while a durable migration runner has database activity'
                case "$migration_runner_observed_status" in
                    created)
                        remove_live_expand_migration_runner created
                        return
                        ;;
                    running)
                        fail 'recovery-abort is forbidden after the durable migration runner start intent'
                        ;;
                    exited)
                        record_live_expand_migration_runner_started
                        reconciliation_mode='wait'
                        ;;
                    *) fail 'durable migration runner has an unsafe lifecycle state' ;;
                esac
            else
                case "$migration_runner_observed_status" in
                    created)
                        [ "$state_phase" = live-expand-migrations-quiesced ] \
                            || fail 'durably identified stopped migration runner lost incumbent quiescence'
                        start_created_live_expand_migration_runner
                        ;;
                    running|exited)
                        record_live_expand_migration_runner_started
                        ;;
                    *) fail 'durable migration runner has an unsafe lifecycle state' ;;
                esac
                reconciliation_mode='wait'
            fi
            ;;
        launched:present|active:present)
            case "$migration_runner_observed_status" in
                running)
                    [ "$reconciliation_mode" != abort ] \
                        || fail 'recovery-abort is forbidden while a durable migration runner is active'
                    record_live_expand_migration_runner_started
                    reconciliation_mode='wait'
                    ;;
                exited)
                    record_live_expand_migration_runner_started
                    reconciliation_mode='wait'
                    ;;
                created)
                    fail 'durable live migration runner lifecycle regressed before terminal evidence'
                    ;;
                *) fail 'durable migration runner has an unsafe lifecycle state' ;;
            esac
            ;;
        launched:absent|active:absent)
            [ "$reconciliation_mode" = abort ] \
                || fail 'launched or active migration runner disappeared without its exact terminal exit evidence'
            [ "$migration_advisory_lock_count" = 0 ] \
                && [ "$migration_backend_count" = 0 ] \
                && [ "$migration_global_advisory_lock_count" = 0 ] \
                || fail 'recovery-abort is forbidden while disappeared migration execution is still active'
            return
            ;;
        *)
            fail 'migration attempt status and runner presence are inconsistent'
            ;;
    esac

    if [ "$migration_runner_observed_status" = running ]; then
        [ "$reconciliation_mode" = wait ] \
            || fail 'previous live migration runner is still active; retry is forbidden'
        if ! docker wait "$migration_runner_observed_id" >/dev/null; then
            migration_reconciliation_api_unavailable=1
            fail 'Docker API is unavailable while waiting for the migration runner'
        fi
        observe_live_expand_migration_runner
    fi
    if [ "$migration_advisory_lock_count" != 0 ] \
        || [ "$migration_backend_count" != 0 ] \
        || [ "$migration_global_advisory_lock_count" != 0 ]; then
        [ "$reconciliation_mode" = wait ] \
            || fail 'live migration PostgreSQL lock or backend is still active; reconciliation is forbidden'
        wait_for_control_plane_migration_database_idle
        observe_control_plane_migration_database_activity
    fi
    [ "$migration_runner_presence:$migration_runner_observed_status" = present:exited ] \
        || fail 'live migration runner did not reach an exact terminal Docker state'
    [ "$attempt_runner_id:$migration_runner_observed_id" = \
        "${attempt_runner_id}:${attempt_runner_id}" ] \
        && [ "$migration_runner_observed_exit_code" != none ] \
        || fail 'live migration runner terminal identity or exit evidence is incomplete'
    attempt_runner_exit_code=$migration_runner_observed_exit_code
    finish_live_expand_migration_attempt
}

assert_persisted_live_expand_migration()
{
    [ "$state_migration_status" = applied ] \
        || fail 'cutover requires a successful live-expand migration'
    [ "$state_migration_verification_sha256" != none ] \
        || fail 'live migration post-verification artifact was not persisted'
    assert_persisted_live_expand_artifacts
    assert_live_expand_verification_inputs
    if ! printf '%s' "$state_migration_attempt" | grep -Eq '^[1-9][0-9]*$'; then
        fail 'persisted successful live migration attempt is invalid'
    fi
    validate_migration_attempt_history
    [ "$attempt_status" = applied ] \
        && [ "$(sha256_file "$migration_attempt_state_file")" = \
            "$operation_attempt_state_sha256" ] \
        && [ "$attempt_verification_sha256" = "$state_migration_verification_sha256" ] \
        || fail 'cutover migration attempt state is not the exact authoritative applied state'
    assert_migration_attempt_inputs
    observe_live_expand_migration_runner
    observe_control_plane_migration_database_activity
    case "$migration_runner_presence:$migration_runner_observed_status" in
        absent:absent|present:exited) ;;
        *) fail 'cutover is forbidden while a migration runner is not terminal' ;;
    esac
    [ "$migration_runner_running" != true ] \
        && [ "$migration_advisory_lock_count" = 0 ] \
        && [ "$migration_backend_count" = 0 ] \
        && [ "$migration_global_advisory_lock_count" = 0 ] \
        || fail 'cutover is forbidden while a migration runner, lock, or backend is active'
    assert_terminal_migration_attempt
    remove_live_expand_migration_runner
}

assert_rehearsal_input_snapshots()
{
    assert_operator_configuration_identity
    assert_file_identity "$rehearsal_runtime_env_file" "$rehearsal_runtime_env_source_path" \
        "$rehearsal_runtime_env_source_sha256" "$rehearsal_runtime_env_source_uid" \
        "$rehearsal_runtime_env_source_gid" "$rehearsal_runtime_env_source_mode" \
        "$rehearsal_runtime_env_source_size" 'migration-rehearsal environment source'
    assert_file_identity "$migration_compatibility_file" \
        "$rehearsal_compatibility_source_path" "$rehearsal_compatibility_source_sha256" \
        "$rehearsal_compatibility_source_uid" "$rehearsal_compatibility_source_gid" \
        "$rehearsal_compatibility_source_mode" "$rehearsal_compatibility_source_size" \
        'migration-rehearsal compatibility source'
    assert_file_identity "$rehearsal_runtime_env_artifact_file" \
        "$rehearsal_runtime_env_snapshot_path" "$rehearsal_runtime_env_snapshot_sha256" \
        "$rehearsal_runtime_env_snapshot_uid" "$rehearsal_runtime_env_snapshot_gid" \
        "$rehearsal_runtime_env_snapshot_mode" "$rehearsal_runtime_env_snapshot_size" \
        'migration-rehearsal environment snapshot'
    assert_file_identity "$rehearsal_compatibility_artifact_file" \
        "$rehearsal_compatibility_snapshot_path" "$rehearsal_compatibility_snapshot_sha256" \
        "$rehearsal_compatibility_snapshot_uid" "$rehearsal_compatibility_snapshot_gid" \
        "$rehearsal_compatibility_snapshot_mode" "$rehearsal_compatibility_snapshot_size" \
        'migration-rehearsal compatibility snapshot'
    [ "$rehearsal_runtime_env_snapshot_sha256" = "$rehearsal_runtime_env_source_sha256" ] \
        && [ "$rehearsal_compatibility_snapshot_sha256" = \
            "$rehearsal_compatibility_source_sha256" ] \
        && [ "$rehearsal_runtime_env_snapshot_uid" = "$operator_uid" ] \
        && [ "$rehearsal_runtime_env_snapshot_gid" = "$operator_gid" ] \
        && [ "$rehearsal_runtime_env_snapshot_mode" = 600 ] \
        && [ "$rehearsal_compatibility_snapshot_uid" = "$operator_uid" ] \
        && [ "$rehearsal_compatibility_snapshot_gid" = "$operator_gid" ] \
        && [ "$rehearsal_compatibility_snapshot_mode" = 600 ] \
        || fail 'migration-rehearsal snapshots differ from their immutable sources'
}

prepare_rehearsal_input_snapshots()
{
    rehearsal_runtime_env_source_path=$rehearsal_runtime_env_file
    rehearsal_runtime_env_source_sha256=$(sha256_file "$rehearsal_runtime_env_file")
    rehearsal_runtime_env_source_uid=$(file_uid "$rehearsal_runtime_env_file")
    rehearsal_runtime_env_source_gid=$(file_gid "$rehearsal_runtime_env_file")
    rehearsal_runtime_env_source_mode=$(file_mode "$rehearsal_runtime_env_file")
    rehearsal_runtime_env_source_size=$(file_size "$rehearsal_runtime_env_file")
    rehearsal_compatibility_source_path=$migration_compatibility_file
    rehearsal_compatibility_source_sha256=$(sha256_file "$migration_compatibility_file")
    rehearsal_compatibility_source_uid=$(file_uid "$migration_compatibility_file")
    rehearsal_compatibility_source_gid=$(file_gid "$migration_compatibility_file")
    rehearsal_compatibility_source_mode=$(file_mode "$migration_compatibility_file")
    rehearsal_compatibility_source_size=$(file_size "$migration_compatibility_file")

    rehearsal_snapshot_presence="$(path_presence "$rehearsal_runtime_env_artifact_file"):$(path_presence "$rehearsal_compatibility_artifact_file")"
    case "$rehearsal_snapshot_presence" in
        absent:absent)
            install_atomic_operation_copy "$rehearsal_runtime_env_file" \
                "$rehearsal_runtime_env_artifact_file" 600 \
                'migration-rehearsal environment snapshot'
            install_atomic_operation_copy "$migration_compatibility_file" \
                "$rehearsal_compatibility_artifact_file" 600 \
                'migration-rehearsal compatibility snapshot'
            ;;
        present:present)
            ;;
        *)
            fail 'migration-rehearsal input snapshots are only partially persisted'
            ;;
    esac
    rehearsal_runtime_env_snapshot_path=$rehearsal_runtime_env_artifact_file
    rehearsal_runtime_env_snapshot_sha256=$(sha256_file "$rehearsal_runtime_env_artifact_file")
    rehearsal_runtime_env_snapshot_uid=$(file_uid "$rehearsal_runtime_env_artifact_file")
    rehearsal_runtime_env_snapshot_gid=$(file_gid "$rehearsal_runtime_env_artifact_file")
    rehearsal_runtime_env_snapshot_mode=$(file_mode "$rehearsal_runtime_env_artifact_file")
    rehearsal_runtime_env_snapshot_size=$(file_size "$rehearsal_runtime_env_artifact_file")
    rehearsal_compatibility_snapshot_path=$rehearsal_compatibility_artifact_file
    rehearsal_compatibility_snapshot_sha256=$(sha256_file \
        "$rehearsal_compatibility_artifact_file")
    rehearsal_compatibility_snapshot_uid=$(file_uid "$rehearsal_compatibility_artifact_file")
    rehearsal_compatibility_snapshot_gid=$(file_gid "$rehearsal_compatibility_artifact_file")
    rehearsal_compatibility_snapshot_mode=$(file_mode "$rehearsal_compatibility_artifact_file")
    rehearsal_compatibility_snapshot_size=$(file_size "$rehearsal_compatibility_artifact_file")
    assert_rehearsal_input_snapshots
}

rehearsal_compose()
{
    assert_rehearsal_input_snapshots
    CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE="$rehearsal_runtime_env_artifact_file" \
        CONTROL_PLANE_RUNTIME_ENV_FILE="$rehearsal_runtime_env_artifact_file" \
        docker compose --ansi never --project-name "${compose_project}-migration-rehearsal" \
            --file "$rehearsal_compose_file" "$@"
}

migration_rehearsal_state_value()
{
    rehearsal_state_key=$1
    rehearsal_state_document=${2:-$rehearsal_state_file}
    awk -F= -v expected_key="$rehearsal_state_key" '
        $1 == expected_key { matches++; result = substr($0, index($0, "=") + 1) }
        END { if (matches != 1) exit 1; print result }
    ' "$rehearsal_state_document" \
        || fail "migration rehearsal state key is absent or duplicated: $rehearsal_state_key"
}

validate_migration_rehearsal_state()
{
    rehearsal_state_document=${1:-$rehearsal_state_file}
    assert_non_symlink_regular_file "$rehearsal_state_document" 'migration rehearsal state'
    [ "$(file_uid "$rehearsal_state_document")" = "$operator_uid" ] \
        && [ "$(file_gid "$rehearsal_state_document")" = "$operator_gid" ] \
        && [ "$(file_mode "$rehearsal_state_document")" = 600 ] \
        || fail 'migration rehearsal state ownership or mode is invalid'
    awk -F= '
        BEGIN {
            expected[1] = "version"; expected[2] = "operation_id";
            expected[3] = "status"; expected[4] = "runner_name";
            expected[5] = "runner_id"; expected[6] = "runner_exit_code";
            expected[7] = "runner_log_sha256"; expected[8] = "image_id";
            expected[9] = "database_container"; expected[10] = "database_container_id";
            expected[11] = "database_image_id"; expected[12] = "database_identity_sha256";
            expected[13] = "database_system_identifier"; expected[14] = "database_name";
            expected[15] = "rehearsal_network_id"; expected[16] = "operator_sha256";
            expected[17] = "rehearsal_compose_sha256";
            expected[18] = "runtime_env_sha256"; expected[19] = "compatibility_sha256";
            expected[20] = "ledger_before_sha256"; expected[21] = "schema_before_sha256";
            expected[22] = "pending_sha256"; expected[23] = "expected_batch";
            expected[24] = "backend_application_name"; expected[25] = "database_outcome";
        }
        NF != 2 || $1 != expected[NR] || seen[$1]++ { exit 1 }
        END { exit(NR == 25 ? 0 : 1) }
    ' "$rehearsal_state_document" \
        || fail 'migration rehearsal state has a malformed or noncanonical key inventory'

    [ "$(migration_rehearsal_state_value version "$rehearsal_state_document")" = 2 ] \
        || fail 'migration rehearsal state version is unsupported'
    [ "$(migration_rehearsal_state_value operation_id "$rehearsal_state_document")" \
        = "$operation_id" ] || fail 'migration rehearsal operation identity changed'
    rehearsal_status=$(migration_rehearsal_state_value status "$rehearsal_state_document")
    rehearsal_runner_name=$(migration_rehearsal_state_value runner_name "$rehearsal_state_document")
    rehearsal_runner_id=$(migration_rehearsal_state_value runner_id "$rehearsal_state_document")
    rehearsal_runner_exit_code=$(migration_rehearsal_state_value runner_exit_code \
        "$rehearsal_state_document")
    rehearsal_runner_log_sha256=$(migration_rehearsal_state_value runner_log_sha256 \
        "$rehearsal_state_document")
    rehearsal_state_image_id=$(migration_rehearsal_state_value image_id \
        "$rehearsal_state_document")
    rehearsal_state_database_container=$(migration_rehearsal_state_value database_container \
        "$rehearsal_state_document")
    rehearsal_state_database_container_id=$(migration_rehearsal_state_value \
        database_container_id "$rehearsal_state_document")
    rehearsal_state_database_image_id=$(migration_rehearsal_state_value database_image_id \
        "$rehearsal_state_document")
    rehearsal_state_database_identity_sha256=$(migration_rehearsal_state_value \
        database_identity_sha256 "$rehearsal_state_document")
    rehearsal_state_database_system_identifier=$(migration_rehearsal_state_value \
        database_system_identifier "$rehearsal_state_document")
    rehearsal_state_database_name=$(migration_rehearsal_state_value database_name \
        "$rehearsal_state_document")
    rehearsal_state_network_id=$(migration_rehearsal_state_value rehearsal_network_id \
        "$rehearsal_state_document")
    rehearsal_state_operator_sha256=$(migration_rehearsal_state_value operator_sha256 \
        "$rehearsal_state_document")
    rehearsal_state_compose_sha256=$(migration_rehearsal_state_value \
        rehearsal_compose_sha256 "$rehearsal_state_document")
    rehearsal_state_runtime_env_sha256=$(migration_rehearsal_state_value runtime_env_sha256 \
        "$rehearsal_state_document")
    rehearsal_state_compatibility_sha256=$(migration_rehearsal_state_value \
        compatibility_sha256 "$rehearsal_state_document")
    rehearsal_state_ledger_before_sha256=$(migration_rehearsal_state_value \
        ledger_before_sha256 "$rehearsal_state_document")
    rehearsal_state_schema_before_sha256=$(migration_rehearsal_state_value \
        schema_before_sha256 "$rehearsal_state_document")
    rehearsal_state_pending_sha256=$(migration_rehearsal_state_value pending_sha256 \
        "$rehearsal_state_document")
    rehearsal_state_expected_batch=$(migration_rehearsal_state_value expected_batch \
        "$rehearsal_state_document")
    rehearsal_state_backend_application_name=$(migration_rehearsal_state_value \
        backend_application_name "$rehearsal_state_document")
    rehearsal_database_outcome=$(migration_rehearsal_state_value database_outcome \
        "$rehearsal_state_document")

    case "$rehearsal_status" in planned|launched|active|passed|failed|blocked) ;; *)
        fail 'migration rehearsal status is invalid' ;;
    esac
    printf '%s' "$rehearsal_runner_name" | grep -Eq '^coolify-cp-rehearse-[0-9a-f]{24}$' \
        || fail 'migration rehearsal runner name is malformed'
    [ "$rehearsal_runner_id" = none ] \
        || printf '%s' "$rehearsal_runner_id" | grep -Eq '^[0-9a-f]{64}$' \
        || fail 'migration rehearsal runner ID is malformed'
    [ "$rehearsal_runner_exit_code" = none ] \
        || { printf '%s' "$rehearsal_runner_exit_code" | grep -Eq '^[0-9]+$' \
            && [ "$rehearsal_runner_exit_code" -le 255 ]; } \
        || fail 'migration rehearsal runner exit code is malformed'
    [ "$rehearsal_runner_log_sha256" = none ] \
        || printf '%s' "$rehearsal_runner_log_sha256" | grep -Eq '^[0-9a-f]{64}$' \
        || fail 'migration rehearsal runner log digest is malformed'
    for rehearsal_sha256 in "$rehearsal_state_image_id" \
        "$rehearsal_state_database_image_id"
    do
        printf '%s' "$rehearsal_sha256" | grep -Eq '^sha256:[0-9a-f]{64}$' \
            || fail 'migration rehearsal image identity is malformed'
    done
    for rehearsal_sha256 in "$rehearsal_state_database_identity_sha256" \
        "$rehearsal_state_network_id" "$rehearsal_state_operator_sha256" \
        "$rehearsal_state_compose_sha256" "$rehearsal_state_runtime_env_sha256" \
        "$rehearsal_state_compatibility_sha256" "$rehearsal_state_ledger_before_sha256" \
        "$rehearsal_state_schema_before_sha256" "$rehearsal_state_pending_sha256"
    do
        printf '%s' "$rehearsal_sha256" | grep -Eq '^[0-9a-f]{64}$' \
            || fail 'migration rehearsal contains a malformed SHA-256 identity'
    done
    printf '%s' "$rehearsal_state_database_container_id" | grep -Eq '^[0-9a-f]{64}$' \
        || fail 'migration rehearsal database container ID is malformed'
    printf '%s' "$rehearsal_state_database_system_identifier" | grep -Eq '^[1-9][0-9]*$' \
        || fail 'migration rehearsal database system identifier is malformed'
    validate_identifier "$rehearsal_state_database_container" \
        'migration rehearsal database container'
    validate_identifier "$rehearsal_state_database_name" 'migration rehearsal database name'
    printf '%s' "$rehearsal_state_expected_batch" | grep -Eq '^[1-9][0-9]*$' \
        || fail 'migration rehearsal expected batch is malformed'
    [ "$rehearsal_state_backend_application_name" = coolify-control-plane-expand ] \
        || fail 'migration rehearsal PostgreSQL application identity changed'
    case "$rehearsal_database_outcome" in unknown|unchanged|applied|drift) ;; *)
        fail 'migration rehearsal database outcome is invalid' ;;
    esac
    case "$rehearsal_status" in
        planned)
            [ "$rehearsal_runner_id:$rehearsal_runner_exit_code:$rehearsal_runner_log_sha256:$rehearsal_database_outcome" \
                = none:none:none:unknown ] \
                || fail 'planned migration rehearsal contains launched or terminal evidence'
            ;;
        launched|active)
            [ "$rehearsal_runner_id" != none ] \
                && [ "$rehearsal_runner_exit_code:$rehearsal_runner_log_sha256:$rehearsal_database_outcome" \
                    = none:none:unknown ] \
                || fail 'nonterminal migration rehearsal evidence is inconsistent'
            ;;
        passed)
            [ "$rehearsal_runner_log_sha256" != none ] \
                && [ "$rehearsal_database_outcome" = applied ] \
                || fail 'passed migration rehearsal lacks exact applied evidence'
            ;;
        failed)
            [ "$rehearsal_runner_log_sha256" != none ] \
                && [ "$rehearsal_database_outcome" = unchanged ] \
                || fail 'failed migration rehearsal lacks exact unchanged evidence'
            ;;
        blocked)
            [ "$rehearsal_runner_log_sha256" != none ] \
                && [ "$rehearsal_database_outcome" = drift ] \
                || fail 'blocked migration rehearsal lacks exact drift evidence'
            ;;
    esac
}

write_migration_rehearsal_state()
{
    next_rehearsal_status=$1
    rehearsal_state_candidate="${rehearsal_state_file}.new"
    rm -f "$rehearsal_state_candidate"
    if ! (umask 077; set -C; : > "$rehearsal_state_candidate") 2>/dev/null; then
        fail 'could not create an atomic migration-rehearsal state candidate'
    fi
    {
        printf '%s\n' 'version=2'
        printf 'operation_id=%s\n' "$operation_id"
        printf 'status=%s\n' "$next_rehearsal_status"
        printf 'runner_name=%s\n' "$rehearsal_runner_name"
        printf 'runner_id=%s\n' "$rehearsal_runner_id"
        printf 'runner_exit_code=%s\n' "$rehearsal_runner_exit_code"
        printf 'runner_log_sha256=%s\n' "$rehearsal_runner_log_sha256"
        printf 'image_id=%s\n' "$rehearsal_plan_image_id"
        printf 'database_container=%s\n' "$rehearsal_database_container"
        printf 'database_container_id=%s\n' "$rehearsal_database_container_id"
        printf 'database_image_id=%s\n' "$rehearsal_database_image_id"
        printf 'database_identity_sha256=%s\n' "$rehearsal_database_identity_sha256"
        printf 'database_system_identifier=%s\n' "$rehearsal_database_system_identifier"
        printf 'database_name=%s\n' "$rehearsal_database_actual_name"
        printf 'rehearsal_network_id=%s\n' "$rehearsal_network_id"
        printf 'operator_sha256=%s\n' "$rehearsal_operator_sha256"
        printf 'rehearsal_compose_sha256=%s\n' "$rehearsal_compose_sha256"
        printf 'runtime_env_sha256=%s\n' "$rehearsal_runtime_env_snapshot_sha256"
        printf 'compatibility_sha256=%s\n' "$rehearsal_compatibility_snapshot_sha256"
        printf 'ledger_before_sha256=%s\n' "$rehearsal_ledger_before_sha256"
        printf 'schema_before_sha256=%s\n' "$rehearsal_schema_before_sha256"
        printf 'pending_sha256=%s\n' "$rehearsal_pending_sha256"
        printf 'expected_batch=%s\n' "$rehearsal_expected_migration_batch"
        printf 'backend_application_name=%s\n' "$rehearsal_backend_application_name"
        printf 'database_outcome=%s\n' "$rehearsal_database_outcome"
    } > "$rehearsal_state_candidate"
    chmod 600 "$rehearsal_state_candidate"
    validate_migration_rehearsal_state "$rehearsal_state_candidate"
    sync -f "$rehearsal_state_candidate"
    mv -f "$rehearsal_state_candidate" "$rehearsal_state_file"
    sync -f "$operation_directory"
    rehearsal_status=$next_rehearsal_status
    case "$next_rehearsal_status" in
        passed|failed|blocked)
            test_crash after-migration-rehearsal-terminal-state
            ;;
    esac
}

rehearsal_runtime_environment_value()
{
    rehearsal_environment_key=$1
    awk -F= -v expected_key="$rehearsal_environment_key" '
        $1 == expected_key { matches++; result = substr($0, index($0, "=") + 1) }
        END { if (matches != 1 || result == "") exit 1; print result }
    ' "$rehearsal_runtime_env_artifact_file" \
        || fail "migration rehearsal runtime environment must contain exactly one $rehearsal_environment_key"
}

rehearsal_database_psql()
{
    docker exec \
        --env "PGOPTIONS=-c lock_timeout=${migration_lock_timeout} -c statement_timeout=${migration_statement_timeout}" \
        "$rehearsal_database_container" psql --no-psqlrc --tuples-only --no-align --quiet \
        --set ON_ERROR_STOP=1 --username "$rehearsal_database_user" \
        --dbname "$rehearsal_database_name" "$@"
}

rehearsal_ledger_snapshot()
{
    rehearsal_database_psql --command \
        "COPY (SELECT migration, batch FROM migrations ORDER BY migration) TO STDOUT WITH (FORMAT csv)" \
        > "$1"
}

rehearsal_schema_snapshot()
{
    (
        database_container=$rehearsal_database_container
        database_user=$rehearsal_database_user
        database_name=$rehearsal_database_name
        migration_schema_snapshot "$1"
    )
}

verify_rehearsal_blue_green_schema()
{
    (
        database_container=$rehearsal_database_container
        database_user=$rehearsal_database_user
        database_name=$rehearsal_database_name
        verify_blue_green_schema
    )
}

capture_migration_rehearsal_identity()
{
    rehearsal_plan_image_id=$(image_id "$green_image")
    rehearsal_database_container_id=$(container_id "$rehearsal_database_container")
    rehearsal_database_image_id=$(container_image_id "$rehearsal_database_container")
    rehearsal_database_system_identifier=$(rehearsal_database_psql --command \
        'SELECT system_identifier::text FROM pg_control_system()' | tr -d '[:space:]')
    rehearsal_database_actual_name=$(rehearsal_database_psql --command \
        'SELECT current_database()' | tr -d '[:space:]')
    rehearsal_database_oid=$(rehearsal_database_psql --command \
        'SELECT oid::text FROM pg_database WHERE datname = current_database()' | tr -d '[:space:]')
    rehearsal_database_address=$(docker inspect --format \
        "{{with index .NetworkSettings.Networks \"${rehearsal_network}\"}}{{.IPAddress}}{{end}}" \
        "$rehearsal_database_container")
    printf '%s' "$rehearsal_database_system_identifier" | grep -Eq '^[1-9][0-9]*$' \
        || fail 'migration rehearsal database system identifier is invalid'
    [ "$rehearsal_database_actual_name" = "$rehearsal_database_name" ] \
        || fail 'migration rehearsal database name differs from its explicit plan'
    printf '%s' "$rehearsal_database_oid" | grep -Eq '^[1-9][0-9]*$' \
        || fail 'migration rehearsal database OID is invalid'
    printf '%s' "$rehearsal_database_address" | grep -Eq '^[0-9a-fA-F:.]+$' \
        || fail 'migration rehearsal database address is invalid'
    rehearsal_database_instance_marker=$(printf '%s' \
        "${rehearsal_database_system_identifier}:${rehearsal_database_oid}" \
        | sha256sum | awk '{print $1}')
    rehearsal_database_identity_payload="system_identifier=${rehearsal_database_system_identifier};database=${rehearsal_database_actual_name};server_address=${rehearsal_database_address};server_port=${database_port};instance_marker=${rehearsal_database_instance_marker}"
    rehearsal_database_identity_sha256=$(printf '%s' "$rehearsal_database_identity_payload" \
        | sha256sum | awk '{print $1}')
    rehearsal_expected_database_identity_sha256=$(rehearsal_runtime_environment_value \
        CONTROL_PLANE_EXPECTED_DATABASE_IDENTITY_SHA256)
    [ "$rehearsal_database_identity_sha256" = \
        "$rehearsal_expected_database_identity_sha256" ] \
        || fail 'migration rehearsal database identity differs from its runtime environment pin'
    live_database_system_identifier=$(database_psql --command \
        'SELECT system_identifier::text FROM pg_control_system()' | tr -d '[:space:]')
    [ "$rehearsal_database_system_identifier" != "$live_database_system_identifier" ] \
        || fail 'migration rehearsal database is not a distinct clone'
    rehearsal_network_id=$(network_id "$rehearsal_network")
    printf '%s' "$rehearsal_network_id" | grep -Eq '^[0-9a-f]{64}$' \
        || fail 'migration rehearsal network ID is malformed'
    docker inspect "$rehearsal_database_container" | jq --exit-status \
        --arg rehearsal_network "$rehearsal_network" \
        --arg live_network "$control_plane_network" '
            .[0].NetworkSettings.Networks | has($rehearsal_network) and (has($live_network) | not)
        ' >/dev/null || fail 'migration rehearsal database network identity is unsafe'
    rehearsal_operator_sha256=$(sha256_file "$OPERATOR_PATH")
    rehearsal_compose_sha256=$(sha256_file "$rehearsal_compose_file")
    rehearsal_backend_application_name=coolify-control-plane-expand
    rehearsal_runner_key=$(printf '%s\n' "$operation_id" \
        | sha256sum | awk '{ print substr($1, 1, 24) }')
    rehearsal_runner_name="coolify-cp-rehearse-${rehearsal_runner_key}"
}

prepare_migration_rehearsal_plan()
{
    rehearsal_plan_candidate="${rehearsal_plan_directory}.new"
    [ ! -e "$rehearsal_plan_directory" ] && [ ! -L "$rehearsal_plan_directory" ] \
        || fail 'migration rehearsal plan already exists before initial planning'
    if [ -d "$rehearsal_plan_candidate" ] && [ ! -L "$rehearsal_plan_candidate" ]; then
        rm -f "$rehearsal_plan_candidate/ledger-before.csv" \
            "$rehearsal_plan_candidate/schema-before.csv" \
            "$rehearsal_plan_candidate/pending"
        rmdir "$rehearsal_plan_candidate" \
            || fail 'stale migration rehearsal plan candidate contains unexpected entries'
    fi
    [ ! -e "$rehearsal_plan_candidate" ] && [ ! -L "$rehearsal_plan_candidate" ] \
        || fail 'migration rehearsal plan candidate path is unsafe'
    (umask 077; mkdir "$rehearsal_plan_candidate")
    rehearsal_ledger_snapshot "$rehearsal_plan_candidate/ledger-before.csv"
    rehearsal_schema_snapshot "$rehearsal_plan_candidate/schema-before.csv"
    sed -n 's/^authorized-pending-migration=//p' \
        "$rehearsal_compatibility_artifact_file" > "$rehearsal_plan_candidate/pending"
    grep -Eq '^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+$' \
        "$rehearsal_plan_candidate/pending" \
        || fail 'migration rehearsal pending inventory is empty or malformed'
    [ "$(LC_ALL=C sort -u "$rehearsal_plan_candidate/pending" | sha256_file /dev/stdin)" \
        = "$(sha256_file "$rehearsal_plan_candidate/pending")" ] \
        || fail 'migration rehearsal pending inventory is not sorted and unique'
    rehearsal_ledger_before_sha256=$(sha256_file \
        "$rehearsal_plan_candidate/ledger-before.csv")
    rehearsal_schema_before_sha256=$(sha256_file \
        "$rehearsal_plan_candidate/schema-before.csv")
    rehearsal_pending_sha256=$(sha256_file "$rehearsal_plan_candidate/pending")
    [ "$rehearsal_ledger_before_sha256" = \
        "$rehearsal_expected_migration_ledger_sha256" ] \
        || fail 'migration rehearsal clone ledger differs from its authorization input'
    [ "$rehearsal_pending_sha256" = "$rehearsal_expected_migration_pending_sha256" ] \
        || fail 'migration rehearsal pending inventory differs from its authorization input'
    rehearsal_observed_expected_batch=$(rehearsal_database_psql --command \
        'SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations' | tr -d '[:space:]')
    [ "$rehearsal_observed_expected_batch" = "$rehearsal_expected_migration_batch" ] \
        || fail 'migration rehearsal clone batch differs from its authorization input'
    mv "$rehearsal_plan_candidate" "$rehearsal_plan_directory"
    sync -f "$operation_directory"
    rehearsal_runner_id=none
    rehearsal_runner_exit_code=none
    rehearsal_runner_log_sha256=none
    rehearsal_database_outcome=unknown
    write_migration_rehearsal_state planned
}

assert_migration_rehearsal_plan()
{
    validate_migration_rehearsal_state
    [ "$rehearsal_runner_name" = "coolify-cp-rehearse-${rehearsal_runner_key}" ] \
        && [ "$rehearsal_state_image_id" = "$rehearsal_plan_image_id" ] \
        && [ "$rehearsal_state_database_container" = "$rehearsal_database_container" ] \
        && [ "$rehearsal_state_database_container_id" = "$rehearsal_database_container_id" ] \
        && [ "$rehearsal_state_database_image_id" = "$rehearsal_database_image_id" ] \
        && [ "$rehearsal_state_database_identity_sha256" = \
            "$rehearsal_database_identity_sha256" ] \
        && [ "$rehearsal_state_database_system_identifier" = \
            "$rehearsal_database_system_identifier" ] \
        && [ "$rehearsal_state_database_name" = "$rehearsal_database_actual_name" ] \
        && [ "$rehearsal_state_network_id" = "$rehearsal_network_id" ] \
        && [ "$rehearsal_state_operator_sha256" = "$rehearsal_operator_sha256" ] \
        && [ "$rehearsal_state_compose_sha256" = "$rehearsal_compose_sha256" ] \
        && [ "$rehearsal_state_runtime_env_sha256" = \
            "$rehearsal_runtime_env_snapshot_sha256" ] \
        && [ "$rehearsal_state_compatibility_sha256" = \
            "$rehearsal_compatibility_snapshot_sha256" ] \
        && [ "$rehearsal_state_expected_batch" = "$rehearsal_expected_migration_batch" ] \
        && [ "$rehearsal_state_backend_application_name" = \
            "$rehearsal_backend_application_name" ] \
        || fail 'migration rehearsal immutable plan differs from its authoritative state'
    assert_non_symlink_regular_file "$rehearsal_ledger_before_file" \
        'migration rehearsal ledger-before artifact'
    assert_non_symlink_regular_file "$rehearsal_schema_before_file" \
        'migration rehearsal schema-before artifact'
    assert_non_symlink_regular_file "$rehearsal_pending_file" \
        'migration rehearsal pending artifact'
    rehearsal_ledger_before_sha256=$(sha256_file "$rehearsal_ledger_before_file")
    rehearsal_schema_before_sha256=$(sha256_file "$rehearsal_schema_before_file")
    rehearsal_pending_sha256=$(sha256_file "$rehearsal_pending_file")
    [ "$rehearsal_ledger_before_sha256" = "$rehearsal_state_ledger_before_sha256" ] \
        && [ "$rehearsal_schema_before_sha256" = \
            "$rehearsal_state_schema_before_sha256" ] \
        && [ "$rehearsal_pending_sha256" = "$rehearsal_state_pending_sha256" ] \
        && [ "$rehearsal_ledger_before_sha256" = \
            "$rehearsal_expected_migration_ledger_sha256" ] \
        && [ "$rehearsal_pending_sha256" = \
            "$rehearsal_expected_migration_pending_sha256" ] \
        || fail 'migration rehearsal authoritative plan artifacts changed'
}

assert_migration_rehearsal_runner()
{
    rehearsal_asserted_id=$1
    if ! rehearsal_runner_json=$(docker inspect "$rehearsal_runner_name"); then
        migration_reconciliation_api_unavailable=1
        fail 'Docker API is unavailable while reading migration rehearsal identity'
    fi
    printf '%s' "$rehearsal_runner_json" | jq --exit-status \
        --arg runner_name "/${rehearsal_runner_name}" \
        --arg runner_id "$rehearsal_asserted_id" \
        --arg project "${compose_project}-migration-rehearsal" \
        --arg image_id "$rehearsal_plan_image_id" \
        --arg operation_id "$operation_id" \
        --arg database_identity "$rehearsal_database_identity_sha256" \
        --arg ledger "$rehearsal_ledger_before_sha256" \
        --arg pending "$rehearsal_pending_sha256" \
        --arg batch "$rehearsal_expected_migration_batch" \
        --arg application_name "$rehearsal_backend_application_name" \
        --arg rehearsal_network "$rehearsal_network" \
        --arg live_network "$control_plane_network" '
            .[0].Name == $runner_name
            and ($runner_id == "none" or .[0].Id == $runner_id)
            and .[0].Config.Labels["com.docker.compose.project"] == $project
            and .[0].Config.Labels["com.docker.compose.service"] == "migration-rehearsal"
            and .[0].Config.Labels["io.coolify.control-plane.operation-id"] == $operation_id
            and .[0].Config.Labels["io.coolify.control-plane.migration-rehearsal"] == "true"
            and .[0].Config.Labels["io.coolify.control-plane.database-identity-sha256"] == $database_identity
            and .[0].Config.Labels["io.coolify.control-plane.migration-ledger-sha256"] == $ledger
            and .[0].Config.Labels["io.coolify.control-plane.migration-pending-sha256"] == $pending
            and .[0].Config.Labels["io.coolify.control-plane.migration-batch"] == $batch
            and .[0].Config.Labels["io.coolify.control-plane.pg-application-name"] == $application_name
            and .[0].Image == $image_id
            and (.[0].NetworkSettings.Networks | has($rehearsal_network))
            and (.[0].NetworkSettings.Networks | has($live_network) | not)
        ' >/dev/null || fail 'migration rehearsal runner identity differs from its durable plan'
}

observe_migration_rehearsal_runner()
{
    docker_container_presence "$rehearsal_runner_name"
    rehearsal_runner_presence=$container_presence
    rehearsal_runner_observed_id=none
    rehearsal_runner_observed_status=absent
    rehearsal_runner_observed_exit_code=none
    [ "$rehearsal_runner_presence" = present ] || return 0
    assert_migration_rehearsal_runner "$rehearsal_runner_id"
    rehearsal_runner_observed_id=$(printf '%s' "$rehearsal_runner_json" \
        | jq --raw-output '.[0].Id')
    rehearsal_runner_observed_status=$(printf '%s' "$rehearsal_runner_json" \
        | jq --raw-output '.[0].State.Status')
    case "$rehearsal_runner_observed_status" in
        created|running)
            ;;
        exited)
            rehearsal_runner_observed_exit_code=$(printf '%s' "$rehearsal_runner_json" \
                | jq --raw-output '.[0].State.ExitCode')
            printf '%s' "$rehearsal_runner_observed_exit_code" | grep -Eq '^[0-9]+$' \
                && [ "$rehearsal_runner_observed_exit_code" -le 255 ] \
                || fail 'migration rehearsal runner exit code is malformed'
            ;;
        *)
            fail "migration rehearsal runner has an unsafe Docker state: $rehearsal_runner_observed_status"
            ;;
    esac
}

observe_migration_rehearsal_database_activity()
{
    rehearsal_activity=$(rehearsal_database_psql --field-separator=, --command "
WITH lock_key AS (
    SELECT hashtextextended('coolify-control-plane-expand', 0) AS value
), rehearsal_backend AS (
    SELECT pid FROM pg_stat_activity
    WHERE pid <> pg_backend_pid() AND application_name = 'coolify-control-plane-expand'
), rehearsal_lock AS (
    SELECT advisory_lock.pid FROM pg_locks AS advisory_lock CROSS JOIN lock_key
    WHERE advisory_lock.locktype = 'advisory' AND advisory_lock.granted
      AND advisory_lock.objsubid = 1
      AND advisory_lock.classid = (((lock_key.value >> 32) & 4294967295)::oid)
      AND advisory_lock.objid = ((lock_key.value & 4294967295)::oid)
)
SELECT (SELECT count(*) FROM rehearsal_backend),
       (SELECT count(*) FROM rehearsal_lock),
       (SELECT count(*) FROM rehearsal_lock JOIN rehearsal_backend USING (pid))" \
        | tr -d '[:space:]')
    printf '%s\n' "$rehearsal_activity" | awk -F, '
        NF == 3 && $1 ~ /^[0-9]+$/ && $2 ~ /^[0-9]+$/ && $3 ~ /^[0-9]+$/ \
            && $3 <= $1 && $3 <= $2 { valid++ }
        END { exit(valid == 1 ? 0 : 1) }
    ' || fail 'migration rehearsal PostgreSQL activity truth is malformed'
    rehearsal_backend_count=$(printf '%s' "$rehearsal_activity" | awk -F, '{print $1}')
    rehearsal_advisory_lock_count=$(printf '%s' "$rehearsal_activity" | awk -F, '{print $2}')
    rehearsal_backend_lock_count=$(printf '%s' "$rehearsal_activity" | awk -F, '{print $3}')
}

wait_for_migration_rehearsal_database_idle()
{
    rehearsal_idle_attempt=0
    while [ "$rehearsal_idle_attempt" -lt "$drain_attempts" ]; do
        observe_migration_rehearsal_database_activity
        if [ "$rehearsal_backend_count" = 0 ] \
            && [ "$rehearsal_advisory_lock_count" = 0 ]; then
            return
        fi
        rehearsal_idle_attempt=$((rehearsal_idle_attempt + 1))
        sleep 1
    done
    fail 'migration rehearsal PostgreSQL backend or advisory lock did not become idle'
}

classify_migration_rehearsal_database()
{
    rehearsal_classification_ledger="$operation_directory/.migration-rehearsal-ledger-current"
    rehearsal_classification_schema="$operation_directory/.migration-rehearsal-schema-current"
    rehearsal_classification_guard="$operation_directory/.migration-rehearsal-schema-guard"
    rm -f "$rehearsal_classification_ledger" "$rehearsal_classification_schema" \
        "$rehearsal_classification_guard"
    rehearsal_ledger_snapshot "$rehearsal_classification_ledger"
    rehearsal_current_ledger_sha256=$(sha256_file "$rehearsal_classification_ledger")
    if [ "$rehearsal_current_ledger_sha256" = "$rehearsal_ledger_before_sha256" ]; then
        rehearsal_schema_snapshot "$rehearsal_classification_schema"
        if [ "$(sha256_file "$rehearsal_classification_schema")" = \
            "$rehearsal_schema_before_sha256" ]; then
            rehearsal_database_outcome=unchanged
        else
            rehearsal_database_outcome=drift
        fi
    elif awk -F, \
        -v pre_file="$rehearsal_ledger_before_file" \
        -v pending_file="$rehearsal_pending_file" \
        -v current_file="$rehearsal_classification_ledger" \
        -v expected_batch="$rehearsal_expected_migration_batch" '
            FILENAME == pre_file {
                if (NF != 2 || $1 == "" || $2 !~ /^[1-9][0-9]*$/ || pre[$1]++) exit 1
                pre_batch[$1] = $2; next
            }
            FILENAME == pending_file {
                if (NF != 1 || $1 == "" || pending[$1]++) exit 1
                next
            }
            FILENAME == current_file {
                if (NF != 2 || $1 == "" || $2 !~ /^[1-9][0-9]*$/ || current[$1]++) exit 1
                if ($1 in pre_batch) {
                    if ($2 != pre_batch[$1]) exit 1
                    preserved[$1] = 1; next
                }
                if (($1 in pending) && $2 == expected_batch) {
                    applied[$1] = 1; next
                }
                exit 1
            }
            END {
                for (migration in pre_batch) if (!(migration in preserved)) exit 1
                for (migration in pending) if (!(migration in applied)) exit 1
            }
        ' "$rehearsal_ledger_before_file" "$rehearsal_pending_file" \
            "$rehearsal_classification_ledger"; then
        if verify_rehearsal_blue_green_schema > "$rehearsal_classification_guard" \
            && awk -F= '
                NF == 2 { observed++; if ($2 == "true") passed++ }
                END { exit(observed == 9 && passed == 9 ? 0 : 1) }
            ' "$rehearsal_classification_guard"; then
            rehearsal_database_outcome=applied
        else
            rehearsal_database_outcome=drift
        fi
    else
        rehearsal_database_outcome=drift
    fi
    rm -f "$rehearsal_classification_ledger" "$rehearsal_classification_schema" \
        "$rehearsal_classification_guard"
}

start_migration_rehearsal_runner()
{
    test_crash before-migration-rehearsal-runner-create
    created_rehearsal_runner_id=$(rehearsal_compose run --detach --no-deps --pull never \
        --name "$rehearsal_runner_name" \
        --env "PGAPPNAME=${rehearsal_backend_application_name}" \
        --label "io.coolify.control-plane.operation-id=${operation_id}" \
        --label 'io.coolify.control-plane.migration-rehearsal=true' \
        --label "io.coolify.control-plane.database-identity-sha256=${rehearsal_database_identity_sha256}" \
        --label "io.coolify.control-plane.migration-ledger-sha256=${rehearsal_ledger_before_sha256}" \
        --label "io.coolify.control-plane.migration-pending-sha256=${rehearsal_pending_sha256}" \
        --label "io.coolify.control-plane.migration-batch=${rehearsal_expected_migration_batch}" \
        --label "io.coolify.control-plane.pg-application-name=${rehearsal_backend_application_name}" \
        migration-rehearsal)
    validate_container_id "$created_rehearsal_runner_id" 'migration rehearsal runner ID'
    test_crash after-migration-rehearsal-runner-create
    rehearsal_runner_id=$created_rehearsal_runner_id
    assert_migration_rehearsal_runner "$rehearsal_runner_id"
    write_migration_rehearsal_state launched
    test_crash after-migration-rehearsal-runner-id-persist
    write_migration_rehearsal_state active
    test_crash after-migration-rehearsal-runner-active
}

write_migration_rehearsal_log()
{
    rehearsal_log_candidate="${rehearsal_log_file}.new"
    rm -f "$rehearsal_log_candidate"
    if [ "$rehearsal_runner_presence" = present ]; then
        docker logs "$rehearsal_runner_observed_id" > "$rehearsal_log_candidate" 2>&1 \
            || fail 'Docker API is unavailable while capturing migration rehearsal logs'
    else
        {
            printf '%s\n' 'migration rehearsal runner disappeared; terminal outcome reconciled from exact clone truth'
            printf 'control-plane-database-identity=%s\n' "$rehearsal_database_identity_payload"
            printf 'control-plane-database-identity-sha256=%s\n' \
                "$rehearsal_database_identity_sha256"
        } > "$rehearsal_log_candidate"
    fi
    if [ "$rehearsal_database_outcome" = applied ]; then
        identity_line_count=$(grep -F -x -c \
            "control-plane-database-identity=${rehearsal_database_identity_payload}" \
            "$rehearsal_log_candidate" || true)
        identity_sha_count=$(grep -F -x -c \
            "control-plane-database-identity-sha256=${rehearsal_database_identity_sha256}" \
            "$rehearsal_log_candidate" || true)
        schema_pass_count=$(grep -F -x -c 'control-plane-schema-attestation=passed' \
            "$rehearsal_log_candidate" || true)
        [ "$identity_line_count" -le 1 ] && [ "$identity_sha_count" -le 1 ] \
            && [ "$schema_pass_count" -le 1 ] \
            || fail 'migration rehearsal emitted duplicate identity or schema proof lines'
        [ "$identity_line_count" = 1 ] \
            || printf 'control-plane-database-identity=%s\n' \
                "$rehearsal_database_identity_payload" >> "$rehearsal_log_candidate"
        [ "$identity_sha_count" = 1 ] \
            || printf 'control-plane-database-identity-sha256=%s\n' \
                "$rehearsal_database_identity_sha256" >> "$rehearsal_log_candidate"
        [ "$schema_pass_count" = 1 ] \
            || printf '%s\n' 'control-plane-schema-attestation=passed' \
                >> "$rehearsal_log_candidate"
    fi
    {
        printf '\noperator_runner_id=%s\n' "$rehearsal_runner_id"
        printf 'operator_runner_exit_code=%s\n' "$rehearsal_runner_exit_code"
        printf 'operator_postgres_backend_count=%s\n' "$rehearsal_backend_count"
        printf 'operator_postgres_advisory_lock_count=%s\n' "$rehearsal_advisory_lock_count"
        printf 'operator_postgres_backend_lock_count=%s\n' "$rehearsal_backend_lock_count"
        printf 'operator_ledger_sha256=%s\n' "$rehearsal_current_ledger_sha256"
        printf 'operator_database_outcome=%s\n' "$rehearsal_database_outcome"
    } >> "$rehearsal_log_candidate"
    chmod 600 "$rehearsal_log_candidate"
    sync -f "$rehearsal_log_candidate"
    test_crash after-migration-rehearsal-log-fsync
    if [ -e "$rehearsal_log_file" ]; then
        cmp -s "$rehearsal_log_candidate" "$rehearsal_log_file" \
            || fail 'migration rehearsal log differs from its previously renamed terminal bytes'
        rm -f "$rehearsal_log_candidate"
    else
        mv "$rehearsal_log_candidate" "$rehearsal_log_file"
    fi
    test_crash after-migration-rehearsal-log-rename
    sync -f "$operation_directory"
    rehearsal_runner_log_sha256=$(sha256_file "$rehearsal_log_file")
}

remove_migration_rehearsal_runner()
{
    observe_migration_rehearsal_runner
    if [ "$rehearsal_runner_presence" = present ]; then
        [ "$rehearsal_runner_observed_status" = exited ] \
            || fail 'refusing to remove a nonterminal migration rehearsal runner'
        docker rm "$rehearsal_runner_observed_id" >/dev/null
    fi
    docker_container_presence "$rehearsal_runner_name"
    [ "$container_presence" = absent ] \
        || fail 'migration rehearsal runner remained after exact terminal cleanup'
}

finish_migration_rehearsal_runner()
{
    classify_migration_rehearsal_database
    case "$rehearsal_database_outcome" in
        applied) terminal_rehearsal_status=passed ;;
        unchanged) terminal_rehearsal_status=failed ;;
        drift) terminal_rehearsal_status=blocked ;;
        *) fail 'migration rehearsal database classification is invalid' ;;
    esac
    write_migration_rehearsal_log
    write_migration_rehearsal_state "$terminal_rehearsal_status"
    remove_migration_rehearsal_runner
    test_crash after-migration-rehearsal-cleanup
    case "$terminal_rehearsal_status" in
        passed) return ;;
        failed) fail 'migration rehearsal runner failed without changing clone truth' ;;
        blocked) fail 'migration rehearsal clone has an unsafe partial or wrong-batch outcome' ;;
    esac
}

reconcile_migration_rehearsal_runner()
{
    assert_migration_rehearsal_plan
    observe_migration_rehearsal_runner
    observe_migration_rehearsal_database_activity
    case "$rehearsal_status" in
        passed|failed|blocked)
            [ "$rehearsal_backend_count" = 0 ] \
                && [ "$rehearsal_advisory_lock_count" = 0 ] \
                || fail 'terminal migration rehearsal still owns a PostgreSQL backend or lock'
            classify_migration_rehearsal_database
            case "$rehearsal_status:$rehearsal_database_outcome" in
                passed:applied|failed:unchanged|blocked:drift) ;;
                *) fail 'terminal migration rehearsal no longer matches exact clone truth' ;;
            esac
            [ "$(sha256_file "$rehearsal_log_file")" = "$rehearsal_runner_log_sha256" ] \
                || fail 'terminal migration rehearsal log digest changed'
            remove_migration_rehearsal_runner
            [ "$rehearsal_status" = passed ] \
                || fail "migration rehearsal is terminal: $rehearsal_status"
            return
            ;;
    esac

    if [ "$rehearsal_runner_presence" = present ] \
        && [ "$rehearsal_runner_id" = none ]; then
        rehearsal_runner_id=$rehearsal_runner_observed_id
        write_migration_rehearsal_state launched
        if [ "$rehearsal_runner_observed_status" = created ]; then
            docker start "$rehearsal_runner_id" >/dev/null
        fi
        write_migration_rehearsal_state active
    fi
    if [ "$rehearsal_runner_presence" = absent ] \
        && [ "$rehearsal_status" = planned ]; then
        [ "$rehearsal_backend_count" = 0 ] \
            && [ "$rehearsal_advisory_lock_count" = 0 ] \
            || wait_for_migration_rehearsal_database_idle
        classify_migration_rehearsal_database
        if [ "$rehearsal_database_outcome" = unchanged ]; then
            rehearsal_database_outcome=unknown
            start_migration_rehearsal_runner
            observe_migration_rehearsal_runner
        else
            finish_migration_rehearsal_runner
            return
        fi
    fi
    if [ "$rehearsal_runner_presence" = absent ]; then
        [ "$rehearsal_backend_count" = 0 ] \
            && [ "$rehearsal_advisory_lock_count" = 0 ] \
            || wait_for_migration_rehearsal_database_idle
        rehearsal_runner_exit_code=none
        finish_migration_rehearsal_runner
        return
    fi
    if [ "$rehearsal_runner_observed_status" = created ]; then
        docker start "$rehearsal_runner_observed_id" >/dev/null
        write_migration_rehearsal_state active
        observe_migration_rehearsal_runner
    fi
    if [ "$rehearsal_runner_observed_status" = running ]; then
        docker wait "$rehearsal_runner_observed_id" >/dev/null \
            || fail 'Docker API is unavailable while waiting for migration rehearsal'
        observe_migration_rehearsal_runner
    fi
    [ "$rehearsal_runner_observed_status" = exited ] \
        || fail 'migration rehearsal runner did not reach a terminal Docker state'
    rehearsal_runner_exit_code=$rehearsal_runner_observed_exit_code
    test_crash after-migration-rehearsal-runner-exit
    wait_for_migration_rehearsal_database_idle
    finish_migration_rehearsal_runner
}

live_expand_compose()
{
    assert_runtime_env_artifact "$green_runtime_env_file" "$state_green_runtime_env_path" \
        "$state_green_runtime_env_sha256" 'green runtime environment artifact'
    CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE="$green_runtime_env_file" \
    CONTROL_PLANE_RUNTIME_ENV_FILE="$green_runtime_env_file" \
    CONTROL_PLANE_MIGRATION_RUNNER_NAME="$attempt_runner_name" \
    CONTROL_PLANE_MIGRATION_OPERATION_ID="$operation_id" \
    CONTROL_PLANE_MIGRATION_ATTEMPT="$attempt_number" \
    CONTROL_PLANE_MIGRATION_APPLICATION_NAME="$attempt_backend_application_name" \
    CONTROL_PLANE_MIGRATION_ATTEMPT_LOCK_IDENTITY="$attempt_advisory_lock_identity" \
        docker compose --ansi never --project-name "${compose_project}-live-expand" \
            --file "$rehearsal_compose_file" "$@"
}

load_configuration()
{
    test_mode=${CONTROL_PLANE_TEST_MODE:-0}
    operator_target=${CONTROL_PLANE_OPERATOR_TARGET:-production}
    case "${test_mode}:${operator_target}" in
        0:production|1:lab)
            ;;
        *)
            fail 'CONTROL_PLANE_TEST_MODE and CONTROL_PLANE_OPERATOR_TARGET must be exactly 0:production or 1:lab'
            ;;
    esac
    if is_test_mode; then
        validate_nonnegative_integer \
            "${CONTROL_PLANE_TEST_MUTATION_FREEZE_EXCLUSIVE_LEASE_HOLD_SECONDS:-0}" \
            CONTROL_PLANE_TEST_MUTATION_FREEZE_EXCLUSIVE_LEASE_HOLD_SECONDS
    else
        [ -z "${CONTROL_PLANE_TEST_MUTATION_FREEZE_EXCLUSIVE_LEASE_HOLD_SECONDS:-}" ] \
            || fail 'mutation-freeze exclusive-lease test hold is unavailable in production mode'
        [ -z "${CONTROL_PLANE_TEST_MUTATION_LEASE_RACE:-}" ] \
            || fail 'mutation-lease race injection is unavailable in production mode'
    fi
    configure_global_transaction_lock
    acquire_lock

    operation_id=${CONTROL_PLANE_OPERATION_ID:-}
    mutation_freeze_epoch=${CONTROL_PLANE_MUTATION_FREEZE_EPOCH:-}
    reverse_mutation_freeze_epoch=${CONTROL_PLANE_REVERSE_MUTATION_FREEZE_EPOCH:-}
    writer_epoch=${CONTROL_PLANE_WRITER_EPOCH:-}
    blue_writer_epoch=${CONTROL_PLANE_BLUE_WRITER_EPOCH:-}
    green_writer_member=${CONTROL_PLANE_GREEN_WRITER_MEMBER:-web-a}
    blue_writer_member=${CONTROL_PLANE_BLUE_WRITER_MEMBER:-web-a}
    green_web_epoch=${CONTROL_PLANE_GREEN_WEB_A_WEB_EPOCH:-}
    green_web_b_epoch=${CONTROL_PLANE_GREEN_WEB_B_WEB_EPOCH:-}
    blue_web_epoch=${CONTROL_PLANE_BLUE_WEB_A_WEB_EPOCH:-}
    blue_web_b_epoch=${CONTROL_PLANE_BLUE_WEB_B_WEB_EPOCH:-}
    green_web_a_route_drain_epoch=${CONTROL_PLANE_GREEN_WEB_A_ROUTE_DRAIN_EPOCH:-}
    green_web_b_route_drain_epoch=${CONTROL_PLANE_GREEN_WEB_B_ROUTE_DRAIN_EPOCH:-}
    blue_web_a_route_drain_epoch=${CONTROL_PLANE_BLUE_WEB_A_ROUTE_DRAIN_EPOCH:-}
    blue_web_b_route_drain_epoch=${CONTROL_PLANE_BLUE_WEB_B_ROUTE_DRAIN_EPOCH:-}
    blue_container=${CONTROL_PLANE_BLUE_CONTAINER:-coolify}
    green_container=${CONTROL_PLANE_GREEN_WEB_A_CONTAINER:-coolify-control-plane-green-web-a}
    green_web_b_container=${CONTROL_PLANE_GREEN_WEB_B_CONTAINER:-coolify-control-plane-green-web-b}
    replacement_blue_container=${CONTROL_PLANE_BLUE_WEB_A_CONTAINER:-coolify-control-plane-blue-web-a}
    replacement_blue_web_b_container=${CONTROL_PLANE_BLUE_WEB_B_CONTAINER:-coolify-control-plane-blue-web-b}
    green_web_a_route_identity=${CONTROL_PLANE_GREEN_WEB_A_ROUTE_IDENTITY:-}
    green_web_b_route_identity=${CONTROL_PLANE_GREEN_WEB_B_ROUTE_IDENTITY:-}
    blue_web_a_route_identity=${CONTROL_PLANE_BLUE_WEB_A_ROUTE_IDENTITY:-}
    blue_web_b_route_identity=${CONTROL_PLANE_BLUE_WEB_B_ROUTE_IDENTITY:-}
    green_pool_label_value=${CONTROL_PLANE_GREEN_POOL_LABEL_VALUE:-}
    blue_pool_label_value=${CONTROL_PLANE_BLUE_POOL_LABEL_VALUE:-}
    forward_pool_generation=${CONTROL_PLANE_FORWARD_POOL_GENERATION:-1}
    proxy_container=${CONTROL_PLANE_PROXY_CONTAINER:-coolify-proxy}
    database_container=${CONTROL_PLANE_DATABASE_CONTAINER:-coolify-db}
    database_user=${CONTROL_PLANE_DATABASE_USER:-coolify}
    database_name=${CONTROL_PLANE_DATABASE_NAME:-coolify}
    green_image=${CONTROL_PLANE_GREEN_IMAGE:-}
    blue_image=${CONTROL_PLANE_BLUE_IMAGE:-}
    source_compose_base=${CONTROL_PLANE_SOURCE_COMPOSE_BASE:-/data/coolify/source/docker-compose.yml}
    source_compose_prod=${CONTROL_PLANE_SOURCE_COMPOSE_PROD:-/data/coolify/source/docker-compose.prod.yml}
    source_compose_custom=${CONTROL_PLANE_SOURCE_COMPOSE_CUSTOM:-/data/coolify/source/docker-compose.custom.yml}
    source_compose_postgres=${CONTROL_PLANE_SOURCE_COMPOSE_POSTGRES:-/data/coolify/source/docker-compose.postgres-upgrade.yml}
    proxy_enrollment_compose_override=${CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE:-/data/coolify/source/docker-compose.control-plane-enrolled.yml}
    proxy_enrollment_command=${CONTROL_PLANE_PROXY_ENROLLMENT_COMMAND:-}
    proxy_enrollment_docker_socket=${CONTROL_PLANE_PROXY_ENROLLMENT_DOCKER_SOCKET:-/var/run/docker.sock}
    source_env_file=${CONTROL_PLANE_SOURCE_ENV_FILE:-/data/coolify/source/.env}
    source_compose_project=${CONTROL_PLANE_SOURCE_COMPOSE_PROJECT:-coolify}
    source_compose_service=${CONTROL_PLANE_SOURCE_COMPOSE_SERVICE:-coolify}
    control_plane_network=${CONTROL_PLANE_NETWORK:-coolify}
    green_state_volume=${CONTROL_PLANE_GREEN_WEB_A_PRIVATE_VOLUME:-coolify-control-plane-green-web-a-private}
    green_web_b_private_volume=${CONTROL_PLANE_GREEN_WEB_B_PRIVATE_VOLUME:-coolify-control-plane-green-web-b-private}
    blue_state_volume=${CONTROL_PLANE_BLUE_WEB_A_PRIVATE_VOLUME:-coolify-control-plane-blue-web-a-private}
    blue_web_b_private_volume=${CONTROL_PLANE_BLUE_WEB_B_PRIVATE_VOLUME:-coolify-control-plane-blue-web-b-private}
    coordination_volume=${CONTROL_PLANE_COORDINATION_VOLUME:-coolify-control-plane-coordination}
    state_directory=${CONTROL_PLANE_OPERATOR_STATE_DIR:-/var/lib/coolify-control-plane-operator}
    traefik_dynamic_directory=${CONTROL_PLANE_TRAEFIK_DYNAMIC_DIR:-}
    dynamic_filename=${CONTROL_PLANE_TRAEFIK_DYNAMIC_FILENAME:-coolify-control-plane-blue-green.yaml}
    control_plane_host=${CONTROL_PLANE_HOST:-coolify.iocloudhost.net}
    traefik_entrypoint=${CONTROL_PLANE_TRAEFIK_ENTRYPOINT:-https}
    local_ingress_entrypoint=${CONTROL_PLANE_LOCAL_INGRESS_ENTRYPOINT:-coolify-local}
    traefik_tls=${CONTROL_PLANE_TRAEFIK_TLS:-true}
    traefik_cert_resolver=${CONTROL_PLANE_TRAEFIK_CERT_RESOLVER:-}
    traefik_router_priority=${CONTROL_PLANE_TRAEFIK_ROUTER_PRIORITY:-100000}
    backend_port=${CONTROL_PLANE_BACKEND_PORT:-8080}
    configured_app_port=${APP_PORT:-}
    app_port=
    direct_probe_path=${CONTROL_PLANE_DIRECT_PROBE_PATH:-/api/control-plane/probe}
    green_direct_probe_token_file=${CONTROL_PLANE_GREEN_WEB_A_DIRECT_PROBE_RUNTIME_FILE:-}
    green_applied_ack_file=${CONTROL_PLANE_GREEN_WEB_A_APPLIED_ACK_RUNTIME_FILE:-}
    green_web_b_direct_probe_token_file=${CONTROL_PLANE_GREEN_WEB_B_DIRECT_PROBE_RUNTIME_FILE:-}
    green_web_b_applied_ack_file=${CONTROL_PLANE_GREEN_WEB_B_APPLIED_ACK_RUNTIME_FILE:-}
    green_route_health_token_file=${CONTROL_PLANE_GREEN_ROUTE_HEALTH_RUNTIME_FILE:-}
    green_pool_ack_file=${CONTROL_PLANE_GREEN_POOL_ACK_RUNTIME_FILE:-}
    blue_direct_probe_token_file=${CONTROL_PLANE_BLUE_WEB_A_DIRECT_PROBE_RUNTIME_FILE:-}
    blue_applied_ack_file=${CONTROL_PLANE_BLUE_WEB_A_APPLIED_ACK_RUNTIME_FILE:-}
    blue_web_b_direct_probe_token_file=${CONTROL_PLANE_BLUE_WEB_B_DIRECT_PROBE_RUNTIME_FILE:-}
    blue_web_b_applied_ack_file=${CONTROL_PLANE_BLUE_WEB_B_APPLIED_ACK_RUNTIME_FILE:-}
    blue_route_health_token_file=${CONTROL_PLANE_BLUE_ROUTE_HEALTH_RUNTIME_FILE:-}
    blue_pool_ack_file=${CONTROL_PLANE_BLUE_POOL_ACK_RUNTIME_FILE:-}
    secret_uid=${CONTROL_PLANE_SECRET_UID:-9999}
    secret_gid=${CONTROL_PLANE_SECRET_GID:-9999}
    operator_uid=$(id -u)
    operator_gid=$(id -g)
    if is_test_mode; then
        immutable_uid=$operator_uid
        immutable_gid=$operator_gid
    else
        immutable_uid=0
        immutable_gid=0
    fi
    if is_test_mode && [ "$operator_uid" -ne 0 ]; then
        runtime_env_uid=$operator_uid
        runtime_env_gid=$operator_gid
    else
        runtime_env_uid=9999
        runtime_env_gid=9999
    fi
    ssh_directory=${CONTROL_PLANE_SSH_DIRECTORY:-/data/coolify/ssh}
    applications_directory=${CONTROL_PLANE_APPLICATIONS_DIRECTORY:-/data/coolify/applications}
    databases_directory=${CONTROL_PLANE_DATABASES_DIRECTORY:-/data/coolify/databases}
    services_directory=${CONTROL_PLANE_SERVICES_DIRECTORY:-/data/coolify/services}
    backups_directory=${CONTROL_PLANE_BACKUPS_DIRECTORY:-/data/coolify/backups}
    green_loopback_port=${CONTROL_PLANE_GREEN_WEB_A_LOOPBACK_PORT:-18081}
    green_web_b_loopback_port=${CONTROL_PLANE_GREEN_WEB_B_LOOPBACK_PORT:-18082}
    blue_loopback_port=${CONTROL_PLANE_BLUE_WEB_A_LOOPBACK_PORT:-18083}
    blue_web_b_loopback_port=${CONTROL_PLANE_BLUE_WEB_B_LOOPBACK_PORT:-18084}
    database_host=${CONTROL_PLANE_DATABASE_HOST:-coolify-db}
    database_port=${CONTROL_PLANE_DATABASE_PORT:-5432}
    redis_host=${CONTROL_PLANE_REDIS_HOST:-coolify-redis}
    redis_port=${CONTROL_PLANE_REDIS_PORT:-6379}
    soketi_host=${CONTROL_PLANE_SOKETI_HOST:-coolify-realtime}
    soketi_port=${CONTROL_PLANE_SOKETI_PORT:-6001}
    soketi_metrics_port=${CONTROL_PLANE_SOKETI_METRICS_PORT:-6002}
    candidate_health_attempts=${CONTROL_PLANE_CANDIDATE_HEALTH_ATTEMPTS:-40}
    drain_attempts=${CONTROL_PLANE_DRAIN_ATTEMPTS:-60}
    drain_stable_seconds=${CONTROL_PLANE_DRAIN_STABLE_SECONDS:-2}
    s6_wait_milliseconds=${CONTROL_PLANE_S6_WAIT_MILLISECONDS:-30000}
    ingress_controller=${CONTROL_PLANE_INGRESS_CONTROLLER:-$SCRIPT_DIRECTORY/controllers/traefik-ingress.sh}
    public_probe_url=${CONTROL_PLANE_PUBLIC_PROBE_URL:-}
    expected_ipv4=${CONTROL_PLANE_EXPECTED_PUBLIC_IPV4:-}
    configured_local_ingress_url=${CONTROL_PLANE_LOCAL_INGRESS_URL:-}
    local_ingress_url=
    public_probe_host_header=${CONTROL_PLANE_PUBLIC_PROBE_HOST_HEADER:-}
    public_probe_attempts=${CONTROL_PLANE_PUBLIC_PROBE_ATTEMPTS:-20}
    container_stop_timeout=${CONTROL_PLANE_CONTAINER_STOP_TIMEOUT:-30}
    backup_attestation_verifier=${CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER:-$SCRIPT_DIRECTORY/backup/restore-attest.sh}
    backup_attestation_verifier_sha256=${CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER_SHA256:-}
    backup_database_dump_file=${CONTROL_PLANE_BACKUP_DATABASE_DUMP_FILE:-}
    backup_redis_snapshot_file=${CONTROL_PLANE_BACKUP_REDIS_SNAPSHOT_FILE:-}
    backup_state_archive_file=${CONTROL_PLANE_BACKUP_STATE_ARCHIVE_FILE:-}
    backup_capture_manifest_file=${CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_FILE:-}
    backup_capture_manifest_sha256=${CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_SHA256:-}
    backup_attestation_file=${CONTROL_PLANE_BACKUP_ATTESTATION_FILE:-}
    backup_attestation_sha256=${CONTROL_PLANE_BACKUP_ATTESTATION_SHA256:-}
    backup_expected_source_pg_system_identifier=${CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_SYSTEM_IDENTIFIER:-}
    backup_expected_source_pg_database_oid=${CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_DATABASE_OID:-}
    backup_expected_source_pg_version=${CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_VERSION:-}
    backup_expected_source_database_name=${CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_DATABASE_NAME:-}
    backup_expected_source_host_identity=${CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_HOST_IDENTITY:-}
    backup_expected_source_redis_container_id=${CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_CONTAINER_ID:-}
    backup_expected_source_redis_image_id=${CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_IMAGE_ID:-}
    backup_expected_source_redis_image_digest=${CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_IMAGE_DIGEST:-}
    backup_expected_source_redis_endpoint=${CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_ENDPOINT:-}
    backup_expected_candidate_image_digest=${CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_IMAGE_DIGEST:-}
    backup_expected_recipient_fingerprint=${CONTROL_PLANE_BACKUP_EXPECTED_RECIPIENT_FINGERPRINT:-}
    backup_expected_quiesce_operator_sha256=${CONTROL_PLANE_BACKUP_EXPECTED_QUIESCE_OPERATOR_SHA256:-}
    backup_expected_restore_target_identity=${CONTROL_PLANE_BACKUP_EXPECTED_RESTORE_TARGET_IDENTITY:-}
    backup_max_age_seconds=${CONTROL_PLANE_BACKUP_MAX_AGE_SECONDS:-}
    backup_expected_operator_rehearsal_hook_sha256=${CONTROL_PLANE_BACKUP_EXPECTED_OPERATOR_REHEARSAL_HOOK_SHA256:-}
    backup_expected_candidate_boot_hook_sha256=${CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_BOOT_HOOK_SHA256:-}
    backup_expected_candidate_api_probe_hook_sha256=${CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_API_PROBE_HOOK_SHA256:-}
    backup_expected_state_proof_tool_sha256=${CONTROL_PLANE_BACKUP_EXPECTED_STATE_PROOF_TOOL_SHA256:-}
    operator_compose_file=${CONTROL_PLANE_OPERATOR_COMPOSE_FILE:-$SCRIPT_DIRECTORY/compose.yaml}
    release_rehearsal_compose_file=${CONTROL_PLANE_REHEARSAL_COMPOSE_FILE:-$SCRIPT_DIRECTORY/compose.rehearsal.yaml}
    rehearsal_compose_file=${CONTROL_PLANE_TEST_REHEARSAL_COMPOSE_FILE:-$release_rehearsal_compose_file}
    compose_project=${CONTROL_PLANE_COMPOSE_PROJECT:-coolify-control-plane-blue-green}
    migration_compatibility_file=${CONTROL_PLANE_MIGRATION_COMPATIBILITY_FILE:-}
    rehearsal_runtime_env_file=${CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE:-}
    rehearsal_network=${CONTROL_PLANE_REHEARSAL_NETWORK:-}
    rehearsal_database_container=${CONTROL_PLANE_REHEARSAL_DATABASE_CONTAINER:-}
    rehearsal_database_user=${CONTROL_PLANE_REHEARSAL_DATABASE_USER:-}
    rehearsal_database_name=${CONTROL_PLANE_REHEARSAL_DATABASE_NAME:-}
    rehearsal_expected_migration_ledger_sha256=${CONTROL_PLANE_REHEARSAL_EXPECTED_MIGRATION_LEDGER_SHA256:-}
    rehearsal_expected_migration_pending_sha256=${CONTROL_PLANE_REHEARSAL_EXPECTED_MIGRATION_PENDING_SHA256:-}
    rehearsal_expected_migration_batch=${CONTROL_PLANE_REHEARSAL_EXPECTED_MIGRATION_BATCH:-}
    migration_lock_timeout=${CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT:-}
    migration_statement_timeout=${CONTROL_PLANE_MIGRATION_STATEMENT_TIMEOUT:-}
    runtime_fence_provisioner=${CONTROL_PLANE_RUNTIME_FENCE_PROVISIONER:-/usr/local/sbin/coolify-runtime-fence-provision}
    runtime_fence_provisioner_sha256=${CONTROL_PLANE_RUNTIME_FENCE_PROVISIONER_SHA256:-}
    runtime_fence_management_endpoints=${CONTROL_PLANE_RUNTIME_FENCE_MANAGEMENT_ENDPOINTS:-}
    runtime_fence_additional_network_ids=${CONTROL_PLANE_RUNTIME_FENCE_ADDITIONAL_NETWORK_IDS:-}
    runtime_fence_self_ssh_target=${CONTROL_PLANE_RUNTIME_FENCE_SELF_SSH_TARGET:-host.docker.internal}
    runtime_fence_probe_max_age_seconds=${CONTROL_PLANE_RUNTIME_FENCE_PROBE_MAX_AGE_SECONDS:-30}
    runtime_fence_queue_stable_seconds=${CONTROL_PLANE_RUNTIME_FENCE_QUEUE_STABLE_SECONDS:-2}
    runtime_fence_provider_api_url=${CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_API_URL:-}
    runtime_fence_provider_router=${CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_ROUTER:-}
    runtime_fence_provider_service=${CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_SERVICE:-}
    runtime_fence_provider_legacy_port=${CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_LEGACY_PORT:-$backend_port}
    runtime_fence_provider_header_file=${CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_HEADER_FILE:-}
    configure_and_verify_release_inventory


    require_value CONTROL_PLANE_OPERATION_ID "$operation_id"
    require_value CONTROL_PLANE_MUTATION_FREEZE_EPOCH "$mutation_freeze_epoch"
    require_value CONTROL_PLANE_REVERSE_MUTATION_FREEZE_EPOCH "$reverse_mutation_freeze_epoch"
    require_value CONTROL_PLANE_WRITER_EPOCH "$writer_epoch"
    require_value CONTROL_PLANE_BLUE_WRITER_EPOCH "$blue_writer_epoch"
    require_value CONTROL_PLANE_GREEN_WEB_A_WEB_EPOCH "$green_web_epoch"
    require_value CONTROL_PLANE_GREEN_WEB_B_WEB_EPOCH "$green_web_b_epoch"
    require_value CONTROL_PLANE_BLUE_WEB_A_WEB_EPOCH "$blue_web_epoch"
    require_value CONTROL_PLANE_BLUE_WEB_B_WEB_EPOCH "$blue_web_b_epoch"
    require_value CONTROL_PLANE_GREEN_WEB_A_ROUTE_DRAIN_EPOCH "$green_web_a_route_drain_epoch"
    require_value CONTROL_PLANE_GREEN_WEB_B_ROUTE_DRAIN_EPOCH "$green_web_b_route_drain_epoch"
    require_value CONTROL_PLANE_BLUE_WEB_A_ROUTE_DRAIN_EPOCH "$blue_web_a_route_drain_epoch"
    require_value CONTROL_PLANE_BLUE_WEB_B_ROUTE_DRAIN_EPOCH "$blue_web_b_route_drain_epoch"
    require_value CONTROL_PLANE_GREEN_WEB_A_ROUTE_IDENTITY "$green_web_a_route_identity"
    require_value CONTROL_PLANE_GREEN_WEB_B_ROUTE_IDENTITY "$green_web_b_route_identity"
    require_value CONTROL_PLANE_BLUE_WEB_A_ROUTE_IDENTITY "$blue_web_a_route_identity"
    require_value CONTROL_PLANE_BLUE_WEB_B_ROUTE_IDENTITY "$blue_web_b_route_identity"
    require_value CONTROL_PLANE_GREEN_POOL_LABEL_VALUE "$green_pool_label_value"
    require_value CONTROL_PLANE_BLUE_POOL_LABEL_VALUE "$blue_pool_label_value"
    require_value CONTROL_PLANE_GREEN_IMAGE "$green_image"
    require_value CONTROL_PLANE_BLUE_IMAGE "$blue_image"
    require_value CONTROL_PLANE_TRAEFIK_DYNAMIC_DIR "$traefik_dynamic_directory"
    require_value CONTROL_PLANE_GREEN_WEB_A_DIRECT_PROBE_RUNTIME_FILE "$green_direct_probe_token_file"
    require_value CONTROL_PLANE_GREEN_WEB_A_APPLIED_ACK_RUNTIME_FILE "$green_applied_ack_file"
    require_value CONTROL_PLANE_GREEN_WEB_B_DIRECT_PROBE_RUNTIME_FILE "$green_web_b_direct_probe_token_file"
    require_value CONTROL_PLANE_GREEN_WEB_B_APPLIED_ACK_RUNTIME_FILE "$green_web_b_applied_ack_file"
    require_value CONTROL_PLANE_GREEN_ROUTE_HEALTH_RUNTIME_FILE "$green_route_health_token_file"
    require_value CONTROL_PLANE_GREEN_POOL_ACK_RUNTIME_FILE "$green_pool_ack_file"
    require_value CONTROL_PLANE_BLUE_WEB_A_DIRECT_PROBE_RUNTIME_FILE "$blue_direct_probe_token_file"
    require_value CONTROL_PLANE_BLUE_WEB_A_APPLIED_ACK_RUNTIME_FILE "$blue_applied_ack_file"
    require_value CONTROL_PLANE_BLUE_WEB_B_DIRECT_PROBE_RUNTIME_FILE "$blue_web_b_direct_probe_token_file"
    require_value CONTROL_PLANE_BLUE_WEB_B_APPLIED_ACK_RUNTIME_FILE "$blue_web_b_applied_ack_file"
    require_value CONTROL_PLANE_BLUE_ROUTE_HEALTH_RUNTIME_FILE "$blue_route_health_token_file"
    require_value CONTROL_PLANE_BLUE_POOL_ACK_RUNTIME_FILE "$blue_pool_ack_file"
    require_value CONTROL_PLANE_PUBLIC_PROBE_URL "$public_probe_url"
    require_value CONTROL_PLANE_EXPECTED_PUBLIC_IPV4 "$expected_ipv4"
    require_value CONTROL_PLANE_RUNTIME_FENCE_PROVISIONER "$runtime_fence_provisioner"
    require_value CONTROL_PLANE_RUNTIME_FENCE_PROVISIONER_SHA256 "$runtime_fence_provisioner_sha256"
    require_value CONTROL_PLANE_RUNTIME_FENCE_MANAGEMENT_ENDPOINTS "$runtime_fence_management_endpoints"
    require_value CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_API_URL "$runtime_fence_provider_api_url"
    require_value CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_ROUTER "$runtime_fence_provider_router"
    require_value CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_SERVICE "$runtime_fence_provider_service"
    validate_identifier "$operation_id" CONTROL_PLANE_OPERATION_ID
    validate_safe_token "$mutation_freeze_epoch" CONTROL_PLANE_MUTATION_FREEZE_EPOCH
    validate_safe_token "$reverse_mutation_freeze_epoch" CONTROL_PLANE_REVERSE_MUTATION_FREEZE_EPOCH
    case "$green_writer_member:$blue_writer_member" in
        web-a:web-a|web-a:web-b|web-b:web-a|web-b:web-b) ;;
        *) fail 'green and blue writer members must each be exactly web-a or web-b' ;;
    esac
    validate_safe_token "$writer_epoch" CONTROL_PLANE_WRITER_EPOCH
    validate_safe_token "$blue_writer_epoch" CONTROL_PLANE_BLUE_WRITER_EPOCH
    for pool_epoch_identity in \
        "CONTROL_PLANE_GREEN_WEB_A_WEB_EPOCH:$green_web_epoch" \
        "CONTROL_PLANE_GREEN_WEB_B_WEB_EPOCH:$green_web_b_epoch" \
        "CONTROL_PLANE_BLUE_WEB_A_WEB_EPOCH:$blue_web_epoch" \
        "CONTROL_PLANE_BLUE_WEB_B_WEB_EPOCH:$blue_web_b_epoch" \
        "CONTROL_PLANE_GREEN_WEB_A_ROUTE_DRAIN_EPOCH:$green_web_a_route_drain_epoch" \
        "CONTROL_PLANE_GREEN_WEB_B_ROUTE_DRAIN_EPOCH:$green_web_b_route_drain_epoch" \
        "CONTROL_PLANE_BLUE_WEB_A_ROUTE_DRAIN_EPOCH:$blue_web_a_route_drain_epoch" \
        "CONTROL_PLANE_BLUE_WEB_B_ROUTE_DRAIN_EPOCH:$blue_web_b_route_drain_epoch"
    do
        pool_epoch_name=${pool_epoch_identity%%:*}
        pool_epoch_value=${pool_epoch_identity#*:}
        validate_safe_token "$pool_epoch_value" "$pool_epoch_name"
    done
    [ "$writer_epoch" != "$blue_writer_epoch" ] || fail 'blue and green writer epochs must be different'
    epoch_inventory=$(printf '%s\n' "$mutation_freeze_epoch" "$reverse_mutation_freeze_epoch" \
        "$writer_epoch" "$blue_writer_epoch" "$green_web_epoch" "$green_web_b_epoch" \
        "$blue_web_epoch" "$blue_web_b_epoch" "$green_web_a_route_drain_epoch" \
        "$green_web_b_route_drain_epoch" "$blue_web_a_route_drain_epoch" \
        "$blue_web_b_route_drain_epoch")
    [ "$(printf '%s\n' "$epoch_inventory" | LC_ALL=C sort -u | wc -l | tr -d '[:space:]')" = 12 ] \
        || fail 'all pool writer, web, drain, and freeze epochs must be globally distinct'
    for route_identity in "$green_web_a_route_identity" "$green_web_b_route_identity" \
        "$blue_web_a_route_identity" "$blue_web_b_route_identity"
    do
        validate_safe_token "$route_identity" CONTROL_PLANE_MEMBER_ROUTE_IDENTITY
    done
    route_identity_inventory=$(printf '%s\n' "$green_web_a_route_identity" \
        "$green_web_b_route_identity" "$blue_web_a_route_identity" "$blue_web_b_route_identity")
    [ "$(printf '%s\n' "$route_identity_inventory" | LC_ALL=C sort -u | wc -l | tr -d '[:space:]')" = 4 ] \
        || fail 'all planned member route identities must be distinct'
    validate_identifier "$green_pool_label_value" CONTROL_PLANE_GREEN_POOL_LABEL_VALUE
    validate_identifier "$blue_pool_label_value" CONTROL_PLANE_BLUE_POOL_LABEL_VALUE
    [ "$green_pool_label_value" != "$blue_pool_label_value" ] \
        || fail 'green and blue pool label values must be distinct'
    validate_positive_integer "$forward_pool_generation" CONTROL_PLANE_FORWARD_POOL_GENERATION
    validate_identifier "$blue_container" CONTROL_PLANE_BLUE_CONTAINER
    validate_identifier "$green_container" CONTROL_PLANE_GREEN_WEB_A_CONTAINER
    validate_identifier "$green_web_b_container" CONTROL_PLANE_GREEN_WEB_B_CONTAINER
    validate_identifier "$replacement_blue_container" CONTROL_PLANE_BLUE_WEB_A_CONTAINER
    validate_identifier "$replacement_blue_web_b_container" CONTROL_PLANE_BLUE_WEB_B_CONTAINER
    validate_identifier "$proxy_container" CONTROL_PLANE_PROXY_CONTAINER
    validate_identifier "$database_container" CONTROL_PLANE_DATABASE_CONTAINER
    validate_identifier "$database_user" CONTROL_PLANE_DATABASE_USER
    validate_identifier "$database_name" CONTROL_PLANE_DATABASE_NAME
    validate_identifier "$control_plane_network" CONTROL_PLANE_NETWORK
    [ -z "${CONTROL_PLANE_TRUSTED_PROXY_ADDRESSES:-}" ] \
        || fail 'CONTROL_PLANE_TRUSTED_PROXY_ADDRESSES is operator-owned and must not be configured'
    control_plane_trusted_proxy_addresses=$(control_plane_proxy_peer_addresses)
    container_name_inventory=$(printf '%s\n' "$blue_container" "$green_container" \
        "$green_web_b_container" "$replacement_blue_container" \
        "$replacement_blue_web_b_container" "$proxy_container" "$database_container")
    [ "$(printf '%s\n' "$container_name_inventory" | LC_ALL=C sort -u | wc -l | tr -d '[:space:]')" = 7 ] \
        || fail 'legacy, pool-member, proxy, and database container names must be distinct'
    validate_identifier "$green_state_volume" CONTROL_PLANE_GREEN_WEB_A_PRIVATE_VOLUME
    validate_identifier "$green_web_b_private_volume" CONTROL_PLANE_GREEN_WEB_B_PRIVATE_VOLUME
    validate_identifier "$blue_state_volume" CONTROL_PLANE_BLUE_WEB_A_PRIVATE_VOLUME
    validate_identifier "$blue_web_b_private_volume" CONTROL_PLANE_BLUE_WEB_B_PRIVATE_VOLUME
    validate_identifier "$coordination_volume" CONTROL_PLANE_COORDINATION_VOLUME
    volume_inventory=$(printf '%s\n' "$green_state_volume" "$green_web_b_private_volume" \
        "$blue_state_volume" "$blue_web_b_private_volume" "$coordination_volume")
    [ "$(printf '%s\n' "$volume_inventory" | LC_ALL=C sort -u | wc -l | tr -d '[:space:]')" = 5 ] \
        || fail 'all four private authority volumes and the coordination volume must be distinct'
    validate_identifier "$dynamic_filename" CONTROL_PLANE_TRAEFIK_DYNAMIC_FILENAME
    validate_path "$runtime_fence_provisioner" CONTROL_PLANE_RUNTIME_FENCE_PROVISIONER
    validate_sha256 "$runtime_fence_provisioner_sha256" CONTROL_PLANE_RUNTIME_FENCE_PROVISIONER_SHA256
    assert_non_symlink_regular_file "$runtime_fence_provisioner" CONTROL_PLANE_RUNTIME_FENCE_PROVISIONER
    [ -x "$runtime_fence_provisioner" ] || fail 'runtime fence provisioner must be executable'
    [ "$(sha256_file "$runtime_fence_provisioner")" = "$runtime_fence_provisioner_sha256" ] \
        || fail 'runtime fence provisioner differs from its pinned digest'
    if ! is_test_mode; then
        [ "$runtime_fence_provisioner" = "$SCRIPT_DIRECTORY/controllers/provision-runtime-attestation-ssh-fence.sh" ] \
            && [ "$(file_uid "$runtime_fence_provisioner")" = 0 ] \
            && [ "$(file_gid "$runtime_fence_provisioner")" = 0 ] \
            && [ "$(file_mode "$runtime_fence_provisioner")" = 700 ] \
            || fail 'production runtime fence provisioner must be the active release root:root mode 0700 executable'
    fi
    case "$dynamic_filename" in
        *.yaml|*.yml)
            ;;
        *)
            fail 'CONTROL_PLANE_TRAEFIK_DYNAMIC_FILENAME must be YAML'
            ;;
    esac
    validate_host "$control_plane_host" CONTROL_PLANE_HOST
    validate_identifier "$traefik_entrypoint" CONTROL_PLANE_TRAEFIK_ENTRYPOINT
    validate_identifier "$local_ingress_entrypoint" CONTROL_PLANE_LOCAL_INGRESS_ENTRYPOINT
    [ "$local_ingress_entrypoint" != "$traefik_entrypoint" ] \
        || fail 'local and public Traefik entrypoints must be distinct'
    case "$traefik_tls" in
        true|false)
            ;;
        *)
            fail 'CONTROL_PLANE_TRAEFIK_TLS must be true or false'
            ;;
    esac
    if [ "$traefik_tls" = true ]; then
        if [ "$test_mode" != 1 ] || [ -n "$traefik_cert_resolver" ]; then
            traefik_cert_resolver=${traefik_cert_resolver:-letsencrypt}
            validate_identifier "$traefik_cert_resolver" CONTROL_PLANE_TRAEFIK_CERT_RESOLVER
        fi
    elif [ -n "$traefik_cert_resolver" ]; then
        fail 'CONTROL_PLANE_TRAEFIK_CERT_RESOLVER must be empty when TLS is disabled'
    fi
    validate_positive_integer "$traefik_router_priority" CONTROL_PLANE_TRAEFIK_ROUTER_PRIORITY
    validate_port "$backend_port" CONTROL_PLANE_BACKEND_PORT
    validate_port "$green_loopback_port" CONTROL_PLANE_GREEN_WEB_A_LOOPBACK_PORT
    validate_port "$green_web_b_loopback_port" CONTROL_PLANE_GREEN_WEB_B_LOOPBACK_PORT
    validate_port "$blue_loopback_port" CONTROL_PLANE_BLUE_WEB_A_LOOPBACK_PORT
    validate_port "$blue_web_b_loopback_port" CONTROL_PLANE_BLUE_WEB_B_LOOPBACK_PORT
    loopback_port_inventory=$(printf '%s\n' "$green_loopback_port" \
        "$green_web_b_loopback_port" "$blue_loopback_port" "$blue_web_b_loopback_port")
    [ "$(printf '%s\n' "$loopback_port_inventory" | LC_ALL=C sort -u | wc -l | tr -d '[:space:]')" = 4 ] \
        || fail 'all four pool member loopback ports must be distinct'
    validate_port "$database_port" CONTROL_PLANE_DATABASE_PORT
    validate_port "$redis_port" CONTROL_PLANE_REDIS_PORT
    validate_port "$soketi_port" CONTROL_PLANE_SOKETI_PORT
    validate_port "$soketi_metrics_port" CONTROL_PLANE_SOKETI_METRICS_PORT
    validate_host "$database_host" CONTROL_PLANE_DATABASE_HOST
    validate_host "$redis_host" CONTROL_PLANE_REDIS_HOST
    validate_host "$soketi_host" CONTROL_PLANE_SOKETI_HOST
    validate_probe_path "$direct_probe_path"
    validate_positive_integer "$public_probe_attempts" CONTROL_PLANE_PUBLIC_PROBE_ATTEMPTS
    validate_positive_integer "$container_stop_timeout" CONTROL_PLANE_CONTAINER_STOP_TIMEOUT
    validate_positive_integer "$candidate_health_attempts" CONTROL_PLANE_CANDIDATE_HEALTH_ATTEMPTS
    validate_positive_integer "$drain_attempts" CONTROL_PLANE_DRAIN_ATTEMPTS
    validate_positive_integer "$drain_stable_seconds" CONTROL_PLANE_DRAIN_STABLE_SECONDS
    validate_positive_integer "$runtime_fence_probe_max_age_seconds" CONTROL_PLANE_RUNTIME_FENCE_PROBE_MAX_AGE_SECONDS
    validate_positive_integer "$runtime_fence_queue_stable_seconds" CONTROL_PLANE_RUNTIME_FENCE_QUEUE_STABLE_SECONDS
    validate_positive_integer "$s6_wait_milliseconds" CONTROL_PLANE_S6_WAIT_MILLISECONDS
    validate_nonnegative_integer "$secret_uid" CONTROL_PLANE_SECRET_UID
    validate_nonnegative_integer "$secret_gid" CONTROL_PLANE_SECRET_GID
    validate_path "$source_compose_base" CONTROL_PLANE_SOURCE_COMPOSE_BASE
    validate_path "$source_compose_prod" CONTROL_PLANE_SOURCE_COMPOSE_PROD
    validate_path "$source_compose_custom" CONTROL_PLANE_SOURCE_COMPOSE_CUSTOM
    validate_path "$source_compose_postgres" CONTROL_PLANE_SOURCE_COMPOSE_POSTGRES
    validate_path "$proxy_enrollment_compose_override" CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE
    validate_path "$proxy_enrollment_docker_socket" CONTROL_PLANE_PROXY_ENROLLMENT_DOCKER_SOCKET
    validate_path "$source_env_file" CONTROL_PLANE_SOURCE_ENV_FILE
    validate_path "$ssh_directory" CONTROL_PLANE_SSH_DIRECTORY
    validate_path "$applications_directory" CONTROL_PLANE_APPLICATIONS_DIRECTORY
    validate_path "$databases_directory" CONTROL_PLANE_DATABASES_DIRECTORY
    validate_path "$services_directory" CONTROL_PLANE_SERVICES_DIRECTORY
    validate_path "$backups_directory" CONTROL_PLANE_BACKUPS_DIRECTORY
    validate_path "$state_directory" CONTROL_PLANE_OPERATOR_STATE_DIR
    validate_path "$traefik_dynamic_directory" CONTROL_PLANE_TRAEFIK_DYNAMIC_DIR
    assert_non_symlink_regular_file "$source_compose_base" CONTROL_PLANE_SOURCE_COMPOSE_BASE
    assert_non_symlink_regular_file "$source_compose_prod" CONTROL_PLANE_SOURCE_COMPOSE_PROD
    path_presence "$source_compose_custom" >/dev/null
    path_presence "$source_compose_postgres" >/dev/null
    if [ "$(path_presence "$proxy_enrollment_compose_override")" = present ]; then
        assert_non_symlink_regular_file "$proxy_enrollment_compose_override" \
            CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE
        [ "$(file_mode "$proxy_enrollment_compose_override")" = 600 ] \
            || fail 'managed proxy-enrollment compose override must have mode 0600'
        if is_test_mode; then
            [ "$(file_uid "$proxy_enrollment_compose_override")" = "$operator_uid" ] \
                && [ "$(file_gid "$proxy_enrollment_compose_override")" = "$operator_gid" ] \
                || fail 'lab managed proxy-enrollment compose override owner changed'
        else
            [ "$(file_uid "$proxy_enrollment_compose_override")" = 0 ] \
                && [ "$(file_gid "$proxy_enrollment_compose_override")" = 0 ] \
                || fail 'managed proxy-enrollment compose override must be root-owned'
        fi
    fi
    if is_test_mode; then
        if [ -n "$proxy_enrollment_command" ]; then
            validate_path "$proxy_enrollment_command" CONTROL_PLANE_PROXY_ENROLLMENT_COMMAND
            assert_non_symlink_regular_file "$proxy_enrollment_command" \
                CONTROL_PLANE_PROXY_ENROLLMENT_COMMAND
            [ -x "$proxy_enrollment_command" ] \
                && [ "$(file_uid "$proxy_enrollment_command")" = "$operator_uid" ] \
                && [ "$(file_gid "$proxy_enrollment_command")" = "$operator_gid" ] \
                && [ "$(file_mode "$proxy_enrollment_command")" = 700 ] \
                || fail 'lab proxy-enrollment command must be an operator-owned mode 0700 executable'
        fi
    elif [ -n "$proxy_enrollment_command" ]; then
        fail 'CONTROL_PLANE_PROXY_ENROLLMENT_COMMAND is unavailable in production mode'
    fi
    if ! is_test_mode || [ -z "$proxy_enrollment_command" ]; then
        [ -S "$proxy_enrollment_docker_socket" ] && [ ! -L "$proxy_enrollment_docker_socket" ] \
            || fail 'managed proxy-enrollment runner requires a non-symlink Docker socket'
    fi
    assert_non_symlink_regular_file "$source_env_file" CONTROL_PLANE_SOURCE_ENV_FILE
    [ "$(file_mode "$source_env_file")" = 600 ] \
        || fail 'CONTROL_PLANE_SOURCE_ENV_FILE must have mode 0600'
    if is_test_mode; then
        [ "$(file_uid "$source_env_file")" = "$operator_uid" ] \
            && [ "$(file_gid "$source_env_file")" = "$operator_gid" ] \
            || fail 'lab source environment must be owned by the operator UID/GID'
    else
        [ "$(file_uid "$source_env_file")" = 0 ] \
            && [ "$(file_gid "$source_env_file")" = 0 ] \
            || fail 'production source environment must be owned by root:root'
    fi
    if ! source_environment_app_port_value=$(source_environment_app_port "$source_env_file"); then
        fail 'CONTROL_PLANE_SOURCE_ENV_FILE must contain at most one numeric APP_PORT value'
    fi
    if [ -n "$configured_app_port" ]; then
        app_port=$configured_app_port
    elif [ -n "$source_environment_app_port_value" ]; then
        app_port=$source_environment_app_port_value
    else
        app_port=8000
    fi
    validate_port "$app_port" APP_PORT
    derived_local_ingress_url=$(derive_local_ingress_url "$public_probe_url" "$app_port")
    local_ingress_url=${configured_local_ingress_url:-$derived_local_ingress_url}
    [ "$local_ingress_url" = "$derived_local_ingress_url" ] \
        || fail 'CONTROL_PLANE_LOCAL_INGRESS_URL must exactly preserve the public proof path and query on the APP_PORT loopback endpoint'
    [ -d "$ssh_directory" ] && [ ! -L "$ssh_directory" ] \
        || fail 'CONTROL_PLANE_SSH_DIRECTORY must be a non-symlink directory'
    [ -d "$applications_directory" ] && [ ! -L "$applications_directory" ] \
        || fail 'CONTROL_PLANE_APPLICATIONS_DIRECTORY must be a non-symlink directory'
    [ -d "$databases_directory" ] && [ ! -L "$databases_directory" ] \
        || fail 'CONTROL_PLANE_DATABASES_DIRECTORY must be a non-symlink directory'
    [ -d "$services_directory" ] && [ ! -L "$services_directory" ] \
        || fail 'CONTROL_PLANE_SERVICES_DIRECTORY must be a non-symlink directory'
    [ -d "$backups_directory" ] && [ ! -L "$backups_directory" ] \
        || fail 'CONTROL_PLANE_BACKUPS_DIRECTORY must be a non-symlink directory'
    [ -z "${CONTROL_PLANE_RUNTIME_ENV_FILE:-}" ] \
        || fail 'CONTROL_PLANE_RUNTIME_ENV_FILE is operator-owned and must not be configured'
    assert_secret_file_metadata "$green_direct_probe_token_file" \
        CONTROL_PLANE_GREEN_WEB_A_DIRECT_PROBE_RUNTIME_FILE
    assert_secret_file_metadata "$green_applied_ack_file" \
        CONTROL_PLANE_GREEN_WEB_A_APPLIED_ACK_RUNTIME_FILE
    assert_secret_file_metadata "$green_web_b_direct_probe_token_file" \
        CONTROL_PLANE_GREEN_WEB_B_DIRECT_PROBE_RUNTIME_FILE
    assert_secret_file_metadata "$green_web_b_applied_ack_file" \
        CONTROL_PLANE_GREEN_WEB_B_APPLIED_ACK_RUNTIME_FILE
    assert_secret_file_metadata "$green_route_health_token_file" \
        CONTROL_PLANE_GREEN_ROUTE_HEALTH_RUNTIME_FILE
    assert_secret_file_metadata "$green_pool_ack_file" CONTROL_PLANE_GREEN_POOL_ACK_RUNTIME_FILE
    assert_secret_file_metadata "$blue_direct_probe_token_file" \
        CONTROL_PLANE_BLUE_WEB_A_DIRECT_PROBE_RUNTIME_FILE
    assert_secret_file_metadata "$blue_applied_ack_file" CONTROL_PLANE_BLUE_WEB_A_APPLIED_ACK_RUNTIME_FILE
    assert_secret_file_metadata "$blue_web_b_direct_probe_token_file" \
        CONTROL_PLANE_BLUE_WEB_B_DIRECT_PROBE_RUNTIME_FILE
    assert_secret_file_metadata "$blue_web_b_applied_ack_file" \
        CONTROL_PLANE_BLUE_WEB_B_APPLIED_ACK_RUNTIME_FILE
    assert_secret_file_metadata "$blue_route_health_token_file" \
        CONTROL_PLANE_BLUE_ROUTE_HEALTH_RUNTIME_FILE
    assert_secret_file_metadata "$blue_pool_ack_file" CONTROL_PLANE_BLUE_POOL_ACK_RUNTIME_FILE
    pool_secret_digest_inventory=$(for pool_secret_path in \
        "$green_direct_probe_token_file" "$green_applied_ack_file" \
        "$green_web_b_direct_probe_token_file" "$green_web_b_applied_ack_file" \
        "$green_route_health_token_file" "$green_pool_ack_file" \
        "$blue_direct_probe_token_file" "$blue_applied_ack_file" \
        "$blue_web_b_direct_probe_token_file" "$blue_web_b_applied_ack_file" \
        "$blue_route_health_token_file" "$blue_pool_ack_file"
    do
        sha256_file "$pool_secret_path"
    done)
    [ "$(printf '%s\n' "$pool_secret_digest_inventory" | LC_ALL=C sort -u | wc -l | tr -d '[:space:]')" = 12 ] \
        || fail 'all member and pool authorization artifacts must have distinct bytes'
    assert_non_symlink_regular_file "$ingress_controller" CONTROL_PLANE_INGRESS_CONTROLLER
    [ -x "$ingress_controller" ] || fail 'Traefik ingress controller is not executable'
    assert_non_symlink_regular_file "$OPERATOR_PATH" 'executing control-plane operator'
    assert_non_symlink_regular_file "$operator_compose_file" CONTROL_PLANE_OPERATOR_COMPOSE_FILE
    assert_non_symlink_regular_file "$release_rehearsal_compose_file" \
        CONTROL_PLANE_REHEARSAL_COMPOSE_FILE
    assert_non_symlink_regular_file "$rehearsal_compose_file" \
        CONTROL_PLANE_TEST_REHEARSAL_COMPOSE_FILE
    if [ "$rehearsal_compose_file" != "$release_rehearsal_compose_file" ]; then
        is_test_mode \
            || fail 'a migration-rehearsal executor override is unavailable outside the lab'
        require_value CONTROL_PLANE_TEST_REHEARSAL_COMPOSE_FILE_SHA256 \
            "$rehearsal_compose_file_sha256"
        validate_sha256 "$rehearsal_compose_file_sha256" \
            CONTROL_PLANE_TEST_REHEARSAL_COMPOSE_FILE_SHA256
        [ "$(sha256_file "$rehearsal_compose_file")" = "$rehearsal_compose_file_sha256" ] \
            || fail 'migration-rehearsal executor differs from its pinned digest'
    elif [ -n "${CONTROL_PLANE_TEST_REHEARSAL_COMPOSE_FILE_SHA256:-}" ]; then
        fail 'migration-rehearsal executor digest requires an explicit lab executor override'
    fi
    operation_directory="$state_directory/$operation_id"
    state_file="$operation_directory/state"
    if [ -e "$state_file" ] || [ -L "$state_file" ]; then
        assert_persisted_operator_configuration_identity
    fi
    [ -d "$traefik_dynamic_directory" ] || fail 'Traefik dynamic directory does not exist'
    green_applied_acknowledgement=$(cat "$green_applied_ack_file")
    green_web_b_applied_acknowledgement=$(cat "$green_web_b_applied_ack_file")
    green_route_health_token=$(cat "$green_route_health_token_file")
    green_pool_acknowledgement=$(cat "$green_pool_ack_file")
    blue_applied_acknowledgement=$(cat "$blue_applied_ack_file")
    blue_web_b_applied_acknowledgement=$(cat "$blue_web_b_applied_ack_file")
    blue_route_health_token=$(cat "$blue_route_health_token_file")
    blue_pool_acknowledgement=$(cat "$blue_pool_ack_file")
    validate_safe_token "$green_applied_acknowledgement" CONTROL_PLANE_GREEN_WEB_A_APPLIED_ACK_RUNTIME_FILE
    validate_safe_token "$green_web_b_applied_acknowledgement" CONTROL_PLANE_GREEN_WEB_B_APPLIED_ACK_RUNTIME_FILE
    validate_safe_token "$green_route_health_token" CONTROL_PLANE_GREEN_ROUTE_HEALTH_RUNTIME_FILE
    validate_safe_token "$green_pool_acknowledgement" CONTROL_PLANE_GREEN_POOL_ACK_RUNTIME_FILE
    validate_safe_token "$blue_applied_acknowledgement" CONTROL_PLANE_BLUE_WEB_A_APPLIED_ACK_RUNTIME_FILE
    validate_safe_token "$blue_web_b_applied_acknowledgement" CONTROL_PLANE_BLUE_WEB_B_APPLIED_ACK_RUNTIME_FILE
    validate_safe_token "$blue_route_health_token" CONTROL_PLANE_BLUE_ROUTE_HEALTH_RUNTIME_FILE
    validate_safe_token "$blue_pool_acknowledgement" CONTROL_PLANE_BLUE_POOL_ACK_RUNTIME_FILE
    if [ -n "$public_probe_host_header" ]; then
        validate_host "$public_probe_host_header" CONTROL_PLANE_PUBLIC_PROBE_HOST_HEADER
    fi
    if is_test_mode; then
        validate_host "$expected_ipv4" CONTROL_PLANE_EXPECTED_PUBLIC_IPV4
        printf '%s\n' "$expected_ipv4" | grep -Eq '^[0-9]+(\.[0-9]+){3}$' \
            || fail 'CONTROL_PLANE_EXPECTED_PUBLIC_IPV4 must be an IPv4 address'
    else
        validate_globally_routable_ipv4 "$expected_ipv4" CONTROL_PLANE_EXPECTED_PUBLIC_IPV4
    fi

    if ! is_test_mode; then
        assert_runtime_environment_excludes_writer_faults "$source_env_file"
        [ "$direct_probe_path" = /api/control-plane/probe ] \
            || fail 'production direct-origin probe path must be /api/control-plane/probe'
        [ "$source_compose_base" = /data/coolify/source/docker-compose.yml ] \
            && [ "$source_compose_prod" = /data/coolify/source/docker-compose.prod.yml ] \
            && [ "$source_compose_custom" = /data/coolify/source/docker-compose.custom.yml ] \
            && [ "$source_compose_postgres" = /data/coolify/source/docker-compose.postgres-upgrade.yml ] \
            && [ "$proxy_enrollment_compose_override" = /data/coolify/source/docker-compose.control-plane-enrolled.yml ] \
            && [ "$proxy_enrollment_docker_socket" = /var/run/docker.sock ] \
            && [ "$source_env_file" = /data/coolify/source/.env ] \
            || fail 'production source invocation must use the exact ordered /data/coolify/source compose files and .env'
        [ "$operator_compose_file" = "$SCRIPT_DIRECTORY/compose.yaml" ] \
            || fail 'production mode only accepts the bundled operator compose file'
        [ "$release_rehearsal_compose_file" = "$SCRIPT_DIRECTORY/compose.rehearsal.yaml" ] \
            && [ "$rehearsal_compose_file" = "$release_rehearsal_compose_file" ] \
            && [ -z "${CONTROL_PLANE_TEST_REHEARSAL_COMPOSE_FILE:-}" ] \
            || fail 'production mode only accepts the bundled rehearsal compose file'
        case "$public_probe_url" in
            "https://${control_plane_host}/"*)
                ;;
            *)
                fail 'production public probe must use the configured HTTPS control-plane host without credentials'
                ;;
        esac
        [ -z "${CONTROL_PLANE_TEST_CRASH_AT:-}" ] \
            && [ -z "${CONTROL_PLANE_TEST_INVALID_ROUTE:-}" ] \
            && [ -z "${CONTROL_PLANE_TEST_BLUE_STOP_FAILURE:-}" ] \
            && [ -z "${CONTROL_PLANE_TEST_GREEN_PROMOTION_FAILURE:-}" ] \
            && [ -z "${CONTROL_PLANE_TEST_GREEN_PROMOTION_UNPROVEN:-}" ] \
            && [ -z "${CONTROL_PLANE_TEST_GREEN_FENCE_FAILURE:-}" ] \
            && [ -z "${CONTROL_PLANE_TEST_MUTATION_LEASE_RACE:-}" ] \
            || fail 'test fault injection is unavailable outside the lab'
    fi

    assert_immutable_image "$green_image" CONTROL_PLANE_GREEN_IMAGE
    assert_immutable_image "$blue_image" CONTROL_PLANE_BLUE_IMAGE
    if is_test_mode; then
        green_image_digest=$(image_id "$green_image")
        blue_image_digest=$(image_id "$blue_image")
    else
        green_image_digest=$(image_digest_from_reference "$green_image")
        blue_image_digest=$(image_digest_from_reference "$blue_image")
    fi

    mkdir -p "$state_directory"
    migration_artifact_directory="$operation_directory/live-expand-migration-artifact"
    migration_compatibility_artifact_file="$migration_artifact_directory/compatibility"
    migration_manifest_artifact_file="$migration_artifact_directory/manifest"
    migration_baseline_surplus_file="$migration_artifact_directory/immutable-baseline-surplus"
    migration_inventory_fingerprint_file="$migration_artifact_directory/migration-inventory-fingerprint"
    migration_ledger_before_file="$migration_artifact_directory/ledger-before.csv"
    migration_relfilenode_before_file="$migration_artifact_directory/relfilenode-before.csv"
    migration_row_counts_before_file="$migration_artifact_directory/row-counts-before.csv"
    migration_schema_before_file="$migration_artifact_directory/schema-before.csv"
    migration_pending_file="$migration_artifact_directory/pending-migrations"
    migration_database_identity_file="$migration_artifact_directory/live-database-identity"
    migration_verification_file="$migration_artifact_directory/post-verification"
    migration_attempts_directory="$operation_directory/live-expand-migration-attempts"
    source_runtime_artifact_file="$operation_directory/source-runtime.sha256"
    source_rendered_artifact_file="$operation_directory/source-rendered.sha256"
    green_runtime_env_file="$operation_directory/green-runtime.env"
    blue_runtime_env_file="$operation_directory/blue-runtime.env"
    green_restart_policy_intent_file="$operation_directory/green-web-a-restart-policy.intent"
    green_web_b_restart_policy_intent_file="$operation_directory/green-web-b-restart-policy.intent"
    replacement_blue_restart_policy_intent_file="$operation_directory/blue-web-a-restart-policy.intent"
    replacement_blue_web_b_restart_policy_intent_file="$operation_directory/blue-web-b-restart-policy.intent"
    forward_pool_plan_file="$operation_directory/forward-pool-plan.manifest"
    forward_ingress_pool_file="$operation_directory/forward-ingress-pool.manifest"
    forward_route_health_root_file="$operation_directory/forward-route-health-token.root"
    forward_pool_ack_root_file="$operation_directory/forward-pool-ack.root"
    forward_web_a_direct_probe_root_file="$operation_directory/forward-web-a-direct-probe.root"
    forward_web_a_applied_ack_root_file="$operation_directory/forward-web-a-applied-ack.root"
    forward_web_b_direct_probe_root_file="$operation_directory/forward-web-b-direct-probe.root"
    forward_web_b_applied_ack_root_file="$operation_directory/forward-web-b-applied-ack.root"
    reverse_pool_plan_file=none
    reverse_ingress_pool_file=none
    reverse_route_health_root_file=none
    reverse_pool_ack_root_file=none
    reverse_web_a_direct_probe_root_file=none
    reverse_web_a_applied_ack_root_file=none
    reverse_web_b_direct_probe_root_file=none
    reverse_web_b_applied_ack_root_file=none
    green_ack_snapshot_file="$operation_directory/green-applied-ack.snapshot"
    blue_ack_snapshot_file="$operation_directory/blue-applied-ack.snapshot"
    runtime_fence_base_config_file="$operation_directory/runtime-fence-base.env"
    runtime_fence_environment_file="$operation_directory/runtime-fence.env"
    runtime_fence_https_ack_file="$operation_directory/runtime-fence-https-ack"
    runtime_fence_provider_header_copy="$operation_directory/runtime-fence-provider-header"
    rehearsal_runtime_env_artifact_file="$operation_directory/rehearsal-runtime.env"
    rehearsal_compatibility_artifact_file="$operation_directory/rehearsal-compatibility"
    rehearsal_state_file="$operation_directory/migration-rehearsal.state"
    rehearsal_log_file="$operation_directory/migration-rehearsal.log"
    rehearsal_plan_directory="$operation_directory/migration-rehearsal-plan"
    rehearsal_ledger_before_file="$rehearsal_plan_directory/ledger-before.csv"
    rehearsal_schema_before_file="$rehearsal_plan_directory/schema-before.csv"
    rehearsal_pending_file="$rehearsal_plan_directory/pending"
    drain_queue_file="$operation_directory/drain-queues"
}

preflight()
{
    acquire_lock
    if [ -e "$state_file" ]; then
        load_state
    fi
    assert_backup_attestation
    reconcile_proxy_enrollment_before_preflight
    assert_proxy_identity
    assert_proxy_dynamic_mount
    assert_container_running "$blue_container"
    if [ ! -e "$state_file" ]; then
        enroll_proxy_if_needed
        assert_proxy_identity
        assert_proxy_dynamic_mount
        assert_container_running "$blue_container"
    else
        assert_common_state_identity
        assert_blue_state
    fi
    ingress_controller_preflight ingress
    if [ -e "$state_file" ]; then
        complete_forward_runtime_fence
        complete_green_preflight
        return
    fi
    if [ -d "$operation_directory" ] && [ ! -e "$state_file" ]; then
        assert_operation_directory_security
        reset_uncommitted_operation_directory
    fi
    [ ! -e "$operation_directory" ] || fail "operation already exists: $operation_id"
    assert_container_absent "$green_container"
    assert_container_absent "$green_web_b_container"
    assert_container_absent "$replacement_blue_container"
    assert_container_absent "$replacement_blue_web_b_container"
    mkdir "$operation_directory"
    chmod 700 "$operation_directory"
    assert_operation_directory_security
    test_crash after-operation-directory-created

    record_host_release_identity
    state_blue_id=$(container_id "$blue_container")
    state_blue_original_id=$state_blue_id
    state_blue_rollback_replacement_id=none
    state_blue_restore_status=none
    state_blue_image_id=$(container_image_id "$blue_container")
    state_blue_image_reference=$(container_image_reference "$blue_container")
    state_blue_bridge_ip=$(docker inspect --format "{{with index .NetworkSettings.Networks \"${control_plane_network}\"}}{{.IPAddress}}{{end}}" "$blue_container")
    [ -n "$state_blue_bridge_ip" ] || fail 'legacy blue bridge address was not found on the control-plane network'
    state_blue_compose_project=$(container_label "$blue_container" com.docker.compose.project)
    state_blue_compose_service=$(container_label "$blue_container" com.docker.compose.service)
    state_blue_compose_config_files=$(container_label "$blue_container" com.docker.compose.project.config_files)
    [ "$state_blue_compose_project" = "$source_compose_project" ] \
        && [ "$state_blue_compose_service" = "$source_compose_service" ] \
        || fail 'legacy blue compose project/service does not match the exact restore invocation'
    [ "$state_blue_compose_config_files" = "$(ordered_source_compose_paths)" ] \
        || fail 'legacy blue ordered compose paths do not match the exact restore invocation'
    [ "$(source_service_image_reference)" = "$state_blue_image_reference" ] \
        || fail 'legacy blue source compose does not resolve to its running image reference'
    if is_test_mode; then
        state_blue_image_digest=$state_blue_image_id
    else
        state_blue_image_digest=$(container_repository_digest "$blue_container")
        [ -n "$state_blue_image_digest" ] || fail 'legacy blue has no immutable repository digest for identity proof'
    fi
    state_green_id=none
    state_green_web_b_id=none
    state_green_image_id=none
    state_green_web_b_image_id=none
    state_replacement_blue_id=none
    state_replacement_blue_web_b_id=none
    state_replacement_blue_image_id=none
    state_replacement_blue_web_b_image_id=none
    state_proxy_id=$(container_id "$proxy_container")
    state_proxy_image_id=$(container_image_id "$proxy_container")
    record_source_identity
    state_green_plan_image_reference=$green_image
    state_green_plan_image_id=$(image_id "$green_image")
    state_green_plan_network_id=$(network_id "$control_plane_network")
    state_green_container_runtime_sha256=none
    state_green_web_b_container_runtime_sha256=none
    state_green_restart_policy_status=unplanned
    state_green_restart_policy_intent_path=none
    state_green_restart_policy_intent_sha256=none
    state_green_restart_policy_intent_uid=none
    state_green_restart_policy_intent_gid=none
    state_green_restart_policy_intent_mode=none
    state_green_restart_policy_intent_size=none
    state_green_web_b_restart_policy_status=unplanned
    state_green_web_b_restart_policy_intent_path=none
    state_green_web_b_restart_policy_intent_sha256=none
    state_green_web_b_restart_policy_intent_uid=none
    state_green_web_b_restart_policy_intent_gid=none
    state_green_web_b_restart_policy_intent_mode=none
    state_green_web_b_restart_policy_intent_size=none
    state_replacement_blue_plan_image_reference=$blue_image
    state_replacement_blue_plan_image_id=$(image_id "$blue_image")
    state_replacement_blue_plan_network_id=$(network_id "$control_plane_network")
    state_replacement_blue_container_runtime_sha256=none
    state_replacement_blue_web_b_container_runtime_sha256=none
    state_replacement_blue_restart_policy_status=unplanned
    state_replacement_blue_restart_policy_intent_path=none
    state_replacement_blue_restart_policy_intent_sha256=none
    state_replacement_blue_restart_policy_intent_uid=none
    state_replacement_blue_restart_policy_intent_gid=none
    state_replacement_blue_restart_policy_intent_mode=none
    state_replacement_blue_restart_policy_intent_size=none
    state_replacement_blue_web_b_restart_policy_status=unplanned
    state_replacement_blue_web_b_restart_policy_intent_path=none
    state_replacement_blue_web_b_restart_policy_intent_sha256=none
    state_replacement_blue_web_b_restart_policy_intent_uid=none
    state_replacement_blue_web_b_restart_policy_intent_gid=none
    state_replacement_blue_web_b_restart_policy_intent_mode=none
    state_replacement_blue_web_b_restart_policy_intent_size=none
    state_forward_pool_plan_path=none
    state_forward_pool_plan_sha256=none
    state_forward_pool_plan_uid=none
    state_forward_pool_plan_gid=none
    state_forward_pool_plan_mode=none
    state_forward_pool_plan_size=none
    state_forward_ingress_pool_path=none
    state_forward_ingress_pool_sha256=none
    state_forward_ingress_pool_uid=none
    state_forward_ingress_pool_gid=none
    state_forward_ingress_pool_mode=none
    state_forward_ingress_pool_size=none
    state_reverse_pool_plan_path=none
    state_reverse_pool_plan_sha256=none
    state_reverse_pool_plan_uid=none
    state_reverse_pool_plan_gid=none
    state_reverse_pool_plan_mode=none
    state_reverse_pool_plan_size=none
    state_reverse_ingress_pool_path=none
    state_reverse_ingress_pool_sha256=none
    state_reverse_ingress_pool_uid=none
    state_reverse_ingress_pool_gid=none
    state_reverse_ingress_pool_mode=none
    state_reverse_ingress_pool_size=none
    state_routed_recovery_generation=0
    state_routed_recovery_color=none
    state_routed_recovery_status=none
    state_routed_recovery_from_phase=none
    state_reverse_fence_generation=none
    state_reverse_fence_operation_id=none
    state_reverse_fence_provisioner_path=none
    state_reverse_fence_provisioner_sha256=none
    state_reverse_fence_environment_path=none
    state_reverse_fence_environment_uid=none
    state_reverse_fence_environment_gid=none
    state_reverse_fence_environment_mode=none
    state_reverse_fence_environment_size=none
    state_reverse_fence_https_ack_path=none
    state_reverse_fence_https_ack_sha256=none
    state_reverse_fence_https_ack_uid=none
    state_reverse_fence_https_ack_gid=none
    state_reverse_fence_https_ack_mode=none
    state_reverse_fence_https_ack_size=none
    state_reverse_fence_provider_header_path=none
    state_reverse_fence_provider_header_sha256=none
    state_reverse_fence_provider_header_uid=none
    state_reverse_fence_provider_header_gid=none
    state_reverse_fence_provider_header_mode=none
    state_reverse_fence_provider_header_size=none
    state_recovery_abort_generation=0
    state_recovery_abort_from_phase=none
    state_reverse_fence_environment_sha256=none
    prepare_runtime_env_artifacts
    test_crash after-runtime-env-artifacts
    state_green_probe_token_path=$green_direct_probe_token_file
    state_green_probe_token_sha256=$(sha256_file "$green_direct_probe_token_file")
    state_green_probe_token_uid=$(file_uid "$green_direct_probe_token_file")
    state_green_probe_token_gid=$(file_gid "$green_direct_probe_token_file")
    state_green_probe_token_mode=$(file_mode "$green_direct_probe_token_file")
    state_green_probe_token_size=$(file_size "$green_direct_probe_token_file")
    state_blue_probe_token_path=$blue_direct_probe_token_file
    state_blue_probe_token_sha256=$(sha256_file "$blue_direct_probe_token_file")
    state_blue_probe_token_uid=$(file_uid "$blue_direct_probe_token_file")
    state_blue_probe_token_gid=$(file_gid "$blue_direct_probe_token_file")
    state_blue_probe_token_mode=$(file_mode "$blue_direct_probe_token_file")
    state_blue_probe_token_size=$(file_size "$blue_direct_probe_token_file")
    state_ingress_controller_sha256=$(sha256_file "$ingress_controller")
    state_route_target=legacy
    state_https_route_target=legacy
    state_drain_queue_sha256=none
    state_migration_status=not-applied
    state_migration_attempt=0
    state_migration_attempt_state_sha256=none
    state_migration_compatibility_sha256=none
    state_migration_manifest_sha256=none
    state_migration_ledger_before_sha256=none
    state_migration_relfilenode_before_sha256=none
    state_migration_row_counts_before_sha256=none
    state_migration_schema_before_sha256=none
    state_migration_pending_sha256=none
    state_migration_expected_batch=none
    state_live_database_identity_artifact_sha256=none
    state_live_database_identity_sha256=none
    state_live_database_system_identifier=none
    state_migration_verification_sha256=none
    state_migration_image_id=none
    state_migration_image_digest=none
    state_migration_lock_timeout=none
    state_migration_statement_timeout=none
    state_runtime_fence_operation_id="${operation_id}.fwd"
    validate_identifier "$state_runtime_fence_operation_id" 'forward runtime-fence operation ID'
    prepare_forward_pool_plan
    prepare_runtime_fence_artifacts
    state_phase=preflight-started
    write_state "$state_phase"
    complete_forward_runtime_fence
    complete_green_preflight
}

apply_migrations()
{
    acquire_lock
    load_state
    assert_common_state_identity
    assert_blue_state
    assert_green_state
    assert_backup_attestation
    runtime_fence_call verify

    if [ -d "$migration_attempts_directory" ]; then
        operation_attempt_before_history=$state_migration_attempt
        validate_migration_attempt_history
        if [ "$latest_attempt_number" -gt "$operation_attempt_before_history" ]; then
            assert_live_expand_retry_inputs
            [ "$attempt_status" = planned ] \
                || fail 'only a crash-persisted planned migration attempt may lead operation state'
            state_migration_attempt=$attempt_number
            state_migration_attempt_state_sha256=$(sha256_file "$migration_attempt_state_file")
            state_migration_status=running
            state_phase=live-expand-migrations-planned
            write_state "$state_phase"
            migration_blue_quiesced=1
            quiesce_legacy_blue_for_live_expand
            reconcile_live_expand_migration_runner retry \
                || fail 'live-expand migration attempt reached a terminal non-applied state'
            return
        fi
    fi

    case "${state_phase}:${state_migration_status}" in
        preflight-ready:not-applied)
            require_live_expand_migration_configuration
            assert_migration_compatibility
            prepare_live_expand_artifacts
            prepare_live_expand_verification
            test_crash after-migration-artifacts
            ;;
        live-expand-migrations-planned:running|live-expand-migrations-quiescing:running|live-expand-migrations-scheduler-stopped:running|live-expand-migrations-schedule-idle:running|live-expand-migrations-horizon-paused:running|live-expand-migrations-drain-inventory-recorded:running|live-expand-migrations-quiesced:running)
            assert_live_expand_retry_inputs
            migration_blue_quiesced=1
            quiesce_legacy_blue_for_live_expand
            reconcile_live_expand_migration_runner retry \
                || fail 'live-expand migration attempt reached a terminal non-applied state'
            return
            ;;
        live-expand-migrations-running:running|live-expand-migrations-resuming:running|live-expand-migrations-resumed:running)
            assert_live_expand_retry_inputs
            reconcile_live_expand_migration_runner retry \
                || fail 'live-expand migration attempt reached a terminal non-applied state'
            return
            ;;
        live-expand-migrations-failed:failed)
            assert_live_expand_retry_inputs
            reconcile_live_expand_migration_runner retry \
                || fail 'live-expand migration attempt reached a terminal non-applied state'
            ;;
        *)
            fail 'apply-migrations requires a preflight-ready operation or an identity-matched retry'
            ;;
    esac

    assert_control_plane_migration_lock_available
    begin_live_expand_migration_attempt
    migration_blue_quiesced=1
    quiesce_legacy_blue_for_live_expand
    reconcile_live_expand_migration_runner retry \
        || fail 'live-expand migration attempt reached a terminal non-applied state'
}

activate_color_web()
{
    activation_container=$1
    activation_volume=$2
    activation_color=$3
    activation_epoch=$4
    activation_role=${5:-web-a}
    case "$activation_role" in web-a|web-b) ;; *) fail 'web activation role is invalid' ;; esac
    activation_log="$operation_directory/${activation_color}-${activation_role}-web-activation.log"
    activate_marker "$activation_volume" "$activation_color" "$activation_epoch" web-epoch
    docker exec --env CONTROL_PLANE_WEB_ACTIVATION_CONFIRM=route-switch-pending \
        "$activation_container" /usr/local/bin/coolify-entrypoint activate-web \
        > "$activation_log" 2>&1

    [ "$(marker_status "$activation_volume" "$activation_epoch" web-epoch)" = matching ] \
        || fail "$activation_color web activation marker was not persisted exactly"
    docker exec "$activation_container" /usr/local/bin/coolify-entrypoint web-activated \
        || fail "$activation_color application did not acknowledge its web activation fence"
}

recover_cutover_to_legacy()
{
    ingress_controller_restore ingress
    revoke_pool_web_markers_if_safe green
    state_route_target=legacy
    state_https_route_target=legacy
    state_phase=live-expand-migrations-applied
    write_state "$state_phase"
}

cutover()
{
    acquire_lock
    load_state
    assert_common_state_identity
    reconcile_routed_candidate
    if [ -d "$migration_attempts_directory" ]; then
        validate_migration_attempt_history
        if [ "$latest_attempt_number" -gt 0 ]; then
            reconcile_live_expand_migration_runner abort || true
        fi
    fi
    case "$state_phase" in
        live-expand-migrations-applied|green-web-activated|green-https-routed|green-routed)
            ;;
        *)
            fail 'cutover requires a successful live-expand-migrations operation'
            ;;
    esac
    assert_blue_state
    assert_green_state
    assert_backup_attestation
    assert_persisted_live_expand_migration
    runtime_fence_call verify
    direct_origin_probe "$green_container"

    ingress_controller_preflight ingress
    ingress_controller_prepare ingress

    if [ "$state_phase" = live-expand-migrations-applied ]; then
        activate_color_web "$green_container" "$green_state_volume" green "$green_web_epoch" web-a
        activate_color_web "$green_web_b_container" "$green_web_b_private_volume" green \
            "$green_web_b_epoch" web-b
        state_phase=green-web-activated
        write_state "$state_phase"
        test_crash after-green-web-activation
    else
        activate_color_web "$green_container" "$green_state_volume" green "$green_web_epoch" web-a
        activate_color_web "$green_web_b_container" "$green_web_b_private_volume" green \
            "$green_web_b_epoch" web-b
    fi

    observed_mutation_freeze=$(marker_status "$green_state_volume" "$mutation_freeze_epoch" \
        mutation-freeze-epoch)
    case "$observed_mutation_freeze" in
        absent)
            [ "$state_phase" = green-web-activated ] \
                || fail 'green mutation freeze is absent after public routing began'
            activate_marker "$green_state_volume" green "$mutation_freeze_epoch" mutation-freeze-epoch
            ;;
        matching)
            ;;
        *)
            fail 'green mutation freeze identity differs before public routing'
            ;;
    esac
    prove_mutation_freeze_quiesced "$green_container" "$mutation_freeze_epoch"

    if [ "$state_phase" = green-web-activated ]; then
        runtime_fence_call verify
        if ! ingress_controller_switch ingress green "$green_container" "$backend_port" "$green_applied_ack_file"; then
            recover_cutover_to_legacy
            preserve_proxy_enrollment_after_state_creation \
                || fail 'failed green route switch also failed to retain native Traefik enrollment after legacy route recovery'
            fail 'green HTTPS route was not atomically switched and acknowledged'
        fi
        if ! finalize_proxy_enrollment_if_owned; then
            recover_cutover_to_legacy
            preserve_proxy_enrollment_after_state_creation \
                || fail 'failed enrollment finalization also failed to preserve native Traefik ownership after legacy route recovery'
            fail 'green native Traefik route was acknowledged but one-time enrollment finalization failed'
        fi
        state_https_route_target=green
        state_route_target=green
        state_phase=green-routed
        write_state "$state_phase"
        test_crash after-green-ingress-route
    else
        ingress_controller_assert ingress green "$green_container" "$backend_port" "$green_applied_ack_file"
    fi

    note "green-routed-native-traefik operation=$operation_id green=$green_container"
}

rollback_before_green_promotion()
{
    assert_pool_writer_authority green absent

    docker_container_presence "$blue_container"
    if [ "$container_presence" = absent ] || [ "$state_blue_restore_status" = intent ]; then
        restore_legacy_blue
    else
        restart_stopped_legacy_blue_incumbent
        assert_blue_state
    fi
    resume_legacy_blue_background
    ingress_controller_adopt_rollback_owner
    ingress_controller_legacy_restore_status \
        || fail 'legacy rollback crossed the irreversible Traefik ownership boundary; keep green routed and run promote'

    ingress_controller_restore ingress

    revoke_pool_web_markers_if_safe green
    docker_container_presence "$green_container"
    rollback_green_a_presence=$container_presence
    docker_container_presence "$green_web_b_container"
    rollback_green_b_presence=$container_presence
    if [ "$rollback_green_a_presence" = present ]; then
        stop_and_remove_container "$green_container" green-web-a "$state_green_id" \
            "$state_green_image_id"
    fi
    if [ "$rollback_green_b_presence" = present ]; then
        stop_and_remove_container "$green_web_b_container" green-web-b \
            "$state_green_web_b_id" "$state_green_web_b_image_id"
    fi
    assert_container_absent "$green_container"
    assert_container_absent "$green_web_b_container"
    case "$green_writer_member" in
        web-a) rollback_green_writer_volume=$green_state_volume ;;
        web-b) rollback_green_writer_volume=$green_web_b_private_volume ;;
        *) fail 'green writer selection is invalid during legacy rollback' ;;
    esac
    revoke_marker "$rollback_green_writer_volume" green "$writer_epoch" absent
    revoke_marker "$green_state_volume" green "$mutation_freeze_epoch" matching mutation-freeze-epoch

    state_route_target=legacy
    state_https_route_target=legacy
    if [ "$state_blue_rollback_replacement_id" != none ]; then
        [ "$state_blue_id" = "$state_blue_rollback_replacement_id" ] \
            || fail 'rollback replacement blue lineage does not match the active incumbent'
        fence_status=$(runtime_fence_call status)
        fence_phase=$(printf '%s\n' "$fence_status" | tr ' ' '\n' | sed -n 's/^phase=//p')
        case "$fence_phase" in
            active)
                runtime_fence_call adopt-rollback-incumbent "$blue_container"
                ;;
            aborted)
                ;;
            *)
                fail 'runtime fence is neither active nor already aborted during rollback adoption'
                ;;
        esac
    fi
    begin_fence_abort_intent forward
    note "rolled-back-before-writer-promotion operation=$operation_id"
}

remove_exact_green_cleanup_member_if_present()
{
    cleanup_member=$1
    case "$cleanup_member" in
        web-a)
            cleanup_container=$green_container
            cleanup_id=$state_green_id
            cleanup_image_id=$state_green_image_id
            cleanup_runtime_sha256=$state_green_container_runtime_sha256
            ;;
        web-b)
            cleanup_container=$green_web_b_container
            cleanup_id=$state_green_web_b_id
            cleanup_image_id=$state_green_web_b_image_id
            cleanup_runtime_sha256=$state_green_web_b_container_runtime_sha256
            ;;
        *) fail 'green cleanup member is invalid' ;;
    esac
    docker_container_presence "$cleanup_container"
    [ "$container_presence" = present ] || return 0
    assert_container_identity_present "$cleanup_container" "$cleanup_id" "$cleanup_image_id"
    [ "$cleanup_runtime_sha256" != none ] \
        && [ "$(runtime_fence_container_runtime_sha256 "$cleanup_container")" \
            = "$cleanup_runtime_sha256" ] \
        || fail "green $cleanup_member cleanup runtime differs from its recorded identity"
    stop_and_remove_container "$cleanup_container" "green-$cleanup_member" \
        "$cleanup_id" "$cleanup_image_id"
}

adopt_green_cleanup_member_identity_if_required()
{
    cleanup_member=$1
    case "$cleanup_member" in
        web-a)
            cleanup_container=$green_container
            cleanup_id=$state_green_id
            cleanup_image_id=$state_green_image_id
            cleanup_runtime_sha256=$state_green_container_runtime_sha256
            ;;
        web-b)
            cleanup_container=$green_web_b_container
            cleanup_id=$state_green_web_b_id
            cleanup_image_id=$state_green_web_b_image_id
            cleanup_runtime_sha256=$state_green_web_b_container_runtime_sha256
            ;;
        *) fail 'green cleanup member is invalid' ;;
    esac
    docker_container_presence "$cleanup_container"
    [ "$container_presence" = present ] || return 0
    if [ "$cleanup_id" != none ] && [ "$cleanup_image_id" != none ] \
        && [ "$cleanup_runtime_sha256" != none ]; then
        return 0
    fi

    runtime_fence_call assert-pool-baseline
    case "$cleanup_member" in
        web-a)
            assert_pool_member_runtime "$green_container" "$green_image" \
                "$green_state_volume" "$green_loopback_port" green web-a \
                "$green_web_a_route_identity" "$green_web_epoch" \
                "$green_web_a_route_drain_epoch" "$green_direct_probe_token_file" \
                "$green_applied_ack_file"
            ;;
        web-b)
            assert_pool_member_runtime "$green_web_b_container" "$green_image" \
                "$green_web_b_private_volume" "$green_web_b_loopback_port" green web-b \
                "$green_web_b_route_identity" "$green_web_b_epoch" \
                "$green_web_b_route_drain_epoch" "$green_web_b_direct_probe_token_file" \
                "$green_web_b_applied_ack_file"
            ;;
    esac
    cleanup_observed_id=$(container_id "$cleanup_container")
    cleanup_observed_image_id=$(container_image_id "$cleanup_container")
    cleanup_observed_runtime_sha256=$(runtime_fence_container_runtime_sha256 \
        "$cleanup_container")
    [ "$cleanup_observed_image_id" = "$state_green_plan_image_id" ] \
        || fail "green $cleanup_member cleanup image differs from its immutable plan"
    case "$cleanup_id" in
        none|"$cleanup_observed_id") ;;
        *) fail "green $cleanup_member cleanup container identity differs" ;;
    esac
    case "$cleanup_image_id" in
        none|"$cleanup_observed_image_id") ;;
        *) fail "green $cleanup_member cleanup image identity differs" ;;
    esac
    case "$cleanup_runtime_sha256" in
        none|"$cleanup_observed_runtime_sha256") ;;
        *) fail "green $cleanup_member cleanup runtime identity differs" ;;
    esac
    case "$cleanup_member" in
        web-a)
            state_green_id=$cleanup_observed_id
            state_green_image_id=$cleanup_observed_image_id
            state_green_container_runtime_sha256=$cleanup_observed_runtime_sha256
            ;;
        web-b)
            state_green_web_b_id=$cleanup_observed_id
            state_green_web_b_image_id=$cleanup_observed_image_id
            state_green_web_b_container_runtime_sha256=$cleanup_observed_runtime_sha256
            ;;
    esac
    write_state "$state_phase"
}

complete_forward_candidate_cleanup()
{
    [ "$state_phase" = forward-candidate-cleanup-intent ] \
        || fail 'forward candidate cleanup requires its durable intent'
    remove_exact_green_cleanup_member_if_present web-a
    test_crash after-forward-cleanup-web-a-remove
    remove_exact_green_cleanup_member_if_present web-b
    assert_container_absent "$green_container"
    assert_container_absent "$green_web_b_container"
    select_pool_writer_context green
    revoke_marker "$writer_volume" green "$writer_epoch_value" absent
    revoke_pool_web_markers_if_safe green
    assert_pool_web_markers green absent
    revoke_marker "$green_state_volume" green "$mutation_freeze_epoch" absent mutation-freeze-epoch
    assert_green_pool_volumes_reusable
    begin_fence_abort_intent forward
}

complete_recovery_abort_candidate_cleanup()
{
    remove_exact_green_cleanup_member_if_present web-a
    test_crash after-recovery-abort-green-web-a-remove
    remove_exact_green_cleanup_member_if_present web-b
    assert_container_absent "$green_container"
    assert_container_absent "$green_web_b_container"
}

rollback_reverse_failback_before_release()
{
    case "$state_phase" in
        reverse-fence-artifacts-preparing)
            prepare_reverse_runtime_fence_artifacts
            state_phase=reverse-fence-prepare-intent
            write_state "$state_phase"
            ;;
    esac
    case "$state_phase" in
        reverse-fence-prepare-intent|reverse-fence-prepared|reverse-fence-captured|reverse-fence-armed)
            complete_reverse_runtime_fence
            ;;
        reverse-fence-release-intent|reverse-fence-released|blue-writer-promoting|blue-writer-promoted-freeze-active|blue-promoted-finalizing|blue-writer-promoted)
            fail 'reverse runtime fence release may have succeeded; reverse rollback is forbidden'
            ;;
    esac
    reverse_rollback_from_phase=$state_phase

    docker_container_presence "$green_container"
    rollback_green_a_presence=$container_presence
    rollback_green_a_running=$container_presence_running
    docker_container_presence "$green_web_b_container"
    rollback_green_b_presence=$container_presence
    rollback_green_b_running=$container_presence_running
    [ "$rollback_green_a_presence:$rollback_green_b_presence" = present:present ] \
        || fail 'reverse rollback cannot restore a partially absent exact green pool'
    if [ "$rollback_green_a_running" != true ]; then
        assert_container_identity_present "$green_container" "$state_green_id" "$state_green_image_id"
        docker start "$state_green_id" \
            > "$operation_directory/reverse-rollback-green-web-a-restart.log" 2>&1
    fi
    if [ "$rollback_green_b_running" != true ]; then
        assert_container_identity_present "$green_web_b_container" "$state_green_web_b_id" \
            "$state_green_web_b_image_id"
        docker start "$state_green_web_b_id" \
            > "$operation_directory/reverse-rollback-green-web-b-restart.log" 2>&1
    fi
    assert_green_state
    assert_pool_web_markers green matching
    reverse_runtime_fence_call verify
    state_phase=reverse-rollback-routing-green
    write_state "$state_phase"
    case "$reverse_rollback_from_phase" in
        failback-blue-https-routing|failback-blue-https-routed|blue-failback-routed|\
        failback-green-*|failback-blue-final-*|reverse-rollback-routing-green)
            ingress_controller_restore_reverse_generation ingress "$green_container" \
                "$backend_port" "$green_applied_ack_file"
            ;;
        reverse-rollback-green-routed)
            ;;
        *)
            [ "$state_https_route_target" = green ] \
                || fail 'reverse rollback found changed ingress before controller preparation'
            ;;
    esac
    ingress_controller_assert ingress green "$green_container" "$backend_port" "$green_applied_ack_file"
    state_route_target=green
    state_https_route_target=green
    state_phase=reverse-rollback-green-routed
    write_state "$state_phase"

    promote_selected_writer green
    assert_pool_writer_authority green matching
    docker_container_presence "$replacement_blue_container"
    reverse_rollback_web_a_presence=$container_presence
    docker_container_presence "$replacement_blue_web_b_container"
    reverse_rollback_web_b_presence=$container_presence
    if [ "$reverse_rollback_web_a_presence:$reverse_rollback_web_b_presence" = present:present ]; then
        if [ "$state_replacement_blue_id" = none ]; then
            assert_candidate_pool_runtime blue
            state_replacement_blue_id=$(container_id "$replacement_blue_container")
            state_replacement_blue_image_id=$(container_image_id "$replacement_blue_container")
            state_replacement_blue_web_b_id=$(container_id "$replacement_blue_web_b_container")
            state_replacement_blue_web_b_image_id=$(container_image_id \
                "$replacement_blue_web_b_container")
            write_state "$state_phase"
        fi
    elif [ "$reverse_rollback_web_a_presence:$reverse_rollback_web_b_presence" != absent:absent ]; then
        [ "$state_replacement_blue_id" != none ] \
            && [ "$state_replacement_blue_web_b_id" != none ] \
            || fail 'reverse rollback cannot identify a partially created replacement-blue pool'
    fi
    observed_blue_web_a_marker=$(marker_status "$blue_state_volume" "$blue_web_epoch" web-epoch)
    observed_blue_web_b_marker=$(marker_status "$blue_web_b_private_volume" \
        "$blue_web_b_epoch" web-epoch)
    case "$observed_blue_web_a_marker" in
        absent) ;;
        matching)
            revoke_marker "$blue_state_volume" blue-web-a "$blue_web_epoch" matching web-epoch
            ;;
        *) fail 'replacement blue web-a marker is unsafe during reverse rollback' ;;
    esac
    case "$observed_blue_web_b_marker" in
        absent) ;;
        matching)
            revoke_marker "$blue_web_b_private_volume" blue-web-b "$blue_web_b_epoch" matching \
                web-epoch
            ;;
        *) fail 'replacement blue web-b marker is unsafe during reverse rollback' ;;
    esac
    if [ "$reverse_rollback_web_a_presence" = present ]; then
        stop_and_remove_container "$replacement_blue_container" blue \
            "$state_replacement_blue_id" "$state_replacement_blue_image_id"
    fi
    if [ "$reverse_rollback_web_b_presence" = present ]; then
        stop_and_remove_container "$replacement_blue_web_b_container" blue-web-b \
            "$state_replacement_blue_web_b_id" "$state_replacement_blue_web_b_image_id"
    fi
    case "$blue_writer_member" in
        web-a) rollback_blue_writer_volume=$blue_state_volume ;;
        web-b) rollback_blue_writer_volume=$blue_web_b_private_volume ;;
        *) fail 'replacement blue writer selection is invalid during reverse rollback' ;;
    esac
    revoke_marker "$rollback_blue_writer_volume" blue "$blue_writer_epoch" absent
    observed_reverse_freeze=$(marker_status "$blue_state_volume" "$reverse_mutation_freeze_epoch" \
        mutation-freeze-epoch)
    case "$observed_reverse_freeze" in
        absent)
            ;;
        matching)
            revoke_marker "$blue_state_volume" blue "$reverse_mutation_freeze_epoch" matching \
                mutation-freeze-epoch
            ;;
        *)
            fail 'replacement blue mutation freeze is unsafe to revoke during reverse rollback'
            ;;
    esac
    assert_container_absent "$replacement_blue_container"
    assert_container_absent "$replacement_blue_web_b_container"
    begin_fence_abort_intent reverse
}

abort_failback()
{
    acquire_lock
    load_state
    assert_common_state_identity
    [ "$state_phase" = reverse-fence-abort-intent ] || reconcile_routed_candidate

    case "$state_phase" in
        reverse-fence-abort-intent)
            converge_fence_abort_intent reverse
            ;;
        forward-candidate-cleanup-intent)
            complete_forward_candidate_cleanup
            ;;
        reverse-fence-artifacts-preparing|reverse-fence-prepare-intent|reverse-fence-prepared|reverse-fence-captured|reverse-fence-armed|reverse-fence-active|failback-blue-starting|failback-blue-started-unproven|failback-blue-started|failback-blue-web-activating|failback-blue-web-activated|failback-blue-https-routing|failback-blue-https-routed|blue-failback-routed|failback-green-scheduler-stopping|failback-green-scheduler-stopped|failback-green-horizon-pausing|failback-green-horizon-paused|failback-green-drain-inventory-recording|failback-green-drain-inventory-recorded|failback-green-background-drain-first|failback-green-background-zero-first|failback-green-background-zero-proven|failback-green-horizon-stopping|failback-green-horizon-stopped|failback-green-nightwatch-stopping|failback-green-nightwatch-stopped|failback-green-http-drain-first|failback-green-http-zero-first|failback-green-drained|failback-blue-final-ingress-verifying|failback-blue-final-ingress-acknowledged|reverse-rollback-routing-green|reverse-rollback-green-routed)
            rollback_reverse_failback_before_release
            ;;
        failback-green-revoking)
            docker_container_presence "$green_container"
            abort_green_a_presence=$container_presence
            docker_container_presence "$green_web_b_container"
            abort_green_b_presence=$container_presence
            if [ "$abort_green_a_presence:$abort_green_b_presence" = present:present ]; then
                rollback_reverse_failback_before_release
            else
                fail 'green incumbent pool was partially or fully removed; use recover-reverse-forward'
            fi
            ;;
        failback-green-revoked|reverse-proxy-mutation-freeze-activating|reverse-proxy-mutation-freeze-active|reverse-fence-release-intent|reverse-fence-released|blue-writer-promoting|blue-writer-promoted-freeze-active|blue-promoted-finalizing|blue-writer-promoted)
            fail 'green incumbent is irrevocably removed; use recover-reverse-forward'
            ;;
        green-writer-promoted)
            fail 'no reverse failback is active'
            ;;
        *)
            fail "abort-failback cannot reconcile phase: $state_phase"
            ;;
    esac
}

controlled_failback_to_blue()
{
    reconcile_routed_candidate

    case "$state_phase" in
        green-writer-promoted)
            assert_green_state
            assert_pool_web_markers green matching
            assert_pool_writer_authority green matching
            ingress_controller_assert ingress green "$green_container" "$backend_port" "$green_applied_ack_file"
            assert_container_absent "$blue_container"
            assert_container_absent "$replacement_blue_container"
            assert_container_absent "$replacement_blue_web_b_container"
            assert_ingress_v2_capability_for_target
            allocate_reverse_runtime_fence_generation
            ;;
    esac

    case "$state_phase" in
        reverse-fence-artifacts-preparing)
            assert_green_state
            assert_pool_web_markers green matching
            assert_pool_writer_authority green matching
            ingress_controller_assert ingress green "$green_container" "$backend_port" "$green_applied_ack_file"
            assert_container_absent "$blue_container"
            assert_container_absent "$replacement_blue_container"
            assert_container_absent "$replacement_blue_web_b_container"
            assert_ingress_v2_capability_for_target
            assert_fresh_marker_volume "$blue_state_volume" blue-web-a "$blue_writer_epoch"
            assert_fresh_marker_volume "$blue_state_volume" blue "$blue_web_epoch" web-epoch
            assert_fresh_marker_volume "$blue_state_volume" blue-web-a \
                "$blue_web_a_route_drain_epoch" route-drain-epoch
            assert_fresh_marker_volume "$blue_web_b_private_volume" blue-web-b \
                "$blue_writer_epoch"
            assert_fresh_marker_volume "$blue_web_b_private_volume" blue-web-b \
                "$blue_web_b_epoch" web-epoch
            assert_fresh_marker_volume "$blue_web_b_private_volume" blue-web-b \
                "$blue_web_b_route_drain_epoch" route-drain-epoch
            assert_fresh_marker_volume "$blue_state_volume" blue \
                "$reverse_mutation_freeze_epoch" \
                mutation-freeze-epoch
            prepare_reverse_runtime_fence_artifacts
            state_phase=reverse-fence-prepare-intent
            write_state "$state_phase"
            test_crash after-reverse-fence-prepare-intent
            ;;
    esac

    case "$state_phase" in
        reverse-fence-prepare-intent|reverse-fence-prepared|reverse-fence-captured|reverse-fence-armed|reverse-fence-active)
            assert_green_state
            assert_container_absent "$replacement_blue_container"
            assert_container_absent "$replacement_blue_web_b_container"
            complete_reverse_runtime_fence
            ;;
    esac

    case "$state_phase" in
        reverse-fence-active)
            reverse_runtime_fence_call verify
            state_phase=failback-blue-starting
            write_state "$state_phase"
            test_crash after-failback-blue-start-intent
            ;;
    esac

    case "$state_phase" in
        failback-blue-starting)
            assert_green_state
            reverse_runtime_fence_call verify
            docker_container_presence "$replacement_blue_container"
            failback_web_a_presence=$container_presence
            docker_container_presence "$replacement_blue_web_b_container"
            failback_web_b_presence=$container_presence
            if [ "$failback_web_a_presence:$failback_web_b_presence" = absent:absent ]; then
                start_replacement_blue
            elif [ "$failback_web_a_presence:$failback_web_b_presence" = present:present ]; then
                assert_candidate_pool_runtime blue
            else
                fail 'failback start recovery found a partial replacement-blue pool'
            fi
            state_replacement_blue_id=$(container_id "$replacement_blue_container")
            state_replacement_blue_image_id=$(container_image_id "$replacement_blue_container")
            state_replacement_blue_web_b_id=$(container_id "$replacement_blue_web_b_container")
            state_replacement_blue_web_b_image_id=$(container_image_id \
                "$replacement_blue_web_b_container")
            state_phase=failback-blue-started-unproven
            write_state "$state_phase"
            test_crash after-failback-blue-started
            ;;
    esac

    case "$state_phase" in
        failback-blue-started-unproven)
            assert_green_state
            assert_replacement_blue_state
            reverse_runtime_fence_call verify
            reverse_runtime_fence_call prove-pool-denied
            state_replacement_blue_container_runtime_sha256=$(runtime_fence_container_runtime_sha256 "$replacement_blue_container")
            state_replacement_blue_web_b_container_runtime_sha256=$(runtime_fence_container_runtime_sha256 \
                "$replacement_blue_web_b_container")
            prepare_reverse_ingress_pool
            reverse_runtime_fence_call pin-ingress-pool-manifest \
                "$state_reverse_ingress_pool_sha256"
            state_phase=failback-blue-started
            write_state "$state_phase"
            ;;
    esac

    case "$state_phase" in
        failback-blue-started|failback-blue-web-activating)
            assert_green_state
            reconcile_replacement_blue_state
            ensure_candidate_restart_policy_always blue
            reverse_runtime_fence_call verify
            direct_origin_probe "$replacement_blue_container"
            direct_origin_probe "$replacement_blue_web_b_container"
            ingress_controller_prepare_managed ingress blue "$replacement_blue_container" \
                "$backend_port" "$blue_applied_ack_file"
            state_phase=failback-blue-web-activating
            write_state "$state_phase"
            activate_color_web "$replacement_blue_container" "$blue_state_volume" blue \
                "$blue_web_epoch" web-a
            activate_color_web "$replacement_blue_web_b_container" \
                "$blue_web_b_private_volume" blue "$blue_web_b_epoch" web-b
            state_phase=failback-blue-web-activated
            write_state "$state_phase"
            test_crash after-failback-blue-web-activation
            ;;
    esac

    case "$state_phase" in
        failback-blue-web-activated|failback-blue-https-routing)
            observed_reverse_freeze=$(marker_status "$blue_state_volume" \
                "$reverse_mutation_freeze_epoch" mutation-freeze-epoch)
            case "$observed_reverse_freeze" in
                absent)
                    [ "$state_phase" = failback-blue-web-activated ] \
                        || fail 'replacement blue mutation freeze is absent after public routing began'
                    activate_marker "$blue_state_volume" blue "$reverse_mutation_freeze_epoch" \
                        mutation-freeze-epoch
                    ;;
                matching)
                    ;;
                *)
                    fail 'replacement blue mutation freeze identity differs before public routing'
                    ;;
            esac
            prove_mutation_freeze_quiesced "$replacement_blue_container" \
                "$reverse_mutation_freeze_epoch"
            state_phase=failback-blue-https-routing
            write_state "$state_phase"
            reverse_runtime_fence_call verify
            if ! ingress_controller_switch ingress blue "$replacement_blue_container" "$backend_port" "$blue_applied_ack_file"; then
                rollback_reverse_failback_before_release
                fail 'replacement blue HTTPS route was not acknowledged; green remained public'
            fi
            state_https_route_target=blue
            state_route_target=blue
            state_phase=blue-failback-routed
            write_state "$state_phase"
            test_crash after-failback-blue-ingress-route
            ;;
    esac

    case "$state_phase" in
        blue-failback-routed|failback-green-scheduler-stopping|failback-green-scheduler-stopped|failback-green-horizon-pausing|failback-green-horizon-paused|failback-green-drain-inventory-recording|failback-green-drain-inventory-recorded|failback-green-background-drain-first|failback-green-background-zero-first|failback-green-background-zero-proven|failback-green-horizon-stopping|failback-green-horizon-stopped|failback-green-nightwatch-stopping|failback-green-nightwatch-stopped|failback-green-http-drain-first|failback-green-http-zero-first)
            assert_green_state
            reconcile_replacement_blue_state
            reverse_runtime_fence_call verify
            ingress_controller_assert ingress blue "$replacement_blue_container" "$backend_port" "$blue_applied_ack_file"
            select_pool_writer_context green
            drain_color "$writer_container" failback-green blue-failback-routed
            [ "$state_phase" != failback-green-drained ] || assert_pool_http_drained green
            ;;
    esac

    case "$state_phase" in
        failback-green-drained|failback-blue-final-ingress-verifying|failback-blue-final-ingress-acknowledged|failback-green-revoking)
            state_phase=failback-blue-final-ingress-verifying
            write_state "$state_phase"
            assert_common_state_identity
            reconcile_replacement_blue_state
            assert_green_state
            reverse_runtime_fence_call verify
            ingress_controller_verify_ack ingress blue "$replacement_blue_container" "$backend_port" "$blue_applied_ack_file"
            reconcile_replacement_blue_state
            assert_green_state
            assert_replacement_blue_state
            assert_green_state
            reverse_runtime_fence_call verify
            reverse_runtime_fence_call prove-pool-denied
            assert_pool_http_drained green
            state_phase=failback-blue-final-ingress-acknowledged
            write_state "$state_phase"
            test_crash after-failback-blue-final-ingress-ack
            state_phase=failback-green-revoking
            write_state "$state_phase"
            test_crash after-failback-green-revoke-intent
            assert_green_state
            stop_and_remove_container "$green_container" green-web-a \
                "$state_green_id" "$state_green_image_id"
            stop_and_remove_container "$green_web_b_container" green-web-b \
                "$state_green_web_b_id" "$state_green_web_b_image_id"
            assert_container_absent "$green_container"
            assert_container_absent "$green_web_b_container"
            select_pool_writer_context green
            revoke_marker "$writer_volume" green "$writer_epoch_value" matching
            revoke_marker "$green_state_volume" green "$green_web_epoch" matching web-epoch
            revoke_marker "$green_web_b_private_volume" green-web-b "$green_web_b_epoch" \
                matching web-epoch
            state_phase=failback-green-revoked
            write_state "$state_phase"
            reverse_runtime_fence_call verify-post-revoke
            test_crash after-failback-green-revoked
            ;;
    esac

    case "$state_phase" in
        failback-green-revoked|reverse-proxy-mutation-freeze-activating)
            reverse_runtime_fence_call verify-post-revoke
            state_phase=reverse-proxy-mutation-freeze-activating
            write_state "$state_phase"
            activate_marker "$blue_state_volume" blue "$reverse_mutation_freeze_epoch" \
                mutation-freeze-epoch
            prove_mutation_freeze_quiesced "$replacement_blue_container" \
                "$reverse_mutation_freeze_epoch"
            state_phase=reverse-proxy-mutation-freeze-active
            write_state "$state_phase"
            test_crash after-reverse-proxy-mutation-freeze
            ;;
    esac

    case "$state_phase" in
        reverse-proxy-mutation-freeze-active)
            reverse_runtime_fence_call verify-post-revoke
            [ "$(marker_status "$blue_state_volume" "$reverse_mutation_freeze_epoch" \
                mutation-freeze-epoch)" = matching ] \
                || fail 'reverse proxy mutation freeze is not durable before runtime-fence release'
            prove_mutation_freeze_quiesced "$replacement_blue_container" \
                "$reverse_mutation_freeze_epoch"
            state_phase=reverse-fence-release-intent
            write_state "$state_phase"
            test_crash after-reverse-runtime-fence-release-intent
            ;;
    esac

    case "$state_phase" in
        reverse-fence-release-intent)
            reverse_runtime_fence_call release
            state_phase=reverse-fence-released
            write_state "$state_phase"
            test_crash after-reverse-runtime-fence-release
            ;;
    esac

    case "$state_phase" in
        reverse-fence-released|blue-writer-promoting)
            state_phase=blue-writer-promoting
            write_state "$state_phase"
            assert_pool_web_markers blue matching
            promote_selected_writer blue
            test_crash after-blue-failback-marker
            state_phase=blue-writer-promoted-freeze-active
            write_state "$state_phase"
            ;;
    esac

    case "$state_phase" in
        blue-writer-promoted-freeze-active)
            assert_pool_web_markers blue matching
            promote_selected_writer blue
            revoke_marker "$blue_state_volume" blue "$reverse_mutation_freeze_epoch" matching \
                mutation-freeze-epoch
            state_phase=blue-promoted-finalizing
            write_state "$state_phase"
            ;;
    esac

    case "$state_phase" in
        blue-promoted-finalizing)
            [ "$(marker_status "$blue_state_volume" "$reverse_mutation_freeze_epoch" \
                mutation-freeze-epoch)" = absent ] \
                || fail 'reverse proxy mutation freeze remained active during release finalization'
            reverse_runtime_fence_call finalize-release
            state_phase=blue-writer-promoted
            write_state "$state_phase"
            ;;
        blue-writer-promoted)
            [ "$(marker_status "$blue_state_volume" "$reverse_mutation_freeze_epoch" \
                mutation-freeze-epoch)" = absent ] \
                || fail 'terminal blue promotion retained the reverse proxy mutation freeze'
            assert_pool_web_markers blue matching
            promote_selected_writer blue
            ;;
        *)
            fail "controlled failback cannot reconcile phase: $state_phase"
            ;;
    esac

    note "blue-writer-promoted operation=$operation_id fresh-epoch=$blue_writer_epoch"
}

control_s6_service_down()
{
    service_container=$1
    service_name=$2

    if is_test_mode; then
        docker exec "$service_container" /usr/local/bin/control-plane-lab-service down "$service_name"
        return
    fi

    docker exec --user 0 --env CONTROL_PLANE_S6_WAIT_MILLISECONDS="$s6_wait_milliseconds" \
        "$service_container" /bin/sh -ec '
            service="/run/service/$1"
            test -d "$service"
            : > "$service/down"
            /command/s6-svc -d -wD -T "$CONTROL_PLANE_S6_WAIT_MILLISECONDS" "$service"
            /command/s6-svstat -d "$service" >/dev/null
        ' sh "$service_name" >/dev/null
}

container_config_boolean()
{
    boolean_container=$1
    boolean_setting=$2
    configured_boolean=$(docker inspect "$boolean_container" \
        | jq --exit-status --raw-output --arg setting "$boolean_setting" '
            [.[0].Config.Env[] | select(startswith($setting + "="))] as $matches
            | if ($matches | length) == 1
                then $matches[0] | ltrimstr($setting + "=")
                else error("configured boolean is absent or duplicated")
              end
        ') || fail "legacy service boolean is absent or duplicated: $boolean_setting"
    case "$configured_boolean" in
        true|false)
            printf '%s\n' "$configured_boolean"
            ;;
        *)
            fail "legacy service boolean is not exactly true or false: $boolean_setting"
            ;;
    esac
}

resume_legacy_blue_background()
{
    horizon_enabled=$(container_config_boolean "$blue_container" HORIZON_ENABLED)
    scheduler_enabled=$(container_config_boolean "$blue_container" SCHEDULER_ENABLED)
    nightwatch_enabled=$(container_config_boolean "$blue_container" NIGHTWATCH_ENABLED)

    while IFS='|' read -r service_name service_enabled; do
        if is_test_mode; then
            service_action=down
            [ "$service_enabled" != true ] || service_action=up
            docker exec "$blue_container" /usr/local/bin/control-plane-lab-service \
                "$service_action" "$service_name"
        elif [ "$service_enabled" = true ]; then
            docker exec --user 0 "$blue_container" /bin/sh -ec '
                service_directory="/run/service/$1"
                test -d "$service_directory"
                rm -f "$service_directory/down"
                /command/s6-svc -r "$service_directory"
                /command/s6-svstat -u "$service_directory" >/dev/null
            ' sh "$service_name"
        else
            docker exec --user 0 "$blue_container" /bin/sh -ec '
                service_directory="/run/service/$1"
                test -d "$service_directory"
                : > "$service_directory/down"
                /command/s6-svc -d -wD -T "$2" "$service_directory"
                /command/s6-svstat -d "$service_directory" >/dev/null
            ' sh "$service_name" "$s6_wait_milliseconds"
        fi
    done <<EOF
horizon|$horizon_enabled
scheduler-worker|$scheduler_enabled
nightwatch-agent|$nightwatch_enabled
EOF

    [ "$horizon_enabled" != true ] || {
        if is_test_mode; then
            docker exec "$blue_container" /usr/local/bin/control-plane-lab-service \
                continue horizon
        else
            docker exec "$blue_container" php /var/www/html/artisan horizon:continue \
                >/dev/null
        fi
    }
}

pause_horizon()
{
    horizon_container=$1

    if is_test_mode; then
        docker exec "$horizon_container" /usr/local/bin/control-plane-lab-service pause horizon
    else
        docker exec "$horizon_container" php /var/www/html/artisan horizon:pause >/dev/null
    fi
    assert_horizon_paused "$horizon_container"
}

assert_horizon_paused()
{
    horizon_container=$1

    if is_test_mode; then
        [ "$(docker exec "$horizon_container" /usr/local/bin/control-plane-lab-service service-state horizon)" = paused ] \
            || fail 'Horizon did not enter its paused state'
    else
        horizon_status=0
        docker exec "$horizon_container" php /var/www/html/artisan horizon:status \
            >/dev/null 2>&1 || horizon_status=$?
        [ "$horizon_status" -eq 1 ] || fail 'Horizon did not report its paused state'
    fi
}

assert_horizon_stopped()
{
    horizon_container=$1

    if is_test_mode; then
        [ "$(docker exec "$horizon_container" /usr/local/bin/control-plane-lab-service service-state horizon)" = down ] \
            || fail 'Horizon did not enter its stopped state'
        return
    fi

    docker exec --user 0 "$horizon_container" /command/s6-svstat -d /run/service/horizon >/dev/null \
        || fail 'Horizon supervision remained up after termination'
    docker exec "$horizon_container" /bin/sh -ec '
        ! ps -eo args | grep -E "artisan horizon($|:)|horizon:work" | grep -v grep >/dev/null
    ' || fail 'Horizon worker processes remained after termination'
}

terminate_horizon()
{
    horizon_container=$1

    if is_test_mode; then
        docker exec "$horizon_container" /usr/local/bin/control-plane-lab-service terminate horizon
        assert_horizon_stopped "$horizon_container"
        return
    fi

    docker exec --user 0 "$horizon_container" /bin/sh -ec '
        test -d /run/service/horizon
        : > /run/service/horizon/down
    '
    docker exec "$horizon_container" php /var/www/html/artisan horizon:terminate >/dev/null

    attempt=0
    until docker exec --user 0 "$horizon_container" /command/s6-svstat -d \
        /run/service/horizon >/dev/null 2>&1; do
        attempt=$((attempt + 1))
        [ "$attempt" -lt "$drain_attempts" ] \
            || fail 'Horizon did not terminate gracefully within the drain bound'
        sleep 1
    done
    assert_horizon_stopped "$horizon_container"
}

configured_queue_inventory()
{
    queue_container=$1

    if is_test_mode; then
        docker exec "$queue_container" /usr/local/bin/control-plane-lab-service queues
        return
    fi

    docker exec "$queue_container" php -r '
        require "/var/www/html/vendor/autoload.php";
        $app = require "/var/www/html/bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $environments = config("horizon.environments", []);
        $environment = $app->environment();
        $supervisors = $environments[$environment] ?? $environments["*"] ?? [];
        $defaults = config("horizon.defaults", []);
        $queues = [];
        foreach ($supervisors as $name => $supervisor) {
            $configuration = array_merge($defaults[$name] ?? [], $supervisor);
            $configuredQueues = $configuration["queue"] ?? [];
            if (is_string($configuredQueues)) {
                $configuredQueues = explode(",", $configuredQueues);
            }
            foreach ($configuredQueues as $queue) {
                $queue = trim((string) $queue);
                if ($queue !== "") {
                    $queues[$queue] = true;
                }
            }
        }
        $names = array_keys($queues);
        sort($names, SORT_STRING);
        foreach ($names as $name) {
            echo $name, PHP_EOL;
        }
    '
}

persist_drain_inventory()
{
    drain_container=$1
    inventory_candidate="$operation_directory/.drain-queues.$$"
    configured_queue_inventory "$drain_container" \
        | LC_ALL=C sort -u \
        | grep -E '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' > "$inventory_candidate"
    [ -s "$inventory_candidate" ] || fail 'no configured Horizon queues were discovered'

    if [ -e "$drain_queue_file" ]; then
        [ "$(sha256_file "$drain_queue_file")" = "$(sha256_file "$inventory_candidate")" ] \
            || fail 'configured Horizon queue inventory changed during drain recovery'
        rm -f "$inventory_candidate"
    else
        mv "$inventory_candidate" "$drain_queue_file"
        sync
    fi
    state_drain_queue_sha256=$(sha256_file "$drain_queue_file")
}

assert_drain_inventory()
{
    drain_container=$1
    [ "$state_drain_queue_sha256" != none ] && [ -f "$drain_queue_file" ] \
        && [ "$(sha256_file "$drain_queue_file")" = "$state_drain_queue_sha256" ] \
        || fail 'durable Horizon queue inventory is missing or changed'
    inventory_candidate="$operation_directory/.drain-queues-verify.$$"
    configured_queue_inventory "$drain_container" | LC_ALL=C sort -u \
        | grep -E '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' > "$inventory_candidate"
    [ "$(sha256_file "$inventory_candidate")" = "$state_drain_queue_sha256" ] \
        || fail 'configured Horizon queues changed during drain'
    rm -f "$inventory_candidate"
}

reserved_work_count()
{
    queue_container=$1

    if is_test_mode; then
        docker exec --env CONTROL_PLANE_QUEUE_FILE_CONTENTS="$(tr '\n' ',' < "$drain_queue_file")" \
            "$queue_container" /usr/local/bin/control-plane-lab-service reserved-count
        return
    fi

    docker exec --env CONTROL_PLANE_QUEUE_FILE_CONTENTS="$(tr '\n' ',' < "$drain_queue_file")" \
        "$queue_container" php -r '
            require "/var/www/html/vendor/autoload.php";
            $app = require "/var/www/html/bootstrap/app.php";
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $connectionName = config("queue.connections.redis.connection", "default");
            $redis = app("redis")->connection($connectionName);
            $total = 0;
            foreach (array_filter(explode(",", getenv("CONTROL_PLANE_QUEUE_FILE_CONTENTS") ?: "")) as $queue) {
                $total += (int) $redis->llen("queues:{$queue}");
                $total += (int) $redis->zcard("queues:{$queue}:reserved");
                $total += (int) $redis->zcard("queues:{$queue}:delayed");
            }
            echo $total, PHP_EOL;
        '
}

active_deployment_worker_count()
{
    active_count=$(docker exec "$database_container" \
        psql --no-psqlrc --tuples-only --no-align --quiet \
            --username "$database_user" --dbname "$database_name" \
            --command "select count(*) from application_deployment_queues where status = 'in_progress' and horizon_job_worker is not null;")
    printf '%s' "$active_count" | tr -d '[:space:]'
}

assert_no_active_old_work()
{
    drain_container=$1
    assert_drain_inventory "$drain_container"
    reserved_count=$(reserved_work_count "$drain_container" | tr -d '[:space:]')
    active_count=$(active_deployment_worker_count)
    printf '%s' "$reserved_count" | grep -Eq '^[0-9]+$' \
        || fail 'Redis queued-work probe did not return a count'
    printf '%s' "$active_count" | grep -Eq '^[0-9]+$' \
        || fail 'active deployment-worker probe did not return a count'
    [ "$reserved_count" = 0 ] && [ "$active_count" = 0 ]
}

wait_for_no_schedule_run()
{
    scheduler_container=$1
    attempt=0

    while [ "$attempt" -lt "$drain_attempts" ]; do
        if is_test_mode; then
            docker exec "$scheduler_container" /usr/local/bin/control-plane-lab-service schedule-idle \
                && return
        elif ! docker exec "$scheduler_container" /bin/sh -ec '
            ps -eo args | grep -F "artisan schedule:run" | grep -v grep >/dev/null
        '; then
            return
        fi
        attempt=$((attempt + 1))
        sleep 1
    done

    fail 'running scheduled work did not finish within the drain bound'
}

active_tcp_connection_count()
{
    connection_container=$1

    if is_test_mode; then
        docker exec "$connection_container" /usr/local/bin/control-plane-lab-service tcp-active
        return
    fi

    docker exec --env CONTROL_PLANE_BACKEND_PORT="$backend_port" "$connection_container" /bin/sh -ec '
        port=$(printf "%04X" "$CONTROL_PLANE_BACKEND_PORT")
        awk -v port=":${port}" "
            NR > 1 && index(\$2, port) == length(\$2) - length(port) + 1 && \$4 == \"01\" { count++ }
            END { print count + 0 }
        " /proc/net/tcp /proc/net/tcp6 2>/dev/null
    '
}

assert_http_drain_pass()
{
    drain_container=$1
    active_connections=$(active_tcp_connection_count "$drain_container" | tr -d '[:space:]')
    printf '%s' "$active_connections" | grep -Eq '^[0-9]+$' \
        || fail 'old HTTP/TCP connection probe did not return a count'
    [ "$active_connections" = 0 ]
}

assert_pool_http_drained()
{
    drained_color=$1
    case "$drained_color" in
        green)
            drained_a=$green_container
            drained_b=$green_web_b_container
            ;;
        blue)
            drained_a=$replacement_blue_container
            drained_b=$replacement_blue_web_b_container
            ;;
        *) fail "unknown HTTP drain pool color: $drained_color" ;;
    esac
    attempt=0
    until assert_http_drain_pass "$drained_a" && assert_http_drain_pass "$drained_b"; do
        attempt=$((attempt + 1))
        [ "$attempt" -lt "$drain_attempts" ] \
            || fail "$drained_color pool HTTP/TCP connections did not drain within the bound"
        sleep 1
    done
    sleep "$drain_stable_seconds"
    if ! assert_http_drain_pass "$drained_a" || ! assert_http_drain_pass "$drained_b"; then
        fail "$drained_color pool HTTP/TCP connections were not stably zero"
    fi
}

drain_color()
{
    drain_container=$1
    phase_prefix=$2
    initial_phase=$3

    scheduler_stopping="${phase_prefix}-scheduler-stopping"
    scheduler_stopped="${phase_prefix}-scheduler-stopped"
    horizon_pausing="${phase_prefix}-horizon-pausing"
    horizon_paused="${phase_prefix}-horizon-paused"
    inventory_recording="${phase_prefix}-drain-inventory-recording"
    inventory_recorded="${phase_prefix}-drain-inventory-recorded"
    background_first="${phase_prefix}-background-drain-first"
    background_zero_first="${phase_prefix}-background-zero-first"
    background_zero_proven="${phase_prefix}-background-zero-proven"
    horizon_stopping="${phase_prefix}-horizon-stopping"
    horizon_stopped="${phase_prefix}-horizon-stopped"
    nightwatch_stopping="${phase_prefix}-nightwatch-stopping"
    nightwatch_stopped="${phase_prefix}-nightwatch-stopped"
    http_first="${phase_prefix}-http-drain-first"
    http_zero_first="${phase_prefix}-http-zero-first"
    drained="${phase_prefix}-drained"

    case "$state_phase" in
        "$initial_phase"|"$scheduler_stopping")
            state_phase=$scheduler_stopping
            write_state "$state_phase"
            test_crash "after-${phase_prefix}-scheduler-stop-intent"
            control_s6_service_down "$drain_container" scheduler-worker
            wait_for_no_schedule_run "$drain_container"
            state_phase=$scheduler_stopped
            write_state "$state_phase"
            test_crash "after-${phase_prefix}-scheduler-stopped"
            ;;
    esac

    case "$state_phase" in
        "$scheduler_stopped"|"$horizon_pausing")
            state_phase=$horizon_pausing
            write_state "$state_phase"
            pause_horizon "$drain_container"
            state_phase=$horizon_paused
            write_state "$state_phase"
            test_crash "after-${phase_prefix}-horizon-paused"
            ;;
    esac

    case "$state_phase" in
        "$horizon_paused"|"$inventory_recording")
            state_phase=$inventory_recording
            write_state "$state_phase"
            persist_drain_inventory "$drain_container"
            state_phase=$inventory_recorded
            write_state "$state_phase"
            test_crash "after-${phase_prefix}-drain-inventory"
            ;;
    esac

    case "$state_phase" in
        "$inventory_recorded"|"$background_first")
            state_phase=$background_first
            write_state "$state_phase"
            attempt=0
            until assert_no_active_old_work "$drain_container"; do
                attempt=$((attempt + 1))
                [ "$attempt" -lt "$drain_attempts" ] \
                    || fail 'old queued/active work did not quiesce within the drain bound'
                sleep 1
            done
            state_phase=$background_zero_first
            write_state "$state_phase"
            test_crash "after-${phase_prefix}-background-zero-first"
            ;;
    esac

    if [ "$state_phase" = "$background_zero_first" ]; then
        sleep "$drain_stable_seconds"
        assert_no_active_old_work "$drain_container" \
            || fail 'old queued/active work was not zero on the second stable proof'
        state_phase=$background_zero_proven
        write_state "$state_phase"
        test_crash "after-${phase_prefix}-background-zero-proven"
    fi

    case "$state_phase" in
        "$background_zero_proven"|"$horizon_stopping")
            state_phase=$horizon_stopping
            write_state "$state_phase"
            terminate_horizon "$drain_container"
            assert_no_active_old_work "$drain_container" \
                || fail 'old queued/active work reappeared after Horizon termination'
            sleep "$drain_stable_seconds"
            assert_no_active_old_work "$drain_container" \
                || fail 'old queued/active work was not zero on the post-termination stable proof'
            state_phase=$horizon_stopped
            write_state "$state_phase"
            test_crash "after-${phase_prefix}-horizon-stopped"
            ;;
    esac

    case "$state_phase" in
        "$horizon_stopped"|"$nightwatch_stopping")
            state_phase=$nightwatch_stopping
            write_state "$state_phase"
            control_s6_service_down "$drain_container" nightwatch-agent
            state_phase=$nightwatch_stopped
            write_state "$state_phase"
            test_crash "after-${phase_prefix}-nightwatch-stopped"
            ;;
    esac

    case "$state_phase" in
        "$nightwatch_stopped"|"$http_first")
            state_phase=$http_first
            write_state "$state_phase"
            attempt=0
            until assert_http_drain_pass "$drain_container"; do
                attempt=$((attempt + 1))
                [ "$attempt" -lt "$drain_attempts" ] \
                    || fail 'old HTTP/TCP connections did not drain within the bound'
                sleep 1
            done
            state_phase=$http_zero_first
            write_state "$state_phase"
            test_crash "after-${phase_prefix}-http-zero-first"
            ;;
    esac

    if [ "$state_phase" = "$http_zero_first" ]; then
        sleep "$drain_stable_seconds"
        assert_http_drain_pass "$drain_container" \
            || fail 'old HTTP/TCP connections were not zero on the second stable proof'
        state_phase=$drained
        write_state "$state_phase"
        test_crash "after-${phase_prefix}-drained"
    fi
}

drain_legacy_blue()
{
    drain_color "$blue_container" blue green-routed
}

reconcile_recovery_migration_runner()
{
    validate_migration_attempt_history
    if [ "$latest_attempt_number" = 0 ]; then
        assert_control_plane_migration_lock_available
        return
    fi
    reconcile_live_expand_migration_runner abort || true
}

assert_recovery_abort_reversible_phase()
{
    recovery_phase=$1
    case "$recovery_phase" in
        fence-captured|fence-armed|fence-active|green-starting|green-started-unproven|green-started|preflight-ready|live-expand-migrations-planned|live-expand-migrations-quiescing|live-expand-migrations-scheduler-stopped|live-expand-migrations-schedule-idle|live-expand-migrations-horizon-paused|live-expand-migrations-drain-inventory-recorded|live-expand-migrations-quiesced|live-expand-migrations-running|live-expand-migrations-resuming|live-expand-migrations-resumed|live-expand-migrations-failed|live-expand-migrations-blocked|live-expand-migrations-applied|green-web-activated|green-https-routed|green-routed|blue-scheduler-stopping|blue-scheduler-stopped|blue-horizon-pausing|blue-horizon-paused|blue-drain-inventory-recording|blue-drain-inventory-recorded|blue-background-drain-first|blue-background-zero-first|blue-background-zero-proven|blue-horizon-stopping|blue-horizon-stopped|blue-nightwatch-stopping|blue-nightwatch-stopped|blue-http-drain-first|blue-http-zero-first|blue-drained|green-final-ingress-verifying|green-final-ingress-acknowledged|blue-revoking)
            ;;
        blue-revoked|proxy-mutation-freeze-activating|proxy-mutation-freeze-active|fence-release-intent)
            fail "recovery-abort cannot restore legacy ingress from phase: $recovery_phase; use recover-forward"
            ;;
        *)
            fail "recovery-abort is forbidden from phase: $recovery_phase"
            ;;
    esac
}

recover_abort()
{
    acquire_lock
    load_state
    if [ "$state_phase" = recovery-aborted ]; then
        proxy_enrollment_status >/dev/null \
            && [ "$proxy_enrollment_phase" = enrolled ] \
            || fail 'recovery-aborted operation lost its retained native Traefik enrollment state'
        assert_proxy_enrollment_active
        note "recovery-already-aborted operation=$operation_id"
        return
    fi
    proxy_enrollment_status >/dev/null \
        || fail 'recovery-abort lost exact token-owned proxy-enrollment state'
    case "$proxy_enrollment_phase" in
        activated|enrolled)
            assert_proxy_enrollment_active
            assert_source_identity
            ;;
        rolling-back|rollback-pending-legacy|rollback-required|intervention-required)
            fail 'recovery-abort found an unsafe destructive native Traefik rollback phase; preserve the managed listener instead'
            ;;
        rolled-back)
            fail 'recovery-abort found a revoked native Traefik enrollment; direct APP_PORT legacy recreation is forbidden'
            ;;
        *) fail 'recovery-abort found an unsafe proxy-enrollment phase' ;;
    esac
    reconcile_recovery_migration_runner

    docker_container_presence "$blue_container"
    recovery_blue_presence=$container_presence
    recovery_blue_running=$container_presence_running

    if [ "$state_phase" != recovery-abort-reconciling ]; then
        assert_recovery_abort_reversible_phase "$state_phase"
        [ "$(marker_status "$green_state_volume" "$writer_epoch")" = absent ] \
            || fail 'recovery-abort is forbidden after green writer promotion'
        state_recovery_abort_generation=1
        state_recovery_abort_from_phase=$state_phase
        state_phase=recovery-abort-reconciling
        write_state "$state_phase"
    fi
    [ "$state_recovery_abort_generation" = 1 \
        ] && [ "$state_recovery_abort_from_phase" != none ] \
        || fail 'recovery-abort lineage is absent'
    assert_recovery_abort_reversible_phase "$state_recovery_abort_from_phase"

    if [ "$recovery_blue_presence" = absent ] \
        || [ "$state_blue_restore_status" = intent ]; then
        restore_legacy_blue
    elif [ "$recovery_blue_running" != true ]; then
        restart_stopped_legacy_blue_incumbent
    fi
    assert_blue_state
    resume_legacy_blue_background
    ingress_controller_adopt_rollback_owner
    ingress_controller_legacy_restore_status
    if [ -f "$operation_directory/ingress-ingress/state" ]; then
        ingress_controller_restore ingress
    fi

    adopt_green_cleanup_member_identity_if_required web-a
    adopt_green_cleanup_member_identity_if_required web-b
    complete_recovery_abort_candidate_cleanup
    recovery_green_a_volume=absent
    recovery_green_b_volume=absent
    docker volume inspect "$green_state_volume" >/dev/null 2>&1 \
        && recovery_green_a_volume=present
    docker volume inspect "$green_web_b_private_volume" >/dev/null 2>&1 \
        && recovery_green_b_volume=present
    case "$recovery_green_a_volume:$recovery_green_b_volume" in
        absent:absent)
            ;;
        present:present)
            select_pool_writer_context green
            revoke_marker "$writer_volume" green "$writer_epoch_value" absent
            revoke_pool_web_markers_if_safe green
            assert_pool_web_markers green absent
            ;;
        *) fail 'recovery-abort found a partial green private-volume inventory' ;;
    esac
    if [ "$recovery_green_a_volume" = present ]; then
        recovery_marker_status=$(marker_status "$green_state_volume" "$mutation_freeze_epoch" mutation-freeze-epoch)
        case "$recovery_marker_status" in
            absent) ;;
            matching) revoke_marker "$green_state_volume" green "$mutation_freeze_epoch" matching mutation-freeze-epoch ;;
            *) fail 'green mutation freeze is unsafe during recovery-abort' ;;
        esac
        assert_green_pool_volumes_reusable
    fi
    state_route_target=legacy
    state_https_route_target=legacy
    runtime_fence_call recover-abort
    preserve_proxy_enrollment_after_state_creation \
        || fail 'recovery-abort could not retain and finalize native Traefik enrollment after restoring the legacy dynamic route'
    test_crash after-recovery-abort-proxy-enrollment-preserve
    state_phase=recovery-aborted
    write_state "$state_phase"
    note "recovery-aborted operation=$operation_id from=$state_recovery_abort_from_phase generation=1"
}

recover_forward()
{
    acquire_lock
    load_state
    assert_common_state_identity
    reconcile_routed_candidate
    reconcile_recovery_migration_runner

    case "$state_phase" in
        blue-revoking|blue-revoked|proxy-mutation-freeze-activating|proxy-mutation-freeze-active|fence-release-intent|fence-released|green-writer-promoting|green-writer-promoted-freeze-active|green-promoted-finalizing|green-writer-promoted)
            promote_green
            ;;
        *)
            fail "recover-forward requires irreversible forward convergence, not phase: $state_phase"
            ;;
    esac
}

recover_reverse_forward()
{
    acquire_lock
    load_state
    assert_common_state_identity
    reconcile_routed_candidate
    reconcile_recovery_migration_runner

    case "$state_phase" in
        failback-green-revoking|failback-green-revoked|reverse-proxy-mutation-freeze-activating|reverse-proxy-mutation-freeze-active|reverse-fence-release-intent|reverse-fence-released|blue-writer-promoting|blue-writer-promoted-freeze-active|blue-promoted-finalizing|blue-writer-promoted)
            controlled_failback_to_blue
            ;;
        *)
            fail "recover-reverse-forward requires irreversible reverse convergence, not phase: $state_phase"
            ;;
    esac
}

rollback()
{
    acquire_lock
    load_state
    if [ "$state_phase" = rolled-back ]; then
        proxy_enrollment_status >/dev/null \
            && [ "$proxy_enrollment_phase" = enrolled ] \
            || fail 'rolled-back operation lost its retained native Traefik enrollment state'
        assert_proxy_enrollment_active
        note "operation-already-rolled-back operation=$operation_id"
        return
    fi
    proxy_enrollment_status >/dev/null \
        || fail 'rollback lost exact token-owned proxy-enrollment state'
    case "$proxy_enrollment_phase" in
        activated|enrolled)
            assert_proxy_enrollment_active
            ;;
        rolling-back|rollback-pending-legacy|rollback-required|intervention-required)
            fail 'rollback found an unsafe destructive native Traefik rollback phase; preserve the managed listener instead'
            ;;
        rolled-back)
            fail 'rollback found a revoked native Traefik enrollment; direct APP_PORT legacy recreation is forbidden'
            ;;
        *) fail 'rollback found an unsafe proxy-enrollment phase' ;;
    esac
    case "$state_phase" in
        forward-candidate-cleanup-intent|fence-abort-intent|reverse-fence-abort-intent) ;;
        *) reconcile_routed_candidate ;;
    esac
    reconcile_recovery_migration_runner

    case "$state_phase" in
        fence-abort-intent)
            converge_fence_abort_intent forward
            ;;
        reverse-fence-abort-intent)
            converge_fence_abort_intent reverse
            ;;
        forward-candidate-cleanup-intent)
            complete_forward_candidate_cleanup
            ;;
        preflight-started|fence-prepare-intent|fence-prepared|fence-captured|fence-armed|fence-active|green-starting|green-started-unproven|green-started|preflight-ready|live-expand-migrations-planned|live-expand-migrations-quiescing|live-expand-migrations-scheduler-stopped|live-expand-migrations-schedule-idle|live-expand-migrations-horizon-paused|live-expand-migrations-drain-inventory-recorded|live-expand-migrations-quiesced|live-expand-migrations-running|live-expand-migrations-resuming|live-expand-migrations-resumed|live-expand-migrations-failed|live-expand-migrations-blocked)
            docker_container_presence "$green_container"
            rollback_green_presence=$container_presence
            docker_container_presence "$green_web_b_container"
            rollback_green_web_b_presence=$container_presence
            [ "$rollback_green_presence:$rollback_green_web_b_presence" != present:absent ] \
                && [ "$rollback_green_presence:$rollback_green_web_b_presence" != absent:present ] \
                || fail 'rollback found a partial green pool'
            complete_forward_runtime_fence
            if [ "$state_phase" = green-starting ] \
                && [ "$rollback_green_presence" = present ]; then
                assert_candidate_pool_runtime green
                state_green_id=$(container_id "$green_container")
                state_green_image_id=$(container_image_id "$green_container")
                state_green_web_b_id=$(container_id "$green_web_b_container")
                state_green_web_b_image_id=$(container_image_id "$green_web_b_container")
            fi
            if [ "$rollback_green_presence" = present ]; then
                stop_and_remove_container "$green_container" green "$state_green_id" "$state_green_image_id"
                stop_and_remove_container "$green_web_b_container" green-web-b \
                    "$state_green_web_b_id" "$state_green_web_b_image_id"
            fi
            if docker volume inspect "$green_state_volume" >/dev/null 2>&1; then
                revoke_marker "$green_state_volume" green "$writer_epoch" absent
                revoke_marker "$green_state_volume" green "$green_web_epoch" absent web-epoch
                revoke_marker "$green_state_volume" green "$mutation_freeze_epoch" absent mutation-freeze-epoch
            fi
            begin_fence_abort_intent forward
            note "rolled-back-before-route-switch operation=$operation_id"
            ;;
        live-expand-migrations-applied)
            if [ -f "$operation_directory/ingress-ingress/state" ] \
                || [ "$(marker_status "$green_state_volume" "$green_web_epoch" web-epoch)" = matching ]; then
                rollback_before_green_promotion
            else
                assert_green_state
                state_phase=forward-candidate-cleanup-intent
                write_state "$state_phase"
                test_crash after-forward-candidate-cleanup-intent
                complete_forward_candidate_cleanup
            fi
            ;;
        green-web-activated|green-https-routed|green-routed|blue-scheduler-stopping|blue-scheduler-stopped|blue-horizon-pausing|blue-horizon-paused|blue-drain-inventory-recording|blue-drain-inventory-recorded|blue-background-drain-first|blue-background-zero-first|blue-background-zero-proven|blue-horizon-stopping|blue-horizon-stopped|blue-nightwatch-stopping|blue-nightwatch-stopped|blue-http-drain-first|blue-http-zero-first|blue-drained|green-final-ingress-verifying|green-final-ingress-acknowledged)
            rollback_before_green_promotion
            ;;
        blue-revoking)
            if [ "$(marker_status "$green_state_volume" "$writer_epoch")" = matching ]; then
                state_phase=green-writer-promoted
                write_state "$state_phase"
                fail 'green writer is already promoted; a fresh reverse runtime-fence generation is required for failback'
            else
                rollback_before_green_promotion
            fi
            ;;
        blue-revoked|proxy-mutation-freeze-activating|proxy-mutation-freeze-active|fence-release-intent|fence-released|green-writer-promoting|green-writer-promoted-freeze-active|green-promoted-finalizing)
            fail 'legacy blue is irrevocably removed; use recover-forward before failback'
            ;;
        green-writer-promoted|reverse-fence-artifacts-preparing|reverse-fence-prepare-intent|reverse-fence-prepared|reverse-fence-captured|reverse-fence-armed|reverse-fence-active|failback-blue-starting|failback-blue-started-unproven|failback-blue-started|failback-blue-web-activating|failback-blue-web-activated|failback-blue-https-routing|failback-blue-https-routed|blue-failback-routed|failback-green-scheduler-stopping|failback-green-scheduler-stopped|failback-green-horizon-pausing|failback-green-horizon-paused|failback-green-drain-inventory-recording|failback-green-drain-inventory-recorded|failback-green-background-drain-first|failback-green-background-zero-first|failback-green-background-zero-proven|failback-green-horizon-stopping|failback-green-horizon-stopped|failback-green-nightwatch-stopping|failback-green-nightwatch-stopped|failback-green-http-drain-first|failback-green-http-zero-first|failback-green-drained|failback-blue-final-ingress-verifying|failback-blue-final-ingress-acknowledged)
            controlled_failback_to_blue
            ;;
        failback-green-revoking)
            docker_container_presence "$green_container"
            if [ "$container_presence" = present ]; then
                controlled_failback_to_blue
            else
                fail 'green incumbent is irrevocably removed; use recover-reverse-forward'
            fi
            ;;
        failback-green-revoked|reverse-proxy-mutation-freeze-activating|reverse-proxy-mutation-freeze-active|reverse-fence-release-intent|reverse-fence-released|blue-writer-promoting|blue-writer-promoted-freeze-active|blue-promoted-finalizing)
            fail 'green incumbent is irrevocably removed; use recover-reverse-forward'
            ;;
        blue-writer-promoted)
            controlled_failback_to_blue
            ;;
        rolled-back)
            note "operation-already-rolled-back operation=$operation_id"
            ;;
        *)
            fail "unknown operation phase: $state_phase"
            ;;
    esac
}

promote_green()
{
    reconcile_irreversible_revocation_phase
    case "$state_phase" in
        green-routed|blue-scheduler-stopping|blue-scheduler-stopped|blue-horizon-pausing|blue-horizon-paused|blue-drain-inventory-recording|blue-drain-inventory-recorded|blue-background-drain-first|blue-background-zero-first|blue-background-zero-proven|blue-horizon-stopping|blue-horizon-stopped|blue-nightwatch-stopping|blue-nightwatch-stopped|blue-http-drain-first|blue-http-zero-first|blue-drained|green-final-ingress-verifying|green-final-ingress-acknowledged|blue-revoking)
            runtime_fence_call verify
            ;;
        blue-revoked|proxy-mutation-freeze-activating|proxy-mutation-freeze-active)
            runtime_fence_call verify-post-revoke
            ;;
    esac
    assert_green_state
    case "$state_phase" in
        green-routed|blue-scheduler-stopping|blue-scheduler-stopped|blue-horizon-pausing|blue-horizon-paused|blue-drain-inventory-recording|blue-drain-inventory-recorded|blue-background-drain-first|blue-background-zero-first|blue-background-zero-proven|blue-horizon-stopping|blue-horizon-stopped|blue-nightwatch-stopping|blue-nightwatch-stopped|blue-http-drain-first|blue-http-zero-first|blue-drained|green-final-ingress-verifying|green-final-ingress-acknowledged|blue-revoking)
            assert_blue_state
            ingress_controller_verify_ack ingress green "$green_container" "$backend_port" "$green_applied_ack_file"
            ;;
        green-writer-promoted)
            ingress_controller_assert ingress green "$green_container" "$backend_port" "$green_applied_ack_file"
            ;;
    esac
    [ "$(marker_status "$green_state_volume" "$green_web_epoch" web-epoch)" = matching ] \
        || fail 'green web activation fence is not durable before background drain'

    case "$state_phase" in
        green-routed|blue-scheduler-stopping|blue-scheduler-stopped|blue-horizon-pausing|blue-horizon-paused|blue-drain-inventory-recording|blue-drain-inventory-recorded|blue-background-drain-first|blue-background-zero-first|blue-background-zero-proven|blue-horizon-stopping|blue-horizon-stopped|blue-nightwatch-stopping|blue-nightwatch-stopped|blue-http-drain-first|blue-http-zero-first)
            assert_blue_state
            drain_legacy_blue
            ;;
    esac

    case "$state_phase" in
        blue-drained|green-final-ingress-verifying|green-final-ingress-acknowledged|blue-revoking)
            assert_backup_attestation
            state_phase=green-final-ingress-verifying
            write_state "$state_phase"
            assert_common_state_identity
            assert_green_state
            assert_blue_state
            ingress_controller_verify_ack ingress green "$green_container" "$backend_port" "$green_applied_ack_file"
            assert_green_state
            assert_blue_state
            state_phase=green-final-ingress-acknowledged
            write_state "$state_phase"
            test_crash after-green-final-ingress-ack
            state_phase=blue-revoking
            write_state "$state_phase"
            test_crash after-blue-revoke-intent
            docker_container_presence "$blue_container"
            if [ "$container_presence" = present ]; then
                revoke_legacy_blue || fail 'drained blue stop/removal was not proven; green promotion is forbidden'
            fi
            assert_container_absent "$blue_container"
            state_phase=blue-revoked
            write_state "$state_phase"
            runtime_fence_call verify-post-revoke
            test_crash after-blue-revoked
            ;;
    esac

    case "$state_phase" in
        blue-revoked)
            runtime_fence_call verify-post-revoke
            state_phase=proxy-mutation-freeze-activating
            write_state "$state_phase"
            ;;
    esac

    case "$state_phase" in
        proxy-mutation-freeze-activating)
            runtime_fence_call verify-post-revoke
            activate_marker "$green_state_volume" green "$mutation_freeze_epoch" mutation-freeze-epoch
            prove_mutation_freeze_quiesced "$green_container" "$mutation_freeze_epoch"
            state_phase=proxy-mutation-freeze-active
            write_state "$state_phase"
            test_crash after-proxy-mutation-freeze
            ;;
    esac

    case "$state_phase" in
        proxy-mutation-freeze-active)
            runtime_fence_call verify-post-revoke
            [ "$(marker_status "$green_state_volume" "$mutation_freeze_epoch" mutation-freeze-epoch)" = matching ] \
                || fail 'proxy mutation freeze is not durable before runtime-fence release'
            prove_mutation_freeze_quiesced "$green_container" "$mutation_freeze_epoch"
            state_phase=fence-release-intent
            write_state "$state_phase"
            test_crash after-runtime-fence-release-intent
            ;;
    esac

    case "$state_phase" in
        fence-release-intent)
            runtime_fence_call release
            state_phase=fence-released
            write_state "$state_phase"
            test_crash after-runtime-fence-release
            ;;
    esac

    case "$state_phase" in
        fence-released|green-writer-promoting)
            state_phase=green-writer-promoting
            write_state "$state_phase"
            promote_selected_writer green
            test_crash after-green-marker
            state_phase=green-writer-promoted-freeze-active
            write_state "$state_phase"
            ;;
    esac

    case "$state_phase" in
        green-writer-promoted-freeze-active)
            promote_selected_writer green
            revoke_marker "$green_state_volume" green "$mutation_freeze_epoch" matching mutation-freeze-epoch
            state_phase=green-promoted-finalizing
            write_state "$state_phase"
            ;;
    esac

    case "$state_phase" in
        green-promoted-finalizing)
            [ "$(marker_status "$green_state_volume" "$mutation_freeze_epoch" mutation-freeze-epoch)" = absent ] \
                || fail 'proxy mutation freeze remained active during release finalization'
            runtime_fence_call finalize-release
            state_phase=green-writer-promoted
            write_state "$state_phase"
            ;;
        green-writer-promoted)
            [ "$(marker_status "$green_state_volume" "$mutation_freeze_epoch" mutation-freeze-epoch)" = absent ] \
                || fail 'terminal green promotion retained the proxy mutation freeze'
            promote_selected_writer green
            ;;
        *)
            fail "green promotion cannot reconcile phase: $state_phase"
            ;;
    esac

    note "green-writer-promoted operation=$operation_id legacy-rollback-disabled=true"
}

promote()
{
    acquire_lock
    load_state
    assert_common_state_identity
    reconcile_routed_candidate

    case "$state_phase" in
        green-routed|blue-scheduler-stopping|blue-scheduler-stopped|blue-horizon-pausing|blue-horizon-paused|blue-drain-inventory-recording|blue-drain-inventory-recorded|blue-background-drain-first|blue-background-zero-first|blue-background-zero-proven|blue-horizon-stopping|blue-horizon-stopped|blue-nightwatch-stopping|blue-nightwatch-stopped|blue-http-drain-first|blue-http-zero-first|blue-drained|green-final-ingress-verifying|green-final-ingress-acknowledged|blue-revoking|blue-revoked|proxy-mutation-freeze-activating|proxy-mutation-freeze-active|fence-release-intent|fence-released|green-writer-promoting|green-writer-promoted-freeze-active|green-promoted-finalizing|green-writer-promoted)
            promote_green
            ;;
        reverse-fence-artifacts-preparing|reverse-fence-prepare-intent|reverse-fence-prepared|reverse-fence-captured|reverse-fence-armed|reverse-fence-active|failback-blue-starting|failback-blue-started-unproven|failback-blue-started|failback-blue-web-activating|failback-blue-web-activated|failback-blue-https-routing|failback-blue-https-routed|blue-failback-routed|failback-green-scheduler-stopping|failback-green-scheduler-stopped|failback-green-horizon-pausing|failback-green-horizon-paused|failback-green-drain-inventory-recording|failback-green-drain-inventory-recorded|failback-green-background-drain-first|failback-green-background-zero-first|failback-green-background-zero-proven|failback-green-horizon-stopping|failback-green-horizon-stopped|failback-green-nightwatch-stopping|failback-green-nightwatch-stopped|failback-green-http-drain-first|failback-green-http-zero-first|failback-green-drained|failback-blue-final-ingress-verifying|failback-blue-final-ingress-acknowledged|failback-green-revoking|failback-green-revoked|reverse-proxy-mutation-freeze-activating|reverse-proxy-mutation-freeze-active|reverse-fence-release-intent|reverse-fence-released|blue-writer-promoting|blue-writer-promoted-freeze-active|blue-promoted-finalizing|blue-writer-promoted)
            controlled_failback_to_blue
            ;;
        *)
            fail 'promote requires green-routed or blue-failback-routed state'
            ;;
    esac
}

rehearse_migrations()
{
    require_value CONTROL_PLANE_MIGRATION_COMPATIBILITY_FILE "$migration_compatibility_file"
    require_value CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE "$rehearsal_runtime_env_file"
    require_value CONTROL_PLANE_REHEARSAL_NETWORK "$rehearsal_network"
    require_value CONTROL_PLANE_REHEARSAL_DATABASE_CONTAINER "$rehearsal_database_container"
    require_value CONTROL_PLANE_REHEARSAL_DATABASE_USER "$rehearsal_database_user"
    require_value CONTROL_PLANE_REHEARSAL_DATABASE_NAME "$rehearsal_database_name"
    require_value CONTROL_PLANE_REHEARSAL_EXPECTED_MIGRATION_LEDGER_SHA256 \
        "$rehearsal_expected_migration_ledger_sha256"
    require_value CONTROL_PLANE_REHEARSAL_EXPECTED_MIGRATION_PENDING_SHA256 \
        "$rehearsal_expected_migration_pending_sha256"
    require_value CONTROL_PLANE_REHEARSAL_EXPECTED_MIGRATION_BATCH \
        "$rehearsal_expected_migration_batch"
    validate_path "$migration_compatibility_file" CONTROL_PLANE_MIGRATION_COMPATIBILITY_FILE
    validate_path "$rehearsal_runtime_env_file" CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE
    assert_non_symlink_regular_file "$migration_compatibility_file" \
        CONTROL_PLANE_MIGRATION_COMPATIBILITY_FILE
    assert_non_symlink_regular_file "$rehearsal_runtime_env_file" \
        CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE
    validate_identifier "$rehearsal_network" CONTROL_PLANE_REHEARSAL_NETWORK
    validate_identifier "$rehearsal_database_container" \
        CONTROL_PLANE_REHEARSAL_DATABASE_CONTAINER
    validate_identifier "$rehearsal_database_user" CONTROL_PLANE_REHEARSAL_DATABASE_USER
    validate_identifier "$rehearsal_database_name" CONTROL_PLANE_REHEARSAL_DATABASE_NAME
    require_migration_timeouts
    [ "$rehearsal_network" != "$control_plane_network" ] \
        || fail 'migration rehearsal must use an isolated, non-live network'
    [ "$rehearsal_runtime_env_file" != "$source_env_file" ] \
        || fail 'migration rehearsal must use a separately provisioned clone-database environment file'
    [ "$rehearsal_database_container" != "$database_container" ] \
        || fail 'migration rehearsal database container must differ from the live database container'
    printf '%s' "$rehearsal_expected_migration_ledger_sha256" | grep -Eq '^[0-9a-f]{64}$' \
        || fail 'rehearsal migration ledger checksum is malformed'
    printf '%s' "$rehearsal_expected_migration_pending_sha256" | grep -Eq '^[0-9a-f]{64}$' \
        || fail 'rehearsal pending migration checksum is malformed'
    printf '%s' "$rehearsal_expected_migration_batch" | grep -Eq '^[1-9][0-9]*$' \
        || fail 'rehearsal expected migration batch is malformed'

    acquire_lock
    if [ ! -e "$operation_directory" ] && [ ! -L "$operation_directory" ]; then
        mkdir "$operation_directory"
        chmod 700 "$operation_directory"
    elif [ ! -d "$operation_directory" ] || [ -L "$operation_directory" ]; then
        fail 'migration rehearsal operation path is not a safe directory'
    fi
    assert_operation_directory_security
    record_host_release_identity
    prepare_rehearsal_input_snapshots
    assert_migration_compatibility_file "$rehearsal_compatibility_artifact_file" \
        'migration-rehearsal compatibility snapshot'
    assert_rehearsal_input_snapshots
    assert_container_running "$rehearsal_database_container"
    capture_migration_rehearsal_identity
    CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256=$rehearsal_expected_migration_ledger_sha256
    CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256=$rehearsal_expected_migration_pending_sha256
    CONTROL_PLANE_EXPECTED_MIGRATION_BATCH=$rehearsal_expected_migration_batch
    CONTROL_PLANE_LIVE_DATABASE_SYSTEM_IDENTIFIER=$live_database_system_identifier
    export CONTROL_PLANE_LIVE_DATABASE_SYSTEM_IDENTIFIER
    export CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256
    export CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256
    export CONTROL_PLANE_EXPECTED_MIGRATION_BATCH

    if [ ! -e "$rehearsal_state_file" ] && [ ! -L "$rehearsal_state_file" ]; then
        if [ -d "$rehearsal_plan_directory" ] && [ ! -L "$rehearsal_plan_directory" ]; then
            assert_non_symlink_regular_file "$rehearsal_ledger_before_file" \
                'uncommitted migration rehearsal ledger-before artifact'
            assert_non_symlink_regular_file "$rehearsal_schema_before_file" \
                'uncommitted migration rehearsal schema-before artifact'
            assert_non_symlink_regular_file "$rehearsal_pending_file" \
                'uncommitted migration rehearsal pending artifact'
            rehearsal_ledger_before_sha256=$(sha256_file "$rehearsal_ledger_before_file")
            rehearsal_schema_before_sha256=$(sha256_file "$rehearsal_schema_before_file")
            rehearsal_pending_sha256=$(sha256_file "$rehearsal_pending_file")
            [ "$rehearsal_ledger_before_sha256" = \
                "$rehearsal_expected_migration_ledger_sha256" ] \
                && [ "$rehearsal_pending_sha256" = \
                    "$rehearsal_expected_migration_pending_sha256" ] \
                || fail 'uncommitted migration rehearsal plan differs from requested inputs'
            rehearsal_uncommitted_schema="$operation_directory/.migration-rehearsal-uncommitted-schema"
            rehearsal_schema_snapshot "$rehearsal_uncommitted_schema"
            [ "$(sha256_file "$rehearsal_uncommitted_schema")" = \
                "$rehearsal_schema_before_sha256" ] \
                || fail 'clone schema changed before migration rehearsal plan state committed'
            rm -f "$rehearsal_uncommitted_schema"
            rehearsal_observed_expected_batch=$(rehearsal_database_psql --command \
                'SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations' | tr -d '[:space:]')
            [ "$rehearsal_observed_expected_batch" = \
                "$rehearsal_expected_migration_batch" ] \
                || fail 'clone batch changed before migration rehearsal plan state committed'
            rehearsal_runner_id=none
            rehearsal_runner_exit_code=none
            rehearsal_runner_log_sha256=none
            rehearsal_database_outcome=unknown
            write_migration_rehearsal_state planned
        else
            prepare_migration_rehearsal_plan
        fi
    fi
    reconcile_migration_rehearsal_runner
    note "migration-rehearsal-passed operation=$operation_id lock-timeout=$migration_lock_timeout statement-timeout=$migration_statement_timeout"
}

backup_quiesce_dispatch()
{
    backup_quiesce_test_mode=${CONTROL_PLANE_TEST_MODE:-0}
    operator_target=${CONTROL_PLANE_OPERATOR_TARGET:-production}
    case "${backup_quiesce_test_mode}:${operator_target}" in
        0:production)
            backup_quiesce_service_unit=$INSTALLED_BACKUP_QUIESCE_SERVICE_UNIT
            backup_quiesce_timer_unit=$INSTALLED_BACKUP_QUIESCE_TIMER_UNIT
            ;;
        1:lab)
            backup_quiesce_service_unit="$SCRIPT_DIRECTORY/backup-quiesce/control-plane-backup-quiesce-watchdog.service"
            backup_quiesce_timer_unit="$SCRIPT_DIRECTORY/backup-quiesce/control-plane-backup-quiesce-watchdog.timer"
            ;;
        *)
            fail 'backup quiesce requires exactly production mode or explicit lab test mode'
            ;;
    esac
    test_mode=$backup_quiesce_test_mode
    operator_uid=$(id -u)
    operator_gid=$(id -g)
    if is_test_mode; then
        immutable_uid=$operator_uid
        immutable_gid=$operator_gid
    else
        immutable_uid=0
        immutable_gid=0
    fi
    configure_global_transaction_lock
    acquire_lock
    require_command awk
    require_command grep
    require_command paste
    require_command sed
    require_command sha256sum
    require_command sort
    require_command stat
    require_command tr
    configure_and_verify_release_inventory
    backup_quiesce_controller=$release_backup_quiesce_controller
    backup_quiesce_controller_sha256=$(sha256_file "$backup_quiesce_controller")
    backup_quiesce_service_unit_sha256=$(sha256_file "$release_backup_quiesce_service_unit")
    backup_quiesce_timer_unit_sha256=$(sha256_file "$release_backup_quiesce_timer_unit")
    assert_non_symlink_regular_file "$backup_quiesce_controller" 'backup quiesce controller'
    assert_non_symlink_regular_file "$release_backup_quiesce_service_unit" \
        'release backup quiesce watchdog service unit'
    assert_non_symlink_regular_file "$release_backup_quiesce_timer_unit" \
        'release backup quiesce watchdog timer unit'
    assert_non_symlink_regular_file "$backup_quiesce_service_unit" 'backup quiesce watchdog service unit'
    assert_non_symlink_regular_file "$backup_quiesce_timer_unit" 'backup quiesce watchdog timer unit'
    [ "$(sha256_file "$backup_quiesce_service_unit")" = "$backup_quiesce_service_unit_sha256" ] \
        && [ "$(sha256_file "$backup_quiesce_timer_unit")" = "$backup_quiesce_timer_unit_sha256" ] \
        || fail 'backup quiesce controller or permanent watchdog unit differs from reviewed bytes'
    if [ "$backup_quiesce_test_mode" = 0 ]; then
        backup_quiesce_operator=/usr/local/sbin/control-plane-blue-green
    else
        backup_quiesce_operator=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)/$(basename -- "$0")
    fi
    assert_non_symlink_regular_file "$backup_quiesce_operator" \
        'backup quiesce installed dispatcher'
    CONTROL_PLANE_BACKUP_QUIESCE_OPERATOR_PATH=$backup_quiesce_operator
    CONTROL_PLANE_BACKUP_QUIESCE_OPERATOR_SHA256=$(sha256_file "$backup_quiesce_operator")
    CONTROL_PLANE_BACKUP_QUIESCE_CONTROLLER_PATH=$backup_quiesce_controller
    CONTROL_PLANE_BACKUP_QUIESCE_CONTROLLER_SHA256=$backup_quiesce_controller_sha256
    CONTROL_PLANE_BACKUP_QUIESCE_SERVICE_UNIT_PATH=$backup_quiesce_service_unit
    CONTROL_PLANE_BACKUP_QUIESCE_SERVICE_UNIT_SHA256=$backup_quiesce_service_unit_sha256
    CONTROL_PLANE_BACKUP_QUIESCE_TIMER_UNIT_PATH=$backup_quiesce_timer_unit
    CONTROL_PLANE_BACKUP_QUIESCE_TIMER_UNIT_SHA256=$backup_quiesce_timer_unit_sha256
    export CONTROL_PLANE_BACKUP_QUIESCE_OPERATOR_PATH
    export CONTROL_PLANE_BACKUP_QUIESCE_OPERATOR_SHA256
    export CONTROL_PLANE_BACKUP_QUIESCE_CONTROLLER_PATH
    export CONTROL_PLANE_BACKUP_QUIESCE_CONTROLLER_SHA256
    export CONTROL_PLANE_BACKUP_QUIESCE_SERVICE_UNIT_PATH
    export CONTROL_PLANE_BACKUP_QUIESCE_SERVICE_UNIT_SHA256
    export CONTROL_PLANE_BACKUP_QUIESCE_TIMER_UNIT_PATH
    export CONTROL_PLANE_BACKUP_QUIESCE_TIMER_UNIT_SHA256
    assert_release_asset_identity backup-quiesce-controller
    exec "$backup_quiesce_controller" "$@"
}

usage()
{
    printf '%s\n' 'Usage: control-plane-blue-green.sh {preflight|apply-migrations|cutover|rollback|recover-abort|recover-forward|recover-reverse-forward|abort-failback|promote|rehearse-migrations|backup-quiesce ACTION ...}' >&2
    exit 64
}

main()
{
    if [ "$#" -ge 2 ] && [ "$1" = backup-quiesce ]; then
        shift
        require_command flock
        backup_quiesce_dispatch "$@"
    fi
    [ "$#" -eq 1 ] || usage
    command_name=$1

    require_command flock
    load_configuration

    require_command awk
    require_command cat
    require_command cmp
    require_command curl
    require_command cp
    require_command date
    require_command dd
    require_command docker
    require_command grep
    require_command hostname
    require_command jq
    require_command ln
    require_command paste
    require_command sed
    require_command sha256sum
    require_command stat
    require_command sort
    require_command sync
    require_command tr

    case "$command_name" in
        preflight)
            preflight
            ;;
        apply-migrations)
            apply_migrations
            ;;
        cutover)
            cutover
            ;;
        rollback)
            rollback
            ;;
        recover-abort)
            recover_abort
            ;;
        recover-forward)
            recover_forward
            ;;
        recover-reverse-forward)
            recover_reverse_forward
            ;;
        abort-failback)
            abort_failback
            ;;
        promote)
            promote
            ;;
        rehearse-migrations)
            rehearse_migrations
            ;;
        *)
            usage
            ;;
    esac
}

main "$@"
