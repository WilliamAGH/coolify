#!/bin/sh

set -eu

LAB_DIRECTORY=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)
REPOSITORY_ROOT=$(CDPATH='' cd -- "$LAB_DIRECTORY/../../.." && pwd -P)
OPERATOR="$REPOSITORY_ROOT/docker/control-plane-blue-green/control-plane-blue-green.sh"
if [ -n "${CONTROL_PLANE_BLUE_GREEN_LAB_DIR:-}" ]; then
    mkdir -p "$CONTROL_PLANE_BLUE_GREEN_LAB_DIR"
    LAB_ROOT=$(mktemp -d "$CONTROL_PLANE_BLUE_GREEN_LAB_DIR/invocation.XXXXXX")
else
    LAB_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/coolify-control-plane-blue-green.XXXXXX")
fi
LAB_ROOT=$(CDPATH='' cd -- "$LAB_ROOT" && pwd -P)
CONTROL_PLANE_MIGRATION_NAMES_FILE="$LAB_ROOT/control-plane-migrations.txt"
CONTROL_PLANE_MIGRATION_FINGERPRINT="$REPOSITORY_ROOT/database/migrations/control-plane-migration-inventory.fingerprint"
LAB_INVOCATION_TOKEN=$(printf '%s' "$LAB_ROOT" | sha256sum | awk '{print substr($1, 1, 12)}')
LAB_PORT_SLOT=
LAB_PORT_BASE=20000
LAB_PORT_BAND_COUNT=8
LAB_PORT_BAND_WIDTH=42
LAB_PORT_MAX_SCENARIO=41
LAB_PORT_BLOCK_WIDTH=$((LAB_PORT_BAND_COUNT * LAB_PORT_BAND_WIDTH))
LAB_PORT_SLOT_COUNT=$(((65535 - LAB_PORT_BASE - \
    ((LAB_PORT_BAND_COUNT - 1) * LAB_PORT_BAND_WIDTH + LAB_PORT_MAX_SCENARIO)) \
    / LAB_PORT_BLOCK_WIDTH + 1))
MOCK_IMAGE="control-plane-blue-green-lab:$LAB_INVOCATION_TOKEN"
MOCK_REGISTRY_IMAGE='registry@sha256:a3d8aaa63ed8681a604f1dea0aa03f100d5895b6a58ace528858a7b332415373'
MOCK_REGISTRY_CONTAINER="control-plane-blue-green-registry-$LAB_INVOCATION_TOKEN"
MOCK_REGISTRY_TAG=
MOCK_IMMUTABLE_IMAGE=
active_lab=0
registered_worker_pids=
paused_dependency_container=
PATH="$LAB_DIRECTORY:$PATH"
PYTHONDONTWRITEBYTECODE=1
export PATH PYTHONDONTWRITEBYTECODE
unset CONTROL_PLANE_RUNTIME_ENV_FILE

fail()
{
    printf 'CONTROL_PLANE_BLUE_GREEN_LAB_FAILURE %s\n' "$1" >&2
    exit 1
}

assert_proxy_digest_contract()
{
    operator_proxy_digest=$(sed -n \
        's/^readonly EXPECTED_PROXY_DIGEST=\(sha256:[a-f0-9]\{64\}\)$/\1/p' "$OPERATOR")
    compose_proxy_digest=$(sed -n \
        's/^[[:space:]]*image: "traefik:[^@]*@\(sha256:[a-f0-9]\{64\}\)"$/\1/p' \
        "$LAB_DIRECTORY/compose.yaml")
    [ -n "$operator_proxy_digest" ] && [ "$operator_proxy_digest" = "$compose_proxy_digest" ] \
        || fail 'operator preserved Traefik digest differs from the lab Compose release digest'
}

assert_proxy_digest_contract

discover_control_plane_migrations()
{
    if ! (
        cd "$REPOSITORY_ROOT"
        php artisan start:migration --print-control-plane-migration-inventory --no-interaction
    ) > "$CONTROL_PLANE_MIGRATION_NAMES_FILE" 2>&1; then
        fail 'production control-plane migration inventory command failed'
    fi

    [ -s "$CONTROL_PLANE_MIGRATION_NAMES_FILE" ] \
        || fail 'production control-plane migration inventory command returned no migrations'
}

register_worker()
{
    worker_pid_to_register=$1
    case "$worker_pid_to_register" in
        ''|*[!0-9]*) fail "invalid background worker PID: $worker_pid_to_register" ;;
    esac
    case " $registered_worker_pids " in
        *" $worker_pid_to_register "*)
            fail "background worker PID was registered twice: $worker_pid_to_register"
            ;;
    esac
    registered_worker_pids="${registered_worker_pids}${registered_worker_pids:+ }${worker_pid_to_register}"
}

unregister_worker()
{
    worker_pid_to_unregister=$1
    remaining_worker_pids=
    worker_pid_was_registered=0
    for registered_worker_pid in $registered_worker_pids; do
        if [ "$registered_worker_pid" = "$worker_pid_to_unregister" ]; then
            worker_pid_was_registered=1
        else
            remaining_worker_pids="${remaining_worker_pids}${remaining_worker_pids:+ }${registered_worker_pid}"
        fi
    done
    [ "$worker_pid_was_registered" = 1 ] \
        || fail "background worker PID was not registered: $worker_pid_to_unregister"
    registered_worker_pids=$remaining_worker_pids
}

wait_registered_worker()
{
    registered_wait_pid=$1
    registered_wait_status=0
    wait "$registered_wait_pid" || registered_wait_status=$?
    unregister_worker "$registered_wait_pid"
    return "$registered_wait_status"
}

terminate_registered_workers()
{
    for registered_worker_pid in $registered_worker_pids; do
        kill -TERM "$registered_worker_pid" >/dev/null 2>&1 || true
    done
    for registered_worker_pid in $registered_worker_pids; do
        wait "$registered_worker_pid" >/dev/null 2>&1 || true
    done
    registered_worker_pids=
}

require_command()
{
    command -v "$1" >/dev/null 2>&1 || fail "required command is unavailable: $1"
}

reserve_lab_port_slot()
{
    port_lock_root=/tmp
    port_slot_start=$(printf '%s' "$LAB_ROOT" | cksum \
        | awk -v slot_count="$LAB_PORT_SLOT_COUNT" '{print $1 % slot_count}')
    exec 8>"$port_lock_root/coolify-control-plane-blue-green-port-allocation.lock"
    # The compatibility flock command is an external Python process. Re-duplicate
    # the shell-opened handle so shells that set close-on-exec still pass it through.
    exec 7>&8 8>&7 7>&-
    if flock -w 10 8; then
        :
    else
        port_allocator_lock_status=$?
        if [ "$port_allocator_lock_status" -eq 1 ]; then
            fail 'timed out acquiring the control-plane lab port allocator lock'
        fi
        fail "control-plane lab port allocator lock failed: status=$port_allocator_lock_status"
    fi
    port_slot_offset=0
    while [ "$port_slot_offset" -lt "$LAB_PORT_SLOT_COUNT" ]; do
        port_slot_candidate=$(((port_slot_start + port_slot_offset) % LAB_PORT_SLOT_COUNT))
        exec 9>"$port_lock_root/coolify-control-plane-blue-green-port-slot-${port_slot_candidate}.lock"
        exec 7>&9 9>&7 7>&-
        if flock -n 9; then
            if python3 - "$port_slot_candidate" "$LAB_PORT_BASE" \
                "$LAB_PORT_BAND_COUNT" "$LAB_PORT_BAND_WIDTH" \
                "$LAB_PORT_MAX_SCENARIO" <<'PY'
import socket
import sys

slot = int(sys.argv[1])
port_base = int(sys.argv[2])
band_count = int(sys.argv[3])
band_width = int(sys.argv[4])
max_scenario = int(sys.argv[5])
block_base = port_base + slot * band_count * band_width
ports = [
    block_base + port_type * band_width + scenario
    for port_type in range(band_count)
    for scenario in range(1, max_scenario + 1)
]
if len(ports) != len(set(ports)) or max(ports) > 65535:
    raise SystemExit(1)
sockets = []
try:
    for port in ports:
        listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        listener.bind(("127.0.0.1", port))
        sockets.append(listener)
except OSError:
    raise SystemExit(1)
finally:
    for listener in sockets:
        listener.close()
PY
            then
                LAB_PORT_SLOT=$port_slot_candidate
                break
            fi
        else
            port_slot_lock_status=$?
            [ "$port_slot_lock_status" -eq 1 ] \
                || fail "control-plane lab port slot lock failed: slot=$port_slot_candidate;status=$port_slot_lock_status"
        fi
        exec 9>&-
        port_slot_offset=$((port_slot_offset + 1))
    done
    flock -u 8
    exec 8>&-
    [ -n "$LAB_PORT_SLOT" ] || fail 'no collision-free control-plane lab port slot is available'
}

prepare_mock_image_context()
{
    mock_image_context="$LAB_ROOT/mock-control-plane-image"
    mkdir -p "$mock_image_context"
    cp -R "$LAB_DIRECTORY/mock-control-plane/." "$mock_image_context/"
    cp "$REPOSITORY_ROOT/docker/production/bin/control-plane-direct-probe-healthcheck" \
        "$mock_image_context/control-plane-direct-probe-healthcheck"
    mkdir -p "$mock_image_context/app/Support" "$mock_image_context/config" "$mock_image_context/migrations" \
        "$mock_image_context/vendor/composer" \
        "$mock_image_context/vendor/doctrine" \
        "$mock_image_context/vendor/laravel" \
        "$mock_image_context/vendor/psr" \
        "$mock_image_context/vendor/symfony"
    cp "$REPOSITORY_ROOT/config/database.php" "$mock_image_context/config/database.php"
    cp "$REPOSITORY_ROOT/app/Support/ControlPlaneMigrationInventory.php" \
        "$mock_image_context/app/Support/ControlPlaneMigrationInventory.php"
    while IFS= read -r migration_name; do
        case "$migration_name" in
            ''|*/*) fail "canonical migration inventory returned an unsafe name: $migration_name" ;;
        esac
        migration_file="$REPOSITORY_ROOT/database/migrations/${migration_name}.php"
        [ -f "$migration_file" ] && [ ! -L "$migration_file" ] && [ -r "$migration_file" ] \
            || fail "authorized migration source is unsafe or unavailable: $migration_name"
        cp "$migration_file" "$mock_image_context/migrations/${migration_name}.php"
    done < "$CONTROL_PLANE_MIGRATION_NAMES_FILE"
    cp "$REPOSITORY_ROOT/database/migrations/control-plane-immutable-baseline-surplus.list" \
        "$mock_image_context/migrations/control-plane-immutable-baseline-surplus.list"
    cp "$CONTROL_PLANE_MIGRATION_FINGERPRINT" \
        "$mock_image_context/migrations/control-plane-migration-inventory.fingerprint"
    cp "$REPOSITORY_ROOT/vendor/composer/ClassLoader.php" \
        "$mock_image_context/vendor/composer/ClassLoader.php"
    cp "$REPOSITORY_ROOT/vendor/composer/autoload_psr4.php" \
        "$mock_image_context/vendor/composer/autoload_psr4.php"
    cp -R "$REPOSITORY_ROOT/vendor/doctrine/inflector" "$mock_image_context/vendor/doctrine/"
    cp -R "$REPOSITORY_ROOT/vendor/laravel/framework" "$mock_image_context/vendor/laravel/"
    cp -R "$REPOSITORY_ROOT/vendor/psr/container" "$mock_image_context/vendor/psr/"
    cp -R "$REPOSITORY_ROOT/vendor/symfony/polyfill-php85" \
        "$mock_image_context/vendor/symfony/"
}

file_mode()
{
    stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"
}

file_uid()
{
    stat -c '%u' "$1" 2>/dev/null || stat -f '%u' "$1"
}

file_gid()
{
    stat -c '%g' "$1" 2>/dev/null || stat -f '%g' "$1"
}

file_link_count()
{
    stat -c '%h' "$1" 2>/dev/null || stat -f '%l' "$1"
}

file_checksum_or_absent()
{
    if [ -f "$1" ]; then
        sha256sum "$1" | awk '{print $1}'
    else
        printf '%s\n' absent
    fi
}

configure_lab_global_transaction_lock()
{
    chmod 700 "$scenario_directory"
    CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE="$scenario_directory/control-plane-blue-green.lock"
    export CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE

    case "$CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE" in
        /*) ;;
        *) fail 'lab global transaction lock path is not absolute' ;;
    esac

    global_transaction_lock_parent=${CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE%/*}
    canonical_global_transaction_lock_parent=$(CDPATH='' \
        cd -- "$global_transaction_lock_parent" && pwd -P) \
        || fail 'lab global transaction lock parent is not canonical'
    [ "$canonical_global_transaction_lock_parent" = "$global_transaction_lock_parent" ] \
        && [ -d "$global_transaction_lock_parent" ] \
        && [ ! -L "$global_transaction_lock_parent" ] \
        && [ "$(file_uid "$global_transaction_lock_parent")" = "$(id -u)" ] \
        && [ "$(file_mode "$global_transaction_lock_parent")" = 700 ] \
        || fail 'lab global transaction lock parent is unsafe'
    [ ! -e "$CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE" ] \
        && [ ! -L "$CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE" ] \
        || fail 'lab global transaction lock path is not fresh'
}

assert_lab_global_transaction_lock()
{
    [ -f "$CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE" ] \
        && [ ! -L "$CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE" ] \
        && [ "$(file_uid "$CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE")" = "$(id -u)" ] \
        && [ "$(file_gid "$CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE")" = "$(id -g)" ] \
        && [ "$(file_mode "$CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE")" = 600 ] \
        && [ "$(file_link_count "$CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE")" = 1 ] \
        || fail 'lab global transaction lock owner, mode, link count, or file type is unsafe'
}

install_lab_release_manifest()
{
    release_source_root="$scenario_directory/release-source"
    cp -R "$REPOSITORY_ROOT/docker/control-plane-blue-green" "$release_source_root"
    release_installer="$release_source_root/install-host-release-bundle.sh"
    release_host_root="$scenario_directory/release-host"
    mkdir "$release_host_root"
    chmod 0700 "$release_host_root"
    CONTROL_PLANE_RELEASE_MANIFEST_FILE="$release_host_root/etc/coolify-control-plane/release.manifest"
    CONTROL_PLANE_RELEASES_ROOT="$release_host_root/usr/local/lib/coolify-control-plane/releases"
    CONTROL_PLANE_RELEASE_ID="release-${scenario_number}-${LAB_INVOCATION_TOKEN}"
    CONTROL_PLANE_RELEASE_BACKUP_QUIESCE_CONTROLLER="$REPOSITORY_ROOT/docker/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce.sh"
    CONTROL_PLANE_RELEASE_RUNTIME_FENCE_CONTROLLER="$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/runtime-attestation-ssh-fence.sh"
    CONTROL_PLANE_RELEASE_RUNTIME_FENCE_REAPER="$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/self-ssh-controlmaster-reaper.sh"
    CONTROL_PLANE_RELEASE_RUNTIME_FENCE_PROVIDER_PROBE="$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/traefik-docker-provider-freshness-probe.sh"
    CONTROL_PLANE_RELEASE_RUNTIME_FENCE_QUEUE_PROBE="$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/proxy-queue-zero-probe.sh"
    CONTROL_PLANE_RELEASE_RUNTIME_FENCE_TERMINAL_PROBE="$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/control-plane-terminal-state-probe.sh"

    release_directory="$CONTROL_PLANE_RELEASES_ROOT/$CONTROL_PLANE_RELEASE_ID"
    release_install_output=$(CONTROL_PLANE_RELEASE_BUNDLE_TEST_MODE=1 \
        CONTROL_PLANE_RELEASE_BUNDLE_TEST_ROOT="$release_host_root" \
        CONTROL_PLANE_RELEASE_BUNDLE_SOURCE_ROOT="$release_source_root" \
        "$release_installer" "$CONTROL_PLANE_RELEASE_ID")

    release_manifest_uid=$(id -u)
    release_manifest_gid=$(id -g)
    [ -f "$CONTROL_PLANE_RELEASE_MANIFEST_FILE" ] \
        && [ ! -L "$CONTROL_PLANE_RELEASE_MANIFEST_FILE" ] \
        && [ "$(file_uid "$CONTROL_PLANE_RELEASE_MANIFEST_FILE")" = "$release_manifest_uid" ] \
        && [ "$(file_gid "$CONTROL_PLANE_RELEASE_MANIFEST_FILE")" = "$release_manifest_gid" ] \
        && [ "$(file_mode "$CONTROL_PLANE_RELEASE_MANIFEST_FILE")" = 600 ] \
        || fail 'lab release manifest owner, mode, or file type is unsafe'
    CONTROL_PLANE_RELEASE_MANIFEST_SHA256=$(sha256sum \
        "$CONTROL_PLANE_RELEASE_MANIFEST_FILE" | awk '{print $1}')
    [ "$release_install_output" = \
        "CONTROL_PLANE_RELEASE_BUNDLE_INSTALL complete=true release_id=${CONTROL_PLANE_RELEASE_ID} manifest=${CONTROL_PLANE_RELEASE_MANIFEST_FILE} manifest_sha256=${CONTROL_PLANE_RELEASE_MANIFEST_SHA256} release_directory=${release_directory}" ] \
        || fail 'lab release bundle output does not bind the exported manifest identity'

    OPERATOR="$release_directory/control-plane-blue-green.sh"
    CONTROL_PLANE_OPERATOR_COMPOSE_FILE="$release_directory/compose.yaml"
    CONTROL_PLANE_REHEARSAL_COMPOSE_FILE="$release_directory/compose.rehearsal.yaml"
    CONTROL_PLANE_INGRESS_CONTROLLER="$release_directory/controllers/traefik-ingress.sh"
    CONTROL_PLANE_RELEASE_BACKUP_ATTESTATION_VERIFIER="$release_directory/backup/restore-attest.sh"
    CONTROL_PLANE_RELEASE_BACKUP_QUIESCE_CONTROLLER="$release_directory/backup-quiesce/control-plane-backup-quiesce.sh"
    CONTROL_PLANE_RELEASE_RUNTIME_FENCE_CONTROLLER="$release_directory/controllers/runtime-attestation-ssh-fence.sh"
    CONTROL_PLANE_RELEASE_RUNTIME_FENCE_REAPER="$release_directory/controllers/self-ssh-controlmaster-reaper.sh"
    CONTROL_PLANE_RELEASE_RUNTIME_FENCE_PROVIDER_PROBE="$release_directory/controllers/traefik-docker-provider-freshness-probe.sh"
    CONTROL_PLANE_RELEASE_RUNTIME_FENCE_QUEUE_PROBE="$release_directory/controllers/proxy-queue-zero-probe.sh"
    CONTROL_PLANE_RELEASE_RUNTIME_FENCE_TERMINAL_PROBE="$release_directory/controllers/control-plane-terminal-state-probe.sh"
}

assert_release_manifest_asset_attestation()
{
    manifest_asset_role=$1
    manifest_asset_path=$2
    manifest_asset_expected_mode=$3
    [ -f "$manifest_asset_path" ] && [ ! -L "$manifest_asset_path" ] \
        || fail "reviewed release asset is unavailable or unsafe: $manifest_asset_role"
    [ "$(file_mode "$manifest_asset_path")" = "$manifest_asset_expected_mode" ] \
        || fail "reviewed release asset mode diverges from its release contract: $manifest_asset_role"
    manifest_asset_expected_sha256=$(sha256sum "$manifest_asset_path" | awk '{print $1}')
    manifest_asset_expected_line="asset|$manifest_asset_role|$manifest_asset_path|$manifest_asset_expected_sha256|$(file_uid "$manifest_asset_path")|$(file_gid "$manifest_asset_path")|$manifest_asset_expected_mode"
    if ! manifest_asset_line=$(awk -F'|' -v expected_role="$manifest_asset_role" '
        $1 == "asset" && $2 == expected_role { matches++; line = $0 }
        END { if (matches != 1) exit 1; print line }
    ' "$CONTROL_PLANE_RELEASE_MANIFEST_FILE"); then
        fail "release manifest does not contain one attested asset: $manifest_asset_role"
    fi
    [ "$manifest_asset_line" = "$manifest_asset_expected_line" ] \
        || fail "release manifest does not attest the reviewed source bytes: $manifest_asset_role"
}

assert_release_manifest_source_attestation()
{
    [ "$(sha256sum "$CONTROL_PLANE_RELEASE_MANIFEST_FILE" | awk '{print $1}')" \
        = "$CONTROL_PLANE_RELEASE_MANIFEST_SHA256" ] \
        || fail 'lab release manifest differs from its exported out-of-band identity'
    assert_release_manifest_asset_attestation backup-quiesce-controller \
        "$CONTROL_PLANE_RELEASE_BACKUP_QUIESCE_CONTROLLER" 755
    assert_release_manifest_asset_attestation backup-attestation-verifier \
        "$CONTROL_PLANE_RELEASE_BACKUP_ATTESTATION_VERIFIER" 755
    assert_release_manifest_asset_attestation backup-quiesce-service-unit \
        "$release_directory/backup-quiesce/control-plane-backup-quiesce-watchdog.service" 644
    assert_release_manifest_asset_attestation backup-quiesce-timer-unit \
        "$release_directory/backup-quiesce/control-plane-backup-quiesce-watchdog.timer" 644
    grep -F -x -q '    verify_release_manifest' "$OPERATOR" \
        || fail 'operator does not verify the release manifest before preflight work'
    grep -F -x -q "        assert_release_asset_identity \"\$release_required_role\"" "$OPERATOR" \
        || fail 'operator does not attest each manifest-selected release asset'
}

assert_release_manifest_preflight_rejections()
{
    release_preflight_bin="$scenario_directory/release-preflight-bin"
    release_preflight_docker_marker="$scenario_directory/release-preflight-docker-invoked"
    release_preflight_output="$scenario_directory/release-preflight-output"
    mkdir "$release_preflight_bin"
    {
        printf '%s\n' '#!/bin/sh' 'set -eu'
        printf '%s\n' ": > \"\${CONTROL_PLANE_RELEASE_PREFLIGHT_DOCKER_MARKER}\"" 'exit 97'
    } > "$release_preflight_bin/docker"
    chmod 700 "$release_preflight_bin/docker"

    missing_release_manifest="$scenario_directory/missing-release.manifest"
    if PATH="$release_preflight_bin:$PATH" \
        CONTROL_PLANE_RELEASE_PREFLIGHT_DOCKER_MARKER="$release_preflight_docker_marker" \
        CONTROL_PLANE_RELEASE_MANIFEST_FILE="$missing_release_manifest" \
        "$OPERATOR" preflight > "$release_preflight_output" 2>&1; then
        fail 'operator accepted a missing release manifest'
    fi
    if [ -e "$release_preflight_docker_marker" ] \
        || ! grep -F -q 'host release manifest must be a readable regular non-symlink file' \
            "$release_preflight_output"; then
        fail 'missing release manifest was not rejected before Docker or Compose'
    fi

    tampered_release_manifest="$scenario_directory/tampered-release.manifest"
    cp "$CONTROL_PLANE_RELEASE_MANIFEST_FILE" "$tampered_release_manifest"
    printf '%s\n' tampered >> "$tampered_release_manifest"
    chmod 600 "$tampered_release_manifest"
    if PATH="$release_preflight_bin:$PATH" \
        CONTROL_PLANE_RELEASE_PREFLIGHT_DOCKER_MARKER="$release_preflight_docker_marker" \
        CONTROL_PLANE_RELEASE_MANIFEST_FILE="$tampered_release_manifest" \
        "$OPERATOR" preflight > "$release_preflight_output" 2>&1; then
        fail 'operator accepted a tampered release manifest'
    fi
    if [ -e "$release_preflight_docker_marker" ] \
        || ! grep -F -q 'host release manifest differs from its expected out-of-band hash' \
            "$release_preflight_output"; then
        fail 'tampered release manifest was not rejected before Docker or Compose'
    fi

}

write_sanitized_probe_headers()
{
    sanitized_headers_source=$1
    sanitized_headers_destination=$2

    if [ ! -f "$sanitized_headers_source" ]; then
        : > "$sanitized_headers_destination"
        return
    fi

    awk -F: '
        {
            header_name = tolower($1)
            sub(/\r$/, "", header_name)
            if (header_name == "authorization" || header_name == "set-cookie" \
                || header_name == "x-control-plane-probe" \
                || header_name == "x-control-plane-applied-config" \
                || header_name == "x-control-plane-route-ack") {
                next
            }
            print
        }
    ' "$sanitized_headers_source" > "$sanitized_headers_destination"
}

response_header_equals_once()
{
    response_header_file=$1
    response_header_name=$2
    response_header_expected=$3

    awk -F: -v expected_name="$response_header_name" -v expected_value="$response_header_expected" '
        tolower($1) == tolower(expected_name) {
            count++
            observed = substr($0, index($0, ":") + 1)
            sub(/^[[:space:]]*/, "", observed)
            sub(/\r$/, "", observed)
        }
        END { exit count != 1 || observed != expected_value }
    ' "$response_header_file"
}

preserve_legacy_ingress_failure()
{
    legacy_evidence_directory=$scenario_directory/legacy-ingress-readiness
    mkdir -p "$legacy_evidence_directory"
    chmod 700 "$legacy_evidence_directory"
    legacy_evidence_status_candidate="$legacy_evidence_directory/.status.$$"
    legacy_evidence_https_headers_candidate="$legacy_evidence_directory/.https-headers.$$"

    {
        printf 'attempt=%s\n' "$legacy_readiness_attempt"
        printf 'https_curl_status=%s\n' "$legacy_https_curl_status"
        printf 'https_http_status=%s\n' "$legacy_https_http_status"
        printf 'https_body_sha256=%s\n' "$(file_checksum_or_absent "$legacy_https_body")"
    } > "$legacy_evidence_status_candidate"
    write_sanitized_probe_headers "$legacy_https_headers" "$legacy_evidence_https_headers_candidate"
    mv "$legacy_evidence_status_candidate" "$legacy_evidence_directory/status"
    mv "$legacy_evidence_https_headers_candidate" "$legacy_evidence_directory/https.headers"
}

wait_for_blue()
{
    legacy_evidence_directory=$scenario_directory/legacy-ingress-readiness
    legacy_https_headers="$legacy_evidence_directory/https.raw-headers"
    legacy_https_body="$legacy_evidence_directory/https.raw-body"
    mkdir -p "$legacy_evidence_directory"
    chmod 700 "$legacy_evidence_directory"
    rm -f "$legacy_evidence_directory/status" "$legacy_evidence_directory/https.headers" \
        "$legacy_https_headers" "$legacy_https_body"
    legacy_readiness_deadline=$(( $(date -u +%s) + 120 ))
    legacy_readiness_attempt=0

    while [ "$(date -u +%s)" -lt "$legacy_readiness_deadline" ]; do
        legacy_readiness_attempt=$((legacy_readiness_attempt + 1))
        legacy_https_curl_status=0
        legacy_https_http_status=$(curl --silent --show-error --connect-timeout 1 --max-time 2 \
            --header "Host: $CONTROL_PLANE_HOST" \
            --dump-header "$legacy_https_headers" --output "$legacy_https_body" \
            --write-out '%{http_code}' \
            "https://127.0.0.1:${LAB_TRAEFIK_PORT}/cgi-bin/request") \
            || legacy_https_curl_status=$?
        legacy_https_http_status=${legacy_https_http_status:-000}
        if [ "$legacy_https_curl_status" -eq 0 ] \
            && [ "$legacy_https_http_status" = 200 ] \
            && response_header_equals_once "$legacy_https_headers" X-Control-Plane-Color legacy \
            && response_header_equals_once "$legacy_https_headers" X-Control-Plane-Lab-Host "$CONTROL_PLANE_HOST" \
            && [ "$(tr -d '\r\n' < "$legacy_https_body")" = legacy ]; then
            rm -f "$legacy_https_headers" "$legacy_https_body"
            return
        fi

        sleep 1
    done

    preserve_legacy_ingress_failure
    rm -f "$legacy_https_headers" "$legacy_https_body"
    fail "legacy ingress did not become ready within 120 seconds; sanitized evidence=$legacy_evidence_directory"
}

assert_source_traefik_identity()
{
    docker inspect "$CONTROL_PLANE_BLUE_CONTAINER" | jq --exit-status \
        --arg host "$CONTROL_PLANE_HOST" \
        --arg project "$CONTROL_PLANE_SOURCE_COMPOSE_PROJECT" \
        --arg router "$CONTROL_PLANE_TRAEFIK_ROUTER" \
        --arg service "$CONTROL_PLANE_TRAEFIK_SERVICE" \
        --arg backend_port "$CONTROL_PLANE_BACKEND_PORT" '
            .[0] as $container
            | ($container.Config.Env // []) as $environment
            | ($container.Config.Labels // {}) as $labels
            | ($environment | index("LAB_EXPECTED_HOST=" + $host) != null)
            and ($labels["com.docker.compose.project"] == $project)
            and ($labels["traefik.enable"] == "true")
            and ($labels["traefik.http.routers." + $router + ".rule"] == ("Host(`" + $host + "`)"))
            and ($labels["traefik.http.routers." + $router + ".entrypoints"] == "web")
            and ($labels["traefik.http.routers." + $router + ".tls"] == "true")
            and ($labels["traefik.http.routers." + $router + ".priority"] == "10")
            and ($labels["traefik.http.routers." + $router + ".service"] == $service)
            and ($labels["traefik.http.services." + $service + ".loadbalancer.server.port"] == $backend_port)
        ' >/dev/null \
        || fail 'source container does not carry the operation-scoped Traefik identity'
}

assert_candidate_trusted_proxy_peer_addresses()
{
    candidate_container=$1
    configured_addresses=$(docker inspect "$candidate_container" | jq --exit-status --raw-output '
        [.[0].Config.Env[] | select(startswith("CONTROL_PLANE_TRUSTED_PROXY_ADDRESSES="))]
        | if length == 1 then .[0] | split("=")[1] else error("missing allowlist") end
    ') || fail 'candidate has no exact trusted-proxy allowlist environment value'
    observed_gateway=$(docker network inspect "$CONTROL_PLANE_NETWORK" | jq --exit-status --raw-output '
        [.[0].IPAM.Config[]?.Gateway? | select(. != null)] | unique | join(",")
    ') || fail 'lab control-plane network gateway was not observable'
    proxy_address=$(docker inspect "$CONTROL_PLANE_PROXY_CONTAINER" | jq --exit-status --raw-output \
        --arg network "$CONTROL_PLANE_NETWORK" '
            .[0].NetworkSettings.Networks[$network].IPAddress
            | select(type == "string" and length > 0)
        ') || fail 'lab proxy peer address was not observable'

    [ "$observed_gateway" = "$CONTROL_PLANE_TEST_PROXY_GATEWAY" ] \
        || fail 'lab control-plane network did not retain its non-default gateway'
    printf '%s\n' "$configured_addresses" | tr ',' '\n' | grep -F -x -q \
        "$CONTROL_PLANE_TEST_PROXY_GATEWAY" \
        || fail 'candidate trusted-proxy allowlist omitted the non-default gateway'
    printf '%s\n' "$configured_addresses" | tr ',' '\n' | grep -F -x -q "$proxy_address" \
        || fail 'candidate trusted-proxy allowlist omitted the actual proxy peer address'
}

assert_marker_absent()
{
    volume_name=$1

    docker run --rm --network none --user 0 \
        --mount "type=volume,source=${volume_name},target=/state,readonly" \
        --entrypoint /bin/sh "$MOCK_IMAGE" -ec 'test ! -e /state/writer-epoch'
}

assert_marker_equals()
{
    volume_name=$1
    expected_epoch=$2

    docker run --rm --network none \
        --mount "type=volume,source=${volume_name},target=/state,readonly" \
        --env "EXPECTED_EPOCH=$expected_epoch" \
        --entrypoint /bin/sh "$MOCK_IMAGE" -ec \
        'printf %s "$EXPECTED_EPOCH" | cmp -s - /state/writer-epoch' \
        || fail 'marker bytes did not equal the expected epoch'
}

assert_mutation_freeze_marker_absent()
{
    volume_name=$1

    docker run --rm --network none --user 0 \
        --mount "type=volume,source=${volume_name},target=/state,readonly" \
        --entrypoint /bin/sh "$MOCK_IMAGE" -ec '
            lease=/state/mutation-inflight.lock
            test ! -L "$lease"
            test -f "$lease"
            test "$(stat -c %u:%g:%a:%h:%s "$lease")" = 0:9999:660:1:0
            lease_identity=$(stat -c %d:%i "$lease")
            exec 8< "$lease"
            test "$(stat -Lc %d:%i /proc/self/fd/8)" = "$lease_identity"
            test "$(stat -c %d:%i "$lease")" = "$lease_identity"
            test ! -e /state/mutation-freeze-epoch
            test ! -L /state/mutation-freeze-epoch
        ' || fail 'mutation-freeze marker was not absent with its exact lease identity intact'
}

assert_mutation_freeze_marker_equals()
{
    volume_name=$1
    expected_epoch=$2

    docker run --rm --network none --user 0 \
        --mount "type=volume,source=${volume_name},target=/state,readonly" \
        --env "EXPECTED_EPOCH=$expected_epoch" \
        --entrypoint /bin/sh "$MOCK_IMAGE" -ec '
            lease=/state/mutation-inflight.lock
            marker=/state/mutation-freeze-epoch
            test ! -L "$lease"
            test -f "$lease"
            test "$(stat -c %u:%g:%a:%h:%s "$lease")" = 0:9999:660:1:0
            lease_identity=$(stat -c %d:%i "$lease")
            exec 8< "$lease"
            test "$(stat -Lc %d:%i /proc/self/fd/8)" = "$lease_identity"
            test "$(stat -c %d:%i "$lease")" = "$lease_identity"
            test ! -L "$marker"
            test -f "$marker"
            test "$(stat -c %u:%g:%a:%h "$marker")" = 0:9999:440:1
            marker_identity=$(stat -c %d:%i "$marker")
            exec 9< "$marker"
            test "$(stat -Lc %d:%i /proc/self/fd/9)" = "$marker_identity"
            test "$(stat -c %d:%i "$marker")" = "$marker_identity"
            printf %s "$EXPECTED_EPOCH" | cmp -s - /proc/self/fd/9
        ' || fail 'mutation-freeze marker bytes or exact lease identity is unsafe'
}

wait_for_mutation_freeze_exclusive_lease()
{
    volume_name=$1
    lease_wait_attempt=0

    while :; do
        lease_probe=$(docker run --rm --network none --user 0 \
            --mount "type=volume,source=${volume_name},target=/state,readonly" \
            --entrypoint /bin/sh "$MOCK_IMAGE" -ec '
                lease=/state/mutation-inflight.lock
                test ! -L "$lease"
                test -f "$lease"
                test "$(stat -c %u:%g:%a:%h:%s "$lease")" = 0:9999:660:1:0
                lease_identity=$(stat -c %d:%i "$lease")
                exec 8< "$lease"
                test "$(stat -Lc %d:%i /proc/self/fd/8)" = "$lease_identity"
                test "$(stat -c %d:%i "$lease")" = "$lease_identity"
                if flock -s -n 8; then
                    printf "%s\\n" shared-acquired
                else
                    printf "%s\\n" shared-blocked
                fi
            ') || fail 'mutation-freeze lease probe could not verify the exact shared lease inode'
        case "$lease_probe" in
            shared-blocked)
                return
                ;;
            shared-acquired)
                ;;
            *)
                fail 'mutation-freeze lease probe returned an invalid outcome'
                ;;
        esac
        lease_wait_attempt=$((lease_wait_attempt + 1))
        [ "$lease_wait_attempt" -lt 120 ] \
            || fail 'operator did not acquire the mutation freeze lease exclusively'
        sleep 0.1
    done
}

start_held_mutation_request()
{
    held_mutation_color=$1
    held_mutation_port=$2
    held_mutation_status_file=$scenario_directory/held-mutation-${held_mutation_color}.status
    rm -f "$LAB_RUNTIME_STATE_DIR/mutation-inflight.ready" \
        "$LAB_RUNTIME_STATE_DIR/mutation-inflight.release" "$held_mutation_status_file"
    curl --silent --show-error --connect-timeout 2 --max-time 60 \
        --request POST --header "Host: $CONTROL_PLANE_HOST" \
        --header 'X-Control-Plane-Mutation-Gate: hold' --output /dev/null \
        --write-out '%{http_code}' \
        "http://127.0.0.1:${held_mutation_port}/cgi-bin/request" \
        > "$held_mutation_status_file" &
    held_mutation_pid=$!
    register_worker "$held_mutation_pid"

    held_mutation_wait_attempt=0
    while ! { [ -f "$LAB_RUNTIME_STATE_DIR/mutation-inflight.ready" ] \
        && grep -F -x -q POST "$LAB_RUNTIME_STATE_DIR/mutation-inflight.ready"; }; do
        held_mutation_wait_attempt=$((held_mutation_wait_attempt + 1))
        [ "$held_mutation_wait_attempt" -lt 120 ] \
            || fail "pre-freeze ${held_mutation_color} mutation did not acquire its shared lease"
        sleep 0.1
    done
}

release_held_mutation_request()
{
    touch "$LAB_RUNTIME_STATE_DIR/mutation-inflight.release"
    held_mutation_status=0
    wait "$held_mutation_pid" || held_mutation_status=$?
    unregister_worker "$held_mutation_pid"
    [ "$held_mutation_status" -eq 0 ] \
        && [ "$(cat "$held_mutation_status_file")" = 200 ] \
        || fail 'pre-freeze mutation did not complete before marker publication'
}

start_post_freeze_mutation_request()
{
    post_freeze_port=$1
    post_freeze_status_file=$scenario_directory/post-freeze-mutation.status
    rm -f "$post_freeze_status_file"
    curl --silent --show-error --connect-timeout 2 --max-time 60 \
        --request POST --header "Host: $CONTROL_PLANE_HOST" --output /dev/null \
        --write-out '%{http_code}' \
        "http://127.0.0.1:${post_freeze_port}/cgi-bin/request" \
        > "$post_freeze_status_file" &
    post_freeze_pid=$!
    register_worker "$post_freeze_pid"
}

assert_post_freeze_mutation_locked()
{
    post_freeze_status=0
    wait "$post_freeze_pid" || post_freeze_status=$?
    unregister_worker "$post_freeze_pid"
    [ "$post_freeze_status" -eq 0 ] \
        && [ "$(cat "$post_freeze_status_file")" = 423 ] \
        || fail 'a mutation admitted after freeze activation did not receive HTTP 423'
}

assert_route_color()
{
    route_url=$1
    expected_color=$2
    curl --fail --silent --show-error --max-time 5 \
        --header "Host: $CONTROL_PLANE_HOST" --dump-header - --output /dev/null "$route_url" \
        | grep -F -i -q "X-Control-Plane-Color: $expected_color" \
        || fail "ingress did not remain acknowledged on $expected_color"
}

prepare_lab_tls()
{
    lab_tls_extension_file="$LAB_TRAEFIK_TLS_DIRECTORY/leaf.ext"

    openssl req -x509 -new -nodes -newkey rsa:2048 -days 2 \
        -subj '/CN=coolify-control-plane-lab-ca' \
        -keyout "$LAB_TRAEFIK_TLS_DIRECTORY/ca.key" \
        -out "$LAB_TRAEFIK_TLS_CA" >/dev/null 2>&1
    openssl req -new -nodes -newkey rsa:2048 \
        -subj '/CN=127.0.0.1' \
        -keyout "$LAB_TRAEFIK_TLS_DIRECTORY/leaf.key" \
        -out "$LAB_TRAEFIK_TLS_DIRECTORY/leaf.csr" >/dev/null 2>&1
    printf 'subjectAltName=IP:127.0.0.1,DNS:localhost,DNS:%s\n' "$CONTROL_PLANE_HOST" \
        > "$lab_tls_extension_file"
    openssl x509 -req -days 2 \
        -in "$LAB_TRAEFIK_TLS_DIRECTORY/leaf.csr" \
        -CA "$LAB_TRAEFIK_TLS_CA" \
        -CAkey "$LAB_TRAEFIK_TLS_DIRECTORY/ca.key" \
        -CAcreateserial -extfile "$lab_tls_extension_file" \
        -out "$LAB_TRAEFIK_TLS_DIRECTORY/leaf.crt" >/dev/null 2>&1
    chmod 600 "$LAB_TRAEFIK_TLS_DIRECTORY/ca.key" "$LAB_TRAEFIK_TLS_DIRECTORY/leaf.key"
    chmod 644 "$LAB_TRAEFIK_TLS_CA" "$LAB_TRAEFIK_TLS_DIRECTORY/leaf.crt"
    {
        printf '%s\n' 'tls:' '  certificates:' \
            '    - certFile: "/etc/traefik/tls/leaf.crt"' \
            '      keyFile: "/etc/traefik/tls/leaf.key"' \
            '  stores:' \
            '    default:' \
            '      defaultCertificate:' \
            '        certFile: "/etc/traefik/tls/leaf.crt"' \
            '        keyFile: "/etc/traefik/tls/leaf.key"'
    } > "$CONTROL_PLANE_TRAEFIK_DYNAMIC_DIR/tls.yml"
    chmod 600 "$CONTROL_PLANE_TRAEFIK_DYNAMIC_DIR/tls.yml"
}

start_lab()
{
    scenario_name=$1
    scenario_number=$2
    scenario_directory="$LAB_ROOT/${scenario_number}-${scenario_name}-${LAB_INVOCATION_TOKEN}"
    registered_worker_pids=
    backup_quiesce_writer_pid=
    held_owner_pid=
    zombie_parent_pid=
    boot_reconcile_pid=
    managed_app_container=
    installer_test_container=
    foreign_router_container=
    unset CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE
    unset CONTROL_PLANE_PROXY_ENROLLMENT_COMMAND
    unset CONTROL_PLANE_TEST_PROXY_ENROLLMENT_STATE_FILE
    unset CONTROL_PLANE_TEST_PROXY_ENROLLMENT_LOG_FILE
    unset CONTROL_PLANE_TEST_PROXY_ENROLLMENT_OPERATOR_STATE_FILE
    unset CONTROL_PLANE_TEST_PROXY_ENROLLMENT_PROXY_CONTAINER
    unset CONTROL_PLANE_TEST_PROXY_ENROLLMENT_BLUE_CONTAINER
    unset CONTROL_PLANE_TEST_PROXY_ENROLLMENT_PROXY_PROJECT
    unset CONTROL_PLANE_TEST_PROXY_ENROLLMENT_PROXY_NATIVE_COMPOSE
    unset CONTROL_PLANE_TEST_PROXY_ENROLLMENT_PROXY_LEGACY_COMPOSE
    unset CONTROL_PLANE_TEST_PROXY_ENROLLMENT_STATIC_CONFIG
    unset CONTROL_PLANE_TEST_PROXY_ENROLLMENT_NATIVE_STATIC_CONFIG
    unset CONTROL_PLANE_TEST_PROXY_ENROLLMENT_LEGACY_STATIC_CONFIG
    unset CONTROL_PLANE_TEST_PROXY_ENROLLMENT_COMPOSE_OVERRIDE
    unset CONTROL_PLANE_TEST_PROXY_ENROLLMENT_DYNAMIC_DIRECTORY
    unset CONTROL_PLANE_TEST_PROXY_ENROLLMENT_SOURCE_COMPOSE_FILES
    unset CONTROL_PLANE_TEST_PROXY_ENROLLMENT_MODE
    mkdir -p "$scenario_directory/dynamic" "$scenario_directory/tls" "$scenario_directory/state" \
        "$scenario_directory/runtime-state" \
        "$scenario_directory/ssh" "$scenario_directory/applications" \
        "$scenario_directory/databases" "$scenario_directory/services" \
        "$scenario_directory/backups" "$scenario_directory/green-secrets" \
        "$scenario_directory/blue-secrets"
    chmod 777 "$scenario_directory/runtime-state"
    cp "$LAB_DIRECTORY/backup-attestation-verifier.sh" \
        "$scenario_directory/backup-attestation-verifier"
    chmod 700 "$scenario_directory/backup-attestation-verifier"

    project_name="cpbg-${scenario_number}-${LAB_INVOCATION_TOKEN}"
    control_plane_network="${project_name}-network"
    CONTROL_PLANE_BLUE_CONTAINER="${project_name}-blue"
    CONTROL_PLANE_GREEN_WEB_A_CONTAINER="${project_name}-green-web-a"
    CONTROL_PLANE_GREEN_WEB_B_CONTAINER="${project_name}-green-web-b"
    CONTROL_PLANE_BLUE_WEB_A_CONTAINER="${project_name}-blue-web-a"
    CONTROL_PLANE_BLUE_WEB_B_CONTAINER="${project_name}-blue-web-b"
    CONTROL_PLANE_GREEN_CONTAINER=$CONTROL_PLANE_GREEN_WEB_A_CONTAINER
    CONTROL_PLANE_REPLACEMENT_BLUE_CONTAINER=$CONTROL_PLANE_BLUE_WEB_A_CONTAINER
    CONTROL_PLANE_PROXY_CONTAINER="${project_name}-proxy"
    CONTROL_PLANE_DATABASE_CONTAINER="${project_name}-database"
    CONTROL_PLANE_REHEARSAL_DATABASE_CONTAINER="${project_name}-rehearsal-database"
    CONTROL_PLANE_REDIS_CONTAINER="${project_name}-redis"
    CONTROL_PLANE_SOKETI_CONTAINER="${project_name}-realtime"
    CONTROL_PLANE_GREEN_WEB_A_PRIVATE_VOLUME="${project_name}-green-web-a-private"
    CONTROL_PLANE_GREEN_WEB_B_PRIVATE_VOLUME="${project_name}-green-web-b-private"
    CONTROL_PLANE_BLUE_WEB_A_PRIVATE_VOLUME="${project_name}-blue-web-a-private"
    CONTROL_PLANE_BLUE_WEB_B_PRIVATE_VOLUME="${project_name}-blue-web-b-private"
    CONTROL_PLANE_COORDINATION_VOLUME="${project_name}-coordination"
    CONTROL_PLANE_GREEN_STATE_VOLUME=$CONTROL_PLANE_GREEN_WEB_A_PRIVATE_VOLUME
    CONTROL_PLANE_BLUE_STATE_VOLUME=$CONTROL_PLANE_BLUE_WEB_A_PRIVATE_VOLUME
    CONTROL_PLANE_TRAEFIK_ROUTER="control-plane-${scenario_number}-${LAB_INVOCATION_TOKEN}"
    CONTROL_PLANE_TRAEFIK_SERVICE="${CONTROL_PLANE_TRAEFIK_ROUTER}-service"
    CONTROL_PLANE_HOST="${CONTROL_PLANE_TRAEFIK_ROUTER}.lab.test"
    LAB_TRAEFIK_TLS_DIRECTORY="$scenario_directory/tls"
    LAB_TRAEFIK_TLS_CA="$LAB_TRAEFIK_TLS_DIRECTORY/ca.crt"
    CURL_CA_BUNDLE=$LAB_TRAEFIK_TLS_CA
    CONTROL_PLANE_OPERATION_ID="operation-${scenario_name}-0123456789"
    CONTROL_PLANE_MUTATION_FREEZE_EPOCH="${CONTROL_PLANE_OPERATION_ID}.mutation-freeze"
    CONTROL_PLANE_REVERSE_MUTATION_FREEZE_EPOCH="${CONTROL_PLANE_OPERATION_ID}.reverse-mutation-freeze"
    CONTROL_PLANE_WRITER_EPOCH="green-${scenario_name}-writer-epoch-0123456789"
    CONTROL_PLANE_BLUE_WRITER_EPOCH="blue-${scenario_name}-writer-epoch-0123456789"
    CONTROL_PLANE_GREEN_WEB_A_WEB_EPOCH="green-${scenario_name}-web-a-epoch-0123456789"
    CONTROL_PLANE_GREEN_WEB_B_WEB_EPOCH="green-${scenario_name}-web-b-epoch-0123456789"
    CONTROL_PLANE_BLUE_WEB_A_WEB_EPOCH="blue-${scenario_name}-web-a-epoch-0123456789"
    CONTROL_PLANE_BLUE_WEB_B_WEB_EPOCH="blue-${scenario_name}-web-b-epoch-0123456789"
    CONTROL_PLANE_GREEN_WEB_EPOCH=$CONTROL_PLANE_GREEN_WEB_A_WEB_EPOCH
    CONTROL_PLANE_BLUE_WEB_EPOCH=$CONTROL_PLANE_BLUE_WEB_A_WEB_EPOCH
    CONTROL_PLANE_GREEN_WEB_A_ROUTE_DRAIN_EPOCH="green-${scenario_name}-drain-a-0123456789"
    CONTROL_PLANE_GREEN_WEB_B_ROUTE_DRAIN_EPOCH="green-${scenario_name}-drain-b-0123456789"
    CONTROL_PLANE_BLUE_WEB_A_ROUTE_DRAIN_EPOCH="blue-${scenario_name}-drain-a-0123456789"
    CONTROL_PLANE_BLUE_WEB_B_ROUTE_DRAIN_EPOCH="blue-${scenario_name}-drain-b-0123456789"
    CONTROL_PLANE_GREEN_WEB_A_ROUTE_IDENTITY=green-web-a-route-identity
    CONTROL_PLANE_GREEN_WEB_B_ROUTE_IDENTITY=green-web-b-route-identity
    CONTROL_PLANE_BLUE_WEB_A_ROUTE_IDENTITY=blue-web-a-route-identity
    CONTROL_PLANE_BLUE_WEB_B_ROUTE_IDENTITY=blue-web-b-route-identity
    CONTROL_PLANE_GREEN_POOL_LABEL_VALUE="${project_name}-green-pool"
    CONTROL_PLANE_BLUE_POOL_LABEL_VALUE="${project_name}-blue-pool"
    lab_port_block_base=$((LAB_PORT_BASE + LAB_PORT_SLOT * LAB_PORT_BLOCK_WIDTH))
    LAB_TRAEFIK_PORT=$((lab_port_block_base + scenario_number))
    CONTROL_PLANE_GREEN_LOOPBACK_PORT=$((lab_port_block_base + \
        2 * LAB_PORT_BAND_WIDTH + scenario_number))
    CONTROL_PLANE_GREEN_WEB_A_LOOPBACK_PORT=$CONTROL_PLANE_GREEN_LOOPBACK_PORT
    CONTROL_PLANE_GREEN_WEB_B_LOOPBACK_PORT=$((lab_port_block_base + \
        6 * LAB_PORT_BAND_WIDTH + scenario_number))
    CONTROL_PLANE_BLUE_LOOPBACK_PORT=$((lab_port_block_base + \
        3 * LAB_PORT_BAND_WIDTH + scenario_number))
    CONTROL_PLANE_BLUE_WEB_A_LOOPBACK_PORT=$CONTROL_PLANE_BLUE_LOOPBACK_PORT
    CONTROL_PLANE_BLUE_WEB_B_LOOPBACK_PORT=$((lab_port_block_base + \
        7 * LAB_PORT_BAND_WIDTH + scenario_number))
    CONTROL_PLANE_NETWORK=$control_plane_network
    CONTROL_PLANE_TEST_PROXY_GATEWAY="10.$((LAB_PORT_SLOT + 1)).${scenario_number}.1"
    CONTROL_PLANE_TEST_PROXY_SUBNET="10.$((LAB_PORT_SLOT + 1)).${scenario_number}.0/24"
    CONTROL_PLANE_GREEN_IMAGE=$MOCK_IMMUTABLE_IMAGE
    CONTROL_PLANE_BLUE_IMAGE=$CONTROL_PLANE_GREEN_IMAGE
    CONTROL_PLANE_OPERATOR_TARGET=lab
    CONTROL_PLANE_TEST_MODE=1
    CONTROL_PLANE_OPERATOR_STATE_DIR="$scenario_directory/state"
    CONTROL_PLANE_RUNTIME_FENCE_PROVISIONER="$LAB_DIRECTORY/runtime-fence-provisioner.sh"
    CONTROL_PLANE_RUNTIME_FENCE_PROVISIONER_SHA256=$(sha256sum \
        "$CONTROL_PLANE_RUNTIME_FENCE_PROVISIONER" | awk '{print $1}')
    CONTROL_PLANE_RUNTIME_FENCE_MANAGEMENT_ENDPOINTS=lo=127.0.0.1
    CONTROL_PLANE_RUNTIME_FENCE_ADDITIONAL_NETWORK_IDS=
    CONTROL_PLANE_RUNTIME_FENCE_SELF_SSH_TARGET=host.docker.internal
    CONTROL_PLANE_RUNTIME_FENCE_PROBE_MAX_AGE_SECONDS=30
    CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_API_URL=http://127.0.0.1:8080/api/rawdata
    CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_ROUTER="${CONTROL_PLANE_TRAEFIK_ROUTER}@docker"
    CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_SERVICE="${CONTROL_PLANE_TRAEFIK_SERVICE}@docker"
    CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_LEGACY_PORT=8080
    CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_HEADER_FILE=
    CONTROL_PLANE_RUNTIME_FENCE_LAB_STATE="$scenario_directory/runtime-fence-state"
    CONTROL_PLANE_TRAEFIK_DYNAMIC_DIR="$scenario_directory/dynamic"
    CONTROL_PLANE_TRAEFIK_DYNAMIC_FILENAME=control-plane-blue-green.yaml
    CONTROL_PLANE_TRAEFIK_ENTRYPOINT=web
    CONTROL_PLANE_LOCAL_INGRESS_ENTRYPOINT=coolify-local
    CONTROL_PLANE_TRAEFIK_TLS=true
    CONTROL_PLANE_TRAEFIK_CERT_RESOLVER=
    CONTROL_PLANE_BACKEND_PORT=8080
    CONTROL_PLANE_DIRECT_PROBE_PATH=/cgi-bin/probe
    CONTROL_PLANE_SOURCE_ENV_FILE="$scenario_directory/runtime.env"
    CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE="$scenario_directory/rehearsal.env"
    CONTROL_PLANE_GREEN_SECRET_DIRECTORY="$scenario_directory/green-secrets"
    CONTROL_PLANE_BLUE_SECRET_DIRECTORY="$scenario_directory/blue-secrets"
    CONTROL_PLANE_GREEN_WEB_A_DIRECT_PROBE_RUNTIME_FILE="$CONTROL_PLANE_GREEN_SECRET_DIRECTORY/web-a-direct-probe"
    CONTROL_PLANE_GREEN_WEB_A_APPLIED_ACK_RUNTIME_FILE="$CONTROL_PLANE_GREEN_SECRET_DIRECTORY/web-a-applied-ack"
    CONTROL_PLANE_GREEN_WEB_B_DIRECT_PROBE_RUNTIME_FILE="$CONTROL_PLANE_GREEN_SECRET_DIRECTORY/web-b-direct-probe"
    CONTROL_PLANE_GREEN_WEB_B_APPLIED_ACK_RUNTIME_FILE="$CONTROL_PLANE_GREEN_SECRET_DIRECTORY/web-b-applied-ack"
    CONTROL_PLANE_GREEN_ROUTE_HEALTH_RUNTIME_FILE="$CONTROL_PLANE_GREEN_SECRET_DIRECTORY/route-health"
    CONTROL_PLANE_GREEN_POOL_ACK_RUNTIME_FILE="$CONTROL_PLANE_GREEN_SECRET_DIRECTORY/pool-ack"
    CONTROL_PLANE_BLUE_WEB_A_DIRECT_PROBE_RUNTIME_FILE="$CONTROL_PLANE_BLUE_SECRET_DIRECTORY/web-a-direct-probe"
    CONTROL_PLANE_BLUE_WEB_A_APPLIED_ACK_RUNTIME_FILE="$CONTROL_PLANE_BLUE_SECRET_DIRECTORY/web-a-applied-ack"
    CONTROL_PLANE_BLUE_WEB_B_DIRECT_PROBE_RUNTIME_FILE="$CONTROL_PLANE_BLUE_SECRET_DIRECTORY/web-b-direct-probe"
    CONTROL_PLANE_BLUE_WEB_B_APPLIED_ACK_RUNTIME_FILE="$CONTROL_PLANE_BLUE_SECRET_DIRECTORY/web-b-applied-ack"
    CONTROL_PLANE_BLUE_ROUTE_HEALTH_RUNTIME_FILE="$CONTROL_PLANE_BLUE_SECRET_DIRECTORY/route-health"
    CONTROL_PLANE_BLUE_POOL_ACK_RUNTIME_FILE="$CONTROL_PLANE_BLUE_SECRET_DIRECTORY/pool-ack"
    CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE=$CONTROL_PLANE_GREEN_WEB_A_DIRECT_PROBE_RUNTIME_FILE
    CONTROL_PLANE_GREEN_APPLIED_ACK_FILE=$CONTROL_PLANE_GREEN_WEB_A_APPLIED_ACK_RUNTIME_FILE
    CONTROL_PLANE_BLUE_DIRECT_PROBE_TOKEN_FILE=$CONTROL_PLANE_BLUE_WEB_A_DIRECT_PROBE_RUNTIME_FILE
    CONTROL_PLANE_BLUE_APPLIED_ACK_FILE=$CONTROL_PLANE_BLUE_WEB_A_APPLIED_ACK_RUNTIME_FILE
    CONTROL_PLANE_SECRET_UID=9999
    CONTROL_PLANE_SECRET_GID=9999
    chmod 700 "$CONTROL_PLANE_GREEN_SECRET_DIRECTORY" "$CONTROL_PLANE_BLUE_SECRET_DIRECTORY"
    CONTROL_PLANE_PUBLIC_PROBE_URL="https://127.0.0.1:${LAB_TRAEFIK_PORT}/api/health"
    CONTROL_PLANE_LOCAL_INGRESS_URL=http://127.0.0.1:8000/api/health
    CONTROL_PLANE_PUBLIC_PROBE_HOST_HEADER=$CONTROL_PLANE_HOST
    CONTROL_PLANE_PUBLIC_PROBE_ATTEMPTS=15
    CONTROL_PLANE_CONTAINER_STOP_TIMEOUT=2
    CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER="$scenario_directory/backup-attestation-verifier"
    CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER_SHA256=$(sha256sum \
        "$CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER" | awk '{print $1}')
    CONTROL_PLANE_BACKUP_DATABASE_DUMP_FILE="$scenario_directory/database.pgdump.gpg"
    CONTROL_PLANE_BACKUP_REDIS_SNAPSHOT_FILE="$scenario_directory/redis.rdb.gpg"
    CONTROL_PLANE_BACKUP_STATE_ARCHIVE_FILE="$scenario_directory/control-plane-state.tar.gpg"
    CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_FILE="$scenario_directory/capture.manifest"
    CONTROL_PLANE_BACKUP_ATTESTATION_FILE="$scenario_directory/backup-attestation"
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_SYSTEM_IDENTIFIER=
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_DATABASE_OID=
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_VERSION=15.18
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_DATABASE_NAME=postgres
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_HOST_IDENTITY=$(hostname -f 2>/dev/null || hostname)
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_CONTAINER_ID=
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_IMAGE_ID=$(docker image inspect --format '{{.Id}}' \
        "$MOCK_IMAGE")
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_IMAGE_DIGEST=$MOCK_IMAGE
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_ENDPOINT="${CONTROL_PLANE_REDIS_CONTAINER}:6379"
    CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_IMAGE_DIGEST=$CONTROL_PLANE_GREEN_IMAGE
    CONTROL_PLANE_BACKUP_EXPECTED_RECIPIENT_FINGERPRINT=0123456789ABCDEF0123456789ABCDEF01234567
    CONTROL_PLANE_BACKUP_EXPECTED_QUIESCE_OPERATOR_SHA256=$(sha256sum "$OPERATOR" | awk '{print $1}')
    CONTROL_PLANE_BACKUP_EXPECTED_RESTORE_TARGET_IDENTITY=restore-host.lab.test
    CONTROL_PLANE_BACKUP_MAX_AGE_SECONDS=3600
    CONTROL_PLANE_BACKUP_EXPECTED_OPERATOR_REHEARSAL_HOOK_SHA256=1111111111111111111111111111111111111111111111111111111111111111
    CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_BOOT_HOOK_SHA256=2222222222222222222222222222222222222222222222222222222222222222
    CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_API_PROBE_HOOK_SHA256=3333333333333333333333333333333333333333333333333333333333333333
    CONTROL_PLANE_BACKUP_EXPECTED_STATE_PROOF_TOOL_SHA256=4444444444444444444444444444444444444444444444444444444444444444
    CONTROL_PLANE_SOURCE_COMPOSE_BASE="$LAB_DIRECTORY/source-compose.yaml"
    CONTROL_PLANE_SOURCE_COMPOSE_PROD="$LAB_DIRECTORY/source-compose.prod.yaml"
    CONTROL_PLANE_SOURCE_COMPOSE_CUSTOM="$scenario_directory/absent-custom.yaml"
    CONTROL_PLANE_SOURCE_COMPOSE_POSTGRES="$scenario_directory/absent-postgres.yaml"
    CONTROL_PLANE_SOURCE_COMPOSE_PROJECT=$project_name
    CONTROL_PLANE_SOURCE_COMPOSE_SERVICE=coolify
    CONTROL_PLANE_COMPOSE_PROJECT="${project_name}-candidate"
    CONTROL_PLANE_DOCKER_PROVIDER_CONSTRAINT="Label(\`com.docker.compose.project\`,\`${CONTROL_PLANE_SOURCE_COMPOSE_PROJECT}\`) || Label(\`com.docker.compose.project\`,\`${CONTROL_PLANE_COMPOSE_PROJECT}\`)"
    LAB_TRAEFIK_STATIC_CONFIG="$scenario_directory/traefik.yml"
    sed "s#CONTROL_PLANE_DOCKER_PROVIDER_CONSTRAINT_PLACEHOLDER#$CONTROL_PLANE_DOCKER_PROVIDER_CONSTRAINT#" \
        "$LAB_DIRECTORY/traefik.yml" > "$LAB_TRAEFIK_STATIC_CONFIG"
    chmod 400 "$LAB_TRAEFIK_STATIC_CONFIG"
    grep -F -x -q "    constraints: \"$CONTROL_PLANE_DOCKER_PROVIDER_CONSTRAINT\"" \
        "$LAB_TRAEFIK_STATIC_CONFIG" \
        || fail 'lab Traefik static configuration lacks the exact source-project constraint'
    prepare_lab_tls
    CONTROL_PLANE_OPERATOR_COMPOSE_FILE="$LAB_DIRECTORY/operator-compose.yaml"
    CONTROL_PLANE_REHEARSAL_COMPOSE_FILE="$LAB_DIRECTORY/rehearsal-compose.yaml"
    CONTROL_PLANE_TEST_REHEARSAL_COMPOSE_FILE="$CONTROL_PLANE_REHEARSAL_COMPOSE_FILE"
    CONTROL_PLANE_TEST_REHEARSAL_COMPOSE_FILE_SHA256=$(sha256sum \
        "$CONTROL_PLANE_TEST_REHEARSAL_COMPOSE_FILE" | awk '{print $1}')
    CONTROL_PLANE_DATABASE_USER=postgres
    CONTROL_PLANE_DATABASE_NAME=postgres
    CONTROL_PLANE_DATABASE_HOST=$CONTROL_PLANE_DATABASE_CONTAINER
    CONTROL_PLANE_DATABASE_PORT=5432
    CONTROL_PLANE_REHEARSAL_DATABASE_USER=postgres
    CONTROL_PLANE_REHEARSAL_DATABASE_NAME=postgres
    CONTROL_PLANE_REDIS_HOST=$CONTROL_PLANE_REDIS_CONTAINER
    CONTROL_PLANE_REDIS_PORT=6379
    CONTROL_PLANE_SOKETI_HOST=$CONTROL_PLANE_SOKETI_CONTAINER
    CONTROL_PLANE_SOKETI_PORT=6001
    CONTROL_PLANE_SOKETI_METRICS_PORT=6002
    CONTROL_PLANE_SSH_DIRECTORY="$scenario_directory/ssh"
    CONTROL_PLANE_APPLICATIONS_DIRECTORY="$scenario_directory/applications"
    CONTROL_PLANE_DATABASES_DIRECTORY="$scenario_directory/databases"
    CONTROL_PLANE_SERVICES_DIRECTORY="$scenario_directory/services"
    CONTROL_PLANE_BACKUPS_DIRECTORY="$scenario_directory/backups"
    CONTROL_PLANE_MIGRATION_COMPATIBILITY_FILE="$scenario_directory/migration-compatibility"
    CONTROL_PLANE_REHEARSAL_NETWORK="${project_name}-rehearsal"
    CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT=750ms
    CONTROL_PLANE_MIGRATION_STATEMENT_TIMEOUT=30s
    CONTROL_PLANE_INGRESS_CONTROLLER="$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/traefik-ingress.sh"
    CONTROL_PLANE_EXPECTED_PUBLIC_IPV4=127.0.0.1
    configure_lab_global_transaction_lock
    install_lab_release_manifest
    assert_release_manifest_source_attestation
    CONTROL_PLANE_DRAIN_ATTEMPTS=12
    CONTROL_PLANE_DRAIN_STABLE_SECONDS=1
    LAB_DYNAMIC_DIR=$CONTROL_PLANE_TRAEFIK_DYNAMIC_DIR
    LAB_RUNTIME_STATE_DIR="$scenario_directory/runtime-state"
    CONTROL_PLANE_LEGACY_IMAGE=$MOCK_IMAGE

    printf 'CONTROL_PLANE_BLUE_GREEN_LAB_START root=%s;project=%s;scenario=%s\n' \
        "$LAB_ROOT" "$project_name" "$scenario_name" >&2

    export CONTROL_PLANE_APPLICATIONS_DIRECTORY CONTROL_PLANE_BACKUPS_DIRECTORY
    export CONTROL_PLANE_BACKUP_ATTESTATION_FILE CONTROL_PLANE_BACKUP_ATTESTATION_SHA256
    export CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER
    export CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER_SHA256 CONTROL_PLANE_BACKUP_DATABASE_DUMP_FILE
    export CONTROL_PLANE_BACKUP_REDIS_SNAPSHOT_FILE
    export CONTROL_PLANE_BACKUP_STATE_ARCHIVE_FILE CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_FILE
    export CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_SHA256
    export CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_SYSTEM_IDENTIFIER
    export CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_DATABASE_OID
    export CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_VERSION
    export CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_DATABASE_NAME
    export CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_HOST_IDENTITY
    export CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_CONTAINER_ID
    export CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_IMAGE_ID
    export CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_IMAGE_DIGEST
    export CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_ENDPOINT
    export CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_IMAGE_DIGEST
    export CONTROL_PLANE_BACKUP_EXPECTED_RECIPIENT_FINGERPRINT
    export CONTROL_PLANE_BACKUP_EXPECTED_QUIESCE_OPERATOR_SHA256
    export CONTROL_PLANE_BACKUP_EXPECTED_RESTORE_TARGET_IDENTITY
    export CONTROL_PLANE_BACKUP_MAX_AGE_SECONDS
    export CONTROL_PLANE_BACKUP_EXPECTED_OPERATOR_REHEARSAL_HOOK_SHA256
    export CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_BOOT_HOOK_SHA256
    export CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_API_PROBE_HOOK_SHA256
    export CONTROL_PLANE_BACKUP_EXPECTED_STATE_PROOF_TOOL_SHA256 CONTROL_PLANE_BACKEND_PORT
    export CONTROL_PLANE_BLUE_CONTAINER CONTROL_PLANE_BLUE_IMAGE CONTROL_PLANE_BLUE_STATE_VOLUME
    export CONTROL_PLANE_BLUE_WEB_A_CONTAINER CONTROL_PLANE_BLUE_WEB_B_CONTAINER
    export CONTROL_PLANE_BLUE_WEB_A_PRIVATE_VOLUME CONTROL_PLANE_BLUE_WEB_B_PRIVATE_VOLUME
    export CONTROL_PLANE_BLUE_SECRET_DIRECTORY
    export CONTROL_PLANE_BLUE_DIRECT_PROBE_TOKEN_FILE CONTROL_PLANE_BLUE_APPLIED_ACK_FILE
    export CONTROL_PLANE_BLUE_LOOPBACK_PORT CONTROL_PLANE_BLUE_WEB_EPOCH
    export CONTROL_PLANE_BLUE_WEB_A_LOOPBACK_PORT CONTROL_PLANE_BLUE_WEB_B_LOOPBACK_PORT
    export CONTROL_PLANE_BLUE_WEB_A_WEB_EPOCH CONTROL_PLANE_BLUE_WEB_B_WEB_EPOCH
    export CONTROL_PLANE_BLUE_WEB_A_ROUTE_DRAIN_EPOCH CONTROL_PLANE_BLUE_WEB_B_ROUTE_DRAIN_EPOCH
    export CONTROL_PLANE_BLUE_WEB_A_ROUTE_IDENTITY CONTROL_PLANE_BLUE_WEB_B_ROUTE_IDENTITY
    export CONTROL_PLANE_BLUE_POOL_LABEL_VALUE
    export CONTROL_PLANE_BLUE_WEB_A_DIRECT_PROBE_RUNTIME_FILE
    export CONTROL_PLANE_BLUE_WEB_A_APPLIED_ACK_RUNTIME_FILE
    export CONTROL_PLANE_BLUE_WEB_B_DIRECT_PROBE_RUNTIME_FILE
    export CONTROL_PLANE_BLUE_WEB_B_APPLIED_ACK_RUNTIME_FILE
    export CONTROL_PLANE_BLUE_ROUTE_HEALTH_RUNTIME_FILE CONTROL_PLANE_BLUE_POOL_ACK_RUNTIME_FILE
    export CONTROL_PLANE_BLUE_WRITER_EPOCH CONTROL_PLANE_COMPOSE_PROJECT CONTROL_PLANE_DATABASES_DIRECTORY
    export CONTROL_PLANE_CONTAINER_STOP_TIMEOUT CONTROL_PLANE_DATABASE_CONTAINER
    export CONTROL_PLANE_DATABASE_HOST CONTROL_PLANE_DATABASE_NAME CONTROL_PLANE_DATABASE_PORT
    export CONTROL_PLANE_DATABASE_USER CONTROL_PLANE_DIRECT_PROBE_PATH
    export CONTROL_PLANE_REHEARSAL_DATABASE_CONTAINER
    export CONTROL_PLANE_REHEARSAL_DATABASE_USER CONTROL_PLANE_REHEARSAL_DATABASE_NAME
    export CONTROL_PLANE_GREEN_CONTAINER CONTROL_PLANE_GREEN_IMAGE CONTROL_PLANE_GREEN_STATE_VOLUME
    export CONTROL_PLANE_GREEN_WEB_A_CONTAINER CONTROL_PLANE_GREEN_WEB_B_CONTAINER
    export CONTROL_PLANE_GREEN_WEB_A_PRIVATE_VOLUME CONTROL_PLANE_GREEN_WEB_B_PRIVATE_VOLUME
    export CONTROL_PLANE_GREEN_SECRET_DIRECTORY
    export CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE CONTROL_PLANE_GREEN_APPLIED_ACK_FILE
    export CONTROL_PLANE_GREEN_LOOPBACK_PORT CONTROL_PLANE_GREEN_WEB_EPOCH
    export CONTROL_PLANE_GREEN_WEB_A_LOOPBACK_PORT CONTROL_PLANE_GREEN_WEB_B_LOOPBACK_PORT
    export CONTROL_PLANE_GREEN_WEB_A_WEB_EPOCH CONTROL_PLANE_GREEN_WEB_B_WEB_EPOCH
    export CONTROL_PLANE_GREEN_WEB_A_ROUTE_DRAIN_EPOCH CONTROL_PLANE_GREEN_WEB_B_ROUTE_DRAIN_EPOCH
    export CONTROL_PLANE_GREEN_WEB_A_ROUTE_IDENTITY CONTROL_PLANE_GREEN_WEB_B_ROUTE_IDENTITY
    export CONTROL_PLANE_GREEN_POOL_LABEL_VALUE
    export CONTROL_PLANE_GREEN_WEB_A_DIRECT_PROBE_RUNTIME_FILE
    export CONTROL_PLANE_GREEN_WEB_A_APPLIED_ACK_RUNTIME_FILE
    export CONTROL_PLANE_GREEN_WEB_B_DIRECT_PROBE_RUNTIME_FILE
    export CONTROL_PLANE_GREEN_WEB_B_APPLIED_ACK_RUNTIME_FILE
    export CONTROL_PLANE_GREEN_ROUTE_HEALTH_RUNTIME_FILE CONTROL_PLANE_GREEN_POOL_ACK_RUNTIME_FILE
    export CONTROL_PLANE_HOST CONTROL_PLANE_MIGRATION_COMPATIBILITY_FILE
    export CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT CONTROL_PLANE_MIGRATION_STATEMENT_TIMEOUT
    export CONTROL_PLANE_NETWORK
    export CONTROL_PLANE_OPERATION_ID CONTROL_PLANE_MUTATION_FREEZE_EPOCH \
        CONTROL_PLANE_REVERSE_MUTATION_FREEZE_EPOCH CONTROL_PLANE_COORDINATION_VOLUME \
        CONTROL_PLANE_OPERATOR_COMPOSE_FILE
    export CONTROL_PLANE_OPERATOR_STATE_DIR CONTROL_PLANE_OPERATOR_TARGET
    export CONTROL_PLANE_RUNTIME_FENCE_PROVISIONER CONTROL_PLANE_RUNTIME_FENCE_PROVISIONER_SHA256
    export CONTROL_PLANE_RUNTIME_FENCE_MANAGEMENT_ENDPOINTS
    export CONTROL_PLANE_RUNTIME_FENCE_ADDITIONAL_NETWORK_IDS
    export CONTROL_PLANE_RUNTIME_FENCE_SELF_SSH_TARGET
    export CONTROL_PLANE_RUNTIME_FENCE_PROBE_MAX_AGE_SECONDS
    export CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_API_URL
    export CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_ROUTER CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_SERVICE
    export CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_LEGACY_PORT
    export CONTROL_PLANE_RUNTIME_FENCE_PROVIDER_HEADER_FILE
    export CONTROL_PLANE_RUNTIME_FENCE_LAB_STATE
    export CONTROL_PLANE_RELEASE_BACKUP_ATTESTATION_VERIFIER
    export CONTROL_PLANE_RELEASE_BACKUP_QUIESCE_CONTROLLER CONTROL_PLANE_RELEASE_ID
    export CONTROL_PLANE_RELEASE_MANIFEST_FILE CONTROL_PLANE_RELEASE_MANIFEST_SHA256
    export CONTROL_PLANE_RELEASE_RUNTIME_FENCE_CONTROLLER
    export CONTROL_PLANE_RELEASE_RUNTIME_FENCE_PROVIDER_PROBE
    export CONTROL_PLANE_RELEASE_RUNTIME_FENCE_QUEUE_PROBE
    export CONTROL_PLANE_RELEASE_RUNTIME_FENCE_REAPER
    export CONTROL_PLANE_RELEASE_RUNTIME_FENCE_TERMINAL_PROBE
    export CONTROL_PLANE_INGRESS_CONTROLLER CONTROL_PLANE_LOCAL_INGRESS_ENTRYPOINT
    export CONTROL_PLANE_LOCAL_INGRESS_URL
    export CONTROL_PLANE_PROXY_CONTAINER CONTROL_PLANE_PUBLIC_PROBE_ATTEMPTS
    export CONTROL_PLANE_PUBLIC_PROBE_HOST_HEADER CONTROL_PLANE_PUBLIC_PROBE_URL
    export CONTROL_PLANE_REDIS_CONTAINER CONTROL_PLANE_REDIS_HOST CONTROL_PLANE_REDIS_PORT
    export CONTROL_PLANE_REHEARSAL_COMPOSE_FILE CONTROL_PLANE_REHEARSAL_NETWORK
    export CONTROL_PLANE_TEST_REHEARSAL_COMPOSE_FILE
    export CONTROL_PLANE_TEST_REHEARSAL_COMPOSE_FILE_SHA256
    export CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE CONTROL_PLANE_REPLACEMENT_BLUE_CONTAINER
    export CONTROL_PLANE_SECRET_GID CONTROL_PLANE_SECRET_UID
    export CONTROL_PLANE_SERVICES_DIRECTORY CONTROL_PLANE_SSH_DIRECTORY
    export CONTROL_PLANE_SOKETI_CONTAINER CONTROL_PLANE_SOKETI_HOST
    export CONTROL_PLANE_SOKETI_METRICS_PORT CONTROL_PLANE_SOKETI_PORT
    export CONTROL_PLANE_SOURCE_COMPOSE_BASE CONTROL_PLANE_SOURCE_COMPOSE_CUSTOM
    export CONTROL_PLANE_SOURCE_COMPOSE_POSTGRES CONTROL_PLANE_SOURCE_COMPOSE_PROD
    export CONTROL_PLANE_SOURCE_ENV_FILE
    export CONTROL_PLANE_SOURCE_COMPOSE_PROJECT CONTROL_PLANE_SOURCE_COMPOSE_SERVICE
    export CONTROL_PLANE_DOCKER_PROVIDER_CONSTRAINT
    export CONTROL_PLANE_TEST_MODE CONTROL_PLANE_TRAEFIK_DYNAMIC_DIR
    export CONTROL_PLANE_TRAEFIK_DYNAMIC_FILENAME CONTROL_PLANE_TRAEFIK_ENTRYPOINT
    export CONTROL_PLANE_TRAEFIK_ROUTER CONTROL_PLANE_TRAEFIK_SERVICE
    export CONTROL_PLANE_TRAEFIK_TLS CONTROL_PLANE_TRAEFIK_CERT_RESOLVER
    export CONTROL_PLANE_WRITER_EPOCH
    export CONTROL_PLANE_EXPECTED_PUBLIC_IPV4
    export CONTROL_PLANE_DRAIN_ATTEMPTS CONTROL_PLANE_DRAIN_STABLE_SECONDS
    export CONTROL_PLANE_LEGACY_IMAGE
    export LAB_DYNAMIC_DIR LAB_RUNTIME_STATE_DIR
    export LAB_TRAEFIK_PORT LAB_TRAEFIK_STATIC_CONFIG LAB_TRAEFIK_TLS_DIRECTORY
    export LAB_TRAEFIK_TLS_CA CURL_CA_BUNDLE

    printf '%s\n' 'CONTROL_PLANE_BACKEND_PORT=8080' "LAB_EXPECTED_HOST=$CONTROL_PLANE_HOST" \
        "DB_HOST=$CONTROL_PLANE_DATABASE_CONTAINER" 'DB_PORT=5432' \
        'DB_DATABASE=postgres' 'DB_USERNAME=postgres' \
        "REDIS_HOST=$CONTROL_PLANE_REDIS_CONTAINER" 'REDIS_PORT=6379' \
        'HORIZON_ENABLED=true' \
        'SCHEDULER_ENABLED=true' 'NIGHTWATCH_ENABLED=true' > "$CONTROL_PLANE_SOURCE_ENV_FILE"
    chmod 600 "$CONTROL_PLANE_SOURCE_ENV_FILE"
    printf '%s\n' 'CONTROL_PLANE_BACKEND_PORT=8080' \
        "REDIS_HOST=$CONTROL_PLANE_REHEARSAL_DATABASE_CONTAINER" \
        > "$CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE"
    chmod 600 "$CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE"
    printf 'green-direct-probe-%s-0123456789' "$scenario_name" > "$CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE"
    printf 'green-applied-ack-%s-0123456789' "$scenario_name" > "$CONTROL_PLANE_GREEN_APPLIED_ACK_FILE"
    printf 'green-web-b-direct-probe-%s-0123456789' "$scenario_name" \
        > "$CONTROL_PLANE_GREEN_WEB_B_DIRECT_PROBE_RUNTIME_FILE"
    printf 'green-web-b-applied-ack-%s-0123456789' "$scenario_name" \
        > "$CONTROL_PLANE_GREEN_WEB_B_APPLIED_ACK_RUNTIME_FILE"
    printf 'green-route-health-%s-0123456789' "$scenario_name" \
        > "$CONTROL_PLANE_GREEN_ROUTE_HEALTH_RUNTIME_FILE"
    printf 'green-pool-ack-%s-0123456789' "$scenario_name" \
        > "$CONTROL_PLANE_GREEN_POOL_ACK_RUNTIME_FILE"
    printf 'blue-direct-probe-%s-0123456789' "$scenario_name" > "$CONTROL_PLANE_BLUE_DIRECT_PROBE_TOKEN_FILE"
    printf 'blue-applied-ack-%s-0123456789' "$scenario_name" > "$CONTROL_PLANE_BLUE_APPLIED_ACK_FILE"
    printf 'blue-web-b-direct-probe-%s-0123456789' "$scenario_name" \
        > "$CONTROL_PLANE_BLUE_WEB_B_DIRECT_PROBE_RUNTIME_FILE"
    printf 'blue-web-b-applied-ack-%s-0123456789' "$scenario_name" \
        > "$CONTROL_PLANE_BLUE_WEB_B_APPLIED_ACK_RUNTIME_FILE"
    printf 'blue-route-health-%s-0123456789' "$scenario_name" \
        > "$CONTROL_PLANE_BLUE_ROUTE_HEALTH_RUNTIME_FILE"
    printf 'blue-pool-ack-%s-0123456789' "$scenario_name" \
        > "$CONTROL_PLANE_BLUE_POOL_ACK_RUNTIME_FILE"
    chmod 400 \
        "$CONTROL_PLANE_GREEN_WEB_A_DIRECT_PROBE_RUNTIME_FILE" \
        "$CONTROL_PLANE_GREEN_WEB_A_APPLIED_ACK_RUNTIME_FILE" \
        "$CONTROL_PLANE_GREEN_WEB_B_DIRECT_PROBE_RUNTIME_FILE" \
        "$CONTROL_PLANE_GREEN_WEB_B_APPLIED_ACK_RUNTIME_FILE" \
        "$CONTROL_PLANE_GREEN_ROUTE_HEALTH_RUNTIME_FILE" \
        "$CONTROL_PLANE_GREEN_POOL_ACK_RUNTIME_FILE" \
        "$CONTROL_PLANE_BLUE_WEB_A_DIRECT_PROBE_RUNTIME_FILE" \
        "$CONTROL_PLANE_BLUE_WEB_A_APPLIED_ACK_RUNTIME_FILE" \
        "$CONTROL_PLANE_BLUE_WEB_B_DIRECT_PROBE_RUNTIME_FILE" \
        "$CONTROL_PLANE_BLUE_WEB_B_APPLIED_ACK_RUNTIME_FILE" \
        "$CONTROL_PLANE_BLUE_ROUTE_HEALTH_RUNTIME_FILE" \
        "$CONTROL_PLANE_BLUE_POOL_ACK_RUNTIME_FILE"
    docker run --rm --user 0:0 --network none \
        --volume "$scenario_directory:/scenario" --entrypoint chown "$MOCK_IMAGE" \
        9999:9999 \
        /scenario/green-secrets/web-a-direct-probe \
        /scenario/green-secrets/web-a-applied-ack \
        /scenario/green-secrets/web-b-direct-probe \
        /scenario/green-secrets/web-b-applied-ack \
        /scenario/green-secrets/route-health \
        /scenario/green-secrets/pool-ack \
        /scenario/blue-secrets/web-a-direct-probe \
        /scenario/blue-secrets/web-a-applied-ack \
        /scenario/blue-secrets/web-b-direct-probe \
        /scenario/blue-secrets/web-b-applied-ack \
        /scenario/blue-secrets/route-health \
        /scenario/blue-secrets/pool-ack
    observed_secret_ownership=$(docker run --rm --user 0:0 --network none \
        --volume "$scenario_directory:/scenario:ro" --entrypoint /bin/sh "$MOCK_IMAGE" -ec '
            for secret in /scenario/green-secrets/* /scenario/blue-secrets/*; do
                stat -c "%u:%g:%a" "$secret"
            done
        ')
    [ "$(printf '%s\n' "$observed_secret_ownership" \
        | grep -F -x -c 9999:9999:400)" = 12 ] \
        || fail 'Docker consumer boundary cannot represent all twelve exact 9999:9999:0400 pool secrets'
    printf 'encrypted-database-dump=%s\n' "$CONTROL_PLANE_OPERATION_ID" \
        > "$CONTROL_PLANE_BACKUP_DATABASE_DUMP_FILE"
    printf 'encrypted-redis-snapshot=%s\n' "$CONTROL_PLANE_OPERATION_ID" \
        > "$CONTROL_PLANE_BACKUP_REDIS_SNAPSHOT_FILE"
    printf 'encrypted-control-plane-state=%s\n' "$CONTROL_PLANE_OPERATION_ID" \
        > "$CONTROL_PLANE_BACKUP_STATE_ARCHIVE_FILE"
    printf 'capture-manifest=%s\nsource_redis_image_id=%s\n' "$CONTROL_PLANE_OPERATION_ID" \
        "$CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_IMAGE_ID" \
        > "$CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_FILE"
    printf 'restore-attestation=%s\n' "$CONTROL_PLANE_OPERATION_ID" \
        > "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE"
    chmod 600 "$CONTROL_PLANE_BACKUP_DATABASE_DUMP_FILE" \
        "$CONTROL_PLANE_BACKUP_REDIS_SNAPSHOT_FILE" "$CONTROL_PLANE_BACKUP_STATE_ARCHIVE_FILE"
    chmod 400 "$CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_FILE" "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE"
    CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_SHA256=$(sha256sum \
        "$CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_FILE" | awk '{print $1}')
    CONTROL_PLANE_BACKUP_ATTESTATION_SHA256=$(sha256sum \
        "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE" | awk '{print $1}')
    mock_image_digest=$(docker image inspect --format '{{.Id}}' "$MOCK_IMAGE")
    authorized_pending_count=$(wc -l < "$CONTROL_PLANE_MIGRATION_NAMES_FILE" | tr -d ' ')
    if ! { [ "$authorized_pending_count" -gt 0 ] \
        && [ "$(LC_ALL=C sort -u "$CONTROL_PLANE_MIGRATION_NAMES_FILE" | wc -l | tr -d ' ')" \
            = "$authorized_pending_count" ]; }; then
        fail 'canonical migration inventory is empty or duplicated'
    fi
    authorized_pending_sha256=$(sha256sum "$CONTROL_PLANE_MIGRATION_NAMES_FILE" | awk '{print $1}')
    {
        printf 'image-digest=%s\n' "$mock_image_digest"
        printf 'legacy-blue-image-digest=%s\n' "$mock_image_digest"
        printf '%s\n' 'migration-class=additive' 'legacy-blue-read-compatible=true' \
            'legacy-blue-write-compatible=true'
        printf 'authorized-pending-count=%s\n' "$authorized_pending_count"
        printf 'authorized-pending-sha256=%s\n' "$authorized_pending_sha256"
        sed 's/^/authorized-pending-migration=/' "$CONTROL_PLANE_MIGRATION_NAMES_FILE"
    } > "$CONTROL_PLANE_MIGRATION_COMPATIBILITY_FILE"
    if [ "$scenario_number" = 1 ]; then
        assert_release_manifest_preflight_rejections
    fi

    active_lab=1
    docker network create --subnet "$CONTROL_PLANE_TEST_PROXY_SUBNET" \
        --gateway "$CONTROL_PLANE_TEST_PROXY_GATEWAY" "$CONTROL_PLANE_NETWORK" >/dev/null
    docker network create --internal "$CONTROL_PLANE_REHEARSAL_NETWORK" >/dev/null
    if [ "$scenario_name" = backup-quiesce ]; then
        start_foreign_control_plane_router
    fi
    docker compose --project-name "$project_name" --file "$LAB_DIRECTORY/compose.yaml" \
        up --detach --no-build --remove-orphans >/dev/null
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_CONTAINER_ID=$(docker inspect --format '{{.Id}}' \
        "$CONTROL_PLANE_REDIS_CONTAINER")
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_IMAGE_ID=$(docker inspect --format '{{.Image}}' \
        "$CONTROL_PLANE_REDIS_CONTAINER")
    [ "$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/etc/traefik/traefik.yml"}}{{.Source}}{{end}}{{end}}' \
        "$CONTROL_PLANE_PROXY_CONTAINER")" = "$LAB_TRAEFIK_STATIC_CONFIG" ] \
        || fail 'lab Traefik is not mounted to its operation-scoped static configuration'
    attempts=0
    while :; do
        backup_source_system_identifier=$(docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
            psql --username postgres --dbname postgres --tuples-only --no-align \
                --command 'SELECT system_identifier::text FROM pg_control_system()' 2>/dev/null || true)
        backup_source_database_oid=$(docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
            psql --username postgres --dbname postgres --tuples-only --no-align \
                --command 'SELECT oid::text FROM pg_database WHERE datname = current_database()' 2>/dev/null || true)
        rehearsal_system_identifier=$(docker exec "$CONTROL_PLANE_REHEARSAL_DATABASE_CONTAINER" \
            psql --username postgres --dbname postgres --tuples-only --no-align \
                --command 'SELECT system_identifier::text FROM pg_control_system()' 2>/dev/null || true)
        rehearsal_database_oid=$(docker exec "$CONTROL_PLANE_REHEARSAL_DATABASE_CONTAINER" \
            psql --username postgres --dbname postgres --tuples-only --no-align \
                --command 'SELECT oid::text FROM pg_database WHERE datname = current_database()' 2>/dev/null || true)
        if printf '%s' "$backup_source_system_identifier" | grep -Eq '^[0-9]+$' \
            && printf '%s' "$backup_source_database_oid" | grep -Eq '^[1-9][0-9]*$' \
            && printf '%s' "$rehearsal_system_identifier" | grep -Eq '^[0-9]+$' \
            && printf '%s' "$rehearsal_database_oid" | grep -Eq '^[1-9][0-9]*$'; then
            sleep 1
            docker exec "$CONTROL_PLANE_REHEARSAL_DATABASE_CONTAINER" \
                pg_isready --username postgres --dbname postgres >/dev/null 2>&1 && break
        fi
        attempts=$((attempts + 1))
        [ "$attempts" -lt 30 ] || fail 'rehearsal clone database did not become stably ready'
        sleep 1
    done
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_SYSTEM_IDENTIFIER=$backup_source_system_identifier
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_DATABASE_OID=$backup_source_database_oid
    rehearsal_database_address=$(docker inspect --format \
        "{{with index .NetworkSettings.Networks \"${CONTROL_PLANE_REHEARSAL_NETWORK}\"}}{{.IPAddress}}{{end}}" \
        "$CONTROL_PLANE_REHEARSAL_DATABASE_CONTAINER")
    rehearsal_instance_marker=$(printf '%s' "${rehearsal_system_identifier}:${rehearsal_database_oid}" \
        | sha256sum | awk '{print $1}')
    rehearsal_identity_payload="system_identifier=${rehearsal_system_identifier};database=postgres;server_address=${rehearsal_database_address};server_port=5432;instance_marker=${rehearsal_instance_marker}"
    rehearsal_identity_sha256=$(printf '%s' "$rehearsal_identity_payload" | sha256sum | awk '{print $1}')
    rehearsal_ledger_file="$scenario_directory/rehearsal-ledger.csv"
    rehearsal_pending_file="$scenario_directory/rehearsal-pending"
    docker exec "$CONTROL_PLANE_REHEARSAL_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --tuples-only --no-align --quiet \
            --command "COPY (SELECT migration, batch FROM migrations ORDER BY migration) TO STDOUT WITH (FORMAT csv)" \
        > "$rehearsal_ledger_file"
    cp "$CONTROL_PLANE_MIGRATION_NAMES_FILE" "$rehearsal_pending_file"
    CONTROL_PLANE_REHEARSAL_EXPECTED_MIGRATION_LEDGER_SHA256=$(sha256sum "$rehearsal_ledger_file" | awk '{print $1}')
    CONTROL_PLANE_REHEARSAL_EXPECTED_MIGRATION_PENDING_SHA256=$(sha256sum "$rehearsal_pending_file" | awk '{print $1}')
    CONTROL_PLANE_REHEARSAL_EXPECTED_MIGRATION_BATCH=2
    export CONTROL_PLANE_REHEARSAL_EXPECTED_MIGRATION_LEDGER_SHA256
    export CONTROL_PLANE_REHEARSAL_EXPECTED_MIGRATION_PENDING_SHA256
    export CONTROL_PLANE_REHEARSAL_EXPECTED_MIGRATION_BATCH
    {
        printf 'PGHOST=%s\n' "$CONTROL_PLANE_REHEARSAL_DATABASE_CONTAINER"
        printf '%s\n' 'PGPORT=5432' 'PGUSER=postgres' 'PGDATABASE=postgres'
        printf 'CONTROL_PLANE_EXPECTED_DATABASE_IDENTITY_SHA256=%s\n' "$rehearsal_identity_sha256"
    } >> "$CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE"
    docker compose --ansi never --project-name "$CONTROL_PLANE_SOURCE_COMPOSE_PROJECT" \
        --env-file "$CONTROL_PLANE_SOURCE_ENV_FILE" \
        --file "$CONTROL_PLANE_SOURCE_COMPOSE_BASE" --file "$CONTROL_PLANE_SOURCE_COMPOSE_PROD" \
        up --detach --no-build --pull never --no-deps "$CONTROL_PLANE_SOURCE_COMPOSE_SERVICE" >/dev/null
    [ "${CONTROL_PLANE_TEST_START_FAILURE:-0}" != 1 ] \
        || fail 'intentional start failure after Docker resources were created'
    assert_source_traefik_identity
    wait_for_blue
    case "$scenario_name" in
        proxy-enrollment-*) ;;
        *) prepare_proxy_enrollment_native_lab ;;
    esac
}

reconcile_active_backup_quiesce()
{
    cleanup_state_directory=${CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR:-}
    [ -n "$cleanup_state_directory" ] || return 0
    cleanup_active_file="$cleanup_state_directory/active"
    [ -f "$cleanup_active_file" ] && [ ! -L "$cleanup_active_file" ] || return 0
    cleanup_operation_name=$(cat "$cleanup_active_file")
    cleanup_state_file="$cleanup_state_directory/$cleanup_operation_name/state"
    [ -f "$cleanup_state_file" ] && [ ! -L "$cleanup_state_file" ] || return 1
    cleanup_controller_path=$(sed -n 's/^controller_path=//p' "$cleanup_state_file")
    cleanup_boot_id="lab-cleanup-${LAB_INVOCATION_TOKEN}"
    cleanup_log="${scenario_directory:-$LAB_ROOT}/backup-quiesce-cleanup.log"

    case "$cleanup_controller_path" in
        /reviewed/*|/usr/local/*)
            [ -n "${installer_test_container:-}" ] \
                && [ "$(docker inspect --format '{{.State.Running}}' \
                    "$installer_test_container" 2>/dev/null || true)" = true ] \
                || return 1
            docker exec --user 0 \
                --env CONTROL_PLANE_TEST_MODE=1 \
                --env "CONTROL_PLANE_TEST_BACKUP_QUIESCE_BOOT_ID=$cleanup_boot_id" \
                "$installer_test_container" "$cleanup_controller_path" \
                watchdog-scan --state-directory /var/lib/coolify/control-plane-backup-quiesce \
                >> "$cleanup_log" 2>&1 || return 1
            ;;
        "$REPOSITORY_ROOT"/*)
            cleanup_owner_pid=$(sed -n 's/^owner_pid=//p' "$cleanup_state_file")
            case "$cleanup_owner_pid" in
                ''|*[!0-9]*) return 1 ;;
            esac
            if [ "$cleanup_owner_pid" != "$$" ]; then
                kill -TERM "$cleanup_owner_pid" >/dev/null 2>&1 || true
            fi
            CONTROL_PLANE_TEST_MODE=1 \
                CONTROL_PLANE_TEST_BACKUP_QUIESCE_BOOT_ID=$cleanup_boot_id \
                "$cleanup_controller_path" watchdog-scan \
                    --state-directory "$cleanup_state_directory" \
                    >> "$cleanup_log" 2>&1 || return 1
            ;;
        *)
            return 1
            ;;
    esac
    [ ! -e "$cleanup_active_file" ] && [ ! -L "$cleanup_active_file" ]
}

cleanup_compose_down()
{
    cleanup_stack_name=$1
    shift
    cleanup_attempt=1

    while ! "$@" >/dev/null; do
        [ "$cleanup_attempt" -lt 15 ] \
            || fail "$cleanup_stack_name Compose stack did not tear down cleanly"
        cleanup_attempt=$((cleanup_attempt + 1))
        sleep 2
    done
}

cleanup_lab()
{
    terminate_registered_workers
    reconcile_active_backup_quiesce \
        || fail 'backup quiesce state did not reconcile before successful lab teardown'
    held_owner_pid=
    zombie_parent_pid=
    boot_reconcile_pid=
    backup_quiesce_writer_pid=
    if [ -n "${managed_app_container:-}" ]; then
        docker rm --force "$managed_app_container" >/dev/null 2>&1 || true
        managed_app_container=
    fi
    if [ -n "${installer_test_container:-}" ]; then
        docker rm --force "$installer_test_container" >/dev/null 2>&1 || true
        installer_test_container=
    fi
    if [ -n "${foreign_router_container:-}" ]; then
        docker rm --force "$foreign_router_container" >/dev/null 2>&1 || true
        foreign_router_container=
    fi
    docker rm --force \
        "$CONTROL_PLANE_GREEN_WEB_A_CONTAINER" "$CONTROL_PLANE_GREEN_WEB_B_CONTAINER" \
        "$CONTROL_PLANE_BLUE_WEB_A_CONTAINER" "$CONTROL_PLANE_BLUE_WEB_B_CONTAINER" \
        >/dev/null 2>&1 || true
    cleanup_compose_down source docker compose --ansi never \
        --project-name "$CONTROL_PLANE_SOURCE_COMPOSE_PROJECT" \
        --env-file "$CONTROL_PLANE_SOURCE_ENV_FILE" \
        --file "$CONTROL_PLANE_SOURCE_COMPOSE_BASE" --file "$CONTROL_PLANE_SOURCE_COMPOSE_PROD" \
        down --remove-orphans
    cleanup_compose_down support docker compose --project-name "$project_name" \
        --file "$LAB_DIRECTORY/compose.yaml" down --volumes --remove-orphans
    docker volume rm \
        "$CONTROL_PLANE_GREEN_WEB_A_PRIVATE_VOLUME" \
        "$CONTROL_PLANE_GREEN_WEB_B_PRIVATE_VOLUME" \
        "$CONTROL_PLANE_BLUE_WEB_A_PRIVATE_VOLUME" \
        "$CONTROL_PLANE_BLUE_WEB_B_PRIVATE_VOLUME" \
        "$CONTROL_PLANE_COORDINATION_VOLUME" >/dev/null 2>&1 || true
    docker network rm "$CONTROL_PLANE_NETWORK" "$CONTROL_PLANE_REHEARSAL_NETWORK" >/dev/null 2>&1 || true
    if docker ps --all --format '{{.Names}}' | grep -F -q "${project_name}-"; then
        fail 'operation-owned containers remained after successful lab teardown'
    fi
    if docker volume ls --format '{{.Name}}' | grep -F -q "${project_name}-"; then
        fail 'operation-owned volumes remained after successful lab teardown'
    fi
    if docker network ls --format '{{.Name}}' | grep -F -q "${project_name}-"; then
        fail 'operation-owned networks remained after successful lab teardown'
    fi
    active_lab=0
}

cleanup_on_exit()
{
    exit_status=$?
    trap - EXIT HUP INT TERM
    if [ "$active_lab" = 1 ]; then
        terminate_registered_workers
        if ! reconcile_active_backup_quiesce; then
            printf 'CONTROL_PLANE_BLUE_GREEN_LAB_CLEANUP_FAILURE backup quiesce state was preserved before Docker teardown\n' >&2
        fi
    fi
    if [ -n "$paused_dependency_container" ]; then
        docker unpause "$paused_dependency_container" >/dev/null 2>&1 || true
    fi
    if [ "$exit_status" -ne 0 ]; then
        printf 'CONTROL_PLANE_BLUE_GREEN_LAB_PRESERVED root=%s;project=%s;scenario=%s\n' \
            "$LAB_ROOT" "${project_name:-none}" "${scenario_name:-none}" >&2
    fi
    docker rm --force "$MOCK_REGISTRY_CONTAINER" >/dev/null 2>&1 || true
    exit "$exit_status"
}

remove_mock_image()
{
    docker rm --force "$MOCK_REGISTRY_CONTAINER" >/dev/null 2>&1 || true
    docker image rm "$MOCK_IMMUTABLE_IMAGE" "$MOCK_REGISTRY_TAG" "$MOCK_IMAGE" \
        >/dev/null 2>&1 || true
    ! docker container inspect "$MOCK_REGISTRY_CONTAINER" >/dev/null 2>&1 \
        || fail 'operation-owned mock registry container remained after removal'
    ! docker image inspect "$MOCK_IMMUTABLE_IMAGE" >/dev/null 2>&1 \
        || fail 'operation-owned immutable registry image remained after removal'
    ! docker image inspect "$MOCK_IMAGE" >/dev/null 2>&1 \
        || fail 'operation-owned mock image remained after removal'
    ! docker image inspect "$MOCK_REGISTRY_TAG" >/dev/null 2>&1 \
        || fail 'operation-owned registry image tag remained after removal'
}

publish_mock_image()
{
    docker run --detach --pull never --name "$MOCK_REGISTRY_CONTAINER" \
        --publish 127.0.0.1::5000 "$MOCK_REGISTRY_IMAGE" >/dev/null
    mock_registry_port=$(docker port "$MOCK_REGISTRY_CONTAINER" 5000/tcp | awk -F: '{print $NF}')
    printf '%s' "$mock_registry_port" | grep -Eq '^[1-9][0-9]{0,4}$' \
        || fail 'mock image registry exposed an invalid port'
    mock_registry_attempt=0
    until curl --fail --silent --show-error --max-time 2 \
        "http://127.0.0.1:${mock_registry_port}/v2/" >/dev/null 2>&1
    do
        mock_registry_attempt=$((mock_registry_attempt + 1))
        [ "$mock_registry_attempt" -lt 30 ] || fail 'mock image registry did not become ready'
        sleep 0.1
    done
    MOCK_REGISTRY_TAG="localhost:${mock_registry_port}/control-plane-blue-green-lab:${LAB_INVOCATION_TOKEN}"
    docker tag "$MOCK_IMAGE" "$MOCK_REGISTRY_TAG"
    docker push "$MOCK_REGISTRY_TAG" >/dev/null
    MOCK_IMMUTABLE_IMAGE=$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' \
        "$MOCK_REGISTRY_TAG" | awk -v repository="${MOCK_REGISTRY_TAG%:*}@" \
        'index($0, repository) == 1 { print; exit }')
    printf '%s' "$MOCK_IMMUTABLE_IMAGE" \
        | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._/:@-]*@sha256:[a-f0-9]{64}$' \
        || fail 'mock image registry did not produce an immutable digest reference'
}

exit_from_signal()
{
    signal_exit_status=$1
    trap - HUP INT TERM
    exit "$signal_exit_status"
}

with_lab()
{
    scenario_name=$1
    scenario_number=$2
    scenario_function=$3

    start_lab "$scenario_name" "$scenario_number"
    "$scenario_function"
    cleanup_lab
}

operator()
{
    operator_status=0
    "$OPERATOR" "$1" || operator_status=$?
    assert_lab_global_transaction_lock
    return "$operator_status"
}

operator_without_backup_attestation_inputs()
{
    CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER_SHA256='' \
    CONTROL_PLANE_BACKUP_DATABASE_DUMP_FILE='' \
    CONTROL_PLANE_BACKUP_REDIS_SNAPSHOT_FILE='' \
    CONTROL_PLANE_BACKUP_STATE_ARCHIVE_FILE='' \
    CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_FILE='' \
    CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_SHA256='' \
    CONTROL_PLANE_BACKUP_ATTESTATION_FILE='' \
    CONTROL_PLANE_BACKUP_ATTESTATION_SHA256='' \
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_SYSTEM_IDENTIFIER='' \
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_DATABASE_OID='' \
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_VERSION='' \
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_DATABASE_NAME='' \
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_HOST_IDENTITY='' \
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_CONTAINER_ID='' \
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_IMAGE_ID='' \
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_IMAGE_DIGEST='' \
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_ENDPOINT='' \
    CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_IMAGE_DIGEST='' \
    CONTROL_PLANE_BACKUP_EXPECTED_RECIPIENT_FINGERPRINT='' \
    CONTROL_PLANE_BACKUP_EXPECTED_QUIESCE_OPERATOR_SHA256='' \
    CONTROL_PLANE_BACKUP_EXPECTED_RESTORE_TARGET_IDENTITY='' \
    CONTROL_PLANE_BACKUP_MAX_AGE_SECONDS='' \
    CONTROL_PLANE_BACKUP_EXPECTED_OPERATOR_REHEARSAL_HOOK_SHA256='' \
    CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_BOOT_HOOK_SHA256='' \
    CONTROL_PLANE_BACKUP_EXPECTED_CANDIDATE_API_PROBE_HOOK_SHA256='' \
    CONTROL_PLANE_BACKUP_EXPECTED_STATE_PROOF_TOOL_SHA256='' \
        "$OPERATOR" "$1"
}

operator_with_stale_backup_attestation_inputs()
{
    CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER="$scenario_directory/absent-backup-verifier" \
    CONTROL_PLANE_BACKUP_ATTESTATION_VERIFIER_SHA256=ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff \
    CONTROL_PLANE_BACKUP_ATTESTATION_FILE="$scenario_directory/absent-backup-attestation" \
    CONTROL_PLANE_BACKUP_ATTESTATION_SHA256=ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff \
        "$OPERATOR" "$1"
}

assert_operator_target_mode_binding_rejected()
{
    rejected_operation_id="production-mode-lab-rejection-${scenario_number}-0123456789"
    rejected_operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$rejected_operation_id"
    rejected_log="$scenario_directory/production-mode-lab-target-rejected.log"
    if env \
        CONTROL_PLANE_OPERATION_ID="$rejected_operation_id" \
        CONTROL_PLANE_TEST_MODE=0 \
        CONTROL_PLANE_OPERATOR_TARGET=lab \
        "$OPERATOR" preflight > "$rejected_log" 2>&1; then
        fail 'production mode accepted the lab operator target'
    fi
    grep -F -q 'must be exactly 0:production or 1:lab' "$rejected_log" \
        || fail 'production-mode/lab-target rejection did not reach the operator target binding gate'
    [ ! -e "$rejected_operation_directory" ] \
        || fail 'rejected production-mode/lab-target operator invocation mutated operation state'
}

preflight_and_apply_migrations()
{
    preflight_log="$scenario_directory/preflight.log"
    if ! operator preflight > "$preflight_log" 2>&1; then
        sed -n '1,240p' "$preflight_log" >&2
        fail 'control-plane preflight failed'
    fi
    assert_candidate_trusted_proxy_peer_addresses "$CONTROL_PLANE_GREEN_CONTAINER"
    apply_migrations_log="$scenario_directory/apply-migrations.log"
    if ! operator apply-migrations > "$apply_migrations_log" 2>&1; then
        sed -n '1,240p' "$apply_migrations_log" >&2
        fail 'control-plane live migration failed'
    fi
}

replace_lab_traefik_static_config()
{
    replacement_static_config=$1
    chmod 600 "$LAB_TRAEFIK_STATIC_CONFIG"
    cp "$replacement_static_config" "$LAB_TRAEFIK_STATIC_CONFIG"
    chmod 400 "$LAB_TRAEFIK_STATIC_CONFIG"
}

assert_proxy_enrollment_proxy_binding()
{
    expected_proxy_enrollment_binding=$1
    case "$expected_proxy_enrollment_binding" in
        native)
            docker inspect "$CONTROL_PLANE_PROXY_CONTAINER" | jq --exit-status '
                [ (.[0].NetworkSettings.Ports["8000/tcp"] // [])[]
                    | [.HostIp, .HostPort]
                ] == [["127.0.0.1", "8000"]]
            ' >/dev/null
            ;;
        legacy)
            docker inspect "$CONTROL_PLANE_PROXY_CONTAINER" | jq --exit-status '
                [ (.[0].NetworkSettings.Ports["8000/tcp"] // [])[] ] == []
            ' >/dev/null
            ;;
        *)
            fail 'proxy-enrollment test requested an unknown proxy binding'
            ;;
    esac
}

assert_proxy_enrollment_blue_binding()
{
    expected_proxy_enrollment_binding=$1
    case "$expected_proxy_enrollment_binding" in
        legacy)
            docker inspect "$CONTROL_PLANE_BLUE_CONTAINER" | jq --exit-status '
                [ (.[0].NetworkSettings.Ports["8080/tcp"] // [])[]
                    | [.HostIp, .HostPort]
                ] == [["127.0.0.1", "8000"]]
            ' >/dev/null
            ;;
        absent)
            docker inspect "$CONTROL_PLANE_BLUE_CONTAINER" | jq --exit-status '
                [ (.[0].NetworkSettings.Ports["8080/tcp"] // [])[] ] == []
            ' >/dev/null
            ;;
        *)
            fail 'proxy-enrollment test requested an unknown blue binding'
            ;;
    esac
}

assert_proxy_enrollment_phase()
{
    expected_proxy_enrollment_phase=$1
    grep -F -x -q "phase=$expected_proxy_enrollment_phase" \
        "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_STATE_FILE" \
        || fail "proxy-enrollment did not reach phase=$expected_proxy_enrollment_phase"
}

assert_proxy_enrollment_runner_bootstraps_from_green()
{
    proxy_enrollment_runner_source=$(sed -n '/^proxy_enrollment_runner_call()/,/^}/p' "$OPERATOR")
    # These assertions inspect literal source expressions.
    # shellcheck disable=SC2016
    printf '%s\n' "$proxy_enrollment_runner_source" \
        | grep -F -q -- '--entrypoint php "$green_image"' \
        || fail 'proxy-enrollment runner does not bootstrap the command from the pinned candidate image'
    # shellcheck disable=SC2016
    printf '%s\n' "$proxy_enrollment_runner_source" \
        | grep -F -q 'assert_immutable_image "$green_image" CONTROL_PLANE_GREEN_IMAGE' \
        || fail 'proxy-enrollment runner does not revalidate the pinned candidate image before each action'
    # shellcheck disable=SC2016
    printf '%s\n' "$proxy_enrollment_runner_source" \
        | grep -F -q 'docker_container_presence "$runner_name"' \
        || fail 'proxy-enrollment runner does not reject an exact leftover action container'
    # This assertion rejects the literal legacy variable reference.
    # shellcheck disable=SC2016
    if printf '%s\n' "$proxy_enrollment_runner_source" | grep -F -q '$blue_container'; then
        fail 'proxy-enrollment runner still uses the legacy container as its bootstrap executor'
    fi
}

prepare_proxy_enrollment_lab()
{
    proxy_enrollment_topology=$1
    proxy_enrollment_native_static_config="$scenario_directory/traefik-native.yml"
    proxy_enrollment_legacy_static_config="$scenario_directory/traefik-legacy.yml"
    proxy_enrollment_legacy_proxy_compose="$scenario_directory/proxy-legacy-compose.yaml"
    proxy_enrollment_source_custom="$scenario_directory/source-legacy-app-port.yml"
    proxy_enrollment_command="$scenario_directory/proxy-enrollment-command"
    CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE="$scenario_directory/proxy-enrollment.yml"
    CONTROL_PLANE_PROXY_ENROLLMENT_COMMAND=$proxy_enrollment_command
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_STATE_FILE="$scenario_directory/proxy-enrollment.state"
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_LOG_FILE="$scenario_directory/proxy-enrollment.log"
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_OPERATOR_STATE_FILE="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID/state"
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_PROXY_CONTAINER=$CONTROL_PLANE_PROXY_CONTAINER
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_BLUE_CONTAINER=$CONTROL_PLANE_BLUE_CONTAINER
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_PROXY_PROJECT=$project_name
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_PROXY_NATIVE_COMPOSE="$LAB_DIRECTORY/compose.yaml"
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_PROXY_LEGACY_COMPOSE=$proxy_enrollment_legacy_proxy_compose
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_STATIC_CONFIG=$LAB_TRAEFIK_STATIC_CONFIG
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_NATIVE_STATIC_CONFIG=$proxy_enrollment_native_static_config
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_LEGACY_STATIC_CONFIG=$proxy_enrollment_legacy_static_config
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_COMPOSE_OVERRIDE=$CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_DYNAMIC_DIRECTORY=$CONTROL_PLANE_TRAEFIK_DYNAMIC_DIR
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_MODE=normal

    cp "$LAB_TRAEFIK_STATIC_CONFIG" "$proxy_enrollment_native_static_config"
    chmod 400 "$proxy_enrollment_native_static_config"
    awk '
        /^  coolify-local:$/ {
            omit_local_entrypoint = 1
            next
        }
        omit_local_entrypoint && /^    address: ":8000"$/ {
            omit_local_entrypoint = 0
            next
        }
        { print }
    ' "$proxy_enrollment_native_static_config" > "$proxy_enrollment_legacy_static_config"
    chmod 400 "$proxy_enrollment_legacy_static_config"
    awk '$0 != "      - \"127.0.0.1:8000:8000\"" { print }' \
        "$LAB_DIRECTORY/compose.yaml" > "$proxy_enrollment_legacy_proxy_compose"
    chmod 600 "$proxy_enrollment_legacy_proxy_compose"
    {
        printf '%s\n' 'services:' '  coolify:' '    ports:' \
            '      - "127.0.0.1:8000:8080"'
    } > "$proxy_enrollment_source_custom"
    chmod 600 "$proxy_enrollment_source_custom"

    case "$proxy_enrollment_topology" in
        native)
            {
                printf '%s\n' 'services:' '  coolify:' '    ports: !reset null'
            } > "$CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE"
            chmod 600 "$CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE"
            {
                printf 'phase=enrolled\n'
                printf 'compose_override_sha256=%s\n' "$(sha256sum \
                    "$CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE" | awk '{print $1}')"
            } > "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_STATE_FILE"
            chmod 600 "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_STATE_FILE"
            set -- docker compose --ansi never \
                --project-name "$CONTROL_PLANE_SOURCE_COMPOSE_PROJECT" \
                --env-file "$CONTROL_PLANE_SOURCE_ENV_FILE" \
                --file "$CONTROL_PLANE_SOURCE_COMPOSE_BASE" \
                --file "$CONTROL_PLANE_SOURCE_COMPOSE_PROD"
            if [ -f "$CONTROL_PLANE_SOURCE_COMPOSE_CUSTOM" ] \
                && [ ! -L "$CONTROL_PLANE_SOURCE_COMPOSE_CUSTOM" ]; then
                set -- "$@" --file "$CONTROL_PLANE_SOURCE_COMPOSE_CUSTOM"
            fi
            if [ -f "$CONTROL_PLANE_SOURCE_COMPOSE_POSTGRES" ] \
                && [ ! -L "$CONTROL_PLANE_SOURCE_COMPOSE_POSTGRES" ]; then
                set -- "$@" --file "$CONTROL_PLANE_SOURCE_COMPOSE_POSTGRES"
            fi
            set -- "$@" --file "$CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE"
            "$@" up --detach --force-recreate --no-build --pull never --no-deps \
                "$CONTROL_PLANE_SOURCE_COMPOSE_SERVICE" >/dev/null
            ;;
        legacy)
            replace_lab_traefik_static_config "$proxy_enrollment_legacy_static_config"
            docker rm --force "$CONTROL_PLANE_PROXY_CONTAINER" >/dev/null
            if docker inspect "$CONTROL_PLANE_PROXY_CONTAINER" >/dev/null 2>&1; then
                fail 'native proxy identity remained while preparing the legacy APP_PORT owner'
            fi
            CONTROL_PLANE_SOURCE_COMPOSE_CUSTOM=$proxy_enrollment_source_custom
            export CONTROL_PLANE_SOURCE_COMPOSE_CUSTOM
            docker compose --ansi never --project-name "$CONTROL_PLANE_SOURCE_COMPOSE_PROJECT" \
                --env-file "$CONTROL_PLANE_SOURCE_ENV_FILE" \
                --file "$CONTROL_PLANE_SOURCE_COMPOSE_BASE" --file "$CONTROL_PLANE_SOURCE_COMPOSE_PROD" \
                --file "$CONTROL_PLANE_SOURCE_COMPOSE_CUSTOM" \
                up --detach --force-recreate --no-build --pull never --no-deps \
                "$CONTROL_PLANE_SOURCE_COMPOSE_SERVICE" >/dev/null
            docker compose --ansi never --project-name "$project_name" \
                --file "$proxy_enrollment_legacy_proxy_compose" \
                up --detach --force-recreate --no-build --pull never --no-deps proxy >/dev/null
            ;;
        *) fail 'proxy-enrollment fixture requested an unknown topology' ;;
    esac
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_SOURCE_COMPOSE_FILES="$CONTROL_PLANE_SOURCE_COMPOSE_BASE,$CONTROL_PLANE_SOURCE_COMPOSE_PROD"
    if [ -f "$CONTROL_PLANE_SOURCE_COMPOSE_CUSTOM" ] \
        && [ ! -L "$CONTROL_PLANE_SOURCE_COMPOSE_CUSTOM" ]; then
        CONTROL_PLANE_TEST_PROXY_ENROLLMENT_SOURCE_COMPOSE_FILES="$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_SOURCE_COMPOSE_FILES,$CONTROL_PLANE_SOURCE_COMPOSE_CUSTOM"
    fi
    if [ -f "$CONTROL_PLANE_SOURCE_COMPOSE_POSTGRES" ] \
        && [ ! -L "$CONTROL_PLANE_SOURCE_COMPOSE_POSTGRES" ]; then
        CONTROL_PLANE_TEST_PROXY_ENROLLMENT_SOURCE_COMPOSE_FILES="$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_SOURCE_COMPOSE_FILES,$CONTROL_PLANE_SOURCE_COMPOSE_POSTGRES"
    fi
    cp "$LAB_DIRECTORY/proxy-enrollment-command.sh" "$proxy_enrollment_command"
    chmod 700 "$proxy_enrollment_command"
    export CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE
    export CONTROL_PLANE_PROXY_ENROLLMENT_COMMAND
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_STATE_FILE
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_LOG_FILE
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_OPERATOR_STATE_FILE
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_PROXY_CONTAINER
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_BLUE_CONTAINER
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_PROXY_PROJECT
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_PROXY_NATIVE_COMPOSE
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_PROXY_LEGACY_COMPOSE
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_STATIC_CONFIG
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_NATIVE_STATIC_CONFIG
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_LEGACY_STATIC_CONFIG
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_COMPOSE_OVERRIDE
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_DYNAMIC_DIRECTORY
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_SOURCE_COMPOSE_FILES
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_MODE
    assert_proxy_enrollment_proxy_binding "$proxy_enrollment_topology"
    case "$proxy_enrollment_topology" in
        native) assert_proxy_enrollment_blue_binding absent ;;
        legacy) assert_proxy_enrollment_blue_binding legacy ;;
    esac
    assert_source_traefik_identity
    wait_for_blue
}

prepare_proxy_enrollment_native_lab()
{
    prepare_proxy_enrollment_lab native
}

prepare_proxy_enrollment_legacy_lab()
{
    prepare_proxy_enrollment_lab legacy
}

scenario_proxy_enrollment_success()
{
    prepare_proxy_enrollment_legacy_lab
    assert_proxy_enrollment_runner_bootstraps_from_green
    if docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        php artisan control-plane:proxy-enrollment status >/dev/null 2>&1; then
        fail 'legacy latest unexpectedly provides the proxy-enrollment Artisan command'
    fi

    preflight_and_apply_migrations
    assert_proxy_enrollment_phase activated
    assert_proxy_enrollment_proxy_binding native
    assert_proxy_enrollment_blue_binding absent
    [ -f "$CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE" ] \
        || fail 'native enrollment did not retain the controlled source compose override'
    operator cutover >/dev/null
    assert_proxy_enrollment_phase enrolled
    grep -F -x -q prepare "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_LOG_FILE"
    grep -F -x -q activate "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_LOG_FILE"
    grep -F -x -q finalize "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_LOG_FILE"
}

scenario_proxy_enrollment_preidentity_recovery()
{
    prepare_proxy_enrollment_legacy_lab
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_MODE=fail-activate-before-proxy
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_MODE
    if CONTROL_PLANE_TEST_CRASH_AT=after-proxy-enrollment-rollback-pending-legacy \
        operator preflight > "$scenario_directory/proxy-enrollment-prestate-crash.log" 2>&1; then
        fail 'pre-state enrollment rollback crash injection unexpectedly completed'
    fi
    assert_proxy_enrollment_phase rollback-pending-legacy
    assert_proxy_enrollment_proxy_binding legacy
    assert_proxy_enrollment_blue_binding absent
    [ ! -e "$CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE" ] \
        || fail 'rollback-pending legacy recovery retained the source-port suppression override'
    [ ! -e "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_OPERATOR_STATE_FILE" ] \
        || fail 'pre-state enrollment failure created durable operator state'

    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_MODE=normal
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_MODE
    operator preflight >/dev/null
    assert_proxy_enrollment_phase activated
    assert_proxy_enrollment_proxy_binding native
    assert_proxy_enrollment_blue_binding absent
}

scenario_proxy_enrollment_activating_recovery()
{
    prepare_proxy_enrollment_legacy_lab
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_MODE=crash-activate-after-proxy-removal
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_MODE
    if operator preflight > "$scenario_directory/proxy-enrollment-activating-crash.log" 2>&1; then
        fail 'activating enrollment crash injection unexpectedly completed'
    fi
    assert_proxy_enrollment_phase activating
    if docker inspect "$CONTROL_PLANE_PROXY_CONTAINER" >/dev/null 2>&1; then
        fail 'activating crash fixture did not leave proxy identity unavailable before recovery'
    fi

    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_MODE=normal
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_MODE
    operator preflight >/dev/null
    assert_proxy_enrollment_phase activated
    assert_proxy_enrollment_proxy_binding native
    assert_proxy_enrollment_blue_binding absent
}

scenario_proxy_enrollment_persisted_rollback_recovery()
{
    prepare_proxy_enrollment_legacy_lab
    preflight_and_apply_migrations
    [ -f "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_OPERATOR_STATE_FILE" ] \
        || fail 'persisted rollback recovery fixture did not create durable operator state'
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_MODE=crash-rollback-after-intent
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_MODE
    if CONTROL_PLANE_TEST_INVALID_ROUTE=1 operator cutover \
        > "$scenario_directory/proxy-enrollment-rolling-back-crash.log" 2>&1; then
        fail 'invalid-route cutover fixture unexpectedly completed'
    fi
    assert_proxy_enrollment_phase rolling-back
    assert_proxy_enrollment_proxy_binding native
    assert_proxy_enrollment_blue_binding absent
    [ -f "$CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE" ] \
        || fail 'rolling-back crash lost the enrolled source compose override before restoration'

    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_MODE=normal
    export CONTROL_PLANE_TEST_PROXY_ENROLLMENT_MODE
    if CONTROL_PLANE_TEST_CRASH_AT=after-proxy-enrollment-rollback-pending-legacy \
        operator rollback > "$scenario_directory/proxy-enrollment-pending-legacy-crash.log" 2>&1; then
        fail 'rollback-pending-legacy crash injection unexpectedly completed'
    fi
    assert_proxy_enrollment_phase rollback-pending-legacy
    assert_proxy_enrollment_proxy_binding legacy
    assert_proxy_enrollment_blue_binding absent
    [ ! -e "$CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE" ] \
        || fail 'rollback-pending-legacy retained the source-port suppression override'

    operator rollback >/dev/null
    assert_proxy_enrollment_phase rolled-back
    assert_proxy_enrollment_proxy_binding legacy
    assert_proxy_enrollment_blue_binding legacy
    [ ! -e "$CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE" ] \
        || fail 'terminal persisted rollback retained the source-port suppression override'
    [ "$(grep -F -x -c rollback "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_LOG_FILE")" -ge 3 ] \
        || fail 'persisted rollback recovery did not retry the exact rollback action through both durable phases'
    assert_route_color "https://127.0.0.1:${LAB_TRAEFIK_PORT}/cgi-bin/request" legacy
    assert_route_color http://127.0.0.1:8000/cgi-bin/request legacy
}

scenario_proxy_enrollment_partial_credential_recovery()
{
    prepare_proxy_enrollment_legacy_lab
    proxy_enrollment_credential_file="$CONTROL_PLANE_OPERATOR_STATE_DIR/native-traefik-enrollment-v1.token"
    if CONTROL_PLANE_TEST_CRASH_AT=after-proxy-enrollment-credential-candidate-created \
        operator preflight > "$scenario_directory/proxy-enrollment-partial-credential-crash.log" 2>&1; then
        fail 'native Traefik enrollment partial credential crash injection unexpectedly completed'
    fi
    [ ! -e "$proxy_enrollment_credential_file" ] \
        || fail 'partial credential crash unexpectedly published a durable enrollment credential'
    proxy_enrollment_partial_candidate=
    for discovered_proxy_enrollment_candidate in \
        "${proxy_enrollment_credential_file}".new.*
    do
        [ -f "$discovered_proxy_enrollment_candidate" ] \
            && [ ! -L "$discovered_proxy_enrollment_candidate" ] \
            || continue
        proxy_enrollment_partial_candidate=$discovered_proxy_enrollment_candidate
        break
    done
    [ -n "$proxy_enrollment_partial_candidate" ] \
        || fail 'partial credential crash did not leave its mode-0600 candidate'
    [ ! -s "$proxy_enrollment_partial_candidate" ] \
        || fail 'partial credential crash did not leave an empty credential candidate'
    [ "$(stat -c '%a' "$proxy_enrollment_partial_candidate" 2>/dev/null \
        || stat -f '%Lp' "$proxy_enrollment_partial_candidate")" = 600 ] \
        || fail 'partial credential crash candidate does not have mode 0600'
    [ ! -e "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_OPERATOR_STATE_FILE" ] \
        || fail 'partial credential crash created durable release state before enrollment'

    operator preflight >/dev/null
    [ -f "$proxy_enrollment_credential_file" ] \
        || fail 'partial credential restart did not publish a replacement enrollment credential'
    [ "$(stat -c '%h' "$proxy_enrollment_credential_file" 2>/dev/null \
        || stat -f '%l' "$proxy_enrollment_credential_file")" = 1 ] \
        || fail 'partial credential restart published an ambiguous enrollment credential link count'
    [ ! -e "$proxy_enrollment_partial_candidate" ] \
        || fail 'partial credential restart retained the unparseable staging candidate'
    assert_proxy_enrollment_phase activated
    assert_proxy_enrollment_proxy_binding native
    assert_proxy_enrollment_blue_binding absent
}

scenario_proxy_enrollment_cross_operation_adoption()
{
    prepare_proxy_enrollment_legacy_lab
    proxy_enrollment_credential_file="$CONTROL_PLANE_OPERATOR_STATE_DIR/native-traefik-enrollment-v1.token"
    if CONTROL_PLANE_TEST_CRASH_AT=after-proxy-enrollment-credential-linked \
        operator preflight > "$scenario_directory/proxy-enrollment-credential-publication-crash.log" 2>&1; then
        fail 'native Traefik enrollment credential publication crash injection unexpectedly completed'
    fi
    [ -f "$proxy_enrollment_credential_file" ] \
        || fail 'credential publication crash did not leave the durable native Traefik enrollment credential'
    proxy_enrollment_credential_candidate=
    for discovered_proxy_enrollment_candidate in \
        "${proxy_enrollment_credential_file}".new.*
    do
        [ -f "$discovered_proxy_enrollment_candidate" ] \
            && [ ! -L "$discovered_proxy_enrollment_candidate" ] \
            || continue
        proxy_enrollment_credential_candidate=$discovered_proxy_enrollment_candidate
        break
    done
    [ -n "$proxy_enrollment_credential_candidate" ] \
        || fail 'credential publication crash did not leave its linked staging candidate'
    [ "$(stat -c '%d:%i' "$proxy_enrollment_credential_candidate" 2>/dev/null \
        || stat -f '%d:%i' "$proxy_enrollment_credential_candidate")" = \
        "$(stat -c '%d:%i' "$proxy_enrollment_credential_file" 2>/dev/null \
        || stat -f '%d:%i' "$proxy_enrollment_credential_file")" ] \
        || fail 'credential publication crash did not leave a same-inode staging link'
    [ "$(stat -c '%h' "$proxy_enrollment_credential_file" 2>/dev/null \
        || stat -f '%l' "$proxy_enrollment_credential_file")" = 2 ] \
        || fail 'credential publication crash did not exercise the linked-before-unlink recovery state'
    [ ! -e "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_OPERATOR_STATE_FILE" ] \
        || fail 'credential publication crash created durable release state before enrollment'
    operator preflight >/dev/null
    [ "$(stat -c '%h' "$proxy_enrollment_credential_file" 2>/dev/null \
        || stat -f '%l' "$proxy_enrollment_credential_file")" = 1 ] \
        || fail 'credential publication restart did not reconcile the same-inode staging link'
    [ ! -e "$proxy_enrollment_credential_candidate" ] \
        || fail 'credential publication restart retained its linked staging candidate'
    preflight_and_apply_migrations
    operator cutover >/dev/null
    assert_proxy_enrollment_phase enrolled
    first_enrollment_operation=$(awk -F= '$1 == "operation_id" { print $2 }' \
        "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_STATE_FILE")
    first_enrollment_token_sha256=$(awk -F= '$1 == "token_sha256" { print $2 }' \
        "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_STATE_FILE")
    [ "$first_enrollment_operation" = native-traefik-enrollment-v1 ] \
        || fail 'first native Traefik enrollment did not use the host-stable durable operation identity'
    printf '%s' "$first_enrollment_token_sha256" | grep -Eq '^[0-9a-f]{64}$' \
        || fail 'first native Traefik enrollment did not persist a valid host-stable token digest'

    operator rollback >/dev/null
    assert_proxy_enrollment_phase rolled-back
    assert_proxy_enrollment_proxy_binding legacy
    assert_proxy_enrollment_blue_binding legacy
    [ ! -e "$CONTROL_PLANE_PROXY_ENROLLMENT_COMPOSE_OVERRIDE" ] \
        || fail 'release rollback retained the native source compose override'
    assert_route_color "https://127.0.0.1:${LAB_TRAEFIK_PORT}/cgi-bin/request" legacy
    assert_route_color http://127.0.0.1:8000/cgi-bin/request legacy

    CONTROL_PLANE_OPERATION_ID="operation-${scenario_name}-second-0123456789"
    CONTROL_PLANE_TEST_PROXY_ENROLLMENT_OPERATOR_STATE_FILE="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID/state"
    export CONTROL_PLANE_OPERATION_ID CONTROL_PLANE_TEST_PROXY_ENROLLMENT_OPERATOR_STATE_FILE
    printf 'encrypted-database-dump=%s\n' "$CONTROL_PLANE_OPERATION_ID" \
        > "$CONTROL_PLANE_BACKUP_DATABASE_DUMP_FILE"
    printf 'encrypted-redis-snapshot=%s\n' "$CONTROL_PLANE_OPERATION_ID" \
        > "$CONTROL_PLANE_BACKUP_REDIS_SNAPSHOT_FILE"
    printf 'encrypted-control-plane-state=%s\n' "$CONTROL_PLANE_OPERATION_ID" \
        > "$CONTROL_PLANE_BACKUP_STATE_ARCHIVE_FILE"
    printf 'capture-manifest=%s\nsource_redis_image_id=%s\n' "$CONTROL_PLANE_OPERATION_ID" \
        "$CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_REDIS_IMAGE_ID" \
        > "$CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_FILE"
    printf 'restore-attestation=%s\n' "$CONTROL_PLANE_OPERATION_ID" \
        > "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE"
    CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_SHA256=$(sha256sum \
        "$CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_FILE" | awk '{print $1}')
    CONTROL_PLANE_BACKUP_ATTESTATION_SHA256=$(sha256sum \
        "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE" | awk '{print $1}')
    export CONTROL_PLANE_BACKUP_CAPTURE_MANIFEST_SHA256 CONTROL_PLANE_BACKUP_ATTESTATION_SHA256

    operator preflight >/dev/null
    [ -f "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_OPERATOR_STATE_FILE" ] \
        || fail 'second release preflight did not create an independent durable operation state'
    assert_proxy_enrollment_phase activated
    second_enrollment_operation=$(awk -F= '$1 == "operation_id" { print $2 }' \
        "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_STATE_FILE")
    second_enrollment_token_sha256=$(awk -F= '$1 == "token_sha256" { print $2 }' \
        "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_STATE_FILE")
    [ "$second_enrollment_operation" = "$first_enrollment_operation" ] \
        && [ "$second_enrollment_token_sha256" = "$first_enrollment_token_sha256" ] \
        || fail 'second release could not adopt the exact existing native Traefik enrollment credentials'
    [ "$(grep -F -x -c prepare "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_LOG_FILE")" = 2 ] \
        && [ "$(grep -F -x -c activate "$CONTROL_PLANE_TEST_PROXY_ENROLLMENT_LOG_FILE")" = 2 ] \
        || fail 'second release did not perform one exact enrollment after the prior terminal rollback'
    assert_proxy_enrollment_proxy_binding native
    assert_proxy_enrollment_blue_binding absent
}

scenario_queue_gate_and_rehearsal()
{
    assert_operator_target_mode_binding_rejected
    live_operation_id=$CONTROL_PLANE_OPERATION_ID
    CONTROL_PLANE_OPERATION_ID="rehearsal-${scenario_name}-0123456789"
    export CONTROL_PLANE_OPERATION_ID
    operator rehearse-migrations >/dev/null
    grep -F -q 'migration rehearsal completed' \
        "$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID/migration-rehearsal.log"
    CONTROL_PLANE_OPERATION_ID=$live_operation_id
    export CONTROL_PLANE_OPERATION_ID

    docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --command \
            "insert into application_deployment_queues (status, horizon_job_worker) values ('queued', null), ('in_progress', null);" >/dev/null
    preflight_log="$scenario_directory/preflight.log"
    if ! operator preflight > "$preflight_log" 2>&1; then
        sed -n '1,240p' "$preflight_log" >&2
        fail 'control-plane preflight failed'
    fi
    if operator cutover >/dev/null 2>&1; then
        fail 'cutover accepted an operation without a persisted live-expand migration artifact'
    fi
    docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --command \
            "INSERT INTO migrations (migration, batch) VALUES ('2099_01_01_000000_unreviewed_future_migration', 1);" \
        >/dev/null
    if operator apply-migrations > "$scenario_directory/unreviewed-ledger.log" 2>&1; then
        fail 'live migration accepted an unreviewed preexisting migration ledger row'
    fi
    grep -F -q 'migration ledger surplus differs from the exact immutable three-row baseline inventory' \
        "$scenario_directory/unreviewed-ledger.log" \
        || fail 'unreviewed migration ledger row did not reach the immutable inventory gate'
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    grep -F -x -q 'phase=preflight-ready' "$operation_directory/state"
    grep -F -x -q 'migration_status=not-applied' "$operation_directory/state"
    schema_change_count=$(docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --tuples-only --no-align --command "
            SELECT
                (to_regclass('application_blue_green_deployments') IS NOT NULL)::integer
                + (to_regclass('application_blue_green_deactivations') IS NOT NULL)::integer
                + (SELECT count(*) FROM information_schema.columns
                    WHERE table_schema = current_schema()
                      AND column_name LIKE 'blue_green_%');")
    [ "$schema_change_count" = 0 ] \
        || fail 'unreviewed migration ledger rejection occurred after schema mutation'
    docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --command \
            "DELETE FROM migrations WHERE migration = '2099_01_01_000000_unreviewed_future_migration';" \
        >/dev/null
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service set-reserved 1
    docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --command \
            "insert into application_deployment_queues (status, horizon_job_worker) values ('in_progress', 'other-worker');" >/dev/null
    live_insert_id_file="$scenario_directory/live-application-setting-id"
    live_migration_gate=/lab-state/live-migration-concurrency-gate
    live_migration_gate_host="$LAB_RUNTIME_STATE_DIR/live-migration-concurrency-gate"
    rm -f "${live_migration_gate_host}.ready" "${live_migration_gate_host}.release"
    (
        release_queue_blockers()
        {
            docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
                /usr/local/bin/control-plane-lab-service set-reserved 0 >/dev/null 2>&1 || true
            docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
                psql --username postgres --dbname postgres --command \
                    "delete from application_deployment_queues where horizon_job_worker is not null;" \
                >/dev/null 2>&1 || true
        }

        release_concurrency_gate()
        {
            touch "${live_migration_gate_host}.release"
        }

        trap 'release_queue_blockers; release_concurrency_gate' EXIT
        migration_attempt_state_file="$operation_directory/live-expand-migration-attempts/attempt-1.state"
        running_attempt=0
        until grep -F -x -q 'migration_status=running' "$operation_directory/state" \
            && grep -F -x -q 'migration_attempt=1' "$operation_directory/state" \
            && grep -F -x -q 'status=planned' "$migration_attempt_state_file" 2>/dev/null
        do
            running_attempt=$((running_attempt + 1))
            [ "$running_attempt" -lt 300 ] \
                || fail 'live migration did not persist its planned attempt for concurrent write proof'
            sleep 1
        done
        release_queue_blockers
        running_attempt=0
        until [ -e "${live_migration_gate_host}.ready" ]; do
            running_attempt=$((running_attempt + 1))
            [ "$running_attempt" -lt 600 ] \
                || fail 'live migration runner did not reach its concurrent-write gate'
            sleep 1
        done
        migration_runner_name=$(sed -n 's/^runner_name=//p' "$migration_attempt_state_file")
        [ "$(docker inspect --format '{{.State.Running}}' "$migration_runner_name")" = true ] \
            || fail 'live migration runner was not active during the concurrent write proof'
        docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
            psql --username postgres --dbname postgres --tuples-only --no-align --command \
                'INSERT INTO application_settings DEFAULT VALUES RETURNING id;' \
            | sed -n '1p' > "$live_insert_id_file"
        release_concurrency_gate
        trap - EXIT
    ) &
    release_pid=$!
    register_worker "$release_pid"
    CONTROL_PLANE_LAB_MIGRATION_GATE_FILE="$live_migration_gate" \
        operator apply-migrations >/dev/null
    wait_registered_worker "$release_pid"
    rm -f "${live_migration_gate_host}.ready" "${live_migration_gate_host}.release"
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    grep -F -x -q 'phase=live-expand-migrations-applied' "$operation_directory/state"
    grep -F -x -q 'migration_status=applied' "$operation_directory/state"
    grep -F -x -q 'migration_attempt=1' "$operation_directory/state"
    grep -F -x -q 'migration_lock_timeout=750ms' "$operation_directory/state"
    grep -F -x -q 'migration_statement_timeout=30s' "$operation_directory/state"
    grep -F -q 'live expand migration completed' "$operation_directory/live-expand-migration-attempts/attempt-1.log"
    grep -F -x -q 'status=applied' \
        "$operation_directory/live-expand-migration-attempts/attempt-1.state"
    test -s "$operation_directory/live-expand-migration-artifact/compatibility"
    test -s "$operation_directory/live-expand-migration-artifact/manifest"
    test -s "$operation_directory/live-expand-migration-artifact/post-verification"
    grep -F -x -q 'application_setting=true' \
        "$operation_directory/live-expand-migration-artifact/post-verification"
    backlog_count=$(docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --tuples-only --no-align \
            --command "select count(*) from application_deployment_queues where horizon_job_worker is null;")
    [ "$backlog_count" = 2 ] || fail 'durable queued/null-worker backlog was incorrectly drained'
    live_insert_id=$(cat "$live_insert_id_file")
    printf '%s' "$live_insert_id" | grep -Eq '^[1-9][0-9]*$' \
        || fail 'concurrent application-settings insert did not return a row identity'
    live_insert_count=$(docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --tuples-only --no-align \
            --command "SELECT count(*) FROM application_settings WHERE id = $live_insert_id::bigint;")
    [ "$live_insert_count" = 1 ] \
        || fail 'concurrent application-settings insert was not preserved across additive migration'
    grep -F -q 'row_counts_after=application_deployment_queues,2' \
        "$operation_directory/live-expand-migration-artifact/post-verification" \
        || fail 'post-migration row-count observation did not record concurrent queue deletion'
    grep -F -q 'row_counts_after=application_settings,1' \
        "$operation_directory/live-expand-migration-artifact/post-verification" \
        || fail 'post-migration row-count observation did not record concurrent settings insertion'
    operator rollback >/dev/null
}

scenario_crash_after_migration_artifact()
{
    operator preflight >/dev/null
    if CONTROL_PLANE_TEST_CRASH_AT=after-migration-artifacts "$OPERATOR" apply-migrations >/dev/null 2>&1; then
        fail 'artifact crash injection unexpectedly completed'
    fi
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    test -s "$operation_directory/live-expand-migration-artifact/compatibility"
    test -s "$operation_directory/live-expand-migration-artifact/manifest"
    artifact_checksum_before=$(sha256sum "$operation_directory/live-expand-migration-artifact/compatibility" | awk '{print $1}')
    operator apply-migrations >/dev/null
    artifact_checksum_after=$(sha256sum "$operation_directory/live-expand-migration-artifact/compatibility" | awk '{print $1}')
    [ "$artifact_checksum_before" = "$artifact_checksum_after" ] || fail 'artifact retry rewrote the persisted compatibility input'
    grep -F -x -q 'migration_attempt=1' "$operation_directory/state"
    grep -F -x -q 'migration_status=applied' "$operation_directory/state"
    operator rollback >/dev/null
}

scenario_terminal_migration_crash_direct_cutover()
{
    operator preflight >/dev/null
    if CONTROL_PLANE_TEST_CRASH_AT=after-migration-attempt-terminal-state \
        "$OPERATOR" apply-migrations >/dev/null 2>&1; then
        fail 'terminal migration-state crash injection unexpectedly completed'
    fi
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    grep -F -x -q 'phase=live-expand-migrations-running' "$operation_directory/state"
    grep -F -x -q 'status=applied' \
        "$operation_directory/live-expand-migration-attempts/attempt-1.state"

    operator cutover >/dev/null

    grep -F -x -q 'phase=green-routed' "$operation_directory/state"
    grep -F -x -q 'migration_status=applied' "$operation_directory/state"
    grep -F -x -q 'migration_attempt=1' "$operation_directory/state"
    rollback_log="$scenario_directory/terminal-migration-cutover.rollback.log"
    if ! operator rollback > "$rollback_log" 2>&1; then
        sed -n '1,240p' "$rollback_log" >&2
        fail "terminal migration cutover rollback failed; log=$rollback_log"
    fi
}

scenario_rehearsal_crash_recovery()
{
    rehearsal_crash_point=${CONTROL_PLANE_REHEARSAL_CRASH_POINT:-after-migration-rehearsal-terminal-state}
    live_operation_id=$CONTROL_PLANE_OPERATION_ID
    CONTROL_PLANE_OPERATION_ID="rehearsal-crash-${scenario_name}-0123456789"
    export CONTROL_PLANE_OPERATION_ID

    if CONTROL_PLANE_TEST_CRASH_AT="$rehearsal_crash_point" \
        "$OPERATOR" rehearse-migrations > "$scenario_directory/rehearsal-crash.log" 2>&1; then
        fail "rehearsal crash injection unexpectedly completed: $rehearsal_crash_point"
    fi
    rehearsal_operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    test -s "$rehearsal_operation_directory/migration-rehearsal.state"

    operator rehearse-migrations > "$scenario_directory/rehearsal-recovery.log" 2>&1

    rehearsal_state="$rehearsal_operation_directory/migration-rehearsal.state"
    rehearsal_log="$rehearsal_operation_directory/migration-rehearsal.log"
    grep -F -x -q 'status=passed' "$rehearsal_state"
    rehearsal_log_sha256=$(sha256sum "$rehearsal_log" | awk '{print $1}')
    grep -F -x -q "runner_log_sha256=$rehearsal_log_sha256" "$rehearsal_state"
    [ -z "$(docker ps --all --quiet \
        --filter "label=io.coolify.control-plane.operation-id=$CONTROL_PLANE_OPERATION_ID" \
        --filter 'label=io.coolify.control-plane.migration-rehearsal=true')" ] \
        || fail 'migration rehearsal runner remained after crash recovery cleanup'
    grep -F -q 'migration-rehearsal-passed' "$scenario_directory/rehearsal-recovery.log"

    CONTROL_PLANE_OPERATION_ID=$live_operation_id
    export CONTROL_PLANE_OPERATION_ID
}

run_restore_rehearsal_hook()
{
    restore_rehearsal_crash_at=$1
    env \
        CONTROL_PLANE_TEST_MODE=1 \
        CONTROL_PLANE_TEST_CRASH_AT="$restore_rehearsal_crash_at" \
        CONTROL_PLANE_OPERATION_ID="$restore_rehearsal_operation_id" \
        CONTROL_PLANE_CANDIDATE_IMAGE_DIGEST="$CONTROL_PLANE_GREEN_IMAGE" \
        CONTROL_PLANE_SOURCE_PG_SYSTEM_IDENTIFIER="$backup_source_system_identifier" \
        CONTROL_PLANE_RESTORE_PG_SYSTEM_IDENTIFIER="$rehearsal_system_identifier" \
        CONTROL_PLANE_RESTORE_DATABASE_CONTAINER="$CONTROL_PLANE_REHEARSAL_DATABASE_CONTAINER" \
        CONTROL_PLANE_RESTORE_DATABASE_USER="$CONTROL_PLANE_REHEARSAL_DATABASE_USER" \
        CONTROL_PLANE_RESTORE_DATABASE_NAME="$CONTROL_PLANE_REHEARSAL_DATABASE_NAME" \
        CONTROL_PLANE_SOURCE_REDIS_ENDPOINT="${CONTROL_PLANE_REDIS_CONTAINER}:6379" \
        CONTROL_PLANE_RESTORE_REDIS_CONTAINER="$CONTROL_PLANE_REHEARSAL_DATABASE_CONTAINER" \
        CONTROL_PLANE_RESTORE_REDIS_ENDPOINT="${CONTROL_PLANE_REHEARSAL_DATABASE_CONTAINER}:6379" \
        CONTROL_PLANE_RESTORE_REDIS_NETWORK="$CONTROL_PLANE_REHEARSAL_NETWORK" \
        CONTROL_PLANE_REHEARSAL_COMPOSE_FILE="$CONTROL_PLANE_TEST_REHEARSAL_COMPOSE_FILE" \
        CONTROL_PLANE_EXPECTED_REHEARSAL_COMPOSE_SHA256="$restore_rehearsal_compose_sha256" \
        CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE="$CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE" \
        CONTROL_PLANE_REHEARSAL_NETWORK="$CONTROL_PLANE_REHEARSAL_NETWORK" \
        CONTROL_PLANE_LIVE_NETWORK="$CONTROL_PLANE_NETWORK" \
        CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256="$CONTROL_PLANE_REHEARSAL_EXPECTED_MIGRATION_LEDGER_SHA256" \
        CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256="$CONTROL_PLANE_REHEARSAL_EXPECTED_MIGRATION_PENDING_SHA256" \
        CONTROL_PLANE_EXPECTED_MIGRATION_BATCH="$CONTROL_PLANE_REHEARSAL_EXPECTED_MIGRATION_BATCH" \
        CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT="$CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT" \
        CONTROL_PLANE_MIGRATION_STATEMENT_TIMEOUT="$CONTROL_PLANE_MIGRATION_STATEMENT_TIMEOUT" \
        CONTROL_PLANE_CANDIDATE_STATE_PROOF_FILE="$scenario_directory/candidate-state-proof" \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/backup/operator-rehearsal-hook.sh"
}

scenario_restore_rehearsal_crash_recovery()
{
    restore_rehearsal_crash_point=${CONTROL_PLANE_RESTORE_REHEARSAL_CRASH_POINT:-after-restore-rehearsal-terminal-state}
    restore_rehearsal_operation_id="restore-rehearsal-crash-${scenario_name}-0123456789"
    restore_rehearsal_compose_sha256=$(sha256sum \
        "$CONTROL_PLANE_TEST_REHEARSAL_COMPOSE_FILE" | awk '{print $1}')

    if run_restore_rehearsal_hook "$restore_rehearsal_crash_point" \
        > "$scenario_directory/restore-rehearsal-crash.log" 2>&1; then
        fail "restore rehearsal crash injection unexpectedly completed: $restore_rehearsal_crash_point"
    fi
    restore_rehearsal_key=$(printf '%s' "$restore_rehearsal_operation_id" \
        | sha256sum | awk '{print substr($1, 1, 20)}')
    restore_rehearsal_state="$scenario_directory/operator-rehearsal-$restore_rehearsal_key.state"
    restore_rehearsal_log="$scenario_directory/operator-rehearsal-$restore_rehearsal_key.log"
    if ! test -s "$restore_rehearsal_state"; then
        sed -n '1,240p' "$scenario_directory/restore-rehearsal-crash.log" >&2
        fail 'restore rehearsal crash did not leave durable state'
    fi

    if ! run_restore_rehearsal_hook '' \
        > "$scenario_directory/restore-rehearsal-recovery.log" 2>&1; then
        sed -n '1,240p' "$scenario_directory/restore-rehearsal-recovery.log" >&2
        fail 'restore rehearsal did not recover from its terminal-state crash'
    fi

    grep -F -x -q 'status=passed' "$restore_rehearsal_state"
    grep -F -x -q 'mode=apply' "$restore_rehearsal_state"
    grep -F -x -q 'generation=1' "$restore_rehearsal_state"
    restore_rehearsal_log_sha256=$(sha256sum "$restore_rehearsal_log" | awk '{print $1}')
    grep -F -x -q "runner_log_sha256=$restore_rehearsal_log_sha256" \
        "$restore_rehearsal_state"
    [ -z "$(docker ps --all --quiet \
        --filter "label=io.coolify.control-plane.operation-id=$restore_rehearsal_operation_id" \
        --filter 'label=io.coolify.control-plane.restore-rehearsal=true')" ] \
        || fail 'restore rehearsal runner remained after crash recovery cleanup'
    grep -F -x -q 'control-plane-restore-rehearsal=passed' \
        "$scenario_directory/restore-rehearsal-recovery.log"
}

scenario_unknown_migration_outcome_retries()
{
    operator preflight >/dev/null
    migration_gate=/lab-state/migration-orphan-gate
    migration_gate_host="$LAB_RUNTIME_STATE_DIR/migration-orphan-gate"
    if CONTROL_PLANE_TEST_CRASH_AT=during-live-expand-migrations \
        CONTROL_PLANE_LAB_MIGRATION_GATE_FILE="$migration_gate" "$OPERATOR" apply-migrations \
            > "$scenario_directory/migration-crash.log" 2>&1; then
        fail 'unknown-outcome crash injection unexpectedly completed'
    fi
    attempts=0
    while ! (
        exec 9> "$CONTROL_PLANE_OPERATOR_STATE_DIR/.control-plane-blue-green.lock"
        flock -n 9
    ); do
        attempts=$((attempts + 1))
        [ "$attempts" -lt 60 ] || fail 'crashed migration child did not release the operator lock'
        sleep 1
    done
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    if ! grep -F -x -q 'phase=live-expand-migrations-running' "$operation_directory/state"; then
        cat "$scenario_directory/migration-crash.log" >&2
        fail 'migration crash did not leave a running durable attempt'
    fi
    grep -F -x -q 'migration_status=running' "$operation_directory/state"
    grep -E -x -q 'status=(launched|active)' \
        "$operation_directory/live-expand-migration-attempts/attempt-1.state"
    migration_runner_name=$(sed -n 's/^runner_name=//p' \
        "$operation_directory/live-expand-migration-attempts/attempt-1.state")
    printf '%s' "$migration_runner_name" | grep -Eq '^coolify-cp-migrate-[a-f0-9]{24}-a1$' \
        || fail 'crashed live migration did not persist its deterministic runner name'
    attempts=0
    while [ ! -e "${migration_gate_host}.ready" ]; do
        attempts=$((attempts + 1))
        [ "$attempts" -lt 50 ] || fail 'live migration runner did not reach its deterministic gate'
        sleep 0.1
    done
    [ "$(docker inspect --format '{{.State.Running}}' "$migration_runner_name")" = true ] \
        || fail 'deterministically gated live migration runner is not active'
    if operator apply-migrations > "$scenario_directory/running-migration-retry.log" 2>&1; then
        fail 'retry accepted a live migration runner that was still active'
    fi
    grep -F -q 'previous live migration runner is still active; retry is forbidden' \
        "$scenario_directory/running-migration-retry.log"
    touch "${migration_gate_host}.release"
    until [ "$(docker inspect --format '{{.State.Running}}' "$migration_runner_name")" = false ]; do
        sleep 1
    done
    operator apply-migrations >/dev/null
    grep -F -x -q 'migration_attempt=1' "$operation_directory/state"
    grep -F -x -q 'migration_status=applied' "$operation_directory/state"
    grep -F -x -q 'status=applied' \
        "$operation_directory/live-expand-migration-attempts/attempt-1.state"
    ! docker inspect "$migration_runner_name" >/dev/null 2>&1 \
        || fail 'reconciled live migration runner remained after exact removal'
    operator rollback >/dev/null
}

scenario_failed_migration_retries_only_with_identical_input()
{
    operator preflight >/dev/null
    if CONTROL_PLANE_LAB_FAIL_MIGRATION=1 "$OPERATOR" apply-migrations >/dev/null 2>&1; then
        fail 'failing live migration unexpectedly completed'
    fi
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    compatibility_original="$operation_directory/migration-compatibility.original"
    cp -p "$CONTROL_PLANE_MIGRATION_COMPATIBILITY_FILE" "$compatibility_original"
    grep -F -x -q 'phase=live-expand-migrations-failed' "$operation_directory/state"
    grep -F -x -q 'migration_attempt=1' "$operation_directory/state"
    grep -F -x -q 'status=failed' \
        "$operation_directory/live-expand-migration-attempts/attempt-1.state"
    printf '%s\n' 'operator-review=changed-input' >> "$CONTROL_PLANE_MIGRATION_COMPATIBILITY_FILE"
    if operator apply-migrations >/dev/null 2>&1; then
        fail 'migration retry accepted changed compatibility input'
    fi
    grep -F -x -q 'migration_attempt=1' "$operation_directory/state"
    grep -F -x -q 'migration_status=failed' "$operation_directory/state"
    cp -p "$compatibility_original" "$CONTROL_PLANE_MIGRATION_COMPATIBILITY_FILE"
    operator apply-migrations >/dev/null
    grep -F -x -q 'migration_attempt=2' "$operation_directory/state"
    grep -F -x -q 'migration_status=applied' "$operation_directory/state"
    grep -F -x -q 'status=failed' \
        "$operation_directory/live-expand-migration-attempts/attempt-1.state"
    grep -F -x -q 'status=applied' \
        "$operation_directory/live-expand-migration-attempts/attempt-2.state"
    operator rollback >/dev/null
}

scenario_partial_or_wrong_batch_migration_blocks()
{
    partial_migration_count=2
    operator preflight >/dev/null
    if CONTROL_PLANE_LAB_PARTIAL_MIGRATION_COUNT=$partial_migration_count \
        "$OPERATOR" apply-migrations >/dev/null 2>&1; then
        fail 'partially committed migration attempt unexpectedly completed'
    fi
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    grep -F -x -q 'migration_attempt=1' "$operation_directory/state"
    grep -F -x -q 'migration_status=blocked' "$operation_directory/state"
    grep -F -x -q 'status=blocked' \
        "$operation_directory/live-expand-migration-attempts/attempt-1.state"
    initial_batch=$(sed -n 's/^migration_expected_batch=//p' "$operation_directory/state")
    second_batch=$((initial_batch + 1))

    docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --set ON_ERROR_STOP=1 \
            --command "INSERT INTO migrations (migration, batch) VALUES ('unrelated_control_plane_row', ${second_batch})" \
        >/dev/null
    if operator apply-migrations >/dev/null 2>&1; then
        fail 'migration retry accepted an unrelated ledger row'
    fi
    grep -F -x -q 'migration_attempt=1' "$operation_directory/state"
    [ ! -e "$operation_directory/live-expand-migration-attempts/attempt-2.state" ] \
        || fail 'rejected ledger drift allocated a migration attempt'
    docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --set ON_ERROR_STOP=1 \
            --command "DELETE FROM migrations WHERE migration = 'unrelated_control_plane_row'" \
        >/dev/null

    if operator apply-migrations >/dev/null 2>&1; then
        fail 'blocked partial migration outcome was retried after unrelated row removal'
    fi
    grep -F -x -q 'migration_status=blocked' "$operation_directory/state"
    grep -F -x -q 'migration_attempt=1' "$operation_directory/state"
    [ ! -e "$operation_directory/live-expand-migration-attempts/attempt-2.state" ] \
        || fail 'blocked partial migration outcome allocated a successor attempt'
}

scenario_lock_timeout_resumes_blue()
{
    CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT=3s
    export CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT
    operator preflight >/dev/null
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    docker exec --env PGAPPNAME=control-plane-blue-green-preflight-lock-test \
        "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --set ON_ERROR_STOP=1 \
            --command 'BEGIN; LOCK TABLE application_settings IN ACCESS EXCLUSIVE MODE; SELECT pg_sleep(600); COMMIT;' \
        > "$scenario_directory/preflight-lock-holder.log" 2>&1 &
    preflight_lock_holder_pid=$!
    register_worker "$preflight_lock_holder_pid"
    attempts=0
    while :; do
        lock_count=$(docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
            psql --username postgres --dbname postgres --tuples-only --no-align \
                --command "SELECT count(*) FROM pg_locks lock JOIN pg_stat_activity activity USING (pid) WHERE lock.relation = 'application_settings'::regclass AND lock.mode = 'AccessExclusiveLock' AND lock.granted AND activity.application_name = 'control-plane-blue-green-preflight-lock-test'")
        [ "$lock_count" = 1 ] && break
        attempts=$((attempts + 1))
        [ "$attempts" -lt 20 ] || fail 'preflight database lock holder did not acquire ACCESS EXCLUSIVE'
        sleep 1
    done

    timeout_status_file="$scenario_directory/preflight-lock-timeout.status"
    (
        if operator apply-migrations > "$scenario_directory/preflight-lock-timeout.log" 2>&1; then
            printf '0\n' > "$timeout_status_file"
        else
            printf '%s\n' "$?" > "$timeout_status_file"
        fi
    ) &
    timeout_operator_pid=$!
    register_worker "$timeout_operator_pid"
    timeout_probe_attempt=0
    blocked_backend_pid=
    while [ -z "$blocked_backend_pid" ] && [ ! -s "$timeout_status_file" ]; do
        blocked_backend_pid=$(docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
            psql --username postgres --dbname postgres --tuples-only --no-align \
                --command "SELECT pid FROM pg_stat_activity WHERE pid <> pg_backend_pid() AND query LIKE '%FROM application_settings%' AND wait_event_type = 'Lock' ORDER BY pid LIMIT 1")
        timeout_probe_attempt=$((timeout_probe_attempt + 1))
        [ "$timeout_probe_attempt" -lt 90 ] \
            || fail 'migration preflight SQL did not reach the blocked database query'
        [ -n "$blocked_backend_pid" ] || sleep 1
    done
    [ -n "$blocked_backend_pid" ] \
        || fail 'migration preflight SQL exited without exposing the expected blocked database query'
    sleep 5
    blocked_backend_count=$(docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --tuples-only --no-align \
            --command "SELECT count(*) FROM pg_stat_activity WHERE pid = ${blocked_backend_pid}")
    [ "$blocked_backend_count" = 0 ] \
        || fail 'migration preflight SQL timeout did not terminate the blocked database backend'
    wait_registered_worker "$timeout_operator_pid"
    [ "$(cat "$timeout_status_file")" != 0 ] \
        || fail 'migration preflight SQL ignored its configured lock timeout'
    grep -F -q 'canceling statement due to lock timeout' \
        "$scenario_directory/preflight-lock-timeout.log" \
        || fail 'migration preflight failure was not caused by the configured lock timeout'
    grep -F -x -q 'phase=preflight-ready' "$operation_directory/state"
    grep -F -x -q 'migration_status=not-applied' "$operation_directory/state"
    [ ! -e "$operation_directory/live-expand-migration-attempts/attempt-1.state" ] \
        || fail 'bounded preflight SQL failure allocated a migration attempt'
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service service-up horizon
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service service-up scheduler-worker
    docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --set ON_ERROR_STOP=1 \
            --command "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE application_name = 'control-plane-blue-green-preflight-lock-test'" \
        >/dev/null
    wait_registered_worker "$preflight_lock_holder_pid" || true

    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service set-reserved 1
    apply_status_file="$scenario_directory/ddl-lock-apply-status"
    (
        set +e
        operator apply-migrations > "$scenario_directory/ddl-lock-timeout.log" 2>&1
        printf '%s\n' "$?" > "$apply_status_file"
    ) &
    apply_pid=$!
    register_worker "$apply_pid"
    attempts=0
    until grep -F -x -q 'phase=live-expand-migrations-running' "$operation_directory/state"; do
        [ ! -s "$apply_status_file" ] \
            || fail 'migration exited before reaching the durable running state for DDL lock injection'
        attempts=$((attempts + 1))
        [ "$attempts" -lt 120 ] || fail 'migration did not reach running state before DDL lock injection'
        sleep 1
    done

    docker exec --env PGAPPNAME=control-plane-blue-green-ddl-lock-test \
        "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --set ON_ERROR_STOP=1 \
            --command 'BEGIN; LOCK TABLE application_settings IN ACCESS EXCLUSIVE MODE; SELECT pg_sleep(600); COMMIT;' \
        > "$scenario_directory/ddl-lock-holder.log" 2>&1 &
    ddl_lock_holder_pid=$!
    register_worker "$ddl_lock_holder_pid"
    attempts=0
    while :; do
        lock_count=$(docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
            psql --username postgres --dbname postgres --tuples-only --no-align \
                --command "SELECT count(*) FROM pg_locks lock JOIN pg_stat_activity activity USING (pid) WHERE lock.relation = 'application_settings'::regclass AND lock.mode = 'AccessExclusiveLock' AND lock.granted AND activity.application_name = 'control-plane-blue-green-ddl-lock-test'")
        [ "$lock_count" = 1 ] && break
        attempts=$((attempts + 1))
        [ "$attempts" -lt 20 ] || fail 'DDL database lock holder did not acquire ACCESS EXCLUSIVE'
        sleep 1
    done
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service set-reserved 0
    wait_registered_worker "$apply_pid"
    apply_status=$(cat "$apply_status_file")
    if [ "$apply_status" -eq 0 ]; then
        fail 'live migration ignored its configured lock timeout'
    fi
    grep -F -x -q 'phase=live-expand-migrations-failed' "$operation_directory/state"
    grep -F -x -q 'migration_status=failed' "$operation_directory/state"
    grep -F -q '55P03' "$operation_directory/live-expand-migration-attempts/attempt-1.log"
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service service-up horizon
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service service-up scheduler-worker
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service service-up nightwatch-agent

    docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --set ON_ERROR_STOP=1 \
            --command "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE application_name = 'control-plane-blue-green-ddl-lock-test'" \
        >/dev/null
    wait_registered_worker "$ddl_lock_holder_pid" || true
    operator apply-migrations >/dev/null
    grep -F -x -q 'migration_attempt=2' "$operation_directory/state"
    grep -F -x -q 'migration_status=applied' "$operation_directory/state"
    operator rollback >/dev/null
}

scenario_stale_epoch()
{
    docker volume create "$CONTROL_PLANE_GREEN_STATE_VOLUME" >/dev/null
    docker run --rm --network none --user 0 \
        --mount "type=volume,source=${CONTROL_PLANE_GREEN_STATE_VOLUME},target=/state" \
        --entrypoint /bin/sh "$MOCK_IMAGE" -ec 'printf %s stale-writer-epoch-0123456789 > /state/writer-epoch'
    if operator preflight >/dev/null 2>&1; then
        fail 'preflight accepted a stale writer marker'
    fi
}

scenario_concurrent_operator()
{
    CONTROL_PLANE_TEST_HOLD_LOCK_SECONDS=3 "$OPERATOR" preflight > "$LAB_ROOT/concurrent-first.log" 2>&1 &
    first_operator_pid=$!
    register_worker "$first_operator_pid"
    sleep 1
    if operator preflight >/dev/null 2>&1; then
        fail 'concurrent operator acquired the operation lock'
    fi
    wait_registered_worker "$first_operator_pid"
    operator rollback >/dev/null
}

assert_route_health_dependency_gate()
{
    route_health_token=$(cat "$CONTROL_PLANE_GREEN_ROUTE_HEALTH_RUNTIME_FILE")
    route_health_status=$(docker exec "$CONTROL_PLANE_GREEN_CONTAINER" \
        curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
            --header "Host: $CONTROL_PLANE_HOST" \
            --header "X-Control-Plane-Route-Health: $route_health_token" \
            http://127.0.0.1:8080/api/control-plane/route-health)
    [ "$route_health_status" = 204 ] \
        || fail 'candidate route health was not ready before dependency-loss proof'

    passive_status=$(docker exec \
        --env CONTROL_PLANE_MODE=passive \
        --env REQUEST_METHOD=GET \
        --env "HTTP_HOST=$CONTROL_PLANE_HOST" \
        --env "HTTP_X_CONTROL_PLANE_ROUTE_HEALTH=$route_health_token" \
        "$CONTROL_PLANE_GREEN_CONTAINER" /srv/www/cgi-bin/route-health \
        | sed -n 's/^Status: \([0-9][0-9][0-9]\).*/\1/p')
    [ "$passive_status" = 404 ] \
        || fail 'candidate route health accepted passive mode'
    full_status=$(docker exec \
        --env CONTROL_PLANE_STARTUP_MODE=full \
        --env REQUEST_METHOD=GET \
        --env "HTTP_HOST=$CONTROL_PLANE_HOST" \
        --env "HTTP_X_CONTROL_PLANE_ROUTE_HEALTH=$route_health_token" \
        "$CONTROL_PLANE_GREEN_CONTAINER" /srv/www/cgi-bin/route-health \
        | sed -n 's/^Status: \([0-9][0-9][0-9]\).*/\1/p')
    [ "$full_status" = 404 ] \
        || fail 'candidate route health accepted full startup mode'

    paused_dependency_container=$CONTROL_PLANE_REDIS_CONTAINER
    docker pause "$CONTROL_PLANE_REDIS_CONTAINER" >/dev/null
    route_health_command_status=0
    route_health_status=$(docker exec "$CONTROL_PLANE_GREEN_CONTAINER" \
        curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
            --header "Host: $CONTROL_PLANE_HOST" \
            --header "X-Control-Plane-Route-Health: $route_health_token" \
            http://127.0.0.1:8080/api/control-plane/route-health) \
        || route_health_command_status=$?
    docker unpause "$CONTROL_PLANE_REDIS_CONTAINER" >/dev/null
    paused_dependency_container=
    [ "$route_health_command_status" -eq 0 ] && [ "$route_health_status" = 503 ] \
        || fail 'candidate route health accepted an unavailable Redis dependency'

    paused_dependency_container=$CONTROL_PLANE_DATABASE_CONTAINER
    docker pause "$CONTROL_PLANE_DATABASE_CONTAINER" >/dev/null
    route_health_command_status=0
    route_health_status=$(docker exec "$CONTROL_PLANE_GREEN_CONTAINER" \
        curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
            --header "Host: $CONTROL_PLANE_HOST" \
            --header "X-Control-Plane-Route-Health: $route_health_token" \
            http://127.0.0.1:8080/api/control-plane/route-health) \
        || route_health_command_status=$?
    docker unpause "$CONTROL_PLANE_DATABASE_CONTAINER" >/dev/null
    paused_dependency_container=
    [ "$route_health_command_status" -eq 0 ] && [ "$route_health_status" = 503 ] \
        || fail 'candidate route health accepted an unavailable PostgreSQL dependency'

    route_health_attempt=0
    while :; do
        route_health_status=$(docker exec "$CONTROL_PLANE_GREEN_CONTAINER" \
            curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
                --header "Host: $CONTROL_PLANE_HOST" \
                --header "X-Control-Plane-Route-Health: $route_health_token" \
                http://127.0.0.1:8080/api/control-plane/route-health)
        [ "$route_health_status" = 204 ] && break
        route_health_attempt=$((route_health_attempt + 1))
        [ "$route_health_attempt" -lt 30 ] \
            || fail 'candidate route health did not recover after Redis resumed'
        sleep 0.1
    done
}

scenario_router_reload_failure()
{
    preflight_and_apply_migrations
    route_health_activation_log="$scenario_directory/route-health-activation.log"
    if CONTROL_PLANE_TEST_CRASH_AT=after-green-web-activation "$OPERATOR" cutover \
        > "$route_health_activation_log" 2>&1; then
        fail 'route-health activation crash injection unexpectedly completed'
    fi
    grep -F -x -q 'phase=green-web-activated' \
        "$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID/state" \
        || fail 'route-health dependency proof did not begin from durable web activation'
    assert_route_health_dependency_gate
    router_reload_failure_log="$scenario_directory/router-reload-failure.log"
    if CONTROL_PLANE_TEST_INVALID_ROUTE=1 "$OPERATOR" cutover \
        > "$router_reload_failure_log" 2>&1; then
        fail 'cutover accepted a malformed Traefik dynamic file'
    fi
    if ! grep -F -q \
        'exact Traefik protected provider API did not prove the managed live route' \
        "$router_reload_failure_log"; then
        sed -n '1,240p' "$router_reload_failure_log" >&2
        fail 'malformed route did not reach the live Traefik provider rejection gate'
    fi
    if ! grep -F -q 'green HTTPS route was not atomically switched and acknowledged' \
        "$router_reload_failure_log" \
        || ! grep -F -x -q 'phase=live-expand-migrations-applied' \
            "$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID/state"; then
        sed -n '1,240p' "$router_reload_failure_log" >&2
        fail 'malformed route failure did not complete durable legacy-route recovery'
    fi
    wait_for_blue
    operator rollback >/dev/null
}

scenario_route_switch_crash()
{
    preflight_and_apply_migrations
    if CONTROL_PLANE_TEST_CRASH_AT=after-green-ingress-route "$OPERATOR" cutover >/dev/null 2>&1; then
        fail 'route-switch crash injection unexpectedly completed'
    fi
    operator rollback >/dev/null
    wait_for_blue
}

scenario_blue_revoke_crash()
{
    preflight_and_apply_migrations
    operator cutover >/dev/null
    if CONTROL_PLANE_TEST_CRASH_AT=after-green-final-ingress-ack \
        "$OPERATOR" promote >/dev/null 2>&1; then
        fail 'pre-revoke final-ingress crash injection unexpectedly completed'
    fi
    docker inspect "$CONTROL_PLANE_BLUE_CONTAINER" >/dev/null \
        || fail 'pre-revoke final-ingress crash crossed the blue removal boundary'
    assert_marker_absent "$CONTROL_PLANE_GREEN_STATE_VOLUME"
    docker stop --time 2 "$CONTROL_PLANE_GREEN_CONTAINER" >/dev/null
    if operator promote >/dev/null 2>&1; then
        fail 'promotion revoked blue after the final-ack routed green target stopped'
    fi
    docker inspect "$CONTROL_PLANE_BLUE_CONTAINER" >/dev/null \
        || fail 'stopped final-ack routed green target crossed the blue removal boundary'
    docker start "$CONTROL_PLANE_GREEN_CONTAINER" >/dev/null
    if CONTROL_PLANE_TEST_CRASH_AT=after-blue-revoked "$OPERATOR" promote >/dev/null 2>&1; then
        fail 'blue-revocation crash injection unexpectedly completed'
    fi
    docker inspect "$CONTROL_PLANE_BLUE_CONTAINER" >/dev/null 2>&1 && fail 'revoked blue container still existed'
    operator recover-forward >/dev/null
    operator rollback >/dev/null
    wait_for_blue
    ! docker inspect "$CONTROL_PLANE_GREEN_CONTAINER" >/dev/null 2>&1 \
        || fail 'pre-release blue-revocation rollback left the green candidate present'
}

scenario_blue_stop_failure()
{
    preflight_and_apply_migrations
    operator cutover >/dev/null
    if CONTROL_PLANE_TEST_BLUE_STOP_FAILURE=1 "$OPERATOR" promote >/dev/null 2>&1; then
        fail 'green promoted when blue stop proof failed'
    fi
    docker inspect "$CONTROL_PLANE_BLUE_CONTAINER" >/dev/null
    assert_marker_absent "$CONTROL_PLANE_GREEN_STATE_VOLUME"
    operator rollback >/dev/null
}

scenario_blue_stop_crash_converges()
{
    preflight_and_apply_migrations
    operator cutover >/dev/null
    if CONTROL_PLANE_TEST_CRASH_AT=after-blue-stop "$OPERATOR" promote >/dev/null 2>&1; then
        fail 'blue stop crash injection unexpectedly completed'
    fi
    [ "$(docker inspect --format '{{.State.Running}}' "$CONTROL_PLANE_BLUE_CONTAINER")" = false ] \
        || fail 'blue stop crash did not preserve the exact stopped container'
    assert_marker_absent "$CONTROL_PLANE_GREEN_STATE_VOLUME"
    operator promote >/dev/null
    docker inspect "$CONTROL_PLANE_BLUE_CONTAINER" >/dev/null 2>&1 \
        && fail 'blue stop retry did not remove the exact stopped container'
    assert_marker_equals "$CONTROL_PLANE_GREEN_STATE_VOLUME" "$CONTROL_PLANE_WRITER_EPOCH"
    operator rollback >/dev/null
}

scenario_blue_remove_crash_rolls_back_with_adoption()
{
    preflight_and_apply_migrations
    operator cutover >/dev/null
    if CONTROL_PLANE_TEST_CRASH_AT=after-blue-remove "$OPERATOR" promote >/dev/null 2>&1; then
        fail 'blue removal crash injection unexpectedly completed'
    fi
    docker inspect "$CONTROL_PLANE_BLUE_CONTAINER" >/dev/null 2>&1 \
        && fail 'blue removal crash left the original incumbent present'
    if CONTROL_PLANE_TEST_CRASH_AT=after-legacy-blue-restore-create \
        "$OPERATOR" rollback >/dev/null 2>&1; then
        fail 'legacy blue restore-create crash injection unexpectedly completed'
    fi
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    grep -F -x -q 'blue_restore_status=intent' "$operation_directory/state" \
        || fail 'legacy blue restore crash did not preserve its write-ahead intent'
    restored_blue_id=$(docker inspect --format '{{.Id}}' "$CONTROL_PLANE_BLUE_CONTAINER")
    [ -n "$restored_blue_id" ] || fail 'legacy blue restore crash did not leave a candidate incumbent'
    if CONTROL_PLANE_TEST_CRASH_AT=after-forward-fence-provisioner-finalization \
        "$OPERATOR" rollback >/dev/null 2>&1; then
        fail 'post-fence-abort parent-state crash injection unexpectedly completed'
    fi
    grep -F -x -q phase=fence-abort-intent "$operation_directory/state" \
        || fail 'forward subordinate abort crash lost its durable parent intent'
    grep -F -x -q 'blue_restore_status=adopted' "$operation_directory/state" \
        || fail 'legacy blue restore retry did not durably adopt the exact incumbent'
    grep -F -x -q "blue_rollback_replacement_id=$restored_blue_id" "$operation_directory/state" \
        || fail 'legacy blue restore retry changed the crash-created incumbent identity'
    [ "$(cat "$CONTROL_PLANE_RUNTIME_FENCE_LAB_STATE")" = aborted ] \
        || fail 'subordinate runtime fence did not reach its terminal abort before parent crash'
    operator rollback >/dev/null
    wait_for_blue
    docker inspect "$CONTROL_PLANE_GREEN_CONTAINER" >/dev/null 2>&1 \
        && fail 'rollback incumbent adoption left the green candidate present'
    grep -F -x -q adopt-rollback-incumbent "${CONTROL_PLANE_RUNTIME_FENCE_LAB_STATE}.log" \
        || fail 'rollback did not execute the one-way incumbent adoption transition'
    grep -F -x -q abort "${CONTROL_PLANE_RUNTIME_FENCE_LAB_STATE}.log" \
        || fail 'rollback did not abort and finalize the active runtime fence'
}

scenario_irreversible_directional_recovery()
{
    preflight_and_apply_migrations
    operator cutover >/dev/null
    if CONTROL_PLANE_TEST_CRASH_AT=after-blue-revoked "$OPERATOR" promote >/dev/null 2>&1; then
        fail 'forward directional-recovery crash fixture unexpectedly completed'
    fi
    docker inspect "$CONTROL_PLANE_BLUE_CONTAINER" >/dev/null 2>&1 \
        && fail 'forward directional-recovery fixture did not revoke blue'
    if operator recover-abort > "$scenario_directory/recover-abort-post-revoke.log" 2>&1; then
        fail 'recovery-abort restored legacy ingress after permanent blue revocation'
    fi
    grep -F -q 'use recover-forward' "$scenario_directory/recover-abort-post-revoke.log" \
        || fail 'post-revoke recovery-abort did not identify the only safe direction'
    if operator rollback > "$scenario_directory/rollback-post-revoke.log" 2>&1; then
        fail 'rollback claimed legacy restoration after permanent blue revocation'
    fi
    grep -F -q 'use recover-forward' "$scenario_directory/rollback-post-revoke.log" \
        || fail 'post-revoke rollback did not identify the only safe direction'
    operator recover-forward >/dev/null
    grep -F -x -q phase=green-writer-promoted \
        "$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID/state" \
        || fail 'recover-forward did not complete terminal green promotion'
    assert_marker_equals "$CONTROL_PLANE_GREEN_STATE_VOLUME" "$CONTROL_PLANE_WRITER_EPOCH"

    if CONTROL_PLANE_TEST_CRASH_AT=after-failback-green-revoked \
        "$OPERATOR" rollback >/dev/null 2>&1; then
        fail 'reverse directional-recovery crash fixture unexpectedly completed'
    fi
    docker inspect "$CONTROL_PLANE_GREEN_CONTAINER" >/dev/null 2>&1 \
        && fail 'reverse directional-recovery fixture did not revoke green'
    if "$OPERATOR" abort-failback > "$scenario_directory/abort-failback-post-revoke.log" 2>&1; then
        fail 'abort-failback restored green after permanent green revocation'
    fi
    grep -F -q 'use recover-reverse-forward' \
        "$scenario_directory/abort-failback-post-revoke.log" \
        || fail 'post-revoke abort-failback did not identify the only safe direction'
    if operator rollback > "$scenario_directory/reverse-rollback-post-revoke.log" 2>&1; then
        fail 'rollback claimed green restoration after permanent green revocation'
    fi
    grep -F -q 'use recover-reverse-forward' \
        "$scenario_directory/reverse-rollback-post-revoke.log" \
        || fail 'post-revoke reverse rollback did not identify the only safe direction'
    operator recover-reverse-forward >/dev/null
    grep -F -x -q phase=blue-writer-promoted \
        "$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID/state" \
        || fail 'recover-reverse-forward did not complete terminal blue promotion'
    assert_marker_equals "$CONTROL_PLANE_BLUE_STATE_VOLUME" "$CONTROL_PLANE_BLUE_WRITER_EPOCH"
}

scenario_green_promotion_failure()
{
    preflight_and_apply_migrations
    operator cutover >/dev/null
    if CONTROL_PLANE_TEST_GREEN_PROMOTION_FAILURE=1 "$OPERATOR" promote >/dev/null 2>&1; then
        fail 'green promotion failure injection unexpectedly completed'
    fi
    docker inspect "$CONTROL_PLANE_BLUE_CONTAINER" >/dev/null 2>&1 && fail 'old blue remained restartable after revoke'
    assert_marker_absent "$CONTROL_PLANE_GREEN_STATE_VOLUME"
    for fenced_service in horizon scheduler-worker nightwatch-agent; do
        [ "$(docker exec "$CONTROL_PLANE_GREEN_CONTAINER" \
            /usr/local/bin/control-plane-lab-service service-state "$fenced_service")" \
            = sleeping ] \
            || fail "failed green promotion did not converge exact writer fencing: $fenced_service"
    done
    if CONTROL_PLANE_TEST_GREEN_PROMOTION_FAILURE=1 \
        CONTROL_PLANE_TEST_GREEN_FENCE_FAILURE=1 \
        "$OPERATOR" promote >/dev/null 2>&1; then
        fail 'green promotion completed despite injected promotion and writer-fencing failures'
    fi
    assert_marker_absent "$CONTROL_PLANE_GREEN_STATE_VOLUME"
    [ "$(docker inspect --format '{{.State.Running}}' "$CONTROL_PLANE_GREEN_CONTAINER")" \
        = false ] \
        || fail 'operator did not stop the exact candidate after writer fencing failed'
    operator recover-forward >/dev/null
    operator rollback >/dev/null
    docker inspect "$CONTROL_PLANE_GREEN_CONTAINER" >/dev/null 2>&1 \
        && fail 'failed green promotion remained restartable after reverse-fence recovery'
    assert_marker_absent "$CONTROL_PLANE_GREEN_STATE_VOLUME"
    assert_marker_equals "$CONTROL_PLANE_BLUE_STATE_VOLUME" "$CONTROL_PLANE_BLUE_WRITER_EPOCH"
    operator promote >/dev/null
    assert_marker_equals "$CONTROL_PLANE_BLUE_STATE_VOLUME" "$CONTROL_PLANE_BLUE_WRITER_EPOCH"
}

scenario_green_marker_crash_and_failback()
{
    preflight_and_apply_migrations
    operator cutover >/dev/null
    if CONTROL_PLANE_TEST_CRASH_AT=after-green-marker "$OPERATOR" promote >/dev/null 2>&1; then
        fail 'green-marker crash injection unexpectedly completed'
    fi
    assert_marker_equals "$CONTROL_PLANE_GREEN_STATE_VOLUME" "$CONTROL_PLANE_WRITER_EPOCH"
    operator recover-forward >/dev/null
    if CONTROL_PLANE_TEST_CRASH_AT=after-failback-blue-final-ingress-ack \
        "$OPERATOR" rollback >/dev/null 2>&1; then
        fail 'failback final-ingress crash injection unexpectedly completed'
    fi
    if CONTROL_PLANE_TEST_CRASH_AT=after-green-stop \
        "$OPERATOR" rollback >/dev/null 2>&1; then
        fail 'failback green-stop crash injection unexpectedly completed'
    fi
    [ "$(docker inspect --format '{{.State.Running}}' "$CONTROL_PLANE_GREEN_CONTAINER")" = false ] \
        || fail 'failback green-stop crash did not preserve the exact stopped container'
    operator rollback >/dev/null
    docker inspect "$CONTROL_PLANE_GREEN_CONTAINER" >/dev/null 2>&1 && fail 'green remained restartable after failback revoke'
    assert_marker_absent "$CONTROL_PLANE_GREEN_STATE_VOLUME"
    assert_marker_equals "$CONTROL_PLANE_BLUE_STATE_VOLUME" "$CONTROL_PLANE_BLUE_WRITER_EPOCH"
    operator promote >/dev/null
    assert_marker_equals "$CONTROL_PLANE_BLUE_STATE_VOLUME" "$CONTROL_PLANE_BLUE_WRITER_EPOCH"
}

scenario_green_marker_unlink_crash_converges()
{
    preflight_and_apply_migrations
    operator cutover >/dev/null
    operator promote >/dev/null
    if CONTROL_PLANE_TEST_CRASH_AT=after-green-writer-epoch-revoked \
        "$OPERATOR" rollback >/dev/null 2>&1; then
        fail 'green writer marker unlink crash injection unexpectedly completed'
    fi
    assert_marker_absent "$CONTROL_PLANE_GREEN_STATE_VOLUME"
    operator recover-reverse-forward >/dev/null
    assert_marker_absent "$CONTROL_PLANE_GREEN_STATE_VOLUME"
    assert_marker_equals "$CONTROL_PLANE_BLUE_STATE_VOLUME" "$CONTROL_PLANE_BLUE_WRITER_EPOCH"
}

scenario_writer_marker_and_restart_crashes_converge()
{
    preflight_and_apply_migrations
    operator cutover >/dev/null
    if CONTROL_PLANE_TEST_CRASH_AT=after-green-marker-fsync "$OPERATOR" promote >/dev/null 2>&1; then
        fail 'writer marker fsync crash injection unexpectedly completed'
    fi
    assert_marker_equals "$CONTROL_PLANE_GREEN_STATE_VOLUME" "$CONTROL_PLANE_WRITER_EPOCH"
    operator promote >/dev/null
    [ "$(docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' "$CONTROL_PLANE_GREEN_CONTAINER")" = always ] \
        || fail 'writer marker retry did not converge the restart policy'
    operator rollback >/dev/null
}

scenario_routed_replacement_blue_restart_converges()
{
    preflight_and_apply_migrations
    operator cutover >/dev/null
    operator promote >/dev/null
    if CONTROL_PLANE_TEST_CRASH_AT=after-failback-blue-ingress-route \
        "$OPERATOR" rollback >/dev/null 2>&1; then
        fail 'routed replacement blue crash injection unexpectedly completed'
    fi
    docker stop --time 2 "$CONTROL_PLANE_REPLACEMENT_BLUE_CONTAINER" >/dev/null
    operator_with_stale_backup_attestation_inputs promote >/dev/null
    [ "$(docker inspect --format '{{.State.Running}}' "$CONTROL_PLANE_REPLACEMENT_BLUE_CONTAINER")" = true ] \
        || fail 'routed replacement blue retry did not restart the exact container'
    assert_marker_equals "$CONTROL_PLANE_BLUE_STATE_VOLUME" "$CONTROL_PLANE_BLUE_WRITER_EPOCH"
}

scenario_initial_operation_directory_crash_converges()
{
    if CONTROL_PLANE_TEST_CRASH_AT=after-operation-directory-created \
        "$OPERATOR" preflight >/dev/null 2>&1; then
        fail 'initial operation-directory crash injection unexpectedly completed'
    fi
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    [ -d "$operation_directory" ] && [ ! -e "$operation_directory/state" ] \
        || fail 'initial crash did not leave the expected empty operation directory'
    operator preflight >/dev/null
    grep -F -x -q 'phase=preflight-ready' "$operation_directory/state"
    operator rollback >/dev/null
}

scenario_candidate_start_crash_converges()
{
    if CONTROL_PLANE_TEST_CRASH_AT=after-green-compose-up \
        "$OPERATOR" preflight >/dev/null 2>&1; then
        fail 'candidate-start crash injection unexpectedly completed'
    fi
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    grep -F -x -q 'phase=green-starting' "$operation_directory/state" \
        || fail 'candidate-start crash did not preserve the durable start intent'
    grep -F -x -q 'green_id=none' "$operation_directory/state" \
        || fail 'candidate-start crash recorded an unverified container ID'
    candidate_id=$(docker inspect --format '{{.Id}}' "$CONTROL_PLANE_GREEN_CONTAINER")
    [ -n "$candidate_id" ] || fail 'candidate-start crash did not leave a candidate to reconcile'
    operator preflight >/dev/null
    grep -F -x -q 'phase=preflight-ready' "$operation_directory/state"
    grep -F -x -q "green_id=$candidate_id" "$operation_directory/state" \
        || fail 'preflight retry did not adopt the exact validated candidate ID'
    [ "$(docker inspect --format '{{.Id}}' "$CONTROL_PLANE_GREEN_CONTAINER")" = "$candidate_id" ] \
        || fail 'preflight retry replaced rather than adopted the exact candidate'
    operator rollback >/dev/null
}

scenario_mutation_freeze_lease_activation_is_atomic()
{
    preflight_and_apply_migrations
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    if CONTROL_PLANE_TEST_CRASH_AT=after-green-web-activation \
        "$OPERATOR" cutover > "$scenario_directory/forward-freeze-stage.log" 2>&1; then
        fail 'forward pre-freeze staging crash injection unexpectedly completed'
    fi
    grep -F -x -q 'phase=green-web-activated' "$operation_directory/state" \
        || fail 'forward pre-freeze staging did not preserve the web-only green candidate'
    assert_mutation_freeze_marker_absent "$CONTROL_PLANE_COORDINATION_VOLUME"

    start_held_mutation_request green "$CONTROL_PLANE_GREEN_LOOPBACK_PORT"
    CONTROL_PLANE_TEST_MUTATION_FREEZE_EXCLUSIVE_LEASE_HOLD_SECONDS=5 \
    CONTROL_PLANE_TEST_CRASH_AT=after-green-mutation-freeze-epoch-published-under-exclusive-lease \
        "$OPERATOR" cutover > "$scenario_directory/forward-freeze-atomicity.log" 2>&1 &
    forward_freeze_pid=$!
    register_worker "$forward_freeze_pid"
    sleep 1
    kill -0 "$forward_freeze_pid" >/dev/null 2>&1 \
        || fail 'forward freeze activation completed while a pre-freeze shared lease was held'
    assert_mutation_freeze_marker_absent "$CONTROL_PLANE_COORDINATION_VOLUME"

    release_held_mutation_request
    wait_for_mutation_freeze_exclusive_lease "$CONTROL_PLANE_COORDINATION_VOLUME"
    assert_mutation_freeze_marker_absent "$CONTROL_PLANE_COORDINATION_VOLUME"
    start_post_freeze_mutation_request "$CONTROL_PLANE_GREEN_LOOPBACK_PORT"
    sleep 0.25
    kill -0 "$post_freeze_pid" >/dev/null 2>&1 \
        || fail 'post-freeze mutation acquired the shared lease before marker publication'

    forward_freeze_status=0
    wait "$forward_freeze_pid" || forward_freeze_status=$?
    unregister_worker "$forward_freeze_pid"
    [ "$forward_freeze_status" -ne 0 ] \
        || fail 'forward marker-publication crash injection unexpectedly completed'
    grep -F -x -q 'phase=green-web-activated' "$operation_directory/state" \
        || fail 'forward marker-publication crash advanced beyond its durable activation phase'
    assert_mutation_freeze_marker_equals "$CONTROL_PLANE_COORDINATION_VOLUME" \
        "${CONTROL_PLANE_OPERATION_ID}.fwd.mutation-freeze"
    assert_post_freeze_mutation_locked
    operator cutover >/dev/null
    grep -F -x -q 'phase=green-routed' "$operation_directory/state" \
        || fail 'forward marker-publication crash retry did not converge both green ingresses'
    operator promote >/dev/null
    grep -F -x -q 'phase=green-writer-promoted' "$operation_directory/state" \
        || fail 'forward marker-publication crash retry did not converge green promotion'

    if CONTROL_PLANE_TEST_CRASH_AT=after-failback-blue-web-activation \
        "$OPERATOR" rollback > "$scenario_directory/reverse-freeze-stage.log" 2>&1; then
        fail 'reverse pre-freeze staging crash injection unexpectedly completed'
    fi
    grep -F -x -q 'phase=failback-blue-web-activated' "$operation_directory/state" \
        || fail 'reverse pre-freeze staging did not preserve the active web-only blue candidate'
    assert_mutation_freeze_marker_absent "$CONTROL_PLANE_COORDINATION_VOLUME"

    start_held_mutation_request blue "$CONTROL_PLANE_BLUE_LOOPBACK_PORT"
    CONTROL_PLANE_TEST_MUTATION_FREEZE_EXCLUSIVE_LEASE_HOLD_SECONDS=5 \
    CONTROL_PLANE_TEST_CRASH_AT=after-blue-mutation-freeze-epoch-exclusive-lease-acquired \
        "$OPERATOR" rollback > "$scenario_directory/reverse-freeze-atomicity.log" 2>&1 &
    reverse_freeze_pid=$!
    register_worker "$reverse_freeze_pid"
    sleep 1
    kill -0 "$reverse_freeze_pid" >/dev/null 2>&1 \
        || fail 'reverse freeze activation completed while a pre-freeze shared lease was held'
    assert_mutation_freeze_marker_absent "$CONTROL_PLANE_COORDINATION_VOLUME"
    release_held_mutation_request

    reverse_freeze_status=0
    wait "$reverse_freeze_pid" || reverse_freeze_status=$?
    unregister_worker "$reverse_freeze_pid"
    [ "$reverse_freeze_status" -ne 0 ] \
        || fail 'reverse exclusive-lease crash injection unexpectedly completed'
    grep -F -x -q 'phase=failback-blue-web-activated' "$operation_directory/state" \
        || fail 'reverse exclusive-lease crash advanced beyond its durable activation phase'
    assert_mutation_freeze_marker_absent "$CONTROL_PLANE_COORDINATION_VOLUME"
    operator rollback >/dev/null
    grep -F -x -q 'phase=blue-writer-promoted' "$operation_directory/state" \
        || fail 'reverse exclusive-lease crash retry did not converge blue promotion'
}

scenario_mutation_lease_identity_race_is_rejected()
{
    mutation_lease_race=$1
    preflight_and_apply_migrations
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    if CONTROL_PLANE_TEST_CRASH_AT=after-green-web-activation \
        "$OPERATOR" cutover >/dev/null 2>&1; then
        fail 'mutation-lease race fixture did not stop before freeze activation'
    fi
    if CONTROL_PLANE_TEST_MUTATION_LEASE_RACE=$mutation_lease_race \
        "$OPERATOR" cutover > "$scenario_directory/mutation-lease-${mutation_lease_race}.log" 2>&1; then
        fail "mutation quiescence accepted a $mutation_lease_race lease-path race"
    fi
    grep -F -x -q phase=green-web-activated "$operation_directory/state" \
        || fail "mutation-lease $mutation_lease_race race advanced the operator phase"
    grep -F -q 'in-flight proxy mutations did not quiesce under the durable freeze' \
        "$scenario_directory/mutation-lease-${mutation_lease_race}.log" \
        || fail "mutation-lease $mutation_lease_race race did not fail at exact FD attestation"
}

scenario_mutation_lease_missing_race_is_rejected()
{
    scenario_mutation_lease_identity_race_is_rejected missing
}

scenario_mutation_lease_replacement_race_is_rejected()
{
    scenario_mutation_lease_identity_race_is_rejected replacement
}

scenario_forward_runtime_fence_promotion()
{
    preflight_and_apply_migrations
    if CONTROL_PLANE_TEST_CRASH_AT=after-green-web-activation \
        "$OPERATOR" cutover >/dev/null 2>&1; then
        fail 'green web activation crash injection unexpectedly completed'
    fi
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    grep -F -x -q 'phase=green-web-activated' "$operation_directory/state" \
        || fail 'green web activation crash did not preserve the pre-freeze phase'
    rm -f "$LAB_RUNTIME_STATE_DIR/mutation-inflight.ready" \
        "$LAB_RUNTIME_STATE_DIR/mutation-inflight.release"
    for held_method in GET HEAD; do
        curl --fail --silent --show-error --request "$held_method" \
            --header "Host: $CONTROL_PLANE_HOST" \
            --header 'X-Control-Plane-Mutation-Gate: hold' \
            "http://127.0.0.1:${CONTROL_PLANE_GREEN_LOOPBACK_PORT}/cgi-bin/request" >/dev/null &
        held_request_pid=$!
        register_worker "$held_request_pid"
        case "$held_method" in
            GET) held_get_pid=$held_request_pid ;;
            HEAD) held_head_pid=$held_request_pid ;;
        esac
    done
    for held_method in GET HEAD; do
        lease_wait_attempt=0
        while :; do
            if [ -f "$LAB_RUNTIME_STATE_DIR/mutation-inflight.ready" ] \
                && grep -F -x -q "$held_method" "$LAB_RUNTIME_STATE_DIR/mutation-inflight.ready"; then
                break
            fi
            lease_wait_attempt=$((lease_wait_attempt + 1))
            [ "$lease_wait_attempt" -lt 100 ] \
                || fail "in-flight ${held_method} request did not acquire its shared lease"
            sleep 0.1
        done
    done
    "$OPERATOR" cutover > "$scenario_directory/mutation-freeze-cutover.log" 2>&1 &
    cutover_pid=$!
    register_worker "$cutover_pid"
    sleep 2
    kill -0 "$cutover_pid" >/dev/null 2>&1 \
        || fail 'cutover crossed the freeze while pre-freeze dynamic requests held their leases'
    grep -F -x -q 'phase=green-web-activated' "$operation_directory/state" \
        || fail 'cutover advanced beyond green web activation while dynamic requests held their leases'
    touch "$LAB_RUNTIME_STATE_DIR/mutation-inflight.release"
    wait_registered_worker "$held_get_pid"
    wait_registered_worker "$held_head_pid"
    wait_registered_worker "$cutover_pid"
    grep -F -x -q 'phase=green-routed' "$operation_directory/state" \
        || fail 'cutover did not complete both ingress acknowledgements after dynamic requests released their leases'
    if CONTROL_PLANE_TEST_CRASH_AT=after-proxy-mutation-freeze \
        "$OPERATOR" promote > "$scenario_directory/mutation-freeze-promote.log" 2>&1; then
        fail 'proxy mutation freeze crash injection unexpectedly completed'
    fi
    grep -F -x -q 'phase=proxy-mutation-freeze-active' "$operation_directory/state" \
        || fail 'promotion crash did not retain the durable proxy mutation freeze'
    for dynamic_url in \
        "https://127.0.0.1:${LAB_TRAEFIK_PORT}/cgi-bin/request" \
        "http://127.0.0.1:8000/cgi-bin/request"
    do
        for dynamic_method in GET HEAD OPTIONS POST; do
            dynamic_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
                --request "$dynamic_method" \
                --header "Host: $CONTROL_PLANE_HOST" "$dynamic_url")
            [ "$dynamic_status" = 423 ] \
                || fail "routed control-plane ${dynamic_method} request was not explicitly locked during release"
        done
    done
    operator promote >/dev/null
    grep -F -x -q 'phase=green-writer-promoted' "$operation_directory/state"
    [ "$(cat "$CONTROL_PLANE_RUNTIME_FENCE_LAB_STATE")" = finalized ] \
        || fail 'forward promotion did not finalize the released runtime fence lifecycle'
    docker run --rm --network none \
        --mount "type=volume,source=${CONTROL_PLANE_GREEN_STATE_VOLUME},target=/state,readonly" \
        --entrypoint /bin/sh "$MOCK_IMAGE" -ec \
            'test ! -e /state/mutation-freeze-epoch' \
        || fail 'terminal forward promotion retained its proxy mutation freeze'
    awk '
        $0 == "prepare" && !prepare { prepare = NR }
        $0 == "capture" && !capture { capture = NR }
        $0 == "arm" && !arm { arm = NR }
        $0 == "prove-candidate-denied" && !denial { denial = NR }
        $0 == "verify-post-revoke" && !post_revoke { post_revoke = NR }
        $0 == "release" && !release { release = NR }
        $0 == "finalize-release" && !finalize { finalize = NR }
        END {
            if (!(prepare < capture && capture < arm && arm < denial &&
                denial < post_revoke && post_revoke < release && release < finalize)) {
                exit 1
            }
        }
    ' "${CONTROL_PLANE_RUNTIME_FENCE_LAB_STATE}.log" \
        || fail 'forward runtime fence actions were not observed in the required order'
    if CONTROL_PLANE_TEST_CRASH_AT=after-reverse-fence-arm \
        "$OPERATOR" rollback >/dev/null 2>&1; then
        fail 'reverse fence arm crash injection unexpectedly completed'
    fi
    grep -F -x -q 'phase=reverse-fence-armed' "$operation_directory/state" \
        || fail 'reverse fence arm crash did not retain its durable phase'
    ! docker inspect "$CONTROL_PLANE_REPLACEMENT_BLUE_CONTAINER" >/dev/null 2>&1 \
        || fail 'replacement blue started before reverse fence verification'
    grep -F -x -q "reverse_fence_operation_id=${CONTROL_PLANE_OPERATION_ID}.rev01" \
        "$operation_directory/state" \
        || fail 'reverse fence generation was not pinned in operator state'
    grep -F -x -q 'CONTROL_PLANE_RUNTIME_PROVIDER_CAPTURE_EXPECTATION=absent' \
        "$operation_directory/runtime-fence-rev01.env" \
        || fail 'reverse fence did not require original Docker provider keys absent'
    if CONTROL_PLANE_TEST_CRASH_AT=after-reverse-proxy-mutation-freeze \
        "$OPERATOR" rollback > "$scenario_directory/reverse-failback.log" 2>&1; then
        fail 'reverse proxy mutation freeze crash injection unexpectedly completed'
    fi
    if ! grep -F -x -q 'phase=reverse-proxy-mutation-freeze-active' "$operation_directory/state"; then
        cat "$scenario_directory/reverse-failback.log" >&2
        fail 'reverse release crash did not retain its durable mutation freeze'
    fi
    operator recover-reverse-forward >/dev/null
    grep -F -x -q 'phase=blue-writer-promoted' "$operation_directory/state"
    assert_marker_absent "$CONTROL_PLANE_GREEN_STATE_VOLUME"
    assert_marker_equals "$CONTROL_PLANE_BLUE_STATE_VOLUME" "$CONTROL_PLANE_BLUE_WRITER_EPOCH"
    docker inspect "$CONTROL_PLANE_GREEN_CONTAINER" >/dev/null 2>&1 \
        && fail 'reverse promotion retained the revoked green incumbent'
    [ "$(cat "$CONTROL_PLANE_RUNTIME_FENCE_LAB_STATE")" = finalized ] \
        || fail 'reverse promotion did not finalize its released runtime fence lifecycle'
    awk '
        $0 == "prepare" {
            prepare_count++
            if (prepare_count == 2) { reverse = 1; prepare = NR }
            next
        }
        reverse && $0 == "capture" && !capture { capture = NR }
        reverse && $0 == "arm" && !arm { arm = NR }
        reverse && $0 == "prove-candidate-denied" && !denial { denial = NR }
        reverse && $0 == "verify-post-revoke" && !post_revoke { post_revoke = NR }
        reverse && $0 == "release" && !release { release = NR }
        reverse && $0 == "finalize-release" && !finalize { finalize = NR }
        END {
            if (!(prepare < capture && capture < arm && arm < denial &&
                denial < post_revoke && post_revoke < release && release < finalize)) {
                exit 1
            }
        }
    ' "${CONTROL_PLANE_RUNTIME_FENCE_LAB_STATE}.log" \
        || fail 'reverse runtime fence actions were not observed in the required order'
}

scenario_reverse_runtime_fence_rollback()
{
    preflight_and_apply_migrations
    operator cutover >/dev/null
    operator promote >/dev/null
    if CONTROL_PLANE_TEST_CRASH_AT=after-failback-blue-ingress-route \
        "$OPERATOR" rollback >/dev/null 2>&1; then
        fail 'reverse route crash injection unexpectedly completed'
    fi
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    grep -F -x -q 'phase=blue-failback-routed' "$operation_directory/state" \
        || fail 'reverse route crash did not preserve both blue ingress acknowledgements'
    docker inspect "$CONTROL_PLANE_GREEN_CONTAINER" >/dev/null \
        || fail 'green incumbent was removed before reverse rollback'
    docker inspect "$CONTROL_PLANE_REPLACEMENT_BLUE_CONTAINER" >/dev/null \
        || fail 'replacement blue was not present before reverse rollback'

    reverse_abort_crash_log="$scenario_directory/reverse-abort-finalization-crash.log"
    if CONTROL_PLANE_TEST_CRASH_AT=after-reverse-fence-provisioner-finalization \
        "$OPERATOR" abort-failback > "$reverse_abort_crash_log" 2>&1; then
        fail 'reverse post-fence-abort parent-state crash injection unexpectedly completed'
    fi
    if ! grep -F -x -q phase=reverse-fence-abort-intent "$operation_directory/state"; then
        sed -n '1,240p' "$reverse_abort_crash_log" >&2
        fail 'reverse subordinate abort crash lost its durable parent intent'
    fi
    "$OPERATOR" abort-failback >/dev/null
    reverse_ingress_state="$operation_directory/ingress-ingress-rev01/state"
    grep -F -x -q 'status=restored-managed-v2' "$reverse_ingress_state" \
        || fail 'reverse ingress restore did not preserve the managed green predecessor'
    grep -F -x -q 'phase=green-writer-promoted' "$operation_directory/state"
    ingress_https_url="https://127.0.0.1:${LAB_TRAEFIK_PORT}/cgi-bin/request"
    ingress_local_url=http://127.0.0.1:8000/cgi-bin/request
    assert_route_color "$ingress_https_url" green
    assert_route_color "$ingress_local_url" green
    docker inspect "$CONTROL_PLANE_REPLACEMENT_BLUE_CONTAINER" >/dev/null 2>&1 \
        && fail 'reverse rollback retained replacement blue'
    assert_marker_equals "$CONTROL_PLANE_GREEN_STATE_VOLUME" "$CONTROL_PLANE_WRITER_EPOCH"
    assert_marker_absent "$CONTROL_PLANE_BLUE_STATE_VOLUME"
    [ "$(cat "$CONTROL_PLANE_RUNTIME_FENCE_LAB_STATE")" = aborted ] \
        || fail 'reverse rollback did not abort the active reverse runtime fence'
    [ "$(tail -1 "${CONTROL_PLANE_RUNTIME_FENCE_LAB_STATE}.log")" = abort ] \
        || fail 'reverse rollback did not terminalize through the provisioner abort action'

    rev01_artifact_sha256=$(
        sha256sum \
            "$operation_directory/runtime-fence-rev01.env" \
            "$operation_directory/runtime-fence-rev01-https-ack" \
            | sha256sum | awk '{print $1}'
    )
    reverse_generation_allocation_log="$scenario_directory/reverse-generation-allocation-crash.log"
    if CONTROL_PLANE_TEST_CRASH_AT=after-reverse-fence-generation-allocation \
        "$OPERATOR" rollback > "$reverse_generation_allocation_log" 2>&1; then
        fail 'reverse generation-allocation crash injection unexpectedly completed'
    fi
    if ! grep -F -x -q 'phase=reverse-fence-artifacts-preparing' \
        "$operation_directory/state"; then
        sed -n '1,240p' "$reverse_generation_allocation_log" >&2
        fail 'fresh reverse failback did not durably reserve its artifact generation'
    fi
    grep -F -x -q 'reverse_fence_generation=2' "$operation_directory/state" \
        || fail 'fresh reverse failback did not advance monotonically to generation 2'
    grep -F -x -q "reverse_fence_operation_id=${CONTROL_PLANE_OPERATION_ID}.rev02" \
        "$operation_directory/state" \
        || fail 'fresh reverse failback did not pin its generation-specific operation ID'
    [ ! -e "$operation_directory/runtime-fence-rev02.env" ] \
        || fail 'reverse generation allocation wrote artifacts before its durable state'

    if CONTROL_PLANE_TEST_CRASH_AT=after-reverse-fence-arm \
        "$OPERATOR" rollback >/dev/null 2>&1; then
        fail 'fresh reverse fence arm crash injection unexpectedly completed'
    fi
    grep -F -x -q 'phase=reverse-fence-armed' "$operation_directory/state" \
        || fail 'fresh reverse fence did not resume its reserved generation'
    grep -F -x -q \
        "reverse_fence_environment_path=$operation_directory/runtime-fence-rev02.env" \
        "$operation_directory/state" \
        || fail 'fresh reverse fence did not persist its generation-specific manifest path'
    grep -F -x -q "CONTROL_PLANE_RUNTIME_OPERATION_ID=${CONTROL_PLANE_OPERATION_ID}.rev02" \
        "$operation_directory/runtime-fence-rev02.env" \
        || fail 'fresh reverse fence manifest did not bind generation 2'
    observed_rev01_artifact_sha256=$(
        sha256sum \
            "$operation_directory/runtime-fence-rev01.env" \
            "$operation_directory/runtime-fence-rev01-https-ack" \
            | sha256sum | awk '{print $1}'
    )
    [ "$rev01_artifact_sha256" = "$observed_rev01_artifact_sha256" ] \
        || fail 'fresh reverse failback mutated immutable generation 1 artifacts'

    "$OPERATOR" abort-failback >/dev/null
    grep -F -x -q 'phase=green-writer-promoted' "$operation_directory/state" \
        || fail 'generation 2 reverse rollback did not return to terminal green'
}

scenario_runtime_env_artifact_attestation()
{
    backup_source_system_identifier=$CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_SYSTEM_IDENTIFIER
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_SYSTEM_IDENTIFIER=1
    if operator preflight > "$scenario_directory/backup-live-identity-mismatch.log" 2>&1; then
        fail 'preflight accepted a restore attestation for another live source database'
    fi
    grep -F -q 'live backup source identity differs' \
        "$scenario_directory/backup-live-identity-mismatch.log" \
        || fail 'backup source mismatch did not reach the live identity gate'
    CONTROL_PLANE_BACKUP_EXPECTED_SOURCE_PG_SYSTEM_IDENTIFIER=$backup_source_system_identifier
    if CONTROL_PLANE_TEST_BACKUP_VERIFIER_OUTPUT=extra \
        "$OPERATOR" preflight > "$scenario_directory/backup-inexact-output.log" 2>&1; then
        fail 'preflight accepted inexact backup verifier success output'
    fi
    grep -F -q 'verifier returned inexact success output' \
        "$scenario_directory/backup-inexact-output.log" \
        || fail 'inexact backup verifier output did not reach the exact-output gate'

    chmod 644 "$CONTROL_PLANE_SOURCE_ENV_FILE"
    if operator preflight >/dev/null 2>&1; then
        fail 'preflight accepted a source environment file broader than mode 0600'
    fi
    chmod 600 "$CONTROL_PLANE_SOURCE_ENV_FILE"

    if CONTROL_PLANE_TEST_CRASH_AT=after-runtime-env-artifacts \
        "$OPERATOR" preflight >/dev/null 2>&1; then
        fail 'runtime environment artifact crash injection unexpectedly completed'
    fi
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    green_runtime_env="$operation_directory/green-runtime.env"
    blue_runtime_env="$operation_directory/blue-runtime.env"
    [ -d "$operation_directory" ] && [ ! -e "$operation_directory/state" ] \
        || fail 'artifact crash did not preserve an uncommitted operation directory'
    [ "$(file_mode "$operation_directory")" = 700 ] \
        || fail 'operation directory is not mode 0700'
    source_env_sha256=$(sha256sum "$CONTROL_PLANE_SOURCE_ENV_FILE" | awk '{print $1}')
    [ "$(sha256sum "$green_runtime_env" | awk '{print $1}')" = "$source_env_sha256" ] \
        && [ "$(sha256sum "$blue_runtime_env" | awk '{print $1}')" = "$source_env_sha256" ] \
        || fail 'crash-preserved runtime environment artifact bytes differ from source'
    [ "$(file_mode "$green_runtime_env")" = 400 ] \
        && [ "$(file_mode "$blue_runtime_env")" = 400 ] \
        || fail 'runtime environment artifacts are not mode 0400'

    chmod 600 "$green_runtime_env"
    printf '%s\n' 'unverified-crash-artifact=true' > "$green_runtime_env"
    operator preflight >/dev/null
    grep -F -x -q "green_runtime_env_sha256=$source_env_sha256" "$operation_directory/state"
    grep -F -x -q "blue_runtime_env_sha256=$source_env_sha256" "$operation_directory/state"
    green_probe_token_sha256=$(sha256sum "$CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE" | awk '{print $1}')
    grep -F -x -q "green_probe_token_path=$CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE" \
        "$operation_directory/state"
    grep -F -x -q "green_probe_token_sha256=$green_probe_token_sha256" \
        "$operation_directory/state"
    grep -F -x -q \
        "backup_attestation_sha256=$CONTROL_PLANE_BACKUP_ATTESTATION_SHA256" \
        "$operation_directory/state"
    grep -Eq '^backup_attestation_configuration_sha256=[0-9a-f]{64}$' \
        "$operation_directory/state"
    grep -Eq '^backup_attestation_last_verified_unix=[1-9][0-9]+$' \
        "$operation_directory/state"
    backup_attestation_backup="$scenario_directory/backup-attestation.original"
    cp -p "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE" "$backup_attestation_backup"
    chmod 600 "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE"
    printf '%s\n' 'replacement-attestation=true' >> "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE"
    chmod 400 "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE"
    original_backup_attestation_sha256=$CONTROL_PLANE_BACKUP_ATTESTATION_SHA256
    CONTROL_PLANE_BACKUP_ATTESTATION_SHA256=$(sha256sum \
        "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE" | awk '{print $1}')
    export CONTROL_PLANE_BACKUP_ATTESTATION_SHA256
    if operator apply-migrations > "$scenario_directory/replaced-backup-attestation.log" 2>&1; then
        fail 'operation accepted a replacement restore attestation after preflight'
    fi
    grep -F -q 'backup/restore attestation pins changed during this operation' \
        "$scenario_directory/replaced-backup-attestation.log" \
        || fail 'replacement restore attestation did not reach the durable pin gate'
    cp -p "$backup_attestation_backup" "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE"
    CONTROL_PLANE_BACKUP_ATTESTATION_SHA256=$original_backup_attestation_sha256
    export CONTROL_PLANE_BACKUP_ATTESTATION_SHA256
    if CONTROL_PLANE_TEST_BACKUP_VERIFIER_OUTPUT=missing-newline \
        "$OPERATOR" apply-migrations > "$scenario_directory/backup-missing-newline.log" 2>&1; then
        fail 'operation accepted backup verifier success without its exact newline'
    fi
    grep -F -q 'verifier returned inexact success output' \
        "$scenario_directory/backup-missing-newline.log" \
        || fail 'missing verifier newline did not reach the exact-output gate'
    [ "$(sha256sum "$green_runtime_env" | awk '{print $1}')" = "$source_env_sha256" ] \
        && [ "$(sha256sum "$blue_runtime_env" | awk '{print $1}')" = "$source_env_sha256" ] \
        || fail 'preflight retry reused an unverified runtime environment artifact'
    [ "$(file_mode "$green_runtime_env")" = 400 ] \
        && [ "$(file_mode "$blue_runtime_env")" = 400 ] \
        || fail 'reconciled runtime environment artifacts are not mode 0400'
    docker exec --user 0 "$CONTROL_PLANE_BLUE_CONTAINER" \
        apk info -e su-exec=0.3-r0 >/dev/null \
        || fail 'mock control-plane image lacks the exact su-exec package'
    docker exec --user 0 "$CONTROL_PLANE_BLUE_CONTAINER" /bin/sh -ec '
        [ -x /sbin/su-exec ] && [ ! -L /sbin/su-exec ] \
            && [ "$(stat -c %u:%g:%a /sbin/su-exec)" = 0:0:755 ] \
            && [ -x /bin/busybox ] && [ ! -L /bin/busybox ] \
            && [ "$(stat -c %u:%g:%a /bin/busybox)" = 0:0:755 ]
    ' || fail 'mock mutation identity dependencies are not trusted root executables'
    [ "$(docker exec --user 0 "$CONTROL_PLANE_BLUE_CONTAINER" \
        /sbin/su-exec 9999:9999 /bin/sh -ec \
        'printf "%s:%s:%s\n" "$(id -u)" "$(id -g)" "$(id -G)"')" \
        = 9999:9999:9999 ] \
        || fail 'mock su-exec did not reproduce the exact non-root default identity'
    [ "$(docker inspect --format '{{.Config.User}}' "$CONTROL_PLANE_DATABASE_CONTAINER")" \
        = '' ] && [ "$(docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        /bin/sh -ec 'printf "%s:%s\n" "$(id -u)" "$(id -g)"')" = 0:0 ] \
        || fail 'mock database default execution identity is not root'
    docker exec --user 0 "$CONTROL_PLANE_DATABASE_CONTAINER" /bin/sh -ec '
        [ -x /bin/busybox ] && [ ! -L /bin/busybox ] \
            && [ "$(stat -c %u:%g:%a /bin/busybox)" = 0:0:755 ]
    ' || fail 'mock database BusyBox executable is not trusted'
    busybox_session_started=$(date -u +%s)
    busybox_session_output=$(docker exec --user 0 "$CONTROL_PLANE_BLUE_CONTAINER" \
        /bin/sh -ec '
            mutation_status=0
            /bin/busybox setsid /bin/sh -ec "$1" || mutation_status=$?
            printf "status=%s\n" "$mutation_status"
            exit "$mutation_status"
        ' sh '
            process_stat=$(sed "s/^.*) //" /proc/$$/stat)
            set -- $process_stat
            printf "session=%s:%s:%s\n" "$$" "$3" "$4"
            sleep 3
            printf "%s\n" completed
        ')
    [ $(( $(date -u +%s) - busybox_session_started )) -ge 3 ] \
        || fail 'attached BusyBox setsid wrapper returned before its child completed'
    if ! printf '%s\n' "$busybox_session_output" | grep -F -x -q completed \
        || ! printf '%s\n' "$busybox_session_output" | grep -F -x -q status=0; then
        fail 'attached BusyBox setsid wrapper lost completion or status'
    fi
    busybox_session_identity=$(printf '%s\n' "$busybox_session_output" \
        | sed -n 's/^session=//p')
    saved_ifs=$IFS
    IFS=:
    # shellcheck disable=SC2086 # Colon-delimited identity requires intentional splitting.
    set -- $busybox_session_identity
    IFS=$saved_ifs
    [ "$#" -eq 3 ] && [ "$1" -gt 1 ] && [ "$1" = "$2" ] && [ "$1" = "$3" ] \
        || fail 'attached BusyBox child was not the private session leader'
    docker exec --user 0 "$CONTROL_PLANE_BLUE_CONTAINER" test ! -e "/proc/$1" \
        || fail 'attached BusyBox wrapper left a zombie or live session leader'
    candidate_env_sha256=$(docker exec "$CONTROL_PLANE_GREEN_CONTAINER" \
        /bin/sh -ec 'test "$(id -u):$(id -g)" = 9999:9999; test -r /var/www/html/.env; sha256sum /var/www/html/.env' \
        | awk '{print $1}')
    [ "$candidate_env_sha256" = "$source_env_sha256" ] \
        || fail 'candidate UID did not read the exact runtime environment artifact'
    if [ "$(uname -s)" = Linux ]; then
        docker run --rm --network none --user 65534:65534 \
            --mount "type=bind,source=${green_runtime_env},target=/runtime.env,readonly" \
            --entrypoint /bin/sh "$MOCK_IMAGE" -ec 'test ! -r /runtime.env' \
            || fail 'an unauthorized host UID could read the runtime environment artifact'
    fi
    green_probe_token_backup="$scenario_directory/green-direct-probe.token.backup"
    cp -p "$CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE" "$green_probe_token_backup"
    chmod 600 "$CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE"
    printf '%s' 'drifted-green-direct-probe-token-0123456789' \
        > "$CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE"
    chmod 400 "$CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE"
    if operator apply-migrations > "$scenario_directory/drifted-probe-token.log" 2>&1; then
        fail 'operation accepted a direct-probe token that differed from durable state'
    fi
    grep -F -q 'green direct-probe token identity or metadata changed' \
        "$scenario_directory/drifted-probe-token.log" \
        || fail 'direct-probe token drift did not reach the durable identity gate'
    cp -p "$green_probe_token_backup" "$CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE"
    operator_without_backup_attestation_inputs rollback >/dev/null
}

scenario_drain_waits_for_scheduler_child_and_active_work()
{
    preflight_and_apply_migrations
    operator cutover >/dev/null
    backup_attestation_before_drain="$scenario_directory/backup-attestation.before-drain"
    cp -p "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE" "$backup_attestation_before_drain"
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service hold-schedule
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service set-reserved 1
    docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --command \
            "INSERT INTO application_deployment_queues (status, horizon_job_worker) VALUES ('in_progress', 'long-running-worker')" \
        >/dev/null
    (
        sleep 2
        chmod 600 "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE"
        printf '%s\n' 'drifted-during-drain=true' >> "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE"
        chmod 400 "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE"
        docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
            /usr/local/bin/control-plane-lab-service release-schedule
        sleep 1
        docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
            /usr/local/bin/control-plane-lab-service set-reserved 0
        docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
            psql --username postgres --dbname postgres --command \
                "DELETE FROM application_deployment_queues WHERE horizon_job_worker = 'long-running-worker'" \
            >/dev/null
    ) &
    release_pid=$!
    register_worker "$release_pid"
    if operator promote > "$scenario_directory/drifted-during-drain.log" 2>&1; then
        fail 'green promotion accepted backup evidence that drifted during the blue drain'
    fi
    wait_registered_worker "$release_pid"
    grep -F -q 'attestation differs from its out-of-band pinned digest' \
        "$scenario_directory/drifted-during-drain.log" \
        || fail 'post-drain backup drift did not reach the irreversible-boundary gate'
    docker inspect "$CONTROL_PLANE_BLUE_CONTAINER" >/dev/null \
        || fail 'backup drift crossed the irreversible legacy-blue revoke boundary'
    assert_marker_absent "$CONTROL_PLANE_GREEN_STATE_VOLUME"
    cp -p "$backup_attestation_before_drain" "$CONTROL_PLANE_BACKUP_ATTESTATION_FILE"
    operator promote >/dev/null
    assert_marker_equals "$CONTROL_PLANE_GREEN_STATE_VOLUME" "$CONTROL_PLANE_WRITER_EPOCH"
    operator rollback >/dev/null
}

scenario_restart_persistence_and_failback()
{
    preflight_and_apply_migrations
    operator cutover >/dev/null
    operator promote >/dev/null
    assert_marker_equals "$CONTROL_PLANE_GREEN_STATE_VOLUME" "$CONTROL_PLANE_WRITER_EPOCH"
    docker restart "$CONTROL_PLANE_GREEN_CONTAINER" >/dev/null
    assert_marker_equals "$CONTROL_PLANE_GREEN_STATE_VOLUME" "$CONTROL_PLANE_WRITER_EPOCH"
    operator rollback >/dev/null
    assert_marker_absent "$CONTROL_PLANE_GREEN_STATE_VOLUME"
    operator promote >/dev/null
    assert_marker_equals "$CONTROL_PLANE_BLUE_STATE_VOLUME" "$CONTROL_PLANE_BLUE_WRITER_EPOCH"
}

scenario_routed_candidate_process_restart_recovers_service()
{
    preflight_and_apply_migrations
    cutover_log="$scenario_directory/cutover.log"
    if ! operator cutover > "$cutover_log" 2>&1; then
        sed -n '1,240p' "$cutover_log" >&2
        fail 'control-plane cutover failed'
    fi
    operation_directory="$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID"
    candidate_id_before=$(docker inspect --format '{{.Id}}' "$CONTROL_PLANE_GREEN_CONTAINER")
    candidate_image_id_before=$(docker inspect --format '{{.Image}}' \
        "$CONTROL_PLANE_GREEN_CONTAINER")
    candidate_started_at_before=$(docker inspect --format '{{.State.StartedAt}}' \
        "$CONTROL_PLANE_GREEN_CONTAINER")
    restart_count_before=$(docker inspect --format '{{.RestartCount}}' "$CONTROL_PLANE_GREEN_CONTAINER")
    green_start_log_sha256=$(sha256sum "$operation_directory/green-start.log" | awk '{print $1}')
    candidate_host_pid=$(docker inspect --format '{{.State.Pid}}' \
        "$CONTROL_PLANE_GREEN_CONTAINER")
    case "$candidate_host_pid" in
        ''|0|*[!0-9]*) fail 'routed candidate did not expose a valid host PID' ;;
    esac
    # Docker API stop/kill is operator intent and suppresses restart-policy recovery.
    docker run --rm --network none --pid host --user 0 --entrypoint /bin/kill \
        "$CONTROL_PLANE_GREEN_IMAGE" -KILL "$candidate_host_pid" >/dev/null
    restart_wait_attempt=0
    while :; do
        candidate_running=$(docker inspect --format '{{.State.Running}}' \
            "$CONTROL_PLANE_GREEN_CONTAINER")
        candidate_health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}' \
            "$CONTROL_PLANE_GREEN_CONTAINER")
        restart_count_after=$(docker inspect --format '{{.RestartCount}}' \
            "$CONTROL_PLANE_GREEN_CONTAINER")
        if [ "$candidate_running" = true ] && [ "$candidate_health" = healthy ] \
            && [ "$restart_count_after" -gt "$restart_count_before" ]; then
            break
        fi
        restart_wait_attempt=$((restart_wait_attempt + 1))
        [ "$restart_wait_attempt" -lt 120 ] \
            || fail 'routed web-only candidate did not recover after a process crash'
        sleep 0.25
    done
    candidate_id_after=$(docker inspect --format '{{.Id}}' "$CONTROL_PLANE_GREEN_CONTAINER")
    candidate_image_id_after=$(docker inspect --format '{{.Image}}' \
        "$CONTROL_PLANE_GREEN_CONTAINER")
    candidate_network_ids_after=$(docker inspect --format \
        '{{range .NetworkSettings.Networks}}{{.NetworkID}}{{"\n"}}{{end}}' \
        "$CONTROL_PLANE_GREEN_CONTAINER" | sed '/^$/d' | LC_ALL=C sort -u | paste -sd, -)
    candidate_started_at_after=$(docker inspect --format '{{.State.StartedAt}}' \
        "$CONTROL_PLANE_GREEN_CONTAINER")
    [ "$candidate_id_after" = "$candidate_id_before" ] \
        || fail 'routed candidate restart replaced the exact container ID'
    [ "$candidate_image_id_after" = "$candidate_image_id_before" ] \
        || fail 'routed candidate restart changed the exact image ID'
    [ "$(docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' "$CONTROL_PLANE_GREEN_CONTAINER")" \
        = always ] || fail 'routed candidate restart policy is not always'
    [ "$candidate_started_at_after" != "$candidate_started_at_before" ] \
        && [ "$restart_count_after" -gt "$restart_count_before" ] \
        || fail 'routed candidate restart did not produce fresh StartedAt and RestartCount evidence'
    captured_status=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
        --header "Host: $CONTROL_PLANE_HOST" \
        "https://127.0.0.1:${LAB_TRAEFIK_PORT}/api/health")
    [ "$captured_status" = 200 ] \
        || fail 'HTTPS health did not recover after the routed candidate process restart'
    captured_status=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
        --header "Host: $CONTROL_PLANE_HOST" \
        "http://127.0.0.1:8000/api/health")
    [ "$captured_status" = 200 ] \
        || fail ':8000 health did not recover after the routed candidate process restart'
    assert_marker_absent "$CONTROL_PLANE_GREEN_STATE_VOLUME"
    operator promote > "$scenario_directory/restarted-candidate-promote.log" 2>&1
    [ "$(sha256sum "$operation_directory/green-start.log" | awk '{print $1}')" \
        = "$green_start_log_sha256" ] \
        || fail 'routed candidate recovery used Compose instead of the exact recorded runtime'
    [ ! -e "$operation_directory/green-routed-recovery-start.log" ] \
        && [ ! -L "$operation_directory/green-routed-recovery-start.log" ] \
        || fail 'already-restarted routed candidate was restarted instead of reconciled in place'
    grep -F -x -q "green_id=$candidate_id_before" "$operation_directory/state" \
        || fail 'routed candidate recovery did not retain the recorded exact ID'
    if ! grep -F -x -q 'routed_recovery_color=green' "$operation_directory/state" \
        || ! grep -F -x -q 'routed_recovery_status=verified' "$operation_directory/state"; then
        fail 'routed candidate recovery did not durably finalize its green runtime generation'
    fi
    fixture_log="${CONTROL_PLANE_RUNTIME_FENCE_LAB_STATE}.log"
    recovery_evidence="${CONTROL_PLANE_RUNTIME_FENCE_LAB_STATE}.daemon-recovery"
    candidate_runtime_sha256_after=$("$CONTROL_PLANE_RUNTIME_FENCE_PROVISIONER" \
        container-runtime-sha256 "$CONTROL_PLANE_GREEN_CONTAINER")
    if ! [ -f "$recovery_evidence" ] || [ -L "$recovery_evidence" ] \
        || ! grep -F -x -q 'recovery_kind=candidate' "$recovery_evidence" \
        || ! grep -F -x -q "candidate_id=$candidate_id_after" "$recovery_evidence" \
        || ! grep -F -x -q "candidate_image_id=$candidate_image_id_after" "$recovery_evidence" \
        || ! grep -F -x -q "candidate_network_ids=$candidate_network_ids_after" "$recovery_evidence" \
        || ! grep -F -x -q "candidate_runtime_sha256=$candidate_runtime_sha256_after" \
            "$recovery_evidence" \
        || ! grep -F -x -q "candidate_started_at=$candidate_started_at_after" \
            "$recovery_evidence" \
        || ! grep -F -x -q "candidate_restart_count=$restart_count_after" "$recovery_evidence" \
        || ! grep -F -x -q 'candidate_restart_policy=always' "$recovery_evidence"; then
        fail 'routed candidate recovery evidence did not preserve the exact candidate runtime'
    fi
    if ! grep -F -x -q recover-routed-runtime "$fixture_log" \
        || ! grep -F -x -q repin-candidate "$fixture_log" \
        || ! grep -F -x -q finalize-routed-runtime-recovery "$fixture_log"; then
        fail 'routed candidate recovery did not reconcile, repin, and finalize the fence'
    fi
    assert_route_color "https://127.0.0.1:${LAB_TRAEFIK_PORT}/cgi-bin/request" green
    assert_route_color "http://127.0.0.1:8000/cgi-bin/request" green
    assert_marker_equals "$CONTROL_PLANE_GREEN_STATE_VOLUME" "$CONTROL_PLANE_WRITER_EPOCH"
    docker exec "$CONTROL_PLANE_GREEN_CONTAINER" \
        /usr/local/bin/coolify-entrypoint web-activated \
        || fail 'routed candidate web marker did not remain active after recovery'
    [ "$(docker exec "$CONTROL_PLANE_GREEN_CONTAINER" \
        /usr/local/bin/coolify-entrypoint mode)" = active ] \
        || fail 'routed candidate did not remain in active mode after recovery'
    for promoted_service in scheduler-worker horizon nightwatch-agent; do
        [ "$(docker exec "$CONTROL_PLANE_GREEN_CONTAINER" \
            /usr/local/bin/control-plane-lab-service service-state "$promoted_service")" = up ] \
            || fail "routed candidate background service did not promote after recovery: $promoted_service"
    done
}

configure_backup_quiesce_lab()
{
    CONTROL_PLANE_BACKUP_LIVE_CONTAINER=$CONTROL_PLANE_BLUE_CONTAINER
    CONTROL_PLANE_BACKUP_REALTIME_CONTAINER=$CONTROL_PLANE_SOKETI_CONTAINER
    CONTROL_PLANE_DATABASE_ADMIN_USER=postgres
    CONTROL_PLANE_BACKUP_APPLICATION_DATABASE_ROLE=postgres
    CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR="$scenario_directory/backup-quiesce-state"
    CONTROL_PLANE_BACKUP_QUIESCE_DRAIN_TIMEOUT_SECONDS=1
    CONTROL_PLANE_BACKUP_QUIESCE_PROBE_TIMEOUT_SECONDS=1
    CONTROL_PLANE_S6_WAIT_MILLISECONDS=1000
    CONTROL_PLANE_BACKUP_QUIESCE_REALTIME_STOP_SECONDS=1
    CONTROL_PLANE_BACKUP_QUIESCE_MINIMUM_CAPTURE_SECONDS=2
    CONTROL_PLANE_TEST_BACKUP_QUIESCE_WATCHDOG_PID_FILE="$scenario_directory/watchdog.pid"
    CONTROL_PLANE_TEST_BACKUP_QUIESCE_BOOT_ID=lab-boot-primary-0123456789
    CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID=$$
    export CONTROL_PLANE_BACKUP_LIVE_CONTAINER CONTROL_PLANE_BACKUP_REALTIME_CONTAINER
    export CONTROL_PLANE_DATABASE_ADMIN_USER CONTROL_PLANE_BACKUP_APPLICATION_DATABASE_ROLE
    export CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR
    export CONTROL_PLANE_BACKUP_QUIESCE_DRAIN_TIMEOUT_SECONDS
    export CONTROL_PLANE_BACKUP_QUIESCE_PROBE_TIMEOUT_SECONDS
    export CONTROL_PLANE_S6_WAIT_MILLISECONDS
    export CONTROL_PLANE_BACKUP_QUIESCE_REALTIME_STOP_SECONDS
    export CONTROL_PLANE_BACKUP_QUIESCE_MINIMUM_CAPTURE_SECONDS
    export CONTROL_PLANE_TEST_BACKUP_QUIESCE_WATCHDOG_PID_FILE
    export CONTROL_PLANE_TEST_BACKUP_QUIESCE_BOOT_ID
    export CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID
}

capture_control_plane_route()
{
    capture_name=$1
    capture_method=$2
    capture_headers="$scenario_directory/${capture_name}.headers"
    capture_body="$scenario_directory/${capture_name}.body"
    capture_status_file="$scenario_directory/${capture_name}.status"
    capture_curl_status=0
    captured_status=$(curl --silent --show-error --connect-timeout 2 --max-time 5 \
        --request "$capture_method" \
        --header "Host: $CONTROL_PLANE_HOST" \
        --dump-header "$capture_headers" \
        --output "$capture_body" \
        --write-out '%{http_code}' \
        "https://127.0.0.1:${LAB_TRAEFIK_PORT}/cgi-bin/request") \
        || capture_curl_status=$?
    {
        printf 'curl_status=%s\n' "$capture_curl_status"
        printf 'http_status=%s\n' "${captured_status:-000}"
    } > "$capture_status_file"
    [ "$capture_curl_status" -eq 0 ] \
        || fail "control-plane route probe failed: $capture_name"
    captured_color=$(awk -F ': *' '
        tolower($1) == "x-control-plane-color" {
            gsub("\r", "", $2)
            print $2
        }
    ' "$capture_headers")
    captured_body=$(tr -d '\r\n' < "$capture_body")
}

start_availability_monitor()
{
    availability_stop="$scenario_directory/availability.stop"
    availability_log="$scenario_directory/availability.tsv"
    : > "$availability_log"
    (
        availability_iteration=0
        while [ ! -e "$availability_stop" ]; do
            for availability_endpoint in \
                "https|https://127.0.0.1:${LAB_TRAEFIK_PORT}/api/health" \
                "local-ingress|http://127.0.0.1:8000/api/health"
            do
                availability_name=${availability_endpoint%%|*}
                availability_url=${availability_endpoint#*|}
                availability_headers="$scenario_directory/availability-${availability_name}.headers"
                availability_status=000
                availability_curl_status=0
                availability_status=$(curl --silent --show-error --connect-timeout 1 --max-time 2 \
                    --header "Host: $CONTROL_PLANE_HOST" \
                    --dump-header "$availability_headers" --output /dev/null \
                    --write-out '%{http_code}' "$availability_url") \
                    || availability_curl_status=$?
                printf '%s\t%s\t%s\t%s\t%s\n' "$availability_iteration" \
                    "$availability_name" "$availability_curl_status" \
                    "$availability_status" health \
                    >> "$availability_log"
            done
            availability_iteration=$((availability_iteration + 1))
            sleep 0.1
        done
    ) &
    availability_monitor_pid=$!
    register_worker "$availability_monitor_pid"
}

stop_and_assert_availability_monitor()
{
    touch "$availability_stop"
    wait_registered_worker "$availability_monitor_pid" \
        || fail 'continuous availability monitor exited unsuccessfully'
    if ! awk -F '\t' '
        BEGIN { https = 0; local_ingress = 0; invalid = 0 }
        $2 == "https" { https++ }
        $2 == "local-ingress" { local_ingress++ }
        $2 != "https" && $2 != "local-ingress" { invalid = 1 }
        $3 != "0" || $4 != "200" { invalid = 1 }
        $5 != "health" { invalid = 1 }
        END { exit invalid || https < 10 || local_ingress < 10 }
    ' "$availability_log"; then
        awk -F '\t' '
            BEGIN { https = 0; local_ingress = 0; invalid = 0 }
            $2 == "https" { https++ }
            $2 == "local-ingress" { local_ingress++ }
            $2 != "https" && $2 != "local-ingress" { invalid++ }
            $3 != "0" || $4 != "200" || $5 != "health" { invalid++ }
            END {
                printf "availability_summary https=%d local_ingress=%d invalid=%d\n", \
                    https, local_ingress, invalid > "/dev/stderr"
            }
        ' "$availability_log"
        awk -F '\t' \
            '$2 != "https" && $2 != "local-ingress" \
                || $3 != "0" || $4 != "200" || $5 != "health" { print }' \
            "$availability_log" | sed -n '1,40p' >&2
        fail 'continuous health traffic observed a transport error, non-2xx response, or insufficient samples'
    fi
}

scenario_continuous_forward_reverse_availability()
{
    preflight_and_apply_migrations
    start_availability_monitor
    availability_https_crash_log="$scenario_directory/availability-https-crash.log"
    if CONTROL_PLANE_TEST_CRASH_AT=after-green-ingress-route \
        "$OPERATOR" cutover > "$availability_https_crash_log" 2>&1; then
        fail 'availability HTTPS-route crash injection unexpectedly completed'
    fi
    grep -F -x -q 'phase=green-routed' \
        "$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID/state" \
        || fail 'availability HTTPS-route crash injection did not persist the exact routed phase'
    operator cutover >/dev/null
    if CONTROL_PLANE_TEST_CRASH_AT=after-green-final-ingress-ack \
        "$OPERATOR" promote >/dev/null 2>&1; then
        fail 'availability final-ingress crash injection unexpectedly completed'
    fi
    if CONTROL_PLANE_TEST_CRASH_AT=after-green-marker \
        "$OPERATOR" promote >/dev/null 2>&1; then
        fail 'availability green-marker crash injection unexpectedly completed'
    fi
    operator recover-forward >/dev/null
    availability_reverse_https_crash_log="$scenario_directory/availability-reverse-https-crash.log"
    if CONTROL_PLANE_TEST_CRASH_AT=after-failback-blue-ingress-route \
        "$OPERATOR" rollback > "$availability_reverse_https_crash_log" 2>&1; then
        fail 'availability reverse HTTPS-route crash injection unexpectedly completed'
    fi
    if ! grep -F -x -q 'phase=blue-failback-routed' \
        "$CONTROL_PLANE_OPERATOR_STATE_DIR/$CONTROL_PLANE_OPERATION_ID/state"; then
        sed -n '1,240p' "$availability_reverse_https_crash_log" >&2
        fail 'availability reverse HTTPS-route crash injection did not persist the exact routed phase'
    fi
    operator rollback >/dev/null
    stop_and_assert_availability_monitor
    assert_marker_absent "$CONTROL_PLANE_GREEN_STATE_VOLUME"
    assert_marker_equals "$CONTROL_PLANE_BLUE_STATE_VOLUME" "$CONTROL_PLANE_BLUE_WRITER_EPOCH"
}

start_foreign_control_plane_router()
{
    foreign_router_container="${project_name}-foreign-router"
    foreign_project="${project_name}-foreign"
    foreign_router_name="${CONTROL_PLANE_TRAEFIK_ROUTER}-foreign"
    foreign_service_name="${foreign_router_name}-service"
    docker run --detach --name "$foreign_router_container" \
        --network "$CONTROL_PLANE_NETWORK" \
        --label "com.docker.compose.project=$foreign_project" \
        --label 'traefik.enable=true' \
        --label "traefik.http.routers.${foreign_router_name}.rule=Host(\`${CONTROL_PLANE_HOST}\`)" \
        --label "traefik.http.routers.${foreign_router_name}.entrypoints=web" \
        --label "traefik.http.routers.${foreign_router_name}.priority=99999" \
        --label "traefik.http.routers.${foreign_router_name}.service=${foreign_service_name}" \
        --label "traefik.http.services.${foreign_service_name}.loadbalancer.server.port=8080" \
        --env CONTROL_PLANE_COLOR=foreign \
        --entrypoint /bin/sh "$MOCK_IMAGE" -ec \
        'exec httpd -f -p 8080 -h /srv/www' >/dev/null
    [ "$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' \
        "$foreign_router_container")" = "$foreign_project" ] \
        || fail 'foreign-router regression did not bind a distinct Compose project label'
    foreign_ready_attempt=0
    while [ "$foreign_ready_attempt" -lt 30 ]; do
        if [ "$(docker run --rm --network "$CONTROL_PLANE_NETWORK" "$MOCK_IMAGE" \
            curl --silent --show-error --max-time 2 \
                "http://${foreign_router_container}:8080/cgi-bin/request" 2>/dev/null \
            | tr -d '\r\n')" = foreign ]; then
            break
        fi
        foreign_ready_attempt=$((foreign_ready_attempt + 1))
        sleep 0.1
    done
    [ "$foreign_ready_attempt" -lt 30 ] \
        || fail 'foreign-router regression backend did not become directly reachable'
}

assert_foreign_control_plane_router_filtered()
{
    [ -n "${foreign_router_container:-}" ] \
        || fail 'foreign-router regression container was not started before Traefik discovery'
    capture_control_plane_route foreign-router-filtered-before-quiesce POST
    [ "$captured_status" = 200 ] \
        && [ "$captured_color" = legacy ] \
        && [ "$captured_body" = legacy ] \
        || fail 'lab Traefik admitted the higher-priority foreign Compose project router'
}

start_installer_test_container()
{
    installer_test_container="${project_name}-backup-quiesce-installer"
    mkdir -p "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR"
    docker run --detach --name "$installer_test_container" --network none --user 0 \
        --volume /var/run/docker.sock:/var/run/docker.sock \
        --volume "$REPOSITORY_ROOT/docker/control-plane-blue-green:/reviewed:ro" \
        --volume "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR:/var/lib/coolify/control-plane-backup-quiesce" \
        --volume "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR:/bundle-host/var/lib/coolify/control-plane-backup-quiesce" \
        --entrypoint sleep "$MOCK_IMAGE" infinity >/dev/null
    docker exec --user 0 "$installer_test_container" \
        install -d -o root -g root -m 0755 /bundle-host
}

installer_container_operator()
{
    installer_operator_sha256=$(sha256sum \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/control-plane-blue-green.sh" \
        | awk '{print $1}')
    installer_controller_sha256=$(sha256sum \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce.sh" \
        | awk '{print $1}')
    installer_service_sha256=$(sha256sum \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce-watchdog.service" \
        | awk '{print $1}')
    installer_timer_sha256=$(sha256sum \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce-watchdog.timer" \
        | awk '{print $1}')
    docker exec --user 0 \
        --env CONTROL_PLANE_TEST_MODE=1 \
        --env "CONTROL_PLANE_BLUE_CONTAINER=$CONTROL_PLANE_BLUE_CONTAINER" \
        --env "CONTROL_PLANE_BACKUP_LIVE_CONTAINER=$CONTROL_PLANE_BLUE_CONTAINER" \
        --env "CONTROL_PLANE_DATABASE_CONTAINER=$CONTROL_PLANE_DATABASE_CONTAINER" \
        --env CONTROL_PLANE_DATABASE_NAME=postgres \
        --env CONTROL_PLANE_DATABASE_USER=postgres \
        --env CONTROL_PLANE_DATABASE_ADMIN_USER=postgres \
        --env CONTROL_PLANE_BACKUP_APPLICATION_DATABASE_ROLE=postgres \
        --env "CONTROL_PLANE_BACKUP_REALTIME_CONTAINER=$CONTROL_PLANE_SOKETI_CONTAINER" \
        --env CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR=/bundle-host/var/lib/coolify/control-plane-backup-quiesce \
        --env CONTROL_PLANE_BACKUP_QUIESCE_DRAIN_TIMEOUT_SECONDS=1 \
        --env CONTROL_PLANE_BACKUP_QUIESCE_PROBE_TIMEOUT_SECONDS=1 \
        --env CONTROL_PLANE_S6_WAIT_MILLISECONDS=1000 \
        --env CONTROL_PLANE_BACKUP_QUIESCE_REALTIME_STOP_SECONDS=1 \
        --env CONTROL_PLANE_BACKUP_QUIESCE_MINIMUM_CAPTURE_SECONDS=2 \
        --env CONTROL_PLANE_TEST_BACKUP_QUIESCE_BOOT_ID=lab-installer-boot-0123456789 \
        --env CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID=1 \
        --env CONTROL_PLANE_BACKUP_QUIESCE_OPERATOR_PATH=/reviewed/control-plane-blue-green.sh \
        --env "CONTROL_PLANE_BACKUP_QUIESCE_OPERATOR_SHA256=$installer_operator_sha256" \
        --env CONTROL_PLANE_BACKUP_QUIESCE_CONTROLLER_PATH=/reviewed/backup-quiesce/control-plane-backup-quiesce.sh \
        --env "CONTROL_PLANE_BACKUP_QUIESCE_CONTROLLER_SHA256=$installer_controller_sha256" \
        --env CONTROL_PLANE_BACKUP_QUIESCE_SERVICE_UNIT_PATH=/reviewed/backup-quiesce/control-plane-backup-quiesce-watchdog.service \
        --env "CONTROL_PLANE_BACKUP_QUIESCE_SERVICE_UNIT_SHA256=$installer_service_sha256" \
        --env CONTROL_PLANE_BACKUP_QUIESCE_TIMER_UNIT_PATH=/reviewed/backup-quiesce/control-plane-backup-quiesce-watchdog.timer \
        --env "CONTROL_PLANE_BACKUP_QUIESCE_TIMER_UNIT_SHA256=$installer_timer_sha256" \
        --env CONTROL_PLANE_TEST_BACKUP_QUIESCE_WATCHDOG_PID_FILE=/bundle-host/var/lib/coolify/control-plane-backup-quiesce/watchdog.pid \
        --env "CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_AFTER_WATCHDOG_SECONDS=${installer_operator_hold_after_watchdog_seconds:-0}" \
        "$installer_test_container" \
        /reviewed/backup-quiesce/control-plane-backup-quiesce.sh "$@"
}

installer_installed_controller()
{
    installed_release_id=$1
    shift
    installed_release_directory="/bundle-host/usr/local/lib/coolify-control-plane/releases/$installed_release_id"
    installed_controller_path="$installed_release_directory/backup-quiesce/control-plane-backup-quiesce.sh"
    installed_operator_sha256=$(docker exec --user 0 "$installer_test_container" \
        sha256sum "$installed_release_directory/control-plane-blue-green.sh" | awk '{print $1}')
    installed_controller_sha256=$(docker exec --user 0 "$installer_test_container" \
        sha256sum "$installed_controller_path" | awk '{print $1}')
    installed_service_sha256=$(docker exec --user 0 "$installer_test_container" \
        sha256sum /bundle-host/etc/systemd/system/control-plane-backup-quiesce-watchdog.service \
        | awk '{print $1}')
    installed_timer_sha256=$(docker exec --user 0 "$installer_test_container" \
        sha256sum /bundle-host/etc/systemd/system/control-plane-backup-quiesce-watchdog.timer \
        | awk '{print $1}')
    docker exec --user 0 \
        --env CONTROL_PLANE_RELEASE_DISPATCH_TEST_MODE=1 \
        --env CONTROL_PLANE_RELEASE_DISPATCH_TEST_ROOT=/bundle-host \
        --env CONTROL_PLANE_RELEASE_MANIFEST_FILE=/bundle-host/etc/coolify-control-plane/release.manifest \
        --env CONTROL_PLANE_RELEASES_ROOT=/bundle-host/usr/local/lib/coolify-control-plane/releases \
        --env CONTROL_PLANE_RELEASE_DISPATCH_IMMUTABLE_UID=0 \
        --env CONTROL_PLANE_RELEASE_DISPATCH_IMMUTABLE_GID=0 \
        --env CONTROL_PLANE_TEST_MODE=1 \
        --env "CONTROL_PLANE_BACKUP_LIVE_CONTAINER=$CONTROL_PLANE_BLUE_CONTAINER" \
        --env "CONTROL_PLANE_DATABASE_CONTAINER=$CONTROL_PLANE_DATABASE_CONTAINER" \
        --env CONTROL_PLANE_DATABASE_NAME=postgres \
        --env CONTROL_PLANE_DATABASE_ADMIN_USER=postgres \
        --env CONTROL_PLANE_BACKUP_APPLICATION_DATABASE_ROLE=postgres \
        --env "CONTROL_PLANE_BACKUP_REALTIME_CONTAINER=$CONTROL_PLANE_SOKETI_CONTAINER" \
        --env CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR=/bundle-host/var/lib/coolify/control-plane-backup-quiesce \
        --env CONTROL_PLANE_BACKUP_QUIESCE_DRAIN_TIMEOUT_SECONDS=1 \
        --env CONTROL_PLANE_BACKUP_QUIESCE_PROBE_TIMEOUT_SECONDS=1 \
        --env CONTROL_PLANE_S6_WAIT_MILLISECONDS=1000 \
        --env CONTROL_PLANE_BACKUP_QUIESCE_REALTIME_STOP_SECONDS=1 \
        --env CONTROL_PLANE_BACKUP_QUIESCE_MINIMUM_CAPTURE_SECONDS=2 \
        --env CONTROL_PLANE_TEST_BACKUP_QUIESCE_BOOT_ID=lab-installer-boot-0123456789 \
        --env CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID=1 \
        --env "CONTROL_PLANE_BACKUP_QUIESCE_OPERATOR_PATH=$installed_release_directory/control-plane-blue-green.sh" \
        --env "CONTROL_PLANE_BACKUP_QUIESCE_OPERATOR_SHA256=$installed_operator_sha256" \
        --env "CONTROL_PLANE_BACKUP_QUIESCE_CONTROLLER_PATH=$installed_controller_path" \
        --env "CONTROL_PLANE_BACKUP_QUIESCE_CONTROLLER_SHA256=$installed_controller_sha256" \
        --env CONTROL_PLANE_BACKUP_QUIESCE_SERVICE_UNIT_PATH=/bundle-host/etc/systemd/system/control-plane-backup-quiesce-watchdog.service \
        --env "CONTROL_PLANE_BACKUP_QUIESCE_SERVICE_UNIT_SHA256=$installed_service_sha256" \
        --env CONTROL_PLANE_BACKUP_QUIESCE_TIMER_UNIT_PATH=/bundle-host/etc/systemd/system/control-plane-backup-quiesce-watchdog.timer \
        --env "CONTROL_PLANE_BACKUP_QUIESCE_TIMER_UNIT_SHA256=$installed_timer_sha256" \
        "$installer_test_container" \
        /bundle-host/usr/local/libexec/coolify-control-plane-release-dispatch \
        backup-quiesce-controller "$@"
}

run_installer_release_bundle()
{
    installer_release_id=$1
    shift
    docker exec --user 0 \
        --env CONTROL_PLANE_RELEASE_BUNDLE_TEST_MODE=1 \
        --env CONTROL_PLANE_RELEASE_BUNDLE_TEST_ROOT=/bundle-host \
        --env CONTROL_PLANE_RELEASE_BUNDLE_SOURCE_ROOT=/reviewed \
        "$@" "$installer_test_container" \
        /reviewed/backup-quiesce/install-host-prerequisites.sh "$installer_release_id"
}

wait_for_file()
{
    expected_file=$1
    file_attempt=0
    while [ ! -e "$expected_file" ]; do
        file_attempt=$((file_attempt + 1))
        [ "$file_attempt" -lt 100 ] || fail "timed out waiting for test file: $expected_file"
        sleep 0.1
    done
}

wait_for_quiesce_acquired_marker()
{
    operation_name=$1
    acquired_marker=$2
    state_path="$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$operation_name/state"
    wait_for_file "$state_path"
    acquisition_started_unix=$(sed -n 's/^lease_acquired_unix=//p' "$state_path")
    acquisition_bound_seconds=$(sed -n \
        's/^lease_acquisition_bound_seconds=//p' "$state_path")
    case "$acquisition_started_unix:$acquisition_bound_seconds" in
        *[!0-9:]*|:*|*:) fail "invalid acquisition budget in test state: $state_path" ;;
    esac
    acquisition_deadline=$((acquisition_started_unix + acquisition_bound_seconds))
    while [ ! -e "$acquired_marker" ]; do
        [ "$(date -u +%s)" -lt "$acquisition_deadline" ] \
            || fail "timed out waiting for quiesce acquisition: $operation_name"
        sleep 0.1
    done
}

wait_for_quiesce_reconciliation()
{
    operation_name=$1
    state_path="$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$operation_name/state"
    reconciliation_started=$(date -u +%s)
    while :; do
        observed_phase=$(sed -n 's/^phase=//p' "$state_path")
        case "$observed_phase" in
            releasing|finalizing|released) return ;;
            acquired) ;;
            *) fail "invalid phase while waiting for quiesce reconciliation: $operation_name" ;;
        esac
        [ $(( $(date -u +%s) - reconciliation_started )) -lt 10 ] \
            || fail "backup quiesce did not begin ownerless reconciliation promptly: $operation_name"
        sleep 0.1
    done
}

assert_quiesce_mutation_residue_absent()
{
    operation_name=$1
    for mutation_container in \
        "$CONTROL_PLANE_BLUE_CONTAINER" "$CONTROL_PLANE_DATABASE_CONTAINER"; do
        docker exec --user 0 "$mutation_container" /bin/sh -ec '
            for mutation_marker in "/root/control-plane-backup-quiesce/$1-"*; do
                [ ! -e "$mutation_marker" ] && [ ! -L "$mutation_marker" ] || exit 1
            done
        ' sh "$operation_name" \
            || fail "quiesce reconciliation left remote mutation residue: $operation_name"
    done
    for mutation_input_path in \
        "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$operation_name"/.mutation-input.*; do
        [ ! -e "$mutation_input_path" ] \
            || fail "quiesce reconciliation left a staged mutation input: $operation_name"
    done
}

scenario_backup_quiesce_installer_lock()
{
    start_installer_test_container
    if docker exec --user 0 \
        --env CONTROL_PLANE_RELEASE_BUNDLE_TEST_MODE=1 \
        --env CONTROL_PLANE_RELEASE_BUNDLE_TEST_ROOT=/bundle-host \
        --env CONTROL_PLANE_RELEASE_BUNDLE_SOURCE_ROOT=/reviewed \
        "$installer_test_container" \
        /reviewed/backup-quiesce/install-host-prerequisites.sh \
        > "$scenario_directory/installer-no-release.log" 2>&1; then
        fail 'retired component-only backup installer succeeded without a release ID'
    fi
    grep -F -q 'component-only installation is retired; provide RELEASE_ID' \
        "$scenario_directory/installer-no-release.log" \
        || fail 'backup component installer did not require the complete bundle interface'

    installer_first_release=backup-bundle-release-first-000001
    install_marker="$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/installer-bundle-locked"
    run_installer_release_bundle "$installer_first_release" \
        --env CONTROL_PLANE_RELEASE_BUNDLE_TEST_HOLD_LOCK_SECONDS=3 \
        --env CONTROL_PLANE_RELEASE_BUNDLE_TEST_LOCK_MARKER=/bundle-host/var/lib/coolify/control-plane-backup-quiesce/installer-bundle-locked \
        > "$scenario_directory/installer-first.log" 2>&1 &
    installer_pid=$!
    register_worker "$installer_pid"
    wait_for_file "$install_marker"
    installer_first_operation=backup-quiesce-installer-first-0123456789
    installer_first_token=7777777777777777777777777777777777777777777777777777777777777777
    installer_container_operator acquire --operation-id "$installer_first_operation" \
        --fencing-token "$installer_first_token" --lease-seconds 120 \
        > "$scenario_directory/installer-first-acquire.log" 2>&1 &
    installer_first_acquire_pid=$!
    register_worker "$installer_first_acquire_pid"
    sleep 1
    [ ! -e "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$installer_first_operation" ] \
        || fail 'acquire crossed the installer-held canonical global lock'
    wait_registered_worker "$installer_pid" || fail 'installer-first ordering failed'
    wait_registered_worker "$installer_first_acquire_pid" \
        || fail 'acquire did not proceed after installer released the lock'
    installer_container_operator release --operation-id "$installer_first_operation" \
        --fencing-token "$installer_first_token" >/dev/null
    assert_backup_quiesce_prestate up up up true absent:none unset none
    docker exec --user 0 "$installer_test_container" /bin/sh -ec '
        test -x /bundle-host/usr/local/libexec/coolify-control-plane-release-dispatch
        test -x /bundle-host/usr/local/sbin/control-plane-blue-green
        test -x "/bundle-host/usr/local/lib/coolify-control-plane/releases/$1/backup-quiesce/control-plane-backup-quiesce.sh"
        grep -F -x -q "release|$1" /bundle-host/etc/coolify-control-plane/release.manifest
        grep -F -x -q "ExecStart=/usr/local/sbin/control-plane-blue-green backup-quiesce watchdog-scan --state-directory /var/lib/coolify/control-plane-backup-quiesce" /bundle-host/etc/systemd/system/control-plane-backup-quiesce-watchdog.service
    ' sh "$installer_first_release" \
        || fail 'complete backup release bundle lacks its dispatcher, versioned controller, manifest, or stable unit'

    installer_second_operation=backup-quiesce-installer-second-0123456789
    installer_second_token=8888888888888888888888888888888888888888888888888888888888888888
    installer_installed_controller "$installer_first_release" acquire --operation-id "$installer_second_operation" \
        --fencing-token "$installer_second_token" --lease-seconds 120 \
        > "$scenario_directory/installer-second-acquire.log"
    installer_second_release=backup-bundle-release-second-000002
    if run_installer_release_bundle "$installer_second_release" \
        > "$scenario_directory/installer-during-acquire.log" 2>&1; then
        fail 'bundle installer activated a release while a backup lease was active'
    fi
    grep -F -q \
        'CONTROL_PLANE_RELEASE_BUNDLE_INSTALL_FAILURE an active backup-quiesce lease forbids release activation' \
        "$scenario_directory/installer-during-acquire.log" \
        || fail 'bundle installer did not enforce the active backup lease gate'
    docker exec --user 0 "$installer_test_container" \
        grep -F -x -q "release|$installer_first_release" \
        /bundle-host/etc/coolify-control-plane/release.manifest \
        || fail 'rejected bundle activation changed the selected release'
    installer_installed_controller "$installer_first_release" release --operation-id "$installer_second_operation" \
        --fencing-token "$installer_second_token" >/dev/null

    installer_crash_release=backup-bundle-release-crash-000003
    installer_crash_status=0
    run_installer_release_bundle "$installer_crash_release" \
        --env CONTROL_PLANE_RELEASE_BUNDLE_TEST_CRASH_AT=after-release-manifest-replaced \
        > "$scenario_directory/installer-crash.log" 2>&1 || installer_crash_status=$?
    [ "$installer_crash_status" -eq 137 ] \
        || fail 'bundle post-manifest crash seam did not terminate with status 137'
    docker exec --user 0 "$installer_test_container" \
        grep -F -x -q "release|$installer_crash_release" \
        /bundle-host/etc/coolify-control-plane/release.manifest \
        || fail 'post-publication crash did not leave the new release selected'
    installer_recovery_operation=backup-quiesce-installer-recovery-0123456789
    installer_recovery_token=9999999999999999999999999999999999999999999999999999999999999999
    installer_installed_controller "$installer_crash_release" acquire \
        --operation-id "$installer_recovery_operation" \
        --fencing-token "$installer_recovery_token" --lease-seconds 120 >/dev/null
    installer_installed_controller "$installer_crash_release" release \
        --operation-id "$installer_recovery_operation" \
        --fencing-token "$installer_recovery_token" >/dev/null
    docker exec --user 0 "$installer_test_container" \
        test ! -e /bundle-host/etc/coolify-control-plane/release.activation \
        || fail 'first dispatcher invocation did not recover the interrupted bundle activation'
    [ ! -e "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/active" ] \
        || fail 'installer/acquire contention stranded an active backup fence'
    assert_backup_quiesce_prestate up up up true absent:none unset none
    docker rm --force "$installer_test_container" >/dev/null
    installer_test_container=
}

wait_for_quiesce_release()
{
    operation_name=$1
    state_path="$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$operation_name/state"
    attempt=0
    while [ "$attempt" -lt 60 ]; do
        if [ -f "$state_path" ] \
            && grep -F -x -q 'phase=released' "$state_path" \
            && [ ! -e "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/active" ]; then
            return
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    fail "backup quiesce watchdog did not release operation: $operation_name"
}

assert_backup_quiesce_prestate()
{
    expected_scheduler=$1
    expected_horizon=$2
    expected_nightwatch=$3
    expected_realtime=$4
    expected_maintenance=$5
    expected_role_setting=$6
    expected_maintenance_metadata=$7
    [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service service-state scheduler-worker)" = "$expected_scheduler" ] \
        || fail 'backup quiesce did not restore the exact scheduler prestate'
    [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service service-state horizon)" = "$expected_horizon" ] \
        || fail 'backup quiesce did not restore the exact Horizon prestate'
    [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service service-state nightwatch-agent)" = "$expected_nightwatch" ] \
        || fail 'backup quiesce did not restore the exact Nightwatch prestate'
    [ "$(docker inspect --format '{{.State.Running}}' "$CONTROL_PLANE_SOKETI_CONTAINER")" \
        = "$expected_realtime" ] \
        || fail 'backup quiesce did not restore the exact realtime prestate'
    [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service maintenance-state)" = "$expected_maintenance" ] \
        || fail 'backup quiesce did not restore the exact maintenance prestate'
    if [ "$expected_maintenance_metadata" != none ]; then
        [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
            /usr/local/bin/control-plane-lab-service maintenance-metadata)" \
            = "$expected_maintenance_metadata" ] \
            || fail 'backup quiesce did not restore exact maintenance ownership and mode'
    fi
    actual_role_setting=$(docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --tuples-only --no-align --quiet \
            --command "SELECT COALESCE((SELECT split_part(setting, '=', 2) FROM unnest(rolconfig) AS setting WHERE split_part(setting, '=', 1) = 'default_transaction_read_only'), 'unset') FROM pg_roles WHERE rolname = 'postgres'" \
        | tr -d '[:space:]')
    [ "$actual_role_setting" = "$expected_role_setting" ] \
        || fail 'backup quiesce did not restore the exact database-role prestate'
}

scenario_backup_quiesce_budget_bounds()
{
    undersized_operation=backup-quiesce-undersized-0123456789
    undersized_token=0000000000000000000000000000000000000000000000000000000000000000
    if "$OPERATOR" backup-quiesce acquire --operation-id "$undersized_operation" \
        --fencing-token "$undersized_token" --lease-seconds 31 \
        > "$scenario_directory/undersized-lease.log" 2>&1; then
        fail 'backup quiesce accepted a lease shorter than its configured acquisition and capture budgets'
    fi
    grep -F -x -q \
        'CONTROL_PLANE_BACKUP_QUIESCE_FAILURE backup quiesce lease must be at least 32 seconds (acquisition_bound_seconds=30 minimum_capture_seconds=2)' \
        "$scenario_directory/undersized-lease.log" \
        || fail 'undersized backup quiesce lease did not report its derived configured bound'
    [ ! -e "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$undersized_operation" ] \
        || fail 'undersized backup quiesce lease created durable operation state'

    capture_budget_operation=backup-quiesce-capture-budget-0123456789
    capture_budget_token=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
    capture_budget_stdin="$scenario_directory/capture-budget.stdin"
    mkfifo "$capture_budget_stdin"
    (
        exec 7> "$capture_budget_stdin"
        sleep 120
    ) &
    capture_budget_stdin_holder_pid=$!
    register_worker "$capture_budget_stdin_holder_pid"
    capture_budget_started_unix=$(date -u +%s)
    CONTROL_PLANE_BACKUP_QUIESCE_MINIMUM_CAPTURE_SECONDS=100 \
        CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_AFTER_WATCHDOG_SECONDS=12 \
        "$OPERATOR" backup-quiesce acquire --operation-id "$capture_budget_operation" \
            --fencing-token "$capture_budget_token" --lease-seconds 130 \
            < "$capture_budget_stdin" > "$scenario_directory/capture-budget.log" 2>&1 &
    capture_budget_acquire_pid=$!
    register_worker "$capture_budget_acquire_pid"
    capture_budget_acquisition_deadline=$((capture_budget_started_unix + 30))
    while ! grep -F -q \
        'backup quiesce lease lacks the required capture budget after acquisition' \
        "$scenario_directory/capture-budget.log"; do
        capture_budget_acquire_state=$(ps -o stat= -p "$capture_budget_acquire_pid" \
            2>/dev/null | sed 's/^[[:space:]]*//;s/[[:space:]].*$//')
        case "$capture_budget_acquire_state" in
            ''|Z*)
                fail 'capture-budget acquire exited before reaching the post-acquisition budget gate'
                ;;
        esac
        [ "$(date -u +%s)" -le "$capture_budget_acquisition_deadline" ] \
            || fail 'capture-budget acquire did not reach its outcome within the declared acquisition bound'
        sleep 0.1
    done
    capture_budget_cleanup_deadline=$(( $(date -u +%s) + 30 ))
    while :; do
        capture_budget_acquire_state=$(ps -o stat= -p "$capture_budget_acquire_pid" \
            2>/dev/null | sed 's/^[[:space:]]*//;s/[[:space:]].*$//')
        case "$capture_budget_acquire_state" in ''|Z*) break ;; esac
        [ "$(date -u +%s)" -lt "$capture_budget_cleanup_deadline" ] \
            || fail 'capture-budget rejection did not finish bounded rollback cleanup'
        sleep 0.1
    done
    capture_budget_status=0
    wait_registered_worker "$capture_budget_acquire_pid" || capture_budget_status=$?
    [ "$capture_budget_status" -ne 0 ] \
        || fail 'backup quiesce accepted less than the required post-acquisition capture budget'
    kill "$capture_budget_stdin_holder_pid" >/dev/null 2>&1 || true
    wait_registered_worker "$capture_budget_stdin_holder_pid" >/dev/null 2>&1 || true
    rm -f "$capture_budget_stdin"
    grep -F -q 'backup quiesce lease lacks the required capture budget after acquisition' \
        "$scenario_directory/capture-budget.log" \
        || fail 'post-acquisition capture-budget rejection did not reach the budget gate'
    capture_budget_watchdog_pid=$(cat "$CONTROL_PLANE_TEST_BACKUP_QUIESCE_WATCHDOG_PID_FILE")
    capture_budget_watchdog_deadline=$(( $(date -u +%s) + 5 ))
    while kill -0 "$capture_budget_watchdog_pid" 2>/dev/null; do
        [ "$(date -u +%s)" -lt "$capture_budget_watchdog_deadline" ] \
            || fail 'capture-budget rejection left its detached watchdog running'
        sleep 0.1
    done
    assert_quiesce_mutation_residue_absent "$capture_budget_operation"
    for capture_budget_input in \
        "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$capture_budget_operation"/.mutation-input.*; do
        [ ! -e "$capture_budget_input" ] && [ ! -L "$capture_budget_input" ] \
            || fail 'capture-budget rejection left staged mutation input behind'
    done
    assert_backup_quiesce_prestate up up up true absent:none unset none
}

scenario_backup_quiesce_budget_only()
{
    configure_backup_quiesce_lab
    scenario_backup_quiesce_budget_bounds
}

scenario_backup_quiesce_fence()
{
    configure_backup_quiesce_lab
    assert_foreign_control_plane_router_filtered
    scenario_backup_quiesce_installer_lock
    scenario_backup_quiesce_budget_bounds

    systemd_operation=backup-quiesce-systemd-lock-0123456789
    systemd_token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
    CONTROL_PLANE_TEST_BACKUP_QUIESCE_SYSTEMD_WATCHDOG=1 \
        CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_AFTER_WATCHDOG_SECONDS=2 \
        "$OPERATOR" backup-quiesce acquire --operation-id "$systemd_operation" \
            --fencing-token "$systemd_token" --lease-seconds 120 \
            > "$scenario_directory/systemd-acquire.log" 2>&1 &
    systemd_acquire_pid=$!
    register_worker "$systemd_acquire_pid"
    systemd_state="$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$systemd_operation/state"
    systemd_wait_attempt=0
    while ! grep -F -x -q 'phase=acquiring' "$systemd_state" 2>/dev/null; do
        systemd_wait_attempt=$((systemd_wait_attempt + 1))
        [ "$systemd_wait_attempt" -lt 400 ] \
            || fail 'systemd-mode acquire did not durably arm its rollback state'
        sleep 0.1
    done
    CONTROL_PLANE_TEST_MODE=1 \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce.sh" \
        watchdog-scan --state-directory "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR" \
        > "$scenario_directory/systemd-concurrent-scan.log" 2>&1 &
    systemd_scan_pid=$!
    register_worker "$systemd_scan_pid"
    wait_registered_worker "$systemd_acquire_pid" \
        || fail 'systemd-mode acquire deadlocked or failed while its permanent watchdog contended'
    systemd_scan_status=0
    wait_registered_worker "$systemd_scan_pid" || systemd_scan_status=$?
    case "$systemd_scan_status" in
        0)
            ;;
        1)
            grep -F -x -q \
                'CONTROL_PLANE_BACKUP_QUIESCE_FAILURE another backup quiesce operation holds the global lock' \
                "$scenario_directory/systemd-concurrent-scan.log" \
                || fail 'concurrent systemd-mode watchdog failed for an unexpected reason'
            ;;
        *)
            fail 'concurrent systemd-mode watchdog returned an unexpected status'
            ;;
    esac
    CONTROL_PLANE_TEST_MODE=1 \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce.sh" \
        watchdog-scan --state-directory "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR"
    "$OPERATOR" backup-quiesce release --operation-id "$systemd_operation" \
        --fencing-token "$systemd_token" >/dev/null
    assert_backup_quiesce_prestate up up up true absent:none unset none

    partial_operation=backup-quiesce-partial-0123456789
    partial_token=1111111111111111111111111111111111111111111111111111111111111111
    if CONTROL_PLANE_TEST_BACKUP_QUIESCE_FAIL_AT=after-maintenance \
        "$OPERATOR" backup-quiesce acquire \
            --operation-id "$partial_operation" \
            --fencing-token "$partial_token" \
            --lease-seconds 120 > "$scenario_directory/partial-acquire.log" 2>&1; then
        fail 'injected partial backup quiesce acquisition unexpectedly succeeded'
    fi
    grep -F -q 'injected backup quiesce acquire failure: after-maintenance' \
        "$scenario_directory/partial-acquire.log" \
        || fail 'partial acquire failure did not reach the injected boundary'
    grep -F -x -q 'phase=released' \
        "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$partial_operation/state"
    assert_backup_quiesce_prestate up up up true absent:none unset none

    distinct_group_operation=backup-quiesce-distinct-group-0123456789
    distinct_group_token=1414141414141414141414141414141414141414141414141414141414141414
    if CONTROL_PLANE_TEST_BACKUP_QUIESCE_DISTINCT_MUTATION_GROUP=1234 \
        "$OPERATOR" backup-quiesce acquire \
            --operation-id "$distinct_group_operation" \
            --fencing-token "$distinct_group_token" --lease-seconds 120 \
            > "$scenario_directory/distinct-group-acquire.log" 2>&1; then
        fail 'distinct supplemental group mutation preflight unexpectedly succeeded'
    fi
    grep -F -q 'remote backup quiesce mutation container has a distinct supplemental group' \
        "$scenario_directory/distinct-group-acquire.log" \
        || fail 'distinct supplemental group did not reach the mutation preflight gate'
    grep -F -x -q phase=released \
        "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$distinct_group_operation/state"
    assert_quiesce_mutation_residue_absent "$distinct_group_operation"
    assert_backup_quiesce_prestate up up up true absent:none unset none

    name_race_operation=backup-quiesce-name-race-0123456789
    name_race_token=1717171717171717171717171717171717171717171717171717171717171717
    name_race_token_sha256=$(printf '%s' "$name_race_token" | sha256sum | awk '{print $1}')
    name_race_permit="/root/control-plane-backup-quiesce/${name_race_operation}-${name_race_token_sha256}.permit"
    /bin/sh -c '
        terminate_owner_wrapper()
        {
            trap - HUP INT TERM
            if [ -n "${controller_pid:-}" ]; then
                kill -TERM "$controller_pid" >/dev/null 2>&1 || true
                wait "$controller_pid" >/dev/null 2>&1 || true
            fi
            exit 143
        }
        trap terminate_owner_wrapper HUP INT TERM
        CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID=$$
        CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_BEFORE_REMOTE_LAUNCH_SECONDS=3
        export CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID
        export CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_BEFORE_REMOTE_LAUNCH_SECONDS
        "$1" backup-quiesce acquire --operation-id "$2" \
            --fencing-token "$3" --lease-seconds 120 &
        controller_pid=$!
        controller_status=0
        wait "$controller_pid" || controller_status=$?
        controller_pid=
        exit "$controller_status"
    ' sh "$OPERATOR" "$name_race_operation" "$name_race_token" \
        > "$scenario_directory/name-race-acquire.log" 2>&1 &
    name_race_owner_pid=$!
    register_worker "$name_race_owner_pid"
    name_race_wait_attempt=0
    while ! docker exec --user 0 "$CONTROL_PLANE_BLUE_CONTAINER" \
        test -d "$name_race_permit"; do
        name_race_wait_attempt=$((name_race_wait_attempt + 1))
        [ "$name_race_wait_attempt" -lt 100 ] \
            || fail 'name-race acquire did not reach the pre-launch permit seam'
        sleep 0.1
    done
    name_race_original_container="${CONTROL_PLANE_BLUE_CONTAINER}-state-bound"
    docker rename "$CONTROL_PLANE_BLUE_CONTAINER" "$name_race_original_container"
    docker run --detach --name "$CONTROL_PLANE_BLUE_CONTAINER" --network none \
        --entrypoint sleep "$MOCK_IMAGE" infinity >/dev/null
    name_race_status=0
    wait_registered_worker "$name_race_owner_pid" || name_race_status=$?
    name_race_owner_pid=
    [ "$name_race_status" -ne 0 ] \
        || fail 'canonical-name replacement unexpectedly reached remote mutation launch'
    grep -F -q 'canonical remote mutation container name changed before launch' \
        "$scenario_directory/name-race-acquire.log" \
        || fail 'canonical-name replacement did not reach the final pre-launch pin gate'
    docker exec --user 0 "$CONTROL_PLANE_BLUE_CONTAINER" /bin/sh -ec '
        [ ! -e /root/control-plane-backup-quiesce ] \
            && [ ! -L /root/control-plane-backup-quiesce ] \
            && [ ! -e /lab-state/services-legacy/maintenance ]
    ' || fail 'canonical-name replacement was mutated through its reused name'
    docker rm --force "$CONTROL_PLANE_BLUE_CONTAINER" >/dev/null
    docker rename "$name_race_original_container" "$CONTROL_PLANE_BLUE_CONTAINER"
    wait_for_quiesce_release "$name_race_operation"
    assert_quiesce_mutation_residue_absent "$name_race_operation"
    assert_backup_quiesce_prestate up up up true absent:none unset none

    input_cleanup_operation=backup-quiesce-input-cleanup-0123456789
    input_cleanup_token=1515151515151515151515151515151515151515151515151515151515151515
    if CONTROL_PLANE_TEST_BACKUP_QUIESCE_FAIL_AFTER_MUTATION_INPUT_LABEL=database-mutation-psql \
        "$OPERATOR" backup-quiesce acquire \
            --operation-id "$input_cleanup_operation" \
            --fencing-token "$input_cleanup_token" --lease-seconds 120 \
            > "$scenario_directory/input-cleanup-acquire.log" 2>&1; then
        fail 'post-state staged-input failure unexpectedly succeeded'
    fi
    grep -F -q \
        'injected backup quiesce failure after staged mutation input: database-mutation-psql' \
        "$scenario_directory/input-cleanup-acquire.log" \
        || fail 'post-state staged-input failure did not reach the injected boundary'
    grep -F -x -q phase=released \
        "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$input_cleanup_operation/state"
    assert_quiesce_mutation_residue_absent "$input_cleanup_operation"
    assert_backup_quiesce_prestate up up up true absent:none unset none

    finalizing_retry_operation=backup-quiesce-finalizing-retry-0123456789
    finalizing_retry_token=1212121212121212121212121212121212121212121212121212121212121212
    "$OPERATOR" backup-quiesce acquire --operation-id "$finalizing_retry_operation" \
        --fencing-token "$finalizing_retry_token" --lease-seconds 120 >/dev/null
    if CONTROL_PLANE_TEST_BACKUP_QUIESCE_FAIL_AT=after-maintenance-restore \
        "$OPERATOR" backup-quiesce release \
            --operation-id "$finalizing_retry_operation" \
            --fencing-token "$finalizing_retry_token" \
            > "$scenario_directory/finalizing-release.log" 2>&1; then
        fail 'post-maintenance release fault unexpectedly succeeded'
    fi
    grep -F -x -q \
        'CONTROL_PLANE_BACKUP_QUIESCE_FAILURE injected backup quiesce restore failure: after-maintenance-restore' \
        "$scenario_directory/finalizing-release.log" \
        || fail 'post-maintenance release fault did not reach the durable finalizing seam'
    grep -F -x -q phase=finalizing \
        "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$finalizing_retry_operation/state" \
        || fail 'post-maintenance release fault did not preserve durable finalizing state'
    assert_backup_quiesce_prestate up up up true absent:none unset none
    "$OPERATOR" backup-quiesce release --operation-id "$finalizing_retry_operation" \
        --fencing-token "$finalizing_retry_token" >/dev/null
    grep -F -x -q phase=released \
        "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$finalizing_retry_operation/state"
    [ ! -e "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/active" ] \
        || fail 'explicit finalizing retry did not clear the active pointer'

    finalizing_watchdog_operation=backup-quiesce-finalizing-watchdog-0123456789
    finalizing_watchdog_token=1313131313131313131313131313131313131313131313131313131313131313
    "$OPERATOR" backup-quiesce acquire --operation-id "$finalizing_watchdog_operation" \
        --fencing-token "$finalizing_watchdog_token" --lease-seconds 120 >/dev/null
    watchdog_pid=$(cat "$CONTROL_PLANE_TEST_BACKUP_QUIESCE_WATCHDOG_PID_FILE")
    kill -9 "$watchdog_pid"
    finalizing_watchdog_status=0
    CONTROL_PLANE_TEST_BACKUP_QUIESCE_BOOT_ID=lab-finalizing-watchdog-0123456789 \
        CONTROL_PLANE_TEST_BACKUP_QUIESCE_FAIL_AT=after-maintenance-restore \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce.sh" \
        watchdog-scan --state-directory "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR" \
        > "$scenario_directory/finalizing-watchdog.log" 2>&1 \
        || finalizing_watchdog_status=$?
    [ "$finalizing_watchdog_status" -ne 0 ] \
        || fail 'post-maintenance watchdog fault unexpectedly succeeded'
    grep -F -x -q phase=finalizing \
        "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$finalizing_watchdog_operation/state" \
        || fail 'watchdog fault did not preserve durable finalizing state'
    assert_backup_quiesce_prestate up up up true absent:none unset none
    CONTROL_PLANE_TEST_BACKUP_QUIESCE_BOOT_ID=lab-finalizing-watchdog-0123456789 \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce.sh" \
        watchdog-scan --state-directory "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR"
    grep -F -x -q phase=released \
        "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$finalizing_watchdog_operation/state"
    [ ! -e "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/active" ] \
        || fail 'watchdog finalizing retry did not clear the active pointer'
    assert_backup_quiesce_prestate up up up true absent:none unset none

    managed_app_container="${project_name}-managed-app"
    docker run --detach --name "$managed_app_container" --network "$CONTROL_PLANE_NETWORK" \
        --entrypoint /bin/sh "$MOCK_IMAGE" -ec \
        'mkdir -p /tmp/managed; printf managed-app-healthy > /tmp/managed/index.html; exec httpd -f -p 9090 -h /tmp/managed' \
        >/dev/null
    writer_stop="$scenario_directory/backup-quiesce-writer.stop"
    writer_state="$CONTROL_PLANE_APPLICATIONS_DIRECTORY/backup-quiesce-writer-state"
    (
        while [ ! -e "$writer_stop" ]; do
            if [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
                /usr/local/bin/control-plane-lab-service maintenance-state)" = absent:none ]; then
                docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
                    psql --username postgres --dbname postgres --quiet \
                        --command 'INSERT INTO backup_quiesce_writer_samples DEFAULT VALUES' \
                    >/dev/null
                printf '%s\n' writer-tick >> "$writer_state"
            fi
            sleep 0.1
        done
    ) &
    backup_quiesce_writer_pid=$!
    register_worker "$backup_quiesce_writer_pid"
    sleep 1
    [ -s "$writer_state" ] \
        && [ "$(docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
            psql --username postgres --dbname postgres --tuples-only --no-align --quiet \
                --command 'SELECT count(*) FROM backup_quiesce_writer_samples' \
            | tr -d '[:space:]')" -gt 0 ] \
        || fail 'synthetic database/filesystem writer was not healthy before capture fencing'

    operation_name=backup-quiesce-normal-0123456789
    fencing_token=2222222222222222222222222222222222222222222222222222222222222222
    token_sha256=$(printf '%s' "$fencing_token" | sha256sum | awk '{print $1}')
    acquire_output=$("$OPERATOR" backup-quiesce acquire \
        --operation-id "$operation_name" --fencing-token "$fencing_token" --lease-seconds 120)
    printf '%s' "$acquire_output" | grep -Eq \
        "^backup-quiesce=acquired;operation_id=${operation_name};fencing_token_sha256=${token_sha256};lease_acquired_unix=[1-9][0-9]*;lease_expires_unix=[1-9][0-9]*$" \
        || fail 'backup quiesce acquire output was not the sole exact binding line'
    database_count_before=$(docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --tuples-only --no-align --quiet \
            --command 'SELECT count(*) FROM backup_quiesce_writer_samples' | tr -d '[:space:]')
    state_sha256_before=$(sha256sum "$writer_state" | awk '{print $1}')
    sleep 2
    [ "$(docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --tuples-only --no-align --quiet \
            --command 'SELECT count(*) FROM backup_quiesce_writer_samples' | tr -d '[:space:]')" \
        = "$database_count_before" ] \
        && [ "$(sha256sum "$writer_state" | awk '{print $1}')" = "$state_sha256_before" ] \
        || fail 'synthetic database/filesystem writer crossed the acquired capture fence'
    if "$OPERATOR" backup-quiesce status --operation-id "$operation_name" \
        --fencing-token 3333333333333333333333333333333333333333333333333333333333333333 \
        >/dev/null 2>&1; then
        fail 'backup quiesce status accepted the wrong fencing token'
    fi
    status_output=$("$OPERATOR" backup-quiesce status \
        --operation-id "$operation_name" --fencing-token "$fencing_token")
    printf '%s' "$status_output" | grep -Eq \
        "^backup-quiesce=status-passed;operation_id=${operation_name};fencing_token_sha256=${token_sha256};lease_expires_unix=[1-9][0-9]*$" \
        || fail 'backup quiesce status output was not the sole exact binding line'
    curl --fail --silent --show-error --header "Host: $CONTROL_PLANE_HOST" \
        "https://127.0.0.1:${LAB_TRAEFIK_PORT}/api/health" >/dev/null \
        || fail 'control-plane health endpoint was unavailable during backup quiesce'
    capture_control_plane_route foreign-router-filtered-during-quiesce POST
    [ "$captured_status" = 503 ] \
        && [ "$captured_color" = legacy ] \
        && [ "$captured_body" = legacy ] \
        || fail 'control-plane mutation/webhook route did not reach the exact quiesced lab backend'
    [ "$(docker run --rm --network "$CONTROL_PLANE_NETWORK" "$MOCK_IMAGE" \
        curl --fail --silent "http://${managed_app_container}:9090/")" = managed-app-healthy ] \
        || fail 'managed application traffic was interrupted by control-plane backup quiesce'
    drifted_live_container="${CONTROL_PLANE_BLUE_CONTAINER}-identity-drift"
    docker rename "$CONTROL_PLANE_BLUE_CONTAINER" "$drifted_live_container"
    if "$OPERATOR" backup-quiesce status --operation-id "$operation_name" \
        --fencing-token "$fencing_token" >/dev/null 2>&1; then
        fail 'backup quiesce status accepted a changed canonical live-container identity'
    fi
    docker rename "$drifted_live_container" "$CONTROL_PLANE_BLUE_CONTAINER"
    release_output=$("$OPERATOR" backup-quiesce release \
        --operation-id "$operation_name" --fencing-token "$fencing_token")
    repeated_release_output=$("$OPERATOR" backup-quiesce release \
        --operation-id "$operation_name" --fencing-token "$fencing_token")
    [ "$release_output" = "$repeated_release_output" ] \
        || fail 'idempotent backup quiesce release changed its durable release binding'
    printf '%s' "$release_output" | grep -Eq \
        "^backup-quiesce=released;operation_id=${operation_name};fencing_token_sha256=${token_sha256};released_unix=[1-9][0-9]*$" \
        || fail 'backup quiesce release output was not the sole exact binding line'
    assert_backup_quiesce_prestate up up up true absent:none unset none
    sleep 1
    [ "$(docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --tuples-only --no-align --quiet \
            --command 'SELECT count(*) FROM backup_quiesce_writer_samples' | tr -d '[:space:]')" \
        -gt "$database_count_before" ] \
        || fail 'synthetic writer did not resume after backup quiesce release'
    : > "$writer_stop"
    wait_registered_worker "$backup_quiesce_writer_pid"
    backup_quiesce_writer_pid=

    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service down scheduler-worker
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service pause horizon
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service down nightwatch-agent
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service maintenance-set-prestate
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service reset-work-counts
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service queue-work 1
    maintenance_prestate=$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service maintenance-state)
    maintenance_metadata_prestate=$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service maintenance-metadata)
    [ "$(curl --silent --output /dev/null --write-out '%{http_code}' --request POST \
        --header "Host: $CONTROL_PLANE_HOST" \
        --header 'X-Maintenance-Bypass: preexisting-bypass' \
        --header 'Cookie: laravel_maintenance=preexisting-bypass' \
        "https://127.0.0.1:${LAB_TRAEFIK_PORT}/cgi-bin/request")" = 200 ] \
        || fail 'lab pre-existing maintenance secret did not model a working bypass before acquisition'
    docker stop --time 2 "$CONTROL_PLANE_SOKETI_CONTAINER" >/dev/null
    docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --quiet \
            --command 'ALTER ROLE postgres SET default_transaction_read_only TO off' >/dev/null
    varied_operation=backup-quiesce-varied-prestate-0123456789
    varied_token=4444444444444444444444444444444444444444444444444444444444444444
    "$OPERATOR" backup-quiesce acquire --operation-id "$varied_operation" \
        --fencing-token "$varied_token" --lease-seconds 120 >/dev/null
    [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service maintenance-state)" != "$maintenance_prestate" ] \
        || fail 'backup quiesce retained a pre-existing maintenance bypass instead of replacing the fence'
    [ "$(curl --silent --output /dev/null --write-out '%{http_code}' --request POST \
        --header "Host: $CONTROL_PLANE_HOST" \
        --header 'X-Maintenance-Bypass: preexisting-bypass' \
        --header 'Cookie: laravel_maintenance=preexisting-bypass' \
        "https://127.0.0.1:${LAB_TRAEFIK_PORT}/cgi-bin/request")" = 503 ] \
        || fail 'pre-existing maintenance secret or cookie bypassed the replacement backup fence'
    varied_state="$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$varied_operation/state"
    varied_fence_sha256=$(sed -n 's/^fence_sha256=//p' "$varied_state")
    CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_BEFORE_DATABASE_RESTORE_SECONDS=3 \
        "$OPERATOR" backup-quiesce release --operation-id "$varied_operation" \
            --fencing-token "$varied_token" \
            > "$scenario_directory/paused-horizon-release.log" 2>&1 &
    varied_release_pid=$!
    register_worker "$varied_release_pid"
    paused_restore_attempt=0
    while :; do
        if [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
            /usr/local/bin/control-plane-lab-service horizon-master-state)" = paused ] \
            && grep -F -x -q phase=releasing "$varied_state"; then
            break
        fi
        paused_restore_attempt=$((paused_restore_attempt + 1))
        [ "$paused_restore_attempt" -lt 100 ] \
            || fail 'paused Horizon restore did not reach the fenced pre-database seam'
        sleep 0.1
    done
    [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service maintenance-state)" \
        = "present:$varied_fence_sha256" ] \
        || fail 'secretless maintenance fence was removed before paused Horizon registration'
    [ "$(docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --tuples-only --no-align --quiet \
            --command "SELECT split_part(setting, '=', 2) FROM pg_roles, unnest(rolconfig) AS setting WHERE rolname = 'postgres' AND split_part(setting, '=', 1) = 'default_transaction_read_only'" \
        | tr -d '[:space:]')" = on ] \
        || fail 'database became writable before exact Horizon pause proof'
    [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service reserved-count)" = 0 \
        ] && [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service queue-count)" = 1 \
        ] && [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service executed-count)" = 0 ] \
        || fail 'Horizon reserved or executed queued work during fenced start-to-pause restore'
    wait_registered_worker "$varied_release_pid" || fail 'paused Horizon release failed'
    assert_backup_quiesce_prestate down paused down false "$maintenance_prestate" off \
        "$maintenance_metadata_prestate"
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service maintenance-release
    sleep 1
    [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service queue-count)" = 1 \
        ] && [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service executed-count)" = 0 ] \
        || fail 'paused Horizon executed queued work after maintenance was removed'
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service continue horizon
    work_execution_attempt=0
    while [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service executed-count)" != 1 ]; do
        work_execution_attempt=$((work_execution_attempt + 1))
        [ "$work_execution_attempt" -lt 50 ] \
            || fail 'behavioral Horizon fixture did not execute queued work after resume'
        sleep 0.1
    done
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service reset-work-counts
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service resume-all
    docker start "$CONTROL_PLANE_SOKETI_CONTAINER" >/dev/null
    docker exec "$CONTROL_PLANE_DATABASE_CONTAINER" \
        psql --username postgres --dbname postgres --quiet \
            --command 'ALTER ROLE postgres RESET default_transaction_read_only' >/dev/null

    pre_marker_operation=backup-quiesce-pre-marker-owner-0123456789
    pre_marker_token=1616161616161616161616161616161616161616161616161616161616161616
    pre_marker_token_sha256=$(printf '%s' "$pre_marker_token" | sha256sum | awk '{print $1}')
    pre_marker_permit="/root/control-plane-backup-quiesce/${pre_marker_operation}-${pre_marker_token_sha256}.permit"
    /bin/sh -c '
        terminate_owner_wrapper()
        {
            trap - HUP INT TERM
            if [ -n "${controller_pid:-}" ]; then
                kill -TERM "$controller_pid" >/dev/null 2>&1 || true
                wait "$controller_pid" >/dev/null 2>&1 || true
            fi
            exit 143
        }
        trap terminate_owner_wrapper HUP INT TERM
        CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID=$$
        CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_BEFORE_REMOTE_MARKER_SECONDS=30
        CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_REMOTE_MUTATION_SECONDS=30
        export CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID
        export CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_BEFORE_REMOTE_MARKER_SECONDS
        export CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_REMOTE_MUTATION_SECONDS
        "$1" backup-quiesce acquire --operation-id "$2" \
            --fencing-token "$3" --lease-seconds 120 &
        controller_pid=$!
        controller_status=0
        wait "$controller_pid" || controller_status=$?
        controller_pid=
        [ "$controller_status" -eq 0 ] || exit "$controller_status"
        while :; do sleep 1; done
    ' sh "$OPERATOR" "$pre_marker_operation" "$pre_marker_token" \
        > "$scenario_directory/pre-marker-owner-acquire.log" 2>&1 &
    pre_marker_owner_pid=$!
    register_worker "$pre_marker_owner_pid"
    pre_marker_attempt=0
    while ! docker exec --user 0 "$CONTROL_PLANE_BLUE_CONTAINER" \
        test -f "$pre_marker_permit/pre-marker-ready"; do
        pre_marker_attempt=$((pre_marker_attempt + 1))
        [ "$pre_marker_attempt" -lt 100 ] \
            || fail 'remote wrapper did not reach the held pre-marker seam'
        sleep 0.1
    done
    kill -9 "$pre_marker_owner_pid"
    wait_registered_worker "$pre_marker_owner_pid" >/dev/null 2>&1 || true
    pre_marker_owner_pid=
    wait_for_quiesce_release "$pre_marker_operation"
    [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service held-mutation-state)" = absent ] \
        || fail 'revoked pre-marker wrapper started its delayed remote mutation'
    if docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service held-mutation-completed; then
        fail 'revoked pre-marker wrapper completed its delayed remote mutation'
    fi
    assert_quiesce_mutation_residue_absent "$pre_marker_operation"
    assert_backup_quiesce_prestate up up up true absent:none unset none

    held_owner_operation=backup-quiesce-held-owner-0123456789
    held_owner_token=cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd
    /bin/sh -c '
        terminate_owner_wrapper()
        {
            trap - HUP INT TERM
            if [ -n "${controller_pid:-}" ]; then
                kill -TERM "$controller_pid" >/dev/null 2>&1 || true
                wait "$controller_pid" >/dev/null 2>&1 || true
            fi
            exit 143
        }
        trap terminate_owner_wrapper HUP INT TERM
        CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID=$$
        CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_REMOTE_MUTATION_SECONDS=30
        export CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID
        export CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_REMOTE_MUTATION_SECONDS
        "$1" backup-quiesce acquire --operation-id "$2" \
            --fencing-token "$3" --lease-seconds 120 &
        controller_pid=$!
        controller_status=0
        wait "$controller_pid" || controller_status=$?
        controller_pid=
        [ "$controller_status" -eq 0 ] || exit "$controller_status"
        while :; do sleep 1; done
    ' sh "$OPERATOR" "$held_owner_operation" "$held_owner_token" \
        > "$scenario_directory/held-owner-acquire.log" 2>&1 &
    held_owner_pid=$!
    register_worker "$held_owner_pid"
    held_mutation_attempt=0
    while [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service held-mutation-state)" != running ]; do
        held_mutation_attempt=$((held_mutation_attempt + 1))
        [ "$held_mutation_attempt" -lt 100 ] \
            || fail 'held remote mutation did not begin during acquisition'
        sleep 0.1
    done
    held_child_attempt=0
    while [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service held-mutation-child-state)" != running ] \
        || ! docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service held-mutation-child-ready; do
        held_child_attempt=$((held_child_attempt + 1))
        [ "$held_child_attempt" -lt 100 ] \
            || fail 'setpgid held-mutation child did not begin'
        sleep 0.1
    done
    [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service held-mutation-identity)" \
        = 9999:9999:9999 ] \
        || fail 'default remote mutation payload did not run as the bound non-root identity'
    held_process_tree=$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service held-mutation-process-tree)
    printf '%s' "$held_process_tree" \
        | grep -Eq '^[1-9][0-9]*:[1-9][0-9]*:[1-9][0-9]*:[1-9][0-9]*:[1-9][0-9]*:[1-9][0-9]*$' \
        || fail 'held remote mutation process tree is malformed'
    saved_ifs=$IFS
    IFS=:
    # shellcheck disable=SC2086 # Colon-delimited process tree requires intentional splitting.
    set -- $held_process_tree
    IFS=$saved_ifs
    held_leader_pid=$1
    held_leader_process_group=$2
    held_session=$3
    held_child_pid=$4
    held_child_process_group=$5
    held_child_session=$6
    [ "$held_leader_pid" = "$held_leader_process_group" ] \
        && [ "$held_leader_pid" = "$held_session" ] \
        && [ "$held_child_pid" = "$held_child_process_group" ] \
        && [ "$held_child_process_group" != "$held_leader_process_group" ] \
        && [ "$held_child_session" = "$held_session" ] \
        || fail 'held child did not escape the leader process group inside the private session'
    held_owner_token_sha256=$(printf '%s' "$held_owner_token" | sha256sum | awk '{print $1}')
    held_active="/root/control-plane-backup-quiesce/${held_owner_operation}-${held_owner_token_sha256}.active"
    docker exec --user 0 "$CONTROL_PLANE_BLUE_CONTAINER" /bin/sh -ec '
        active=$1
        identity="$active/identity"
        [ -d /root ] && [ ! -L /root ] \
            && [ "$(stat -c %u:%g:%a /root)" = 0:0:700 ] \
            && [ -d /root/control-plane-backup-quiesce ] \
            && [ ! -L /root/control-plane-backup-quiesce ] \
            && [ "$(stat -c %u:%g:%a /root/control-plane-backup-quiesce)" = 0:0:700 ] \
            && [ -d "$active" ] && [ ! -L "$active" ] \
            && [ "$(stat -c %u:%g:%a "$active")" = 0:0:700 ] \
            && [ -f "$identity" ] && [ ! -L "$identity" ] \
            && [ "$(stat -c %u:%g:%a "$identity")" = 0:0:600 ] \
            || exit 1
        mutation_identity=$(cat "$identity")
        printf "%s" "$mutation_identity" \
            | grep -Eq "^[1-9][0-9]*:[1-9][0-9]*:[1-9][0-9]*:[1-9][0-9]*$"
        saved_ifs=$IFS
        IFS=:
        set -- $mutation_identity
        IFS=$saved_ifs
        [ "$1" -gt 1 ] && [ "$1" = "$3" ] && [ "$1" = "$4" ]
        process_stat=$(sed "s/^.*) //" "/proc/$1/stat")
        set -- $process_stat
        current_process_group=$3
        current_session=$4
        shift 19
        current_start=$1
        [ "$current_start:$current_process_group:$current_session" \
            = "$(printf "%s" "$mutation_identity" | cut -d: -f2-4)" ]
    ' sh "$held_active" \
        || fail 'trusted held-mutation marker did not bind the exact private session leader'
    hostile_sibling="${held_active}.hostile"
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" /bin/sh -ec '
        active=$1
        hostile=$2
        ! ls /root >/dev/null 2>&1
        ! mkdir "$hostile" >/dev/null 2>&1
        ! mv "$active" "$hostile" >/dev/null 2>&1
        ! rm -rf "$active" >/dev/null 2>&1
        ! ln -s /proc/1/root "$hostile" >/dev/null 2>&1
        ! printf "%s\n" forged > "$active/identity" 2>/dev/null
        ! rm -f "$active/identity" >/dev/null 2>&1
    ' sh "$held_active" "$hostile_sibling" \
        || fail 'workload UID reached the root-owned mutation ticket boundary'
    docker exec --user 0 "$CONTROL_PLANE_BLUE_CONTAINER" /bin/sh -ec '
        [ -d "$1" ] && [ ! -L "$1" ] && [ -f "$1/identity" ] \
            && [ ! -e "$2" ] && [ ! -L "$2" ]
    ' sh "$held_active" "$hostile_sibling" \
        || fail 'hostile workload attempt changed the trusted mutation boundary'
    held_owner_loss_started=$(date -u +%s)
    kill -9 "$held_owner_pid"
    wait_registered_worker "$held_owner_pid" >/dev/null 2>&1 || true
    held_owner_pid=
    held_mutation_stop_attempt=0
    while [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service held-mutation-state)" != gone ] \
        || [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service held-mutation-child-state)" != gone ]; do
        held_mutation_stop_attempt=$((held_mutation_stop_attempt + 1))
        [ "$held_mutation_stop_attempt" -lt 100 ] \
            || fail 'held acquisition mutation did not cancel promptly after owner death'
        sleep 0.1
    done
    [ $(( $(date -u +%s) - held_owner_loss_started )) -lt 10 ] \
        || fail 'held acquisition mutation did not cancel promptly after owner death'
    [ "$(docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service held-mutation-child-state)" = gone ] \
        || fail 'setpgid held-mutation child survived private-session cleanup'
    wait_for_quiesce_release "$held_owner_operation"
    if docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service held-mutation-completed; then
        fail 'cancelled remote acquisition mutation completed after owner death'
    fi
    assert_quiesce_mutation_residue_absent "$held_owner_operation"
    assert_backup_quiesce_prestate up up up true absent:none unset none

    zombie_operation=backup-quiesce-zombie-owner-0123456789
    zombie_token=dededededededededededededededededededededededededededededededede
    zombie_pid_file="$scenario_directory/zombie-owner.pid"
    zombie_acquired_marker="$scenario_directory/zombie-owner-acquired"
    python3 -c '
import os
import pathlib
import subprocess
import sys
import time

operator, operation, token, pid_file, acquired_marker = sys.argv[1:]
owner_pid = os.fork()
if owner_pid == 0:
    environment = os.environ.copy()
    environment["CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID"] = str(os.getpid())
    result = subprocess.run(
        [operator, "backup-quiesce", "acquire", "--operation-id", operation,
         "--fencing-token", token, "--lease-seconds", "120"],
        env=environment,
        stdout=subprocess.DEVNULL,
    )
    if result.returncode != 0:
        os._exit(result.returncode)
    pathlib.Path(acquired_marker).touch()
    os._exit(0)
pathlib.Path(pid_file).write_text(f"{owner_pid}\n", encoding="ascii")
time.sleep(60)
' "$OPERATOR" "$zombie_operation" "$zombie_token" "$zombie_pid_file" \
        "$zombie_acquired_marker" &
    zombie_parent_pid=$!
    register_worker "$zombie_parent_pid"
    wait_for_file "$zombie_pid_file"
    wait_for_quiesce_acquired_marker "$zombie_operation" "$zombie_acquired_marker"
    zombie_owner_pid=$(cat "$zombie_pid_file")
    zombie_wait_attempt=0
    while ! ps -o stat= -p "$zombie_owner_pid" 2>/dev/null | grep -Eq '^[[:space:]]*Z'; do
        zombie_wait_attempt=$((zombie_wait_attempt + 1))
        [ "$zombie_wait_attempt" -lt 100 ] \
            || fail 'dedicated backup owner did not enter zombie state'
        sleep 0.1
    done
    wait_for_quiesce_reconciliation "$zombie_operation"
    wait_for_quiesce_release "$zombie_operation"
    kill "$zombie_parent_pid" >/dev/null 2>&1 || true
    wait_registered_worker "$zombie_parent_pid" >/dev/null 2>&1 || true
    zombie_parent_pid=
    assert_backup_quiesce_prestate up up up true absent:none unset none
    assert_quiesce_mutation_residue_absent "$zombie_operation"

    sigkill_operation=backup-quiesce-sigkill-0123456789
    sigkill_token=5555555555555555555555555555555555555555555555555555555555555555
    sigkill_acquired_marker="$scenario_directory/sigkill-owner-acquired"
    /bin/sh -c '
        terminate_owner_wrapper()
        {
            trap - HUP INT TERM
            if [ -n "${controller_pid:-}" ]; then
                kill -TERM "$controller_pid" >/dev/null 2>&1 || true
                wait "$controller_pid" >/dev/null 2>&1 || true
            fi
            exit 143
        }
        trap terminate_owner_wrapper HUP INT TERM
        CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID=$$
        export CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID
        "$1" backup-quiesce acquire --operation-id "$2" \
            --fencing-token "$3" --lease-seconds 120 >/dev/null &
        controller_pid=$!
        controller_status=0
        wait "$controller_pid" || controller_status=$?
        controller_pid=
        [ "$controller_status" -eq 0 ] || exit "$controller_status"
        : > "$4"
        while :; do sleep 1; done
    ' sh "$OPERATOR" "$sigkill_operation" "$sigkill_token" \
        "$sigkill_acquired_marker" &
    capture_pid=$!
    register_worker "$capture_pid"
    wait_for_quiesce_acquired_marker "$sigkill_operation" "$sigkill_acquired_marker"
    [ "$capture_pid" = "$(sed -n 's/^owner_pid=//p' \
        "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$sigkill_operation/state")" ] \
        || fail 'backup lease was not bound to the dedicated owner subprocess'
    kill -9 "$capture_pid"
    wait_registered_worker "$capture_pid" >/dev/null 2>&1 || true
    wait_for_quiesce_reconciliation "$sigkill_operation"
    wait_for_quiesce_release "$sigkill_operation"
    assert_backup_quiesce_prestate up up up true absent:none unset none
    assert_quiesce_mutation_residue_absent "$sigkill_operation"

    reboot_operation=backup-quiesce-reboot-0123456789
    reboot_token=6666666666666666666666666666666666666666666666666666666666666666
    "$OPERATOR" backup-quiesce acquire --operation-id "$reboot_operation" \
        --fencing-token "$reboot_token" --lease-seconds 120 >/dev/null
    watchdog_pid=$(cat "$CONTROL_PLANE_TEST_BACKUP_QUIESCE_WATCHDOG_PID_FILE")
    kill -9 "$watchdog_pid"
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service resume-all
    docker start "$CONTROL_PLANE_SOKETI_CONTAINER" >/dev/null
    CONTROL_PLANE_TEST_MODE=1 \
        CONTROL_PLANE_TEST_BACKUP_QUIESCE_BOOT_ID=lab-boot-after-reboot-0123456789 \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce.sh" \
        watchdog-scan --state-directory "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR" &
    boot_reconcile_pid=$!
    register_worker "$boot_reconcile_pid"
    wait_for_quiesce_reconciliation "$reboot_operation"
    boot_reconcile_status=0
    wait_registered_worker "$boot_reconcile_pid" || boot_reconcile_status=$?
    boot_reconcile_pid=
    [ "$boot_reconcile_status" -eq 0 ] \
        || fail 'new-boot watchdog scan failed during exact restoration'
    wait_for_quiesce_release "$reboot_operation"
    assert_backup_quiesce_prestate up up up true absent:none unset none
    assert_quiesce_mutation_residue_absent "$reboot_operation"

    reaper_old_operation=backup-quiesce-ownerless-reaper-old-0123456789
    reaper_old_token=9999999999999999999999999999999999999999999999999999999999999999
    "$OPERATOR" backup-quiesce acquire --operation-id "$reaper_old_operation" \
        --fencing-token "$reaper_old_token" --lease-seconds 120 >/dev/null
    watchdog_pid=$(cat "$CONTROL_PLANE_TEST_BACKUP_QUIESCE_WATCHDOG_PID_FILE")
    kill -9 "$watchdog_pid"
    docker exec "$CONTROL_PLANE_BLUE_CONTAINER" \
        /usr/local/bin/control-plane-lab-service resume-all
    docker start "$CONTROL_PLANE_SOKETI_CONTAINER" >/dev/null
    reaper_new_operation=backup-quiesce-ownerless-reaper-new-0123456789
    reaper_new_token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
    CONTROL_PLANE_TEST_BACKUP_QUIESCE_BOOT_ID=lab-boot-new-acquire-0123456789 \
        "$OPERATOR" backup-quiesce acquire --operation-id "$reaper_new_operation" \
            --fencing-token "$reaper_new_token" --lease-seconds 120 >/dev/null
    grep -F -x -q phase=released \
        "$CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR/$reaper_old_operation/state" \
        || fail 'new acquire did not reap the unexpired ownerless active lease'
    CONTROL_PLANE_TEST_BACKUP_QUIESCE_BOOT_ID=lab-boot-new-acquire-0123456789 \
        "$OPERATOR" backup-quiesce release --operation-id "$reaper_new_operation" \
            --fencing-token "$reaper_new_token" >/dev/null
    assert_backup_quiesce_prestate up up up true absent:none unset none
    curl --fail --silent --show-error --header "Host: $CONTROL_PLANE_HOST" \
        "https://127.0.0.1:${LAB_TRAEFIK_PORT}/api/health" >/dev/null \
        || fail 'control-plane health route did not recover after simulated host reboot'
}

main()
{
    require_command curl
    require_command docker
    require_command flock
    require_command grep
    require_command mkfifo
    require_command mktemp
    require_command openssl
    require_command php
    require_command ps
    require_command python3
    require_command shellcheck
    docker info >/dev/null 2>&1 || fail 'Docker daemon is not reachable'
    discover_control_plane_migrations
    reserve_lab_port_slot
    shellcheck -e SC2015 --shell=sh "$OPERATOR" "$LAB_DIRECTORY/run.sh" \
        "$LAB_DIRECTORY/entrypoint-runtime-contract-test.sh" \
        "$REPOSITORY_ROOT/docker/production/bin/coolify-entrypoint" \
        "$REPOSITORY_ROOT/docker/production/bin/control-plane-direct-probe-healthcheck" \
        "$LAB_DIRECTORY/runtime-fence-provisioner.sh" \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce.sh" \
        "$LAB_DIRECTORY/backup-attestation-verifier.sh" \
        "$LAB_DIRECTORY/proxy-enrollment-command.sh" \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/traefik-ingress.sh" \
        "$LAB_DIRECTORY/mock-control-plane/dependency.sh" \
        "$LAB_DIRECTORY/mock-control-plane/entrypoint.sh" \
        "$LAB_DIRECTORY/mock-control-plane/install" \
        "$LAB_DIRECTORY/mock-control-plane/lab-service.sh" \
        "$LAB_DIRECTORY/mock-control-plane/probe.sh" \
        "$LAB_DIRECTORY/mock-control-plane/rehearse-migration.sh" \
        "$LAB_DIRECTORY/mock-control-plane/request.sh" \
        "$LAB_DIRECTORY/mock-control-plane/route-health.sh"
    shellcheck -e SC2015 --shell=sh "$LAB_DIRECTORY/mock-control-plane/systemctl"
    shellcheck -e SC2015 --shell=bash \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/backup-quiesce/install-host-prerequisites.sh"
    "$LAB_DIRECTORY/entrypoint-runtime-contract-test.sh"
    prepare_mock_image_context
    docker pull "$MOCK_REGISTRY_IMAGE" >/dev/null
    docker build --tag "$MOCK_IMAGE" "$mock_image_context" >/dev/null
    publish_mock_image
    CONTROL_PLANE_BLUE_GREEN_SIMULATION_IMAGE=$MOCK_IMAGE \
        "$LAB_DIRECTORY/mock-control-plane-artisan-contract-test.sh"
    horizon_behavior_output=$(docker run --rm --network none \
        --volume "$REPOSITORY_ROOT:/workspace:ro" \
        --entrypoint php85 "$MOCK_IMAGE" \
        /workspace/docker/control-plane-blue-green/fixtures/horizon-maintenance-behavior.php)
    [ "$horizon_behavior_output" \
        = 'horizon-maintenance=passed;force=false;reservation_attempt=0;executed=0' ] \
        || fail 'real Horizon force=false maintenance behavior proof failed'

    if [ "${1:-}" = proxy-enrollment ]; then
        [ "$#" -eq 1 ] || fail 'proxy-enrollment lab selector accepts no additional arguments'
        with_lab proxy-enrollment-success 38 scenario_proxy_enrollment_success
        with_lab proxy-enrollment-preidentity-recovery 39 scenario_proxy_enrollment_preidentity_recovery
        with_lab proxy-enrollment-activating-recovery 40 scenario_proxy_enrollment_activating_recovery
        with_lab proxy-enrollment-persisted-rollback-recovery 41 scenario_proxy_enrollment_persisted_rollback_recovery
        with_lab proxy-enrollment-cross-operation-adoption 42 scenario_proxy_enrollment_cross_operation_adoption
        with_lab proxy-enrollment-partial-credential-recovery 43 scenario_proxy_enrollment_partial_credential_recovery
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_PROXY_ENROLLMENT_LAB_PASS'
        return
    fi
    if [ "${1:-}" = backup-quiesce-budget ]; then
        [ "$#" -eq 1 ] || fail 'backup-quiesce-budget lab selector accepts no additional arguments'
        with_lab backup-quiesce-budget 24 scenario_backup_quiesce_budget_only
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_BACKUP_QUIESCE_BUDGET_LAB_PASS'
        return
    fi
    if [ "${1:-}" = queue-gate ]; then
        [ "$#" -eq 1 ] || fail 'queue-gate lab selector accepts no additional arguments'
        with_lab queue-gate-and-rehearsal 1 scenario_queue_gate_and_rehearsal
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_QUEUE_GATE_LAB_PASS'
        return
    fi
    if [ "${1:-}" = router-reload-failure ]; then
        [ "$#" -eq 1 ] || fail 'router-reload-failure lab selector accepts no additional arguments'
        with_lab router-reload-failure 8 scenario_router_reload_failure
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_ROUTER_RELOAD_FAILURE_LAB_PASS'
        return
    fi
    if [ "${1:-}" = migration-orphan ]; then
        [ "$#" -eq 1 ] || fail 'migration-orphan lab selector accepts no additional arguments'
        with_lab unknown-migration-outcome-retries 3 scenario_unknown_migration_outcome_retries
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_MIGRATION_ORPHAN_LAB_PASS'
        return
    fi
    if [ "${1:-}" = migration-lock-timeout ]; then
        [ "$#" -eq 1 ] || fail 'migration-lock-timeout lab selector accepts no additional arguments'
        with_lab lock-timeout-resumes-blue 5 scenario_lock_timeout_resumes_blue
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT_LAB_PASS'
        return
    fi
    if [ "${1:-}" = migration-terminal-cutover ]; then
        [ "$#" -eq 1 ] \
            || fail 'migration-terminal-cutover lab selector accepts no additional arguments'
        with_lab migration-terminal-cutover 32 scenario_terminal_migration_crash_direct_cutover
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_MIGRATION_TERMINAL_CUTOVER_LAB_PASS'
        return
    fi
    if [ "${1:-}" = rehearsal-crash-recovery ]; then
        [ "$#" -eq 1 ] \
            || fail 'rehearsal-crash-recovery lab selector accepts no additional arguments'
        with_lab rehearsal-crash-recovery 33 scenario_rehearsal_crash_recovery
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_REHEARSAL_CRASH_RECOVERY_LAB_PASS'
        return
    fi
    if [ "${1:-}" = restore-rehearsal-crash-recovery ]; then
        [ "$#" -eq 1 ] \
            || fail 'restore-rehearsal-crash-recovery lab selector accepts no additional arguments'
        with_lab restore-rehearsal-crash-recovery 34 scenario_restore_rehearsal_crash_recovery
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_RESTORE_REHEARSAL_CRASH_RECOVERY_LAB_PASS'
        return
    fi
    if [ "${1:-}" = candidate-start-crash ]; then
        [ "$#" -eq 1 ] || fail 'candidate-start-crash lab selector accepts no additional arguments'
        with_lab candidate-start-crash 25 scenario_candidate_start_crash_converges
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_CANDIDATE_START_CRASH_LAB_PASS'
        return
    fi
    if [ "${1:-}" = mutation-freeze-atomicity ]; then
        [ "$#" -eq 1 ] || fail 'mutation-freeze-atomicity lab selector accepts no additional arguments'
        with_lab mutation-freeze-atomicity 35 scenario_mutation_freeze_lease_activation_is_atomic
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_MUTATION_FREEZE_ATOMICITY_LAB_PASS'
        return
    fi
    if [ "${1:-}" = forward-fence ]; then
        [ "$#" -eq 1 ] || fail 'forward-fence lab selector accepts no additional arguments'
        with_lab forward-fence 26 scenario_forward_runtime_fence_promotion
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_FORWARD_RUNTIME_FENCE_LAB_PASS'
        return
    fi
    if [ "${1:-}" = reverse-fence-rollback ]; then
        [ "$#" -eq 1 ] || fail 'reverse-fence-rollback lab selector accepts no additional arguments'
        with_lab reverse-fence-rollback 27 scenario_reverse_runtime_fence_rollback
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_REVERSE_RUNTIME_FENCE_ROLLBACK_LAB_PASS'
        return
    fi
    if [ "${1:-}" = rollback-incumbent-adoption ]; then
        [ "$#" -eq 1 ] || fail 'rollback-incumbent-adoption lab selector accepts no additional arguments'
        with_lab rollback-incumbent-adoption 28 scenario_blue_remove_crash_rolls_back_with_adoption
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_ROLLBACK_INCUMBENT_ADOPTION_LAB_PASS'
        return
    fi
    if [ "${1:-}" = directional-recovery ]; then
        [ "$#" -eq 1 ] || fail 'directional-recovery lab selector accepts no additional arguments'
        with_lab directional-recovery 29 scenario_irreversible_directional_recovery
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_DIRECTIONAL_RECOVERY_LAB_PASS'
        return
    fi
    if [ "${1:-}" = backup-quiesce ]; then
        [ "$#" -eq 1 ] || fail 'backup-quiesce lab selector accepts no additional arguments'
        with_lab backup-quiesce 23 scenario_backup_quiesce_fence
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_BACKUP_QUIESCE_LAB_PASS'
        return
    fi
    if [ "${1:-}" = blue-revoke-crash ]; then
        [ "$#" -eq 1 ] || fail 'blue-revoke-crash lab selector accepts no additional arguments'
        with_lab blue-revoke-crash 10 scenario_blue_revoke_crash
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_BLUE_REVOKE_CRASH_LAB_PASS'
        return
    fi
    if [ "${1:-}" = blue-stop-crash-converges ]; then
        [ "$#" -eq 1 ] || fail 'blue-stop-crash-converges lab selector accepts no additional arguments'
        with_lab blue-stop-crash-converges 16 scenario_blue_stop_crash_converges
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_BLUE_STOP_CRASH_CONVERGES_LAB_PASS'
        return
    fi
    if [ "${1:-}" = green-marker-crash-and-failback ]; then
        [ "$#" -eq 1 ] || fail 'green-marker-crash-and-failback lab selector accepts no additional arguments'
        with_lab green-marker-crash-and-failback 13 scenario_green_marker_crash_and_failback
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_GREEN_MARKER_CRASH_AND_FAILBACK_LAB_PASS'
        return
    fi
    if [ "${1:-}" = green-promotion-failure ]; then
        [ "$#" -eq 1 ] || fail 'green-promotion-failure lab selector accepts no additional arguments'
        with_lab green-promotion-failure 12 scenario_green_promotion_failure
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_GREEN_PROMOTION_FAILURE_LAB_PASS'
        return
    fi
    if [ "${1:-}" = continuous-availability ]; then
        [ "$#" -eq 1 ] || fail 'continuous-availability lab selector accepts no additional arguments'
        with_lab continuous-availability 30 scenario_continuous_forward_reverse_availability
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_CONTINUOUS_AVAILABILITY_LAB_PASS'
        return
    fi
    if [ "${1:-}" = routed-candidate-restart ]; then
        [ "$#" -eq 1 ] || fail 'routed-candidate-restart lab selector accepts no additional arguments'
        with_lab routed-candidate-restart 31 scenario_routed_candidate_process_restart_recovers_service
        remove_mock_image
        rm -rf "$LAB_ROOT"
        printf '%s\n' 'CONTROL_PLANE_ROUTED_CANDIDATE_RESTART_LAB_PASS'
        return
    fi
    [ "$#" -eq 0 ] || fail 'unknown control-plane Blue/Green lab selector'

    with_lab queue-gate-and-rehearsal 1 scenario_queue_gate_and_rehearsal
    with_lab crash-after-migration-artifact 2 scenario_crash_after_migration_artifact
    with_lab migration-terminal-cutover 32 scenario_terminal_migration_crash_direct_cutover
    with_lab rehearsal-crash-recovery 33 scenario_rehearsal_crash_recovery
    with_lab restore-rehearsal-crash-recovery 34 scenario_restore_rehearsal_crash_recovery
    with_lab unknown-migration-outcome-retries 3 scenario_unknown_migration_outcome_retries
    with_lab failed-migration-retries-only-with-identical-input 4 scenario_failed_migration_retries_only_with_identical_input
    with_lab lock-timeout-resumes-blue 5 scenario_lock_timeout_resumes_blue
    with_lab stale-epoch 6 scenario_stale_epoch
    with_lab concurrent-operator 7 scenario_concurrent_operator
    with_lab router-reload-failure 8 scenario_router_reload_failure
    with_lab route-switch-crash 9 scenario_route_switch_crash
    with_lab blue-revoke-crash 10 scenario_blue_revoke_crash
    with_lab blue-stop-failure 11 scenario_blue_stop_failure
    with_lab green-promotion-failure 12 scenario_green_promotion_failure
    with_lab green-marker-crash-and-failback 13 scenario_green_marker_crash_and_failback
    with_lab restart-persistence-and-failback 14 scenario_restart_persistence_and_failback
    with_lab partial-or-wrong-batch-migration-blocks 15 \
        scenario_partial_or_wrong_batch_migration_blocks
    with_lab blue-stop-crash-converges 16 scenario_blue_stop_crash_converges
    with_lab blue-remove-crash-rolls-back-with-adoption 28 scenario_blue_remove_crash_rolls_back_with_adoption
    with_lab directional-recovery 29 scenario_irreversible_directional_recovery
    with_lab green-marker-unlink-crash-converges 17 scenario_green_marker_unlink_crash_converges
    with_lab writer-marker-and-restart-crashes-converge 18 scenario_writer_marker_and_restart_crashes_converge
    with_lab routed-replacement-blue-restart-converges 19 scenario_routed_replacement_blue_restart_converges
    with_lab initial-operation-directory-crash-converges 20 scenario_initial_operation_directory_crash_converges
    with_lab candidate-start-crash 25 scenario_candidate_start_crash_converges
    with_lab mutation-freeze-atomicity 35 scenario_mutation_freeze_lease_activation_is_atomic
    with_lab mutation-lease-missing-race 36 scenario_mutation_lease_missing_race_is_rejected
    with_lab mutation-lease-replacement-race 37 \
        scenario_mutation_lease_replacement_race_is_rejected
    with_lab forward-fence 26 scenario_forward_runtime_fence_promotion
    with_lab reverse-fence-rollback 27 scenario_reverse_runtime_fence_rollback
    with_lab continuous-availability 30 scenario_continuous_forward_reverse_availability
    with_lab routed-candidate-restart 31 scenario_routed_candidate_process_restart_recovers_service
    with_lab drain-waits-for-scheduler-child-and-active-work 21 scenario_drain_waits_for_scheduler_child_and_active_work
    with_lab runtime-env-artifact-attestation 22 scenario_runtime_env_artifact_attestation
    with_lab backup-quiesce 23 scenario_backup_quiesce_fence

    remove_mock_image
    rm -rf "$LAB_ROOT"
    printf '%s\n' 'CONTROL_PLANE_BLUE_GREEN_LAB_PASS'
}

trap cleanup_on_exit EXIT
trap 'exit_from_signal 129' HUP
trap 'exit_from_signal 130' INT
trap 'exit_from_signal 143' TERM
main "$@"
