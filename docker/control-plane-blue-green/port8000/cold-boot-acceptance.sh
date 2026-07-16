#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

SCRIPT_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
readonly SCRIPT_DIRECTORY
readonly CONTROLLER="$SCRIPT_DIRECTORY/../controllers/haproxy-port8000.sh"
readonly CONFIG_DIRECTORY=/etc/coolify-control-plane-port8000
readonly RUNTIME_DIRECTORY=/run/coolify-control-plane-port8000
readonly TEST_CONFIG="$CONFIG_DIRECTORY/phase-a.cfg"
readonly TEST_RULESET="$CONFIG_DIRECTORY/active.nft"
readonly TEST_TABLE=coolify_cp_port8000_boot_test
readonly TEST_PUBLIC_PORT=28000
readonly TEST_BOOTSTRAP_PORT=28001
readonly HAPROXY_VERSION=2.8.26
readonly HAPROXY_SOURCE_SHA256=88c28dae25ea46672e66f8db0dadd1fb5920e06ee2415ceb9f281c256b537727
readonly HAPROXY_RUNTIME_BINARY=/usr/local/lib/coolify-control-plane-port8000/haproxy-2.8.26/haproxy
readonly HAPROXY_BUILD_ATTESTATION=/usr/local/lib/coolify-control-plane-port8000/haproxy-2.8.26/provenance

fail()
{
    printf 'CONTROL_PLANE_PORT8000_COLD_BOOT_FAILURE %s\n' "$1" >&2
    exit 1
}

checksum()
{
    sha256sum "$1" | awk '{print $1}'
}

publish_attestation()
{
    local candidate=$1 destination=$2 parent
    [[ $destination == /* ]] || fail 'attestation path must be absolute'
    parent=$(dirname -- "$destination")
    [[ -d $parent && ! -L $parent && $(cd "$parent" && pwd -P) == "$parent" ]] \
        || fail 'attestation parent is absent or symlinked'
    [[ ! -e $destination || -f $destination && ! -L $destination ]] \
        || fail 'attestation destination is not a regular non-symlink file'
    [[ -f $candidate && ! -L $candidate ]] \
        || fail 'attestation candidate is not a regular non-symlink file'
    chmod 600 "$candidate"
    sync "$candidate"
    mv -f -- "$candidate" "$destination"
    sync "$parent"
    [[ -f $destination && ! -L $destination \
        && $(stat -c '%a:%u:%g' "$destination") == 600:0:0 ]] \
        || fail 'published attestation identity is unsafe'
}

assert_exact_host_prerequisites()
{
    local expected_docker_version=${CONTROL_PLANE_PORT8000_EXPECTED_DOCKER_VERSION:-}
    local expected_kernel_release=${CONTROL_PLANE_PORT8000_EXPECTED_KERNEL_RELEASE:-}
    local first_nftables_directive version_line
    [[ $(id -u) -eq 0 ]] || fail 'cold-boot acceptance requires root'
    [[ $expected_docker_version =~ ^[A-Za-z0-9._+-]+$ ]] \
        || fail 'CONTROL_PLANE_PORT8000_EXPECTED_DOCKER_VERSION is required and must be an exact safe version'
    [[ $expected_kernel_release =~ ^[A-Za-z0-9._+-]+$ ]] \
        || fail 'CONTROL_PLANE_PORT8000_EXPECTED_KERNEL_RELEASE is required and must be an exact safe release value'
    [[ $(. /etc/os-release; printf '%s:%s' "$ID" "$VERSION_ID") == ubuntu:24.04 ]] \
        || fail 'cold-boot acceptance is pinned to Ubuntu 24.04'
    [[ $(uname -r) == "$expected_kernel_release" ]] \
        || fail 'kernel release differs from the explicit cold-boot target'
    version_line=$($HAPROXY_RUNTIME_BINARY -v 2>&1 \
        | sed -n '1s/^HAProxy version \([^ ]*\).*/\1/p')
    [[ $version_line =~ ^2\.8\.26(-[0-9A-Fa-f]+)?$ ]] \
        || fail 'exact official HAProxy 2.8.26 runtime is required'
    $HAPROXY_RUNTIME_BINARY -vv 2>&1 \
        | grep -F '+SYSTEMD' >/dev/null \
        || fail 'HAProxy 2.8.26 runtime was not compiled with USE_SYSTEMD=1'
    [[ -d $(dirname -- "$HAPROXY_RUNTIME_BINARY") \
        && ! -L $(dirname -- "$HAPROXY_RUNTIME_BINARY") \
        && $(stat -c '%a:%u:%g' "$(dirname -- "$HAPROXY_RUNTIME_BINARY")") == 755:0:0 \
        && -f $HAPROXY_RUNTIME_BINARY && ! -L $HAPROXY_RUNTIME_BINARY \
        && $(stat -c '%a:%u:%g' "$HAPROXY_RUNTIME_BINARY") == 755:0:0 ]] \
        || fail 'HAProxy atomic runtime publication identity is unsafe'
    [[ -f $HAPROXY_BUILD_ATTESTATION && ! -L $HAPROXY_BUILD_ATTESTATION \
        && $(stat -c '%a:%u:%g' "$HAPROXY_BUILD_ATTESTATION") == 600:0:0 ]] \
        || fail 'HAProxy source-build attestation identity is unsafe'
    grep -F -x -q "haproxy_version=$HAPROXY_VERSION" "$HAPROXY_BUILD_ATTESTATION" \
        && grep -F -x -q "haproxy_source_sha256=$HAPROXY_SOURCE_SHA256" \
            "$HAPROXY_BUILD_ATTESTATION" \
        && grep -F -x -q 'haproxy_build_options=TARGET=linux-glibc USE_SYSTEMD=1' \
            "$HAPROXY_BUILD_ATTESTATION" \
        && grep -F -x -q \
            "haproxy_binary=$HAPROXY_RUNTIME_BINARY" \
            "$HAPROXY_BUILD_ATTESTATION" \
        && grep -F -x -q \
            "haproxy_binary_sha256=$(checksum "$HAPROXY_RUNTIME_BINARY")" \
            "$HAPROXY_BUILD_ATTESTATION" \
        || fail 'HAProxy source-build attestation differs from the exact runtime'
    [[ $(dpkg-query -W -f='${Version}' nftables 2>/dev/null || true) == 1.0.9-1ubuntu0.1 ]] \
        || fail 'exact nftables 1.0.9-1ubuntu0.1 is required'
    [[ $(dpkg-query -W -f='${Version}' conntrack 2>/dev/null || true) == 1:1.4.8-1ubuntu1 ]] \
        || fail 'exact conntrack 1:1.4.8-1ubuntu1 is required'
    [[ -f /etc/nftables.conf && ! -L /etc/nftables.conf ]] || fail 'host nftables.conf is absent or ambiguous'
    first_nftables_directive=$(sed -E '/^[[:space:]]*(#|$)/d; s/^[[:space:]]+//; q' /etc/nftables.conf)
    [[ $first_nftables_directive == 'flush ruleset' ]] \
        || fail 'host nftables.conf differs from the attested global-flush baseline'
    ! systemctl is-enabled --quiet nftables.service 2>/dev/null \
        || fail 'global nftables.service must remain disabled throughout cold-boot acceptance'
    ! systemctl is-active --quiet nftables.service 2>/dev/null \
        || fail 'global nftables.service is active'
    ! systemctl is-enabled --quiet coolify-proxy-rebind.service 2>/dev/null \
        || fail 'coolify-proxy-rebind.service must be separately redesigned or disabled before cold-boot acceptance'
    ! systemctl is-active --quiet coolify-proxy-rebind.service 2>/dev/null \
        || fail 'coolify-proxy-rebind.service is still active'
    [[ -f $CONTROLLER && ! -L $CONTROLLER ]] || fail 'reviewed controller is absent'
    for unit in coolify-port8000-haproxy@.service \
        coolify-port8000-phase-b-authorizer.service coolify-port8000-nft.service; do
        [[ -f /etc/systemd/system/$unit && ! -L /etc/systemd/system/$unit ]] \
            || fail "installed systemd unit is absent: $unit"
        [[ $(checksum "/etc/systemd/system/$unit") == "$(checksum "$SCRIPT_DIRECTORY/$unit")" ]] \
            || fail "installed systemd unit differs from reviewed source: $unit"
    done
    [[ -f /usr/local/libexec/coolify-port8000-apply-active-nft \
        && ! -L /usr/local/libexec/coolify-port8000-apply-active-nft ]] \
        || fail 'installed nftables apply helper is absent'
    [[ $(checksum /usr/local/libexec/coolify-port8000-apply-active-nft) \
        == "$(checksum "$SCRIPT_DIRECTORY/apply-active-nft.sh")" ]] \
        || fail 'installed nftables apply helper differs from reviewed source'
}

assert_live_docker_prerequisite()
{
    local expected_docker_version=${CONTROL_PLANE_PORT8000_EXPECTED_DOCKER_VERSION:?}
    [[ $(docker version --format '{{.Server.Version}}') == "$expected_docker_version" ]] \
        || fail 'Docker Engine version differs from the explicit cold-boot target'
}

assert_recorded_docker_prerequisite()
{
    local state_file=$1 expected_docker_version recorded_docker_version
    expected_docker_version=${CONTROL_PLANE_PORT8000_EXPECTED_DOCKER_VERSION:?}
    [[ -f $state_file && ! -L $state_file ]] \
        || fail 'recorded Docker identity state is absent or unsafe'
    [[ $(grep -F -c 'docker_version=' "$state_file") -eq 1 ]] \
        || fail 'recorded Docker identity is absent or duplicated'
    recorded_docker_version=$(sed -n 's/^docker_version=//p' "$state_file")
    [[ $recorded_docker_version == "$expected_docker_version" ]] \
        || fail 'recorded Docker identity differs from the explicit cold-boot target'
}

assert_nft_failure_blocks_docker()
{
    local backup="$CONFIG_DIRECTORY/.active.failure-test.backup" corrupt="$CONFIG_DIRECTORY/.active.failure-test.corrupt"
    cp -p -- "$TEST_RULESET" "$backup"
    sed '2c# payload-sha256=0000000000000000000000000000000000000000000000000000000000000000' \
        "$TEST_RULESET" > "$corrupt"
    chmod 600 "$corrupt"
    systemctl stop docker.socket docker.service coolify-port8000-nft.service
    mv -f -- "$corrupt" "$TEST_RULESET"
    sync "$CONFIG_DIRECTORY"
    systemctl reset-failed docker.socket docker.service coolify-port8000-nft.service >/dev/null 2>&1 || true
    ! systemctl start docker.socket >/dev/null 2>&1 \
        || fail 'Docker socket started although required nftables restore failed'
    ! systemctl is-active --quiet docker.socket \
        || fail 'Docker socket became active after required nftables restore failure'
    ! systemctl start docker.service >/dev/null 2>&1 \
        || fail 'Docker service started although required nftables restore failed'
    ! systemctl is-active --quiet docker.service \
        || fail 'Docker service became active after required nftables restore failure'
    mv -f -- "$backup" "$TEST_RULESET"
    sync "$CONFIG_DIRECTORY"
    systemctl reset-failed docker.socket docker.service coolify-port8000-nft.service >/dev/null 2>&1 || true
    systemctl start coolify-port8000-nft.service docker.socket docker.service \
        || fail 'Docker did not recover after the valid atomic nftables bundle was restored'
}

write_fixture()
{
    local state_file=$1 config_candidate rules_candidate payload_candidate
    trap 'rm -f -- "$CONFIG_DIRECTORY"/.phase-a.cold-boot.* "$CONFIG_DIRECTORY"/.active.cold-boot.*' RETURN
    [[ ! -e $TEST_CONFIG && ! -e $TEST_RULESET ]] \
        || fail 'a production or prior port-8000 boot intent already exists'
    nft list table inet "$TEST_TABLE" >/dev/null 2>&1 \
        && fail 'cold-boot test nftables table already exists'
    install -d -m 0700 -o root -g root "$CONFIG_DIRECTORY"
    config_candidate="$CONFIG_DIRECTORY/.phase-a.cold-boot.$$"
    {
        printf '%s\n' '# control-plane-operation-id: cold-boot-acceptance'
        printf '%s\n' '# control-plane-instance: phase-a'
        printf '%s\n' 'global'
        printf '    user %s\n' haproxy
        printf '    group %s\n' haproxy
        printf '    stats socket %s/phase-a.sock mode 600 level admin\n' "$RUNTIME_DIRECTORY"
        printf '%s\n' 'defaults'
        printf '%s\n' '    mode http'
        printf '%s\n' '    timeout connect 5s'
        printf '%s\n' '    timeout client 30s'
        printf '%s\n' '    timeout server 30s'
        printf '%s\n' 'frontend cold_boot_fixture'
        printf '    bind :::%s v4v6\n' "$TEST_BOOTSTRAP_PORT"
        printf '%s\n' '    http-request return status 200 content-type text/plain hdr X-Control-Plane-Cold-Boot pass hdr X-Control-Plane-Port-Owner bootstrap-a string ok'
    } > "$config_candidate"
    chmod 600 "$config_candidate"
    $HAPROXY_RUNTIME_BINARY -c -f "$config_candidate" >/dev/null \
        || fail 'cold-boot HAProxy fixture is invalid'
    mv "$config_candidate" "$TEST_CONFIG"

    payload_candidate="$CONFIG_DIRECTORY/.active.cold-boot.payload.$$"
    {
        printf '%s\n' '# coolify-port8000-mode=capture'
        printf '%s\n' '# coolify-port8000-operation=cold-boot-acceptance'
        printf '# coolify-port8000-table=%s\n' "$TEST_TABLE"
        printf 'table inet %s {\n' "$TEST_TABLE"
        printf '%s\n' '    comment "coolify-port8000 operation=cold-boot-acceptance mode=capture"'
        printf '%s\n' '    chain capture_prerouting {'
        printf '%s\n' '        type nat hook prerouting priority -110; policy accept;'
        printf '        meta nfproto ipv4 fib daddr type local tcp dport %s counter redirect to :%s comment "capture-ipv4"\n' "$TEST_PUBLIC_PORT" "$TEST_BOOTSTRAP_PORT"
        printf '        meta nfproto ipv6 fib daddr type local tcp dport %s counter redirect to :%s comment "capture-ipv6"\n' "$TEST_PUBLIC_PORT" "$TEST_BOOTSTRAP_PORT"
        printf '%s\n' '    }'
        printf '%s\n' '    chain capture_output {'
        printf '%s\n' '        type nat hook output priority -110; policy accept;'
        printf '        meta nfproto ipv4 fib daddr type local tcp dport %s counter redirect to :%s comment "capture-ipv4"\n' "$TEST_PUBLIC_PORT" "$TEST_BOOTSTRAP_PORT"
        printf '        meta nfproto ipv6 fib daddr type local tcp dport %s counter redirect to :%s comment "capture-ipv6"\n' "$TEST_PUBLIC_PORT" "$TEST_BOOTSTRAP_PORT"
        printf '%s\n' '    }'
        printf '%s\n' '    chain protect_input {'
        printf '%s\n' '        type filter hook input priority -110; policy accept;'
        printf '        tcp dport %s ct original proto-dst %s counter accept\n' "$TEST_BOOTSTRAP_PORT" "$TEST_PUBLIC_PORT"
        printf '        tcp dport %s counter reject with tcp reset\n' "$TEST_BOOTSTRAP_PORT"
        printf '%s\n' '    }'
        printf '%s\n' '    chain protect_output {'
        printf '%s\n' '        type filter hook output priority -110; policy accept;'
        printf '        tcp dport %s ct original proto-dst %s counter accept\n' "$TEST_BOOTSTRAP_PORT" "$TEST_PUBLIC_PORT"
        printf '        tcp dport %s counter reject with tcp reset\n' "$TEST_BOOTSTRAP_PORT"
        printf '%s\n' '    }'
        printf '%s\n' '}'
    } > "$payload_candidate"
    nft -c -f <(tail -n +4 "$payload_candidate") || fail 'cold-boot nftables fixture is invalid'
    rules_candidate="$CONFIG_DIRECTORY/.active.cold-boot.$$"
    {
        printf '%s\n' '# coolify-port8000-active-bundle-format=1'
        printf '# payload-sha256=%s\n' "$(checksum "$payload_candidate")"
        cat "$payload_candidate"
    } > "$rules_candidate"
    rm -f -- "$payload_candidate"
    chmod 600 "$rules_candidate"
    mv "$rules_candidate" "$TEST_RULESET"

    {
        printf 'version=1\n'
        printf 'hostname=%s\n' "$(hostname -f)"
        printf 'boot_id=%s\n' "$(cat /proc/sys/kernel/random/boot_id)"
        printf 'controller_sha256=%s\n' "$(checksum "$CONTROLLER")"
        printf 'haproxy_unit_sha256=%s\n' "$(checksum "$SCRIPT_DIRECTORY/coolify-port8000-haproxy@.service")"
        printf 'haproxy_authorizer_unit_sha256=%s\n' \
            "$(checksum "$SCRIPT_DIRECTORY/coolify-port8000-phase-b-authorizer.service")"
        printf 'nft_unit_sha256=%s\n' "$(checksum "$SCRIPT_DIRECTORY/coolify-port8000-nft.service")"
        printf 'nft_helper_sha256=%s\n' "$(checksum "$SCRIPT_DIRECTORY/apply-active-nft.sh")"
        printf 'docker_version=%s\n' "$(docker version --format '{{.Server.Version}}')"
        printf 'kernel_release=%s\n' "$(uname -r)"
    } > "$state_file"
    chmod 600 "$state_file"
    systemctl daemon-reload
    systemctl enable coolify-port8000-haproxy@phase-a.service \
        coolify-port8000-haproxy@phase-b.service \
        coolify-port8000-phase-b-authorizer.service coolify-port8000-nft.service >/dev/null
    [[ -L /etc/systemd/system/docker.service.requires/coolify-port8000-nft.service \
        && -L /etc/systemd/system/docker.socket.requires/coolify-port8000-nft.service ]] \
        || fail 'nftables unit is not a required dependency of both Docker activation paths'
    assert_nft_failure_blocks_docker
    sync
}

cleanup_fixture()
{
    systemctl stop coolify-port8000-nft.service >/dev/null 2>&1 || true
    systemctl stop coolify-port8000-haproxy@phase-a.service >/dev/null 2>&1 || true
    if nft list table inet "$TEST_TABLE" >/dev/null 2>&1; then
        nft delete table inet "$TEST_TABLE"
    fi
    [[ ! -e $TEST_CONFIG ]] \
        || grep -F -x -q '# control-plane-operation-id: cold-boot-acceptance' "$TEST_CONFIG" \
        || fail 'refusing to remove a non-fixture phase-A configuration'
    [[ ! -e $TEST_RULESET ]] \
        || grep -F -q 'operation=cold-boot-acceptance' "$TEST_RULESET" \
        || fail 'refusing to remove a non-fixture active nftables ruleset'
    rm -f -- "$TEST_CONFIG" "$TEST_RULESET" \
        "$RUNTIME_DIRECTORY/phase-a.sock" "$RUNTIME_DIRECTORY/phase-a.pid"
    rm -f -- "$CONFIG_DIRECTORY"/.phase-a.cold-boot.* "$CONFIG_DIRECTORY"/.active.cold-boot.*
    systemctl reset-failed coolify-port8000-haproxy@phase-a.service \
        coolify-port8000-nft.service >/dev/null 2>&1 || true
    sync
}

arm_phase_b_reboot()
{
    local state_file=$1 unit=coolify-port8000-haproxy@phase-b.service main_pid worker_pid
    [[ ! -e $state_file && ! -L $state_file ]] \
        || fail 'phase-B reboot challenge state already exists'
    [[ -f /var/lib/coolify-control-plane-port8000/phase-b.boot-identity \
        && ! -L /var/lib/coolify-control-plane-port8000/phase-b.boot-identity ]] \
        || fail 'durable phase-B boot identity is absent'
    [[ ! -e $TEST_CONFIG && ! -L $TEST_CONFIG ]] \
        || fail 'phase-A configuration remains before permanent phase-B reboot challenge'
    ! systemctl is-active --quiet coolify-port8000-haproxy@phase-a.service \
        || fail 'phase-A remains active before permanent phase-B reboot challenge'
    systemctl is-active --quiet "$unit" \
        || fail 'phase-B is not active before permanent reboot challenge'
    main_pid=$(systemctl show --property=MainPID --value "$unit")
    worker_pid=$(pgrep -P "$main_pid" | head -n 1)
    [[ $main_pid =~ ^[1-9][0-9]*$ && $worker_pid =~ ^[1-9][0-9]*$ ]] \
        || fail 'phase-B process identities are absent before reboot challenge'
    {
        printf 'version=1\n'
        printf 'challenge=permanent-phase-b-reboot\n'
        printf 'hostname=%s\n' "$(hostname -f)"
        printf 'boot_id=%s\n' "$(cat /proc/sys/kernel/random/boot_id)"
        printf 'main_pid=%s\n' "$main_pid"
        printf 'worker_pid=%s\n' "$worker_pid"
        printf 'boot_identity_sha256=%s\n' \
            "$(checksum /var/lib/coolify-control-plane-port8000/phase-b.boot-identity)"
        printf 'runtime_binary_sha256=%s\n' "$(checksum "$HAPROXY_RUNTIME_BINARY")"
    } > "$state_file"
    chmod 600 "$state_file"
    sync "$state_file" "$(dirname -- "$state_file")"
}

arm_phase_b_authorizer_crash()
{
    local state_file=$1 dropin_directory
    [[ ${CONTROL_PLANE_PORT8000_PHASE_B_DESTRUCTIVE_ACCEPTANCE:-} == yes ]] \
        || fail 'phase-B authorizer crash challenge requires explicit destructive authorization'
    [[ ! -e $state_file && ! -L $state_file ]] \
        || fail 'phase-B authorizer crash challenge state already exists'
    [[ -f /var/lib/coolify-control-plane-port8000/phase-b.boot-identity \
        && ! -L /var/lib/coolify-control-plane-port8000/phase-b.boot-identity ]] \
        || fail 'durable phase-B boot identity is absent'
    systemctl is-active --quiet coolify-port8000-haproxy@phase-b.service \
        || fail 'phase-B is not active before authorizer crash challenge'
    dropin_directory=/etc/systemd/system/coolify-port8000-phase-b-authorizer.service.d
    install -d -m 0755 -o root -g root "$dropin_directory"
    printf '%s\n' '[Service]' \
        'Environment=CONTROL_PLANE_TEST_PORT8000_CRASH_AT=after-phase-b-boot-allowance-consumed' \
        > "$dropin_directory/crash-after-allowance.conf"
    chmod 0644 "$dropin_directory/crash-after-allowance.conf"
    {
        printf 'version=1\n'
        printf 'challenge=phase-b-authorizer-crash-after-allowance\n'
        printf 'hostname=%s\n' "$(hostname -f)"
        printf 'boot_id=%s\n' "$(cat /proc/sys/kernel/random/boot_id)"
        printf 'docker_version=%s\n' "$(docker version --format '{{.Server.Version}}')"
        printf 'boot_identity_sha256=%s\n' \
            "$(checksum /var/lib/coolify-control-plane-port8000/phase-b.boot-identity)"
    } > "$state_file"
    chmod 600 "$state_file"
    sync "$dropin_directory/crash-after-allowance.conf" "$state_file" \
        "$dropin_directory" "$(dirname -- "$state_file")"
    systemctl daemon-reload
}

verify_phase_b_authorizer_crash()
{
    local state_file=$1 attestation=$2 prior_boot boot_id candidate
    local authorizer=coolify-port8000-phase-b-authorizer.service
    local phase_b=coolify-port8000-haproxy@phase-b.service
    local dropin=/etc/systemd/system/coolify-port8000-phase-b-authorizer.service.d/crash-after-allowance.conf
    [[ ${CONTROL_PLANE_PORT8000_PHASE_B_DESTRUCTIVE_ACCEPTANCE:-} == yes ]] \
        || fail 'phase-B authorizer crash verification requires explicit destructive authorization'
    [[ -f $state_file && ! -L $state_file \
        && $(sed -n 's/^challenge=//p' "$state_file") \
            == phase-b-authorizer-crash-after-allowance ]] \
        || fail 'phase-B authorizer crash challenge state is absent'
    prior_boot=$(sed -n 's/^boot_id=//p' "$state_file")
    boot_id=$(cat /proc/sys/kernel/random/boot_id)
    [[ -n $prior_boot && $prior_boot != "$boot_id" ]] \
        || fail 'phase-B authorizer crash verification did not cross a real reboot'
    [[ $(sed -n 's/^hostname=//p' "$state_file") == "$(hostname -f)" ]] \
        || fail 'phase-B authorizer crash challenge belongs to another host'
    [[ -f $dropin && ! -L $dropin ]] || fail 'phase-B authorizer crash seam was not armed'
    [[ $(sed -n 's/^boot_id=//p' \
        /var/lib/coolify-control-plane-port8000/phase-b.boot-receipt) == "$boot_id" ]] \
        || fail 'phase-B boot allowance was not durably consumed before the crash'
    [[ ! -e $RUNTIME_DIRECTORY/phase-b.start-authorization ]] \
        || fail 'phase-B runtime authorization was published before the crash seam'
    ! systemctl is-active --quiet "$authorizer" \
        || fail 'phase-B authorizer survived the crash seam'
    ! systemctl is-active --quiet "$phase_b" \
        || fail 'phase-B started despite the authorizer crash seam'
    ! systemctl is-active --quiet coolify-port8000-nft.service \
        || fail 'nft activation succeeded despite the authorizer crash seam'
    ! systemctl is-active --quiet docker.service \
        || fail 'Docker started despite the authorizer crash seam'

    rm -f -- "$dropin"
    rmdir /etc/systemd/system/coolify-port8000-phase-b-authorizer.service.d 2>/dev/null || true
    systemctl daemon-reload
    systemctl reset-failed "$authorizer" "$phase_b" coolify-port8000-nft.service \
        docker.socket docker.service >/dev/null 2>&1 || true
    if systemctl start "$authorizer" >/dev/null 2>&1; then
        fail 'same-boot authorizer replay succeeded after durable allowance consumption'
    fi
    if systemctl start "$phase_b" >/dev/null 2>&1; then
        fail 'direct same-boot phase-B start succeeded after authorizer crash'
    fi
    if systemctl start coolify-port8000-nft.service >/dev/null 2>&1; then
        fail 'dependency-triggered same-boot phase-B start succeeded after authorizer crash'
    fi
    if systemctl start docker.service >/dev/null 2>&1; then
        fail 'Docker bypassed failed authorizer/HAProxy/nft dependencies'
    fi
    candidate="${attestation}.new.$$"
    {
        printf 'result=pass\n'
        printf 'acceptance=phase-b-authorizer-crash-after-durable-allowance\n'
        printf 'boot_id_before=%s\n' "$prior_boot"
        printf 'boot_id_after=%s\n' "$boot_id"
        printf 'docker_version=%s\n' \
            "$(sed -n 's/^docker_version=//p' "$state_file")"
        printf 'runtime_authorization_published=no\n'
        printf 'same_boot_authorizer_replay=denied\n'
        printf 'same_boot_direct_start=denied\n'
        printf 'same_boot_dependency_start=denied\n'
        printf 'docker_dependency_bypass=denied\n'
    } > "$candidate"
    publish_attestation "$candidate" "$attestation"
}

verify_after_reboot()
{
    local state_file=$1 attestation=$2 before_boot after_boot haproxy_time nft_time docker_time host_local_url
    local attestation_candidate="${attestation}.new.$$"
    [[ -f $state_file && ! -L $state_file ]] || fail 'cold-boot challenge state is absent'
    before_boot=$(sed -n 's/^boot_id=//p' "$state_file")
    after_boot=$(cat /proc/sys/kernel/random/boot_id)
    [[ -n $before_boot && $before_boot != "$after_boot" ]] || fail 'a real host reboot has not occurred since arm'
    [[ $(sed -n 's/^hostname=//p' "$state_file") == "$(hostname -f)" ]] \
        || fail 'cold-boot challenge belongs to another host'
    [[ $(sed -n 's/^controller_sha256=//p' "$state_file") == "$(checksum "$CONTROLLER")" ]] \
        || fail 'controller changed across the cold-boot challenge'
    [[ $(sed -n 's/^haproxy_authorizer_unit_sha256=//p' "$state_file") \
        == "$(checksum "$SCRIPT_DIRECTORY/coolify-port8000-phase-b-authorizer.service")" ]] \
        || fail 'phase-B authorizer unit changed across the cold-boot challenge'
    [[ $(sed -n 's/^kernel_release=//p' "$state_file") == "$(uname -r)" ]] \
        || fail 'kernel release changed across the cold-boot challenge'
    systemctl is-active --quiet coolify-port8000-haproxy@phase-a.service \
        || fail 'phase-A HAProxy did not recover on cold boot'
    systemctl is-active --quiet coolify-port8000-nft.service \
        || fail 'nftables capture did not recover on cold boot'
    systemctl is-active --quiet docker.service || fail 'Docker did not recover on cold boot'
    haproxy_time=$(systemctl show -p ActiveEnterTimestampMonotonic --value coolify-port8000-haproxy@phase-a.service)
    nft_time=$(systemctl show -p ActiveEnterTimestampMonotonic --value coolify-port8000-nft.service)
    docker_time=$(systemctl show -p ActiveEnterTimestampMonotonic --value docker.service)
    [[ $haproxy_time =~ ^[1-9][0-9]*$ && $nft_time =~ ^[1-9][0-9]*$ && $docker_time =~ ^[1-9][0-9]*$ ]] \
        || fail 'systemd activation ordering timestamps are unavailable'
    ((haproxy_time < nft_time && nft_time < docker_time)) \
        || fail 'cold-boot ordering was not HAProxy -> nftables -> Docker'
    curl --fail --silent --show-error --max-time 5 -D - -o /dev/null \
        "http://127.0.0.1:${TEST_PUBLIC_PORT}/" \
        | grep -F -i 'X-Control-Plane-Cold-Boot: pass' >/dev/null \
        || fail 'cold-boot redirected request did not reach HAProxy'
    ! curl --fail --silent --show-error --max-time 2 \
        "http://127.0.0.1:${TEST_BOOTSTRAP_PORT}/" >/dev/null 2>&1 \
        || fail 'cold-boot bootstrap port was directly reachable'
    nft -s list table inet "$TEST_TABLE" | grep -F 'capture-ipv4' >/dev/null \
        || fail 'cold-boot IPv4 capture rule is absent'
    nft -s list table inet "$TEST_TABLE" | grep -F 'capture-ipv6' >/dev/null \
        || fail 'cold-boot IPv6 capture rule is absent'

    cleanup_fixture
    host_local_url=${CONTROL_PLANE_PORT8000_COLD_BOOT_HOST_LOCAL_URL:-}
    [[ -n $host_local_url ]] \
        || fail 'CONTROL_PLANE_PORT8000_COLD_BOOT_HOST_LOCAL_URL is required for post-cleanup local proof'
    [[ $host_local_url == http://*:8000/* && $host_local_url != *[' ']* ]] \
        || fail 'cold-boot host-local URL must use plain HTTP and explicit TCP/8000'
    curl --noproxy '*' --fail --silent --show-error --max-time 5 "$host_local_url" >/dev/null \
        || fail 'legacy host-local :8000 route failed after cold-boot fixture cleanup'
    {
        printf 'result=pass\n'
        printf 'hostname=%s\n' "$(hostname -f)"
        printf 'boot_id_before=%s\n' "$before_boot"
        printf 'boot_id_after=%s\n' "$after_boot"
        printf 'controller_sha256=%s\n' "$(checksum "$CONTROLLER")"
        printf 'docker_version=%s\n' "$(docker version --format '{{.Server.Version}}')"
        printf 'kernel_release=%s\n' "$(uname -r)"
        printf 'haproxy_version=%s\n' "$HAPROXY_VERSION"
        printf 'haproxy_source_sha256=%s\n' "$HAPROXY_SOURCE_SHA256"
        printf 'haproxy_build_options=TARGET=linux-glibc USE_SYSTEMD=1\n'
        printf 'haproxy_binary_sha256=%s\n' \
            "$(checksum "$HAPROXY_RUNTIME_BINARY")"
        printf 'nftables_package=%s\n' "$(dpkg-query -W -f='${Version}' nftables)"
        printf 'conntrack_package=%s\n' "$(dpkg-query -W -f='${Version}' conntrack)"
        printf 'ordering=haproxy,nftables,docker\n'
        printf 'direct_bootstrap=denied\n'
        printf 'fixture_cleanup=passed\n'
        printf 'host_local_port8000_after_cleanup=passed\n'
        printf 'source_cold_boot_sha256=%s\n' "$(checksum "$0")"
        printf 'source_haproxy_unit_sha256=%s\n' \
            "$(checksum "$SCRIPT_DIRECTORY/coolify-port8000-haproxy@.service")"
        printf 'source_haproxy_authorizer_unit_sha256=%s\n' \
            "$(checksum "$SCRIPT_DIRECTORY/coolify-port8000-phase-b-authorizer.service")"
        printf 'runtime_haproxy_unit_sha256=%s\n' \
            "$(checksum /etc/systemd/system/coolify-port8000-haproxy@.service)"
        printf 'runtime_haproxy_authorizer_unit_sha256=%s\n' \
            "$(checksum /etc/systemd/system/coolify-port8000-phase-b-authorizer.service)"
    } > "$attestation_candidate"
    publish_attestation "$attestation_candidate" "$attestation"
}

verify_phase_b_systemd()
{
    local attestation=$1 challenge_state=$2 unit=coolify-port8000-haproxy@phase-b.service
    local main_pid main_start worker_pid worker_start boot_id prior_boot policy_log attempt=0
    local operation_directory haproxy_time docker_time host_local_url
    local config_file pool_state_file server_state_base server_state_file attestation_candidate
    [[ ${CONTROL_PLANE_PORT8000_PHASE_B_DESTRUCTIVE_ACCEPTANCE:-} == yes ]] \
        || fail 'phase-B systemd acceptance requires explicit destructive authorization'
    [[ -n $attestation ]] || fail 'phase-B systemd acceptance requires an attestation output path'
    [[ -f $challenge_state && ! -L $challenge_state \
        && $(sed -n 's/^challenge=//p' "$challenge_state") == permanent-phase-b-reboot ]] \
        || fail 'phase-B systemd acceptance requires an armed permanent reboot challenge'
    prior_boot=$(sed -n 's/^boot_id=//p' "$challenge_state")
    boot_id=$(cat /proc/sys/kernel/random/boot_id)
    [[ -n $prior_boot && $prior_boot != "$boot_id" ]] \
        || fail 'phase-B systemd acceptance did not cross a real reboot boundary'
    [[ $(sed -n 's/^hostname=//p' "$challenge_state") == "$(hostname -f)" ]] \
        || fail 'phase-B reboot challenge belongs to another host'
    [[ ! -e $TEST_CONFIG && ! -L $TEST_CONFIG ]] \
        || fail 'phase-A configuration reappeared after permanent phase-B reboot'
    ! systemctl is-active --quiet coolify-port8000-haproxy@phase-a.service \
        || fail 'phase-A restarted after permanent phase-B reboot'
    systemctl is-active --quiet docker.service \
        || fail 'Docker is not active after permanent phase-B reboot'
    operation_directory=${CONTROL_PLANE_INGRESS_OPERATION_DIR:-}
    [[ $operation_directory == /* && -d $operation_directory && ! -L $operation_directory ]] \
        || fail 'phase-B systemd acceptance requires the exact operation directory'
    config_file=/etc/coolify-control-plane-port8000/phase-b.cfg
    pool_state_file="$operation_directory/pool-state"
    [[ -f $config_file && ! -L $config_file && -f $pool_state_file && ! -L $pool_state_file ]] \
        || fail 'phase-B systemd acceptance config or pool state is unsafe'
    server_state_base=$(sed -n 's/^[[:space:]]*server-state-base //p' "$config_file")
    [[ $server_state_base == /* ]] || fail 'phase-B server-state base is absent from config'
    server_state_file="$server_state_base/server-state"
    [[ -f $server_state_file && ! -L $server_state_file ]] \
        || fail 'phase-B restored server-state file is unsafe'
    [[ $(systemctl show --property=Restart --value "$unit") == no ]] \
        || fail 'phase-B unit still permits an automatic same-boot restart'
    systemctl is-active --quiet "$unit" || fail 'phase-B HAProxy is not active before acceptance'
    main_pid=$(systemctl show --property=MainPID --value "$unit")
    [[ $main_pid =~ ^[1-9][0-9]*$ ]] || fail 'phase-B systemd MainPID is absent'
    main_start=$(awk '{ line=$0; sub(/^.*\) /, "", line); split(line, field, " "); print field[20] }' \
        "/proc/$main_pid/stat")
    [[ $main_start =~ ^[0-9]+$ ]] || fail 'phase-B systemd MainPID start time is unavailable'
    worker_pid=$(pgrep -P "$main_pid" | head -n 1)
    [[ $worker_pid =~ ^[1-9][0-9]*$ ]] || fail 'phase-B worker PID is absent'
    worker_start=$(awk '{ line=$0; sub(/^.*\) /, "", line); split(line, field, " "); print field[20] }' \
        "/proc/$worker_pid/stat")
    [[ $worker_start =~ ^[0-9]+$ ]] || fail 'phase-B worker start time is unavailable'
    [[ ! -e $RUNTIME_DIRECTORY/phase-b.start-authorization \
        && $(sed -n 's/^boot_id=//p' \
            /var/lib/coolify-control-plane-port8000/phase-b.boot-receipt) == "$boot_id" ]] \
        || fail 'phase-B boot authorization was not consumed exactly once'
    haproxy_time=$(systemctl show -p ActiveEnterTimestampMonotonic --value "$unit")
    docker_time=$(systemctl show -p ActiveEnterTimestampMonotonic --value docker.service)
    [[ $haproxy_time =~ ^[1-9][0-9]*$ && $docker_time =~ ^[1-9][0-9]*$ \
        && $haproxy_time -lt $docker_time ]] \
        || fail 'permanent phase-B did not become active before Docker'
    host_local_url=${CONTROL_PLANE_PORT8000_COLD_BOOT_HOST_LOCAL_URL:-}
    [[ $host_local_url == http://*:8000/* && $host_local_url != *[' ']* ]] \
        || fail 'phase-B reboot acceptance requires a host-local HTTP :8000 URL'
    curl --noproxy '*' --fail --silent --show-error --max-time 5 "$host_local_url" >/dev/null \
        || fail 'permanent phase-B did not serve :8000 after reboot'
    if systemctl reload "$unit" >/dev/null 2>&1; then
        fail 'phase-B systemd reload unexpectedly succeeded'
    fi
    [[ $(systemctl show --property=MainPID --value "$unit") == "$main_pid" \
        && $(pgrep -P "$main_pid" | head -n 1) == "$worker_pid" \
        && $(awk '{ line=$0; sub(/^.*\) /, "", line); split(line, field, " "); print field[20] }' \
            "/proc/$main_pid/stat") == "$main_start" \
        && $(awk '{ line=$0; sub(/^.*\) /, "", line); split(line, field, " "); print field[20] }' \
            "/proc/$worker_pid/stat") == "$worker_start" ]] \
        || fail 'phase-B reload rejection changed MainPID or worker identity'
    policy_log="${attestation}.policy"
    "$CONTROLLER" policy-status > "$policy_log" \
        || fail 'phase-B controller policy proof failed before same-boot crash acceptance'
    grep -F -q 'CONTROL_PLANE_PORT8000_POLICY result=pass' "$policy_log" \
        || fail 'phase-B controller omitted its public pool policy attestation'

    systemctl kill --kill-whom=all --signal=SIGKILL "$unit" \
        || fail 'phase-B same-boot SIGKILL injection failed'
    while ((attempt < 50)); do
        [[ $(systemctl show --property=MainPID --value "$unit") == 0 ]] && break
        attempt=$((attempt + 1))
        sleep 0.1
    done
    [[ $(systemctl show --property=MainPID --value "$unit") == 0 ]] \
        || fail 'phase-B MainPID survived the same-boot SIGKILL'
    sleep 2
    [[ $(systemctl show --property=MainPID --value "$unit") == 0 \
        && $(systemctl show --property=Restart --value "$unit") == no ]] \
        || fail 'phase-B unit automatically restarted after the same-boot SIGKILL'
    if "$CONTROLLER" policy-status >/dev/null 2>&1; then
        fail 'phase-B controller accepted a replacement runtime after its same-boot PID was pinned'
    fi
    [[ $(systemctl show --property=MainPID --value "$unit") == 0 ]] \
        || fail 'phase-B controller re-entry started a replacement same-boot MainPID'
    systemctl reset-failed "$unit" >/dev/null 2>&1 || true
    if systemctl start "$unit" >/dev/null 2>&1; then
        fail 'direct same-boot systemd start bypassed phase-B authorization'
    fi
    [[ $(systemctl show --property=MainPID --value "$unit") == 0 ]] \
        || fail 'direct same-boot systemd start created a replacement MainPID'
    systemctl reset-failed "$unit" >/dev/null 2>&1 || true
    if systemctl restart coolify-port8000-nft.service >/dev/null 2>&1; then
        fail 'nftables dependency succeeded without the selected phase-B HAProxy'
    fi
    ! systemctl is-active --quiet coolify-port8000-nft.service \
        || fail 'nftables dependency remained successful without selected phase-B HAProxy'
    [[ $(systemctl show --property=MainPID --value "$unit") == 0 ]] \
        || fail 'dependency-triggered same-boot start created a replacement phase-B MainPID'
    systemctl stop docker.socket docker.service
    systemctl reset-failed docker.socket docker.service coolify-port8000-nft.service \
        >/dev/null 2>&1 || true
    if systemctl start docker.service >/dev/null 2>&1; then
        fail 'Docker started after selected phase-B HAProxy authorization was unavailable'
    fi
    ! systemctl is-active --quiet docker.service \
        || fail 'Docker became active after selected phase-B HAProxy authorization failure'
    attestation_candidate="${attestation}.new.$$"
    {
        printf 'result=pass\n'
        printf 'acceptance=phase-b-systemd-2.8.26\n'
        printf 'boot_id=%s\n' "$boot_id"
        printf 'prior_boot_id=%s\n' "$prior_boot"
        printf 'pinned_main_pid=%s\n' "$main_pid"
        printf 'pinned_main_start_time=%s\n' "$main_start"
        printf 'pinned_worker_pid=%s\n' "$worker_pid"
        printf 'pinned_worker_start_time=%s\n' "$worker_start"
        printf 'haproxy_version=%s\n' "$HAPROXY_VERSION"
        printf 'haproxy_source_sha256=%s\n' "$HAPROXY_SOURCE_SHA256"
        printf 'haproxy_build_options=TARGET=linux-glibc USE_SYSTEMD=1\n'
        printf 'systemd_restart=no\n'
        printf 'pre_crash_pool_policy=pass\n'
        printf 'same_boot_automatic_restart=denied\n'
        printf 'same_boot_controller_replacement=denied\n'
        printf 'same_boot_direct_start=denied\n'
        printf 'same_boot_dependency_start=denied\n'
        printf 'selected_haproxy_failure_blocks_nft=passed\n'
        printf 'selected_haproxy_failure_blocks_docker=passed\n'
        printf 'cold_boot_phase_b_before_docker=passed\n'
        printf 'cold_boot_phase_a_absent=passed\n'
        printf 'phase_b_reload=denied_identity_unchanged\n'
        printf 'source_controller_sha256=%s\n' "$(checksum "$CONTROLLER")"
        printf 'source_cold_boot_sha256=%s\n' "$(checksum "$0")"
        printf 'source_haproxy_unit_sha256=%s\n' \
            "$(checksum "$SCRIPT_DIRECTORY/coolify-port8000-haproxy@.service")"
        printf 'source_haproxy_authorizer_unit_sha256=%s\n' \
            "$(checksum "$SCRIPT_DIRECTORY/coolify-port8000-phase-b-authorizer.service")"
        printf 'runtime_haproxy_unit_sha256=%s\n' \
            "$(checksum /etc/systemd/system/coolify-port8000-haproxy@.service)"
        printf 'runtime_haproxy_authorizer_unit_sha256=%s\n' \
            "$(checksum /etc/systemd/system/coolify-port8000-phase-b-authorizer.service)"
        printf 'runtime_haproxy_binary_sha256=%s\n' \
            "$(checksum "$HAPROXY_RUNTIME_BINARY")"
        printf 'runtime_phase_b_config_sha256=%s\n' "$(checksum "$config_file")"
        printf 'runtime_pool_state_sha256=%s\n' "$(checksum "$pool_state_file")"
        printf 'runtime_server_state_sha256=%s\n' "$(checksum "$server_state_file")"
    } > "$attestation_candidate"
    publish_attestation "$attestation_candidate" "$attestation"
    rm -f -- "$policy_log"
}

action=${1:-}
state_file=${2:-}
attestation=${3:-}
[[ -n $state_file ]] || fail 'usage: cold-boot-acceptance.sh {arm|arm-phase-b|arm-phase-b-crash|verify|verify-phase-b|verify-phase-b-crash|cleanup} STATE_FILE [ATTESTATION_FILE]'
assert_exact_host_prerequisites
if [[ $action == verify-phase-b-crash ]]; then
    assert_recorded_docker_prerequisite "$state_file"
else
    assert_live_docker_prerequisite
fi

case "$action" in
    arm)
        [[ ! -e $state_file ]] || fail 'cold-boot challenge state already exists'
        write_fixture "$state_file"
        printf '%s\n' 'CONTROL_PLANE_PORT8000_COLD_BOOT armed=true reboot_required=true production_port8000_unchanged=true'
        ;;
    arm-phase-b)
        arm_phase_b_reboot "$state_file"
        printf '%s\n' 'CONTROL_PLANE_PORT8000_PHASE_B_COLD_BOOT armed=true reboot_required=true phase_a_absent=true'
        ;;
    arm-phase-b-crash)
        arm_phase_b_authorizer_crash "$state_file"
        printf '%s\n' 'CONTROL_PLANE_PORT8000_PHASE_B_AUTHORIZER_CRASH armed=true reboot_required=true'
        ;;
    verify)
        [[ -n $attestation ]] || fail 'verify requires an attestation output path'
        verify_after_reboot "$state_file" "$attestation"
        printf 'CONTROL_PLANE_PORT8000_COLD_BOOT result=pass attestation=%s\n' "$attestation"
        ;;
    verify-phase-b)
        [[ -n $attestation ]] || fail 'verify-phase-b requires an attestation output path'
        verify_phase_b_systemd "$attestation" "$state_file"
        printf 'CONTROL_PLANE_PORT8000_PHASE_B_SYSTEMD result=pass attestation=%s service_left_failed=true\n' \
            "$attestation"
        ;;
    verify-phase-b-crash)
        [[ -n $attestation ]] || fail 'verify-phase-b-crash requires an attestation output path'
        verify_phase_b_authorizer_crash "$state_file" "$attestation"
        printf 'CONTROL_PLANE_PORT8000_PHASE_B_AUTHORIZER_CRASH result=pass attestation=%s host_left_fail_closed=true\n' \
            "$attestation"
        ;;
    cleanup)
        cleanup_fixture
        printf '%s\n' 'CONTROL_PLANE_PORT8000_COLD_BOOT cleanup=complete attestation=not-issued'
        ;;
    *)
        fail 'usage: cold-boot-acceptance.sh {arm|arm-phase-b|arm-phase-b-crash|verify|verify-phase-b|verify-phase-b-crash|cleanup} STATE_FILE [ATTESTATION_FILE]'
        ;;
esac
