#!/usr/bin/env bash

set -Eeuo pipefail

repository_root="$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)"
helper="$repository_root/scripts/release-operational-fixtures.sh"
state="$(mktemp -d "${TMPDIR:-/tmp}/coolify-release-fixtures.XXXXXX")"
mock_bin="$state/bin"
log="$state/registry.log"
event_path="$state/event.json"

fail() {
    printf 'RELEASE_OPERATIONAL_FIXTURES_FAILURE %s\n' "$1" >&2
    exit 1
}

repeat_digest() {
    local character="$1"

    printf 'sha256:'
    printf '%*s' 64 '' | tr ' ' "$character"
}

reference_key() {
    if command -v sha256sum >/dev/null; then
        printf '%s' "$1" | sha256sum | awk '{print $1}'
    else
        printf '%s' "$1" | shasum -a 256 | awk '{print $1}'
    fi
}

reference_path() {
    printf '%s/refs/%s\n' "$state" "$(reference_key "$1")"
}

set_reference() {
    printf '%s\n' "$2" > "$(reference_path "$1")"
}

get_reference() {
    cat "$(reference_path "$1")"
}

delete_reference() {
    rm -f -- "$(reference_path "$1")"
}

set_config() {
    printf '%s\n' "$2" > "$state/config/${1#sha256:}"
}

platform_config_path() {
    local digest="$1"
    local platform_key="${2//\//_}"

    printf '%s/platform-config/%s/%s\n' "$state" "${digest#sha256:}" "$platform_key"
}

set_platform_config() {
    mkdir -p "$(dirname -- "$(platform_config_path "$1" "$2")")"
    printf '%s\n' "$3" > "$(platform_config_path "$1" "$2")"
}

fixture_config() {
    local scenario="$1"
    local role="$2"
    local run_id="$3"
    local release_run_id="${4:-}"

    if [[ -n "$release_run_id" ]]; then
        jq -cn --arg fixture "${scenario}-${run_id}-1" --arg role "$role" --arg release_run_id "$release_run_id" \
            '{config: {Labels: {"io.coolify.release-operational-fixture": $fixture, "io.coolify.release-operational-role": $role, "io.coolify.release-run-id": $release_run_id}}}'
    else
        jq -cn --arg fixture "${scenario}-${run_id}-1" --arg role "$role" \
            '{config: {Labels: {"io.coolify.release-operational-fixture": $fixture, "io.coolify.release-operational-role": $role}}}'
    fi
}

operational_output_config() {
    local run_id="$1"
    local run_attempt="$2"
    local revision="$3"

    jq -cn --arg run_id "$run_id" --arg run_attempt "$run_attempt" --arg revision "$revision" \
        '{config: {Labels: {"io.coolify.release-run-id": $run_id, "io.coolify.release-run-attempt": $run_attempt, "org.opencontainers.image.revision": $revision}}}'
}

prepare_source() {
    local scenario="$1"
    local role="$2"
    local digest="$3"
    local release_run_id="${4:-}"
    local candidate_repository="ghcr.io/williamagh/coolify-remediation-${scenario}-candidates"
    local tag="acceptance-${scenario}-123-1-${role}"

    set_reference "${candidate_repository}:${tag}" "$digest"
    set_reference "${candidate_repository}@${digest}" "$digest"
    set_config "$digest" "$(fixture_config "$scenario" "$role" 123 "$release_run_id")"
}

prepare_issue9_sources() {
    local mixed_index
    local malformed_index

    mixed_index="$(repeat_digest 1)"
    malformed_index="$(repeat_digest 2)"

    prepare_source issue9 candidate "$(repeat_digest e)" 123
    prepare_source issue9 legacy "$(repeat_digest f)"
    prepare_source issue9 mixed "$mixed_index"
    set_platform_config "$mixed_index" linux/amd64 "$(fixture_config issue9 mixed 123 122)"
    set_platform_config "$mixed_index" linux/arm64 "$(fixture_config issue9 mixed 123)"
    prepare_source issue9 malformed "$malformed_index"
    set_platform_config "$malformed_index" linux/amd64 "$(fixture_config issue9 malformed 123 malformed)"
    set_platform_config "$malformed_index" linux/arm64 "$(fixture_config issue9 malformed 123 malformed)"
    prepare_source issue9 split-ghcr "$(repeat_digest 3)"
    prepare_source issue9 split-docker "$(repeat_digest 4)"
    prepare_source issue9 predecessor "$(repeat_digest 5)" 122
}

assert_no_mutation() {
    if grep -Eq '^(copy|delete):' "$log"; then
        cat "$log" >&2
        fail "$1"
    fi
}

assert_no_fixture_mutation() {
    if grep -Eq '^(copy|delete|docker-build|docker-index|docker-pull):' "$log"; then
        cat "$log" >&2
        fail "$1"
    fi
}

clear_log() {
    : > "$log"
}

cleanup() {
    rm -rf -- "$state"
}

trap cleanup EXIT INT TERM

mkdir -p "$mock_bin" "$state/refs" "$state/config" "$state/platform-config"
touch "$log"
cat > "$mock_bin/regctl" <<'SH'
#!/usr/bin/env bash

set -Eeuo pipefail

reference_key() {
    if command -v sha256sum >/dev/null; then
        printf '%s' "$1" | sha256sum | awk '{print $1}'
    else
        printf '%s' "$1" | shasum -a 256 | awk '{print $1}'
    fi
}

reference_path() {
    printf '%s/refs/%s\n' "$REGCTL_STATE" "$(reference_key "$1")"
}

read_reference() {
    local path

    path="$(reference_path "$1")"
    if [[ ! -f "$path" ]]; then
        printf 'manifest unknown: %s\n' "$1" >&2
        return 1
    fi
    cat "$path"
}

write_reference() {
    local path

    path="$(reference_path "$1")"
    printf '%s\n' "$2" > "$path"
}

case "${1:-}:${2:-}" in
    image:digest)
        reference="${3:-}"
        if [[ " $* " == *' --platform linux/amd64 '* ]]; then
            printf '%s\n' "$REGCTL_AMD64"
            printf 'platform:%s:linux/amd64\n' "$reference" >> "$REGCTL_LOG"
            exit 0
        fi
        if [[ " $* " == *' --platform linux/arm64 '* ]]; then
            printf '%s\n' "$REGCTL_ARM64"
            printf 'platform:%s:linux/arm64\n' "$reference" >> "$REGCTL_LOG"
            exit 0
        fi
        printf 'digest:%s\n' "$reference" >> "$REGCTL_LOG"
        read_reference "$reference"
        ;;
    image:copy)
        source="${3:-}"
        target="${4:-}"
        digest="$(read_reference "$source")"
        target_repository="${target%:*}"
        write_reference "$target" "$digest"
        write_reference "${target_repository}@${digest}" "$digest"
        printf 'copy:%s:%s\n' "$source" "$target" >> "$REGCTL_LOG"
        ;;
    image:config)
        reference="${3:-}"
        digest="${reference##*@}"
        platform=''
        if [[ " $* " == *' --platform linux/amd64 '* ]]; then
            platform='linux_amd64'
        elif [[ " $* " == *' --platform linux/arm64 '* ]]; then
            platform='linux_arm64'
        fi
        config="$REGCTL_STATE/config/${digest#sha256:}"
        if [[ -n "$platform" && -f "$REGCTL_STATE/platform-config/${digest#sha256:}/$platform" ]]; then
            config="$REGCTL_STATE/platform-config/${digest#sha256:}/$platform"
        fi
        [[ -f "$config" ]] || exit 1
        cat "$config"
        ;;
    tag:delete)
        target="${!#}"
        rm -f -- "$(reference_path "$target")"
        printf 'delete:%s\n' "$target" >> "$REGCTL_LOG"
        ;;
    *)
        printf 'unexpected regctl invocation: %s\n' "$*" >&2
        exit 2
        ;;
esac
SH
cat > "$mock_bin/docker" <<'SH'
#!/usr/bin/env bash

set -Eeuo pipefail

reference_key() {
    if command -v sha256sum >/dev/null; then
        printf '%s' "$1" | sha256sum | awk '{print $1}'
    else
        printf '%s' "$1" | shasum -a 256 | awk '{print $1}'
    fi
}

reference_path() {
    printf '%s/refs/%s\n' "$REGCTL_STATE" "$(reference_key "$1")"
}

write_reference() {
    local path

    path="$(reference_path "$1")"
    printf '%s\n' "$2" > "$path"
}

hash_digest() {
    local value="$1"

    if command -v sha256sum >/dev/null; then
        printf 'sha256:%s\n' "$(printf '%s' "$value" | sha256sum | awk '{print $1}')"
    else
        printf 'sha256:%s\n' "$(printf '%s' "$value" | shasum -a 256 | awk '{print $1}')"
    fi
}

config_path() {
    printf '%s/config/%s\n' "$REGCTL_STATE" "${1#sha256:}"
}

platform_config_path() {
    local platform_key="${2//\//_}"

    printf '%s/platform-config/%s/%s\n' "$REGCTL_STATE" "${1#sha256:}" "$platform_key"
}

digest_for_reference() {
    local reference="$1"

    if [[ "$reference" == *@sha256:* ]]; then
        printf '%s\n' "${reference##*@}"
        return 0
    fi
    cat "$(reference_path "$reference")"
}

build_fixture() {
    local tag=''
    local platform=''
    local context=''
    local label
    local key
    local value
    local digest
    local labels='{}'

    shift 2
    while (( $# > 0 )); do
        case "$1" in
            --file|--platform|--tag)
                case "$1" in
                    --platform) platform="$2" ;;
                    --tag) tag="$2" ;;
                esac
                shift 2
                ;;
            --label)
                label="$2"
                key="${label%%=*}"
                value="${label#*=}"
                labels="$(jq -cn --argjson labels "$labels" --arg key "$key" --arg value "$value" '$labels + {($key): $value}')"
                shift 2
                ;;
            --provenance=false|--push|--sbom=false)
                shift
                ;;
            *)
                context="$1"
                shift
                ;;
        esac
    done

    [[ -n "$tag" && -n "$platform" && -n "$context" ]] || exit 2
    digest="$(hash_digest "$tag")"
    write_reference "$tag" "$digest"
    write_reference "${tag%:*}@${digest}" "$digest"
    printf '%s\n' "$(jq -cn --argjson labels "$labels" '{config: {Labels: $labels}}')" > "$(config_path "$digest")"
    printf 'docker-build:%s:%s\n' "$tag" "$platform" >> "$REGCTL_LOG"
}

combine_fixture() {
    local tag=''
    local source_amd64=''
    local source_arm64=''
    local source
    local digest
    local source_digest

    shift 3
    while (( $# > 0 )); do
        case "$1" in
            --tag)
                tag="$2"
                shift 2
                ;;
            *)
                if [[ -z "$source_amd64" ]]; then
                    source_amd64="$1"
                else
                    source_arm64="$1"
                fi
                shift
                ;;
        esac
    done

    [[ -n "$tag" && -n "$source_amd64" && -n "$source_arm64" ]] || exit 2
    digest="$(hash_digest "$tag")"
    write_reference "$tag" "$digest"
    write_reference "${tag%:*}@${digest}" "$digest"
    for source in "$source_amd64" "$source_arm64"; do
        source_digest="$(digest_for_reference "$source")"
        [[ -f "$(config_path "$source_digest")" ]] || exit 2
    done
    mkdir -p "$(dirname -- "$(platform_config_path "$digest" linux/amd64)")"
    cp "$(config_path "$(digest_for_reference "$source_amd64")")" "$(platform_config_path "$digest" linux/amd64)"
    cp "$(config_path "$(digest_for_reference "$source_arm64")")" "$(platform_config_path "$digest" linux/arm64)"
    printf 'docker-index:%s\n' "$tag" >> "$REGCTL_LOG"
}

case "${1:-}:${2:-}" in
    buildx:build)
        build_fixture "$@"
        ;;
    buildx:imagetools)
        [[ "${3:-}" == create ]] || exit 2
        combine_fixture "$@"
        ;;
    pull:--platform)
        reference="${4:-}"
        digest_for_reference "$reference" >/dev/null
        printf 'docker-pull:%s\n' "$reference" >> "$REGCTL_LOG"
        ;;
    image:inspect)
        reference="${!#}"
        digest="$(digest_for_reference "$reference")"
        printf 'sha256:image-%s\n' "${digest#sha256:}"
        ;;
    *)
        printf 'unexpected docker invocation: %s\n' "$*" >&2
        exit 2
        ;;
esac
SH
chmod +x "$mock_bin/regctl" "$mock_bin/docker"

export PATH="$mock_bin:/usr/bin:/bin"
REGCTL_AMD64="$(repeat_digest a)"
REGCTL_ARM64="$(repeat_digest b)"
export REGCTL_AMD64
export REGCTL_ARM64
export REGCTL_LOG="$log"
export REGCTL_STATE="$state"
export GITHUB_ACTIONS=true
export GITHUB_EVENT_NAME=repository_dispatch
export GITHUB_REPOSITORY=williamacallahan/coolify
export GITHUB_REF_TYPE=branch
export GITHUB_REF_PROTECTED=true
export GITHUB_REF=refs/heads/v4.x
export GITHUB_REF_NAME=v4.x
export GITHUB_SHA=deadbeefdeadbeefdeadbeefdeadbeefdeadbeef
export GITHUB_RUN_ID=123
export GITHUB_RUN_ATTEMPT=1
export GITHUB_EVENT_PATH="$event_path"
cat > "$event_path" <<'JSON'
{
  "action": "release-operational-acceptance",
  "repository": {
    "full_name": "williamacallahan/coolify",
    "default_branch": "v4.x"
  }
}
JSON

issue7_candidate="$(repeat_digest c)"
issue7_predecessor="$(repeat_digest d)"
prepare_source issue7 candidate "$issue7_candidate" 123
prepare_source issue7 predecessor "$issue7_predecessor" 122

export GITHUB_REF_PROTECTED=false
if "$helper" seed issue7 staging-predecessor >/dev/null 2>&1; then
    fail 'unprotected repository_dispatch seeded a registry fixture'
fi
assert_no_mutation 'unprotected repository_dispatch reached a regctl mutation'
export GITHUB_REF_PROTECTED=true

export GITHUB_REF=refs/heads/not-v4.x
export GITHUB_REF_NAME=not-v4.x
if "$helper" seed issue7 staging-predecessor >/dev/null 2>&1; then
    fail 'non-default protected branch seeded a registry fixture'
fi
assert_no_mutation 'non-default protected branch reached a regctl mutation'
export GITHUB_REF=refs/heads/v4.x
export GITHUB_REF_NAME=v4.x

export GITHUB_RUN_ID='123/unsafe'
if "$helper" seed issue7 staging-predecessor >/dev/null 2>&1; then
    fail 'malformed run id seeded a registry fixture'
fi
assert_no_mutation 'malformed run id reached a regctl mutation'
export GITHUB_RUN_ID=123

export GITHUB_EVENT_NAME=workflow_dispatch
if "$helper" cleanup issue7 >/dev/null 2>&1; then
    fail 'non-dispatch cleanup reached a registry fixture'
fi
assert_no_mutation 'non-dispatch cleanup reached a regctl mutation'
export GITHUB_EVENT_NAME=repository_dispatch

if "$helper" seed issue10 staging-predecessor >/dev/null 2>&1; then
    fail 'unallowlisted issue scenario was accepted'
fi
assert_no_mutation 'unallowlisted issue scenario reached a regctl mutation'

targets="$($helper targets issue7)"
jq -e '
    .ghcr_target == "ghcr.io/williamagh/coolify-remediation-issue7"
    and .docker_target == "docker.io/williamagh/coolify-remediation-issue7"
    and .ghcr_candidate == "ghcr.io/williamagh/coolify-remediation-issue7-candidates"
' <<< "$targets" >/dev/null || fail 'issue7 target mapping drifted'

clear_log
seed_snapshot="$($helper seed issue7 staging-predecessor)"
jq -e --arg index "$issue7_predecessor" --arg amd64 "$REGCTL_AMD64" --arg arm64 "$REGCTL_ARM64" '
    .alias == "v4.x"
    and .ghcr.index == $index and .docker.index == $index
    and .ghcr.amd64 == $amd64 and .docker.amd64 == $amd64
    and .ghcr.arm64 == $arm64 and .docker.arm64 == $arm64
' <<< "$seed_snapshot" >/dev/null || fail 'v4.x seed did not emit index and platform evidence'
"$helper" verify-seed issue7 staging-predecessor

issue7_ghcr='ghcr.io/williamagh/coolify-remediation-issue7'
issue7_docker='docker.io/williamagh/coolify-remediation-issue7'
set_reference "${issue7_ghcr}:v4.x" "$issue7_candidate"
set_reference "${issue7_docker}:v4.x" "$issue7_candidate"
result_snapshot="$($helper verify-result issue7 staging-predecessor)"
jq -e --arg index "$issue7_candidate" '
    .ghcr.index == $index and .docker.index == $index
' <<< "$result_snapshot" >/dev/null || fail 'result evidence did not prove convergence to the candidate'
grep -Fqx "docker-pull:${issue7_ghcr}@${issue7_candidate}" "$log" || fail 'GHCR immutable passive pull was not performed'
grep -Fqx "docker-pull:${issue7_docker}:v4.x" "$log" || fail 'Docker Hub alias passive pull was not performed'

clear_log
"$helper" cleanup issue7
for reference in \
    "ghcr.io/williamagh/coolify-remediation-issue7-candidates:acceptance-issue7-123-1-candidate" \
    "ghcr.io/williamagh/coolify-remediation-issue7-candidates:acceptance-issue7-123-1-predecessor" \
    "${issue7_ghcr}:acceptance-issue7-123-1-candidate" \
    "${issue7_docker}:acceptance-issue7-123-1-candidate" \
    "${issue7_ghcr}:v4.x" \
    "${issue7_docker}:v4.x"; do
    grep -Fqx "delete:${reference}" "$log" || fail "cleanup did not delete exact fixture tag: $reference"
done

strict_unowned_index="$(repeat_digest 9)"
strict_source_reference='ghcr.io/williamagh/coolify-remediation-issue7-candidates:acceptance-issue7-123-1-candidate'
set_reference "$strict_source_reference" "$strict_unowned_index"
set_config "$strict_unowned_index" '{"config":{"Labels":{}}}'
clear_log
if "$helper" cleanup issue7 >/dev/null 2>&1; then
    fail 'cleanup accepted an unowned exact source tag'
fi
assert_no_mutation 'cleanup deleted a fixture before rejecting an unowned exact source tag'
delete_reference "$strict_source_reference"

strict_target_reference="${issue7_ghcr}:acceptance-issue7-123-1-candidate"
set_reference "$strict_target_reference" "$strict_unowned_index"
clear_log
if "$helper" cleanup issue7 >/dev/null 2>&1; then
    fail 'cleanup accepted an unowned exact target candidate tag'
fi
assert_no_mutation 'cleanup deleted a fixture before rejecting an unowned exact target candidate tag'
delete_reference "$strict_target_reference"

clear_log
export GITHUB_REF_PROTECTED=false
if "$helper" prepare-sources issue7 staging-predecessor >/dev/null 2>&1; then
    fail 'unprotected repository_dispatch prepared fixture sources'
fi
assert_no_fixture_mutation 'unprotected prepare-sources reached a registry or Docker mutation'
export GITHUB_REF_PROTECTED=true

prepared_sources="$($helper prepare-sources issue7 staging-predecessor)"
jq -e '
    .scenario == "issue7"
    and .preset == "staging-predecessor"
    and .candidate_repository == "ghcr.io/williamagh/coolify-remediation-issue7-candidates"
' <<< "$prepared_sources" >/dev/null || fail 'prepare-sources did not report the fixed issue7 candidate repository'
grep -Fqx 'docker-build:ghcr.io/williamagh/coolify-remediation-issue7-candidates:acceptance-issue7-123-1-candidate-amd64:linux/amd64' "$log" \
    || fail 'prepare-sources did not build the fixed amd64 candidate source'
grep -Fqx 'docker-index:ghcr.io/williamagh/coolify-remediation-issue7-candidates:acceptance-issue7-123-1-predecessor' "$log" \
    || fail 'prepare-sources did not create the fixed predecessor multiarch index'
prepared_candidate_index="$(get_reference 'ghcr.io/williamagh/coolify-remediation-issue7-candidates:acceptance-issue7-123-1-candidate')"
prepared_predecessor_index="$(get_reference 'ghcr.io/williamagh/coolify-remediation-issue7-candidates:acceptance-issue7-123-1-predecessor')"
for platform in linux/amd64 linux/arm64; do
    jq -e --arg fixture issue7-123-1 '
        .config.Labels["io.coolify.release-operational-fixture"] == $fixture
        and .config.Labels["io.coolify.release-operational-role"] == "candidate"
        and .config.Labels["io.coolify.release-run-id"] == "123"
    ' < "$(platform_config_path "$prepared_candidate_index" "$platform")" >/dev/null \
        || fail "scratch candidate build omitted labels from $platform config"
    jq -e --arg fixture issue7-123-1 '
        .config.Labels["io.coolify.release-operational-fixture"] == $fixture
        and .config.Labels["io.coolify.release-operational-role"] == "predecessor"
        and .config.Labels["io.coolify.release-run-id"] == "122"
    ' < "$(platform_config_path "$prepared_predecessor_index" "$platform")" >/dev/null \
        || fail "scratch predecessor build omitted labels from $platform config"
done
"$helper" seed issue7 staging-predecessor >/dev/null
"$helper" verify-seed issue7 staging-predecessor
"$helper" cleanup issue7

for preset in latest-predecessor latest-mixed latest-malformed; do
    clear_log
    "$helper" prepare-sources issue9 "$preset" >/dev/null
    "$helper" seed issue9 "$preset" >/dev/null
    "$helper" verify-seed issue9 "$preset"
    "$helper" cleanup issue9
done

prepare_issue9_sources
issue9_ghcr='ghcr.io/williamagh/coolify-remediation-issue9'
issue9_docker='docker.io/williamagh/coolify-remediation-issue9'
issue9_candidate="$(repeat_digest e)"
semantic_tag='0.0.0-operational.123'

for preset in latest-bootstrap latest-predecessor latest-mixed latest-malformed latest-split latest-absent; do
    clear_log
    "$helper" seed issue9 "$preset" >/dev/null
    "$helper" verify-seed issue9 "$preset"
    "$helper" cleanup issue9
    prepare_issue9_sources
done

set_reference "${issue9_ghcr}:${semantic_tag}" "$issue9_candidate"
set_reference "${issue9_docker}:${semantic_tag}" "$issue9_candidate"
clear_log
if "$helper" seed issue9 latest-predecessor >/dev/null 2>&1; then
    fail 'latest-predecessor seeded despite a pre-existing semantic alias'
fi
assert_no_mutation 'latest-predecessor copied a fixture after semantic-alias preflight failure'
delete_reference "${issue9_ghcr}:${semantic_tag}"
delete_reference "${issue9_docker}:${semantic_tag}"

clear_log
"$helper" seed issue9 latest-predecessor >/dev/null
set_reference "${issue9_ghcr}:${semantic_tag}" "$issue9_candidate"
set_reference "${issue9_docker}:${semantic_tag}" "$issue9_candidate"
if "$helper" verify-seed issue9 latest-predecessor >/dev/null 2>&1; then
    fail 'latest-predecessor accepted a leftover semantic alias after failure'
fi
clear_log
"$helper" cleanup issue9
grep -Fqx "delete:${issue9_ghcr}:${semantic_tag}" "$log" \
    || fail 'cleanup silently accepted the marker-owned GHCR semantic alias'
grep -Fqx "delete:${issue9_docker}:${semantic_tag}" "$log" \
    || fail 'cleanup silently accepted the marker-owned Docker Hub semantic alias'
prepare_issue9_sources

clear_log
export GITHUB_REF_PROTECTED=false
if "$helper" seed issue9 forward-repair >/dev/null 2>&1; then
    fail 'unprotected forward-repair seeded a semantic fixture tag'
fi
assert_no_fixture_mutation 'unprotected forward-repair reached a registry or Docker mutation'
export GITHUB_REF_PROTECTED=true

clear_log
"$helper" seed issue9 forward-repair >/dev/null
"$helper" verify-seed issue9 forward-repair
[[ "$(get_reference "${issue9_ghcr}:${semantic_tag}")" == "$issue9_candidate" ]] \
    || fail 'forward-repair did not seed the GHCR semantic candidate tag'
[[ "$(get_reference "${issue9_docker}:${semantic_tag}")" == "$issue9_candidate" ]] \
    || fail 'forward-repair did not seed the Docker Hub semantic candidate tag'
grep -Fqx "copy:ghcr.io/williamagh/coolify-remediation-issue9-candidates@${issue9_candidate}:${issue9_ghcr}:${semantic_tag}" "$log" \
    || fail 'forward-repair did not copy the candidate into the GHCR semantic tag'
grep -Fqx "copy:ghcr.io/williamagh/coolify-remediation-issue9-candidates@${issue9_candidate}:${issue9_docker}:${semantic_tag}" "$log" \
    || fail 'forward-repair did not copy the candidate into the Docker Hub semantic tag'

published_issue9="$(repeat_digest 6)"
set_reference "${issue9_ghcr}:latest" "$published_issue9"
set_reference "${issue9_docker}:latest" "$published_issue9"
set_reference "${issue9_ghcr}:${semantic_tag}" "$published_issue9"
set_reference "${issue9_docker}:${semantic_tag}" "$published_issue9"
set_config "$published_issue9" '{"config":{"Labels":{}}}'
clear_log
"$helper" cleanup issue9
for reference in \
    "${issue9_ghcr}:latest" \
    "${issue9_docker}:latest" \
    "${issue9_ghcr}:${semantic_tag}" \
    "${issue9_docker}:${semantic_tag}"; do
    if grep -Fqx "delete:${reference}" "$log"; then
        fail "cleanup deleted an unowned published reference: $reference"
    fi
done
grep -Fqx "delete:${issue9_ghcr}:acceptance-issue9-123-1-candidate" "$log" \
    || fail 'cleanup did not delete the marker-owned GHCR candidate tag'
grep -Fqx "delete:${issue9_docker}:acceptance-issue9-123-1-candidate" "$log" \
    || fail 'cleanup did not delete the marker-owned Docker Hub candidate tag'

published_issue7="$(repeat_digest 7)"
published_issue18="$(repeat_digest 8)"
set_reference "${issue7_ghcr}:v4.x" "$published_issue7"
set_reference "${issue7_docker}:v4.x" "$published_issue7"
issue18_ghcr='ghcr.io/williamagh/coolify-remediation-issue18'
issue18_docker='docker.io/williamagh/coolify-remediation-issue18'
set_reference "${issue18_ghcr}:v4.x" "$published_issue18"
set_reference "${issue18_docker}:v4.x" "$published_issue18"

clear_log
published_issue7_snapshot="$($helper verify-published issue7 staging-predecessor "$published_issue7")"
jq -e --arg index "$published_issue7" '
    .alias == "v4.x"
    and .expected_index == $index
    and .ghcr.index == $index
    and .docker.index == $index
    and .ghcr.amd64 != .ghcr.arm64
' <<< "$published_issue7_snapshot" >/dev/null || fail 'issue7 published inspector did not prove the expected v4.x index'
grep -Fqx "docker-pull:${issue7_ghcr}@${published_issue7}" "$log" || fail 'issue7 inspector did not pull the immutable GHCR index'
grep -Fqx "docker-pull:${issue7_ghcr}:v4.x" "$log" || fail 'issue7 inspector did not pull the GHCR v4.x alias'
grep -Fqx "docker-pull:${issue7_docker}@${published_issue7}" "$log" || fail 'issue7 inspector did not pull the immutable Docker Hub index'
grep -Fqx "docker-pull:${issue7_docker}:v4.x" "$log" || fail 'issue7 inspector did not pull the Docker Hub v4.x alias'

clear_log
published_issue9_snapshot="$($helper verify-published issue9 latest-bootstrap "$published_issue9")"
jq -e --arg index "$published_issue9" '
    .alias == "latest"
    and .expected_index == $index
    and .ghcr.index == $index
    and .docker.index == $index
    and .ghcr.amd64 != .ghcr.arm64
' <<< "$published_issue9_snapshot" >/dev/null || fail 'issue9 published inspector did not prove the expected latest index'

clear_log
published_issue18_snapshot="$($helper verify-published issue18 staging-predecessor "$published_issue18")"
jq -e --arg index "$published_issue18" '
    .alias == "v4.x"
    and .expected_index == $index
    and .ghcr.index == $index
    and .docker.index == $index
    and .ghcr.amd64 != .ghcr.arm64
' <<< "$published_issue18_snapshot" >/dev/null || fail 'issue18 published inspector did not prove the expected v4.x index'

clear_log
if "$helper" verify-published issue7 staging-predecessor sha256:not-a-digest >/dev/null 2>&1; then
    fail 'published inspector accepted a malformed expected index digest'
fi
assert_no_fixture_mutation 'malformed published index triggered a registry or Docker mutation'

canonical_issue7="$(repeat_digest 0)"
set_reference "${issue7_ghcr}:v4.x" "$canonical_issue7"
set_reference "${issue7_docker}:v4.x" "$canonical_issue7"
for platform in linux/amd64 linux/arm64; do
    set_platform_config "$canonical_issue7" "$platform" "$(operational_output_config "$GITHUB_RUN_ID" "$GITHUB_RUN_ATTEMPT" "$GITHUB_SHA")"
done
clear_log
"$helper" verify-published issue7 staging-predecessor "$canonical_issue7" >/dev/null
"$helper" cleanup issue7
grep -Fqx "delete:${issue7_ghcr}:v4.x" "$log" || fail 'cleanup did not remove the exact current GHCR v4.x output'
grep -Fqx "delete:${issue7_docker}:v4.x" "$log" || fail 'cleanup did not remove the exact current Docker Hub v4.x output'

canonical_issue9="$(repeat_digest 1)"
for repository in "$issue9_ghcr" "$issue9_docker"; do
    set_reference "${repository}:latest" "$canonical_issue9"
    set_reference "${repository}:${semantic_tag}" "$canonical_issue9"
done
for platform in linux/amd64 linux/arm64; do
    set_platform_config "$canonical_issue9" "$platform" "$(operational_output_config "$GITHUB_RUN_ID" "$GITHUB_RUN_ATTEMPT" "$GITHUB_SHA")"
done
clear_log
"$helper" verify-published issue9 latest-bootstrap "$canonical_issue9" >/dev/null
"$helper" cleanup issue9
for reference in \
    "${issue9_ghcr}:latest" \
    "${issue9_docker}:latest" \
    "${issue9_ghcr}:${semantic_tag}" \
    "${issue9_docker}:${semantic_tag}"; do
    grep -Fqx "delete:${reference}" "$log" || fail "cleanup did not remove the exact current operational output: $reference"
done

clear_log
"$helper" prepare-sources issue9 latest-bootstrap >/dev/null
"$helper" seed issue9 latest-bootstrap >/dev/null
"$helper" verify-seed issue9 latest-bootstrap
"$helper" cleanup issue9

wrong_revision='cafebabecafebabecafebabecafebabecafebabe'
for identity in wrong-run wrong-attempt wrong-revision mixed-platform; do
    case "$identity" in
        wrong-run)
            stale_index="$(repeat_digest 2)"
            amd64_config="$(operational_output_config 124 "$GITHUB_RUN_ATTEMPT" "$GITHUB_SHA")"
            arm64_config="$amd64_config"
            ;;
        wrong-attempt)
            stale_index="$(repeat_digest 3)"
            amd64_config="$(operational_output_config "$GITHUB_RUN_ID" 2 "$GITHUB_SHA")"
            arm64_config="$amd64_config"
            ;;
        wrong-revision)
            stale_index="$(repeat_digest 4)"
            amd64_config="$(operational_output_config "$GITHUB_RUN_ID" "$GITHUB_RUN_ATTEMPT" "$wrong_revision")"
            arm64_config="$amd64_config"
            ;;
        mixed-platform)
            stale_index="$(repeat_digest 5)"
            amd64_config="$(operational_output_config "$GITHUB_RUN_ID" "$GITHUB_RUN_ATTEMPT" "$GITHUB_SHA")"
            arm64_config="$(operational_output_config "$GITHUB_RUN_ID" 2 "$GITHUB_SHA")"
            ;;
    esac

    for repository in "$issue9_ghcr" "$issue9_docker"; do
        set_reference "${repository}:latest" "$stale_index"
        set_reference "${repository}:${semantic_tag}" "$stale_index"
    done
    set_platform_config "$stale_index" linux/amd64 "$amd64_config"
    set_platform_config "$stale_index" linux/arm64 "$arm64_config"
    clear_log
    "$helper" cleanup issue9
    for reference in \
        "${issue9_ghcr}:latest" \
        "${issue9_docker}:latest" \
        "${issue9_ghcr}:${semantic_tag}" \
        "${issue9_docker}:${semantic_tag}"; do
        if grep -Fqx "delete:${reference}" "$log"; then
            fail "cleanup deleted a ${identity} operational output: $reference"
        fi
    done
    assert_no_mutation "cleanup mutated a ${identity} operational output"
    for repository in "$issue9_ghcr" "$issue9_docker"; do
        delete_reference "${repository}:latest"
        delete_reference "${repository}:${semantic_tag}"
    done
done

production_ghcr='ghcr.io/coollabsio/coolify:latest'
production_docker='docker.io/coollabsio/coolify:latest'
production_index="$(repeat_digest 6)"
set_reference "$production_ghcr" "$production_index"
set_reference "$production_docker" "$production_index"
clear_log
"$helper" cleanup issue9
assert_no_mutation 'cleanup mutated a production registry target'
[[ "$(get_reference "$production_ghcr")" == "$production_index" ]] \
    || fail 'cleanup changed the production GHCR target'
[[ "$(get_reference "$production_docker")" == "$production_index" ]] \
    || fail 'cleanup changed the production Docker Hub target'

printf 'release operational fixture integration tests passed\n'
