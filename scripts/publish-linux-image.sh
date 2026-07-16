#!/usr/bin/env bash

set -euo pipefail

readonly AMD64_PLATFORM='linux/amd64'
readonly ARM64_PLATFORM='linux/arm64'

die() {
    printf 'publish-linux-image: %s\n' "$*" >&2
    exit 1
}

require_digest() {
    [[ "$1" =~ ^sha256:[a-f0-9]{64}$ ]] || die "expected a sha256 digest, received: $1"
}

tag_digest_or_empty() {
    regctl image digest "$1" 2>/dev/null || true
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

    if [[ "$(tag_digest_or_empty "${repository}:${tag}")" != "$index_digest" ]]; then
        printf 'publish-linux-image: tag digest mismatch for %s:%s\n' "$repository" "$tag" >&2
        return 1
    fi

    verify_index "$repository" "$index_digest" "$amd64_digest" "$arm64_digest"
}

release_run_id() {
    local repository="$1"
    local index_digest="$2"
    local release_run_id

    release_run_id="$(regctl image config "${repository}@${index_digest}" --platform "$AMD64_PLATFORM" | jq -er '.config.Labels["io.coolify.release-run-id"]')"
    [[ "$release_run_id" =~ ^[0-9]+$ ]] || die "missing monotonic release-run-id label on ${repository}@${index_digest}"
    printf '%s\n' "$release_run_id"
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
    existing_digest="$(tag_digest_or_empty "$target")"

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

    existing_digest="$(tag_digest_or_empty "${repository}:${tag}")"
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

    existing_digest="$(tag_digest_or_empty "${target_repository}:${tag}")"
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

    current_digest="$(tag_digest_or_empty "${repository}:${tag}")"
    [[ "$current_digest" == "$index_digest" ]] || return 0
    regctl tag delete --ignore-missing "${repository}:${tag}"
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
            remove_created_tag "$ghcr_repository" "$tag" "$index_digest"
            die 'unable to create GHCR immutable tag'
        fi
        if [[ "$ghcr_copy_state" == created ]]; then
            ghcr_created='true'
        fi
    fi

    if [[ "$docker_state" == create ]]; then
        if ! docker_copy_state="$(copy_preflighted_tag "$source_repository" "$docker_repository" "$tag" "$index_digest" "$amd64_digest" "$arm64_digest")"; then
            remove_created_tag "$docker_repository" "$tag" "$index_digest"
            if [[ "$ghcr_created" == 'true' ]]; then
                remove_created_tag "$ghcr_repository" "$tag" "$index_digest"
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
            remove_created_tag "$ghcr_repository" "$tag" "$index_digest"
        fi
        if [[ "$docker_created" == 'true' ]]; then
            remove_created_tag "$docker_repository" "$tag" "$index_digest"
        fi
        die 'immutable tag postflight verification failed; compensated newly created tags'
    fi
}

restore_latest() {
    local repository="$1"
    local previous_digest="$2"
    local promoted_digest="$3"
    local current_digest

    current_digest="$(tag_digest_or_empty "${repository}:latest")"
    [[ "$current_digest" == "$promoted_digest" ]] || return 0

    if [[ -n "$previous_digest" ]]; then
        regctl image copy "${repository}@${previous_digest}" "${repository}:latest" || return 1
        [[ "$(tag_digest_or_empty "${repository}:latest")" == "$previous_digest" ]]
    else
        regctl tag delete --ignore-missing "${repository}:latest" || return 1
        [[ -z "$(tag_digest_or_empty "${repository}:latest")" ]]
    fi
}

preflight_latest() {
    local ghcr_repository="$1"
    local docker_repository="$2"
    local candidate_index="$3"
    local candidate_run_id="$4"
    local current_repository
    local current_digest
    local current_run_id

    require_digest "$candidate_index"
    [[ "$candidate_run_id" =~ ^[0-9]+$ ]] || die "release run id must be numeric"

    for current_repository in "$ghcr_repository" "$docker_repository"; do
        current_digest="$(tag_digest_or_empty "${current_repository}:latest")"
        [[ -n "$current_digest" ]] || continue
        require_digest "$current_digest"

        current_run_id="$(release_run_id "$current_repository" "$current_digest")"
        if (( current_run_id > candidate_run_id )); then
            printf 'publish-linux-image: classification=superseded candidate_run_id=%s current_run_id=%s registry=%s digest=%s\n' \
                "$candidate_run_id" "$current_run_id" "$current_repository" "$current_digest" >&2
            return 3
        fi
        if (( current_run_id == candidate_run_id )) && [[ "$current_digest" != "$candidate_index" ]]; then
            die "conflicting latest digests share release run id ${current_run_id}"
        fi
    done
}

promote_latest() {
    local ghcr_repository="$1"
    local docker_repository="$2"
    local candidate_index="$3"
    local candidate_amd64="$4"
    local candidate_arm64="$5"
    local candidate_run_id="$6"
    local ghcr_previous
    local docker_previous
    local desired_repository="$ghcr_repository"
    local desired_index="$candidate_index"
    local desired_amd64="$candidate_amd64"
    local desired_arm64="$candidate_arm64"
    local desired_run_id="$candidate_run_id"
    local current_repository
    local current_digest
    local current_run_id
    local updated_ghcr='false'
    local updated_docker='false'
    local preflight_status

    require_digest "$candidate_index"
    require_digest "$candidate_amd64"
    require_digest "$candidate_arm64"
    [[ "$candidate_run_id" =~ ^[0-9]+$ ]] || die "release run id must be numeric"

    preflight_latest "$ghcr_repository" "$docker_repository" "$candidate_index" "$candidate_run_id" || {
        preflight_status=$?
        return "$preflight_status"
    }

    ghcr_previous="$(tag_digest_or_empty "${ghcr_repository}:latest")"
    docker_previous="$(tag_digest_or_empty "${docker_repository}:latest")"

    for current_repository in "$ghcr_repository" "$docker_repository"; do
        if [[ "$current_repository" == "$ghcr_repository" ]]; then
            current_digest="$ghcr_previous"
        else
            current_digest="$docker_previous"
        fi

        [[ -n "$current_digest" ]] || continue

        current_run_id="$(release_run_id "$current_repository" "$current_digest")"
        if (( current_run_id > desired_run_id )); then
            printf 'publish-linux-image: classification=superseded candidate_run_id=%s current_run_id=%s registry=%s digest=%s\n' \
                "$desired_run_id" "$current_run_id" "$current_repository" "$current_digest" >&2
            return 3
        elif (( current_run_id == desired_run_id )) && [[ "$current_digest" != "$desired_index" ]]; then
            die "conflicting latest digests share release run id ${current_run_id}"
        fi
    done

    if [[ "$ghcr_previous" != "$desired_index" ]]; then
        if ! regctl image copy "${desired_repository}@${desired_index}" "${ghcr_repository}:latest"; then
            die "unable to promote GHCR latest"
        fi
        updated_ghcr='true'
        if ! verify_tag_index "$ghcr_repository" latest "$desired_index" "$desired_amd64" "$desired_arm64"; then
            restore_latest "$ghcr_repository" "$ghcr_previous" "$desired_index" || die 'unable to compensate GHCR latest'
            die "GHCR latest platform verification failed"
        fi
    fi

    if [[ "$docker_previous" != "$desired_index" ]]; then
        if ! regctl image copy "${desired_repository}@${desired_index}" "${docker_repository}:latest"; then
            if [[ "$updated_ghcr" == 'true' ]]; then
                restore_latest "$ghcr_repository" "$ghcr_previous" "$desired_index"
            fi
            die "unable to promote Docker Hub latest; compensated GHCR"
        fi
        updated_docker='true'
        if ! verify_tag_index "$docker_repository" latest "$desired_index" "$desired_amd64" "$desired_arm64"; then
            if [[ "$updated_ghcr" == 'true' ]]; then
                restore_latest "$ghcr_repository" "$ghcr_previous" "$desired_index" || die 'unable to compensate GHCR latest'
            fi
            if [[ "$updated_docker" == 'true' ]]; then
                restore_latest "$docker_repository" "$docker_previous" "$desired_index" || die 'unable to compensate Docker Hub latest'
            fi
            die "Docker Hub latest platform verification failed; compensated both registries"
        fi
    fi

    if ! verify_tag_index "$ghcr_repository" latest "$desired_index" "$desired_amd64" "$desired_arm64" ||
        ! verify_tag_index "$docker_repository" latest "$desired_index" "$desired_amd64" "$desired_arm64"; then
        if [[ "$updated_ghcr" == 'true' ]]; then
            restore_latest "$ghcr_repository" "$ghcr_previous" "$desired_index" || die 'unable to compensate GHCR latest'
        fi
        if [[ "$updated_docker" == 'true' ]]; then
            restore_latest "$docker_repository" "$docker_previous" "$desired_index" || die 'unable to compensate Docker Hub latest'
        fi
        die 'latest postflight verification failed; compensated registries'
    fi
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
        preflight_latest "$@"
        ;;
    promote-latest)
        [[ "$#" -eq 7 ]] || die 'usage: promote-latest GHCR_REPOSITORY DOCKER_REPOSITORY INDEX_DIGEST AMD64_DIGEST ARM64_DIGEST RUN_ID'
        shift
        promote_latest "$@"
        ;;
    cleanup-candidates)
        [[ "$#" -eq 4 ]] || die 'usage: cleanup-candidates CANDIDATE_REPOSITORY TARGET_REPOSITORY RUN_TAG'
        shift
        cleanup_candidates "$@"
        ;;
    *)
        die 'expected one of: verify-index, ensure-tag, ensure-pair, preflight-latest, promote-latest, cleanup-candidates'
        ;;
esac
