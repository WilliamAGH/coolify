#!/usr/bin/env bash

# Validate untrusted exported host-gate evidence, copy only the allowlisted
# regular files into a private immutable snapshot, and publish one bounded tar.

set -Eeuo pipefail
umask 077

readonly MAX_EVIDENCE_FILE_BYTES=$((10 * 1024 * 1024))
declare -a package_paths=()
declare -A validated_sha256=()
declare -A validated_size=()
temporary_root=
find_counter=0
cleanup_started=0
readonly main_bash_pid=$BASHPID

fail()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_EVIDENCE_PACKAGE_FAILURE %s\n' "$1" >&2
    exit 1
}

usage()
{
    printf '%s\n' \
        'usage: package-host-gate-evidence.sh --mode full|exited --platform linux/amd64|linux/arm64 OPERATION_DIRECTORY OPERATION OUTPUT_TAR' \
        'Validates an exported host-gate operation tree and writes one sanitized tar.'
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
    if [[ -n ${temporary_root:-} && -d $temporary_root && ! -L $temporary_root ]]; then
        rm -rf -- "$temporary_root"
    fi
    exit "$status"
}

signal_exit()
{
    local status=$1

    trap - HUP INT TERM
    exit "$status"
}

assert_safe_operation()
{
    [[ $1 =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]] \
        || fail 'operation identifier is unsafe'
}

assert_canonical_directory()
{
    [[ $1 == /* && -d $1 && ! -L $1 && $(cd -- "$1" && pwd -P) == "$1" ]] \
        || fail "$2 is not an existing canonical absolute directory"
}

assert_output_path()
{
    local output=$1 parent basename

    [[ $output == /* ]] || fail 'output tar path is not absolute'
    parent=$(dirname -- "$output")
    basename=$(basename -- "$output")
    assert_canonical_directory "$parent" 'output parent'
    [[ $output == "$parent/$basename" && $basename =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*\.tar$ ]] \
        || fail 'output tar path is unsafe'
    [[ ! -e $output && ! -L $output ]] || fail 'output tar already exists or is unsafe'
}

file_size()
{
    wc -c < "$1" | tr -d '[:space:]'
}

file_sha256()
{
    sha256sum "$1" | awk '{print $1}'
}

file_nlink()
{
    if [[ $stat_style == gnu ]]; then
        stat -c '%h' "$1"
    else
        stat -f '%l' "$1"
    fi
}

file_owner_mode()
{
    if [[ $stat_style == gnu ]]; then
        stat -c '%u:%g:%a' "$1"
    else
        stat -f '%u:%g:%Lp' "$1"
    fi
}

assert_regular_file()
{
    [[ -f $1 && ! -L $1 ]] || fail "required regular file is absent or unsafe: $2"
    [[ $(file_nlink "$1") == 1 ]] || fail "evidence file has multiple hard links: $2"
    [[ $(file_owner_mode "$1") == 0:0:600 ]] \
        || fail "evidence file does not have root:root 0600 identity: $2"
}

assert_small_file()
{
    local size

    assert_regular_file "$1" "$2"
    size=$(file_size "$1")
    [[ $size =~ ^[0-9]+$ && $size -le $MAX_EVIDENCE_FILE_BYTES ]] \
        || fail "evidence file exceeds the bounded upload limit: $2"
}

next_find_inventory()
{
    find_counter=$((find_counter + 1))
    find_inventory="$temporary_root/find-inventory-$find_counter"
}

assert_no_links_hardlinks_or_special_entries()
{
    local entry relative

    next_find_inventory
    find -P "$operation_directory" -print0 > "$find_inventory" \
        || fail 'find failed while inventorying the evidence tree'
    while IFS= read -r -d '' entry; do
        relative=${entry#"$operation_directory"/}
        [[ $entry != "$operation_directory" ]] || relative=.
        [[ ! -L $entry ]] || fail "evidence tree contains a symlink: $relative"
        if [[ -f $entry ]]; then
            [[ $(file_nlink "$entry") == 1 ]] \
                || fail "evidence tree contains a multiply-linked file: $relative"
        elif [[ ! -d $entry ]]; then
            fail "evidence tree contains a non-regular entry: $relative"
        fi
    done < "$find_inventory"
}

assert_direct_entries_are_known()
{
    local directory=$1 label=$2 entry name
    shift 2
    local -A allowed=()

    for name in "$@"; do
        allowed[$name]=1
    done
    next_find_inventory
    find -P "$directory" -mindepth 1 -maxdepth 1 -print0 > "$find_inventory" \
        || fail "find failed while validating $label"
    while IFS= read -r -d '' entry; do
        name=${entry##*/}
        [[ -n ${allowed[$name]+present} ]] \
            || fail "$label contains an unknown entry: $name"
    done < "$find_inventory"
}

record_value()
{
    local source=$1 key=$2 count value

    count=$(grep -E -c "^${key}=" "$source" || true)
    [[ $count == 1 ]] || fail "$3 does not contain exactly one $key"
    value=$(sed -n "s/^${key}=//p" "$source")
    [[ -n $value && $value != *$'\r'* && $value != *$'\n'* ]] \
        || fail "$3 has an unsafe $key"
    printf '%s' "$value"
}

assert_ordered_keys()
{
    local source=$1 label=$2 key index key_file
    shift 2
    local -a expected_keys=("$@") actual_keys=()

    key_file="$temporary_root/keys-$find_counter"
    find_counter=$((find_counter + 1))
    sed 's/=.*//' "$source" > "$key_file" || fail "could not parse $label"
    mapfile -t actual_keys < "$key_file"
    [[ ${#actual_keys[@]} -eq ${#expected_keys[@]} ]] \
        || fail "$label has an unexpected key count"
    for index in "${!expected_keys[@]}"; do
        key=${expected_keys[$index]}
        [[ ${actual_keys[$index]} == "$key" ]] \
            || fail "$label keys are reordered, missing, duplicate, or unknown"
    done
}

assert_sha256_digest()
{
    [[ $2 =~ ^sha256:[a-f0-9]{64}$ ]] || fail "$1 is not a SHA-256 digest"
}

assert_sha256_sum()
{
    [[ $2 =~ ^[a-f0-9]{64}$ ]] || fail "$1 is not a SHA-256 checksum"
}

record_package_file()
{
    local relative=$1 source="$operation_directory/$1"

    assert_small_file "$source" "$relative"
    [[ -z ${validated_sha256[$relative]+present} ]] \
        || fail "evidence package path is duplicated: $relative"
    validated_sha256[$relative]=$(file_sha256 "$source")
    validated_size[$relative]=$(file_size "$source")
    package_paths+=("$relative")
}

validate_image_transport()
{
    local key value archive expected_size host_platform
    local -a expected_keys=(
        version operation_id host_platform web_source_reference proxy_source_reference
        web_archive_name web_archive_sha256 web_archive_metadata web_image_digest web_platform
        web_contract_sha256 proxy_archive_name proxy_archive_sha256 proxy_archive_metadata
        proxy_image_digest proxy_platform proxy_contract_sha256
    )

    transport_directory="$operation_directory/image-transport/$operation"
    [[ -d $transport_directory && ! -L $transport_directory ]] \
        || fail 'image transport operation directory is absent or unsafe'
    assert_direct_entries_are_known "$operation_directory/image-transport" 'image transport root' "$operation"
    assert_direct_entries_are_known "$transport_directory" 'image transport operation' \
        images.env web-image.tar proxy-image.tar

    manifest="$transport_directory/images.env"
    web_archive="$transport_directory/web-image.tar"
    proxy_archive="$transport_directory/proxy-image.tar"
    assert_small_file "$manifest" 'image-transport/images.env'
    assert_regular_file "$web_archive" 'image-transport/web-image.tar'
    assert_regular_file "$proxy_archive" 'image-transport/proxy-image.tar'
    assert_ordered_keys "$manifest" 'image transport manifest' "${expected_keys[@]}"

    [[ $(record_value "$manifest" version 'image transport manifest') == 1 \
        && $(record_value "$manifest" operation_id 'image transport manifest') == "$operation" ]] \
        || fail 'image transport manifest does not belong to this operation'
    host_platform=$(record_value "$manifest" host_platform 'image transport manifest')
    case "$host_platform" in
        linux/amd64|linux/arm64) ;;
        *) fail 'image transport manifest has an unsupported host platform' ;;
    esac
    [[ $host_platform == "$expected_platform" ]] \
        || fail 'image transport manifest platform differs from the requested evidence platform'
    for key in web_source_reference proxy_source_reference; do
        value=$(record_value "$manifest" "$key" 'image transport manifest')
        [[ $value =~ ^[A-Za-z0-9][A-Za-z0-9._/:@-]*@sha256:[a-f0-9]{64}$ ]] \
            || fail "image transport manifest has an invalid $key"
    done
    for key in web_image_digest proxy_image_digest; do
        assert_sha256_digest "$key" "$(record_value "$manifest" "$key" 'image transport manifest')"
    done
    for key in web_contract_sha256 proxy_contract_sha256 web_archive_sha256 proxy_archive_sha256; do
        assert_sha256_sum "$key" "$(record_value "$manifest" "$key" 'image transport manifest')"
    done
    [[ $(record_value "$manifest" web_platform 'image transport manifest') == "$host_platform" \
        && $(record_value "$manifest" proxy_platform 'image transport manifest') == "$host_platform" ]] \
        || fail 'image transport manifest image platforms do not bind the exact host platform'
    [[ $(record_value "$manifest" web_archive_name 'image transport manifest') == web-image.tar \
        && $(record_value "$manifest" proxy_archive_name 'image transport manifest') == proxy-image.tar ]] \
        || fail 'image transport manifest has non-canonical archive names'

    for archive in web proxy; do
        value=$(record_value "$manifest" "${archive}_archive_metadata" 'image transport manifest')
        [[ $value =~ ^0:0:600:[1-9][0-9]*$ ]] \
            || fail "image transport manifest has invalid ${archive} archive metadata"
        expected_size=${value##*:}
        if [[ $archive == web ]]; then
            value=$web_archive
        else
            value=$proxy_archive
        fi
        [[ $(file_size "$value") == "$expected_size" ]] \
            || fail "image transport ${archive} archive size differs from its manifest"
        [[ $(file_sha256 "$value") == \
            "$(record_value "$manifest" "${archive}_archive_sha256" 'image transport manifest')" ]] \
            || fail "image transport ${archive} archive checksum differs from its manifest"
    done
    record_package_file "image-transport/$operation/images.env"
}

validate_status_file()
{
    local source=$1 status expected

    assert_small_file "$source" "${source##*/}"
    status=$(record_value "$source" outer_docker_exit_status "${source##*/}")
    [[ $status =~ ^[0-9]{1,3}$ && $status -le 255 ]] \
        || fail "${source##*/} has an invalid Docker exit status"
    expected="outer_docker_exit_status=$status"$'\n'
    [[ $(file_size "$source") == "${#expected}" && $(<"$source") == "${expected%$'\n'}" ]] \
        || fail "${source##*/} is not exactly one newline-terminated canonical status line"
}

validate_optional_control_files()
{
    local source generation phase

    source=$operation_directory/boot-generation
    if [[ -e $source ]]; then
        assert_small_file "$source" boot-generation
        [[ $(wc -l < "$source" | tr -d '[:space:]') == 1 && $(<"$source") =~ ^[1-9][0-9]*$ ]] \
            || fail 'boot-generation is malformed'
    fi

    source=$operation_directory/runtime-fence-host.phase
    if [[ -e $source ]]; then
        assert_small_file "$source" runtime-fence-host.phase
        [[ $(wc -l < "$source" | tr -d '[:space:]') == 1 ]] \
            || fail 'runtime-fence-host.phase is not one canonical line'
        phase=$(<"$source")
        [[ $phase == reboot-pending || $phase == complete ]] \
            || fail 'runtime-fence-host.phase is unknown'
    fi

    source=$operation_directory/boot-record
    if [[ -e $source ]]; then
        assert_small_file "$source" boot-record
        assert_ordered_keys "$source" boot-record generation pid1_started_at
        generation=$(record_value "$source" generation boot-record)
        [[ $generation =~ ^[1-9][0-9]*$ \
            && $(record_value "$source" pid1_started_at boot-record) =~ ^[0-9]+$ ]] \
            || fail 'boot-record is malformed'
        if [[ -e $operation_directory/boot-generation ]]; then
            [[ $generation == "$(<"$operation_directory/boot-generation")" ]] \
                || fail 'boot-record generation differs from boot-generation'
        fi
    fi

    source=$operation_directory/runtime-fence-host.reboot-record
    if [[ -e $source ]]; then
        assert_small_file "$source" runtime-fence-host.reboot-record
        assert_ordered_keys "$source" runtime-fence-host.reboot-record \
            operation generation run_marker_identity restore_enter docker_enter watchdog_enter
        [[ $(record_value "$source" operation runtime-fence-host.reboot-record) == "$operation-reboot" \
            && $(record_value "$source" generation runtime-fence-host.reboot-record) =~ ^[1-9][0-9]*$ \
            && $(record_value "$source" run_marker_identity runtime-fence-host.reboot-record) =~ ^[0-9]+:[0-9]+$ \
            && $(record_value "$source" restore_enter runtime-fence-host.reboot-record) =~ ^[0-9]+$ \
            && $(record_value "$source" docker_enter runtime-fence-host.reboot-record) =~ ^[0-9]+$ \
            && $(record_value "$source" watchdog_enter runtime-fence-host.reboot-record) =~ ^[0-9]+$ ]] \
            || fail 'runtime-fence-host.reboot-record is malformed'
    fi
}

validate_outer_diagnostics()
{
    local required=$1 entry present=0
    local -a diagnostics=(
        outer-container-inspect.json
        outer-container-inspect.status
        outer-container-logs.txt
        outer-container-logs.status
    )

    for entry in "${diagnostics[@]}"; do
        [[ -e $operation_directory/$entry ]] && present=$((present + 1))
    done
    if [[ $required == true ]]; then
        [[ $present -eq ${#diagnostics[@]} ]] \
            || fail 'exited-container evidence is missing required outer diagnostics'
    else
        [[ $present -eq 0 || $present -eq ${#diagnostics[@]} ]] \
            || fail 'full host-gate evidence has an incomplete outer diagnostic group'
    fi
    [[ $present -ne 0 ]] || return 0
    jq -e '
        type == "array"
        and length == 1
        and (.[0] | type == "object")
        and (.[0].Name | type == "string" and startswith("/") and length > 1 and length <= 256)
        and (.[0].State | type == "object")
        and (.[0].State.Status | type == "string"
            and (. == "created" or . == "running" or . == "paused" or . == "restarting"
                or . == "removing" or . == "exited" or . == "dead"))
    ' "$operation_directory/outer-container-inspect.json" >/dev/null \
        || fail 'outer-container-inspect.json does not match the bounded Docker inspect array schema'
    validate_status_file "$operation_directory/outer-container-inspect.status"
    validate_status_file "$operation_directory/outer-container-logs.status"
    for entry in "${diagnostics[@]}"; do
        record_package_file "$entry"
    done
}

validate_host_evidence()
{
    local entry phase='' host_platform

    assert_direct_entries_are_known "$operation_directory" 'host-gate operation' \
        boot-generation boot-record runtime-fence-host.phase runtime-fence-host.reboot-record \
        host-readiness-diagnostics outer-container-inspect.json outer-container-inspect.status \
        outer-container-logs.txt outer-container-logs.status host-platform image-transport
    assert_small_file "$operation_directory/host-platform" host-platform
    host_platform=$(<"$operation_directory/host-platform")
    [[ $host_platform == "$expected_platform" ]] \
        || fail 'host-platform evidence differs from the requested evidence platform'
    record_package_file host-platform
    validate_optional_control_files

    for entry in boot-generation boot-record runtime-fence-host.phase runtime-fence-host.reboot-record \
        host-readiness-diagnostics; do
        if [[ -e $operation_directory/$entry ]]; then
            record_package_file "$entry"
        fi
    done
    [[ ! -e $operation_directory/runtime-fence-host.phase ]] \
        || phase=$(<"$operation_directory/runtime-fence-host.phase")

    case "$mode" in
        full)
            validate_outer_diagnostics false
            if [[ -e $operation_directory/image-transport ]]; then
                [[ -d $operation_directory/image-transport && ! -L $operation_directory/image-transport ]] \
                    || fail 'full host-gate image transport is unsafe'
                validate_image_transport
            fi
            if [[ $phase == complete ]]; then
                for entry in boot-generation boot-record runtime-fence-host.reboot-record; do
                    [[ -e $operation_directory/$entry ]] \
                        || fail "complete full host-gate evidence is missing $entry"
                done
                [[ -d $operation_directory/image-transport && ! -L $operation_directory/image-transport ]] \
                    || fail 'complete full host-gate evidence is missing image transport'
            fi
            ;;
        exited)
            validate_outer_diagnostics true
            [[ ! -e $operation_directory/image-transport ]] \
                || fail 'exited-container evidence unexpectedly contains image transport'
            ;;
    esac
}

snapshot_package_files()
{
    local relative source destination parent candidate

    snapshot_root=$temporary_root/snapshot
    install -d -m 0700 -o root -g root "$snapshot_root"
    for relative in "${package_paths[@]}"; do
        source=$operation_directory/$relative
        assert_small_file "$source" "$relative"
        [[ $(file_size "$source") == "${validated_size[$relative]}" \
            && $(file_sha256 "$source") == "${validated_sha256[$relative]}" ]] \
            || fail "evidence changed after validation: $relative"
        destination=$snapshot_root/$relative
        parent=$(dirname -- "$destination")
        install -d -m 0700 -o root -g root "$parent"
        candidate=$parent/.${destination##*/}.candidate
        cp -P -- "$source" "$candidate"
        [[ -f $candidate && ! -L $candidate && $(file_nlink "$candidate") == 1 ]] \
            || fail "snapshot candidate is not a private regular file: $relative"
        chown root:root "$candidate"
        chmod 0600 "$candidate"
        mv -- "$candidate" "$destination"
        assert_regular_file "$destination" "$relative snapshot"
        [[ $(file_size "$destination") == "${validated_size[$relative]}" \
            && $(file_sha256 "$destination") == "${validated_sha256[$relative]}" ]] \
            || fail "snapshot content differs from validated evidence: $relative"
        assert_small_file "$source" "$relative"
        [[ $(file_size "$source") == "${validated_size[$relative]}" \
            && $(file_sha256 "$source") == "${validated_sha256[$relative]}" ]] \
            || fail "evidence changed while snapshotting: $relative"
    done
}

verify_archive()
{
    local archive=$1 relative index verbose_file list_file content_file
    local -a actual_paths=() verbose_fields=()

    assert_regular_file "$archive" 'sanitized tar'
    [[ -s $archive ]] || fail 'sanitized tar is empty'
    list_file=$temporary_root/archive-paths
    verbose_file=$temporary_root/archive-verbose
    tar --list --file "$archive" > "$list_file" || fail 'could not list sanitized tar paths'
    tar --numeric-owner --list --verbose --file "$archive" > "$verbose_file" \
        || fail 'could not inspect sanitized tar headers'
    mapfile -t actual_paths < "$list_file"
    [[ ${#actual_paths[@]} -eq ${#package_paths[@]} ]] \
        || fail 'sanitized tar contains an unexpected entry count'
    index=0
    while IFS= read -r verbose_line; do
        read -r -a verbose_fields <<< "$verbose_line"
        relative=${package_paths[$index]:-}
        [[ -n $relative && ${actual_paths[$index]} == "$relative" \
            && ${verbose_fields[0]:-} == -rw------- \
            && ${verbose_fields[${#verbose_fields[@]}-1]:-} == "$relative" ]] \
            || fail 'sanitized tar contains an unexpected path or type'
        if [[ $tar_style == gnu ]]; then
            [[ ${verbose_fields[1]:-} == 0/0 \
                && ${verbose_fields[2]:-} == "${validated_size[$relative]}" ]] \
                || fail "sanitized tar has unsafe ownership or size: $relative"
        else
            [[ ${verbose_fields[2]:-} == 0 && ${verbose_fields[3]:-} == 0 \
                && ${verbose_fields[4]:-} == "${validated_size[$relative]}" ]] \
                || fail "sanitized tar has unsafe ownership or size: $relative"
        fi
        content_file=$temporary_root/archive-content
        tar --extract --to-stdout --file "$archive" "$relative" > "$content_file" \
            || fail "could not extract sanitized tar member: $relative"
        [[ $(file_size "$content_file") == "${validated_size[$relative]}" \
            && $(file_sha256 "$content_file") == "${validated_sha256[$relative]}" ]] \
            || fail "sanitized tar content differs from snapshot: $relative"
        index=$((index + 1))
    done < "$verbose_file"
    [[ $index -eq ${#package_paths[@]} ]] \
        || fail 'sanitized tar header count differs from its path count'
}

create_package()
{
    local candidate=$temporary_root/sanitized-evidence.tar

    tar --create --file "$candidate" --format=ustar --no-recursion \
        --numeric-owner --owner=0 --group=0 \
        --directory "$snapshot_root" "${package_paths[@]}"
    chmod 0600 "$candidate"
    chown root:root "$candidate"
    verify_archive "$candidate"
    ln -- "$candidate" "$output_tar" \
        || fail 'output tar appeared before atomic publication'
    rm -f -- "$candidate"
    verify_archive "$output_tar"
    [[ $(file_nlink "$output_tar") == 1 ]] \
        || fail 'published sanitized tar has an unsafe hard-link count'
}

[[ $# == 7 && $1 == --mode && $3 == --platform ]] || {
    usage >&2
    exit 64
}
mode=$2
expected_platform=$4
operation_directory=$5
operation=$6
output_tar=$7
case "$mode" in
    full|exited) ;;
    *) usage >&2; exit 64 ;;
esac
case "$expected_platform" in
    linux/amd64|linux/arm64) ;;
    *) usage >&2; exit 64 ;;
esac
[[ $EUID -eq 0 ]] || fail 'packager must run as root to create a root-owned evidence snapshot'
assert_safe_operation "$operation"
assert_canonical_directory "$operation_directory" 'operation directory'
[[ $(basename -- "$operation_directory") == "$operation" ]] \
    || fail 'operation directory name differs from the requested operation'
assert_output_path "$output_tar"
output_parent=$(dirname -- "$output_tar")
output_basename=$(basename -- "$output_tar")

for command in awk cp find grep install jq mapfile sed sha256sum stat tar wc; do
    command -v "$command" >/dev/null || fail "required command is unavailable: $command"
done
if stat -c '%h' "$operation_directory" >/dev/null 2>&1; then
    readonly stat_style=gnu
else
    readonly stat_style=bsd
fi
if tar --version 2>/dev/null | grep -F GNU >/dev/null; then
    readonly tar_style=gnu
else
    readonly tar_style=bsd
fi

temporary_root=$(mktemp -d "$output_parent/.${output_basename}.packager.XXXXXX")
trap 'cleanup "$?"' EXIT
trap 'signal_exit 129' HUP
trap 'signal_exit 130' INT
trap 'signal_exit 143' TERM
chown root:root "$temporary_root"
chmod 0700 "$temporary_root"
[[ $(file_owner_mode "$temporary_root") == 0:0:700 && ! -L $temporary_root ]] \
    || fail 'temporary evidence workspace is not private and root-owned'

assert_no_links_hardlinks_or_special_entries
validate_host_evidence
[[ ${#package_paths[@]} -gt 0 ]] || fail 'no allowlisted evidence files are available to package'
snapshot_package_files
create_package
printf 'CONTROL_PLANE_RUNTIME_FENCE_EVIDENCE_PACKAGE_PASS mode=%s platform=%s operation=%s tar=%s\n' \
    "$mode" "$expected_platform" "$operation" "$output_tar"
