#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
readonly SCRIPT_DIRECTORY
REPOSITORY_ROOT=${1:?repository root is required}
readonly REPOSITORY_ROOT
ORIGINAL_PATH=$PATH
readonly ORIGINAL_PATH
SIMULATION_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/production-image-supply-chain.XXXXXX")
SIMULATION_ROOT=$(cd "$SIMULATION_ROOT" && pwd -P)
readonly SIMULATION_ROOT

trap 'rm -rf -- "$SIMULATION_ROOT"' EXIT

fail()
{
    printf 'PRODUCTION_IMAGE_SUPPLY_CHAIN_SIMULATION_FAILURE %s\n' "$1" >&2
    exit 1
}

run_provenance_verifier_simulation()
{
    local output status verifier
    verifier="$REPOSITORY_ROOT/docker/verify-source-provenance.sh"

    for dockerfile in docker/production/Dockerfile docker/testing-host/Dockerfile; do
        if ! (
            cd "$REPOSITORY_ROOT"
            PATH="$SCRIPT_DIRECTORY/bin:$ORIGINAL_PATH" /bin/sh "$verifier" "$dockerfile"
        ) >/dev/null 2>&1; then
            fail "pinned source provenance verifier rejected $dockerfile under its simulated official tag mapping"
        fi
    done

    set +e
    output=$(
        cd "$REPOSITORY_ROOT"
        PRODUCTION_IMAGE_SUPPLY_CHAIN_SIMULATION_MISMATCH=1 \
            PATH="$SCRIPT_DIRECTORY/bin:$ORIGINAL_PATH" \
            /bin/sh "$verifier" docker/production/Dockerfile 2>&1
    )
    status=$?
    set -e

    [[ $status -ne 0 ]] || fail 'pinned source provenance verifier accepted a simulated tag-to-commit mismatch'
}

prepare_controller_function_library()
{
    local controller_directory controller_functions fixture_root
    fixture_root="$SIMULATION_ROOT/controller-functions"
    controller_directory="$SIMULATION_ROOT/controller-functions/controllers"
    controller_functions="$controller_directory/haproxy-port8000.sh"

    if [[ -f $controller_functions ]]; then
        printf '%s\n' "$controller_functions"

        return
    fi

    mkdir -p "$controller_directory" "$fixture_root/systemd" "$fixture_root/libexec" \
        "$fixture_root/runtime"
    ln -s "$REPOSITORY_ROOT/docker/control-plane-blue-green/port8000" \
        "$SIMULATION_ROOT/controller-functions/port8000"
    cp "$REPOSITORY_ROOT/docker/control-plane-blue-green/port8000/coolify-port8000-haproxy@.service" \
        "$fixture_root/systemd/coolify-port8000-haproxy@.service"
    cp "$REPOSITORY_ROOT/docker/control-plane-blue-green/port8000/coolify-port8000-phase-b-authorizer.service" \
        "$fixture_root/systemd/coolify-port8000-phase-b-authorizer.service"
    cp "$REPOSITORY_ROOT/docker/control-plane-blue-green/port8000/coolify-port8000-nft.service" \
        "$fixture_root/systemd/coolify-port8000-nft.service"
    cp "$REPOSITORY_ROOT/docker/control-plane-blue-green/port8000/apply-active-nft.sh" \
        "$fixture_root/libexec/coolify-port8000-apply-active-nft"
    printf '%s\n' 'ID=ubuntu' 'VERSION_ID=24.04' > "$SIMULATION_ROOT/controller-functions/os-release"
    printf '%s\n' 'flush ruleset' > "$SIMULATION_ROOT/controller-functions/nftables.conf"
    {
        printf '%s\n' version=1 haproxy_version=2.8.26 \
            haproxy_source_sha256=88c28dae25ea46672e66f8db0dadd1fb5920e06ee2415ceb9f281c256b537727 \
            'haproxy_build_options=TARGET=linux-glibc USE_SYSTEMD=1' \
            "haproxy_binary=$SCRIPT_DIRECTORY/bin/haproxy"
        printf 'haproxy_binary_sha256=%s\n' \
            "$(sha256sum "$SCRIPT_DIRECTORY/bin/haproxy" | awk '{print $1}')"
    } > "$fixture_root/runtime/provenance"
    chmod 600 "$fixture_root/runtime/provenance"
    awk '$0 == "action=${1:-}" { exit } { print }' \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/haproxy-port8000.sh" \
        | sed \
            -e "s|/etc/os-release|$SIMULATION_ROOT/controller-functions/os-release|g" \
            -e "s|/etc/nftables.conf|$SIMULATION_ROOT/controller-functions/nftables.conf|g" \
            -e "s|/etc/systemd/system|$fixture_root/systemd|g" \
            -e "s|/usr/local/libexec|$fixture_root/libexec|g" \
            -e "s|/usr/local/lib/coolify-control-plane-port8000/haproxy-2.8.26/provenance|$fixture_root/runtime/provenance|g" \
            > "$controller_functions"
    chmod 700 "$controller_functions"
    cp "$controller_functions" "$fixture_root/libexec/coolify-haproxy-port8000-controller"
    chmod 700 "$fixture_root/libexec/coolify-haproxy-port8000-controller"
    printf '%s\n' "$controller_functions"
}

run_controller_prerequisite_case()
{
    local expected_docker_version=$1 simulated_docker_version=$2 expected_kernel_release=$3 source_env_file=$4
    local cold_boot_attestation=$5 expected_failure=${6:-} controller_functions output status
    controller_functions=$(prepare_controller_function_library)

    set +e
    output=$( \
        CONTROLLER_FUNCTIONS="$controller_functions" \
        SIM_ROOT="$SIMULATION_ROOT" \
        PATH="$SCRIPT_DIRECTORY/bin:$ORIGINAL_PATH" \
        PRODUCTION_IMAGE_SUPPLY_CHAIN_SIMULATED_DOCKER_VERSION="$simulated_docker_version" \
        EXPECTED_DOCKER_VERSION="$expected_docker_version" \
        EXPECTED_KERNEL_RELEASE="$expected_kernel_release" \
        SOURCE_ENV_FILE="$source_env_file" \
        COLD_BOOT_ATTESTATION="$cold_boot_attestation" \
        SIMULATED_HAPROXY="$SCRIPT_DIRECTORY/bin/haproxy" \
        bash -c '
            source "$CONTROLLER_FUNCTIONS"

            service_mode=systemd
            expected_docker_version="$EXPECTED_DOCKER_VERSION"
            expected_kernel_release="$EXPECTED_KERNEL_RELEASE"
            source_env_file="$SOURCE_ENV_FILE"
            cold_boot_attestation="$COLD_BOOT_ATTESTATION"
            haproxy_binary="$SIMULATED_HAPROXY"
            expected_nftables_package=1.0.9-1ubuntu0.1
            expected_conntrack_package=1:1.4.8-1ubuntu1

            if [[ -e $cold_boot_attestation || -L $cold_boot_attestation ]]; then
                {
                    printf "%s\n" result=pass
                    printf "hostname=%s\n" "$(hostname -f)"
                    printf "controller_sha256=%s\n" "$(checksum "$0")"
                } > "$cold_boot_attestation"
            fi

            assert_production_prerequisites
        ' "$controller_functions" 2>&1
    )
    status=$?
    set -e

    if [[ -z $expected_failure ]]; then
        [[ $status -eq 0 ]] || fail "controller rejected valid regular-file prerequisites: $output"

        return
    fi

    [[ $status -ne 0 ]] || fail "controller accepted simulated invalid prerequisite: $expected_failure"
    [[ $output == *"$expected_failure"* ]] || fail "controller rejected a simulated prerequisite for an unexpected reason: $output"
}

run_controller_prerequisite_simulation()
{
    local source_env_file source_env_target cold_boot_attestation cold_boot_attestation_target
    source_env_file="$SIMULATION_ROOT/source.env"
    source_env_target="$SIMULATION_ROOT/source.env.target"
    cold_boot_attestation="$SIMULATION_ROOT/cold-boot.attestation"
    cold_boot_attestation_target="$SIMULATION_ROOT/cold-boot.attestation.target"

    run_controller_prerequisite_case '' 29.6.1 6.17.0-35-generic "$source_env_file" "$cold_boot_attestation" \
        'production requires exact CONTROL_PLANE_PORT8000_EXPECTED_DOCKER_VERSION'
    run_controller_prerequisite_case 29.6.1 29.5.0 6.17.0-35-generic "$source_env_file" "$cold_boot_attestation" \
        'Docker Engine does not match the explicitly attested production version'
    run_controller_prerequisite_case 29.6.1 29.6.1 6.17.0-34-generic "$source_env_file" "$cold_boot_attestation" \
        'production expected kernel must equal the attested 6.17.0-35-generic release'
    run_controller_prerequisite_case 29.6.1 29.6.1 6.17.0-35-generic "$source_env_file" "$cold_boot_attestation" \
        'CONTROL_PLANE_PORT8000_SOURCE_ENV_FILE is not a regular non-symlink file'

    printf '%s\n' 'source=simulated' > "$source_env_target"
    ln -s "$source_env_target" "$source_env_file"
    : > "$cold_boot_attestation"
    chmod 600 "$source_env_target" "$cold_boot_attestation"
    run_controller_prerequisite_case 29.6.1 29.6.1 6.17.0-35-generic "$source_env_file" "$cold_boot_attestation" \
        'CONTROL_PLANE_PORT8000_SOURCE_ENV_FILE is not a regular non-symlink file'

    rm "$source_env_file"
    mv "$source_env_target" "$source_env_file"
    rm "$cold_boot_attestation"
    run_controller_prerequisite_case 29.6.1 29.6.1 6.17.0-35-generic "$source_env_file" "$cold_boot_attestation" \
        'CONTROL_PLANE_PORT8000_COLD_BOOT_ATTESTATION_FILE is not a regular non-symlink file'

    : > "$cold_boot_attestation_target"
    ln -s "$cold_boot_attestation_target" "$cold_boot_attestation"
    chmod 600 "$source_env_file"
    run_controller_prerequisite_case 29.6.1 29.6.1 6.17.0-35-generic "$source_env_file" "$cold_boot_attestation" \
        'CONTROL_PLANE_PORT8000_COLD_BOOT_ATTESTATION_FILE is not a regular non-symlink file'

    rm "$cold_boot_attestation"
    mv "$cold_boot_attestation_target" "$cold_boot_attestation"
    chmod 600 "$cold_boot_attestation"
    run_controller_prerequisite_case 29.6.1 29.6.1 6.17.0-35-generic "$source_env_file" "$cold_boot_attestation"
}

run_cold_boot_prerequisite_case()
{
    local expected_docker_version=$1 expected_kernel_release=$2 expected_failure=$3 state_file output status
    state_file="$SIMULATION_ROOT/cold-boot-state"

    set +e
    output=$( \
        PATH="$SCRIPT_DIRECTORY/bin:$ORIGINAL_PATH" \
        CONTROL_PLANE_PORT8000_EXPECTED_DOCKER_VERSION="$expected_docker_version" \
        CONTROL_PLANE_PORT8000_EXPECTED_KERNEL_RELEASE="$expected_kernel_release" \
        bash "$REPOSITORY_ROOT/docker/control-plane-blue-green/port8000/cold-boot-acceptance.sh" arm "$state_file" 2>&1
    )
    status=$?
    set -e

    [[ $status -ne 0 ]] || fail "cold-boot acceptance accepted simulated invalid prerequisite: $expected_failure"
    [[ $output == *"$expected_failure"* ]] || fail "cold-boot acceptance rejected a simulated prerequisite for an unexpected reason: $output"
    [[ ! -e $state_file ]] || fail 'cold-boot acceptance wrote state after rejecting a simulated prerequisite'
}

run_cold_boot_prerequisite_simulation()
{
    local cold_boot_root cold_boot_functions output

    run_cold_boot_prerequisite_case '' '' \
        'CONTROL_PLANE_PORT8000_EXPECTED_DOCKER_VERSION is required'
    run_cold_boot_prerequisite_case 29.6.1 'unsafe kernel release' \
        'CONTROL_PLANE_PORT8000_EXPECTED_KERNEL_RELEASE is required and must be an exact safe release value'

    cold_boot_root="$SIMULATION_ROOT/cold-boot-functions"
    cold_boot_functions="$cold_boot_root/port8000/cold-boot-acceptance.sh"
    mkdir -p "$cold_boot_root/port8000" "$cold_boot_root/controllers" \
        "$cold_boot_root/systemd" "$cold_boot_root/libexec"
    cp "$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/haproxy-port8000.sh" \
        "$cold_boot_root/controllers/haproxy-port8000.sh"
    for asset in coolify-port8000-haproxy@.service \
        coolify-port8000-phase-b-authorizer.service coolify-port8000-nft.service \
        apply-active-nft.sh; do
        cp "$REPOSITORY_ROOT/docker/control-plane-blue-green/port8000/$asset" \
            "$cold_boot_root/port8000/$asset"
    done
    cp "$cold_boot_root/port8000/coolify-port8000-haproxy@.service" \
        "$cold_boot_root/systemd/coolify-port8000-haproxy@.service"
    cp "$cold_boot_root/port8000/coolify-port8000-phase-b-authorizer.service" \
        "$cold_boot_root/systemd/coolify-port8000-phase-b-authorizer.service"
    cp "$cold_boot_root/port8000/coolify-port8000-nft.service" \
        "$cold_boot_root/systemd/coolify-port8000-nft.service"
    cp "$cold_boot_root/port8000/apply-active-nft.sh" \
        "$cold_boot_root/libexec/coolify-port8000-apply-active-nft"
    mkdir -p "$cold_boot_root/runtime"
    {
        printf '%s\n' version=1 haproxy_version=2.8.26 \
            haproxy_source_sha256=88c28dae25ea46672e66f8db0dadd1fb5920e06ee2415ceb9f281c256b537727 \
            'haproxy_build_options=TARGET=linux-glibc USE_SYSTEMD=1' \
            "haproxy_binary=$SCRIPT_DIRECTORY/bin/haproxy"
        printf 'haproxy_binary_sha256=%s\n' \
            "$(sha256sum "$SCRIPT_DIRECTORY/bin/haproxy" | awk '{print $1}')"
    } > "$cold_boot_root/runtime/provenance"
    chmod 600 "$cold_boot_root/runtime/provenance"
    printf '%s\n' 'ID=ubuntu' 'VERSION_ID=24.04' > "$cold_boot_root/os-release"
    printf '%s\n' 'flush ruleset' > "$cold_boot_root/nftables.conf"
    awk '$0 == "action=${1:-}" { exit } { print }' \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/port8000/cold-boot-acceptance.sh" \
        | sed \
            -e "s|/etc/os-release|$cold_boot_root/os-release|g" \
            -e "s|/etc/nftables.conf|$cold_boot_root/nftables.conf|g" \
            -e "s|/etc/systemd/system|$cold_boot_root/systemd|g" \
            -e "s|/usr/local/libexec|$cold_boot_root/libexec|g" \
            -e "s|/usr/local/lib/coolify-control-plane-port8000/haproxy-2.8.26/haproxy|$SCRIPT_DIRECTORY/bin/haproxy|g" \
            -e "s|/usr/local/lib/coolify-control-plane-port8000/haproxy-2.8.26/provenance|$cold_boot_root/runtime/provenance|g" \
            > "$cold_boot_functions"
    chmod 700 "$cold_boot_functions"

    if ! output=$( \
        PATH="$SCRIPT_DIRECTORY/bin:$ORIGINAL_PATH" \
        PRODUCTION_IMAGE_SUPPLY_CHAIN_SIMULATED_DOCKER_VERSION=29.6.1 \
        CONTROL_PLANE_PORT8000_EXPECTED_DOCKER_VERSION=29.6.1 \
        CONTROL_PLANE_PORT8000_EXPECTED_KERNEL_RELEASE=6.17.0-35-generic \
        COLD_BOOT_FUNCTIONS="$cold_boot_functions" \
        bash -c 'source "$COLD_BOOT_FUNCTIONS"; assert_exact_host_prerequisites' 2>&1
    ); then
        fail "cold-boot prerequisite simulation rejected valid reviewed fixtures: $output"
    fi
}

file_mode()
{
    stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"
}

run_testing_host_keygen_simulation()
{
    local transformed_keygen runtime_directory private_key public_key derived_public_key_before derived_public_key_after
    local private_key_snapshot public_key_snapshot private_hash_before private_hash_after public_hash_before public_hash_after
    local private_key_mode public_key_mode uid gid
    transformed_keygen="$SIMULATION_ROOT/keygen.sh"
    runtime_directory="$SIMULATION_ROOT/runtime"
    private_key="$runtime_directory/private/testing-host"
    public_key="$runtime_directory/public/authorized_keys"
    derived_public_key_before="$SIMULATION_ROOT/derived-public-key.before"
    derived_public_key_after="$SIMULATION_ROOT/derived-public-key.after"
    private_key_snapshot="$SIMULATION_ROOT/private-key.snapshot"
    public_key_snapshot="$SIMULATION_ROOT/public-key.snapshot"
    uid=$(id -u)
    gid=$(id -g)

    sed \
        -e "s|/run/coolify-testing-host|$runtime_directory|g" \
        -e "s|-o root -g root|-o $uid -g $gid|g" \
        -e "s|root:root|$uid:$gid|g" \
        "$REPOSITORY_ROOT/docker/testing-host/keygen.sh" > "$transformed_keygen"
    chmod 700 "$transformed_keygen"

    COOLIFY_TESTING_KEY_UID="$uid" COOLIFY_TESTING_KEY_GID="$gid" "$transformed_keygen" >/dev/null

    [[ -f $private_key && -f $public_key ]] \
        || fail 'testing-host keygen simulation did not create both isolated key files'
    private_key_mode=$(file_mode "$private_key")
    public_key_mode=$(file_mode "$public_key")
    [[ $private_key_mode == 600 && $public_key_mode == 644 ]] \
        || fail "testing-host keygen simulation did not apply the expected key file modes: private=$private_key_mode public=$public_key_mode"
    ssh-keygen -lf "$public_key" >/dev/null 2>&1 \
        || fail 'testing-host keygen simulation wrote an invalid public key'
    ssh-keygen -y -f "$private_key" > "$derived_public_key_before"
    cmp -s "$derived_public_key_before" "$public_key" \
        || fail 'testing-host keygen simulation public key does not derive from its private key'
    [[ ! -e "$runtime_directory/public/testing-host" ]] \
        || fail 'testing-host keygen simulation exposed the private key in the public runtime directory'

    cp "$private_key" "$private_key_snapshot"
    cp "$public_key" "$public_key_snapshot"
    private_hash_before=$(sha256sum "$private_key" | awk '{print $1}')
    public_hash_before=$(sha256sum "$public_key" | awk '{print $1}')

    COOLIFY_TESTING_KEY_UID="$uid" COOLIFY_TESTING_KEY_GID="$gid" "$transformed_keygen" >/dev/null

    private_hash_after=$(sha256sum "$private_key" | awk '{print $1}')
    public_hash_after=$(sha256sum "$public_key" | awk '{print $1}')
    [[ $private_hash_after == "$private_hash_before" && $public_hash_after == "$public_hash_before" ]] \
        || fail 'testing-host keygen simulation changed established private or public key bytes on restart'
    cmp -s "$private_key_snapshot" "$private_key" \
        || fail 'testing-host keygen simulation changed the established private key on restart'
    cmp -s "$public_key_snapshot" "$public_key" \
        || fail 'testing-host keygen simulation changed the established public key on restart'
    ssh-keygen -y -f "$private_key" > "$derived_public_key_after"
    cmp -s "$derived_public_key_after" "$public_key" \
        || fail 'testing-host keygen simulation current public key does not derive from the current private key after restart'
    cmp -s "$derived_public_key_before" "$derived_public_key_after" \
        || fail 'testing-host keygen simulation changed the private-key derivation on restart'
}

if [[ ${2:-} == --keygen-only ]]; then
    run_testing_host_keygen_simulation
    printf '%s\n' 'PRODUCTION_IMAGE_SUPPLY_CHAIN_SIMULATION keygen=pass production_image_acceptance=false'

    exit 0
fi

bash -n \
    "$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/haproxy-port8000.sh" \
    "$REPOSITORY_ROOT/docker/control-plane-blue-green/port8000/cold-boot-acceptance.sh" \
    "$REPOSITORY_ROOT/docker/testing-host/keygen.sh" \
    "$REPOSITORY_ROOT/docker/verify-source-provenance.sh"

run_provenance_verifier_simulation
run_controller_prerequisite_simulation
run_cold_boot_prerequisite_simulation

printf '%s\n' 'PRODUCTION_IMAGE_SUPPLY_CHAIN_SIMULATION result=pass production_image_acceptance=false'
