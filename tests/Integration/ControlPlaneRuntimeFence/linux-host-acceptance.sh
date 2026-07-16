#!/usr/bin/env bash

# The outer runner is intentionally unable to change the caller's firewall,
# systemd state, Docker socket mounts, or networks. It creates a disposable
# PID-1 Ubuntu host that owns an isolated nested Docker daemon instead.

set -Eeuo pipefail
umask 077

TEST_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
readonly TEST_DIRECTORY
REPOSITORY_ROOT=$(cd -- "$TEST_DIRECTORY/../../.." && pwd -P)
readonly REPOSITORY_ROOT
readonly INNER_RUNNER=/workspace/tests/Integration/ControlPlaneRuntimeFence/linux-host-acceptance-inner.sh
readonly HOST_DOCKERFILE=$TEST_DIRECTORY/Dockerfile.systemd-ubuntu24
readonly HOST_BOOT_SCRIPT=$TEST_DIRECTORY/systemd-host-boot.sh
readonly HOST_BOOT_UNIT=$TEST_DIRECTORY/coolify-runtime-fence-host-boot.service
readonly HOST_BUILD_SCRIPT=$TEST_DIRECTORY/build-systemd-ubuntu24-image.sh
readonly LIVE_STACK_ADAPTER=$TEST_DIRECTORY/live-stack-adapter.sh
readonly IMAGE_TRANSPORT_SOURCE=$TEST_DIRECTORY/live-stack-image-transport.bash
readonly HOST_GATE_READINESS_DEADLINE_SECONDS=90
readonly HOST_GATE_READINESS_DOCKER_CALL_TIMEOUT_SECONDS=5
readonly HOST_GATE_DIAGNOSTIC_DOCKER_CALL_TIMEOUT_SECONDS=15
readonly HOST_GATE_EVIDENCE_EXPORT_TIMEOUT_SECONDS=300
readonly HOST_GATE_CLEANUP_DOCKER_CALL_TIMEOUT_SECONDS=15

fail()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE_FAILURE %s\n' "$1" >&2
    exit 1
}

blocked()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE_BLOCKED %s\n' "$1" >&2
    exit 78
}

[[ -f $IMAGE_TRANSPORT_SOURCE && ! -L $IMAGE_TRANSPORT_SOURCE ]] \
    || fail 'the source-owned immutable image transport is absent or unsafe'
# shellcheck disable=SC1090 # The transport is reviewed source beside this host-gate owner.
source "$IMAGE_TRANSPORT_SOURCE"

source_attestation_sha256()
{
    local path
    {
        for path in "$HOST_DOCKERFILE" "$HOST_BOOT_SCRIPT" "$HOST_BOOT_UNIT" "$HOST_BUILD_SCRIPT"; do
            printf '%s\0%s\0' "${path#"$REPOSITORY_ROOT"/}" "$(sha256sum "$path" | awk '{print $1}')"
        done
    } | sha256sum | awk '{print $1}'
}

assert_digest_reference()
{
    [[ $2 =~ ^[A-Za-z0-9][A-Za-z0-9._/:@-]*@sha256:[a-f0-9]{64}$ ]] \
        || blocked "$1 must be an immutable image reference with @sha256"
}

assert_safe_identifier()
{
    [[ $2 =~ ^[a-z0-9][a-z0-9-]{2,60}$ ]] \
        || fail "$1 is not a safe host-gate identifier"
}

assert_native_amd64_engine_platform()
{
    [[ $1 == linux/amd64 ]] \
        || blocked 'the production host gate requires a native linux/amd64 Docker engine; CPU emulation is not accepted'
}

assert_local_docker_endpoint_contract()
{
    [[ $1 == default && $2 == unix:///var/run/docker.sock ]] \
        || blocked 'the production host gate requires the local default Docker context at unix:///var/run/docker.sock'
}

assert_absolute_directory()
{
    [[ -d $2 && ! -L $2 && $(cd -- "$2" && pwd -P) == "$2" ]] \
        || fail "$1 must be an existing non-symlink absolute directory"
}

assert_local_host_image()
{
    local expected_source_sha=$1 reference=$2 source_sha repo_digests

    docker image inspect "$reference" >/dev/null \
        || blocked 'the digest-pinned systemd host image is not present locally; build it with build-systemd-ubuntu24-image.sh and retain that exact local RepoDigest'
    repo_digests=$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$reference")
    grep -F -x -q "$reference" <<< "$repo_digests" \
        || blocked 'the local host image does not retain the requested immutable repository digest'
    source_sha=$(docker image inspect \
        --format '{{ index .Config.Labels "io.coolify.runtime-fence.systemd-host-source-sha256" }}' \
        "$reference")
    [[ $(docker image inspect \
        --format '{{ index .Config.Labels "io.coolify.runtime-fence.systemd-host" }}' "$reference") == true \
        && $(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$reference") == linux/amd64 \
        && $source_sha == "$expected_source_sha" ]] \
        || blocked 'the digest-pinned host image lacks the exact source attestation for this checkout'
}

assert_stack_adapter()
{
    local source_path=$LIVE_STACK_ADAPTER resolved component

    [[ $source_path == "$REPOSITORY_ROOT"/* && -f $source_path && ! -L $source_path \
        && -x $source_path ]] \
        || fail 'the source-owned live-stack adapter is absent or unsafe'
    resolved=$(cd -- "$(dirname -- "$source_path")" && pwd -P)/$(basename -- "$source_path")
    [[ $resolved == "$source_path" ]] \
        || blocked 'the live stack adapter path contains a symlink'
    for component in live-stack-contract.bash live-stack-bootstrap.bash live-stack-runtime.bash; do
        [[ -f $TEST_DIRECTORY/$component && ! -L $TEST_DIRECTORY/$component ]] \
            || fail "the source-owned live-stack component is absent or unsafe: $component"
    done
    LIVE_STACK_COMMAND_INNER=/workspace/${source_path#"$REPOSITORY_ROOT"/}
    export LIVE_STACK_COMMAND_INNER
}

assert_isolated_container()
{
    bounded_outer_docker "$HOST_GATE_READINESS_DOCKER_CALL_TIMEOUT_SECONDS" inspect "$HOST_CONTAINER" \
        | assert_isolated_container_inspect
}

assert_isolated_container_inspect()
{
    local repository=${1:-$REPOSITORY_ROOT}
    local network=${2:-${OUTER_NETWORK:-}}
    local docker_volume=${3:-${INNER_DOCKER_VOLUME:-}}
    local evidence_volume=${4:-${EVIDENCE_VOLUME:-}}

    jq --exit-status \
        --arg repository "$repository" \
        --arg network "$network" \
        --arg docker_volume "$docker_volume" \
        --arg evidence_volume "$evidence_volume" '
        .[0] as $host
        | ($host.HostConfig.Privileged == true)
            and ($host.HostConfig.CgroupnsMode == "private")
            and ($host.HostConfig.NetworkMode == $network)
            and ($host.HostConfig.PidMode == "")
            and (($host.HostConfig.IpcMode == "") or ($host.HostConfig.IpcMode == "private"))
            and ($host.HostConfig.Tmpfs | has("/run"))
            and ($host.HostConfig.Tmpfs | has("/run/lock"))
            and ($host.HostConfig.Tmpfs | has("/tmp"))
            and ([ $host.Mounts[] | select(.Type == "bind")
                | {source: .Source, destination: .Destination, read_write: .RW} ]
                == [{source: $repository, destination: "/workspace", read_write: false}])
            and (([ $host.Mounts[] | select(.Type == "volume")
                | {name: .Name, destination: .Destination} ] | sort_by(.destination))
                == [
                    {name: $evidence_volume, destination: "/evidence"},
                    {name: $docker_volume, destination: "/var/lib/docker"}
                ])
            and (all($host.Mounts[];
                .Destination != "/var/run/docker.sock"
                and .Destination != "/etc"
                and .Destination != "/var/lib"))
    ' >/dev/null || fail 'outer host container violates the isolated mount or namespace contract'
}

assert_isolated_container_contract_representations()
{
    local ipc_mode tmpfs_options inspect
    local network=runtime-fence-isolation-test-net
    local docker_volume=runtime-fence-isolation-test-inner-docker
    local evidence_volume=runtime-fence-isolation-test-evidence

    for ipc_mode in '' private; do
        for tmpfs_options in '' rw,nosuid,nodev; do
            inspect=$(jq --null-input \
                --arg ipc_mode "$ipc_mode" \
                --arg tmpfs_options "$tmpfs_options" \
                --arg repository "$REPOSITORY_ROOT" \
                --arg network "$network" \
                --arg docker_volume "$docker_volume" \
                --arg evidence_volume "$evidence_volume" '[{
                    HostConfig: {
                        Privileged: true,
                        CgroupnsMode: "private",
                        NetworkMode: $network,
                        PidMode: "",
                        IpcMode: $ipc_mode,
                        Tmpfs: {
                            "/run": $tmpfs_options,
                            "/run/lock": $tmpfs_options,
                            "/tmp": $tmpfs_options
                        }
                    },
                    Mounts: [
                        {Type: "bind", Source: $repository, Destination: "/workspace", RW: false},
                        {Type: "volume", Name: $docker_volume, Destination: "/var/lib/docker"},
                        {Type: "volume", Name: $evidence_volume, Destination: "/evidence"}
                    ]
                }]')
            assert_isolated_container_inspect \
                "$REPOSITORY_ROOT" "$network" "$docker_volume" "$evidence_volume" <<< "$inspect"
        done
    done
    inspect=$(jq '.[0].HostConfig.IpcMode = "host"' <<< "$inspect")
    if (assert_isolated_container_inspect \
        "$REPOSITORY_ROOT" "$network" "$docker_volume" "$evidence_volume" <<< "$inspect") \
        >/dev/null 2>&1; then
        fail 'isolation contract accepted host-shared IPC'
    fi
    inspect=$(jq '.[0].HostConfig.IpcMode = "private" | del(.[0].HostConfig.Tmpfs["/run/lock"])' \
        <<< "$inspect")
    if (assert_isolated_container_inspect \
        "$REPOSITORY_ROOT" "$network" "$docker_volume" "$evidence_volume" <<< "$inspect") \
        >/dev/null 2>&1; then
        fail 'isolation contract accepted a missing required tmpfs mount'
    fi
}

bounded_outer_docker()
{
    local duration=$1

    shift
    timeout --foreground --kill-after=1s "$duration" docker "$@"
}

deadline_remaining_duration()
{
    local deadline=$1 maximum_seconds=$2 now remaining

    now=$(date +%s)
    remaining=$((deadline - now))
    (( remaining > 0 )) || return 1
    if (( remaining > maximum_seconds )); then
        remaining=$maximum_seconds
    fi
    printf '%ss' "$remaining"
}

bounded_outer_docker_before_deadline()
{
    local deadline=$1 duration

    shift
    duration=$(deadline_remaining_duration "$deadline" "$HOST_GATE_READINESS_DOCKER_CALL_TIMEOUT_SECONDS") \
        || return 124
    bounded_outer_docker "$duration" "$@"
}

systemd_host_is_ready()
{
    local deadline=$1

    bounded_outer_docker_before_deadline "$deadline" exec "$HOST_CONTAINER" test -S /run/systemd/private \
        && bounded_outer_docker_before_deadline "$deadline" exec "$HOST_CONTAINER" \
            systemctl is-active --quiet coolify-runtime-fence-host-boot.service \
        && bounded_outer_docker_before_deadline "$deadline" exec "$HOST_CONTAINER" \
            systemctl is-active --quiet docker.service \
        && bounded_outer_docker_before_deadline "$deadline" exec "$HOST_CONTAINER" \
            systemctl is-active --quiet ssh.service \
        && bounded_outer_docker_before_deadline "$deadline" exec "$HOST_CONTAINER" docker info
}

wait_for_systemd_host()
{
    local deadline=$(( $(date +%s) + HOST_GATE_READINESS_DEADLINE_SECONDS ))

    while (( $(date +%s) < deadline )); do
        if systemd_host_is_ready "$deadline" >/dev/null 2>&1; then
            return
        fi
        sleep 1
    done
    capture_systemd_host_readiness_failure
    fail 'disposable Ubuntu systemd host did not make boot, SSH, and nested Docker active'
}

# shellcheck disable=SC2016 # This helper intentionally passes a literal shell program to the immutable host image.
persist_outer_docker_diagnostic()
{
    local filename=$1 source=$2

    case "$filename" in
        outer-container-inspect.json|outer-container-inspect.status|outer-container-logs.txt|outer-container-logs.status) ;;
        *) return 64 ;;
    esac
    [[ -f $source && ! -L $source ]] || return 1
    bounded_outer_docker "$HOST_GATE_DIAGNOSTIC_DOCKER_CALL_TIMEOUT_SECONDS" \
        run --rm --pull never --platform linux/amd64 --network none --entrypoint /bin/sh \
        --mount "type=bind,source=$source,target=/input/diagnostic,readonly" \
        --mount "type=volume,source=$EVIDENCE_VOLUME,target=/evidence" \
        "$CONTROL_PLANE_RUNTIME_HOST_IMAGE" -ec '
            destination=/evidence/$1
            source=/input/diagnostic
            candidate=${destination}.candidate.$$
            case "$1" in
                outer-container-inspect.json|outer-container-inspect.status|outer-container-logs.txt|outer-container-logs.status) ;;
                *) exit 64 ;;
            esac
            test -f "$source" && test ! -L "$source"
            test ! -e "$destination" && test ! -L "$destination"
            umask 077
            cp -- "$source" "$candidate"
            chown root:root "$candidate"
            chmod 0600 "$candidate"
            sync "$candidate"
            mv -f -- "$candidate" "$destination"
            sync /evidence
            test -f "$destination" && test ! -L "$destination"
            test "$(stat -c '\''%u:%g:%a'\'' "$destination")" = 0:0:600
        ' sh "$filename"
}

capture_outer_docker_diagnostic()
{
    local filename=$1 staging_directory candidate status_candidate status persisted=0

    shift
    staging_directory=$(mktemp -d "${TMPDIR:-/tmp}/coolify-runtime-fence-host-diagnostic.XXXXXX") \
        || return 1
    candidate=$staging_directory/$filename
    if bounded_outer_docker "$HOST_GATE_DIAGNOSTIC_DOCKER_CALL_TIMEOUT_SECONDS" "$@" \
        > "$candidate" 2>&1; then
        status=0
    else
        status=$?
    fi
    if [[ $filename == outer-container-inspect.json ]] \
        && ! jq --exit-status . "$candidate" >/dev/null 2>&1; then
        jq --null-input --rawfile output "$candidate" --argjson status "$status" \
            '{schema_version: 1, docker_inspect_exit_status: $status, docker_inspect_output: $output}' \
            > "$candidate.normalized"
        mv -f -- "$candidate.normalized" "$candidate"
    fi
    chmod 0600 "$candidate"
    status_candidate=$staging_directory/${filename%.*}.status
    printf 'outer_docker_exit_status=%s\n' "$status" > "$status_candidate"
    chmod 0600 "$status_candidate"
    if persist_outer_docker_diagnostic "$filename" "$candidate" \
        && persist_outer_docker_diagnostic "${filename%.*}.status" "$status_candidate"; then
        persisted=1
    fi
    rm -f -- "$candidate"
    rm -f -- "$status_candidate"
    rmdir -- "$staging_directory"
    (( persisted == 1 ))
}

# shellcheck disable=SC2016 # This diagnostic program intentionally runs inside the disposable host.
capture_systemd_host_readiness_failure()
{
    if ! capture_outer_docker_diagnostic outer-container-inspect.json inspect "$HOST_CONTAINER"; then
        printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE outer_inspect_evidence=failed\n' >&2
    fi
    if ! capture_outer_docker_diagnostic outer-container-logs.txt logs --tail 400 "$HOST_CONTAINER"; then
        printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE outer_logs_evidence=failed\n' >&2
    fi
    bounded_outer_docker "$HOST_GATE_DIAGNOSTIC_DOCKER_CALL_TIMEOUT_SECONDS" exec "$HOST_CONTAINER" /bin/bash -ec '
        umask 077
        destination=/evidence/host-readiness-diagnostics
        candidate=${destination}.candidate.$$
        test ! -e "$destination" && test ! -L "$destination"
        limit() {
            local duration=$1
            shift
            timeout --foreground --kill-after=1s "$duration" "$@" || true
        }
        {
            limit 4s systemctl is-system-running
            limit 4s systemctl --no-pager --full status \
                coolify-runtime-fence-host-boot.service docker.service docker.socket ssh.service \
            limit 4s systemctl --failed --no-pager --full
            limit 6s journalctl -b --no-pager -u coolify-runtime-fence-host-boot.service \
                -u docker.service -u containerd.service -u ssh.service -n 400 || true
        } > "$candidate" 2>&1
        chown root:root "$candidate"
        chmod 0600 "$candidate"
        sync "$candidate"
        mv -f -- "$candidate" "$destination"
        sync /evidence
    ' >/dev/null 2>&1 || true
    bounded_outer_docker "$HOST_GATE_DIAGNOSTIC_DOCKER_CALL_TIMEOUT_SECONDS" exec "$HOST_CONTAINER" \
        /bin/cat /evidence/host-readiness-diagnostics >&2 \
        || bounded_outer_docker "$HOST_GATE_DIAGNOSTIC_DOCKER_CALL_TIMEOUT_SECONDS" \
            logs --tail 400 "$HOST_CONTAINER" >&2 \
        || true
}

wait_for_container_stop()
{
    local deadline=$(( $(date +%s) + HOST_GATE_READINESS_DEADLINE_SECONDS )) running

    while (( $(date +%s) < deadline )); do
        if running=$(bounded_outer_docker_before_deadline "$deadline" inspect \
            --format '{{.State.Running}}' "$HOST_CONTAINER") && [[ $running == false ]]; then
            return
        fi
        sleep 1
    done
    capture_systemd_host_readiness_failure
    fail 'systemctl reboot did not stop the disposable systemd container'
}

evidence_value()
{
    bounded_outer_docker "$HOST_GATE_DIAGNOSTIC_DOCKER_CALL_TIMEOUT_SECONDS" \
        run --rm --pull never --platform linux/amd64 --network none --entrypoint /bin/cat \
        --mount "type=volume,source=$EVIDENCE_VOLUME,target=/evidence,readonly" \
        "$CONTROL_PLANE_RUNTIME_HOST_IMAGE" "/evidence/$1"
}

inner_environment_arguments()
{
    printf '%s\0' \
        "--env" "CONTROL_PLANE_RUNTIME_HOST_GATE_OPERATION=$HOST_GATE_OPERATION" \
        "--env" "CONTROL_PLANE_RUNTIME_LIVE_STACK_COMMAND=$LIVE_STACK_COMMAND_INNER" \
        "--env" "CONTROL_PLANE_RUNTIME_WEB_A_IMAGE=$CONTROL_PLANE_RUNTIME_WEB_A_IMAGE" \
        "--env" "CONTROL_PLANE_RUNTIME_WEB_B_IMAGE=$CONTROL_PLANE_RUNTIME_WEB_B_IMAGE" \
        "--env" "CONTROL_PLANE_RUNTIME_PROXY_IMAGE=$CONTROL_PLANE_RUNTIME_PROXY_IMAGE" \
        "--env" "CONTROL_PLANE_RUNTIME_IMAGE_TRANSPORT_MANIFEST_SHA256=$IMAGE_TRANSPORT_MANIFEST_SHA256" \
        "--env" "CONTROL_PLANE_RUNTIME_HOST_GATE_FINALIZE_SEAMS=${CONTROL_PLANE_RUNTIME_HOST_GATE_FINALIZE_SEAMS:-1}"
}

run_inner()
{
    local mode=$1 status
    local -a arguments=()

    mapfile -d '' -t arguments < <(inner_environment_arguments)
    set +e
    docker exec "${arguments[@]}" "$HOST_CONTAINER" "$INNER_RUNNER" "$mode" "$HOST_GATE_OPERATION"
    status=$?
    set -e
    return "$status"
}

export_evidence()
{
    local destination=${CONTROL_PLANE_RUNTIME_HOST_GATE_EVIDENCE_DIRECTORY:-}
    local binding binding_name binding_token leaf

    [[ -n $destination ]] || return 0
    [[ ${HOST_GATE_EVIDENCE_VOLUME_CREATED:-0} == 1 ]] || return 0
    [[ -d $destination && ! -L $destination && $(cd -- "$destination" && pwd -P) == "$destination" ]] \
        || {
            printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE evidence-export-directory is not an existing canonical absolute directory\n' >&2
            return 1
        }
    create_exclusive_evidence_export_leaf "$destination" "$HOST_GATE_OPERATION" || {
        printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE evidence-export-operation-directory already exists or is unsafe\n' >&2
        return 1
    }
    leaf=$destination/$HOST_GATE_OPERATION
    binding=$(create_evidence_export_binding "$leaf") || {
        printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE evidence-export-bind-attestation could not be created\n' >&2
        return 1
    }
    binding_name=${binding%%:*}
    binding_token=${binding#*:}
    [[ $binding_name == .host-gate-export-binding-* && $binding_token =~ ^[a-f0-9]{64}$ ]] || {
        printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE evidence-export-bind-attestation is malformed\n' >&2
        return 1
    }
    [[ -d $leaf && ! -L $leaf && $(cd -- "$leaf" && pwd -P) == "$leaf" \
        && -f $leaf/$binding_name && ! -L $leaf/$binding_name \
        && $(<"$leaf/$binding_name") == "$binding_token" ]] || {
        printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE evidence-export-bind-attestation changed before Docker mount\n' >&2
        return 1
    }
    # shellcheck disable=SC2016 # This literal is evaluated by the immutable host-image helper.
    bounded_outer_docker "$HOST_GATE_EVIDENCE_EXPORT_TIMEOUT_SECONDS" \
        run --rm --pull never --platform linux/amd64 --network none --entrypoint /bin/sh \
        --mount "type=volume,source=$EVIDENCE_VOLUME,target=/evidence,readonly" \
        --mount "type=bind,source=$leaf,target=/export" \
        "$CONTROL_PLANE_RUNTIME_HOST_IMAGE" -ec '
            binding=/export/$1
            expected=$2
            case "$1" in
                .host-gate-export-binding-*) ;;
                *) exit 64 ;;
            esac
            test -d /export && test ! -L /export
            test -f "$binding" && test ! -L "$binding"
            test "$(cat "$binding")" = "$expected"
            test "$(stat -c '\''%a'\'' "$binding")" = 600
            rm -f -- "$binding"
            test ! -e "$binding" && test ! -L "$binding"
            test -z "$(find /export -mindepth 1 -maxdepth 1 -print -quit)"
            cp -a /evidence/. /export/
        ' sh "$binding_name" "$binding_token" || return
    HOST_GATE_EVIDENCE_EXPORTED=1
}

assert_evidence_export_operation_available()
{
    local destination=${CONTROL_PLANE_RUNTIME_HOST_GATE_EVIDENCE_DIRECTORY:-}

    [[ -n $destination ]] || return 0
    [[ -d $destination && ! -L $destination && $(cd -- "$destination" && pwd -P) == "$destination" ]] \
        || fail 'evidence-export-directory is not an existing canonical absolute directory'
    [[ ! -e $destination/$HOST_GATE_OPERATION && ! -L $destination/$HOST_GATE_OPERATION ]] \
        || fail 'refusing to reuse an existing host-gate evidence operation leaf'
}

create_exclusive_evidence_export_leaf()
{
    local destination=$1 operation=$2 leaf

    [[ -d $destination && ! -L $destination && $(cd -- "$destination" && pwd -P) == "$destination" ]] \
        || return 1
    leaf=$destination/$operation
    [[ ! -e $leaf && ! -L $leaf ]] || return 1
    mkdir -m 0700 -- "$leaf" || return 1
    [[ -d $leaf && ! -L $leaf && $(cd -- "$leaf" && pwd -P) == "$leaf" ]]
}

local_file_mode()
{
    if stat -c '%a' "$1" >/dev/null 2>&1; then
        stat -c '%a' "$1"
    else
        stat -f '%Lp' "$1"
    fi
}

create_evidence_export_binding()
{
    local leaf=$1 token binding_name binding

    [[ -d $leaf && ! -L $leaf && $(cd -- "$leaf" && pwd -P) == "$leaf" ]] || return 1
    token=$(LC_ALL=C od -An -N 32 -tx1 /dev/urandom | tr -d '[:space:]') || return 1
    [[ $token =~ ^[a-f0-9]{64}$ ]] || return 1
    binding_name=.host-gate-export-binding-$token
    binding=$leaf/$binding_name
    (
        umask 077
        set -C
        printf '%s\n' "$token" > "$binding"
    ) || return 1
    [[ -f $binding && ! -L $binding && $(<"$binding") == "$token" \
        && $(local_file_mode "$binding") == 600 ]] || return 1
    printf '%s:%s\n' "$binding_name" "$token"
}

run_exclusive_evidence_export_contract_tests()
{
    local parent operation=host-gate-evidence-export-test leaf stale binding binding_name binding_token

    parent=$(mktemp -d "${TMPDIR:-/tmp}/coolify-runtime-fence-export-contract.XXXXXX")
    parent=$(cd -- "$parent" && pwd -P)
    leaf=$parent/$operation
    create_exclusive_evidence_export_leaf "$parent" "$operation" \
        || fail 'evidence export could not atomically create a fresh operation leaf'
    [[ -d $leaf && ! -L $leaf && $(cd -- "$leaf" && pwd -P) == "$leaf" ]] \
        || fail 'evidence export did not create a canonical operation leaf'
    binding=$(create_evidence_export_binding "$leaf") \
        || fail 'evidence export could not create a private bind attestation'
    binding_name=${binding%%:*}
    binding_token=${binding#*:}
    [[ -f $leaf/$binding_name && ! -L $leaf/$binding_name \
        && $(<"$leaf/$binding_name") == "$binding_token" \
        && $(local_file_mode "$leaf/$binding_name") == 600 ]] \
        || fail 'evidence export created an unsafe bind attestation'
    rm -f -- "$leaf/$binding_name"
    stale=$leaf/stale-evidence
    printf stale-evidence > "$stale"
    if create_exclusive_evidence_export_leaf "$parent" "$operation"; then
        fail 'evidence export reused an existing operation leaf'
    fi
    [[ $(<"$stale") == stale-evidence ]] \
        || fail 'evidence export changed stale evidence after refusing the operation collision'
    rm -f -- "$stale"
    rmdir -- "$leaf"
    ln -s nonexistent-operation-leaf "$leaf"
    if create_exclusive_evidence_export_leaf "$parent" "$operation"; then
        fail 'evidence export accepted a dangling operation-leaf symlink'
    fi
    rm -- "$leaf"
    mkdir -m 0700 -- "$parent/existing-target"
    ln -s existing-target "$leaf"
    if create_exclusive_evidence_export_leaf "$parent" "$operation"; then
        fail 'evidence export accepted an existing operation-leaf symlink'
    fi
    rm -- "$leaf"
    rmdir -- "$parent/existing-target"
    printf collision > "$leaf"
    if create_exclusive_evidence_export_leaf "$parent" "$operation"; then
        fail 'evidence export accepted a non-directory operation collision'
    fi
    rm -f -- "$leaf"
    rmdir -- "$parent"
}

# shellcheck disable=SC2016 # This function writes literal child-shell source for the isolated fake Docker binary.
write_host_gate_fake_docker()
{
    local directory=$1

    printf '%s\n' \
        '#!/usr/bin/env bash' \
        'set -Eeuo pipefail' \
        'printf "%s" "$1" >> "$FAKE_DOCKER_TRACE"' \
        'for argument in "${@:2}"; do printf " %s" "$argument" >> "$FAKE_DOCKER_TRACE"; done' \
        'printf "\\n" >> "$FAKE_DOCKER_TRACE"' \
        'case "$1" in' \
        '    context)' \
        '        case "${2:-}" in' \
        '            show) printf "%s\\n" "${FAKE_DOCKER_ACTIVE_CONTEXT:-default}" ;;' \
        '            inspect)' \
        '                [[ ${3:-} == "${FAKE_DOCKER_ACTIVE_CONTEXT:-default}" ]] || exit 64' \
        '                printf "%s\\n" "${FAKE_DOCKER_ACTIVE_ENDPOINT:-unix:///var/run/docker.sock}"' \
        '                ;;' \
        '            *) exit 64 ;;' \
        '        esac' \
        '        ;;' \
        '    version) printf "linux/%s\\n" "$FAKE_DOCKER_ENGINE_ARCHITECTURE" ;;' \
        '    timeout-test) exec /bin/sleep 30 ;;' \
        '    *) exit 64 ;;' \
        'esac' > "$directory/docker"
    chmod 0700 "$directory/docker"
}

assert_fake_docker_has_no_mutation()
{
    local trace=$1

    ! grep -E -q '^(network|volume|run)([[:space:]]|$)' "$trace" \
        || fail 'host-gate preflight mutated Docker before rejecting the unsafe endpoint or architecture'
}

run_host_gate_preflight_fake_docker_test()
{
    local label=$1 expected_message=$2 docker_host=$3 docker_context=$4 active_context=$5 active_endpoint=$6
    local architecture=$7 expected_trace=$8
    local directory trace output status
    local -a environment=()

    directory=$(mktemp -d "${TMPDIR:-/tmp}/coolify-runtime-fence-preflight-contract.XXXXXX")
    trace=$directory/docker.trace
    output=$directory/output
    : > "$trace"
    write_host_gate_fake_docker "$directory"
    environment=(
        env -u DOCKER_HOST -u DOCKER_CONTEXT
        "PATH=$directory:$PATH"
        "FAKE_DOCKER_TRACE=$trace"
        "FAKE_DOCKER_ACTIVE_CONTEXT=$active_context"
        "FAKE_DOCKER_ACTIVE_ENDPOINT=$active_endpoint"
        "FAKE_DOCKER_ENGINE_ARCHITECTURE=$architecture"
        CONTROL_PLANE_RUNTIME_HOST_GATE_EXECUTE=1
        CONTROL_PLANE_RUNTIME_TEST_MODE=0
        CONTROL_PLANE_RUNTIME_PROVISION_TEST_MODE=0
    )
    [[ -z $docker_host ]] || environment+=("DOCKER_HOST=$docker_host")
    [[ -z $docker_context ]] || environment+=("DOCKER_CONTEXT=$docker_context")
    if "${environment[@]}" "$BASH" "$TEST_DIRECTORY/linux-host-acceptance.sh" > "$output" 2>&1; then
        rm -rf -- "$directory"
        fail "fake-Docker host-gate preflight unexpectedly accepted $label"
    else
        status=$?
    fi
    [[ $status == 78 ]] \
        || {
            cat "$output" >&2
            rm -rf -- "$directory"
            fail "fake-Docker host-gate preflight used the wrong rejection status for $label"
        }
    grep -F -q "$expected_message" "$output" \
        || {
            cat "$output" >&2
            rm -rf -- "$directory"
            fail "fake-Docker host-gate preflight did not reject $label for its attested reason"
        }
    assert_fake_docker_has_no_mutation "$trace"
    [[ $(<"$trace") == "$expected_trace" ]] \
        || {
            cat "$output" >&2
            rm -rf -- "$directory"
            fail "fake-Docker host-gate preflight issued unexpected Docker calls for $label"
        }
    rm -rf -- "$directory"
}

run_host_gate_preflight_fake_docker_tests()
{
    run_host_gate_preflight_fake_docker_test \
        remote-docker-host 'rejects ambient DOCKER_HOST and DOCKER_CONTEXT overrides' \
        ssh://control-plane.example '' default unix:///var/run/docker.sock amd64 ''
    run_host_gate_preflight_fake_docker_test \
        remote-docker-context 'rejects ambient DOCKER_HOST and DOCKER_CONTEXT overrides' \
        '' remote default unix:///var/run/docker.sock amd64 ''
    run_host_gate_preflight_fake_docker_test \
        remote-active-context 'requires the local default Docker context' \
        '' '' remote ssh://control-plane.example amd64 \
        $'context show\ncontext inspect remote --format {{.Endpoints.docker.Host}}'
    run_host_gate_preflight_fake_docker_test \
        remote-active-endpoint 'requires the local default Docker context' \
        '' '' default ssh://control-plane.example amd64 \
        $'context show\ncontext inspect default --format {{.Endpoints.docker.Host}}'
    run_host_gate_preflight_fake_docker_test \
        arm64-engine 'requires a native linux/amd64 Docker engine' \
        '' '' default unix:///var/run/docker.sock arm64 \
        $'context show\ncontext inspect default --format {{.Endpoints.docker.Host}}\nversion --format {{.Server.Os}}/{{.Server.Arch}}'
}

run_exited_container_evidence_export_configuration_test()
{
    local directory trace output status

    directory=$(mktemp -d "${TMPDIR:-/tmp}/coolify-runtime-fence-exited-evidence-config.XXXXXX")
    trace=$directory/docker.trace
    output=$directory/output
    : > "$trace"
    write_host_gate_fake_docker "$directory"
    if env -u DOCKER_HOST -u DOCKER_CONTEXT \
        "PATH=$directory:$PATH" \
        "FAKE_DOCKER_TRACE=$trace" \
        CONTROL_PLANE_RUNTIME_HOST_GATE_EXECUTE=1 \
        CONTROL_PLANE_RUNTIME_TEST_MODE=0 \
        CONTROL_PLANE_RUNTIME_PROVISION_TEST_MODE=0 \
        "$BASH" "$TEST_DIRECTORY/linux-host-acceptance.sh" --test-exited-container-evidence \
        > "$output" 2>&1; then
        rm -rf -- "$directory"
        fail 'exited-container evidence test accepted a missing export directory'
    else
        status=$?
    fi
    [[ $status == 1 ]] \
        || {
            cat "$output" >&2
            rm -rf -- "$directory"
            fail 'exited-container evidence test used the wrong status for a missing export directory'
        }
    grep -F -q 'requires CONTROL_PLANE_RUNTIME_HOST_GATE_EVIDENCE_DIRECTORY' "$output" \
        || {
            cat "$output" >&2
            rm -rf -- "$directory"
            fail 'exited-container evidence test did not require an export directory before Docker use'
        }
    [[ ! -s $trace ]] \
        || {
            cat "$output" >&2
            rm -rf -- "$directory"
            fail 'exited-container evidence test used Docker before validating its export directory'
        }
    rm -rf -- "$directory"
}

# shellcheck disable=SC2030,SC2031 # The fake Docker is intentionally visible only to this bounded child call.
run_bounded_outer_docker_contract_test()
{
    local directory trace started elapsed status

    directory=$(mktemp -d "${TMPDIR:-/tmp}/coolify-runtime-fence-timeout-contract.XXXXXX")
    trace=$directory/docker.trace
    : > "$trace"
    write_host_gate_fake_docker "$directory"
    started=$(date +%s)
    if PATH="$directory:$PATH" FAKE_DOCKER_TRACE=$trace FAKE_DOCKER_ENGINE_ARCHITECTURE=amd64 \
        bounded_outer_docker 1s timeout-test >/dev/null 2>&1; then
        rm -rf -- "$directory"
        fail 'bounded outer Docker command unexpectedly completed'
    else
        status=$?
    fi
    elapsed=$(( $(date +%s) - started ))
    [[ $status == 124 && $elapsed -le 3 && $(<"$trace") == timeout-test ]] \
        || {
            rm -rf -- "$directory"
            fail 'bounded outer Docker command did not enforce its wall-clock timeout'
        }
    rm -rf -- "$directory"
}

# shellcheck disable=SC2016 # This function writes literal child-shell source for the cleanup fake Docker binary.
write_host_gate_cleanup_fake_docker()
{
    local directory=$1

    printf '%s\n' \
        '#!/usr/bin/env bash' \
        'set -Eeuo pipefail' \
        'printf "%s %s\\n" "$1" "${2:-}" >> "$FAKE_DOCKER_TRACE"' \
        'case "$1:$2" in' \
        '    container:rm|network:rm|volume:rm)' \
        '        [[ $FAKE_DOCKER_CLEANUP_MODE == absent ]] && exit 0' \
        '        exit 2' \
        '        ;;' \
        '    container:inspect)' \
        '        [[ $FAKE_DOCKER_CLEANUP_MODE == retained ]] && { printf "[{}]\\n"; exit 0; }' \
        '        printf "Error response from daemon: No such container: %s\\n" "$3" >&2' \
        '        exit 1' \
        '        ;;' \
        '    network:inspect)' \
        '        [[ $FAKE_DOCKER_CLEANUP_MODE == retained ]] && { printf "[{}]\\n"; exit 0; }' \
        '        printf "Error response from daemon: network %s not found\\n" "$3" >&2' \
        '        exit 1' \
        '        ;;' \
        '    volume:inspect)' \
        '        [[ $FAKE_DOCKER_CLEANUP_MODE == retained ]] && { printf "[{}]\\n"; exit 0; }' \
        '        printf "Error response from daemon: get %s: no such volume\\n" "$3" >&2' \
        '        exit 1' \
        '        ;;' \
        '    *) exit 64 ;;' \
        'esac' > "$directory/docker"
    chmod 0700 "$directory/docker"
}

run_host_gate_cleanup_contract_tests()
{
    local directory trace original_path

    directory=$(mktemp -d "${TMPDIR:-/tmp}/coolify-runtime-fence-cleanup-contract.XXXXXX")
    trace=$directory/docker.trace
    : > "$trace"
    write_host_gate_cleanup_fake_docker "$directory"
    original_path=$PATH
    PATH=$directory:$PATH
    export PATH
    FAKE_DOCKER_TRACE=$trace
    FAKE_DOCKER_CLEANUP_MODE=absent
    export FAKE_DOCKER_TRACE FAKE_DOCKER_CLEANUP_MODE
    HOST_CONTAINER=cleanup-contract-container
    OUTER_NETWORK=cleanup-contract-network
    INNER_DOCKER_VOLUME=cleanup-contract-inner-docker
    EVIDENCE_VOLUME=cleanup-contract-evidence
    HOST_GATE_RESOURCES_CREATED=1
    HOST_GATE_CONTAINER_CREATED=1
    HOST_GATE_NETWORK_CREATED=1
    HOST_GATE_INNER_DOCKER_VOLUME_CREATED=1
    HOST_GATE_EVIDENCE_VOLUME_CREATED=1
    if ! remove_host_gate_resources; then
        PATH=$original_path
        unset FAKE_DOCKER_TRACE FAKE_DOCKER_CLEANUP_MODE
        rm -rf -- "$directory"
        fail 'cleanup could not prove resource absence after successful fake Docker removal'
    fi
    if [[ $HOST_GATE_RESOURCES_CREATED != 0 || $HOST_GATE_CONTAINER_CREATED != 0 \
        || $HOST_GATE_NETWORK_CREATED != 0 || $HOST_GATE_INNER_DOCKER_VOLUME_CREATED != 0 \
        || $HOST_GATE_EVIDENCE_VOLUME_CREATED != 0 ]]; then
        PATH=$original_path
        unset FAKE_DOCKER_TRACE FAKE_DOCKER_CLEANUP_MODE
        rm -rf -- "$directory"
        fail 'cleanup did not clear flags after bounded Docker removal and absence proof'
    fi
    : > "$trace"
    FAKE_DOCKER_CLEANUP_MODE=retained
    HOST_GATE_RESOURCES_CREATED=1
    HOST_GATE_CONTAINER_CREATED=1
    HOST_GATE_NETWORK_CREATED=1
    HOST_GATE_INNER_DOCKER_VOLUME_CREATED=1
    HOST_GATE_EVIDENCE_VOLUME_CREATED=1
    HOST_GATE_EVIDENCE_EXPORTED=1
    HOST_GATE_CLEANUP_IN_PROGRESS=0
    if (cleanup 0) >/dev/null 2>&1; then
        PATH=$original_path
        unset FAKE_DOCKER_TRACE FAKE_DOCKER_CLEANUP_MODE
        rm -rf -- "$directory"
        fail 'cleanup reported success while tracked Docker resources remained'
    fi
    [[ $(wc -l < "$trace" | tr -d '[:space:]') == 8 ]] \
        || {
            PATH=$original_path
            unset FAKE_DOCKER_TRACE FAKE_DOCKER_CLEANUP_MODE
            rm -rf -- "$directory"
            fail 'cleanup did not attempt every tracked resource before reporting retention'
        }
    PATH=$original_path
    unset FAKE_DOCKER_TRACE FAKE_DOCKER_CLEANUP_MODE
    rm -rf -- "$directory"
}

run_host_gate_signal_cleanup_contract_test()
{
    HOST_GATE_RESOURCES_CREATED=0
    HOST_GATE_CLEANUP_IN_PROGRESS=0
    if (cleanup_from_signal TERM) >/dev/null 2>&1; then
        fail 'signal cleanup reported a successful host-gate run'
    fi
}

remove_host_gate_resources()
{
    local cleanup_failed=0

    if [[ ${HOST_GATE_CONTAINER_CREATED:-0} == 1 ]]; then
        if remove_tracked_docker_resource container "$HOST_CONTAINER" -f; then
            HOST_GATE_CONTAINER_CREATED=0
        else
            cleanup_failed=1
        fi
    fi
    if [[ ${HOST_GATE_NETWORK_CREATED:-0} == 1 ]]; then
        if remove_tracked_docker_resource network "$OUTER_NETWORK"; then
            HOST_GATE_NETWORK_CREATED=0
        else
            cleanup_failed=1
        fi
    fi
    if [[ ${HOST_GATE_INNER_DOCKER_VOLUME_CREATED:-0} == 1 ]]; then
        if remove_tracked_docker_resource volume "$INNER_DOCKER_VOLUME"; then
            HOST_GATE_INNER_DOCKER_VOLUME_CREATED=0
        else
            cleanup_failed=1
        fi
    fi
    if [[ ${HOST_GATE_EVIDENCE_VOLUME_CREATED:-0} == 1 ]]; then
        if remove_tracked_docker_resource volume "$EVIDENCE_VOLUME"; then
            HOST_GATE_EVIDENCE_VOLUME_CREATED=0
        else
            cleanup_failed=1
        fi
    fi
    if [[ ${HOST_GATE_CONTAINER_CREATED:-0} == 0 && ${HOST_GATE_NETWORK_CREATED:-0} == 0 \
        && ${HOST_GATE_INNER_DOCKER_VOLUME_CREATED:-0} == 0 \
        && ${HOST_GATE_EVIDENCE_VOLUME_CREATED:-0} == 0 ]]; then
        HOST_GATE_RESOURCES_CREATED=0
    else
        cleanup_failed=1
    fi
    if (( cleanup_failed == 1 )); then
        printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE cleanup_retained_container_created=%s cleanup_retained_container=%s cleanup_retained_network_created=%s cleanup_retained_network=%s cleanup_retained_inner_docker_volume_created=%s cleanup_retained_inner_docker_volume=%s cleanup_retained_evidence_volume_created=%s cleanup_retained_evidence_volume=%s\n' \
            "${HOST_GATE_CONTAINER_CREATED:-0}" "$HOST_CONTAINER" \
            "${HOST_GATE_NETWORK_CREATED:-0}" "$OUTER_NETWORK" \
            "${HOST_GATE_INNER_DOCKER_VOLUME_CREATED:-0}" "$INNER_DOCKER_VOLUME" \
            "${HOST_GATE_EVIDENCE_VOLUME_CREATED:-0}" "$EVIDENCE_VOLUME" >&2
        return 1
    fi
}

docker_resource_is_absent()
{
    local resource_kind=$1 resource=$2 output status

    if output=$(bounded_outer_docker "$HOST_GATE_CLEANUP_DOCKER_CALL_TIMEOUT_SECONDS" \
        "$resource_kind" inspect "$resource" 2>&1); then
        printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE cleanup_inspect_retained kind=%s resource=%s\n' \
            "$resource_kind" "$resource" >&2
        return 1
    else
        status=$?
    fi
    (( status == 1 )) || {
        printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE cleanup_inspect_failed kind=%s resource=%s status=%s\n' \
            "$resource_kind" "$resource" "$status" >&2
        return 1
    }
    case "$resource_kind" in
        container) [[ $output == *"No such container: $resource"* ]] ;;
        network) [[ $output == *"No such network: $resource"* || $output == *"network $resource not found"* ]] ;;
        volume) [[ $output == *"No such volume: $resource"* \
            || $output == *"get $resource: no such volume"* \
            || $output == *"no such volume"*"$resource"* ]] ;;
        *) return 64 ;;
    esac
}

remove_tracked_docker_resource()
{
    local resource_kind=$1 resource=$2 remove_status

    shift 2
    if bounded_outer_docker "$HOST_GATE_CLEANUP_DOCKER_CALL_TIMEOUT_SECONDS" \
        "$resource_kind" rm "$@" "$resource" >/dev/null 2>&1; then
        :
    else
        remove_status=$?
        printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE cleanup_remove_failed kind=%s resource=%s status=%s\n' \
            "$resource_kind" "$resource" "$remove_status" >&2
    fi
    docker_resource_is_absent "$resource_kind" "$resource"
}

run_partial_resource_cleanup_test()
{
    local export_directory marker

    [[ $HOST_GATE_CONTAINER_CREATED == 0 ]] \
        || fail 'partial-resource cleanup test must run before host container creation'
    export_directory=$(mktemp -d /private/tmp/coolify-runtime-fence-partial-cleanup.XXXXXX)
    image_transport_outer_evidence_run -ec '
        umask 077
        printf partial-resource-evidence > /evidence/partial-resource.marker
    '
    CONTROL_PLANE_RUNTIME_HOST_GATE_EVIDENCE_DIRECTORY=$export_directory export_evidence \
        || fail 'partial-resource cleanup could not export evidence without a host container'
    marker=$export_directory/$HOST_GATE_OPERATION/partial-resource.marker
    [[ -f $marker && ! -L $marker && $(tr -d '\n' < "$marker") == partial-resource-evidence ]] \
        || fail 'partial-resource cleanup did not export evidence without a host container'
    remove_host_gate_resources
    [[ $HOST_GATE_RESOURCES_CREATED == 0 \
        && $HOST_GATE_CONTAINER_CREATED == 0 \
        && $HOST_GATE_NETWORK_CREATED == 0 \
        && $HOST_GATE_INNER_DOCKER_VOLUME_CREATED == 0 \
        && $HOST_GATE_EVIDENCE_VOLUME_CREATED == 0 ]] \
        || fail 'partial-resource cleanup retained a tracked Docker resource'
    rm -f -- "$marker"
    rmdir -- "$export_directory/$HOST_GATE_OPERATION" "$export_directory"
    printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE partial_cleanup_test=passed container_created=false evidence_export=volume\n'
}

# shellcheck disable=SC2016 # This literal metadata check runs in the immutable host image.
evidence_file_metadata()
{
    bounded_outer_docker "$HOST_GATE_DIAGNOSTIC_DOCKER_CALL_TIMEOUT_SECONDS" \
        run --rm --pull never --platform linux/amd64 --network none --entrypoint /bin/sh \
        --mount "type=volume,source=$EVIDENCE_VOLUME,target=/evidence,readonly" \
        "$CONTROL_PLANE_RUNTIME_HOST_IMAGE" -ec '
            source=/evidence/$1
            test -f "$source" && test ! -L "$source"
            stat -c '\''%u:%g:%a'\'' "$source"
        ' sh "$1"
}

# shellcheck disable=SC2016 # This literal metadata and digest check runs in the immutable host image.
exported_evidence_file_evidence()
{
    local export_leaf=$1 filename=$2

    bounded_outer_docker "$HOST_GATE_DIAGNOSTIC_DOCKER_CALL_TIMEOUT_SECONDS" \
        run --rm --pull never --platform linux/amd64 --network none --entrypoint /bin/sh \
        --mount "type=bind,source=$export_leaf,target=/export,readonly" \
        "$CONTROL_PLANE_RUNTIME_HOST_IMAGE" -ec '
            source=/export/$1
            test -f "$source" && test ! -L "$source"
            printf "%s:%s\\n" "$(stat -c '\''%a'\'' "$source")" "$(sha256sum "$source" | awk '\''{print $1}'\'')"
        ' sh "$filename"
}

# shellcheck disable=SC2016 # This literal reader runs in the immutable host image so root-owned bind files remain verifiable.
exported_evidence_value()
{
    local export_leaf=$1 filename=$2

    bounded_outer_docker "$HOST_GATE_DIAGNOSTIC_DOCKER_CALL_TIMEOUT_SECONDS" \
        run --rm --pull never --platform linux/amd64 --network none --entrypoint /bin/cat \
        --mount "type=bind,source=$export_leaf,target=/export,readonly" \
        "$CONTROL_PLANE_RUNTIME_HOST_IMAGE" "/export/$filename"
}

assert_exited_container_exported_evidence()
{
    local destination=${CONTROL_PLANE_RUNTIME_HOST_GATE_EVIDENCE_DIRECTORY:-}
    local export_leaf filename source_sha exported_evidence inspect_output logs_status

    [[ -n $destination && -d $destination && ! -L $destination \
        && $(cd -- "$destination" && pwd -P) == "$destination" ]] \
        || fail 'exited-host diagnostic export directory is not canonical'
    export_leaf=$destination/$HOST_GATE_OPERATION
    [[ -d $export_leaf && ! -L $export_leaf \
        && $(cd -- "$export_leaf" && pwd -P) == "$export_leaf" \
        && $(local_file_mode "$export_leaf") == 700 ]] \
        || fail 'exited-host diagnostic export leaf is not a private canonical directory'
    for filename in \
        outer-container-inspect.json outer-container-inspect.status \
        outer-container-logs.txt outer-container-logs.status; do
        source_sha=$(evidence_value "$filename" | sha256sum | awk '{print $1}')
        exported_evidence=$(exported_evidence_file_evidence "$export_leaf" "$filename")
        [[ $exported_evidence == "600:$source_sha" ]] \
            || fail "exited-host diagnostic export did not preserve metadata or content: $filename"
    done
    inspect_output=$(exported_evidence_value "$export_leaf" outer-container-inspect.json)
    logs_status=$(exported_evidence_value "$export_leaf" outer-container-logs.status)
    jq --exit-status . <<< "$inspect_output" >/dev/null \
        || fail 'exited-host diagnostic export did not preserve valid outer Docker inspect JSON'
    grep -F -q "\"Name\": \"/$HOST_CONTAINER\"" <<< "$inspect_output" \
        || fail 'exited-host diagnostic export did not retain outer Docker inspect output'
    grep -F -q outer_docker_exit_status=0 <<< "$logs_status" \
        || fail 'exited-host diagnostic export did not retain outer Docker logs output'
}

run_exited_container_evidence_test()
{
    local inspect_output logs_status filename

    bounded_outer_docker "$HOST_GATE_DIAGNOSTIC_DOCKER_CALL_TIMEOUT_SECONDS" \
        create --pull never --platform linux/amd64 --privileged --cgroupns=private \
        --name "$HOST_CONTAINER" \
        --hostname "$HOST_CONTAINER" --network "$OUTER_NETWORK" \
        --tmpfs /run --tmpfs /run/lock --tmpfs /tmp \
        --mount "type=bind,source=$REPOSITORY_ROOT,target=/workspace,readonly" \
        --mount "type=volume,source=$INNER_DOCKER_VOLUME,target=/var/lib/docker" \
        --mount "type=volume,source=$EVIDENCE_VOLUME,target=/evidence" \
        --entrypoint /bin/false "$CONTROL_PLANE_RUNTIME_HOST_IMAGE" >/dev/null
    HOST_GATE_CONTAINER_CREATED=1
    assert_isolated_container
    bounded_outer_docker "$HOST_GATE_DIAGNOSTIC_DOCKER_CALL_TIMEOUT_SECONDS" \
        start "$HOST_CONTAINER" >/dev/null
    wait_for_container_stop
    capture_systemd_host_readiness_failure
    inspect_output=$(evidence_value outer-container-inspect.json)
    logs_status=$(evidence_value outer-container-logs.status)
    jq --exit-status . <<< "$inspect_output" >/dev/null \
        || fail 'exited-host diagnostic evidence did not preserve valid outer Docker inspect JSON'
    grep -F -q "\"Name\": \"/$HOST_CONTAINER\"" <<< "$inspect_output" \
        || fail 'exited-host diagnostic evidence did not retain outer Docker inspect output'
    grep -F -q outer_docker_exit_status=0 <<< "$logs_status" \
        || fail 'exited-host diagnostic evidence did not retain outer Docker logs output'
    for filename in \
        outer-container-inspect.json outer-container-inspect.status \
        outer-container-logs.txt outer-container-logs.status; do
        [[ $(evidence_file_metadata "$filename") == 0:0:600 ]] \
            || fail "exited-host diagnostic evidence has unsafe metadata: $filename"
    done
    export_evidence \
        || fail 'exited-host diagnostic evidence could not be explicitly exported before success'
    [[ ${HOST_GATE_EVIDENCE_EXPORTED:-0} == 1 ]] \
        || fail 'exited-host diagnostic evidence export did not record completion'
    assert_exited_container_exported_evidence
    printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE exited_container_evidence_test=passed container_state=exited\n'
}

cleanup()
{
    local status=$?

    [[ $# == 0 ]] || status=$1
    if [[ ${HOST_GATE_CLEANUP_IN_PROGRESS:-0} == 1 ]]; then
        exit "$status"
    fi
    HOST_GATE_CLEANUP_IN_PROGRESS=1
    trap - EXIT HUP INT TERM
    if [[ ${HOST_GATE_RESOURCES_CREATED:-0} == 1 ]]; then
        if [[ ${HOST_GATE_EVIDENCE_EXPORTED:-0} != 1 ]]; then
            if ! export_evidence; then
                printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE evidence_export=failed original_status=%s\n' \
                    "$status" >&2
                (( status != 0 )) || status=1
            fi
        fi
        if [[ ${CONTROL_PLANE_RUNTIME_HOST_GATE_KEEP:-0} != 1 ]]; then
            if ! remove_host_gate_resources; then
                printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE cleanup=failed original_status=%s\n' \
                    "$status" >&2
                (( status != 0 )) || status=1
            fi
        else
            printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE retained_container_created=%s container=%s evidence_volume_created=%s evidence_volume=%s inner_docker_volume_created=%s inner_docker_volume=%s network_created=%s network=%s\n' \
                "${HOST_GATE_CONTAINER_CREATED:-0}" "$HOST_CONTAINER" \
                "${HOST_GATE_EVIDENCE_VOLUME_CREATED:-0}" "$EVIDENCE_VOLUME" \
                "${HOST_GATE_INNER_DOCKER_VOLUME_CREATED:-0}" "$INNER_DOCKER_VOLUME" \
                "${HOST_GATE_NETWORK_CREATED:-0}" "$OUTER_NETWORK" >&2
        fi
    fi
    exit "$status"
}

cleanup_from_signal()
{
    local signal=$1

    printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE interrupted_signal=%s\n' "$signal" >&2
    cleanup 1
}

usage()
{
    printf '%s\n' \
        'usage: CONTROL_PLANE_RUNTIME_HOST_GATE_EXECUTE=1 linux-host-acceptance.sh [--test-image-export|--test-partial-cleanup|--test-exited-container-evidence]' \
        'requires one digest-pinned Coolify/Laravel web image, a proxy image, and a host image.' \
        'use --check for a non-mutating source-contract check.'
}

if [[ ${1:-} == --check ]]; then
    for path in "$HOST_DOCKERFILE" "$HOST_BOOT_SCRIPT" "$HOST_BOOT_UNIT" "$HOST_BUILD_SCRIPT" \
        "$TEST_DIRECTORY/linux-host-acceptance-inner.sh" "$TEST_DIRECTORY/live-host-fixture.bash" \
        "$LIVE_STACK_ADAPTER" "$TEST_DIRECTORY/live-stack-contract.bash" \
        "$TEST_DIRECTORY/live-stack-bootstrap.bash" "$TEST_DIRECTORY/live-stack-runtime.bash" \
        "$IMAGE_TRANSPORT_SOURCE"; do
        [[ -f $path && ! -L $path ]] || fail "required host-gate source is absent: $path"
    done
    bash -n "$TEST_DIRECTORY/linux-host-acceptance.sh" \
        "$TEST_DIRECTORY/linux-host-acceptance-inner.sh" \
        "$TEST_DIRECTORY/live-host-fixture.bash" \
        "$LIVE_STACK_ADAPTER" \
        "$TEST_DIRECTORY/live-stack-contract.bash" \
        "$TEST_DIRECTORY/live-stack-bootstrap.bash" \
        "$TEST_DIRECTORY/live-stack-runtime.bash" \
        "$IMAGE_TRANSPORT_SOURCE" \
        "$HOST_BUILD_SCRIPT" \
        "$HOST_BOOT_SCRIPT"
    "$LIVE_STACK_ADAPTER" --check >/dev/null
    "$HOST_BUILD_SCRIPT" --check >/dev/null
    assert_isolated_container_contract_representations
    assert_native_amd64_engine_platform linux/amd64
    if (assert_native_amd64_engine_platform linux/arm64) >/dev/null 2>&1; then
        fail 'native-amd64 engine preflight accepted an emulated host architecture'
    fi
    assert_local_docker_endpoint_contract default unix:///var/run/docker.sock
    if (assert_local_docker_endpoint_contract remote ssh://control-plane.example) \
        >/dev/null 2>&1; then
        fail 'local Docker endpoint preflight accepted a remote daemon'
    fi
    command -v timeout >/dev/null \
        || fail 'host-gate wall-clock bounding requires GNU timeout'
    run_bounded_outer_docker_contract_test
    run_host_gate_cleanup_contract_tests
    run_host_gate_signal_cleanup_contract_test
    run_exclusive_evidence_export_contract_tests
    run_host_gate_preflight_fake_docker_tests
    run_exited_container_evidence_export_configuration_test
    grep -F -q "FROM \${UBUNTU_IMAGE}" "$HOST_DOCKERFILE" \
        || fail 'systemd host Dockerfile no longer has a digest-pinned Ubuntu build input'
    printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE check=passed\n'
    exit 0
fi

case ${1:-} in
    '') readonly HOST_GATE_EXECUTION_MODE=run ;;
    --test-image-export) readonly HOST_GATE_EXECUTION_MODE=image-export-test ;;
    --test-partial-cleanup) readonly HOST_GATE_EXECUTION_MODE=partial-cleanup-test ;;
    --test-exited-container-evidence) readonly HOST_GATE_EXECUTION_MODE=exited-container-evidence-test ;;
    *) usage >&2; exit 64 ;;
esac
[[ $# -le 1 ]] || {
    usage >&2
    exit 64
}
[[ ${CONTROL_PLANE_RUNTIME_HOST_GATE_EXECUTE:-0} == 1 ]] \
    || blocked 'set CONTROL_PLANE_RUNTIME_HOST_GATE_EXECUTE=1 only for this isolated disposable host gate'
[[ ${CONTROL_PLANE_RUNTIME_TEST_MODE:-0} == 0 \
    && ${CONTROL_PLANE_RUNTIME_PROVISION_TEST_MODE:-0} == 0 ]] \
    || fail 'the production host-gate path forbids controller and provisioner test mode'
if [[ $HOST_GATE_EXECUTION_MODE == exited-container-evidence-test ]]; then
    evidence_export_directory=${CONTROL_PLANE_RUNTIME_HOST_GATE_EVIDENCE_DIRECTORY:-}
    [[ -n $evidence_export_directory && -d $evidence_export_directory \
        && ! -L $evidence_export_directory \
        && $(cd -- "$evidence_export_directory" && pwd -P) == "$evidence_export_directory" ]] \
        || fail 'the exited-container evidence test requires CONTROL_PLANE_RUNTIME_HOST_GATE_EVIDENCE_DIRECTORY to be a canonical existing directory'
fi

for command in docker jq sha256sum timeout od tr; do
    command -v "$command" >/dev/null || fail "required outer command is unavailable: $command"
done
[[ -z ${DOCKER_HOST:-} && -z ${DOCKER_CONTEXT:-} ]] \
    || blocked 'the production host gate rejects ambient DOCKER_HOST and DOCKER_CONTEXT overrides'
docker_context=$(docker context show) \
    || blocked 'the production host gate could not attest the active Docker context'
docker_endpoint=$(docker context inspect "$docker_context" --format '{{.Endpoints.docker.Host}}') \
    || blocked 'the production host gate could not attest the active Docker endpoint'
assert_local_docker_endpoint_contract "$docker_context" "$docker_endpoint"
engine_platform=$(docker version --format '{{.Server.Os}}/{{.Server.Arch}}') \
    || blocked 'the production host gate could not attest the Docker engine platform'
assert_native_amd64_engine_platform "$engine_platform"
[[ -S /var/run/docker.sock ]] \
    || blocked 'the production host gate requires a local Unix Docker socket at /var/run/docker.sock'
[[ -n ${CONTROL_PLANE_RUNTIME_HOST_IMAGE:-} ]] \
    || blocked 'missing required real-stack input: CONTROL_PLANE_RUNTIME_HOST_IMAGE'

assert_digest_reference host-image "$CONTROL_PLANE_RUNTIME_HOST_IMAGE"
assert_local_host_image "$(source_attestation_sha256)" "$CONTROL_PLANE_RUNTIME_HOST_IMAGE"
if [[ $HOST_GATE_EXECUTION_MODE != partial-cleanup-test \
    && $HOST_GATE_EXECUTION_MODE != exited-container-evidence-test ]]; then
    for required in \
        CONTROL_PLANE_RUNTIME_WEB_A_IMAGE CONTROL_PLANE_RUNTIME_WEB_B_IMAGE \
        CONTROL_PLANE_RUNTIME_PROXY_IMAGE; do
        [[ -n ${!required:-} ]] || blocked "missing required real-stack input: $required"
    done
    assert_digest_reference web-a-image "$CONTROL_PLANE_RUNTIME_WEB_A_IMAGE"
    assert_digest_reference web-b-image "$CONTROL_PLANE_RUNTIME_WEB_B_IMAGE"
    assert_digest_reference proxy-image "$CONTROL_PLANE_RUNTIME_PROXY_IMAGE"
    [[ $CONTROL_PLANE_RUNTIME_WEB_A_IMAGE == "$CONTROL_PLANE_RUNTIME_WEB_B_IMAGE" ]] \
        || blocked 'the host gate requires one immutable Coolify/Laravel digest for all four web members'
    assert_stack_adapter
    "$LIVE_STACK_ADAPTER" --check >/dev/null
fi

HOST_GATE_OPERATION=${CONTROL_PLANE_RUNTIME_HOST_GATE_OPERATION:-runtime-fence-host-$(date -u +%Y%m%d%H%M%S)-$RANDOM}
assert_safe_identifier host-gate-operation "$HOST_GATE_OPERATION"
readonly HOST_GATE_OPERATION
readonly HOST_CONTAINER=$HOST_GATE_OPERATION
readonly OUTER_NETWORK=$HOST_GATE_OPERATION-net
readonly INNER_DOCKER_VOLUME=$HOST_GATE_OPERATION-inner-docker
readonly EVIDENCE_VOLUME=$HOST_GATE_OPERATION-evidence
assert_evidence_export_operation_available

for resource in "$HOST_CONTAINER" "$OUTER_NETWORK" "$INNER_DOCKER_VOLUME" "$EVIDENCE_VOLUME"; do
    if docker container inspect "$resource" >/dev/null 2>&1 \
        || docker network inspect "$resource" >/dev/null 2>&1 \
        || docker volume inspect "$resource" >/dev/null 2>&1; then
        fail "refusing to reuse an existing host-gate resource: $resource"
    fi
done

HOST_GATE_RESOURCES_CREATED=0
HOST_GATE_NETWORK_CREATED=0
HOST_GATE_INNER_DOCKER_VOLUME_CREATED=0
HOST_GATE_EVIDENCE_VOLUME_CREATED=0
HOST_GATE_CONTAINER_CREATED=0
HOST_GATE_EVIDENCE_EXPORTED=0
HOST_GATE_CLEANUP_IN_PROGRESS=0
trap cleanup EXIT
trap 'cleanup_from_signal HUP' HUP
trap 'cleanup_from_signal INT' INT
trap 'cleanup_from_signal TERM' TERM
docker network create --driver bridge "$OUTER_NETWORK" >/dev/null
HOST_GATE_NETWORK_CREATED=1
HOST_GATE_RESOURCES_CREATED=1
docker volume create "$INNER_DOCKER_VOLUME" >/dev/null
HOST_GATE_INNER_DOCKER_VOLUME_CREATED=1
docker volume create "$EVIDENCE_VOLUME" >/dev/null
HOST_GATE_EVIDENCE_VOLUME_CREATED=1
if [[ $HOST_GATE_EXECUTION_MODE == partial-cleanup-test ]]; then
    run_partial_resource_cleanup_test
    exit 0
fi
if [[ $HOST_GATE_EXECUTION_MODE == exited-container-evidence-test ]]; then
    run_exited_container_evidence_test
    exit 0
fi
image_transport_outer_export "$HOST_GATE_OPERATION"
if [[ $HOST_GATE_EXECUTION_MODE == image-export-test ]]; then
    mapfile -t exported_manifest_evidence \
        < <(image_transport_outer_file_evidence "$HOST_GATE_OPERATION" images.env)
    [[ ${#exported_manifest_evidence[@]} == 2 \
        && ${exported_manifest_evidence[0]} == "$IMAGE_TRANSPORT_MANIFEST_SHA256" \
        && ${exported_manifest_evidence[1]} =~ ^0:0:600:[1-9][0-9]*$ ]] \
        || fail 'pre-host image-export test could not re-read exact manifest evidence'
    printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE image_export_test=passed manifest_sha256=%s container_created=false\n' \
        "$IMAGE_TRANSPORT_MANIFEST_SHA256"
    exit 0
fi
docker run --detach --pull never --platform linux/amd64 --privileged --cgroupns=private \
    --name "$HOST_CONTAINER" \
    --hostname "$HOST_CONTAINER" --network "$OUTER_NETWORK" \
    --tmpfs /run --tmpfs /run/lock --tmpfs /tmp \
    --mount "type=bind,source=$REPOSITORY_ROOT,target=/workspace,readonly" \
    --mount "type=volume,source=$INNER_DOCKER_VOLUME,target=/var/lib/docker" \
    --mount "type=volume,source=$EVIDENCE_VOLUME,target=/evidence" \
    --entrypoint /sbin/init "$CONTROL_PLANE_RUNTIME_HOST_IMAGE" >/dev/null
HOST_GATE_CONTAINER_CREATED=1
assert_isolated_container
wait_for_systemd_host

if run_inner --run; then
    initial_status=0
else
    initial_status=$?
fi
[[ $initial_status -ne 78 ]] \
    || blocked 'the inner host cannot obtain a required real Coolify/Traefik image, TLS, :8000, SSH, provider, or queue input'
[[ $(evidence_value runtime-fence-host.phase) == reboot-pending ]] \
    || fail 'inner runner did not persist a reboot-pending phase before systemctl reboot'
started_before=$(bounded_outer_docker "$HOST_GATE_READINESS_DOCKER_CALL_TIMEOUT_SECONDS" \
    inspect --format '{{.State.StartedAt}}' "$HOST_CONTAINER")
wait_for_container_stop
bounded_outer_docker "$HOST_GATE_READINESS_DOCKER_CALL_TIMEOUT_SECONDS" start "$HOST_CONTAINER" >/dev/null
wait_for_systemd_host
started_after=$(bounded_outer_docker "$HOST_GATE_READINESS_DOCKER_CALL_TIMEOUT_SECONDS" \
    inspect --format '{{.State.StartedAt}}' "$HOST_CONTAINER")
[[ $started_after != "$started_before" ]] \
    || fail 'outer Docker container start time did not change across the systemctl reboot boundary'

if run_inner --resume; then
    :
else
    resume_status=$?
    [[ $resume_status -ne 78 ]] \
        || blocked 'the inner host cannot obtain a required real Coolify/Traefik image, TLS, :8000, SSH, provider, or queue input'
    fail 'inner runner did not converge after the actual disposable-host reboot'
fi
printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_GATE PASS operation=%s host_image=%s isolated=true reboot_boundary=true\n' \
    "$HOST_GATE_OPERATION" "$CONTROL_PLANE_RUNTIME_HOST_IMAGE"
