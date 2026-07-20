#!/usr/bin/env bash

set -euo pipefail

readonly AMD64_PLATFORM='linux/amd64'
readonly ARM64_PLATFORM='linux/arm64'

REPAIR_CANDIDATE_AMD64=''
REPAIR_CANDIDATE_ARM64=''
REPAIR_CANDIDATE_RUN_ID=''

die() {
    printf 'publish-linux-image: %s\n' "$*" >&2
    exit 1
}

require_digest() {
    [[ "$1" =~ ^sha256:[a-f0-9]{64}$ ]] || die "expected a sha256 digest, received: $1"
}

tag_digest_or_empty() {
    local reference="$1"
    local error_file
    local digest
    local status

    error_file="$(mktemp "${TMPDIR:-/tmp}/coolify-regctl-error.XXXXXX")"
    if digest="$(regctl image digest "$reference" 2>"$error_file")"; then
        rm -f "$error_file"
        require_digest "$digest"
        printf '%s\n' "$digest"
        return 0
    else
        status=$?
    fi

    if grep -Eiq '^(manifest unknown|name unknown)(: .*)?$|^failed to (get|resolve) manifest [^[:space:]]+: (manifest unknown|not found)$' "$error_file"; then
        rm -f "$error_file"
        printf '\n'
        return 0
    fi

    printf 'publish-linux-image: registry read failed for %s (status %s): ' "$reference" "$status" >&2
    cat "$error_file" >&2
    rm -f "$error_file"
    return "$status"
}

verify_index() {
    local repository="$1"
    local index_digest="$2"
    local amd64_digest="$3"
    local arm64_digest="$4"

    if ! [[ "$index_digest" =~ ^sha256:[a-f0-9]{64}$ && "$amd64_digest" =~ ^sha256:[a-f0-9]{64}$ && "$arm64_digest" =~ ^sha256:[a-f0-9]{64}$ ]]; then
        printf 'publish-linux-image: invalid expected digest for %s\n' "$repository" >&2
        return 1
    fi

    if [[ "$(regctl image digest "${repository}@${index_digest}")" != "$index_digest" ]]; then
        printf 'publish-linux-image: index digest mismatch for %s\n' "$repository" >&2
        return 1
    fi
    if [[ "$(regctl image digest "${repository}@${index_digest}" --platform "$AMD64_PLATFORM")" != "$amd64_digest" ]]; then
        printf 'publish-linux-image: amd64 child mismatch for %s\n' "$repository" >&2
        return 1
    fi
    if [[ "$(regctl image digest "${repository}@${index_digest}" --platform "$ARM64_PLATFORM")" != "$arm64_digest" ]]; then
        printf 'publish-linux-image: arm64 child mismatch for %s\n' "$repository" >&2
        return 1
    fi
}

verify_tag_index() {
    local repository="$1"
    local tag="$2"
    local index_digest="$3"
    local amd64_digest="$4"
    local arm64_digest="$5"

    local current_digest
    current_digest="$(tag_digest_or_empty "${repository}:${tag}")" || return 1
    if [[ "$current_digest" != "$index_digest" ]]; then
        printf 'publish-linux-image: tag digest mismatch for %s:%s\n' "$repository" "$tag" >&2
        return 1
    fi

    verify_index "$repository" "$index_digest" "$amd64_digest" "$arm64_digest"
}

release_run_id() {
    local repository="$1"
    local index_digest="$2"
    local platform
    local config
    local label_state
    local label_error_file
    local amd64_label_state
    local arm64_label_state
    local amd64_run_id
    local arm64_run_id

    for platform in "$AMD64_PLATFORM" "$ARM64_PLATFORM"; do
        if ! config="$(regctl image config "${repository}@${index_digest}" --platform "$platform")"; then
            printf 'publish-linux-image: unable to read the release run id from %s@%s for %s\n' \
                "$repository" "$index_digest" "$platform" >&2
            return 1
        fi
        label_error_file="$(mktemp "${TMPDIR:-/tmp}/coolify-release-label.XXXXXX")"
        if label_state="$(jq -r '
            .config.Labels as $labels
            | if ($labels | type) == "object" then
                if ($labels | has("io.coolify.release-run-id")) then
                    if ($labels["io.coolify.release-run-id"] | type) == "string" then
                        "present:\($labels["io.coolify.release-run-id"])"
                    else
                        "invalid"
                    end
                else
                    "absent"
                end
            elif $labels == null then
                "absent"
            else
                "invalid"
            end
        ' <<< "$config" 2>"$label_error_file")"; then
            rm -f "$label_error_file"
        else
            rm -f "$label_error_file"
            printf 'publish-linux-image: invalid image config for %s@%s on %s\n' \
                "$repository" "$index_digest" "$platform" >&2
            return 1
        fi

        if [[ "$platform" == "$AMD64_PLATFORM" ]]; then
            amd64_label_state="$label_state"
        else
            arm64_label_state="$label_state"
        fi
    done

    if [[ "$amd64_label_state" == absent && "$arm64_label_state" == absent ]]; then
        printf '\n'
        return 0
    fi
    if [[ "$amd64_label_state" != present:* || "$arm64_label_state" != present:* ]]; then
        printf 'publish-linux-image: release run id label is mixed or malformed across platforms for %s@%s\n' \
            "$repository" "$index_digest" >&2
        return 1
    fi

    amd64_run_id="${amd64_label_state#present:}"
    arm64_run_id="${arm64_label_state#present:}"
    if ! [[ "$amd64_run_id" =~ ^[0-9]+$ && "$arm64_run_id" =~ ^[0-9]+$ && "$amd64_run_id" == "$arm64_run_id" ]]; then
        printf 'publish-linux-image: release run id label is inconsistent across platforms for %s@%s\n' \
            "$repository" "$index_digest" >&2
        return 1
    fi

    printf '%s\n' "$amd64_run_id"
}

ensure_tag() {
    local source_repository="$1"
    local target_repository="$2"
    local tag="$3"
    local index_digest="$4"
    local amd64_digest="$5"
    local arm64_digest="$6"
    local target="${target_repository}:${tag}"
    local existing_digest

    require_digest "$index_digest"
    existing_digest="$(tag_digest_or_empty "$target")" || die "unable to read immutable tag: $target"

    if [[ -n "$existing_digest" ]]; then
        [[ "$existing_digest" == "$index_digest" ]] || die "immutable tag conflict for ${target}: ${existing_digest} != ${index_digest}"
        printf 'equal\n'
    else
        regctl image copy "${source_repository}@${index_digest}" "$target"
        printf 'created\n'
    fi

    verify_tag_index "$target_repository" "$tag" "$index_digest" "$amd64_digest" "$arm64_digest"
}

preflight_immutable_tag() {
    local repository="$1"
    local tag="$2"
    local index_digest="$3"
    local amd64_digest="$4"
    local arm64_digest="$5"
    local existing_digest

    existing_digest="$(tag_digest_or_empty "${repository}:${tag}")" || return 1
    if [[ -z "$existing_digest" ]]; then
        printf 'create\n'
        return 0
    fi

    if [[ "$existing_digest" != "$index_digest" ]]; then
        printf 'publish-linux-image: immutable tag conflict for %s:%s: %s != %s\n' "$repository" "$tag" "$existing_digest" "$index_digest" >&2
        return 1
    fi

    verify_tag_index "$repository" "$tag" "$index_digest" "$amd64_digest" "$arm64_digest"
    printf 'equal\n'
}

copy_preflighted_tag() {
    local source_repository="$1"
    local target_repository="$2"
    local tag="$3"
    local index_digest="$4"
    local amd64_digest="$5"
    local arm64_digest="$6"
    local existing_digest
    local copy_state

    existing_digest="$(tag_digest_or_empty "${target_repository}:${tag}")" || return 1
    if [[ -n "$existing_digest" ]]; then
        [[ "$existing_digest" == "$index_digest" ]] || return 1
        copy_state='equal'
    else
        regctl image copy "${source_repository}@${index_digest}" "${target_repository}:${tag}" || return 1
        copy_state='created'
    fi

    verify_tag_index "$target_repository" "$tag" "$index_digest" "$amd64_digest" "$arm64_digest" || return 1
    printf '%s\n' "$copy_state"
}

remove_created_tag() {
    local repository="$1"
    local tag="$2"
    local index_digest="$3"
    local current_digest

    current_digest="$(tag_digest_or_empty "${repository}:${tag}")" || return 1
    [[ -n "$current_digest" ]] || return 0
    [[ "$current_digest" == "$index_digest" ]] || return 1
    regctl tag delete --ignore-missing "${repository}:${tag}" || return 1
    current_digest="$(tag_digest_or_empty "${repository}:${tag}")" || return 1
    [[ -z "$current_digest" ]]
}

ensure_tag_pair() {
    local source_repository="$1"
    local ghcr_repository="$2"
    local docker_repository="$3"
    local tag="$4"
    local index_digest="$5"
    local amd64_digest="$6"
    local arm64_digest="$7"
    local ghcr_state
    local docker_state
    local ghcr_copy_state
    local docker_copy_state
    local ghcr_created='false'
    local docker_created='false'

    require_digest "$index_digest"
    require_digest "$amd64_digest"
    require_digest "$arm64_digest"

    ghcr_state="$(preflight_immutable_tag "$ghcr_repository" "$tag" "$index_digest" "$amd64_digest" "$arm64_digest")" || die 'GHCR immutable tag preflight failed'
    docker_state="$(preflight_immutable_tag "$docker_repository" "$tag" "$index_digest" "$amd64_digest" "$arm64_digest")" || die 'Docker Hub immutable tag preflight failed'

    if [[ "$ghcr_state" == create ]]; then
        if ! ghcr_copy_state="$(copy_preflighted_tag "$source_repository" "$ghcr_repository" "$tag" "$index_digest" "$amd64_digest" "$arm64_digest")"; then
            remove_created_tag "$ghcr_repository" "$tag" "$index_digest" || die 'unable to compensate GHCR immutable tag'
            die 'unable to create GHCR immutable tag'
        fi
        if [[ "$ghcr_copy_state" == created ]]; then
            ghcr_created='true'
        fi
    fi

    if [[ "$docker_state" == create ]]; then
        if ! docker_copy_state="$(copy_preflighted_tag "$source_repository" "$docker_repository" "$tag" "$index_digest" "$amd64_digest" "$arm64_digest")"; then
            remove_created_tag "$docker_repository" "$tag" "$index_digest" || die 'unable to compensate Docker Hub immutable tag'
            if [[ "$ghcr_created" == 'true' ]]; then
                remove_created_tag "$ghcr_repository" "$tag" "$index_digest" || die 'unable to compensate GHCR immutable tag'
            fi
            die 'unable to create Docker Hub immutable tag; compensated GHCR'
        fi
        if [[ "$docker_copy_state" == created ]]; then
            docker_created='true'
        fi
    fi

    if ! verify_tag_index "$ghcr_repository" "$tag" "$index_digest" "$amd64_digest" "$arm64_digest" ||
        ! verify_tag_index "$docker_repository" "$tag" "$index_digest" "$amd64_digest" "$arm64_digest"; then
        if [[ "$ghcr_created" == 'true' ]]; then
            remove_created_tag "$ghcr_repository" "$tag" "$index_digest" || die 'unable to compensate GHCR immutable tag after postflight failure'
        fi
        if [[ "$docker_created" == 'true' ]]; then
            remove_created_tag "$docker_repository" "$tag" "$index_digest" || die 'unable to compensate Docker Hub immutable tag after postflight failure'
        fi
        die 'immutable tag postflight verification failed; compensated newly created tags'
    fi
}

restore_alias() {
    local repository="$1"
    local tag="$2"
    local previous_digest="$3"
    local promoted_digest="$4"
    local current_digest

    current_digest="$(tag_digest_or_empty "${repository}:${tag}")" || return 1
    if [[ "$current_digest" == "$previous_digest" ]]; then
        return 0
    fi
    [[ "$current_digest" == "$promoted_digest" ]] || return 1

    if [[ -n "$previous_digest" ]]; then
        regctl image copy "${repository}@${previous_digest}" "${repository}:${tag}" || return 1
        current_digest="$(tag_digest_or_empty "${repository}:${tag}")" || return 1
        [[ "$current_digest" == "$previous_digest" ]]
    else
        regctl tag delete --ignore-missing "${repository}:${tag}" || return 1
        current_digest="$(tag_digest_or_empty "${repository}:${tag}")" || return 1
        [[ -z "$current_digest" ]]
    fi
}

preflight_alias() {
    local ghcr_repository="$1"
    local docker_repository="$2"
    local tag="$3"
    local candidate_index="$4"
    local candidate_run_id="$5"
    local expected_ghcr_previous="${6-}"
    local expected_docker_previous="${7-}"
    local candidate_attested_run_id
    local ghcr_previous
    local docker_previous
    local ghcr_current_run_id
    local docker_current_run_id
    local current_amd64
    local current_arm64

    require_digest "$candidate_index"
    [[ "$candidate_run_id" =~ ^[0-9]+$ ]] || die "release run id must be numeric"

    candidate_attested_run_id="$(release_run_id "$ghcr_repository" "$candidate_index")" || return 1
    [[ -n "$candidate_attested_run_id" ]] \
        || die "candidate ${ghcr_repository}@${candidate_index} is missing a monotonic release-run-id label"
    [[ "$candidate_attested_run_id" == "$candidate_run_id" ]] \
        || die "candidate release run id does not match ${ghcr_repository}@${candidate_index}"

    ghcr_previous="$(tag_digest_or_empty "${ghcr_repository}:${tag}")" || return 1
    docker_previous="$(tag_digest_or_empty "${docker_repository}:${tag}")" || return 1

    if [[ "$#" -eq 7 ]] && [[ "$ghcr_previous" != "$expected_ghcr_previous" || "$docker_previous" != "$expected_docker_previous" ]]; then
        die "alias changed during promotion: ${tag}"
    fi

    if [[ -z "$ghcr_previous" && -z "$docker_previous" ]]; then
        return 0
    fi
    if [[ -z "$ghcr_previous" || -z "$docker_previous" ]]; then
        die "alias state is split across registries for ${tag}"
    fi
    require_digest "$ghcr_previous"
    require_digest "$docker_previous"
    [[ "$ghcr_previous" == "$docker_previous" ]] \
        || die "alias state is split across registries for ${tag}"

    current_amd64="$(regctl image digest "${ghcr_repository}@${ghcr_previous}" --platform "$AMD64_PLATFORM")"
    current_arm64="$(regctl image digest "${ghcr_repository}@${ghcr_previous}" --platform "$ARM64_PLATFORM")"
    require_digest "$current_amd64"
    require_digest "$current_arm64"
    verify_index "$ghcr_repository" "$ghcr_previous" "$current_amd64" "$current_arm64" || return 1
    verify_index "$docker_repository" "$docker_previous" "$current_amd64" "$current_arm64" || return 1

    ghcr_current_run_id="$(release_run_id "$ghcr_repository" "$ghcr_previous")" || return 1
    docker_current_run_id="$(release_run_id "$docker_repository" "$docker_previous")" || return 1
    if [[ -z "$ghcr_current_run_id" && -z "$docker_current_run_id" ]]; then
        return 0
    fi
    if [[ -z "$ghcr_current_run_id" || -z "$docker_current_run_id" ]]; then
        die "alias state mixes legacy and release-labeled images for ${tag}"
    fi
    [[ "$ghcr_current_run_id" == "$docker_current_run_id" ]] \
        || die "alias state has conflicting release run ids for ${tag}"

    if (( ghcr_current_run_id > candidate_run_id )); then
        printf 'publish-linux-image: classification=superseded candidate_run_id=%s current_run_id=%s tag=%s digest=%s\n' \
            "$candidate_run_id" "$ghcr_current_run_id" "$tag" "$ghcr_previous" >&2
        return 3
    fi
    if (( ghcr_current_run_id == candidate_run_id )) && [[ "$ghcr_previous" != "$candidate_index" ]]; then
        die "conflicting ${tag} digests share release run id ${ghcr_current_run_id}"
    fi
}

validate_docker_alias_before_copy_failure_injection() {
    local ghcr_repository="$1"
    local docker_repository="$2"
    local candidate_index="$3"
    local ghcr_previous="$4"
    local docker_previous="$5"

    [[ "${PUBLISH_LINUX_IMAGE_FAILURE_INJECTION:-}" == 'docker-alias-before-copy' ]] \
        || die 'unsupported release failure injection mode'
    [[ "${GITHUB_REPOSITORY:-}" == 'williamacallahan/coolify' ]] \
        || die 'release failure injection requires the williamacallahan/coolify repository'
    [[ "${GITHUB_EVENT_NAME:-}" == 'repository_dispatch' ]] \
        || die 'release failure injection requires a repository_dispatch event'
    [[ "${GITHUB_REF:-}" == 'refs/heads/v4.x' ]] \
        || die 'release failure injection requires refs/heads/v4.x'
    [[ "${GITHUB_REF_PROTECTED:-}" == 'true' ]] \
        || die 'release failure injection requires a protected ref'

    case "${ghcr_repository}|${docker_repository}" in
        'ghcr.io/williamagh/coolify-remediation-issue7|docker.io/williamagh/coolify-remediation-issue7' | \
        'ghcr.io/williamagh/coolify-remediation-issue9|docker.io/williamagh/coolify-remediation-issue9' | \
        'ghcr.io/williamagh/coolify-remediation-issue18|docker.io/williamagh/coolify-remediation-issue18')
            ;;
        *)
            die 'release failure injection target pair is not allowlisted'
            ;;
    esac

    [[ -n "$ghcr_previous" && -n "$docker_previous" ]] \
        || die 'release failure injection requires both aliases to have predecessors'
    [[ "$candidate_index" != "$ghcr_previous" && "$candidate_index" != "$docker_previous" ]] \
        || die 'release failure injection requires a candidate distinct from both alias predecessors'
}

promote_alias() {
    local ghcr_repository="$1"
    local docker_repository="$2"
    local tag="$3"
    local candidate_index="$4"
    local candidate_amd64="$5"
    local candidate_arm64="$6"
    local candidate_run_id="$7"
    local ghcr_previous
    local docker_previous
    local updated_ghcr='false'
    local updated_docker='false'
    local preflight_status
    local inject_docker_alias_before_copy='false'

    require_digest "$candidate_index"
    require_digest "$candidate_amd64"
    require_digest "$candidate_arm64"
    [[ "$candidate_run_id" =~ ^[0-9]+$ ]] || die "release run id must be numeric"

    ghcr_previous="$(tag_digest_or_empty "${ghcr_repository}:${tag}")" || return 1
    docker_previous="$(tag_digest_or_empty "${docker_repository}:${tag}")" || return 1

    preflight_alias "$ghcr_repository" "$docker_repository" "$tag" "$candidate_index" "$candidate_run_id" \
        "$ghcr_previous" "$docker_previous" || {
        preflight_status=$?
        return "$preflight_status"
    }

    if [[ -n "${PUBLISH_LINUX_IMAGE_FAILURE_INJECTION:-}" ]]; then
        validate_docker_alias_before_copy_failure_injection \
            "$ghcr_repository" "$docker_repository" "$candidate_index" "$ghcr_previous" "$docker_previous"
        inject_docker_alias_before_copy='true'
    fi

    if [[ "$ghcr_previous" != "$candidate_index" ]]; then
        if ! regctl image copy "${ghcr_repository}@${candidate_index}" "${ghcr_repository}:${tag}"; then
            die "unable to promote GHCR ${tag}"
        fi
        updated_ghcr='true'
        if ! verify_tag_index "$ghcr_repository" "$tag" "$candidate_index" "$candidate_amd64" "$candidate_arm64"; then
            restore_alias "$ghcr_repository" "$tag" "$ghcr_previous" "$candidate_index" \
                || die "unable to compensate GHCR ${tag}"
            die "GHCR ${tag} platform verification failed"
        fi
    fi

    if [[ "$inject_docker_alias_before_copy" == 'true' ]]; then
        die "injected failure after GHCR ${tag} promotion before Docker Hub copy"
    fi

    if [[ "$docker_previous" != "$candidate_index" ]]; then
        if ! regctl image copy "${ghcr_repository}@${candidate_index}" "${docker_repository}:${tag}"; then
            if [[ "$updated_ghcr" == 'true' ]]; then
                restore_alias "$ghcr_repository" "$tag" "$ghcr_previous" "$candidate_index" ||
                    die "unable to compensate GHCR ${tag} after Docker Hub promotion failure"
            fi
            die "unable to promote Docker Hub ${tag}; compensated GHCR"
        fi
        updated_docker='true'
        if ! verify_tag_index "$docker_repository" "$tag" "$candidate_index" "$candidate_amd64" "$candidate_arm64"; then
            if [[ "$updated_ghcr" == 'true' ]]; then
                restore_alias "$ghcr_repository" "$tag" "$ghcr_previous" "$candidate_index" \
                    || die "unable to compensate GHCR ${tag}"
            fi
            if [[ "$updated_docker" == 'true' ]]; then
                restore_alias "$docker_repository" "$tag" "$docker_previous" "$candidate_index" \
                    || die "unable to compensate Docker Hub ${tag}"
            fi
            die "Docker Hub ${tag} platform verification failed; compensated both registries"
        fi
    fi

    if ! verify_tag_index "$ghcr_repository" "$tag" "$candidate_index" "$candidate_amd64" "$candidate_arm64" ||
        ! verify_tag_index "$docker_repository" "$tag" "$candidate_index" "$candidate_amd64" "$candidate_arm64"; then
        if [[ "$updated_ghcr" == 'true' ]]; then
            restore_alias "$ghcr_repository" "$tag" "$ghcr_previous" "$candidate_index" \
                || die "unable to compensate GHCR ${tag}"
        fi
        if [[ "$updated_docker" == 'true' ]]; then
            restore_alias "$docker_repository" "$tag" "$docker_previous" "$candidate_index" \
                || die "unable to compensate Docker Hub ${tag}"
        fi
        die "${tag} postflight verification failed; compensated registries"
    fi
}

verify_repair_candidate() {
    local ghcr_repository="$1"
    local docker_repository="$2"
    local candidate_index="$3"
    local ghcr_candidate_run_id
    local docker_candidate_run_id

    require_digest "$candidate_index"

    REPAIR_CANDIDATE_AMD64="$(regctl image digest "${ghcr_repository}@${candidate_index}" --platform "$AMD64_PLATFORM")"
    REPAIR_CANDIDATE_ARM64="$(regctl image digest "${ghcr_repository}@${candidate_index}" --platform "$ARM64_PLATFORM")"
    require_digest "$REPAIR_CANDIDATE_AMD64"
    require_digest "$REPAIR_CANDIDATE_ARM64"

    verify_index "$ghcr_repository" "$candidate_index" "$REPAIR_CANDIDATE_AMD64" "$REPAIR_CANDIDATE_ARM64" || return 1
    verify_index "$docker_repository" "$candidate_index" "$REPAIR_CANDIDATE_AMD64" "$REPAIR_CANDIDATE_ARM64" || return 1

    ghcr_candidate_run_id="$(release_run_id "$ghcr_repository" "$candidate_index")" || return 1
    docker_candidate_run_id="$(release_run_id "$docker_repository" "$candidate_index")" || return 1

    if [[ -z "$ghcr_candidate_run_id" || -z "$docker_candidate_run_id" ]]; then
        die "unlabeled immutable semantic digest ${candidate_index}; refusing automatic repair"
    fi
    [[ "$ghcr_candidate_run_id" == "$docker_candidate_run_id" ]] \
        || die "repair candidate state has conflicting release run ids for ${candidate_index}"
    [[ "$ghcr_candidate_run_id" =~ ^[1-9][0-9]*$ ]] \
        || die "repair candidate release run id is invalid for ${candidate_index}"

    REPAIR_CANDIDATE_RUN_ID="$ghcr_candidate_run_id"
}

verify_common_repair_predecessor() {
    local ghcr_repository="$1"
    local docker_repository="$2"
    local predecessor_index="$3"
    local candidate_run_id="$4"
    local predecessor_amd64
    local predecessor_arm64
    local ghcr_predecessor_run_id
    local docker_predecessor_run_id

    require_digest "$predecessor_index"

    predecessor_amd64="$(regctl image digest "${ghcr_repository}@${predecessor_index}" --platform "$AMD64_PLATFORM")"
    predecessor_arm64="$(regctl image digest "${ghcr_repository}@${predecessor_index}" --platform "$ARM64_PLATFORM")"
    require_digest "$predecessor_amd64"
    require_digest "$predecessor_arm64"
    verify_index "$ghcr_repository" "$predecessor_index" "$predecessor_amd64" "$predecessor_arm64" || return 1
    verify_index "$docker_repository" "$predecessor_index" "$predecessor_amd64" "$predecessor_arm64" || return 1

    ghcr_predecessor_run_id="$(release_run_id "$ghcr_repository" "$predecessor_index")" || return 1
    docker_predecessor_run_id="$(release_run_id "$docker_repository" "$predecessor_index")" || return 1

    if [[ -z "$ghcr_predecessor_run_id" && -z "$docker_predecessor_run_id" ]]; then
        return 0
    fi
    if [[ -z "$ghcr_predecessor_run_id" || -z "$docker_predecessor_run_id" ]]; then
        die "repair predecessor state mixes legacy and release-labeled images for ${predecessor_index}"
    fi
    [[ "$ghcr_predecessor_run_id" == "$docker_predecessor_run_id" ]] \
        || die "repair predecessor state has conflicting release run ids for ${predecessor_index}"
    [[ "$ghcr_predecessor_run_id" =~ ^[1-9][0-9]*$ ]] \
        || die "repair predecessor release run id is invalid for ${predecessor_index}"

    if (( ghcr_predecessor_run_id > candidate_run_id )); then
        printf 'publish-linux-image: classification=superseded candidate_run_id=%s current_run_id=%s digest=%s\n' \
            "$candidate_run_id" "$ghcr_predecessor_run_id" "$predecessor_index" >&2
        return 3
    fi
    if (( ghcr_predecessor_run_id == candidate_run_id )); then
        die "repair predecessor ${predecessor_index} conflicts with candidate run id ${candidate_run_id}"
    fi
}

verify_repair_snapshot() {
    local ghcr_repository="$1"
    local docker_repository="$2"
    local tag="$3"
    local expected_ghcr_digest="$4"
    local expected_docker_digest="$5"
    local observed_ghcr_digest
    local observed_docker_digest

    observed_ghcr_digest="$(tag_digest_or_empty "${ghcr_repository}:${tag}")" || return 1
    observed_docker_digest="$(tag_digest_or_empty "${docker_repository}:${tag}")" || return 1
    if [[ "$observed_ghcr_digest" != "$expected_ghcr_digest" || "$observed_docker_digest" != "$expected_docker_digest" ]]; then
        die "alias changed during forward repair: ${tag}"
    fi
}

repair_alias_forward() {
    local ghcr_repository="$1"
    local docker_repository="$2"
    local tag="$3"
    local candidate_index="$4"
    local candidate_amd64
    local candidate_arm64
    local candidate_run_id
    local ghcr_previous
    local docker_previous
    local predecessor_status
    local lagging_repository

    verify_repair_candidate "$ghcr_repository" "$docker_repository" "$candidate_index"
    candidate_amd64="$REPAIR_CANDIDATE_AMD64"
    candidate_arm64="$REPAIR_CANDIDATE_ARM64"
    candidate_run_id="$REPAIR_CANDIDATE_RUN_ID"

    ghcr_previous="$(tag_digest_or_empty "${ghcr_repository}:${tag}")" || return 1
    docker_previous="$(tag_digest_or_empty "${docker_repository}:${tag}")" || return 1
    if [[ -z "$ghcr_previous" || -z "$docker_previous" ]]; then
        die "alias state is absent or split across registries for ${tag}"
    fi
    require_digest "$ghcr_previous"
    require_digest "$docker_previous"

    if [[ "$ghcr_previous" == "$candidate_index" && "$docker_previous" == "$candidate_index" ]]; then
        verify_tag_index "$ghcr_repository" "$tag" "$candidate_index" "$candidate_amd64" "$candidate_arm64"
        verify_tag_index "$docker_repository" "$tag" "$candidate_index" "$candidate_amd64" "$candidate_arm64"
        printf 'equal\n'
        return 0
    fi

    if [[ "$ghcr_previous" != "$candidate_index" && "$docker_previous" != "$candidate_index" ]]; then
        [[ "$ghcr_previous" == "$docker_previous" ]] \
            || die "alias state is split across registries for ${tag}"
        verify_common_repair_predecessor \
            "$ghcr_repository" "$docker_repository" "$ghcr_previous" "$candidate_run_id" || {
            predecessor_status=$?
            return "$predecessor_status"
        }
        promote_alias \
            "$ghcr_repository" "$docker_repository" "$tag" "$candidate_index" "$candidate_amd64" "$candidate_arm64" "$candidate_run_id"
        return 0
    fi

    if [[ "$ghcr_previous" == "$candidate_index" ]]; then
        lagging_repository="$docker_repository"
        verify_common_repair_predecessor \
            "$ghcr_repository" "$docker_repository" "$docker_previous" "$candidate_run_id" || {
            predecessor_status=$?
            return "$predecessor_status"
        }
    else
        lagging_repository="$ghcr_repository"
        verify_common_repair_predecessor \
            "$ghcr_repository" "$docker_repository" "$ghcr_previous" "$candidate_run_id" || {
            predecessor_status=$?
            return "$predecessor_status"
        }
    fi

    verify_repair_snapshot "$ghcr_repository" "$docker_repository" "$tag" "$ghcr_previous" "$docker_previous"
    regctl image copy "${ghcr_repository}@${candidate_index}" "${lagging_repository}:${tag}" \
        || die "unable to forward-repair ${lagging_repository}:${tag}"
    verify_tag_index "$ghcr_repository" "$tag" "$candidate_index" "$candidate_amd64" "$candidate_arm64"
    verify_tag_index "$docker_repository" "$tag" "$candidate_index" "$candidate_amd64" "$candidate_arm64"
    printf 'repaired\n'
}

cleanup_candidates() {
    local candidate_repository="$1"
    local target_repository="$2"
    local run_tag="$3"
    local candidate_tag

    [[ "$run_tag" =~ ^sha-[0-9a-f]{40}-run-[1-9][0-9]*-[1-9][0-9]*$ ]] || die 'candidate cleanup requires a validated nonempty run tag'
    [[ "$candidate_repository" != "$target_repository" ]] || return 0

    for candidate_tag in "${run_tag}-amd64" "${run_tag}-arm64" "$run_tag"; do
        regctl tag delete --ignore-missing "${candidate_repository}:${candidate_tag}"
    done
}

case "${1:-}" in
    verify-index)
        [[ "$#" -eq 5 ]] || die 'usage: verify-index REPOSITORY INDEX_DIGEST AMD64_DIGEST ARM64_DIGEST'
        shift
        verify_index "$@"
        ;;
    ensure-tag)
        [[ "$#" -eq 7 ]] || die 'usage: ensure-tag SOURCE_REPOSITORY TARGET_REPOSITORY TAG INDEX_DIGEST AMD64_DIGEST ARM64_DIGEST'
        shift
        ensure_tag "$@"
        ;;
    ensure-pair)
        [[ "$#" -eq 8 ]] || die 'usage: ensure-pair SOURCE_REPOSITORY GHCR_REPOSITORY DOCKER_REPOSITORY TAG INDEX_DIGEST AMD64_DIGEST ARM64_DIGEST'
        shift
        ensure_tag_pair "$@"
        ;;
    preflight-latest)
        [[ "$#" -eq 5 ]] || die 'usage: preflight-latest GHCR_REPOSITORY DOCKER_REPOSITORY INDEX_DIGEST RUN_ID'
        shift
        preflight_alias "$1" "$2" latest "$3" "$4"
        ;;
    promote-latest)
        [[ "$#" -eq 7 ]] || die 'usage: promote-latest GHCR_REPOSITORY DOCKER_REPOSITORY INDEX_DIGEST AMD64_DIGEST ARM64_DIGEST RUN_ID'
        shift
        promote_alias "$1" "$2" latest "$3" "$4" "$5" "$6"
        ;;
    promote-alias)
        [[ "$#" -eq 8 ]] || die 'usage: promote-alias GHCR_REPOSITORY DOCKER_REPOSITORY TAG INDEX_DIGEST AMD64_DIGEST ARM64_DIGEST RUN_ID'
        shift
        promote_alias "$@"
        ;;
    repair-alias-forward)
        [[ "$#" -eq 5 ]] || die 'usage: repair-alias-forward GHCR_REPOSITORY DOCKER_REPOSITORY TAG INDEX_DIGEST'
        shift
        repair_alias_forward "$@"
        ;;
    cleanup-candidates)
        [[ "$#" -eq 4 ]] || die 'usage: cleanup-candidates CANDIDATE_REPOSITORY TARGET_REPOSITORY RUN_TAG'
        shift
        cleanup_candidates "$@"
        ;;
    *)
        die 'expected one of: verify-index, ensure-tag, ensure-pair, preflight-latest, promote-latest, promote-alias, repair-alias-forward, cleanup-candidates'
        ;;
esac
