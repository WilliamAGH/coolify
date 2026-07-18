#!/usr/bin/env bash

# Immutable, one-way image transport for the disposable host gate. The outer
# Docker daemon saves verified source images into the evidence volume; inner
# DinD loads and runs only their config digests, never a mutable tag.

image_transport_contract_keys()
{
    printf '%s\n' \
        version operation_id web_source_reference proxy_source_reference \
        web_archive_name web_archive_sha256 web_archive_metadata web_image_digest web_platform \
        web_contract_sha256 proxy_archive_name proxy_archive_sha256 proxy_archive_metadata \
        proxy_image_digest proxy_platform proxy_contract_sha256
}

image_transport_fail()
{
    fail "$1"
}

image_transport_blocked()
{
    blocked "$1"
}

image_transport_assert_source_reference()
{
    [[ $2 =~ ^[A-Za-z0-9][A-Za-z0-9._/:@-]*@sha256:[a-f0-9]{64}$ ]] \
        || image_transport_blocked "$1 must be an immutable repository digest"
}

image_transport_assert_image_digest()
{
    [[ $2 =~ ^sha256:[a-f0-9]{64}$ ]] \
        || image_transport_blocked "$1 must be an immutable image config digest"
}

image_transport_file_metadata()
{
    stat -c '%u:%g:%a:%s' "$1"
}

image_transport_web_contract_sha256()
{
    local image=$1 entrypoint

    entrypoint=$(docker image inspect --format '{{index .Config.Entrypoint 0}}' "$image")
    [[ $entrypoint == /usr/local/bin/coolify-entrypoint ]] \
        || image_transport_blocked 'the transported web image lacks the production Coolify entrypoint'
    # shellcheck disable=SC2016 # This literal is evaluated by the isolated image shell, not this script.
    docker run --rm --pull never --platform linux/amd64 --network none --entrypoint /bin/sh "$image" -ec '
        test -x /usr/local/bin/coolify-entrypoint
        test -x /usr/local/bin/control-plane-direct-probe-healthcheck
        test -f /var/www/html/artisan
        test -f /var/www/html/app/Support/ControlPlaneMode.php
        test -f /var/www/html/vendor/autoload.php
        test "$(id -u www-data)" = 9999
        test "$(id -g www-data)" = 9999
        for path in \
            /usr/local/bin/coolify-entrypoint \
            /usr/local/bin/control-plane-direct-probe-healthcheck \
            /var/www/html/app/Support/ControlPlaneMode.php; do
            sha256sum "$path"
        done
        php -r "require \"/var/www/html/vendor/autoload.php\"; exit(class_exists(\"App\\\\Support\\\\ControlPlaneMode\") ? 0 : 1);"
        printf "www-data=%s:%s\n" "$(id -u www-data)" "$(id -g www-data)"
    ' | sha256sum | awk '{print $1}'
}

image_transport_proxy_contract_sha256()
{
    local image=$1 version

    version=$(docker run --rm --pull never --platform linux/amd64 --network none \
        --entrypoint traefik "$image" version)
    grep -q '^Version:' <<< "$version" \
        || image_transport_blocked 'the transported proxy image is not a runnable Traefik image'
    printf '%s\n' "$version" | sha256sum | awk '{print $1}'
}

image_transport_outer_inspect_image()
{
    local role=$1 reference=$2 repo_digests image_digest platform contract_sha256

    image_transport_assert_source_reference "$role source image" "$reference"
    docker image inspect "$reference" >/dev/null \
        || image_transport_blocked "the supplied $role repository digest is not available locally"
    repo_digests=$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$reference")
    grep -F -x -q "$reference" <<< "$repo_digests" \
        || image_transport_blocked "the supplied $role image does not retain its exact repository digest"
    image_digest=$(docker image inspect --format '{{.Id}}' "$reference")
    image_transport_assert_image_digest "$role source image" "$image_digest"
    platform=$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$reference")
    [[ $platform == linux/amd64 ]] \
        || image_transport_blocked "the supplied $role image is not linux/amd64: $platform"
    case "$role" in
        web) contract_sha256=$(image_transport_web_contract_sha256 "$reference") ;;
        proxy) contract_sha256=$(image_transport_proxy_contract_sha256 "$reference") ;;
        *) image_transport_fail 'unsupported image transport role' ;;
    esac
    [[ $contract_sha256 =~ ^[a-f0-9]{64}$ ]] \
        || image_transport_blocked "the supplied $role image did not produce immutable contract evidence"
    printf '%s\n%s\n%s\n' "$image_digest" "$platform" "$contract_sha256"
}

image_transport_outer_evidence_run()
{
    docker run --rm --pull never --platform linux/amd64 --network none --entrypoint /bin/sh \
        --mount "type=volume,source=${EVIDENCE_VOLUME},target=/evidence" \
        "$CONTROL_PLANE_RUNTIME_HOST_IMAGE" "$@"
}

image_transport_outer_prepare_directory()
{
    # shellcheck disable=SC2016 # This literal is evaluated by the isolated evidence helper shell.
    image_transport_outer_evidence_run -ec '
        destination=/evidence/image-transport/$1
        test ! -e "$destination" && test ! -L "$destination"
        install -d -m 0700 -o root -g root "$destination"
        test "$(stat -c '\''%u:%g:%a'\'' "$destination")" = 0:0:700
    ' sh "$1"
}

image_transport_outer_copy_file()
{
    local operation=$1 filename=$2 source=$3

    [[ -f $source && ! -L $source ]] \
        || image_transport_blocked 'outer transport did not create a safe immutable file candidate'
    # shellcheck disable=SC2016 # This literal is evaluated by the isolated evidence helper shell.
    docker run --rm --pull never --platform linux/amd64 --network none --entrypoint /bin/sh \
        --mount "type=bind,source=${source},target=/input/archive,readonly" \
        --mount "type=volume,source=${EVIDENCE_VOLUME},target=/evidence" \
        "$CONTROL_PLANE_RUNTIME_HOST_IMAGE" -ec '
            destination=/evidence/image-transport/$1/$2
            candidate=${destination}.candidate.$$
            test -f /input/archive && test ! -L /input/archive
            test ! -e "$destination" && test ! -L "$destination"
            umask 077
            cp -- /input/archive "$candidate"
            chown root:root "$candidate"
            chmod 0600 "$candidate"
            mv -f -- "$candidate" "$destination"
            test -f "$destination" && test ! -L "$destination"
            test "$(stat -c '\''%u:%g:%a'\'' "$destination")" = 0:0:600
        ' sh "$operation" "$filename"
}

image_transport_outer_file_evidence()
{
    # shellcheck disable=SC2016 # This literal is evaluated by the isolated evidence helper shell.
    image_transport_outer_evidence_run -ec '
        source=/evidence/image-transport/$1/$2
        test -f "$source" && test ! -L "$source"
        sha256sum "$source" | awk '\''{print $1}'\''
        stat -c '\''%u:%g:%a:%s'\'' "$source"
    ' sh "$1" "$2"
}

image_transport_outer_capture_archive()
{
    local operation=$1 reference=$2 filename=$3 transfer_directory archive_candidate

    transfer_directory=$(mktemp -d "/private/tmp/coolify-runtime-fence-image-export.${operation}.XXXXXX")
    archive_candidate=$transfer_directory/archive
    if ! docker save --output "$archive_candidate" "$reference"; then
        rm -f -- "$archive_candidate"
        rmdir -- "$transfer_directory"
        image_transport_blocked "outer Docker could not save the immutable source image: $reference"
    fi
    chmod 0600 "$archive_candidate"
    if ! image_transport_outer_copy_file "$operation" "$filename" "$archive_candidate"; then
        rm -f -- "$archive_candidate"
        rmdir -- "$transfer_directory"
        image_transport_blocked "evidence helper could not persist the immutable archive: $filename"
    fi
    rm -f -- "$archive_candidate"
    rmdir -- "$transfer_directory"
}

image_transport_outer_publish_manifest()
{
    local operation=$1 manifest=$2 transfer_directory manifest_candidate

    transfer_directory=$(mktemp -d "/private/tmp/coolify-runtime-fence-image-manifest.${operation}.XXXXXX")
    manifest_candidate=$transfer_directory/images.env
    printf '%s\n' "$manifest" > "$manifest_candidate"
    chmod 0600 "$manifest_candidate"
    if ! image_transport_outer_copy_file "$operation" images.env "$manifest_candidate"; then
        rm -f -- "$manifest_candidate"
        rmdir -- "$transfer_directory"
        image_transport_blocked 'evidence helper could not persist the immutable image manifest'
    fi
    rm -f -- "$manifest_candidate"
    rmdir -- "$transfer_directory"
}

image_transport_outer_export()
{
    local operation=$1 manifest
    local -a web_evidence=() proxy_evidence=() web_archive=() proxy_archive=() manifest_evidence=()

    image_transport_outer_prepare_directory "$operation"
    mapfile -t web_evidence < <(image_transport_outer_inspect_image web "$CONTROL_PLANE_RUNTIME_WEB_A_IMAGE")
    mapfile -t proxy_evidence < <(image_transport_outer_inspect_image proxy "$CONTROL_PLANE_RUNTIME_PROXY_IMAGE")
    [[ ${#web_evidence[@]} == 3 && ${#proxy_evidence[@]} == 3 ]] \
        || image_transport_blocked 'outer image inspection did not produce complete immutable evidence'
    image_transport_outer_capture_archive "$operation" "$CONTROL_PLANE_RUNTIME_WEB_A_IMAGE" web-image.tar
    image_transport_outer_capture_archive "$operation" "$CONTROL_PLANE_RUNTIME_PROXY_IMAGE" proxy-image.tar
    mapfile -t web_archive < <(image_transport_outer_file_evidence "$operation" web-image.tar)
    mapfile -t proxy_archive < <(image_transport_outer_file_evidence "$operation" proxy-image.tar)
    [[ ${#web_archive[@]} == 2 && ${#proxy_archive[@]} == 2 \
        && ${web_archive[0]} =~ ^[a-f0-9]{64}$ && ${proxy_archive[0]} =~ ^[a-f0-9]{64}$ \
        && ${web_archive[1]} =~ ^0:0:600:[1-9][0-9]*$ && ${proxy_archive[1]} =~ ^0:0:600:[1-9][0-9]*$ ]] \
        || image_transport_blocked 'outer image archive metadata is incomplete or unsafe'
    manifest=$(
        printf 'version=1\noperation_id=%s\n' "$operation"
        printf 'web_source_reference=%s\nproxy_source_reference=%s\n' \
            "$CONTROL_PLANE_RUNTIME_WEB_A_IMAGE" "$CONTROL_PLANE_RUNTIME_PROXY_IMAGE"
        printf 'web_archive_name=web-image.tar\nweb_archive_sha256=%s\nweb_archive_metadata=%s\n' \
            "${web_archive[0]}" "${web_archive[1]}"
        printf 'web_image_digest=%s\nweb_platform=%s\nweb_contract_sha256=%s\n' \
            "${web_evidence[0]}" "${web_evidence[1]}" "${web_evidence[2]}"
        printf 'proxy_archive_name=proxy-image.tar\nproxy_archive_sha256=%s\nproxy_archive_metadata=%s\n' \
            "${proxy_archive[0]}" "${proxy_archive[1]}"
        printf 'proxy_image_digest=%s\nproxy_platform=%s\nproxy_contract_sha256=%s\n' \
            "${proxy_evidence[0]}" "${proxy_evidence[1]}" "${proxy_evidence[2]}"
    )
    image_transport_outer_publish_manifest "$operation" "$manifest"
    mapfile -t manifest_evidence < <(image_transport_outer_file_evidence "$operation" images.env)
    [[ ${#manifest_evidence[@]} == 2 && ${manifest_evidence[0]} =~ ^[a-f0-9]{64}$ \
        && ${manifest_evidence[1]} =~ ^0:0:600:[1-9][0-9]*$ ]] \
        || image_transport_blocked 'outer image transport manifest is incomplete or unsafe'
    IMAGE_TRANSPORT_MANIFEST_SHA256=${manifest_evidence[0]}
    export IMAGE_TRANSPORT_MANIFEST_SHA256
}

declare -A IMAGE_TRANSPORT=()

image_transport_read_manifest()
{
    local manifest=$1 key value line expected actual
    local -A seen=()

    [[ -f $manifest && ! -L $manifest && $(image_transport_file_metadata "$manifest") == 0:0:600:* ]] \
        || image_transport_blocked 'image transport manifest is absent or unsafe'
    expected=$(image_transport_contract_keys)
    actual=$(sed 's/=.*//' "$manifest")
    [[ $actual == "$expected" && $(wc -l < "$manifest") -eq $(wc -l <<< "$expected") ]] \
        || image_transport_blocked 'image transport manifest keys are reordered, duplicate, absent, or unknown'
    IMAGE_TRANSPORT=()
    while IFS= read -r line || [[ -n $line ]]; do
        key=${line%%=*}
        value=${line#*=}
        [[ -n $key && $line == *=* && -z ${seen[$key]+present} && -n $value \
            && $value != *$'\r'* && $value != *$'\n'* ]] \
            || image_transport_blocked 'image transport manifest contains an unsafe value'
        IMAGE_TRANSPORT[$key]=$value
        seen[$key]=1
    done < "$manifest"
}

image_transport_value()
{
    [[ -n ${IMAGE_TRANSPORT[$1]+present} ]] \
        || image_transport_fail "image transport manifest is missing key: $1"
    printf '%s' "${IMAGE_TRANSPORT[$1]}"
}

image_transport_validate_manifest()
{
    local operation=$1 expected_web=$2 expected_proxy=$3
    local kind value

    [[ $(image_transport_value version) == 1 && $(image_transport_value operation_id) == "$operation" \
        && $(image_transport_value web_source_reference) == "$expected_web" \
        && $(image_transport_value proxy_source_reference) == "$expected_proxy" ]] \
        || image_transport_blocked 'image transport manifest does not bind the supplied immutable input'
    image_transport_assert_source_reference web-source-reference "$(image_transport_value web_source_reference)"
    image_transport_assert_source_reference proxy-source-reference "$(image_transport_value proxy_source_reference)"
    [[ $(image_transport_value web_archive_name) == web-image.tar \
        && $(image_transport_value proxy_archive_name) == proxy-image.tar ]] \
        || image_transport_blocked 'image transport manifest uses a non-canonical archive name'
    for kind in web proxy; do
        value=$(image_transport_value "${kind}_archive_sha256")
        [[ $value =~ ^[a-f0-9]{64}$ ]] \
            || image_transport_blocked "image transport $kind archive hash is malformed"
        value=$(image_transport_value "${kind}_archive_metadata")
        [[ $value =~ ^0:0:600:[1-9][0-9]*$ ]] \
            || image_transport_blocked "image transport $kind archive metadata is malformed"
        image_transport_assert_image_digest "$kind image" "$(image_transport_value "${kind}_image_digest")"
        [[ $(image_transport_value "${kind}_platform") == linux/amd64 ]] \
            || image_transport_blocked "image transport $kind image platform is not linux/amd64"
        value=$(image_transport_value "${kind}_contract_sha256")
        [[ $value =~ ^[a-f0-9]{64}$ ]] \
            || image_transport_blocked "image transport $kind content evidence is malformed"
    done
}

image_transport_assert_archive()
{
    local kind=$1 archive_directory=$2 filename archive expected_metadata expected_sha256

    filename=$(image_transport_value "${kind}_archive_name")
    archive=$archive_directory/$filename
    expected_metadata=$(image_transport_value "${kind}_archive_metadata")
    expected_sha256=$(image_transport_value "${kind}_archive_sha256")
    [[ -f $archive && ! -L $archive && $(image_transport_file_metadata "$archive") == "$expected_metadata" ]] \
        || image_transport_blocked "image transport $kind archive metadata differs from its manifest"
    [[ $(sha256sum "$archive" | awk '{print $1}') == "$expected_sha256" ]] \
        || image_transport_blocked "image transport $kind archive hash differs from its manifest"
}

image_transport_verify_loaded_one()
{
    local kind=$1 expected_image actual_image actual_platform evidence

    expected_image=$(image_transport_value "${kind}_image_digest")
    docker image inspect "$expected_image" >/dev/null \
        || image_transport_blocked "inner Docker did not load the manifest-bound $kind image digest"
    actual_image=$(docker image inspect --format '{{.Id}}' "$expected_image")
    [[ $actual_image == "$expected_image" ]] \
        || image_transport_blocked "inner Docker resolved a different $kind image digest after archive import"
    actual_platform=$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$expected_image")
    [[ $actual_platform == "$(image_transport_value "${kind}_platform")" ]] \
        || image_transport_blocked "inner Docker loaded the wrong $kind image platform"
    case "$kind" in
        web) evidence=$(image_transport_web_contract_sha256 "$expected_image") ;;
        proxy) evidence=$(image_transport_proxy_contract_sha256 "$expected_image") ;;
        *) image_transport_fail 'unsupported image transport load role' ;;
    esac
    [[ $evidence == "$(image_transport_value "${kind}_contract_sha256")" ]] \
        || image_transport_blocked "inner Docker $kind content differs from the outer immutable evidence"
}

image_transport_load_one()
{
    local kind=$1 archive_directory=$2 archive expected_image

    archive=$archive_directory/$(image_transport_value "${kind}_archive_name")
    expected_image=$(image_transport_value "${kind}_image_digest")
    ! docker image inspect "$expected_image" >/dev/null 2>&1 \
        || image_transport_blocked "inner image digest existed before immutable $kind archive import"
    docker load --input "$archive" >/dev/null \
        || image_transport_blocked "inner Docker could not load the immutable $kind archive"
    image_transport_verify_loaded_one "$kind"
}

image_transport_import_candidate()
{
    local manifest=$1 archive_directory=$2 operation=$3 expected_web=$4 expected_proxy=$5 expected_sha256=$6
    local manifest_sha256

    image_transport_read_manifest "$manifest"
    manifest_sha256=$(sha256sum "$manifest" | awk '{print $1}')
    [[ -z $expected_sha256 || $manifest_sha256 == "$expected_sha256" ]] \
        || image_transport_blocked 'image transport manifest hash differs from the outer evidence'
    image_transport_validate_manifest "$operation" "$expected_web" "$expected_proxy"
    image_transport_assert_archive web "$archive_directory"
    image_transport_assert_archive proxy "$archive_directory"
    image_transport_load_one web "$archive_directory"
    image_transport_load_one proxy "$archive_directory"
}

image_transport_write_candidate_manifest()
{
    local source=$1 destination=$2 key=$3 value=$4

    sed "s|^${key}=.*|${key}=${value}|" "$source" > "$destination"
    chown root:root "$destination"
    chmod 0600 "$destination"
}

image_transport_expect_rejection()
{
    local label=$1 pattern=$2 manifest=$3 archive_directory=$4 operation=$5 expected_web=$6 expected_proxy=$7 output

    output=$(mktemp /run/coolify-runtime-fence-image-transport-negative.XXXXXX)
    if (image_transport_import_candidate "$manifest" "$archive_directory" "$operation" \
        "$expected_web" "$expected_proxy" '') > "$output" 2>&1; then
        rm -f -- "$output"
        image_transport_fail "$label unexpectedly succeeded"
    fi
    grep -E -q "$pattern" "$output" \
        || {
            cat "$output" >&2
            rm -f -- "$output"
            image_transport_fail "$label did not report the expected refusal"
        }
    rm -f -- "$output"
}

image_transport_run_negatives()
{
    local manifest=$1 archive_directory=$2 operation=$3 expected_web=$4 expected_proxy=$5 candidate
    local zeros=0000000000000000000000000000000000000000000000000000000000000000

    candidate=$(mktemp /run/coolify-runtime-fence-image-transport-tamper.XXXXXX)
    image_transport_write_candidate_manifest "$manifest" "$candidate" web_archive_sha256 "$zeros"
    image_transport_expect_rejection 'tampered immutable web archive evidence' 'archive hash' \
        "$candidate" "$archive_directory" "$operation" "$expected_web" "$expected_proxy"
    rm -f -- "$candidate"
    candidate=$(mktemp /run/coolify-runtime-fence-image-transport-platform.XXXXXX)
    image_transport_write_candidate_manifest "$manifest" "$candidate" web_platform linux/arm64
    image_transport_expect_rejection 'wrong-platform immutable web image evidence' 'platform' \
        "$candidate" "$archive_directory" "$operation" "$expected_web" "$expected_proxy"
    rm -f -- "$candidate"
    candidate=$(mktemp /run/coolify-runtime-fence-image-transport-digest.XXXXXX)
    image_transport_write_candidate_manifest "$manifest" "$candidate" web_image_digest "sha256:${zeros}"
    image_transport_expect_rejection 'missing immutable web image digest evidence' 'did not load.*image digest' \
        "$candidate" "$archive_directory" "$operation" "$expected_web" "$expected_proxy"
    rm -f -- "$candidate"
}

image_transport_export_inner_values()
{
    export CONTROL_PLANE_RUNTIME_IMAGE_TRANSPORT_OPERATION=$1
    CONTROL_PLANE_RUNTIME_WEB_INNER_IMAGE_DIGEST=$(image_transport_value web_image_digest)
    CONTROL_PLANE_RUNTIME_PROXY_INNER_IMAGE_DIGEST=$(image_transport_value proxy_image_digest)
    CONTROL_PLANE_RUNTIME_WEB_ARCHIVE_SHA256=$(image_transport_value web_archive_sha256)
    CONTROL_PLANE_RUNTIME_PROXY_ARCHIVE_SHA256=$(image_transport_value proxy_archive_sha256)
    CONTROL_PLANE_RUNTIME_WEB_CONTRACT_SHA256=$(image_transport_value web_contract_sha256)
    CONTROL_PLANE_RUNTIME_PROXY_CONTRACT_SHA256=$(image_transport_value proxy_contract_sha256)
    export CONTROL_PLANE_RUNTIME_WEB_INNER_IMAGE_DIGEST CONTROL_PLANE_RUNTIME_PROXY_INNER_IMAGE_DIGEST
    export CONTROL_PLANE_RUNTIME_WEB_ARCHIVE_SHA256 CONTROL_PLANE_RUNTIME_PROXY_ARCHIVE_SHA256
    export CONTROL_PLANE_RUNTIME_WEB_CONTRACT_SHA256 CONTROL_PLANE_RUNTIME_PROXY_CONTRACT_SHA256
}

image_transport_import_inner()
{
    local operation=$1 expected_manifest_sha256=$2 expected_web=$3 expected_proxy=$4
    local archive_directory manifest

    [[ $expected_manifest_sha256 =~ ^[a-f0-9]{64}$ ]] \
        || image_transport_blocked 'outer host did not provide an immutable image transport manifest hash'
    archive_directory=/evidence/image-transport/$operation
    manifest=$archive_directory/images.env
    [[ -d $archive_directory && ! -L $archive_directory ]] \
        || image_transport_blocked 'image transport evidence directory is absent or unsafe'
    image_transport_import_candidate "$manifest" "$archive_directory" "$operation" \
        "$expected_web" "$expected_proxy" "$expected_manifest_sha256"
    image_transport_run_negatives "$manifest" "$archive_directory" "$operation" "$expected_web" "$expected_proxy"
    export CONTROL_PLANE_RUNTIME_IMAGE_TRANSPORT_MANIFEST_SHA256=$expected_manifest_sha256
    image_transport_export_inner_values "$operation"
}

image_transport_restore_inner()
{
    local operation=$1 expected_manifest_sha256=$2 expected_web=$3 expected_proxy=$4
    local archive_directory manifest manifest_sha256

    [[ $expected_manifest_sha256 =~ ^[a-f0-9]{64}$ ]] \
        || image_transport_blocked 'outer host did not provide an immutable image transport manifest hash'
    archive_directory=/evidence/image-transport/$operation
    manifest=$archive_directory/images.env
    [[ -d $archive_directory && ! -L $archive_directory ]] \
        || image_transport_blocked 'image transport evidence directory is absent or unsafe after reboot'
    image_transport_read_manifest "$manifest"
    manifest_sha256=$(sha256sum "$manifest" | awk '{print $1}')
    [[ $manifest_sha256 == "$expected_manifest_sha256" ]] \
        || image_transport_blocked 'image transport manifest hash differs from the outer evidence after reboot'
    image_transport_validate_manifest "$operation" "$expected_web" "$expected_proxy"
    image_transport_assert_archive web "$archive_directory"
    image_transport_assert_archive proxy "$archive_directory"
    image_transport_verify_loaded_one web
    image_transport_verify_loaded_one proxy
    export CONTROL_PLANE_RUNTIME_IMAGE_TRANSPORT_MANIFEST_SHA256=$expected_manifest_sha256
    image_transport_export_inner_values "$operation"
}
