#!/usr/bin/env bash

set -Eeuo pipefail

TEST_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
REPOSITORY_ROOT=$(cd -- "$TEST_DIRECTORY/../../.." && pwd -P)
readonly TEST_DIRECTORY REPOSITORY_ROOT
readonly UBUNTU_IMAGE=docker.io/library/ubuntu@sha256:4fbb8e6a8395de5a7550b33509421a2bafbc0aab6c06ba2cef9ebffbc7092d90

docker image inspect "$UBUNTU_IMAGE" >/dev/null \
    || { printf '%s\n' 'pinned Ubuntu 24.04 prepare-test image is not present' >&2; exit 1; }

# The single-quoted program intentionally expands only inside the pinned Ubuntu container.
# shellcheck disable=SC2016
docker run --rm --volume "$REPOSITORY_ROOT:/workspace:ro" "$UBUNTU_IMAGE" \
    bash -Eeuo pipefail -c '
        fail()
        {
            printf "CONTROL_PLANE_RUNTIME_FENCE_PREPARE_UBUNTU_FAILURE %s\n" "$1" >&2
            exit 1
        }
        write_environment()
        {
            local destination=$1 management_endpoints=$2
            {
                printf "CONTROL_PLANE_RUNTIME_OPERATION_ID=runtime-fence-prepare-test\n"
                printf "CONTROL_PLANE_RUNTIME_PROXY_CONTAINER=proxy\n"
                printf "CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST=%s\n" "$POOL_FIXTURE_POOL_PLAN_MANIFEST"
                printf "CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256=%s\n" "$POOL_FIXTURE_POOL_PLAN_SHA256"
                printf "CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA=%s\n" \
                    "$(pool_fixture_metadata "$POOL_FIXTURE_POOL_PLAN_MANIFEST")"
                printf "CONTROL_PLANE_RUNTIME_MANAGEMENT_ENDPOINTS=%s\n" "$management_endpoints"
                printf "CONTROL_PLANE_RUNTIME_ADDITIONAL_NETWORK_IDS=\n"
                printf "CONTROL_PLANE_RUNTIME_SELF_SSH_TARGET=host.docker.internal\n"
                printf "CONTROL_PLANE_RUNTIME_PROBE_MAX_AGE_SECONDS=30\n"
                printf "CONTROL_PLANE_RUNTIME_QUEUE_STABLE_SECONDS=2\n"
                printf "CONTROL_PLANE_RUNTIME_PROVIDER_CAPTURE_EXPECTATION=incumbent\n"
                printf "CONTROL_PLANE_RUNTIME_PROVIDER_API_URL=http://127.0.0.1:8080/api/rawdata?token=a=b\n"
                printf "CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER=coolify@docker\n"
                printf "CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE=coolify@docker\n"
                printf "CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT=8080\n"
                printf "CONTROL_PLANE_RUNTIME_PROVIDER_HEADER_FILE=/run/provider-header\n"
                printf "CONTROL_PLANE_RUNTIME_TERMINAL_HTTPS_URL=https://coolify.example/api/health\n"
                printf "CONTROL_PLANE_RUNTIME_TERMINAL_PORT8000_URL=http://127.0.0.1:8000/api/health\n"
            } > "$destination"
            chmod 0600 "$destination"
        }

        expect_prepare_failure()
        {
            local name=$1 source=$2
            if PATH=/usr/bin:/bin /usr/local/sbin/coolify-runtime-fence-provision \
                prepare "$source" > "/run/$name.out" 2>&1; then
                fail "invalid prepare fixture passed: $name"
            fi
        }

        [[ $(. /etc/os-release; printf "%s:%s" "$ID" "$VERSION_ID") == ubuntu:24.04 ]] \
            || fail "container is not Ubuntu 24.04"
        [[ $(readlink -f /usr/bin/awk) == /usr/bin/mawk ]] \
            || fail "Ubuntu /usr/bin/awk is not the expected mawk implementation"
        install -d -m 0700 /etc/coolify-runtime-attestation-ssh-fence /usr/local/libexec
        install -m 0700 /workspace/docker/control-plane-blue-green/controllers/runtime-attestation-ssh-fence.sh \
            /usr/local/libexec/coolify-runtime-attestation-ssh-fence
        install -m 0700 /workspace/docker/control-plane-blue-green/controllers/self-ssh-controlmaster-reaper.sh \
            /usr/local/libexec/coolify-self-ssh-controlmaster-reaper
        install -m 0700 /workspace/docker/control-plane-blue-green/controllers/traefik-docker-provider-freshness-probe.sh \
            /usr/local/libexec/coolify-traefik-provider-freshness-probe
        install -m 0700 /workspace/docker/control-plane-blue-green/controllers/proxy-queue-zero-probe.sh \
            /usr/local/libexec/coolify-proxy-queue-zero-probe
        install -m 0700 /workspace/docker/control-plane-blue-green/controllers/control-plane-terminal-state-probe.sh \
            /usr/local/libexec/coolify-control-plane-terminal-state-probe
        install -m 0700 /workspace/docker/control-plane-blue-green/controllers/provision-runtime-attestation-ssh-fence.sh \
            /usr/local/sbin/coolify-runtime-fence-provision
        install -m 0600 /dev/null /run/provider-header
        source /workspace/tests/Integration/ControlPlaneRuntimeFence/pool-manifest-fixture.bash
        install -d -m 0700 /run/pool-fixture
        prepare_control_plane_pool_plan_fixture \
            /run/pool-fixture runtime-fence-prepare-test 0 0 9999 9999
        chown 9999:9999 "$POOL_FIXTURE_ROUTE_RUNTIME" "$POOL_FIXTURE_POOL_ACK_RUNTIME" \
            "$POOL_FIXTURE_WEB_A_DIRECT_RUNTIME" "$POOL_FIXTURE_WEB_B_DIRECT_RUNTIME" \
            "$POOL_FIXTURE_WEB_A_APPLIED_RUNTIME" "$POOL_FIXTURE_WEB_B_APPLIED_RUNTIME"

        write_environment /run/single.env lo=127.0.0.1
        PATH=/usr/bin:/bin /usr/local/sbin/coolify-runtime-fence-provision prepare /run/single.env \
            | grep -F -x -q "CONTROL_PLANE_RUNTIME_FENCE_PROVISION prepared=true armed=false"
        grep -F -x -q \
            "CONTROL_PLANE_RUNTIME_PROVIDER_API_URL=http://127.0.0.1:8080/api/rawdata?token=a=b" \
            /etc/coolify-runtime-attestation-ssh-fence/runtime.env

        sed "s/^CONTROL_PLANE_RUNTIME_ARMED=0$/CONTROL_PLANE_RUNTIME_ARMED=1/" \
            /etc/coolify-runtime-attestation-ssh-fence/runtime.env > /run/armed.env
        chmod 0600 /run/armed.env
        mv /run/armed.env /etc/coolify-runtime-attestation-ssh-fence/runtime.env
        PATH=/usr/bin:/bin /usr/local/sbin/coolify-runtime-fence-provision prepare /run/single.env \
            | grep -F -x -q \
                "CONTROL_PLANE_RUNTIME_FENCE_PROVISION prepared=true armed=true configuration_unchanged=true"

        write_environment /run/multiple.env lo=127.0.0.1,lo=::1
        expect_prepare_failure armed-configuration-drift /run/multiple.env
        sed "s/^CONTROL_PLANE_RUNTIME_ARMED=1$/CONTROL_PLANE_RUNTIME_ARMED=0/" \
            /etc/coolify-runtime-attestation-ssh-fence/runtime.env > /run/unarmed.env
        chmod 0600 /run/unarmed.env
        mv /run/unarmed.env /etc/coolify-runtime-attestation-ssh-fence/runtime.env
        PATH=/usr/bin:/bin /usr/local/sbin/coolify-runtime-fence-provision prepare /run/multiple.env \
            | grep -F -x -q "CONTROL_PLANE_RUNTIME_FENCE_PROVISION prepared=true armed=false"

        cp /run/single.env /run/duplicate.env
        printf "CONTROL_PLANE_RUNTIME_OPERATION_ID=duplicate\n" >> /run/duplicate.env
        expect_prepare_failure duplicate /run/duplicate.env
        cp /run/single.env /run/unknown.env
        printf "CONTROL_PLANE_RUNTIME_UNKNOWN=value\n" >> /run/unknown.env
        expect_prepare_failure unknown /run/unknown.env
        cp /run/single.env /run/obsolete-runtime-plan.env
        printf "CONTROL_PLANE_RUNTIME_CANDIDATE_RUNTIME_SHA256=7777777777777777777777777777777777777777777777777777777777777777\n" \
            >> /run/obsolete-runtime-plan.env
        expect_prepare_failure obsolete-runtime-plan /run/obsolete-runtime-plan.env
        write_environment /run/malformed-management.env lo=127.0.0.1=unexpected
        expect_prepare_failure malformed-management /run/malformed-management.env
        sed "s#^CONTROL_PLANE_RUNTIME_PROVIDER_API_URL=.*#CONTROL_PLANE_RUNTIME_PROVIDER_API_URL=https://other-proxy.example/api/rawdata#" \
            /run/single.env > /run/non-loopback-provider.env
        chmod 0600 /run/non-loopback-provider.env
        expect_prepare_failure non-loopback-provider /run/non-loopback-provider.env
        for unsafe_provider_url in \
            "http://127.0.0.1:8080@attacker.example/api/rawdata" \
            "http://[::1]:8080@attacker.example/api/rawdata" \
            "http://127.0.0.1.attacker.example:8080/api/rawdata" \
            "http://127.0.0.1:8080/api/rawdata#@attacker.example" \
            "http://127.0.0.1:8080/api/%zz"; do
            sed "s|^CONTROL_PLANE_RUNTIME_PROVIDER_API_URL=.*|CONTROL_PLANE_RUNTIME_PROVIDER_API_URL=${unsafe_provider_url}|" \
                /run/single.env > /run/unsafe-provider.env
            chmod 0600 /run/unsafe-provider.env
            expect_prepare_failure unsafe-provider /run/unsafe-provider.env
        done
        sed "s/^CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT=8080$/CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT=70000/" \
            /run/single.env > /run/provider-port.env
        chmod 0600 /run/provider-port.env
        expect_prepare_failure provider-port /run/provider-port.env
        sed "s#^CONTROL_PLANE_RUNTIME_TERMINAL_PORT8000_URL=.*#CONTROL_PLANE_RUNTIME_TERMINAL_PORT8000_URL=http://127.0.0.1:8001/api/health#" \
            /run/single.env > /run/terminal-port8000.env
        chmod 0600 /run/terminal-port8000.env
        expect_prepare_failure terminal-port8000 /run/terminal-port8000.env
        sed "/^CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256=/d" /run/single.env > /run/missing.env
        chmod 0600 /run/missing.env
        expect_prepare_failure missing /run/missing.env
        cp /run/single.env /run/newline.env
        printf "unexpected-line\n" >> /run/newline.env
        expect_prepare_failure newline /run/newline.env

        printf "CONTROL_PLANE_RUNTIME_FENCE_PREPARE_UBUNTU PASS awk=/usr/bin/mawk endpoints=single,multiple negatives=15 armed_retry=true\n"
    '
