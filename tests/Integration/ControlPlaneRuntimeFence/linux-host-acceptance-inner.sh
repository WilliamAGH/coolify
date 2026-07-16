#!/usr/bin/env bash

# Runs only as PID-1's child inside the disposable Ubuntu 24.04 systemd/DinD
# container created by linux-host-acceptance.sh. The adapter is intentionally
# required to create a real Coolify/Traefik stack; this runner never replaces
# installed probes or returns synthetic terminal/provider/queue observations.

set -Eeuo pipefail
umask 077

readonly REPOSITORY_ROOT=/workspace
readonly TEST_DIRECTORY=$REPOSITORY_ROOT/tests/Integration/ControlPlaneRuntimeFence
readonly INSTALLER=$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/install-runtime-attestation-ssh-fence.sh
readonly PROVISIONER=/usr/local/sbin/coolify-runtime-fence-provision
readonly CONTROLLER=/usr/local/libexec/coolify-runtime-attestation-ssh-fence
readonly CONFIG_DIRECTORY=/etc/coolify-runtime-attestation-ssh-fence
readonly RUNTIME_ENV=$CONFIG_DIRECTORY/runtime.env
readonly HOST_GATE_DIRECTORY=/var/lib/coolify-runtime-fence-host-gate
readonly EVIDENCE_DIRECTORY=/evidence
readonly PHASE_FILE=$EVIDENCE_DIRECTORY/runtime-fence-host.phase
readonly REBOOT_RECORD=$EVIDENCE_DIRECTORY/runtime-fence-host.reboot-record
readonly PROVISION_LOCK=/run/lock/coolify-runtime-attestation-ssh-fence-provision.lock
readonly RESTORE_SERVICE=coolify-runtime-attestation-ssh-fence.service
readonly WATCHDOG_SERVICE=coolify-runtime-attestation-ssh-fence-watchdog.service
readonly STACK_ADAPTER_DIRECTORY=/usr/local/libexec/coolify-runtime-fence-live-stack
readonly STACK_ADAPTER=$STACK_ADAPTER_DIRECTORY/live-stack-adapter.sh
readonly IMAGE_TRANSPORT_SOURCE=$TEST_DIRECTORY/live-stack-image-transport.bash
readonly IMAGE_TRANSPORT_DIRECTORY=/usr/local/libexec/coolify-runtime-fence-image-transport
readonly IMAGE_TRANSPORT=$IMAGE_TRANSPORT_DIRECTORY/live-stack-image-transport.bash

declare -A STACK=()

fail()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_INNER_HOST_FAILURE %s\n' "$1" >&2
    exit 1
}

blocked()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_INNER_HOST_BLOCKED %s\n' "$1" >&2
    exit 78
}

require_command()
{
    command -v "$1" >/dev/null 2>&1 || blocked "required command is unavailable: $1"
}

assert_identifier()
{
    [[ $2 =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]] \
        || fail "$1 is not a safe identifier"
}

assert_digest_reference()
{
    [[ $2 =~ ^[A-Za-z0-9][A-Za-z0-9._/:@-]*@sha256:[a-f0-9]{64}$ ]] \
        || blocked "$1 is not a digest-pinned immutable image reference"
}

assert_image_digest()
{
    [[ $2 =~ ^sha256:[a-f0-9]{64}$ ]] \
        || blocked "$1 is not an immutable inner image config digest"
}

assert_sha256_evidence()
{
    [[ $2 =~ ^[a-f0-9]{64}$ ]] \
        || fail "$1 is not a SHA-256 evidence value"
}

assert_loaded_transport_image()
{
    local role=$1 image=$2

    assert_image_digest "$role inner image" "$image"
    docker image inspect "$image" >/dev/null \
        || blocked "inner Docker is missing the imported $role image"
    [[ $(docker image inspect --format '{{.Id}}' "$image") == "$image" \
        && $(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$image") == linux/amd64 ]] \
        || fail "inner Docker transport image content or platform differs for $role"
}

assert_absolute_path()
{
    [[ $2 == /* && $2 != *'//'*
        && $2 != '/..' && $2 != '/../'* && $2 != *'/../'* && $2 != *'/..' \
        && $2 =~ ^/[A-Za-z0-9_./-]+$ ]] \
        || fail "$1 is not a safe absolute path"
}

sha256_file()
{
    sha256sum "$1" | awk '{print $1}'
}

file_metadata()
{
    stat -c '%u:%g:%a:%s' "$1"
}

publish_root_file()
{
    local destination=$1 candidate=$2
    [[ -f $candidate && ! -L $candidate ]] || fail 'root publication candidate is unsafe'
    chown root:root "$candidate"
    chmod 0600 "$candidate"
    sync "$candidate"
    mv -f -- "$candidate" "$destination"
    sync "$(dirname -- "$destination")"
    [[ -f $destination && ! -L $destination && $(file_metadata "$destination") == 0:0:600:* ]] \
        || fail 'root publication did not preserve the required file identity'
}

publish_phase()
{
    local phase=$1 candidate=$EVIDENCE_DIRECTORY/.runtime-fence-host.phase.$$
    install -d -m 0700 -o root -g root "$EVIDENCE_DIRECTORY"
    printf '%s\n' "$phase" > "$candidate"
    publish_root_file "$PHASE_FILE" "$candidate"
}

phase_value()
{
    [[ -f $PHASE_FILE && ! -L $PHASE_FILE && $(file_metadata "$PHASE_FILE") == 0:0:600:* ]] \
        || fail 'host-gate phase record is unsafe'
    tr -d '\n' < "$PHASE_FILE"
}

stack_contract_path()
{
    printf '%s/%s/live-stack.env\n' "$HOST_GATE_DIRECTORY" "$1"
}

stack_operation_directory()
{
    printf '%s/%s\n' "$HOST_GATE_DIRECTORY" "$1"
}

stack_contract_keys()
{
    printf '%s\n' \
        version operation_id proxy_container blue_web_a_container blue_web_b_container \
        green_web_a_container green_web_b_container proxy_image_reference \
        web_a_image_reference web_b_image_reference image_transport_operation_id \
        image_transport_manifest_sha256 web_archive_sha256 web_inner_image_digest \
        web_contract_sha256 proxy_archive_sha256 proxy_inner_image_digest \
        proxy_contract_sha256 web_a_network_ids web_b_network_ids \
        escape_network_name coordination_volume blue_web_a_private_volume \
        blue_web_b_private_volume green_web_a_private_volume green_web_b_private_volume \
        pool_label_key pool_label_value web_a_route_identity web_b_route_identity \
        web_a_loopback_port web_b_loopback_port backend_port writer_member runtime_uid runtime_gid \
        mutation_freeze_epoch mutation_freeze_marker_path mutation_lease_path \
        web_a_web_epoch web_b_web_epoch web_a_route_drain_epoch web_b_route_drain_epoch \
        web_a_writer_epoch web_a_writer_marker_path route_health_token_file \
        route_health_runtime_file pool_ack_file pool_ack_runtime_file \
        web_a_direct_probe_token_file web_a_direct_probe_runtime_file \
        web_b_direct_probe_token_file web_b_direct_probe_runtime_file \
        web_a_applied_ack_file web_a_applied_ack_runtime_file \
        web_b_applied_ack_file web_b_applied_ack_runtime_file web_a_ingress_address \
        web_b_ingress_address terminal_https_url terminal_port8000_url management_endpoints \
        self_ssh_target provider_api_url provider_router provider_service provider_legacy_port \
        provider_header_file
}

stack_value()
{
    local key=$1
    [[ -n ${STACK[$key]+present} ]] || fail "live stack contract is missing key: $key"
    printf '%s' "${STACK[$key]}"
}

load_stack_contract()
{
    local operation=$1 contract key value line expected actual
    local -A seen=()

    contract=$(stack_contract_path "$operation")
    [[ -f $contract && ! -L $contract && $(file_metadata "$contract") == 0:0:600:* ]] \
        || fail 'live stack contract is absent or unsafe'
    expected=$(stack_contract_keys)
    actual=$(sed 's/=.*//' "$contract")
    [[ $actual == "$expected" && $(wc -l < "$contract") -eq $(wc -l <<< "$expected") ]] \
        || fail 'live stack contract keys are reordered, duplicate, absent, or unknown'
    STACK=()
    while IFS= read -r line || [[ -n $line ]]; do
        key=${line%%=*}
        value=${line#*=}
        [[ -n $key && $line == *=* && -z ${seen[$key]+present} && -n $value \
            && $value != *$'\r'* && $value != *$'\n'* ]] \
            || fail 'live stack contract contains an unsafe value'
        STACK[$key]=$value
        seen[$key]=1
    done < "$contract"
    [[ $(stack_value version) == 1 && $(stack_value operation_id) == "$operation" ]] \
        || fail 'live stack contract does not belong to this operation'
}

assert_stack_contract()
{
    local value
    local -a identifiers=(
        proxy_container blue_web_a_container blue_web_b_container green_web_a_container
        green_web_b_container escape_network_name coordination_volume blue_web_a_private_volume
        blue_web_b_private_volume green_web_a_private_volume green_web_b_private_volume
        pool_label_key pool_label_value
    )
    local -a authorities=(
        mutation_freeze_epoch web_a_route_identity web_b_route_identity web_a_web_epoch web_b_web_epoch
        web_a_route_drain_epoch web_b_route_drain_epoch web_a_writer_epoch
    )
    local -a paths=(
        mutation_freeze_marker_path mutation_lease_path web_a_writer_marker_path
        route_health_token_file route_health_runtime_file pool_ack_file pool_ack_runtime_file
        web_a_direct_probe_token_file web_a_direct_probe_runtime_file
        web_b_direct_probe_token_file web_b_direct_probe_runtime_file
        web_a_applied_ack_file web_a_applied_ack_runtime_file
        web_b_applied_ack_file web_b_applied_ack_runtime_file
        provider_header_file
    )

    [[ $(stack_value proxy_image_reference) == "$CONTROL_PLANE_RUNTIME_PROXY_IMAGE" \
        && $(stack_value web_a_image_reference) == "$CONTROL_PLANE_RUNTIME_WEB_A_IMAGE" \
        && $(stack_value web_b_image_reference) == "$CONTROL_PLANE_RUNTIME_WEB_B_IMAGE" ]] \
        || fail 'live stack contract substituted an image for an outer immutable input'
    assert_digest_reference proxy-image "$(stack_value proxy_image_reference)"
    assert_digest_reference web-a-image "$(stack_value web_a_image_reference)"
    assert_digest_reference web-b-image "$(stack_value web_b_image_reference)"
    [[ $(stack_value image_transport_operation_id) == "$CONTROL_PLANE_RUNTIME_IMAGE_TRANSPORT_OPERATION" \
        && $(stack_value image_transport_operation_id) == "$CONTROL_PLANE_RUNTIME_HOST_GATE_OPERATION" \
        && $(stack_value image_transport_manifest_sha256) \
            == "$CONTROL_PLANE_RUNTIME_IMAGE_TRANSPORT_MANIFEST_SHA256" \
        && $(stack_value web_archive_sha256) == "$CONTROL_PLANE_RUNTIME_WEB_ARCHIVE_SHA256" \
        && $(stack_value proxy_archive_sha256) == "$CONTROL_PLANE_RUNTIME_PROXY_ARCHIVE_SHA256" \
        && $(stack_value web_inner_image_digest) == "$CONTROL_PLANE_RUNTIME_WEB_INNER_IMAGE_DIGEST" \
        && $(stack_value proxy_inner_image_digest) == "$CONTROL_PLANE_RUNTIME_PROXY_INNER_IMAGE_DIGEST" \
        && $(stack_value web_contract_sha256) == "$CONTROL_PLANE_RUNTIME_WEB_CONTRACT_SHA256" \
        && $(stack_value proxy_contract_sha256) == "$CONTROL_PLANE_RUNTIME_PROXY_CONTRACT_SHA256" ]] \
        || fail 'live stack contract does not bind the immutable image transport evidence'
    assert_sha256_evidence image-transport-manifest "$(stack_value image_transport_manifest_sha256)"
    assert_sha256_evidence web-archive "$(stack_value web_archive_sha256)"
    assert_sha256_evidence proxy-archive "$(stack_value proxy_archive_sha256)"
    assert_sha256_evidence web-content-contract "$(stack_value web_contract_sha256)"
    assert_sha256_evidence proxy-content-contract "$(stack_value proxy_contract_sha256)"
    assert_loaded_transport_image web "$(stack_value web_inner_image_digest)"
    assert_loaded_transport_image proxy "$(stack_value proxy_inner_image_digest)"
    for value in "${identifiers[@]}"; do
        assert_identifier "stack $value" "$(stack_value "$value")"
    done
    for value in "${authorities[@]}"; do
        [[ $(stack_value "$value") =~ ^[A-Za-z0-9._:-]{16,128}$ ]] \
            || fail "stack $value is not an authority token"
    done
    for value in "${paths[@]}"; do
        assert_absolute_path "stack $value" "$(stack_value "$value")"
    done
    [[ $(stack_value web_a_network_ids) =~ ^[a-f0-9]{64}(,[a-f0-9]{64})*$ \
        && $(stack_value web_b_network_ids) =~ ^[a-f0-9]{64}(,[a-f0-9]{64})*$ ]] \
        || fail 'stack candidate network inventory is malformed'
    [[ $(stack_value writer_member) == web-a \
        && $(stack_value runtime_uid) =~ ^[0-9]+$ \
        && $(stack_value runtime_gid) =~ ^[0-9]+$ \
        && $(stack_value backend_port) =~ ^[1-9][0-9]{0,4}$ \
        && $(stack_value web_a_loopback_port) =~ ^[1-9][0-9]{0,4}$ \
        && $(stack_value web_b_loopback_port) =~ ^[1-9][0-9]{0,4}$ ]] \
        || fail 'stack writer, ownership, or port contract is malformed'
    [[ $(stack_value terminal_https_url) == https://127.0.0.1:8443/api/control-plane/route-health \
        && $(stack_value terminal_port8000_url) == http://127.0.0.1:8000/api/control-plane/route-health ]] \
        || fail 'stack terminal endpoints differ from the isolated loopback contract'
    [[ $(stack_value management_endpoints) == lo=127.0.0.1 \
        && $(stack_value self_ssh_target) == 127.0.0.1 ]] \
        || fail 'stack management or self-SSH endpoint differs from the disposable-host contract'
    [[ $(stack_value provider_api_url) =~ ^http://(127\.0\.0\.1|\[::1\]):[1-9][0-9]{0,4}/api/rawdata$ \
        && $(stack_value provider_router) =~ ^[A-Za-z0-9_.-]+@docker$ \
        && $(stack_value provider_service) =~ ^[A-Za-z0-9_.-]+@docker$ \
        && $(stack_value provider_legacy_port) =~ ^[1-9][0-9]{0,4}$ \
        && -f $(stack_value provider_header_file) \
        && ! -L $(stack_value provider_header_file) \
        && $(file_metadata "$(stack_value provider_header_file)") == 0:0:600:* ]] \
        || fail 'stack Traefik provider contract is malformed'
    [[ $(printf '%s\n' \
        "$(stack_value coordination_volume)" \
        "$(stack_value blue_web_a_private_volume)" \
        "$(stack_value blue_web_b_private_volume)" \
        "$(stack_value green_web_a_private_volume)" \
        "$(stack_value green_web_b_private_volume)" | LC_ALL=C sort -u | wc -l | tr -d '[:space:]') == 5 ]] \
        || fail 'stack must declare four distinct private volumes and one distinct coordination volume'
}

copy_stack_adapter()
{
    local source_directory component source

    [[ $CONTROL_PLANE_RUNTIME_LIVE_STACK_COMMAND \
        == /workspace/tests/Integration/ControlPlaneRuntimeFence/live-stack-adapter.sh \
        && -f $CONTROL_PLANE_RUNTIME_LIVE_STACK_COMMAND \
        && ! -L $CONTROL_PLANE_RUNTIME_LIVE_STACK_COMMAND \
        && -x $CONTROL_PLANE_RUNTIME_LIVE_STACK_COMMAND ]] \
        || blocked 'the reviewed live stack adapter is not available inside /workspace'
    source_directory=$(dirname -- "$CONTROL_PLANE_RUNTIME_LIVE_STACK_COMMAND")
    install -d -m 0700 -o root -g root "$STACK_ADAPTER_DIRECTORY"
    for component in live-stack-adapter.sh live-stack-contract.bash live-stack-bootstrap.bash live-stack-runtime.bash; do
        source=$source_directory/$component
        [[ -f $source && ! -L $source ]] \
            || blocked "the reviewed live stack component is absent or unsafe: $component"
        install -m 0700 -o root -g root "$source" "$STACK_ADAPTER_DIRECTORY/$component"
    done
}

copy_image_transport()
{
    [[ -f $IMAGE_TRANSPORT_SOURCE && ! -L $IMAGE_TRANSPORT_SOURCE ]] \
        || blocked 'the reviewed immutable image transport is not available inside /workspace'
    install -d -m 0700 -o root -g root "$IMAGE_TRANSPORT_DIRECTORY"
    install -m 0700 -o root -g root "$IMAGE_TRANSPORT_SOURCE" "$IMAGE_TRANSPORT"
    # shellcheck disable=SC1090 # The transport was copied from the reviewed read-only source tree.
    source "$IMAGE_TRANSPORT"
}

stack_action()
{
    local action=$1 operation=$2 contract

    # The reviewed adapter receives ACTION OPERATION CONTRACT. It must publish
    # the exact ordered contract after bootstrap, support start-green,
    # start-green-wrong-coordination-mount, start-green-wrong-private-volume,
    # remove-retired, lease/marker negative actions, and cleanup. Its stack is
    # real only when the supplied immutable images, TLS, :8000, provider, queue,
    # and self-SSH observations are available; absent inputs must fail, not stub.
    contract=$(stack_contract_path "$operation")
    install -d -m 0700 -o root -g root "$(stack_operation_directory "$operation")"
    env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
        CONTROL_PLANE_RUNTIME_HOST_GATE_OPERATION="$operation" \
        CONTROL_PLANE_RUNTIME_HOST_GATE_ROOT="$(stack_operation_directory "$operation")" \
        CONTROL_PLANE_RUNTIME_WEB_A_IMAGE="$CONTROL_PLANE_RUNTIME_WEB_A_IMAGE" \
        CONTROL_PLANE_RUNTIME_WEB_B_IMAGE="$CONTROL_PLANE_RUNTIME_WEB_B_IMAGE" \
        CONTROL_PLANE_RUNTIME_PROXY_IMAGE="$CONTROL_PLANE_RUNTIME_PROXY_IMAGE" \
        CONTROL_PLANE_RUNTIME_IMAGE_TRANSPORT_OPERATION="$CONTROL_PLANE_RUNTIME_IMAGE_TRANSPORT_OPERATION" \
        CONTROL_PLANE_RUNTIME_IMAGE_TRANSPORT_MANIFEST_SHA256="$CONTROL_PLANE_RUNTIME_IMAGE_TRANSPORT_MANIFEST_SHA256" \
        CONTROL_PLANE_RUNTIME_WEB_INNER_IMAGE_DIGEST="$CONTROL_PLANE_RUNTIME_WEB_INNER_IMAGE_DIGEST" \
        CONTROL_PLANE_RUNTIME_PROXY_INNER_IMAGE_DIGEST="$CONTROL_PLANE_RUNTIME_PROXY_INNER_IMAGE_DIGEST" \
        CONTROL_PLANE_RUNTIME_WEB_ARCHIVE_SHA256="$CONTROL_PLANE_RUNTIME_WEB_ARCHIVE_SHA256" \
        CONTROL_PLANE_RUNTIME_PROXY_ARCHIVE_SHA256="$CONTROL_PLANE_RUNTIME_PROXY_ARCHIVE_SHA256" \
        CONTROL_PLANE_RUNTIME_WEB_CONTRACT_SHA256="$CONTROL_PLANE_RUNTIME_WEB_CONTRACT_SHA256" \
        CONTROL_PLANE_RUNTIME_PROXY_CONTRACT_SHA256="$CONTROL_PLANE_RUNTIME_PROXY_CONTRACT_SHA256" \
        "$STACK_ADAPTER" "$action" "$operation" "$contract"
}

assert_disposable_host()
{
    local os_identity

    [[ $(id -u) -eq 0 && -d /run/systemd/system \
        && $(ps -p 1 -o comm= | tr -d '[:space:]') == systemd ]] \
        || fail 'inner runner requires a root Ubuntu systemd PID-1 container'
    os_identity=$(awk -F= '$1 == "ID" { id = $2 } $1 == "VERSION_ID" { version = $2 } END { print id ":" version }' \
        /etc/os-release)
    [[ $os_identity == ubuntu:24.04 ]] \
        || fail 'inner runner is pinned to Ubuntu 24.04'
    [[ $(dpkg-query -W -f='${Version}' nftables 2>/dev/null || true) == 1.0.9-1ubuntu0.1 \
        && $(dpkg-query -W -f='${Version}' conntrack 2>/dev/null || true) == 1:1.4.8-1ubuntu1 ]] \
        || fail 'inner host does not have the installer-pinned nftables and conntrack versions'
    [[ ! -e /usr/local/bin/systemctl && ! -e /usr/local/bin/ssh-keyscan ]] \
        || fail 'inner host refuses systemctl or ssh-keyscan replacement shims'
    [[ ${CONTROL_PLANE_RUNTIME_TEST_MODE:-0} == 0 \
        && ${CONTROL_PLANE_RUNTIME_PROVISION_TEST_MODE:-0} == 0 ]] \
        || fail 'inner production path forbids controller and provisioner test mode'
    for command in conntrack curl docker flock ip ipcalc jq nft nsenter openssl sha256sum ssh ssh-keyscan \
        systemctl unshare; do
        require_command "$command"
    done
    systemctl is-active --quiet coolify-runtime-fence-host-boot.service \
        || fail 'boot-generation unit is not active'
    systemctl is-active --quiet docker.service || fail 'nested Docker service is not active'
    systemctl is-active --quiet ssh.service || fail 'inner SSH service is not active'
    [[ -f /run/coolify-runtime-fence-host-boot-generation \
        && ! -L /run/coolify-runtime-fence-host-boot-generation \
        && $(file_metadata /run/coolify-runtime-fence-host-boot-generation) == 0:0:400:* ]] \
        || fail 'inner /run boot-generation marker is absent or unsafe'
    docker info >/dev/null || fail 'nested Docker daemon is unavailable'
    ssh -F /dev/null -i /root/.ssh/id_ed25519 -o BatchMode=yes -o ConnectTimeout=3 \
        -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@127.0.0.1 true >/dev/null \
        || blocked 'inner SSH loopback cannot authenticate with the boot-generated real self-SSH identity'
}

ensure_immutable_images()
{
    local reference

    [[ $CONTROL_PLANE_RUNTIME_WEB_A_IMAGE == "$CONTROL_PLANE_RUNTIME_WEB_B_IMAGE" ]] \
        || blocked 'the inner host requires one immutable Coolify/Laravel digest for all four web members'
    for reference in "$CONTROL_PLANE_RUNTIME_WEB_A_IMAGE" "$CONTROL_PLANE_RUNTIME_PROXY_IMAGE"; do
        assert_digest_reference image "$reference"
    done
}

import_immutable_images()
{
    image_transport_import_inner "$1" "$CONTROL_PLANE_RUNTIME_IMAGE_TRANSPORT_MANIFEST_SHA256" \
        "$CONTROL_PLANE_RUNTIME_WEB_A_IMAGE" "$CONTROL_PLANE_RUNTIME_PROXY_IMAGE"
}

restore_immutable_images()
{
    image_transport_restore_inner "$1" "$CONTROL_PLANE_RUNTIME_IMAGE_TRANSPORT_MANIFEST_SHA256" \
        "$CONTROL_PLANE_RUNTIME_WEB_A_IMAGE" "$CONTROL_PLANE_RUNTIME_PROXY_IMAGE"
}

assert_installed_assets()
{
    local source destination
    local -a assets=(
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/runtime-attestation-ssh-fence.sh:$CONTROLLER"
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/provision-runtime-attestation-ssh-fence.sh:$PROVISIONER"
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/traefik-docker-provider-freshness-probe.sh:/usr/local/libexec/coolify-traefik-provider-freshness-probe"
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/proxy-queue-zero-probe.sh:/usr/local/libexec/coolify-proxy-queue-zero-probe"
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/control-plane-terminal-state-probe.sh:/usr/local/libexec/coolify-control-plane-terminal-state-probe"
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/self-ssh-controlmaster-reaper.sh:/usr/local/libexec/coolify-self-ssh-controlmaster-reaper"
    )

    "$INSTALLER" | grep -F -x -q \
        'CONTROL_PLANE_RUNTIME_FENCE_INSTALL complete=true armed=false services_enabled=false' \
        || fail 'real runtime-fence installer did not report an unarmed installation'
    for source in "${assets[@]}"; do
        destination=${source#*:}
        source=${source%%:*}
        [[ -f $destination && ! -L $destination && $(file_metadata "$destination") == 0:0:700:* \
            && $(sha256_file "$destination") == "$(sha256_file "$source")" ]] \
            || fail "installed production asset differs from reviewed source: $destination"
    done
    systemd-analyze verify \
        /etc/systemd/system/$RESTORE_SERVICE \
        /etc/systemd/system/$WATCHDOG_SERVICE
}

assert_exact_volume_mount()
{
    local container=$1 private_volume=$2 coordination_volume=$3

    docker inspect "$container" | jq --exit-status --arg private "$private_volume" \
        --arg coordination "$coordination_volume" '
        .[0].Mounts
        | [ .[] | select(.Destination == "/var/lib/coolify-control-plane/private") ] as $private_mount
        | [ .[] | select(.Destination == "/var/lib/coolify-control-plane/coordination") ] as $coordination_mount
        | ($private_mount | length) == 1
            and ($private_mount[0].Type == "volume")
            and ($private_mount[0].Name == $private)
            and ($private_mount[0].RW == true)
            and ($coordination_mount | length) == 1
            and ($coordination_mount[0].Type == "volume")
            and ($coordination_mount[0].Name == $coordination)
            and ($coordination_mount[0].RW == true)
    ' >/dev/null || fail "member mount contract differs from the five-volume plan: $container"
}

assert_bootstrap_stack()
{
    local container reference private_volume

    load_stack_contract "$1"
    assert_stack_contract
    for container in proxy_container blue_web_a_container blue_web_b_container; do
        [[ $(docker inspect --format '{{.State.Running}}' "$(stack_value "$container")") == true ]] \
            || fail "real bootstrap stack container is not running: $container"
    done
    [[ $(docker inspect --format '{{.Config.Image}}' "$(stack_value proxy_container)") \
        == "$(stack_value proxy_inner_image_digest)" ]] \
        || fail 'bootstrap proxy does not use the imported immutable Traefik image digest'
    for container in blue_web_a_container blue_web_b_container; do
        [[ $(docker inspect --format '{{.Config.Image}}' "$(stack_value "$container")") \
            == "$(stack_value web_inner_image_digest)" ]] \
            || fail "bootstrap $container does not use the imported immutable Coolify image digest"
    done
    for container in green_web_a_container green_web_b_container; do
        ! docker container inspect "$(stack_value "$container")" >/dev/null 2>&1 \
            || fail "green pool must be absent during prepare/capture/arm: $container"
    done
    for private_volume in coordination_volume blue_web_a_private_volume blue_web_b_private_volume \
        green_web_a_private_volume green_web_b_private_volume; do
        docker volume inspect "$(stack_value "$private_volume")" >/dev/null \
            || fail "stack volume is absent: $private_volume"
    done
    assert_exact_volume_mount "$(stack_value blue_web_a_container)" \
        "$(stack_value blue_web_a_private_volume)" "$(stack_value coordination_volume)"
    assert_exact_volume_mount "$(stack_value blue_web_b_container)" \
        "$(stack_value blue_web_b_private_volume)" "$(stack_value coordination_volume)"
    curl --fail --silent --show-error --max-time 5 "$(stack_value terminal_https_url)" >/dev/null \
        || blocked 'real TLS terminal endpoint is unavailable before the fence is armed'
    curl --fail --silent --show-error --max-time 5 "$(stack_value terminal_port8000_url)" >/dev/null \
        || blocked 'real loopback :8000 terminal endpoint is unavailable before the fence is armed'
}

set_live_fixture_environment()
{
    local operation=$1 fixture_root
    fixture_root=$(stack_operation_directory "$operation")/fixture
    install -d -m 0700 -o root -g root "$fixture_root"

    LIVE_HOST_ROOT=$fixture_root
    LIVE_HOST_OPERATION_ID=$operation
    LIVE_HOST_GENERATION=1
    LIVE_HOST_COORDINATION_VOLUME=$(stack_value coordination_volume)
    LIVE_HOST_MUTATION_FREEZE_EPOCH=$(stack_value mutation_freeze_epoch)
    LIVE_HOST_MUTATION_FREEZE_MARKER_PATH=$(stack_value mutation_freeze_marker_path)
    LIVE_HOST_MUTATION_LEASE_PATH=$(stack_value mutation_lease_path)
    LIVE_HOST_BACKEND_PORT=$(stack_value backend_port)
    LIVE_HOST_POOL_LABEL_KEY=$(stack_value pool_label_key)
    LIVE_HOST_POOL_LABEL_VALUE=$(stack_value pool_label_value)
    LIVE_HOST_WRITER_MEMBER=$(stack_value writer_member)
    LIVE_HOST_RUNTIME_UID=$(stack_value runtime_uid)
    LIVE_HOST_RUNTIME_GID=$(stack_value runtime_gid)
    LIVE_HOST_GREEN_WEB_A_CONTAINER=$(stack_value green_web_a_container)
    LIVE_HOST_GREEN_WEB_B_CONTAINER=$(stack_value green_web_b_container)
    LIVE_HOST_BLUE_WEB_A_CONTAINER=$(stack_value blue_web_a_container)
    LIVE_HOST_BLUE_WEB_B_CONTAINER=$(stack_value blue_web_b_container)
    LIVE_HOST_GREEN_WEB_A_PRIVATE_VOLUME=$(stack_value green_web_a_private_volume)
    LIVE_HOST_GREEN_WEB_B_PRIVATE_VOLUME=$(stack_value green_web_b_private_volume)
    LIVE_HOST_BLUE_WEB_A_PRIVATE_VOLUME=$(stack_value blue_web_a_private_volume)
    LIVE_HOST_BLUE_WEB_B_PRIVATE_VOLUME=$(stack_value blue_web_b_private_volume)
    LIVE_HOST_WEB_A_IMAGE_REFERENCE=$(stack_value web_a_image_reference)
    LIVE_HOST_WEB_B_IMAGE_REFERENCE=$(stack_value web_b_image_reference)
    LIVE_HOST_WEB_A_NETWORK_IDS=$(stack_value web_a_network_ids)
    LIVE_HOST_WEB_B_NETWORK_IDS=$(stack_value web_b_network_ids)
    LIVE_HOST_WEB_A_ROUTE_IDENTITY=$(stack_value web_a_route_identity)
    LIVE_HOST_WEB_B_ROUTE_IDENTITY=$(stack_value web_b_route_identity)
    LIVE_HOST_WEB_A_LOOPBACK_PORT=$(stack_value web_a_loopback_port)
    LIVE_HOST_WEB_B_LOOPBACK_PORT=$(stack_value web_b_loopback_port)
    LIVE_HOST_WEB_A_WEB_EPOCH=$(stack_value web_a_web_epoch)
    LIVE_HOST_WEB_B_WEB_EPOCH=$(stack_value web_b_web_epoch)
    LIVE_HOST_WEB_A_ROUTE_DRAIN_EPOCH=$(stack_value web_a_route_drain_epoch)
    LIVE_HOST_WEB_B_ROUTE_DRAIN_EPOCH=$(stack_value web_b_route_drain_epoch)
    LIVE_HOST_WEB_A_WRITER_EPOCH=$(stack_value web_a_writer_epoch)
    LIVE_HOST_WEB_A_WRITER_MARKER_PATH=$(stack_value web_a_writer_marker_path)
    LIVE_HOST_ROUTE_HEALTH_TOKEN_FILE=$(stack_value route_health_token_file)
    LIVE_HOST_ROUTE_HEALTH_RUNTIME_FILE=$(stack_value route_health_runtime_file)
    LIVE_HOST_POOL_ACK_FILE=$(stack_value pool_ack_file)
    LIVE_HOST_POOL_ACK_RUNTIME_FILE=$(stack_value pool_ack_runtime_file)
    LIVE_HOST_WEB_A_DIRECT_PROBE_TOKEN_FILE=$(stack_value web_a_direct_probe_token_file)
    LIVE_HOST_WEB_A_DIRECT_PROBE_RUNTIME_FILE=$(stack_value web_a_direct_probe_runtime_file)
    LIVE_HOST_WEB_B_DIRECT_PROBE_TOKEN_FILE=$(stack_value web_b_direct_probe_token_file)
    LIVE_HOST_WEB_B_DIRECT_PROBE_RUNTIME_FILE=$(stack_value web_b_direct_probe_runtime_file)
    LIVE_HOST_WEB_A_APPLIED_ACK_FILE=$(stack_value web_a_applied_ack_file)
    LIVE_HOST_WEB_A_APPLIED_ACK_RUNTIME_FILE=$(stack_value web_a_applied_ack_runtime_file)
    LIVE_HOST_WEB_B_APPLIED_ACK_FILE=$(stack_value web_b_applied_ack_file)
    LIVE_HOST_WEB_B_APPLIED_ACK_RUNTIME_FILE=$(stack_value web_b_applied_ack_runtime_file)
    LIVE_HOST_WEB_A_INGRESS_ADDRESS=$(stack_value web_a_ingress_address)
    LIVE_HOST_WEB_B_INGRESS_ADDRESS=$(stack_value web_b_ingress_address)
    export LIVE_HOST_ROOT LIVE_HOST_OPERATION_ID LIVE_HOST_GENERATION LIVE_HOST_COORDINATION_VOLUME
    export LIVE_HOST_MUTATION_FREEZE_EPOCH LIVE_HOST_MUTATION_FREEZE_MARKER_PATH LIVE_HOST_MUTATION_LEASE_PATH
    export LIVE_HOST_BACKEND_PORT LIVE_HOST_POOL_LABEL_KEY LIVE_HOST_POOL_LABEL_VALUE LIVE_HOST_WRITER_MEMBER
    export LIVE_HOST_RUNTIME_UID LIVE_HOST_RUNTIME_GID LIVE_HOST_GREEN_WEB_A_CONTAINER LIVE_HOST_GREEN_WEB_B_CONTAINER
    export LIVE_HOST_BLUE_WEB_A_CONTAINER LIVE_HOST_BLUE_WEB_B_CONTAINER LIVE_HOST_GREEN_WEB_A_PRIVATE_VOLUME
    export LIVE_HOST_GREEN_WEB_B_PRIVATE_VOLUME LIVE_HOST_BLUE_WEB_A_PRIVATE_VOLUME LIVE_HOST_BLUE_WEB_B_PRIVATE_VOLUME
    export LIVE_HOST_WEB_A_IMAGE_REFERENCE LIVE_HOST_WEB_B_IMAGE_REFERENCE LIVE_HOST_WEB_A_NETWORK_IDS
    export LIVE_HOST_WEB_B_NETWORK_IDS LIVE_HOST_WEB_A_ROUTE_IDENTITY LIVE_HOST_WEB_B_ROUTE_IDENTITY
    export LIVE_HOST_WEB_A_LOOPBACK_PORT LIVE_HOST_WEB_B_LOOPBACK_PORT LIVE_HOST_WEB_A_WEB_EPOCH
    export LIVE_HOST_WEB_B_WEB_EPOCH LIVE_HOST_WEB_A_ROUTE_DRAIN_EPOCH LIVE_HOST_WEB_B_ROUTE_DRAIN_EPOCH
    export LIVE_HOST_WEB_A_WRITER_EPOCH LIVE_HOST_WEB_A_WRITER_MARKER_PATH
    export LIVE_HOST_ROUTE_HEALTH_TOKEN_FILE LIVE_HOST_ROUTE_HEALTH_RUNTIME_FILE LIVE_HOST_POOL_ACK_FILE
    export LIVE_HOST_POOL_ACK_RUNTIME_FILE LIVE_HOST_WEB_A_DIRECT_PROBE_TOKEN_FILE
    export LIVE_HOST_WEB_A_DIRECT_PROBE_RUNTIME_FILE LIVE_HOST_WEB_B_DIRECT_PROBE_TOKEN_FILE
    export LIVE_HOST_WEB_B_DIRECT_PROBE_RUNTIME_FILE LIVE_HOST_WEB_A_APPLIED_ACK_FILE
    export LIVE_HOST_WEB_A_APPLIED_ACK_RUNTIME_FILE LIVE_HOST_WEB_B_APPLIED_ACK_FILE
    export LIVE_HOST_WEB_B_APPLIED_ACK_RUNTIME_FILE LIVE_HOST_WEB_A_INGRESS_ADDRESS LIVE_HOST_WEB_B_INGRESS_ADDRESS
}

# shellcheck disable=SC1091 # Runtime path is fixed by TEST_DIRECTORY above.
source "$TEST_DIRECTORY/live-host-fixture.bash"

write_semantic_configuration()
{
    local operation=$1 root configuration candidate

    set_live_fixture_environment "$operation"
    prepare_live_control_plane_pool_plan_fixture
    root=$(stack_operation_directory "$operation")
    configuration=$root/semantic.env
    candidate=$root/.semantic.env.$$
    {
        printf 'CONTROL_PLANE_RUNTIME_OPERATION_ID=%s\n' "$operation"
        printf 'CONTROL_PLANE_RUNTIME_PROXY_CONTAINER=%s\n' "$(stack_value proxy_container)"
        printf 'CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST=%s\n' "$LIVE_HOST_POOL_PLAN_MANIFEST"
        printf 'CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256=%s\n' "$LIVE_HOST_POOL_PLAN_SHA256"
        printf 'CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA=%s\n' "$LIVE_HOST_POOL_PLAN_METADATA"
        printf 'CONTROL_PLANE_RUNTIME_MANAGEMENT_ENDPOINTS=%s\n' "$(stack_value management_endpoints)"
        printf 'CONTROL_PLANE_RUNTIME_ADDITIONAL_NETWORK_IDS=\n'
        printf 'CONTROL_PLANE_RUNTIME_SELF_SSH_TARGET=%s\n' "$(stack_value self_ssh_target)"
        printf 'CONTROL_PLANE_RUNTIME_PROBE_MAX_AGE_SECONDS=30\n'
        printf 'CONTROL_PLANE_RUNTIME_QUEUE_STABLE_SECONDS=1\n'
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_CAPTURE_EXPECTATION=incumbent\n'
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_API_URL=%s\n' "$(stack_value provider_api_url)"
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER=%s\n' "$(stack_value provider_router)"
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE=%s\n' "$(stack_value provider_service)"
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT=%s\n' "$(stack_value provider_legacy_port)"
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_HEADER_FILE=%s\n' "$(stack_value provider_header_file)"
        printf 'CONTROL_PLANE_RUNTIME_TERMINAL_HTTPS_URL=%s\n' "$(stack_value terminal_https_url)"
        printf 'CONTROL_PLANE_RUNTIME_TERMINAL_PORT8000_URL=%s\n' \
            "$(stack_value terminal_port8000_url)"
    } > "$candidate"
    publish_root_file "$configuration" "$candidate"
    printf '%s\n' "$configuration"
}

assert_armed_runtime()
{
    nft list table inet coolify_control_plane_ssh_fence >/dev/null \
        || fail 'armed runtime fence table is absent'
    grep -F -x -q CONTROL_PLANE_RUNTIME_ARMED=1 "$RUNTIME_ENV" \
        || fail 'runtime.env did not persist armed state'
    systemctl is-active --quiet "$RESTORE_SERVICE" \
        || fail 'restore service is not active while the fence is armed'
    systemctl is-active --quiet "$WATCHDOG_SERVICE" \
        || fail 'watchdog service is not active while the fence is armed'
    [[ -L /etc/systemd/system/docker.service.requires/$RESTORE_SERVICE \
        && -L /etc/systemd/system/docker.socket.requires/$RESTORE_SERVICE \
        && -L /etc/systemd/system/docker.service.wants/$WATCHDOG_SERVICE ]] \
        || fail 'armed runtime fence is missing a Docker activation dependency'
}

assert_terminal_runtime()
{
    local phase=$1 output

    output=$($PROVISIONER status)
    [[ $output == *"phase=$phase"* ]] \
        || fail "runtime controller is not terminal phase $phase"
    grep -F -x -q CONTROL_PLANE_RUNTIME_ARMED=0 "$RUNTIME_ENV" \
        || fail 'terminal runtime.env still carries an armed fence'
    ! nft list table inet coolify_control_plane_ssh_fence >/dev/null 2>&1 \
        || fail 'terminal runtime fence table still exists'
    for service in "$RESTORE_SERVICE" "$WATCHDOG_SERVICE"; do
        ! systemctl is-active --quiet "$service" \
            || fail "terminal service is still active: $service"
        ! systemctl is-enabled --quiet "$service" \
            || fail "terminal service is still enabled: $service"
    done
    [[ ! -e /etc/systemd/system/docker.service.requires/$RESTORE_SERVICE \
        && ! -e /etc/systemd/system/docker.socket.requires/$RESTORE_SERVICE \
        && ! -e /etc/systemd/system/docker.service.wants/$WATCHDOG_SERVICE ]] \
        || fail 'terminal runtime fence still has a Docker activation link'
}

assert_provisioner_serializes()
{
    local marker=/run/runtime-fence-host-gate-provision-lock-held
    local output=/run/runtime-fence-host-gate-provision-lock.out lock_holder status

    rm -f -- "$marker" "$output"
    (
        exec 9>"$PROVISION_LOCK"
        flock -x 9
        touch "$marker"
        sleep 2
    ) &
    lock_holder=$!
    for _ in {1..20}; do
        [[ -e $marker ]] && break
        sleep 0.1
    done
    [[ -e $marker ]] || fail 'concurrent provisioner fixture did not acquire the exact transaction lock'
    set +e
    "$PROVISIONER" verify > "$output" 2>&1
    status=$?
    set -e
    [[ $status -ne 0 ]] || fail 'concurrent provisioner invocation was not rejected'
    grep -F -q 'another runtime-fence provisioner transaction is active' "$output" \
        || fail 'concurrent provisioner refusal did not identify the transaction lock'
    wait "$lock_holder"
    rm -f -- "$marker" "$output"
}

setup_armed_operation()
{
    local operation=$1 configuration

    stack_action bootstrap "$operation"
    assert_bootstrap_stack "$operation"
    configuration=$(write_semantic_configuration "$operation")
    "$PROVISIONER" prepare "$configuration" \
        | grep -F -x -q 'CONTROL_PLANE_RUNTIME_FENCE_PROVISION prepared=true armed=false'
    "$PROVISIONER" capture >/dev/null
    "$PROVISIONER" arm \
        | grep -F -x -q 'CONTROL_PLANE_RUNTIME_FENCE_PROVISION prepared=true armed=true services_active=true'
    assert_armed_runtime
    "$PROVISIONER" verify >/dev/null
}

cleanup_stack()
{
    stack_action cleanup "$1"
}

expect_verify_tuple_drift()
{
    local operation=$1 output status

    output=$(stack_operation_directory "$operation")/verify-tuple-drift.out
    set +e
    "$PROVISIONER" verify > "$output" 2>&1
    status=$?
    set -e
    [[ $status -ne 0 ]] || fail 'runtime verification incorrectly survived a changed dockerd tuple'
    grep -F -q 'runtime identity changed:' "$output" \
        || fail 'runtime verification did not identify the changed dockerd lifecycle tuple'
}

run_abort_scenario()
{
    local operation=$1

    setup_armed_operation "$operation"
    assert_provisioner_serializes
    "$PROVISIONER" abort \
        | grep -F -x -q 'CONTROL_PLANE_RUNTIME_FENCE_PROVISION terminal=abort armed=false services_active=false'
    assert_terminal_runtime aborted
    cleanup_stack "$operation"
}

run_same_boot_dockerd_recovery()
{
    local operation=$1

    setup_armed_operation "$operation"
    systemctl restart docker.service
    for _ in {1..60}; do
        if systemctl is-active --quiet "$RESTORE_SERVICE" \
            && systemctl is-active --quiet "$WATCHDOG_SERVICE" \
            && nft list table inet coolify_control_plane_ssh_fence >/dev/null 2>&1; then
            break
        fi
        sleep 1
    done
    assert_armed_runtime
    expect_verify_tuple_drift "$operation"
    "$PROVISIONER" recover-abort \
        | grep -F -x -q 'CONTROL_PLANE_RUNTIME_FENCE_PROVISION terminal=recover-abort armed=false services_active=false'
    assert_terminal_runtime recovery-aborted
    cleanup_stack "$operation"
}

publish_reboot_record()
{
    local operation=$1 candidate=$EVIDENCE_DIRECTORY/.runtime-fence-host.reboot-record.$$
    local generation marker_identity restore_enter docker_enter watchdog_enter

    generation=$(tr -d '\n' < /evidence/boot-generation)
    marker_identity=$(stat -c '%d:%i' /run/coolify-runtime-fence-host-boot-generation)
    restore_enter=$(systemctl show --property=ActiveEnterTimestampMonotonic --value "$RESTORE_SERVICE")
    docker_enter=$(systemctl show --property=ActiveEnterTimestampMonotonic --value docker.service)
    watchdog_enter=$(systemctl show --property=ActiveEnterTimestampMonotonic --value "$WATCHDOG_SERVICE")
    {
        printf 'operation=%s\n' "$operation"
        printf 'generation=%s\n' "$generation"
        printf 'run_marker_identity=%s\n' "$marker_identity"
        printf 'restore_enter=%s\n' "$restore_enter"
        printf 'docker_enter=%s\n' "$docker_enter"
        printf 'watchdog_enter=%s\n' "$watchdog_enter"
    } > "$candidate"
    publish_root_file "$REBOOT_RECORD" "$candidate"
}

reboot_record_value()
{
    local key=$1 count value
    [[ -f $REBOOT_RECORD && ! -L $REBOOT_RECORD && $(file_metadata "$REBOOT_RECORD") == 0:0:600:* ]] \
        || fail 'reboot record is absent or unsafe'
    count=$(grep -E -c "^${key}=" "$REBOOT_RECORD" || true)
    [[ $count -eq 1 ]] || fail "reboot record lacks exactly one $key"
    value=$(sed -n "s/^${key}=//p" "$REBOOT_RECORD")
    [[ -n $value && $value != *$'\n'* ]] || fail "reboot record value is unsafe: $key"
    printf '%s' "$value"
}

request_actual_systemd_reboot()
{
    local operation=$1

    publish_reboot_record "$operation"
    publish_phase reboot-pending
    sync "$EVIDENCE_DIRECTORY"
    systemctl reboot
    fail 'systemctl reboot returned without crossing the disposable-container boundary'
}

assert_actual_reboot_boundary()
{
    local before_generation after_generation before_marker after_marker boot_enter restore_enter docker_enter watchdog_enter

    before_generation=$(reboot_record_value generation)
    before_marker=$(reboot_record_value run_marker_identity)
    after_generation=$(tr -d '\n' < /evidence/boot-generation)
    after_marker=$(stat -c '%d:%i' /run/coolify-runtime-fence-host-boot-generation)
    [[ $before_generation =~ ^[0-9]+$ && $after_generation =~ ^[0-9]+$ \
        && $after_generation -gt $before_generation && $after_marker != "$before_marker" ]] \
        || fail 'systemctl reboot did not create a new persistent boot generation and a fresh /run marker'

    boot_enter=$(systemctl show --property=ActiveEnterTimestampMonotonic --value \
        coolify-runtime-fence-host-boot.service)
    restore_enter=$(systemctl show --property=ActiveEnterTimestampMonotonic --value "$RESTORE_SERVICE")
    docker_enter=$(systemctl show --property=ActiveEnterTimestampMonotonic --value docker.service)
    watchdog_enter=$(systemctl show --property=ActiveEnterTimestampMonotonic --value "$WATCHDOG_SERVICE")
    [[ $boot_enter =~ ^[1-9][0-9]*$ && $restore_enter =~ ^[1-9][0-9]*$ \
        && $docker_enter =~ ^[1-9][0-9]*$ && $watchdog_enter =~ ^[1-9][0-9]*$ \
        && $boot_enter -le $restore_enter && $restore_enter -le $docker_enter \
        && $docker_enter -le $watchdog_enter ]] \
        || fail 'reboot ordering did not prove boot generation, fence restore, Docker, then watchdog activation'
    assert_armed_runtime
}

container_network_sha256()
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
    ' | sha256sum | awk '{print $1}'
}

container_bindings_sha256()
{
    docker inspect "$1" | jq -S -c '.[0] | {
        configured_bindings: (.HostConfig.PortBindings // {}),
        published_bindings: (.NetworkSettings.Ports // {})
    }' | sha256sum | awk '{print $1}'
}

assert_member_ssh_timeout()
{
    local container=$1 gateway=$2 output=/run/runtime-fence-host-gate-ssh.out status

    set +e
    docker exec "$container" timeout 5 ssh -F /dev/null -o BatchMode=yes \
        -o ConnectTimeout=2 -o ConnectionAttempts=1 -o StrictHostKeyChecking=no \
        -o UserKnownHostsFile=/dev/null -p 22 "root@$gateway" true \
        > /dev/null 2> "$output"
    status=$?
    set -e
    [[ $status -eq 255 && $(<"$output") == *'timed out'* ]] \
        || blocked "real member SSH denial is unavailable or escaped: container=$container gateway=$gateway status=$status"
    rm -f -- "$output"
}

assert_escape_network_denied()
{
    local container=$1 network=$2 gateway ipv4_seen=0 ipv6_seen=0

    while IFS= read -r gateway; do
        [[ -n $gateway ]] || continue
        assert_member_ssh_timeout "$container" "$gateway"
        if [[ $gateway == *:* ]]; then
            ipv6_seen=1
        else
            ipv4_seen=1
        fi
    done < <(docker network inspect "$network" | jq -r '.[0].IPAM.Config[]?.Gateway // empty')
    [[ $ipv4_seen == 1 && $ipv6_seen == 1 ]] \
        || blocked 'escape network must expose both IPv4 and IPv6 gateways for the real denial proof'
}

expect_failure()
{
    local label=$1 pattern=$2 output
    shift 2
    output=$(mktemp /run/runtime-fence-host-gate-failure.XXXXXX)
    if "$@" > "$output" 2>&1; then
        rm -f -- "$output"
        fail "$label unexpectedly succeeded"
    fi
    grep -E -q "$pattern" "$output" \
        || {
            cat "$output" >&2
            rm -f -- "$output"
            fail "$label did not report the expected refusal"
        }
    rm -f -- "$output"
}

start_green_pool_and_pin()
{
    local operation=$1 web_a web_b web_a_id web_b_id
    local web_a_runtime web_b_runtime web_a_network web_b_network web_a_bindings web_b_bindings

    stack_action start-green "$operation"
    load_stack_contract "$operation"
    assert_stack_contract
    web_a=$(stack_value green_web_a_container)
    web_b=$(stack_value green_web_b_container)
    [[ $(docker inspect --format '{{.State.Running}}' "$web_a") == true \
        && $(docker inspect --format '{{.State.Running}}' "$web_b") == true ]] \
        || blocked 'real stack adapter did not start both green web members'
    assert_exact_volume_mount "$web_a" "$(stack_value green_web_a_private_volume)" \
        "$(stack_value coordination_volume)"
    assert_exact_volume_mount "$web_b" "$(stack_value green_web_b_private_volume)" \
        "$(stack_value coordination_volume)"
    "$PROVISIONER" assert-pool-baseline >/dev/null
    "$PROVISIONER" prove-pool-denied >/dev/null

    web_a_runtime=$($PROVISIONER container-runtime-sha256 "$web_a")
    web_b_runtime=$($PROVISIONER container-runtime-sha256 "$web_b")
    web_a_network=$(container_network_sha256 "$web_a")
    web_b_network=$(container_network_sha256 "$web_b")
    web_a_bindings=$(container_bindings_sha256 "$web_a")
    web_b_bindings=$(container_bindings_sha256 "$web_b")
    write_live_control_plane_ingress_pool_fixture \
        "$web_a_runtime" "$web_a_network" "$web_a_bindings" \
        "$web_b_runtime" "$web_b_network" "$web_b_bindings"
    "$PROVISIONER" pin-ingress-pool-manifest "$LIVE_HOST_INGRESS_POOL_SHA256" >/dev/null

    web_a_id=$(live_host_fixture_container_id "$web_a")
    web_b_id=$(live_host_fixture_container_id "$web_b")
    write_live_host_repin_intents "$web_a_id" "$web_b_id"
    docker update --restart always "$web_a" "$web_b" >/dev/null
    "$PROVISIONER" repin-pool "$web_a_id" "$web_b_id" >/dev/null
    "$PROVISIONER" verify >/dev/null
}

exercise_member_network_drift()
{
    local operation=$1 member_key=$2 container network

    load_stack_contract "$operation"
    case "$member_key" in
        a) container=$(stack_value green_web_a_container) ;;
        b) container=$(stack_value green_web_b_container) ;;
        *) fail 'network drift can only target canonical web-a or web-b' ;;
    esac
    network=$(stack_value escape_network_name)
    docker network inspect "$network" >/dev/null \
        || blocked 'live stack adapter did not prepare the isolated dual-stack escape network'
    docker network connect "$network" "$container"
    assert_escape_network_denied "$container" "$network"
    expect_failure "web-$member_key escape-network verification" 'network|candidate|pool' \
        "$PROVISIONER" verify
    docker network disconnect "$network" "$container"
    "$PROVISIONER" verify >/dev/null
}

remove_retired_pool_and_verify()
{
    local operation=$1 blue_a blue_b blue_a_id blue_b_id

    blue_a=$(sed -n 's/^retired_member_a_name=//p' "$LIVE_HOST_POOL_PLAN_MANIFEST")
    blue_b=$(sed -n 's/^retired_member_b_name=//p' "$LIVE_HOST_POOL_PLAN_MANIFEST")
    blue_a_id=$(sed -n 's/^retired_member_a_id=//p' "$LIVE_HOST_POOL_PLAN_MANIFEST")
    blue_b_id=$(sed -n 's/^retired_member_b_id=//p' "$LIVE_HOST_POOL_PLAN_MANIFEST")
    stack_action remove-retired "$operation"
    if docker container inspect "$blue_a" >/dev/null 2>&1 \
        || docker container inspect "$blue_b" >/dev/null 2>&1 \
        || docker container inspect "$blue_a_id" >/dev/null 2>&1 \
        || docker container inspect "$blue_b_id" >/dev/null 2>&1; then
        fail 'post-revoke stack still exposes a retired member by exact name or ID'
    fi
    "$PROVISIONER" verify-post-revoke >/dev/null
}

exercise_live_coordination_negatives()
{
    local operation=$1

    stack_action hold-mutation-lease "$operation"
    expect_failure 'exclusive mutation lease release gate' 'lease|acquire|mutation' "$PROVISIONER" release
    stack_action release-mutation-lease "$operation"
    stack_action corrupt-mutation-marker "$operation"
    expect_failure 'mutation marker release gate' 'marker|mutation|freeze' "$PROVISIONER" release
    stack_action restore-mutation-marker "$operation"
}

run_mount_topology_negative()
{
    local operation=$1 mode=$2

    setup_armed_operation "$operation"
    stack_action "start-green-$mode" "$operation"
    expect_failure "$mode pool baseline" 'mount|volume|private|coordination' \
        "$PROVISIONER" assert-pool-baseline
    "$PROVISIONER" abort >/dev/null
    assert_terminal_runtime aborted
    cleanup_stack "$operation"
}

prepare_released_operation()
{
    local operation=$1 include_coordination_negatives=$2

    setup_armed_operation "$operation"
    start_green_pool_and_pin "$operation"
    exercise_member_network_drift "$operation" a
    exercise_member_network_drift "$operation" b
    remove_retired_pool_and_verify "$operation"
    if [[ $include_coordination_negatives == 1 ]]; then
        exercise_live_coordination_negatives "$operation"
    fi
    "$PROVISIONER" release >/dev/null
    $PROVISIONER status | grep -F -q 'phase=released' \
        || fail 'real release did not publish terminal released controller state'
    ! nft list table inet coolify_control_plane_ssh_fence >/dev/null 2>&1 \
        || fail 'real release did not remove the runtime fence table'
}

assert_released_final_invariants()
{
    local operation=$1 web_a web_b blue_a blue_b

    load_stack_contract "$operation"
    web_a=$(stack_value green_web_a_container)
    web_b=$(stack_value green_web_b_container)
    blue_a=$(stack_value blue_web_a_container)
    blue_b=$(stack_value blue_web_b_container)
    "$PROVISIONER" verify-released >/dev/null
    grep -F -x -q CONTROL_PLANE_RUNTIME_ARMED=0 "$RUNTIME_ENV" \
        || fail 'released finalization did not leave runtime.env unarmed'
    ! nft list table inet coolify_control_plane_ssh_fence >/dev/null 2>&1 \
        || fail 'released finalization recreated the managed nft table'
    for service in "$RESTORE_SERVICE" "$WATCHDOG_SERVICE"; do
        ! systemctl is-active --quiet "$service" \
            || fail "released finalization left service active: $service"
        ! systemctl is-enabled --quiet "$service" \
            || fail "released finalization left service enabled: $service"
    done
    [[ ! -e /etc/systemd/system/docker.service.requires/$RESTORE_SERVICE \
        && ! -e /etc/systemd/system/docker.socket.requires/$RESTORE_SERVICE \
        && ! -e /etc/systemd/system/docker.service.wants/$WATCHDOG_SERVICE ]] \
        || fail 'released finalization left a Docker fence dependency link'
    if docker container inspect "$blue_a" >/dev/null 2>&1 \
        || docker container inspect "$blue_b" >/dev/null 2>&1; then
        fail 'released finalization retained a retired pool member'
    fi
    [[ $(docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' "$web_a") == always \
        && $(docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' "$web_b") == always ]] \
        || fail 'released pool members do not retain their required always restart policy'
    assert_exact_volume_mount "$web_a" "$(stack_value green_web_a_private_volume)" \
        "$(stack_value coordination_volume)"
    assert_exact_volume_mount "$web_b" "$(stack_value green_web_b_private_volume)" \
        "$(stack_value coordination_volume)"
    ! docker inspect "$web_a" | jq -e --arg network "$(stack_value escape_network_name)" \
        '.[0].NetworkSettings.Networks | has($network)' >/dev/null \
        || fail 'released web-a retained the escape network'
    ! docker inspect "$web_b" | jq -e --arg network "$(stack_value escape_network_name)" \
        '.[0].NetworkSettings.Networks | has($network)' >/dev/null \
        || fail 'released web-b retained the escape network'
}

finalization_seams()
{
    awk '
        /^finalize_terminal_action\(\)/ { inside = 1; next }
        inside && /^[[:space:]]*test_crash[[:space:]]+/ { print $2 }
        inside && /^}/ { exit }
    ' "$PROVISIONER"
}

run_finalization_seams()
{
    local base_operation=$1 sequence=0 operation seam status
    local -a seams=()

    [[ ${CONTROL_PLANE_RUNTIME_HOST_GATE_FINALIZE_SEAMS:-1} == 0 \
        || ${CONTROL_PLANE_RUNTIME_HOST_GATE_FINALIZE_SEAMS:-1} == 1 ]] \
        || fail 'finalization-seam switch must be exactly 0 or 1'
    [[ ${CONTROL_PLANE_RUNTIME_HOST_GATE_FINALIZE_SEAMS:-1} == 1 ]] || return
    mapfile -t seams < <(finalization_seams)
    ((${#seams[@]} > 0)) \
        || blocked 'installed provisioner exposes no deterministic finalize-terminal crash seam'
    for seam in "${seams[@]}"; do
        sequence=$((sequence + 1))
        operation=${base_operation}-finalize-$sequence
        setup_armed_operation "$operation"
        start_green_pool_and_pin "$operation"
        remove_retired_pool_and_verify "$operation"
        "$PROVISIONER" release >/dev/null
        set +e
        CONTROL_PLANE_RUNTIME_PROVISION_TEST_MODE=1 \
            CONTROL_PLANE_RUNTIME_PROVISION_TEST_CRASH_AT="$seam" \
            "$PROVISIONER" finalize-release >/dev/null 2>&1
        status=$?
        set -e
        [[ $status -eq 86 ]] \
            || fail "provisioner finalization seam did not crash deterministically: $seam status=$status"
        $PROVISIONER status | grep -F -q 'phase=released' \
            || fail "finalization crash changed controller terminal phase: $seam"
        ! nft list table inet coolify_control_plane_ssh_fence >/dev/null 2>&1 \
            || fail "finalization crash revived the released fence table: $seam"
        "$PROVISIONER" finalize-release >/dev/null
        assert_released_final_invariants "$operation"
        cleanup_stack "$operation"
    done
}

run_release_scenarios()
{
    local base_operation=$1
    local normal_operation=$base_operation-release

    run_mount_topology_negative "$base_operation-wrong-coordination" wrong-coordination-mount
    run_mount_topology_negative "$base_operation-wrong-private" wrong-private-volume
    prepare_released_operation "$normal_operation" 1
    "$PROVISIONER" finalize-release >/dev/null
    assert_released_final_invariants "$normal_operation"
    cleanup_stack "$normal_operation"
    run_finalization_seams "$base_operation"
}

run_initial()
{
    local base_operation=$1
    local reboot_operation=$base_operation-reboot

    run_abort_scenario "$base_operation-abort"
    run_same_boot_dockerd_recovery "$base_operation-dockerd-recovery"
    setup_armed_operation "$reboot_operation"
    request_actual_systemd_reboot "$reboot_operation"
}

run_resume()
{
    local base_operation=$1 reboot_operation

    [[ $(phase_value) == reboot-pending ]] \
        || fail 'outer runner did not resume from the expected reboot-pending phase'
    reboot_operation=$(reboot_record_value operation)
    [[ $reboot_operation == "$base_operation-reboot" ]] \
        || fail 'reboot record does not belong to the requested host-gate operation'
    assert_actual_reboot_boundary
    expect_verify_tuple_drift "$reboot_operation"
    "$PROVISIONER" recover-abort >/dev/null
    assert_terminal_runtime recovery-aborted
    cleanup_stack "$reboot_operation"
    run_release_scenarios "$base_operation"
    publish_phase complete
}

usage()
{
    printf '%s\n' 'usage: linux-host-acceptance-inner.sh {--run|--resume} HOST_GATE_OPERATION'
}

[[ $# -eq 2 ]] || {
    usage >&2
    exit 64
}
mode=$1
operation=$2
[[ $mode == --run || $mode == --resume ]] || {
    usage >&2
    exit 64
}
assert_identifier host-gate-operation "$operation"
for required in \
    CONTROL_PLANE_RUNTIME_HOST_GATE_OPERATION CONTROL_PLANE_RUNTIME_LIVE_STACK_COMMAND \
    CONTROL_PLANE_RUNTIME_WEB_A_IMAGE \
    CONTROL_PLANE_RUNTIME_WEB_B_IMAGE CONTROL_PLANE_RUNTIME_PROXY_IMAGE \
    CONTROL_PLANE_RUNTIME_IMAGE_TRANSPORT_MANIFEST_SHA256; do
    [[ -n ${!required:-} ]] || blocked "inner runner is missing immutable real-stack input: $required"
done
[[ $CONTROL_PLANE_RUNTIME_HOST_GATE_OPERATION == "$operation" ]] \
    || fail 'inner host-gate operation environment differs from the requested operation'

assert_disposable_host
copy_image_transport
copy_stack_adapter
if [[ $mode == --run ]]; then
    ensure_immutable_images
    import_immutable_images "$operation"
    assert_installed_assets
    run_initial "$operation"
else
    ensure_immutable_images
    restore_immutable_images "$operation"
    run_resume "$operation"
fi
