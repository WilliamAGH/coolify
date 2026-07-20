#!/usr/bin/env bash

set -Eeuo pipefail

readonly AMD64_PLATFORM='linux/amd64'
readonly ARM64_PLATFORM='linux/arm64'
readonly FIXTURE_EVENT_TYPE='release-operational-acceptance'
readonly FIXTURE_OWNER='williamacallahan/coolify'

SCENARIO=''
GHCR_TARGET=''
DOCKER_TARGET=''
GHCR_CANDIDATE=''
FIXTURE_ID=''
FIXTURE_TAG_PREFIX=''
RESOLVED_INDEX=''
RESOLVED_AMD64=''
RESOLVED_ARM64=''

die() {
    printf 'release-operational-fixtures: %s\n' "$*" >&2
    exit 1
}

require_digest() {
    [[ "$1" =~ ^sha256:[a-f0-9]{64}$ ]] || die "expected a sha256 digest, received: $1"
}

configure_scenario() {
    SCENARIO="$1"

    case "$SCENARIO" in
        issue7)
            GHCR_TARGET='ghcr.io/williamagh/coolify-remediation-issue7'
            DOCKER_TARGET='docker.io/williamagh/coolify-remediation-issue7'
            GHCR_CANDIDATE='ghcr.io/williamagh/coolify-remediation-issue7-candidates'
            ;;
        issue9)
            GHCR_TARGET='ghcr.io/williamagh/coolify-remediation-issue9'
            DOCKER_TARGET='docker.io/williamagh/coolify-remediation-issue9'
            GHCR_CANDIDATE='ghcr.io/williamagh/coolify-remediation-issue9-candidates'
            ;;
        issue18)
            GHCR_TARGET='ghcr.io/williamagh/coolify-remediation-issue18'
            DOCKER_TARGET='docker.io/williamagh/coolify-remediation-issue18'
            GHCR_CANDIDATE='ghcr.io/williamagh/coolify-remediation-issue18-candidates'
            ;;
        *)
            die 'scenario must be one of: issue7, issue9, issue18'
            ;;
    esac

    [[ "$GHCR_TARGET" =~ ^ghcr\.io/williamagh/coolify-remediation-issue(7|9|18)$ ]] \
        || die 'configured GHCR target is outside the disposable allowlist'
    [[ "$DOCKER_TARGET" =~ ^docker\.io/williamagh/coolify-remediation-issue(7|9|18)$ ]] \
        || die 'configured Docker Hub target is outside the disposable allowlist'
    [[ "$GHCR_CANDIDATE" =~ ^ghcr\.io/williamagh/coolify-remediation-issue(7|9|18)-candidates$ ]] \
        || die 'configured candidate target is outside the disposable allowlist'
}

require_run_identity() {
    [[ "${GITHUB_RUN_ID:-}" =~ ^[1-9][0-9]*$ ]] || die 'GITHUB_RUN_ID must be numeric'
    [[ "${GITHUB_RUN_ATTEMPT:-}" =~ ^[1-9][0-9]*$ ]] || die 'GITHUB_RUN_ATTEMPT must be numeric'

    FIXTURE_ID="${SCENARIO}-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
    FIXTURE_TAG_PREFIX="acceptance-${FIXTURE_ID}"
}

require_mutation_context() {
    local default_branch
    local event_action
    local event_repository

    require_run_identity
    command -v jq >/dev/null || die 'jq is required'
    command -v regctl >/dev/null || die 'regctl is required'
    [[ "${GITHUB_ACTIONS:-}" == true ]] || die 'fixture mutation requires GitHub Actions'
    [[ "${GITHUB_EVENT_NAME:-}" == repository_dispatch ]] || die 'fixture mutation requires repository_dispatch'
    [[ "${GITHUB_REPOSITORY:-}" == "$FIXTURE_OWNER" ]] || die "fixture mutation is restricted to $FIXTURE_OWNER"
    [[ "${GITHUB_REF_TYPE:-}" == branch ]] || die 'fixture mutation requires a branch ref'
    [[ "${GITHUB_REF_PROTECTED:-}" == true ]] || die 'fixture mutation requires a protected ref'
    [[ "${GITHUB_SHA:-}" =~ ^[0-9a-f]{40}$ ]] || die 'GITHUB_SHA must be a full commit SHA'
    [[ -r "${GITHUB_EVENT_PATH:-}" ]] || die 'fixture mutation requires a readable GitHub event payload'

    if ! event_action="$(jq -er '.action | strings' "$GITHUB_EVENT_PATH")"; then
        die 'fixture mutation event payload has no action'
    fi
    [[ "$event_action" == "$FIXTURE_EVENT_TYPE" ]] || die "fixture mutation requires $FIXTURE_EVENT_TYPE repository_dispatch"

    if ! event_repository="$(jq -er '.repository.full_name | strings' "$GITHUB_EVENT_PATH")"; then
        die 'fixture mutation event payload has no repository name'
    fi
    [[ "$event_repository" == "$FIXTURE_OWNER" ]] || die 'fixture mutation event repository mismatch'

    if ! default_branch="$(jq -er '.repository.default_branch | strings | select(test("^[A-Za-z0-9._/-]+$"))' "$GITHUB_EVENT_PATH")"; then
        die 'fixture mutation event payload has no valid default branch'
    fi
    [[ "${GITHUB_REF:-}" == "refs/heads/${default_branch}" ]] || die 'fixture mutation must run from the default branch'
    [[ "${GITHUB_REF_NAME:-}" == "$default_branch" ]] || die 'fixture mutation ref name does not match the default branch'

}

fixture_tag() {
    case "$1" in
        candidate|predecessor|legacy|mixed|malformed|split-ghcr|split-docker)
            printf '%s-%s\n' "$FIXTURE_TAG_PREFIX" "$1"
            ;;
        *)
            die 'unknown fixture role'
            ;;
    esac
}

fixture_roles() {
    printf '%s\n' candidate predecessor legacy mixed malformed split-ghcr split-docker
}

fixture_semantic_tag() {
    printf '0.0.0-operational.%s\n' "$GITHUB_RUN_ID"
}

reference_digest_or_absent() {
    local error_file
    local digest
    local status

    error_file="$(mktemp "${TMPDIR:-/tmp}/coolify-release-fixture-regctl.XXXXXX")"
    if digest="$(regctl image digest "$1" 2>"$error_file")"; then
        rm -f -- "$error_file"
        require_digest "$digest"
        printf '%s\n' "$digest"
        return 0
    else
        status=$?
    fi

    if grep -Eiq '^(manifest unknown|name unknown)(: .*)?$|^failed to (get|resolve) manifest [^[:space:]]+: (manifest unknown|not found)$' "$error_file"; then
        rm -f -- "$error_file"
        printf '\n'
        return 0
    fi

    printf 'release-operational-fixtures: registry read failed for %s (status %s): ' "$1" "$status" >&2
    cat "$error_file" >&2
    rm -f -- "$error_file"
    return "$status"
}

resolve_fixture_role() {
    local role="$1"
    local source_tag
    local source_reference
    local platform
    local config
    local label_state
    local amd64_label_state=''
    local arm64_label_state=''

    source_tag="$(fixture_tag "$role")"
    source_reference="${GHCR_CANDIDATE}:${source_tag}"
    RESOLVED_INDEX="$(reference_digest_or_absent "$source_reference")" || die "unable to read fixture source: $source_reference"
    [[ -n "$RESOLVED_INDEX" ]] || die "fixture source is absent: $source_reference"
    require_digest "$RESOLVED_INDEX"

    RESOLVED_AMD64="$(regctl image digest "${GHCR_CANDIDATE}@${RESOLVED_INDEX}" --platform "$AMD64_PLATFORM")"
    RESOLVED_ARM64="$(regctl image digest "${GHCR_CANDIDATE}@${RESOLVED_INDEX}" --platform "$ARM64_PLATFORM")"
    require_digest "$RESOLVED_AMD64"
    require_digest "$RESOLVED_ARM64"
    [[ "$RESOLVED_AMD64" != "$RESOLVED_ARM64" ]] || die "fixture source is not multiarch: $source_reference"

    for platform in "$AMD64_PLATFORM" "$ARM64_PLATFORM"; do
        if ! config="$(regctl image config "${GHCR_CANDIDATE}@${RESOLVED_INDEX}" --platform "$platform")"; then
            die "unable to read fixture source config: $source_reference ($platform)"
        fi
        if ! jq -e --arg fixture "$FIXTURE_ID" --arg role "$role" '
            (.config.Labels // {}) as $labels
            | ($labels | type == "object")
            and ($labels["io.coolify.release-operational-fixture"] == $fixture)
            and ($labels["io.coolify.release-operational-role"] == $role)
        ' <<< "$config" >/dev/null; then
            die "fixture source has no trusted marker: $source_reference ($platform)"
        fi

        if ! label_state="$(jq -er '
            (.config.Labels // {}) as $labels
            | if ($labels | type) != "object" then
                "invalid"
            elif $labels | has("io.coolify.release-run-id") then
                if $labels["io.coolify.release-run-id"] | type == "string" then
                    "present:\($labels["io.coolify.release-run-id"])"
                else
                    "invalid"
                end
            else
                "absent"
            end
        ' <<< "$config")"; then
            die "fixture source has an invalid release label shape: $source_reference ($platform)"
        fi
        if [[ "$platform" == "$AMD64_PLATFORM" ]]; then
            amd64_label_state="$label_state"
        else
            arm64_label_state="$label_state"
        fi
    done

    case "$role" in
        candidate)
            [[ "$amd64_label_state" == "present:${GITHUB_RUN_ID}" && "$arm64_label_state" == "present:${GITHUB_RUN_ID}" ]] \
                || die "fixture candidate has no matching release run id: $source_reference"
            ;;
        predecessor)
            [[ "$amd64_label_state" =~ ^present:[1-9][0-9]*$ && "$arm64_label_state" == "$amd64_label_state" ]] \
                || die "fixture predecessor has no shared numeric release run id: $source_reference"
            (( ${amd64_label_state#present:} < GITHUB_RUN_ID )) \
                || die "fixture predecessor is not older than the candidate: $source_reference"
            ;;
        legacy|split-ghcr|split-docker)
            [[ "$amd64_label_state" == absent && "$arm64_label_state" == absent ]] \
                || die "legacy fixture unexpectedly has a release run id: $source_reference"
            ;;
        mixed)
            [[ "$amd64_label_state" =~ ^present:[1-9][0-9]*$ && "$arm64_label_state" == absent ]] \
                || die "mixed fixture must have numeric amd64 and unlabeled arm64 release state: $source_reference"
            ;;
        malformed)
            [[ "$amd64_label_state" == 'present:malformed' && "$arm64_label_state" == 'present:malformed' ]] \
                || die "malformed fixture must have non-numeric release run ids: $source_reference"
            ;;
    esac
}

fixture_role_release_run_id() {
    local role="$1"
    local platform="$2"

    case "$role" in
        candidate)
            printf '%s\n' "$GITHUB_RUN_ID"
            ;;
        predecessor)
            (( GITHUB_RUN_ID > 1 )) || die 'a predecessor fixture requires GITHUB_RUN_ID greater than one'
            printf '%s\n' "$((GITHUB_RUN_ID - 1))"
            ;;
        legacy|split-ghcr|split-docker)
            printf '\n'
            ;;
        mixed)
            if [[ "$platform" == "$AMD64_PLATFORM" ]]; then
                (( GITHUB_RUN_ID > 1 )) || die 'a mixed fixture requires GITHUB_RUN_ID greater than one'
                printf '%s\n' "$((GITHUB_RUN_ID - 1))"
            else
                printf '\n'
            fi
            ;;
        malformed)
            printf 'malformed\n'
            ;;
        *)
            die 'unknown fixture role'
            ;;
    esac
}

prepare_fixture_platform() {
    local role="$1"
    local platform="$2"
    local architecture="${platform##*/}"
    local source_tag
    local release_run_id
    local context
    local -a labels

    source_tag="$(fixture_tag "$role")"
    release_run_id="$(fixture_role_release_run_id "$role" "$platform")"
    context="${PREPARE_CONTEXT:?}/${role}-${architecture}"
    mkdir -p "$context"
    printf 'FROM scratch\n' > "$context/Dockerfile"

    labels=(
        --label "io.coolify.release-operational-fixture=${FIXTURE_ID}"
        --label "io.coolify.release-operational-role=${role}"
    )
    if [[ -n "$release_run_id" ]]; then
        labels+=(--label "io.coolify.release-run-id=${release_run_id}")
    fi

    docker buildx build \
        --file "$context/Dockerfile" \
        --platform "$platform" \
        --provenance=false \
        --push \
        --sbom=false \
        --tag "${GHCR_CANDIDATE}:${source_tag}-${architecture}" \
        "${labels[@]}" \
        "$context" >&2
}

prepare_fixture_role() {
    local role="$1"
    local source_tag

    source_tag="$(fixture_tag "$role")"
    prepare_fixture_platform "$role" "$AMD64_PLATFORM"
    prepare_fixture_platform "$role" "$ARM64_PLATFORM"
    docker buildx imagetools create \
        --tag "${GHCR_CANDIDATE}:${source_tag}" \
        "${GHCR_CANDIDATE}:${source_tag}-amd64" \
        "${GHCR_CANDIDATE}:${source_tag}-arm64" >&2
    resolve_fixture_role "$role"
}

prepare_sources() {
    local preset="$1"
    local role
    local suffix
    local -a roles

    set_expectation "$preset"
    command -v docker >/dev/null || die 'docker is required to prepare fixture sources'
    PREPARE_CONTEXT="$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/coolify-release-fixtures.XXXXXX")"
    export PREPARE_CONTEXT
    trap 'rm -rf -- "$PREPARE_CONTEXT"' EXIT

    roles=(candidate)
    for role in "$EXPECTED_GHCR_ROLE" "$EXPECTED_DOCKER_ROLE"; do
        [[ -n "$role" ]] || continue
        case " ${roles[*]} " in
            *" ${role} "*) ;;
            *) roles+=("$role") ;;
        esac
    done

    for role in "${roles[@]}"; do
        for suffix in '' '-amd64' '-arm64'; do
            require_reference_absent "${GHCR_CANDIDATE}:$(fixture_tag "$role")${suffix}"
        done
    done

    for role in "${roles[@]}"; do
        prepare_fixture_role "$role"
    done

    jq -cn --arg scenario "$SCENARIO" --arg preset "$preset" --arg candidate_repository "$GHCR_CANDIDATE" --arg tag_prefix "$FIXTURE_TAG_PREFIX" \
        '{scenario: $scenario, preset: $preset, candidate_repository: $candidate_repository, tag_prefix: $tag_prefix}'
}

verify_reference_index() {
    local repository="$1"
    local tag="$2"
    local expected_index="$3"
    local expected_amd64="$4"
    local expected_arm64="$5"
    local observed_index
    local observed_amd64
    local observed_arm64

    observed_index="$(reference_digest_or_absent "${repository}:${tag}")" || return 1
    [[ "$observed_index" == "$expected_index" ]] || return 1
    observed_amd64="$(regctl image digest "${repository}@${observed_index}" --platform "$AMD64_PLATFORM")"
    observed_arm64="$(regctl image digest "${repository}@${observed_index}" --platform "$ARM64_PLATFORM")"
    [[ "$observed_amd64" == "$expected_amd64" && "$observed_arm64" == "$expected_arm64" ]]
}

require_reference_absent() {
    local reference="$1"
    local observed

    observed="$(reference_digest_or_absent "$reference")" || die "unable to read fixture target: $reference"
    [[ -z "$observed" ]] || die "fixture target already exists: $reference"
}

copy_fixture_index() {
    local source_index="$1"
    local target_repository="$2"
    local target_tag="$3"
    local expected_amd64="$4"
    local expected_arm64="$5"

    regctl image copy "${GHCR_CANDIDATE}@${source_index}" "${target_repository}:${target_tag}"
    verify_reference_index "$target_repository" "$target_tag" "$source_index" "$expected_amd64" "$expected_arm64" \
        || die "fixture copy verification failed: ${target_repository}:${target_tag}"
}

set_expectation() {
    local preset="$1"

    EXPECTED_ALIAS=''
    EXPECTED_GHCR_ROLE=''
    EXPECTED_DOCKER_ROLE=''

    case "${SCENARIO}:${preset}" in
        issue7:staging-predecessor|issue18:staging-predecessor)
            EXPECTED_ALIAS='v4.x'
            EXPECTED_GHCR_ROLE='predecessor'
            EXPECTED_DOCKER_ROLE='predecessor'
            ;;
        issue9:latest-bootstrap)
            EXPECTED_ALIAS='latest'
            EXPECTED_GHCR_ROLE='legacy'
            EXPECTED_DOCKER_ROLE='legacy'
            ;;
        issue9:latest-predecessor)
            EXPECTED_ALIAS='latest'
            EXPECTED_GHCR_ROLE='predecessor'
            EXPECTED_DOCKER_ROLE='predecessor'
            ;;
        issue9:latest-mixed)
            EXPECTED_ALIAS='latest'
            EXPECTED_GHCR_ROLE='mixed'
            EXPECTED_DOCKER_ROLE='mixed'
            ;;
        issue9:latest-malformed)
            EXPECTED_ALIAS='latest'
            EXPECTED_GHCR_ROLE='malformed'
            EXPECTED_DOCKER_ROLE='malformed'
            ;;
        issue9:latest-split)
            EXPECTED_ALIAS='latest'
            EXPECTED_GHCR_ROLE='split-ghcr'
            EXPECTED_DOCKER_ROLE='split-docker'
            ;;
        issue9:latest-absent)
            EXPECTED_ALIAS='latest'
            ;;
        issue9:forward-repair)
            EXPECTED_ALIAS='latest'
            EXPECTED_GHCR_ROLE='candidate'
            EXPECTED_DOCKER_ROLE='predecessor'
            ;;
        *)
            die 'preset is not allowed for this issue scenario'
            ;;
    esac
}

resolve_role_values() {
    local role="$1"

    RESOLVED_INDEX=''
    RESOLVED_AMD64=''
    RESOLVED_ARM64=''
    [[ -n "$role" ]] || return 0
    resolve_fixture_role "$role"
}

seed_preset() {
    local preset="$1"
    local candidate_index
    local candidate_amd64
    local candidate_arm64
    local ghcr_index
    local ghcr_amd64
    local ghcr_arm64
    local docker_index
    local docker_amd64
    local docker_arm64
    local candidate_tag
    local semantic_tag=''
    local seed_semantic_tag=false

    set_expectation "$preset"
    resolve_fixture_role candidate
    candidate_index="$RESOLVED_INDEX"
    candidate_amd64="$RESOLVED_AMD64"
    candidate_arm64="$RESOLVED_ARM64"
    candidate_tag="$(fixture_tag candidate)"
    case "${SCENARIO}:${preset}" in
        issue9:forward-repair)
            semantic_tag="$(fixture_semantic_tag)"
            seed_semantic_tag=true
            ;;
        issue9:latest-predecessor)
            semantic_tag="$(fixture_semantic_tag)"
            ;;
    esac

    resolve_role_values "$EXPECTED_GHCR_ROLE"
    ghcr_index="$RESOLVED_INDEX"
    ghcr_amd64="$RESOLVED_AMD64"
    ghcr_arm64="$RESOLVED_ARM64"
    resolve_role_values "$EXPECTED_DOCKER_ROLE"
    docker_index="$RESOLVED_INDEX"
    docker_amd64="$RESOLVED_AMD64"
    docker_arm64="$RESOLVED_ARM64"

    require_reference_absent "${GHCR_TARGET}:${candidate_tag}"
    require_reference_absent "${DOCKER_TARGET}:${candidate_tag}"
    require_reference_absent "${GHCR_TARGET}:${EXPECTED_ALIAS}"
    require_reference_absent "${DOCKER_TARGET}:${EXPECTED_ALIAS}"
    if [[ -n "$semantic_tag" ]]; then
        require_reference_absent "${GHCR_TARGET}:${semantic_tag}"
        require_reference_absent "${DOCKER_TARGET}:${semantic_tag}"
    fi

    copy_fixture_index "$candidate_index" "$GHCR_TARGET" "$candidate_tag" "$candidate_amd64" "$candidate_arm64"
    copy_fixture_index "$candidate_index" "$DOCKER_TARGET" "$candidate_tag" "$candidate_amd64" "$candidate_arm64"
    if [[ "$seed_semantic_tag" == true ]]; then
        copy_fixture_index "$candidate_index" "$GHCR_TARGET" "$semantic_tag" "$candidate_amd64" "$candidate_arm64"
        copy_fixture_index "$candidate_index" "$DOCKER_TARGET" "$semantic_tag" "$candidate_amd64" "$candidate_arm64"
    fi

    if [[ -n "$EXPECTED_GHCR_ROLE" ]]; then
        copy_fixture_index "$ghcr_index" "$GHCR_TARGET" "$EXPECTED_ALIAS" "$ghcr_amd64" "$ghcr_arm64"
    fi
    if [[ -n "$EXPECTED_DOCKER_ROLE" ]]; then
        copy_fixture_index "$docker_index" "$DOCKER_TARGET" "$EXPECTED_ALIAS" "$docker_amd64" "$docker_arm64"
    fi

    snapshot_preset "$preset"
}

snapshot_side() {
    local repository="$1"
    local alias="$2"
    local index
    local amd64
    local arm64

    index="$(reference_digest_or_absent "${repository}:${alias}")" || die "unable to snapshot ${repository}:${alias}"
    if [[ -z "$index" ]]; then
        jq -cn --arg repository "$repository" '{repository: $repository, index: null, amd64: null, arm64: null}'
        return 0
    fi

    amd64="$(regctl image digest "${repository}@${index}" --platform "$AMD64_PLATFORM")"
    arm64="$(regctl image digest "${repository}@${index}" --platform "$ARM64_PLATFORM")"
    require_digest "$amd64"
    require_digest "$arm64"
    [[ "$amd64" != "$arm64" ]] || die "snapshot is not multiarch: ${repository}:${alias}"
    jq -cn --arg repository "$repository" --arg index "$index" --arg amd64 "$amd64" --arg arm64 "$arm64" \
        '{repository: $repository, index: $index, amd64: $amd64, arm64: $arm64}'
}

snapshot_preset() {
    local preset="$1"
    local ghcr_snapshot
    local docker_snapshot

    set_expectation "$preset"
    ghcr_snapshot="$(snapshot_side "$GHCR_TARGET" "$EXPECTED_ALIAS")"
    docker_snapshot="$(snapshot_side "$DOCKER_TARGET" "$EXPECTED_ALIAS")"
    jq -cn \
        --arg scenario "$SCENARIO" \
        --arg preset "$preset" \
        --arg alias "$EXPECTED_ALIAS" \
        --argjson ghcr "$ghcr_snapshot" \
        --argjson docker "$docker_snapshot" \
        '{scenario: $scenario, preset: $preset, alias: $alias, ghcr: $ghcr, docker: $docker}'
}

assert_alias_matches_role() {
    local repository="$1"
    local alias="$2"
    local role="$3"
    local expected_index
    local expected_amd64
    local expected_arm64

    if [[ -z "$role" ]]; then
        assert_alias_absent "$repository" "$alias"
        return 0
    fi

    resolve_fixture_role "$role"
    expected_index="$RESOLVED_INDEX"
    expected_amd64="$RESOLVED_AMD64"
    expected_arm64="$RESOLVED_ARM64"
    verify_reference_index "$repository" "$alias" "$expected_index" "$expected_amd64" "$expected_arm64" \
        || die "fixture alias mismatch: ${repository}:${alias}"
}

assert_alias_absent() {
    local repository="$1"
    local alias="$2"
    local observed_index

    observed_index="$(reference_digest_or_absent "${repository}:${alias}")" \
        || die "unable to read fixture alias: ${repository}:${alias}"
    [[ -z "$observed_index" ]] || die "fixture alias unexpectedly exists: ${repository}:${alias}"
}

verify_seed() {
    local preset="$1"
    local candidate_tag
    local semantic_tag=''

    set_expectation "$preset"
    candidate_tag="$(fixture_tag candidate)"
    assert_alias_matches_role "$GHCR_TARGET" "$candidate_tag" candidate
    assert_alias_matches_role "$DOCKER_TARGET" "$candidate_tag" candidate
    assert_alias_matches_role "$GHCR_TARGET" "$EXPECTED_ALIAS" "$EXPECTED_GHCR_ROLE"
    assert_alias_matches_role "$DOCKER_TARGET" "$EXPECTED_ALIAS" "$EXPECTED_DOCKER_ROLE"
    if [[ "${SCENARIO}:${preset}" == issue9:forward-repair ]]; then
        semantic_tag="$(fixture_semantic_tag)"
        assert_alias_matches_role "$GHCR_TARGET" "$semantic_tag" candidate
        assert_alias_matches_role "$DOCKER_TARGET" "$semantic_tag" candidate
    elif [[ "${SCENARIO}:${preset}" == issue9:latest-predecessor ]]; then
        semantic_tag="$(fixture_semantic_tag)"
        assert_alias_absent "$GHCR_TARGET" "$semantic_tag"
        assert_alias_absent "$DOCKER_TARGET" "$semantic_tag"
    fi
}

pull_alias_matches_index() {
    local repository="$1"
    local alias="$2"
    local expected_index="$3"
    local immutable_id
    local alias_id

    command -v docker >/dev/null || die 'docker is required for passive alias verification'
    docker pull --platform "$AMD64_PLATFORM" "${repository}@${expected_index}"
    immutable_id="$(docker image inspect --format '{{.Id}}' "${repository}@${expected_index}")"
    docker pull --platform "$AMD64_PLATFORM" "${repository}:${alias}"
    alias_id="$(docker image inspect --format '{{.Id}}' "${repository}:${alias}")"
    [[ "$alias_id" == "$immutable_id" ]] || die "pulled alias does not match the immutable index: ${repository}:${alias}"
}

verify_result() {
    local preset="$1"
    local candidate_tag
    local candidate_index
    local candidate_amd64
    local candidate_arm64

    set_expectation "$preset"
    resolve_fixture_role candidate
    candidate_index="$RESOLVED_INDEX"
    candidate_amd64="$RESOLVED_AMD64"
    candidate_arm64="$RESOLVED_ARM64"
    candidate_tag="$(fixture_tag candidate)"

    verify_reference_index "$GHCR_TARGET" "$candidate_tag" "$candidate_index" "$candidate_amd64" "$candidate_arm64" \
        || die "fixture candidate tag mismatch: ${GHCR_TARGET}:${candidate_tag}"
    verify_reference_index "$DOCKER_TARGET" "$candidate_tag" "$candidate_index" "$candidate_amd64" "$candidate_arm64" \
        || die "fixture candidate tag mismatch: ${DOCKER_TARGET}:${candidate_tag}"
    verify_reference_index "$GHCR_TARGET" "$EXPECTED_ALIAS" "$candidate_index" "$candidate_amd64" "$candidate_arm64" \
        || die "fixture result did not converge GHCR: ${GHCR_TARGET}:${EXPECTED_ALIAS}"
    verify_reference_index "$DOCKER_TARGET" "$EXPECTED_ALIAS" "$candidate_index" "$candidate_amd64" "$candidate_arm64" \
        || die "fixture result did not converge Docker Hub: ${DOCKER_TARGET}:${EXPECTED_ALIAS}"
    pull_alias_matches_index "$GHCR_TARGET" "$EXPECTED_ALIAS" "$candidate_index"
    pull_alias_matches_index "$DOCKER_TARGET" "$EXPECTED_ALIAS" "$candidate_index"
    snapshot_preset "$preset"
}

verify_published_side() {
    local repository="$1"
    local alias="$2"
    local expected_index="$3"
    local observed_index
    local observed_amd64
    local observed_arm64

    observed_index="$(reference_digest_or_absent "${repository}:${alias}")" \
        || die "unable to read published alias: ${repository}:${alias}"
    [[ "$observed_index" == "$expected_index" ]] \
        || die "published alias did not resolve to the expected index: ${repository}:${alias}"
    observed_amd64="$(regctl image digest "${repository}@${observed_index}" --platform "$AMD64_PLATFORM")"
    observed_arm64="$(regctl image digest "${repository}@${observed_index}" --platform "$ARM64_PLATFORM")"
    require_digest "$observed_amd64"
    require_digest "$observed_arm64"
    [[ "$observed_amd64" != "$observed_arm64" ]] \
        || die "published alias is not multiarch: ${repository}:${alias}"
    jq -cn --arg repository "$repository" --arg index "$observed_index" --arg amd64 "$observed_amd64" --arg arm64 "$observed_arm64" \
        '{repository: $repository, index: $index, amd64: $amd64, arm64: $arm64}'
}

verify_published() {
    local preset="$1"
    local expected_index="$2"
    local ghcr_snapshot
    local docker_snapshot

    require_digest "$expected_index"
    set_expectation "$preset"
    case "${SCENARIO}:${preset}" in
        issue7:staging-predecessor|issue9:latest-bootstrap|issue18:staging-predecessor) ;;
        *) die 'verify-published is only available for canonical positive publication scenarios' ;;
    esac

    ghcr_snapshot="$(verify_published_side "$GHCR_TARGET" "$EXPECTED_ALIAS" "$expected_index")"
    docker_snapshot="$(verify_published_side "$DOCKER_TARGET" "$EXPECTED_ALIAS" "$expected_index")"
    jq -en --argjson ghcr "$ghcr_snapshot" --argjson docker "$docker_snapshot" '
        $ghcr.index == $docker.index
        and $ghcr.amd64 == $docker.amd64
        and $ghcr.arm64 == $docker.arm64
        and $ghcr.amd64 != $ghcr.arm64
    ' >/dev/null \
        || die 'published aliases disagree on their platform children'
    pull_alias_matches_index "$GHCR_TARGET" "$EXPECTED_ALIAS" "$expected_index"
    pull_alias_matches_index "$DOCKER_TARGET" "$EXPECTED_ALIAS" "$expected_index"
    jq -cn \
        --arg scenario "$SCENARIO" \
        --arg preset "$preset" \
        --arg alias "$EXPECTED_ALIAS" \
        --arg expected_index "$expected_index" \
        --argjson ghcr "$ghcr_snapshot" \
        --argjson docker "$docker_snapshot" \
        '{scenario: $scenario, preset: $preset, alias: $alias, expected_index: $expected_index, ghcr: $ghcr, docker: $docker}'
}

validate_owned_index() {
    local repository="$1"
    local tag="$2"
    local expected_role="${3:-}"
    local expected_platform="${4:-}"
    local index
    local platform
    local config
    local -a platforms

    index="$(reference_digest_or_absent "${repository}:${tag}")" || die "unable to read cleanup candidate: ${repository}:${tag}"
    [[ -n "$index" ]] || return 0
    require_digest "$index"

    if [[ -n "$expected_platform" ]]; then
        platforms=("$expected_platform")
    else
        platforms=("$AMD64_PLATFORM" "$ARM64_PLATFORM")
    fi

    for platform in "${platforms[@]}"; do
        if ! config="$(regctl image config "${repository}@${index}" --platform "$platform")"; then
            die "unable to inspect cleanup candidate: ${repository}:${tag} ($platform)"
        fi
        if [[ -n "$expected_role" ]]; then
            if ! jq -e --arg fixture "$FIXTURE_ID" --arg role "$expected_role" '
                (.config.Labels // {}) as $labels
                | ($labels | type == "object")
                and ($labels["io.coolify.release-operational-fixture"] == $fixture)
                and ($labels["io.coolify.release-operational-role"] == $role)
            ' <<< "$config" >/dev/null; then
                die "cleanup refuses an untrusted fixture tag: ${repository}:${tag}"
            fi
        else
            die "cleanup requires an exact expected role for ${repository}:${tag}"
        fi

        if [[ "$expected_role" == candidate ]]; then
            if ! jq -e --arg run_id "$GITHUB_RUN_ID" '
                (.config.Labels // {})["io.coolify.release-run-id"] == $run_id
            ' <<< "$config" >/dev/null; then
                die "cleanup refuses a candidate with an unexpected release run id: ${repository}:${tag}"
            fi
        fi
    done

    CLEANUP_REFERENCES+=("${repository}:${tag}")
}

validate_owned_alias() {
    local repository="$1"
    local tag="$2"
    local index
    local platform
    local config
    local fixture_owned=true
    local canonical_current=true

    if ! is_allowed_cleanup_alias "$repository" "$tag"; then
        die "cleanup refuses an alias outside the fixed disposable target set: ${repository}:${tag}"
    fi

    index="$(reference_digest_or_absent "${repository}:${tag}")" || die "unable to read cleanup alias: ${repository}:${tag}"
    [[ -n "$index" ]] || return 0
    require_digest "$index"

    for platform in "$AMD64_PLATFORM" "$ARM64_PLATFORM"; do
        if ! config="$(regctl image config "${repository}@${index}" --platform "$platform")"; then
            printf 'release-operational-fixtures: cleanup skipped uninspectable alias: %s (%s)\n' \
                "${repository}:${tag}" "$platform" >&2
            return 0
        fi
        if ! jq -e --arg fixture "$FIXTURE_ID" '
            (.config.Labels // {}) as $labels
            | ($labels | type == "object")
            and ($labels["io.coolify.release-operational-fixture"] == $fixture)
            and ($labels["io.coolify.release-operational-role"] | IN("candidate", "predecessor", "legacy", "mixed", "malformed", "split-ghcr", "split-docker"))
        ' <<< "$config" >/dev/null; then
            fixture_owned=false
        fi
        if ! jq -e \
            --arg run_id "$GITHUB_RUN_ID" \
            --arg run_attempt "$GITHUB_RUN_ATTEMPT" \
            --arg revision "$GITHUB_SHA" '
            (.config.Labels // {}) as $labels
            | ($labels | type == "object")
            and ($labels["io.coolify.release-run-id"] == $run_id)
            and ($labels["io.coolify.release-run-attempt"] == $run_attempt)
            and ($labels["org.opencontainers.image.revision"] == $revision)
        ' <<< "$config" >/dev/null; then
            canonical_current=false
        fi
    done

    if [[ "$fixture_owned" != true && "$canonical_current" != true ]]; then
        printf 'release-operational-fixtures: cleanup skipped unowned or stale alias: %s\n' "${repository}:${tag}" >&2
        return 0
    fi

    CLEANUP_REFERENCES+=("${repository}:${tag}")
}

is_allowed_cleanup_alias() {
    local repository="$1"
    local tag="$2"
    local semantic_tag

    [[ "$repository" == "$GHCR_TARGET" || "$repository" == "$DOCKER_TARGET" ]] || return 1
    case "$SCENARIO" in
        issue7|issue18)
            [[ "$tag" == 'v4.x' ]]
            ;;
        issue9)
            semantic_tag="$(fixture_semantic_tag)"
            [[ "$tag" == latest || "$tag" == "$semantic_tag" ]]
            ;;
        *)
            return 1
            ;;
    esac
}

fixture_aliases() {
    case "$SCENARIO" in
        issue7|issue18) printf '%s\n' 'v4.x' ;;
        issue9) printf '%s\n' latest ;;
    esac
}

preflight_cleanup() {
    local role
    local suffix
    local platform
    local alias
    local semantic_tag

    CLEANUP_REFERENCES=()
    while IFS= read -r role; do
        for suffix in '' '-amd64' '-arm64'; do
            platform=''
            case "$suffix" in
                -amd64) platform="$AMD64_PLATFORM" ;;
                -arm64) platform="$ARM64_PLATFORM" ;;
            esac
            validate_owned_index "$GHCR_CANDIDATE" "$(fixture_tag "$role")${suffix}" "$role" "$platform"
        done
    done < <(fixture_roles)

    validate_owned_index "$GHCR_TARGET" "$(fixture_tag candidate)" candidate
    validate_owned_index "$DOCKER_TARGET" "$(fixture_tag candidate)" candidate
    while IFS= read -r alias; do
        validate_owned_alias "$GHCR_TARGET" "$alias"
        validate_owned_alias "$DOCKER_TARGET" "$alias"
    done < <(fixture_aliases)

    if [[ "$SCENARIO" == issue9 ]]; then
        semantic_tag="$(fixture_semantic_tag)"
        validate_owned_alias "$GHCR_TARGET" "$semantic_tag"
        validate_owned_alias "$DOCKER_TARGET" "$semantic_tag"
    fi
}

cleanup_fixture() {
    local reference

    preflight_cleanup
    for reference in "${CLEANUP_REFERENCES[@]:-}"; do
        [[ -n "$reference" ]] || continue
        regctl tag delete --ignore-missing "$reference"
    done
}

write_targets() {
    jq -cn --arg scenario "$SCENARIO" --arg ghcr_target "$GHCR_TARGET" --arg docker_target "$DOCKER_TARGET" --arg ghcr_candidate "$GHCR_CANDIDATE" \
        '{scenario: $scenario, ghcr_target: $ghcr_target, docker_target: $docker_target, ghcr_candidate: $ghcr_candidate}'
}

command_name="${1:-}"
case "$command_name" in
    targets)
        [[ "$#" -eq 2 ]] || die 'usage: targets ISSUE_SCENARIO'
        configure_scenario "$2"
        write_targets
        ;;
    prepare-sources)
        [[ "$#" -eq 3 ]] || die 'usage: prepare-sources ISSUE_SCENARIO PRESET'
        configure_scenario "$2"
        require_mutation_context
        prepare_sources "$3"
        ;;
    seed)
        [[ "$#" -eq 3 ]] || die 'usage: seed ISSUE_SCENARIO PRESET'
        configure_scenario "$2"
        require_mutation_context
        seed_preset "$3"
        ;;
    snapshot)
        [[ "$#" -eq 3 ]] || die 'usage: snapshot ISSUE_SCENARIO PRESET'
        configure_scenario "$2"
        snapshot_preset "$3"
        ;;
    verify-seed)
        [[ "$#" -eq 3 ]] || die 'usage: verify-seed ISSUE_SCENARIO PRESET'
        configure_scenario "$2"
        require_run_identity
        verify_seed "$3"
        ;;
    verify-result)
        [[ "$#" -eq 3 ]] || die 'usage: verify-result ISSUE_SCENARIO PRESET'
        configure_scenario "$2"
        require_run_identity
        verify_result "$3"
        ;;
    verify-published)
        [[ "$#" -eq 4 ]] || die 'usage: verify-published ISSUE_SCENARIO PRESET EXPECTED_INDEX_DIGEST'
        configure_scenario "$2"
        verify_published "$3" "$4"
        ;;
    cleanup)
        [[ "$#" -eq 2 ]] || die 'usage: cleanup ISSUE_SCENARIO'
        configure_scenario "$2"
        require_mutation_context
        cleanup_fixture
        ;;
    *)
        die 'expected one of: targets, prepare-sources, seed, snapshot, verify-seed, verify-result, verify-published, cleanup'
        ;;
esac
