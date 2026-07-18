#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

SCRIPT_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
readonly SCRIPT_DIRECTORY
REPOSITORY_ROOT=$(cd -- "$SCRIPT_DIRECTORY/../../.." && pwd -P)
readonly REPOSITORY_ROOT
readonly CONTROLLER="$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/haproxy-port8000.sh"
readonly PORT8000_ASSETS="$REPOSITORY_ROOT/docker/control-plane-blue-green/port8000"
readonly EVIDENCE_DIRECTORY="$SCRIPT_DIRECTORY/.evidence"
readonly BASELINE_IMAGE='docker:28.4.0-dind@sha256:2ceb471176ad51e37145d43ce7cbf0fa5d644a2b185bd537f0ef695fb3a37497'
readonly REGRESSION_IMAGE='docker:29.5.0-dind@sha256:8e3fae900cbfbdc14e8abca89a9e44363065cb535f34a09283c59cc0dde2de20'
readonly TARGET_IMAGE='docker:29.6.1-dind@sha256:66d292e5c26bd33a6f6f61cacb880de2186339a524ecba1ce098dbbaceed6515'

fail()
{
    printf 'CONTROL_PLANE_PORT8000_MATRIX_FAILURE %s\n' "$1" >&2
    exit 1
}

checksum()
{
    sha256sum "$1" | awk '{print $1}'
}

source_bundle_checksum()
{
    local file
    for file in "$@"; do
        printf '%s  %s\n' "$(checksum "$file")" "$(basename "$file")"
    done | LC_ALL=C sort | sha256sum | awk '{print $1}'
}

assert_static_contracts()
{
    bash -n "$CONTROLLER" "$PORT8000_ASSETS"/*.sh "$SCRIPT_DIRECTORY"/*.sh
    [[ -x $PORT8000_ASSETS/apply-active-nft.sh ]] \
        || fail 'boot nft helper is not executable'
    grep -F -x -q 'RequiredBy=docker.service docker.socket' \
        "$PORT8000_ASSETS/coolify-port8000-nft.service" \
        || fail 'nft restore is not required by both Docker activation paths'
    ! grep -F -q 'ConditionPathExists=' "$PORT8000_ASSETS/coolify-port8000-nft.service" \
        || fail 'conditional nft restore could let Docker bypass a missing/invalid boot object'
    ! grep -E -q 'http-response[[:space:]]+set-header[[:space:]]+X-Control-Plane-(Applied-Config|Route-Ack|Color)' "$CONTROLLER" \
        || fail 'HAProxy still generates a self-fulfilling control-plane acknowledgement'
    ! grep -R -F -q 'active.nft.sha256' "$CONTROLLER" "$PORT8000_ASSETS" \
        || fail 'crash-torn nft checksum sidecar contract still exists'
}

run_cell()
{
    local cell=$1 version=$2 image=$3 backend=$4 proxy=$5 container status
    [[ -z ${PORT8000_CELL:-} || ${PORT8000_CELL:-} == "$cell" ]] || return 0
    container="coolify-port8000-${cell//[^A-Za-z0-9_.-]/-}-$$"
    mkdir -p "$EVIDENCE_DIRECTORY/$cell"
    printf 'CONTROL_PLANE_PORT8000_MATRIX cell=%s status=starting\n' "$cell"
    docker run -d --privileged --cgroupns=host --name "$container" \
        --hostname "$cell" \
        --entrypoint /bin/sh \
        --mount type=bind,src=/sys/fs/cgroup,dst=/sys/fs/cgroup \
        --mount "type=bind,src=$SCRIPT_DIRECTORY,dst=/workspace/tests,readonly" \
        --mount "type=bind,src=$CONTROLLER,dst=/workspace/docker/control-plane-blue-green/controllers/haproxy-port8000.sh,readonly" \
        --mount "type=bind,src=$PORT8000_ASSETS,dst=/workspace/docker/control-plane-blue-green/port8000,readonly" \
        --mount "type=bind,src=$EVIDENCE_DIRECTORY/$cell,dst=/evidence" \
        "$image" -c 'while :; do sleep 3600; done' >/dev/null
    trap 'docker rm -f "$container" >/dev/null 2>&1 || true' RETURN
    docker exec "$container" /bin/sh -ec '
        attempt=0
        until apk add --no-cache bash >/dev/null; do
            attempt=$((attempt + 1))
            test "$attempt" -lt 4
            sleep 2
        done
    ' \
        > "$EVIDENCE_DIRECTORY/$cell/bash-bootstrap.log" 2>&1 \
        || fail "cell=$cell could not install its pinned-image shell prerequisite"
    set +e
    docker exec \
        --env "LAB_CELL=$cell" \
        --env "LAB_DOCKER_VERSION=$version" \
        --env "LAB_DIND_IMAGE=$image" \
        --env "LAB_FIREWALL_BACKEND=$backend" \
        --env "LAB_USERLAND_PROXY=$proxy" \
        "$container" /workspace/tests/cell.sh \
        > "$EVIDENCE_DIRECTORY/$cell/cell.log" 2>&1
    status=$?
    set -e
    docker logs "$container" > "$EVIDENCE_DIRECTORY/$cell/outer.log" 2>&1 || true
    docker rm -f "$container" >/dev/null
    trap - RETURN
    [[ $status -eq 0 ]] || fail "cell=$cell exited=$status evidence=$EVIDENCE_DIRECTORY/$cell"
    [[ -f $EVIDENCE_DIRECTORY/$cell/result ]] || fail "cell=$cell omitted its result evidence"
    grep -F -x -q 'result=pass' "$EVIDENCE_DIRECTORY/$cell/result" \
        || fail "cell=$cell did not pass"
    if ! grep -F -x -q 'result_version=2' "$EVIDENCE_DIRECTORY/$cell/result" \
        && grep -F -x -q "cell=$cell" "$EVIDENCE_DIRECTORY/$cell/result" \
        && grep -F -x -q "docker_version=$version" "$EVIDENCE_DIRECTORY/$cell/result" \
        && grep -F -x -q "image_ref=$image" "$EVIDENCE_DIRECTORY/$cell/result" \
        && grep -F -x -q "controller_sha256=$(checksum "$CONTROLLER")" "$EVIDENCE_DIRECTORY/$cell/result" \
        && grep -F -x -q "test_bundle_sha256=$(source_bundle_checksum \
            "$SCRIPT_DIRECTORY/run.sh" "$SCRIPT_DIRECTORY/cell.sh" \
            "$SCRIPT_DIRECTORY/backend.py" "$SCRIPT_DIRECTORY/traffic.py")" "$EVIDENCE_DIRECTORY/$cell/result" \
        && grep -F -x -q "haproxy_unit_sha256=$(checksum "$PORT8000_ASSETS/coolify-port8000-haproxy@.service")" "$EVIDENCE_DIRECTORY/$cell/result" \
        && grep -F -x -q "nft_unit_sha256=$(checksum "$PORT8000_ASSETS/coolify-port8000-nft.service")" "$EVIDENCE_DIRECTORY/$cell/result" \
        && grep -F -x -q "nft_helper_sha256=$(checksum "$PORT8000_ASSETS/apply-active-nft.sh")" "$EVIDENCE_DIRECTORY/$cell/result"
    then
        fail "cell=$cell result evidence is stale or belongs to different source/image assets"
    fi
    printf 'CONTROL_PLANE_PORT8000_MATRIX cell=%s status=passed\n' "$cell"
}

main()
{
    command -v docker >/dev/null 2>&1 || fail 'docker CLI is unavailable'
    [[ -f $CONTROLLER && -x $CONTROLLER ]] || fail 'production controller is absent or not executable'
    assert_static_contracts
    mkdir -p "$EVIDENCE_DIRECTORY"

    # Exact sf0 baseline. This proves the current iptables-via-nft/userland-proxy behavior only.
    run_cell baseline-28.4.0-iptables-proxy 28.4.0 "$BASELINE_IMAGE" iptables true

    # 29.5.0 is retained strictly as the originally reported regression surface, not an upgrade target.
    run_cell regression-29.5.0-iptables-proxy 29.5.0 "$REGRESSION_IMAGE" iptables true
    run_cell regression-29.5.0-iptables-noproxy 29.5.0 "$REGRESSION_IMAGE" iptables false
    run_cell regression-29.5.0-nftables-proxy 29.5.0 "$REGRESSION_IMAGE" nftables true
    run_cell regression-29.5.0-nftables-noproxy 29.5.0 "$REGRESSION_IMAGE" nftables false

    # 29.6.1 is a separate maintenance upgrade target. The production-compatible gate preserves iptables-nft.
    run_cell target-29.6.1-iptables-proxy 29.6.1 "$TARGET_IMAGE" iptables true
    run_cell target-29.6.1-iptables-noproxy 29.6.1 "$TARGET_IMAGE" iptables false

    # Native nftables remains experimental and is tested as a separate future migration, never bundled with upgrade.
    run_cell experimental-29.6.1-nftables-proxy 29.6.1 "$TARGET_IMAGE" nftables true
    run_cell experimental-29.6.1-nftables-noproxy 29.6.1 "$TARGET_IMAGE" nftables false

    if [[ -n ${PORT8000_CELL:-} ]]; then
        [[ -f $EVIDENCE_DIRECTORY/$PORT8000_CELL/result ]] \
            || fail "requested cell did not match the matrix: $PORT8000_CELL"
    fi
    find "$EVIDENCE_DIRECTORY" -mindepth 2 -maxdepth 2 -name result -type f -print0 \
        | sort -z | xargs -0 cat > "$EVIDENCE_DIRECTORY/results.txt"
    printf 'CONTROL_PLANE_PORT8000_MATRIX result=pass evidence=%s\n' "$EVIDENCE_DIRECTORY"
}

main "$@"
