#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

test_directory=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)
readonly test_directory
repository_root=$(CDPATH='' cd -- "$test_directory/../../.." && pwd -P)
readonly repository_root
readonly packager=$test_directory/package-host-gate-evidence.sh
readonly workflow=$repository_root/.github/workflows/publish-linux-image.yml
test_root=
cleanup_started=0
readonly main_bash_pid=$BASHPID

fail()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_EVIDENCE_PACKAGE_TEST_FAILURE %s\n' "$1" >&2
    exit 1
}

cleanup()
{
    local status=$1

    trap - EXIT HUP INT TERM
    if [[ $BASHPID != "$main_bash_pid" ]]; then
        exit "$status"
    fi
    if [[ $cleanup_started == 1 ]]; then
        exit "$status"
    fi
    cleanup_started=1
    if [[ -n ${test_root:-} && -d $test_root && ! -L $test_root ]]; then
        rm -rf -- "$test_root"
    fi
    exit "$status"
}

signal_exit()
{
    local status=$1

    trap - HUP INT TERM
    exit "$status"
}

trap 'cleanup "$?"' EXIT
trap 'signal_exit 129' HUP
trap 'signal_exit 130' INT
trap 'signal_exit 143' TERM

if [[ ${1:-} == --signal-contract-child ]]; then
    printf 'SIGNAL_CONTRACT_CHILD_READY\n'
    while :; do
        sleep 1
    done
fi
[[ $# -eq 0 ]] || fail 'focused packager test received an unknown argument'

write_root_file()
{
    local destination=$1
    shift

    printf '%s' "$@" > "$destination"
    chown root:root "$destination"
    chmod 0600 "$destination"
}

new_operation()
{
    operation_directory=$test_root/evidence/$1
    install -d -m 0700 -o root -g root "$operation_directory"
}

write_outer_diagnostics()
{
    write_root_file "$operation_directory/outer-container-inspect.json" \
        '[{"Name":"/exited-host","State":{"Status":"exited"}}]'$'\n'
    write_root_file "$operation_directory/outer-container-inspect.status" \
        'outer_docker_exit_status=0'$'\n'
    write_root_file "$operation_directory/outer-container-logs.txt" 'systemd host exited'$'\n'
    write_root_file "$operation_directory/outer-container-logs.status" \
        'outer_docker_exit_status=0'$'\n'
}

write_control_records()
{
    local operation=$1

    write_root_file "$operation_directory/boot-generation" '2'$'\n'
    write_root_file "$operation_directory/boot-record" \
        'generation=2'$'\n''pid1_started_at=200'$'\n'
    write_root_file "$operation_directory/runtime-fence-host.phase" 'complete'$'\n'
    write_root_file "$operation_directory/runtime-fence-host.reboot-record" \
        "operation=$operation-reboot"$'\n''generation=1'$'\n''run_marker_identity=8:91'$'\n'\
        'restore_enter=100'$'\n''docker_enter=101'$'\n''watchdog_enter=102'$'\n'
}

write_transport_manifest()
{
    local operation=$1 version=${2:-1} transport web_size proxy_size web_sha proxy_sha
    readonly_digest=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
    contract_digest=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
    transport=$operation_directory/image-transport/$operation
    install -d -m 0700 -o root -g root "$transport"
    write_root_file "$transport/web-image.tar" 'web archive bytes'$'\n'
    write_root_file "$transport/proxy-image.tar" 'proxy archive bytes'$'\n'
    web_size=$(wc -c < "$transport/web-image.tar" | tr -d '[:space:]')
    proxy_size=$(wc -c < "$transport/proxy-image.tar" | tr -d '[:space:]')
    web_sha=$(sha256sum "$transport/web-image.tar" | awk '{print $1}')
    proxy_sha=$(sha256sum "$transport/proxy-image.tar" | awk '{print $1}')
    write_root_file "$transport/images.env" \
        "version=$version"$'\n'\
        "operation_id=$operation"$'\n'\
        "web_source_reference=registry.invalid/web@sha256:$readonly_digest"$'\n'\
        "proxy_source_reference=registry.invalid/proxy@sha256:$readonly_digest"$'\n'\
        'web_archive_name=web-image.tar'$'\n'\
        "web_archive_sha256=$web_sha"$'\n'\
        "web_archive_metadata=0:0:600:$web_size"$'\n'\
        "web_image_digest=sha256:$readonly_digest"$'\n'\
        'web_platform=linux/amd64'$'\n'\
        "web_contract_sha256=$contract_digest"$'\n'\
        'proxy_archive_name=proxy-image.tar'$'\n'\
        "proxy_archive_sha256=$proxy_sha"$'\n'\
        "proxy_archive_metadata=0:0:600:$proxy_size"$'\n'\
        "proxy_image_digest=sha256:$readonly_digest"$'\n'\
        'proxy_platform=linux/amd64'$'\n'\
        "proxy_contract_sha256=$contract_digest"$'\n'
}

make_exited_operation()
{
    new_operation "$1"
    write_outer_diagnostics
}

make_complete_full_operation()
{
    new_operation "$1"
    write_control_records "$1"
    write_transport_manifest "$1"
}

run_packager()
{
    local mode=$1 operation=$2 output=$3

    "$packager" --mode "$mode" "$test_root/evidence/$operation" "$operation" "$output"
}

expect_rejection()
{
    local label=$1 mode=$2 operation=$3 output

    output=$test_root/output/$operation.tar

    if run_packager "$mode" "$operation" "$output" > "$test_root/$operation.failure.log" 2>&1; then
        fail "$label unexpectedly passed"
    fi
    [[ ! -e $output && ! -L $output ]] || fail "$label published an output tar"
}

assert_tar_excludes_archives()
{
    local archive=$1 listing=$test_root/tar-listing

    tar --list --file "$archive" > "$listing"
    grep -F -x 'image-transport/complete-full/images.env' "$listing" >/dev/null \
        || fail 'complete full tar omitted the validated transport manifest'
    if grep -E '(^|/)(web-image|proxy-image)\.tar$' "$listing" >/dev/null; then
        fail 'complete full tar included an image archive'
    fi
}

assert_tar_member_matches_source()
{
    local archive=$1 source=$2 member=$3 expected_size header content
    local -a fields=()

    expected_size=$(wc -c < "$source" | tr -d '[:space:]')
    header=$(tar --numeric-owner --list --verbose --file "$archive" "$member")
    read -r -a fields <<< "$header"
    [[ ${fields[0]:-} == -rw------- \
        && ${fields[1]:-} == 0/0 \
        && ${fields[2]:-} == "$expected_size" \
        && ${fields[${#fields[@]}-1]:-} == "$member" ]] \
        || fail "tar header metadata differs from the source contract: $member"
    content=$test_root/extracted-member
    tar --extract --to-stdout --file "$archive" "$member" > "$content"
    [[ $(wc -c < "$content" | tr -d '[:space:]') == "$expected_size" \
        && $(sha256sum "$content" | awk '{print $1}') == $(sha256sum "$source" | awk '{print $1}') ]] \
        || fail "tar member content differs from source: $member"
}

assert_workflow_contract()
{
    local expected_directory expected_output expected_invocation expected_artifact

    # shellcheck disable=SC2016 # These are literal GitHub Actions shell/YAML contracts.
    expected_directory='          readonly package_directory="$GITHUB_WORKSPACE/linux-image/host-gate-evidence"'
    # shellcheck disable=SC2016
    expected_output='            output="$package_directory/${ARTIFACT_NAME}-${operation}.tar"'
    # shellcheck disable=SC2016
    expected_invocation='            if ! sudo "$GITHUB_WORKSPACE/tests/Integration/ControlPlaneRuntimeFence/package-host-gate-evidence.sh" '"\\"
    # shellcheck disable=SC2016
    expected_artifact='EVIDENCE_ARTIFACT_ID: ${{ steps.upload-production-host-gate-evidence.outputs.artifact-id }}'
    grep -F -x "$expected_directory" \
        "$workflow" >/dev/null || fail 'workflow package directory is not canonical and absolute'
    grep -F -x "$expected_output" \
        "$workflow" >/dev/null || fail 'workflow package output does not use the absolute package directory'
    grep -F -x "$expected_invocation" \
        "$workflow" >/dev/null || fail 'workflow does not invoke the exact packager by absolute path'
    grep -F -x '        id: upload-production-host-gate-evidence' "$workflow" >/dev/null \
        || fail 'workflow evidence upload has no stable step id'
    grep -F "$expected_artifact" \
        "$workflow" >/dev/null || fail 'workflow final gate does not consume the uploaded artifact id'
}

assert_signal_contracts()
{
    local operation=negative-packager-signal output status packager_pid test_pid attempt

    make_exited_operation "$operation"
    dd if=/dev/zero of="$operation_directory/host-readiness-diagnostics" \
        bs=1048576 count=10 status=none
    chown root:root "$operation_directory/host-readiness-diagnostics"
    chmod 0600 "$operation_directory/host-readiness-diagnostics"
    output=$test_root/output/$operation.tar
    set +e
    "$packager" --mode exited "$operation_directory" "$operation" "$output" \
        > "$test_root/packager-signal.log" 2>&1 &
    packager_pid=$!
    for ((attempt = 0; attempt < 100; attempt++)); do
        [[ -f $test_root/packager-signal.log ]] && kill -0 "$packager_pid" 2>/dev/null && break
        sleep 0.01
    done
    if [[ ! -f $test_root/packager-signal.log ]] || ! kill -0 "$packager_pid" 2>/dev/null; then
        fail 'packager did not remain active long enough for its signal contract'
    fi
    kill -TERM "$packager_pid" 2>/dev/null \
        || fail 'packager completed before its signal contract could be exercised'
    wait "$packager_pid"
    status=$?
    set -e
    [[ $status -eq 143 && ! -e $output ]] \
        || fail "packager signal contract returned status=$status or published output"
    if grep -F 'CONTROL_PLANE_RUNTIME_FENCE_EVIDENCE_PACKAGE_PASS' \
        "$test_root/packager-signal.log" >/dev/null; then
        fail 'terminated packager reported success'
    fi

    set +e
    "$0" --signal-contract-child > "$test_root/test-signal.log" 2>&1 &
    test_pid=$!
    for ((attempt = 0; attempt < 100; attempt++)); do
        if [[ -f $test_root/test-signal.log ]] \
            && grep -F -x 'SIGNAL_CONTRACT_CHILD_READY' "$test_root/test-signal.log" >/dev/null; then
            break
        fi
        sleep 0.01
    done
    if [[ ! -f $test_root/test-signal.log ]] \
        || ! grep -F -x 'SIGNAL_CONTRACT_CHILD_READY' "$test_root/test-signal.log" >/dev/null; then
        fail 'focused test signal child did not become ready'
    fi
    kill -TERM "$test_pid" 2>/dev/null \
        || fail 'focused test completed before its signal contract could be exercised'
    wait "$test_pid"
    status=$?
    set -e
    [[ $status -eq 143 ]] || fail "focused test signal contract returned status=$status"
    if grep -F 'CONTROL_PLANE_RUNTIME_FENCE_EVIDENCE_PACKAGE_TEST_PASS' \
        "$test_root/test-signal.log" >/dev/null; then
        fail 'terminated focused test reported success'
    fi
}

[[ $EUID -eq 0 ]] || fail 'focused packager tests must run as root (CI invokes this script with sudo)'
for command in awk dd grep install jq mkfifo sha256sum tar; do
    command -v "$command" >/dev/null || fail "required test command is unavailable: $command"
done
[[ -x $packager && -f $workflow ]] || fail 'packager or workflow under test is absent'

test_root=$(mktemp -d "${TMPDIR:-/tmp}/coolify-host-gate-packager-test.XXXXXX")
test_root=$(cd -- "$test_root" && pwd -P)
install -d -m 0700 -o root -g root "$test_root/evidence" "$test_root/output"

make_exited_operation positive-exited
run_packager exited positive-exited "$test_root/output/positive-exited.tar" >/dev/null

make_complete_full_operation complete-full
run_packager full complete-full "$test_root/output/complete-full.tar" >/dev/null
assert_tar_excludes_archives "$test_root/output/complete-full.tar"
assert_tar_member_matches_source "$test_root/output/complete-full.tar" \
    "$test_root/evidence/complete-full/boot-generation" boot-generation
assert_tar_member_matches_source "$test_root/output/complete-full.tar" \
    "$test_root/evidence/complete-full/image-transport/complete-full/images.env" \
    image-transport/complete-full/images.env

new_operation partial-failed-full
write_root_file "$operation_directory/host-readiness-diagnostics" 'docker.service failed'$'\n'
run_packager full partial-failed-full "$test_root/output/partial-failed-full.tar" >/dev/null

make_exited_operation negative-symlink
ln -s outer-container-logs.txt "$operation_directory/host-readiness-diagnostics"
expect_rejection symlink exited negative-symlink

make_exited_operation negative-hardlink
ln "$operation_directory/outer-container-logs.txt" "$operation_directory/host-readiness-diagnostics"
expect_rejection hardlink exited negative-hardlink

make_exited_operation negative-special
mkfifo "$operation_directory/host-readiness-diagnostics"
chown root:root "$operation_directory/host-readiness-diagnostics"
expect_rejection special-file exited negative-special

make_exited_operation negative-unknown
write_root_file "$operation_directory/unknown-evidence" 'unknown'$'\n'
expect_rejection unknown-entry exited negative-unknown

make_exited_operation negative-oversized
dd if=/dev/zero of="$operation_directory/host-readiness-diagnostics" bs=1048576 count=10 status=none
dd if=/dev/zero of="$operation_directory/host-readiness-diagnostics" bs=1 count=1 seek=10485760 conv=notrunc status=none
chown root:root "$operation_directory/host-readiness-diagnostics"
chmod 0600 "$operation_directory/host-readiness-diagnostics"
expect_rejection oversized-file exited negative-oversized

make_complete_full_operation negative-archive-alias
rm -f -- "$operation_directory/image-transport/negative-archive-alias/web-image.tar"
ln "$operation_directory/image-transport/negative-archive-alias/images.env" \
    "$operation_directory/image-transport/negative-archive-alias/web-image.tar"
expect_rejection excluded-archive-alias full negative-archive-alias

make_exited_operation negative-status
write_root_file "$operation_directory/outer-container-logs.status" 'exit_status=0'$'\n'
expect_rejection malformed-status exited negative-status

make_exited_operation negative-status-trailing
write_root_file "$operation_directory/outer-container-logs.status" \
    'outer_docker_exit_status=0'$'\n''trailing-bytes'
expect_rejection trailing-status-bytes exited negative-status-trailing

make_exited_operation negative-inspect-scalar
write_root_file "$operation_directory/outer-container-inspect.json" 'true'$'\n'
expect_rejection inspect-scalar exited negative-inspect-scalar

make_exited_operation negative-inspect-object
write_root_file "$operation_directory/outer-container-inspect.json" \
    '{"Name":"/exited-host","State":{"Status":"exited"}}'$'\n'
expect_rejection inspect-object exited negative-inspect-object

new_operation negative-phase
write_root_file "$operation_directory/runtime-fence-host.phase" 'complete-ish'$'\n'
expect_rejection malformed-phase full negative-phase

make_complete_full_operation negative-manifest
write_transport_manifest negative-manifest 2
expect_rejection malformed-manifest full negative-manifest

make_exited_operation negative-output-collision
write_root_file "$test_root/output/negative-output-collision.tar" 'existing output'$'\n'
if run_packager exited negative-output-collision \
    "$test_root/output/negative-output-collision.tar" > "$test_root/output-collision.failure.log" 2>&1; then
    fail 'output collision unexpectedly passed'
fi
[[ $(<"$test_root/output/negative-output-collision.tar") == 'existing output' ]] \
    || fail 'output collision changed the pre-existing file'

make_exited_operation negative-relative-output
if run_packager exited negative-relative-output relative-output.tar \
    > "$test_root/relative-output.failure.log" 2>&1; then
    fail 'relative output path unexpectedly passed'
fi
[[ ! -e $repository_root/relative-output.tar ]] || fail 'relative output rejection created a repository file'

assert_workflow_contract
assert_signal_contracts
printf 'CONTROL_PLANE_RUNTIME_FENCE_EVIDENCE_PACKAGE_TEST_PASS cases=19 absolute_workflow_caller=true signal_status_preserved=true tar_member_identity=true\n'
