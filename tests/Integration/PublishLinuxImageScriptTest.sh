#!/usr/bin/env bash

set -euo pipefail

repository_root="$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)"
helper="$repository_root/scripts/publish-linux-image.sh"
state="$(mktemp -d "${TMPDIR:-/tmp}/coolify-release-helper.XXXXXX")"
mock_bin="$state/bin"
log="$state/regctl.log"

fail() {
    printf 'PUBLISH_LINUX_IMAGE_HELPER_FAILURE %s\n' "$1" >&2
    exit 1
}

repeat_digest() {
    local character="$1"
    printf 'sha256:'
    printf '%*s' 64 '' | tr ' ' "$character"
}

reference_key() {
    printf '%s' "$1" | sha256sum | awk '{print $1}'
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

set_run_id() {
    printf '%s\n' "$2" > "$state/runs/${1#sha256:}"
}

assert_no_mutation() {
    if grep -Eq '^(copy|delete):' "$log"; then
        fail "$1"
    fi
}

prepare_failure_injection_alias_pair() {
    local ghcr_target="$1"
    local docker_target="$2"
    local tag="$3"

    set_reference "${ghcr_target}:${tag}" "$old_index"
    set_reference "${docker_target}:${tag}" "$old_index"
    set_reference "${ghcr_target}@${old_index}" "$old_index"
    set_reference "${docker_target}@${old_index}" "$old_index"
    set_reference "${ghcr_target}@${new_index}" "$new_index"
    set_reference "${docker_target}@${new_index}" "$new_index"
}

assert_failure_injection_refusal() {
    local description="$1"
    local ghcr_target="$2"
    local docker_target="$3"
    local candidate_index="$4"
    local candidate_run_id="$5"
    shift 5

    prepare_failure_injection_alias_pair "$ghcr_target" "$docker_target" "$failure_injection_tag"
    : > "$log"
    if env "$@" "$helper" promote-alias \
        "$ghcr_target" "$docker_target" "$failure_injection_tag" "$candidate_index" \
        "$REGCTL_AMD64" "$REGCTL_ARM64" "$candidate_run_id" >/dev/null 2>&1; then
        fail "$description unexpectedly succeeded"
    fi
    [ "$(get_reference "${ghcr_target}:${failure_injection_tag}")" = "$old_index" ] \
        || fail "$description moved GHCR before the failure injection guards completed"
    [ "$(get_reference "${docker_target}:${failure_injection_tag}")" = "$old_index" ] \
        || fail "$description moved Docker Hub before the failure injection guards completed"
    assert_no_mutation "$description attempted a registry mutation"
}

cleanup() {
    rm -rf "$state"
}

trap cleanup EXIT INT TERM

mkdir -p "$mock_bin" "$state/refs" "$state/runs"
cp "$repository_root/tests/Fixtures/mock-regctl.sh" "$mock_bin/regctl-base"
cat > "$mock_bin/regctl" <<'SH'
#!/usr/bin/env bash

set -euo pipefail

if [[ "${1:-}:${2:-}" == image:config ]]; then
    reference="${3:-}"
    if [[ "${REGCTL_CONFIG_OVERRIDE_REFERENCE:-}" == "$reference" ]] &&
        [[ -z "${REGCTL_CONFIG_OVERRIDE_PLATFORM:-}" || "$*" == *"--platform ${REGCTL_CONFIG_OVERRIDE_PLATFORM}"* ]]; then
        printf '%s\n' "${REGCTL_CONFIG_OVERRIDE_JSON:?}"
        exit 0
    fi
    case ",${REGCTL_LEGACY_CONFIG_REFERENCES:-}," in
        *",${reference},"*)
            printf '%s\n' '{"config":{"Labels":{}}}'
            exit 0
            ;;
    esac
fi

if [[ "${1:-}:${2:-}" == image:digest ]] &&
    [[ "${REGCTL_MUTATE_ON_READ_REFERENCE:-}" == "${3:-}" ]] &&
    [[ "$*" != *' --platform '* ]]; then
    count_file="$REGCTL_STATE/mutate-on-read-count"
    count=0
    if [[ -f "$count_file" ]]; then
        count="$(cat "$count_file")"
    fi
    count=$((count + 1))
    printf '%s\n' "$count" > "$count_file"
    if [[ "$count" -eq "${REGCTL_MUTATE_ON_READ_NUMBER:-1}" ]]; then
        reference_key="$(printf '%s' "${3:-}" | sha256sum | awk '{print $1}')"
        printf '%s\n' "${REGCTL_MUTATE_ON_READ_DIGEST:?}" > "$REGCTL_STATE/refs/$reference_key"
    fi
fi

exec "$(dirname -- "$0")/regctl-base" "$@"
SH
chmod +x "$mock_bin/regctl"
touch "$log"

export PATH="$mock_bin:$PATH"
REGCTL_AMD64="$(repeat_digest a)"
REGCTL_ARM64="$(repeat_digest b)"
export REGCTL_AMD64
export REGCTL_ARM64
export REGCTL_LOG="$log"
export REGCTL_STATE="$state"

source_repository='ghcr.io/coollabsio/coolify-production-staging'
ghcr_repository='ghcr.io/coollabsio/coolify'
docker_repository='docker.io/coollabsio/coolify'
old_index="$(repeat_digest c)"
new_index="$(repeat_digest d)"
split_index="$(repeat_digest e)"
run_tag='sha-deadbeefdeadbeefdeadbeefdeadbeefdeadbeef-run-42-1'

set_reference "${source_repository}@${new_index}" "$new_index"
set_reference "${source_repository}@${old_index}" "$old_index"
set_run_id "$old_index" 41
set_run_id "$new_index" 42

export REGCTL_FAIL_READ_REFERENCE="${ghcr_repository}:read-error"
if "$helper" ensure-tag "$source_repository" "$ghcr_repository" read-error "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" >/dev/null 2>&1; then
    fail 'registry read error was treated as an absent immutable tag'
fi
unset REGCTL_FAIL_READ_REFERENCE
[ ! -e "$(reference_path "${ghcr_repository}:read-error")" ] || fail 'registry read error allowed a tag mutation'

for hostile_error in 'credential helper not found' 'authentication endpoint returned HTTP 404'; do
    export REGCTL_FAIL_READ_REFERENCE="${ghcr_repository}:hostile-error"
    export REGCTL_FAIL_READ_MESSAGE="$hostile_error"
    if "$helper" ensure-tag "$source_repository" "$ghcr_repository" hostile-error "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" >/dev/null 2>&1; then
        fail "hostile registry error was misclassified as absence: $hostile_error"
    fi
    [ ! -e "$(reference_path "${ghcr_repository}:hostile-error")" ] \
        || fail 'hostile registry error allowed a tag mutation'
done
unset REGCTL_FAIL_READ_REFERENCE REGCTL_FAIL_READ_MESSAGE

"$helper" ensure-tag "$source_repository" "$ghcr_repository" 1.2.3 "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" >/dev/null
[ "$(get_reference "${ghcr_repository}:1.2.3")" = "$new_index" ] || fail 'new semantic tag was not created'
[ "$(grep -c '^copy:' "$log")" -eq 1 ] || fail 'semantic tag was not copied exactly once'

"$helper" ensure-tag "$source_repository" "$ghcr_repository" 1.2.3 "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" >/dev/null
[ "$(grep -c '^copy:' "$log")" -eq 1 ] || fail 'equal semantic tag was copied again'

set_reference "${ghcr_repository}:conflict" "$old_index"
if "$helper" ensure-tag "$source_repository" "$ghcr_repository" conflict "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" >/dev/null 2>&1; then
    fail 'conflicting semantic tag was overwritten'
fi
[ "$(get_reference "${ghcr_repository}:conflict")" = "$old_index" ] || fail 'conflicting semantic tag changed'

semantic_tag='2.0.0'
set_reference "${docker_repository}:${semantic_tag}" "$old_index"
if "$helper" ensure-pair "$source_repository" "$ghcr_repository" "$docker_repository" "$semantic_tag" "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" >/dev/null 2>&1; then
    fail 'cross-registry semantic conflict unexpectedly succeeded'
fi
[ ! -e "$(reference_path "${ghcr_repository}:${semantic_tag}")" ] || fail 'GHCR semantic tag was created before Docker Hub conflict preflight'
[ "$(get_reference "${docker_repository}:${semantic_tag}")" = "$old_index" ] || fail 'Docker Hub semantic conflict changed'
rm -f "$(reference_path "${docker_repository}:${semantic_tag}")"

export REGCTL_FAIL_TARGET="${docker_repository}:${semantic_tag}"
if "$helper" ensure-pair "$source_repository" "$ghcr_repository" "$docker_repository" "$semantic_tag" "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" >/dev/null 2>&1; then
    fail 'partial cross-registry semantic publication unexpectedly succeeded'
fi
unset REGCTL_FAIL_TARGET
[ ! -e "$(reference_path "${ghcr_repository}:${semantic_tag}")" ] || fail 'GHCR semantic tag was not compensated after Docker Hub failed'
[ ! -e "$(reference_path "${docker_repository}:${semantic_tag}")" ] || fail 'Docker Hub semantic tag remained after its failed copy'

export REGCTL_FAIL_PLATFORM_REFERENCE="${docker_repository}@${new_index}"
if "$helper" ensure-pair "$source_repository" "$ghcr_repository" "$docker_repository" "$semantic_tag" "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" >/dev/null 2>&1; then
    fail 'semantic-tag platform postflight failure unexpectedly succeeded'
fi
unset REGCTL_FAIL_PLATFORM_REFERENCE
[ ! -e "$(reference_path "${ghcr_repository}:${semantic_tag}")" ] || fail 'GHCR semantic tag was not compensated after Docker Hub postflight failure'
[ ! -e "$(reference_path "${docker_repository}:${semantic_tag}")" ] || fail 'Docker Hub semantic tag was not compensated after postflight failure'

"$helper" ensure-pair "$source_repository" "$ghcr_repository" "$docker_repository" "$semantic_tag" "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64"
[ "$(get_reference "${ghcr_repository}:${semantic_tag}")" = "$new_index" ] || fail 'GHCR semantic tag was not published'
[ "$(get_reference "${docker_repository}:${semantic_tag}")" = "$new_index" ] || fail 'Docker Hub semantic tag was not published'

: > "$log"
"$helper" cleanup-candidates "$ghcr_repository" "$ghcr_repository" "$run_tag"
[ ! -s "$log" ] || fail 'retained run tags were deleted when candidate and target matched'
if "$helper" cleanup-candidates "$source_repository" "$ghcr_repository" '' >/dev/null 2>&1; then
    fail 'candidate cleanup accepted an empty run tag'
fi
[ ! -s "$log" ] || fail 'empty run tag cleanup attempted a registry mutation'
"$helper" cleanup-candidates "$source_repository" "$ghcr_repository" "$run_tag"
grep -Fqx "delete:${source_repository}:${run_tag}-amd64" "$log" || fail 'candidate amd64 tag was not deleted'
grep -Fqx "delete:${source_repository}:${run_tag}-arm64" "$log" || fail 'candidate arm64 tag was not deleted'
grep -Fqx "delete:${source_repository}:${run_tag}" "$log" || fail 'candidate index tag was not deleted'
if grep -Fq "${ghcr_repository}:${run_tag}" "$log"; then
    fail 'cleanup targeted an immutable release run tag'
fi

set_reference "${ghcr_repository}:latest" "$old_index"
set_reference "${docker_repository}:latest" "$old_index"
set_reference "${ghcr_repository}@${old_index}" "$old_index"
set_reference "${docker_repository}@${old_index}" "$old_index"
set_reference "${ghcr_repository}@${new_index}" "$new_index"
set_reference "${docker_repository}@${new_index}" "$new_index"

: > "$log"
export REGCTL_LEGACY_CONFIG_REFERENCES="${ghcr_repository}@${old_index},${docker_repository}@${old_index}"
"$helper" preflight-latest "$ghcr_repository" "$docker_repository" "$new_index" 42
[ "$(get_reference "${ghcr_repository}:latest")" = "$old_index" ] || fail 'legacy latest preflight mutated GHCR'
[ "$(get_reference "${docker_repository}:latest")" = "$old_index" ] || fail 'legacy latest preflight mutated Docker Hub'
"$helper" promote-latest "$ghcr_repository" "$docker_repository" "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" 42
unset REGCTL_LEGACY_CONFIG_REFERENCES
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] || fail 'legacy GHCR latest was not adopted into the release-label contract'
[ "$(get_reference "${docker_repository}:latest")" = "$new_index" ] || fail 'legacy Docker Hub latest was not adopted into the release-label contract'
grep -Fq "platform:${ghcr_repository}@${old_index}:linux/amd64" "$log" \
    || fail 'legacy GHCR latest was not digest-attested before promotion'
grep -Fq "platform:${docker_repository}@${old_index}:linux/arm64" "$log" \
    || fail 'legacy Docker Hub latest was not digest-attested before promotion'

: > "$log"
legacy_superseded_error="$state/legacy-superseded.err"
if "$helper" promote-latest "$ghcr_repository" "$docker_repository" "$old_index" "$REGCTL_AMD64" "$REGCTL_ARM64" 41 2> "$legacy_superseded_error"; then
    fail 'legacy bootstrap did not establish the monotonic latest fence'
else
    legacy_superseded_status=$?
fi
[ "$legacy_superseded_status" -eq 3 ] || fail 'legacy bootstrap did not classify the older release as superseded'
grep -Fq 'classification=superseded' "$legacy_superseded_error" \
    || fail 'legacy bootstrap did not report the monotonic supersession'
if grep -q '^copy:' "$log"; then
    fail 'superseded release moved aliases after legacy bootstrap'
fi

set_reference "${ghcr_repository}:latest" "$old_index"
set_reference "${docker_repository}:latest" "$old_index"
: > "$log"
export REGCTL_LEGACY_CONFIG_REFERENCES="${ghcr_repository}@${old_index}"
if "$helper" promote-latest "$ghcr_repository" "$docker_repository" "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" 42 >/dev/null 2>&1; then
    fail 'mixed legacy and release-labeled latest aliases unexpectedly promoted'
fi
unset REGCTL_LEGACY_CONFIG_REFERENCES
[ "$(get_reference "${ghcr_repository}:latest")" = "$old_index" ] || fail 'mixed latest state changed GHCR'
[ "$(get_reference "${docker_repository}:latest")" = "$old_index" ] || fail 'mixed latest state changed Docker Hub'
if grep -q '^copy:' "$log"; then
    fail 'mixed latest state attempted an alias mutation'
fi

set_reference "${docker_repository}:latest" "$split_index"
set_reference "${docker_repository}@${split_index}" "$split_index"
: > "$log"
export REGCTL_LEGACY_CONFIG_REFERENCES="${ghcr_repository}@${old_index},${docker_repository}@${split_index}"
if "$helper" promote-latest "$ghcr_repository" "$docker_repository" "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" 42 >/dev/null 2>&1; then
    fail 'split legacy latest aliases unexpectedly promoted'
fi
unset REGCTL_LEGACY_CONFIG_REFERENCES
[ "$(get_reference "${ghcr_repository}:latest")" = "$old_index" ] || fail 'split legacy latest state changed GHCR'
[ "$(get_reference "${docker_repository}:latest")" = "$split_index" ] || fail 'split legacy latest state changed Docker Hub'
if grep -q '^copy:' "$log"; then
    fail 'split legacy latest state attempted an alias mutation'
fi
set_reference "${docker_repository}:latest" "$old_index"

: > "$log"
set_reference "${ghcr_repository}:latest" "$new_index"
set_reference "${docker_repository}:latest" "$old_index"
export REGCTL_LEGACY_CONFIG_REFERENCES="${ghcr_repository}@${old_index},${docker_repository}@${old_index}"
"$helper" repair-alias-forward "$ghcr_repository" "$docker_repository" latest "$new_index"
unset REGCTL_LEGACY_CONFIG_REFERENCES
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] \
    || fail 'forward repair changed the already-promoted GHCR latest alias'
[ "$(get_reference "${docker_repository}:latest")" = "$new_index" ] \
    || fail 'forward repair did not converge Docker Hub latest from unlabeled legacy state'
[ "$(grep -c '^copy:' "$log")" -eq 1 ] || fail 'forward repair mutated more than the lagging alias'
grep -Fqx "copy:${ghcr_repository}@${new_index}:${docker_repository}:latest" "$log" \
    || fail 'forward repair did not copy the attested GHCR candidate to Docker Hub'

: > "$log"
"$helper" repair-alias-forward "$ghcr_repository" "$docker_repository" latest "$new_index"
assert_no_mutation 'equal repair candidate state mutated aliases again'

: > "$log"
set_reference "${ghcr_repository}:latest" "$old_index"
set_reference "${docker_repository}:latest" "$old_index"
export REGCTL_LEGACY_CONFIG_REFERENCES="${ghcr_repository}@${old_index},${docker_repository}@${old_index}"
"$helper" repair-alias-forward "$ghcr_repository" "$docker_repository" latest "$new_index"
unset REGCTL_LEGACY_CONFIG_REFERENCES
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] \
    || fail 'repair command did not promote a common legacy GHCR predecessor'
[ "$(get_reference "${docker_repository}:latest")" = "$new_index" ] \
    || fail 'repair command did not promote a common legacy Docker Hub predecessor'
[ "$(grep -c '^copy:' "$log")" -eq 2 ] || fail 'common legacy predecessor did not use the normal two-alias promotion path'

set_reference "${ghcr_repository}:latest" "$new_index"
set_reference "${docker_repository}:latest" "$old_index"
export REGCTL_FAIL_PLATFORM_REFERENCE="${docker_repository}@${new_index}"
: > "$log"
if "$helper" repair-alias-forward "$ghcr_repository" "$docker_repository" latest "$new_index" >/dev/null 2>&1; then
    fail 'repair accepted incorrect candidate child digests'
fi
unset REGCTL_FAIL_PLATFORM_REFERENCE
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] || fail 'invalid candidate child digest changed GHCR latest'
[ "$(get_reference "${docker_repository}:latest")" = "$old_index" ] || fail 'invalid candidate child digest changed Docker Hub latest'
assert_no_mutation 'invalid candidate child digests attempted an alias mutation'

rm -f "$(reference_path "${docker_repository}@${new_index}")"
: > "$log"
if "$helper" repair-alias-forward "$ghcr_repository" "$docker_repository" latest "$new_index" >/dev/null 2>&1; then
    fail 'repair accepted a candidate missing from Docker Hub'
fi
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] || fail 'missing Docker Hub candidate changed GHCR latest'
[ "$(get_reference "${docker_repository}:latest")" = "$old_index" ] || fail 'missing Docker Hub candidate changed Docker Hub latest'
assert_no_mutation 'missing Docker Hub candidate attempted an alias mutation'
set_reference "${docker_repository}@${new_index}" "$new_index"

export REGCTL_LEGACY_CONFIG_REFERENCES="${ghcr_repository}@${new_index},${docker_repository}@${new_index}"
unlabeled_candidate_error="$state/unlabeled-candidate.err"
: > "$log"
if "$helper" repair-alias-forward "$ghcr_repository" "$docker_repository" latest "$new_index" 2> "$unlabeled_candidate_error"; then
    fail 'repair accepted an unlabeled immutable semantic candidate'
fi
unset REGCTL_LEGACY_CONFIG_REFERENCES
grep -Fq "unlabeled immutable semantic digest ${new_index}; refusing automatic repair" "$unlabeled_candidate_error" \
    || fail 'unlabeled immutable semantic candidate did not report the explicit repair refusal'
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] || fail 'unlabeled candidate changed GHCR latest'
[ "$(get_reference "${docker_repository}:latest")" = "$old_index" ] || fail 'unlabeled candidate changed Docker Hub latest'
assert_no_mutation 'unlabeled immutable semantic candidate attempted an alias mutation'

export REGCTL_CONFIG_OVERRIDE_REFERENCE="${docker_repository}@${new_index}"
export REGCTL_CONFIG_OVERRIDE_JSON='{"config":{"Labels":{"io.coolify.release-run-id":"43"}}}'
: > "$log"
if "$helper" repair-alias-forward "$ghcr_repository" "$docker_repository" latest "$new_index" >/dev/null 2>&1; then
    fail 'repair accepted conflicting candidate run ids across registries'
fi
unset REGCTL_CONFIG_OVERRIDE_REFERENCE REGCTL_CONFIG_OVERRIDE_JSON
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] || fail 'candidate run-id conflict changed GHCR latest'
[ "$(get_reference "${docker_repository}:latest")" = "$old_index" ] || fail 'candidate run-id conflict changed Docker Hub latest'
assert_no_mutation 'candidate run-id conflict attempted an alias mutation'

export REGCTL_CONFIG_OVERRIDE_REFERENCE="${docker_repository}@${new_index}"
export REGCTL_CONFIG_OVERRIDE_PLATFORM='linux/amd64'
export REGCTL_CONFIG_OVERRIDE_JSON='{"config":{"Labels":{"io.coolify.release-run-id":"43"}}}'
: > "$log"
if "$helper" repair-alias-forward "$ghcr_repository" "$docker_repository" latest "$new_index" >/dev/null 2>&1; then
    fail 'repair accepted a candidate run id that disagreed across platforms'
fi
unset REGCTL_CONFIG_OVERRIDE_REFERENCE REGCTL_CONFIG_OVERRIDE_PLATFORM REGCTL_CONFIG_OVERRIDE_JSON
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] || fail 'candidate platform run-id conflict changed GHCR latest'
[ "$(get_reference "${docker_repository}:latest")" = "$old_index" ] || fail 'candidate platform run-id conflict changed Docker Hub latest'
assert_no_mutation 'candidate platform run-id conflict attempted an alias mutation'

export REGCTL_CONFIG_OVERRIDE_REFERENCE="${docker_repository}@${old_index}"
export REGCTL_CONFIG_OVERRIDE_PLATFORM='linux/amd64'
export REGCTL_CONFIG_OVERRIDE_JSON='{"config":{"Labels":{}}}'
: > "$log"
if "$helper" repair-alias-forward "$ghcr_repository" "$docker_repository" latest "$new_index" >/dev/null 2>&1; then
    fail 'repair accepted mixed predecessor labels across platforms'
fi
unset REGCTL_CONFIG_OVERRIDE_REFERENCE REGCTL_CONFIG_OVERRIDE_PLATFORM REGCTL_CONFIG_OVERRIDE_JSON
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] || fail 'mixed predecessor labels changed GHCR latest'
[ "$(get_reference "${docker_repository}:latest")" = "$old_index" ] || fail 'mixed predecessor labels changed Docker Hub latest'
assert_no_mutation 'mixed predecessor labels attempted an alias mutation'

export REGCTL_CONFIG_OVERRIDE_REFERENCE="${docker_repository}@${old_index}"
export REGCTL_CONFIG_OVERRIDE_JSON='{"config":{"Labels":{"io.coolify.release-run-id":41}}}'
: > "$log"
if "$helper" repair-alias-forward "$ghcr_repository" "$docker_repository" latest "$new_index" >/dev/null 2>&1; then
    fail 'repair accepted malformed predecessor labels'
fi
unset REGCTL_CONFIG_OVERRIDE_REFERENCE REGCTL_CONFIG_OVERRIDE_JSON
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] || fail 'malformed predecessor labels changed GHCR latest'
[ "$(get_reference "${docker_repository}:latest")" = "$old_index" ] || fail 'malformed predecessor labels changed Docker Hub latest'
assert_no_mutation 'malformed predecessor labels attempted an alias mutation'

export REGCTL_CONFIG_OVERRIDE_REFERENCE="${docker_repository}@${old_index}"
export REGCTL_CONFIG_OVERRIDE_JSON='not-json'
malformed_config_error="$state/malformed-config.err"
: > "$log"
if "$helper" repair-alias-forward "$ghcr_repository" "$docker_repository" latest "$new_index" 2> "$malformed_config_error"; then
    fail 'repair accepted malformed predecessor image config'
fi
unset REGCTL_CONFIG_OVERRIDE_REFERENCE REGCTL_CONFIG_OVERRIDE_JSON
grep -Fqx "publish-linux-image: invalid image config for ${docker_repository}@${old_index} on linux/amd64" "$malformed_config_error" \
    || fail 'malformed predecessor config did not emit the semantic invalid-config diagnostic'
if grep -Fq 'parse error' "$malformed_config_error"; then
    fail 'malformed predecessor config leaked raw jq diagnostics'
fi
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] || fail 'malformed predecessor config changed GHCR latest'
[ "$(get_reference "${docker_repository}:latest")" = "$old_index" ] || fail 'malformed predecessor config changed Docker Hub latest'
assert_no_mutation 'malformed predecessor config attempted an alias mutation'

set_reference "${ghcr_repository}@${split_index}" "$split_index"
set_reference "${docker_repository}@${split_index}" "$split_index"
set_reference "${ghcr_repository}:latest" "$old_index"
set_reference "${docker_repository}:latest" "$split_index"
: > "$log"
if "$helper" repair-alias-forward "$ghcr_repository" "$docker_repository" latest "$new_index" >/dev/null 2>&1; then
    fail 'repair accepted arbitrary split legacy aliases with neither side at the candidate'
fi
[ "$(get_reference "${ghcr_repository}:latest")" = "$old_index" ] || fail 'arbitrary split legacy aliases changed GHCR latest'
[ "$(get_reference "${docker_repository}:latest")" = "$split_index" ] || fail 'arbitrary split legacy aliases changed Docker Hub latest'
assert_no_mutation 'arbitrary split legacy aliases attempted an alias mutation'

set_reference "${ghcr_repository}:latest" "$new_index"
rm -f "$(reference_path "${docker_repository}:latest")"
: > "$log"
if "$helper" repair-alias-forward "$ghcr_repository" "$docker_repository" latest "$new_index" >/dev/null 2>&1; then
    fail 'repair accepted an absent Docker Hub alias'
fi
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] || fail 'absent Docker Hub alias changed GHCR latest'
[ ! -e "$(reference_path "${docker_repository}:latest")" ] || fail 'absent Docker Hub alias was recreated'
assert_no_mutation 'absent Docker Hub alias attempted an alias mutation'
set_reference "${docker_repository}:latest" "$old_index"

set_run_id "$split_index" 43
set_reference "${ghcr_repository}:latest" "$new_index"
set_reference "${docker_repository}:latest" "$split_index"
: > "$log"
if "$helper" repair-alias-forward "$ghcr_repository" "$docker_repository" latest "$new_index" >/dev/null 2>&1; then
    fail 'repair accepted a newer predecessor'
else
    newer_predecessor_status=$?
fi
[ "$newer_predecessor_status" -eq 3 ] || fail 'newer predecessor was not classified as superseded'
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] || fail 'newer predecessor changed GHCR latest'
[ "$(get_reference "${docker_repository}:latest")" = "$split_index" ] || fail 'newer predecessor changed Docker Hub latest'
assert_no_mutation 'newer predecessor attempted an alias mutation'

set_reference "${ghcr_repository}:latest" "$new_index"
set_reference "${docker_repository}:latest" "$old_index"
rm -f "$state/mutate-on-read-count"
export REGCTL_MUTATE_ON_READ_REFERENCE="${docker_repository}:latest"
export REGCTL_MUTATE_ON_READ_NUMBER=2
export REGCTL_MUTATE_ON_READ_DIGEST="$split_index"
: > "$log"
if "$helper" repair-alias-forward "$ghcr_repository" "$docker_repository" latest "$new_index" >/dev/null 2>&1; then
    fail 'repair accepted a third-party alias change during its forward-repair snapshot'
fi
unset REGCTL_MUTATE_ON_READ_REFERENCE REGCTL_MUTATE_ON_READ_NUMBER REGCTL_MUTATE_ON_READ_DIGEST
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] || fail 'third-party repair race changed GHCR latest'
[ "$(get_reference "${docker_repository}:latest")" = "$split_index" ] || fail 'third-party repair race was overwritten'
assert_no_mutation 'third-party repair race attempted an alias mutation'
set_reference "${ghcr_repository}:latest" "$old_index"
set_reference "${docker_repository}:latest" "$old_index"

staging_tag='staging'
set_reference "${ghcr_repository}:${staging_tag}" "$old_index"
set_reference "${docker_repository}:${staging_tag}" "$old_index"
: > "$log"
export REGCTL_LEGACY_CONFIG_REFERENCES="${ghcr_repository}@${old_index},${docker_repository}@${old_index}"
"$helper" promote-alias "$ghcr_repository" "$docker_repository" "$staging_tag" "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" 42
unset REGCTL_LEGACY_CONFIG_REFERENCES
[ "$(get_reference "${ghcr_repository}:${staging_tag}")" = "$new_index" ] || fail 'generic alias promotion did not move GHCR staging'
[ "$(get_reference "${docker_repository}:${staging_tag}")" = "$new_index" ] || fail 'generic alias promotion did not move Docker Hub staging'
grep -Fq "copy:${ghcr_repository}@${new_index}:${ghcr_repository}:${staging_tag}" "$log" \
    || fail 'generic alias promotion did not publish GHCR staging'

: > "$log"
staging_superseded_error="$state/staging-superseded.err"
if "$helper" promote-alias "$ghcr_repository" "$docker_repository" "$staging_tag" "$old_index" "$REGCTL_AMD64" "$REGCTL_ARM64" 41 2> "$staging_superseded_error"; then
    fail 'generic alias promotion did not enforce the monotonic staging fence'
else
    staging_superseded_status=$?
fi
[ "$staging_superseded_status" -eq 3 ] || fail 'generic alias supersession was not classified'
grep -Fq 'classification=superseded' "$staging_superseded_error" \
    || fail 'generic alias supersession was not reported'
[ "$(get_reference "${ghcr_repository}:${staging_tag}")" = "$new_index" ] || fail 'superseded staging release regressed GHCR'
[ "$(get_reference "${docker_repository}:${staging_tag}")" = "$new_index" ] || fail 'superseded staging release regressed Docker Hub'

set_reference "${ghcr_repository}:${staging_tag}" "$old_index"
set_reference "${docker_repository}:${staging_tag}" "$old_index"
export REGCTL_FAIL_TARGET="${docker_repository}:${staging_tag}"
if "$helper" promote-alias "$ghcr_repository" "$docker_repository" "$staging_tag" "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" 42 >/dev/null 2>&1; then
    fail 'generic alias promotion did not compensate a failed Docker Hub staging update'
fi
unset REGCTL_FAIL_TARGET
[ "$(get_reference "${ghcr_repository}:${staging_tag}")" = "$old_index" ] || fail 'generic alias promotion did not compensate GHCR staging'
[ "$(get_reference "${docker_repository}:${staging_tag}")" = "$old_index" ] || fail 'generic alias promotion changed Docker Hub staging after failure'
rm -f "$state/copy-failure-triggered"

rollback_error="$state/rollback.err"
export REGCTL_FAIL_TARGET="${docker_repository}:latest"
export REGCTL_CONCURRENT_REFERENCE="${ghcr_repository}:latest"
export REGCTL_CONCURRENT_DIGEST="$REGCTL_ARM64"
if "$helper" promote-latest "$ghcr_repository" "$docker_repository" "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" 42 >/dev/null 2>"$rollback_error"; then
    fail 'third-party latest digest was treated as compensated'
fi
unset REGCTL_CONCURRENT_REFERENCE REGCTL_CONCURRENT_DIGEST REGCTL_FAIL_TARGET
[ "$(get_reference "${ghcr_repository}:latest")" = "$REGCTL_ARM64" ] || fail 'third-party latest digest was overwritten during compensation'
set_reference "${ghcr_repository}:latest" "$old_index"
rm -f "$state/copy-failure-triggered"

export REGCTL_IGNORE_COPY_TARGET="${ghcr_repository}:latest"
if "$helper" promote-latest "$ghcr_repository" "$docker_repository" "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" 42 >/dev/null 2>&1; then
    fail 'latest promotion without a moved GHCR alias unexpectedly succeeded'
fi
unset REGCTL_IGNORE_COPY_TARGET
[ "$(get_reference "${ghcr_repository}:latest")" = "$old_index" ] || fail 'GHCR latest changed after an unmoved-alias postflight failure'
[ "$(get_reference "${docker_repository}:latest")" = "$old_index" ] || fail 'Docker Hub latest changed after an unmoved GHCR alias'

export REGCTL_FAIL_TARGET="${docker_repository}:latest"
export REGCTL_FAIL_COPY_SOURCE="${ghcr_repository}@${old_index}"
if "$helper" promote-latest "$ghcr_repository" "$docker_repository" "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" 42 >/dev/null 2>"$rollback_error"; then
    fail 'partial latest promotion with failed rollback unexpectedly succeeded'
fi
unset REGCTL_FAIL_COPY_SOURCE
unset REGCTL_FAIL_TARGET
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] || fail 'failed rollback did not retain observable promoted GHCR state'
[ "$(get_reference "${docker_repository}:latest")" = "$old_index" ] || fail 'Docker Hub latest changed after failed promotion and rollback'
grep -Fq 'unable to compensate GHCR latest after Docker Hub promotion failure' "$rollback_error" \
    || fail 'failed rollback was not reported as the terminal promotion error'
set_reference "${ghcr_repository}:latest" "$old_index"

rm -f "$(reference_path "${ghcr_repository}:latest")" "$(reference_path "${docker_repository}:latest")" \
    "$state/copy-failure-triggered"
export REGCTL_FAIL_TARGET="${docker_repository}:latest"
export REGCTL_FAIL_READ_AFTER_COPY_FAILURE_REFERENCE="${ghcr_repository}:latest"
if "$helper" promote-latest "$ghcr_repository" "$docker_repository" "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" 42 >/dev/null 2>"$rollback_error"; then
    fail 'absent-previous latest rollback read failure unexpectedly succeeded'
fi
unset REGCTL_FAIL_READ_AFTER_COPY_FAILURE_REFERENCE REGCTL_FAIL_TARGET
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] || fail 'rollback read failure hid observable promoted GHCR state'
grep -Fq 'unable to compensate GHCR latest after Docker Hub promotion failure' "$rollback_error" \
    || fail 'absent-previous rollback read failure was not terminal'
set_reference "${ghcr_repository}:latest" "$old_index"
set_reference "${docker_repository}:latest" "$old_index"

export REGCTL_FAIL_TARGET="${docker_repository}:latest"
if "$helper" promote-latest "$ghcr_repository" "$docker_repository" "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" 42 >/dev/null 2>&1; then
    fail 'partial latest promotion unexpectedly succeeded'
fi
unset REGCTL_FAIL_TARGET
[ "$(get_reference "${ghcr_repository}:latest")" = "$old_index" ] || fail 'GHCR latest was not compensated after Docker Hub failed'
[ "$(get_reference "${docker_repository}:latest")" = "$old_index" ] || fail 'Docker Hub latest changed after failed promotion'

export REGCTL_FAIL_PLATFORM_REFERENCE="${docker_repository}@${new_index}"
if "$helper" promote-latest "$ghcr_repository" "$docker_repository" "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" 42 >/dev/null 2>&1; then
    fail 'invalid Docker Hub platform postflight unexpectedly succeeded'
fi
unset REGCTL_FAIL_PLATFORM_REFERENCE
[ "$(get_reference "${ghcr_repository}:latest")" = "$old_index" ] || fail 'GHCR latest was not compensated after failed Docker Hub postflight'
[ "$(get_reference "${docker_repository}:latest")" = "$old_index" ] || fail 'Docker Hub latest was not compensated after failed postflight'

: > "$log"
"$helper" promote-latest "$ghcr_repository" "$docker_repository" "$new_index" "$REGCTL_AMD64" "$REGCTL_ARM64" 42
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] || fail 'GHCR latest was not promoted'
[ "$(get_reference "${docker_repository}:latest")" = "$new_index" ] || fail 'Docker Hub latest was not promoted'
grep -Fq "platform:${ghcr_repository}@${new_index}:linux/amd64" "$log" || fail 'GHCR amd64 postflight verification did not run'
grep -Fq "platform:${docker_repository}@${new_index}:linux/arm64" "$log" || fail 'Docker Hub arm64 postflight verification did not run'

: > "$log"
superseded_error="$state/superseded.err"
if "$helper" promote-latest "$ghcr_repository" "$docker_repository" "$old_index" "$REGCTL_AMD64" "$REGCTL_ARM64" 41 2> "$superseded_error"; then
    fail 'superseded release run unexpectedly succeeded'
else
    superseded_status=$?
fi
[ "$superseded_status" -eq 3 ] || fail 'superseded release did not return its classified status'
grep -Fq 'classification=superseded' "$superseded_error" || fail 'superseded release did not report its classification'
[ "$(get_reference "${ghcr_repository}:latest")" = "$new_index" ] || fail 'GHCR latest regressed to an older run'
[ "$(get_reference "${docker_repository}:latest")" = "$new_index" ] || fail 'Docker Hub latest regressed to an older run'
if grep -q '^copy:' "$log"; then
    fail 'older release run moved latest'
fi

failure_injection_tag='staging'
failure_issue7_ghcr='ghcr.io/williamagh/coolify-remediation-issue7'
failure_issue7_docker='docker.io/williamagh/coolify-remediation-issue7'
failure_issue9_ghcr='ghcr.io/williamagh/coolify-remediation-issue9'
failure_issue9_docker='docker.io/williamagh/coolify-remediation-issue9'
failure_issue18_ghcr='ghcr.io/williamagh/coolify-remediation-issue18'
failure_issue18_docker='docker.io/williamagh/coolify-remediation-issue18'

export PUBLISH_LINUX_IMAGE_FAILURE_INJECTION='docker-alias-before-copy'
export GITHUB_REPOSITORY='williamacallahan/coolify'
export GITHUB_EVENT_NAME='repository_dispatch'
export GITHUB_REF='refs/heads/main'
export GITHUB_REF_PROTECTED='true'

assert_failure_injection_refusal \
    'unsupported release failure injection mode' \
    "$failure_issue7_ghcr" "$failure_issue7_docker" "$new_index" 42 \
    PUBLISH_LINUX_IMAGE_FAILURE_INJECTION='wrong-mode'
assert_failure_injection_refusal \
    'wrong GitHub repository context' \
    "$failure_issue7_ghcr" "$failure_issue7_docker" "$new_index" 42 \
    GITHUB_REPOSITORY='WilliamAGH/not-coolify'
assert_failure_injection_refusal \
    'wrong GitHub event context' \
    "$failure_issue7_ghcr" "$failure_issue7_docker" "$new_index" 42 \
    GITHUB_EVENT_NAME='workflow_dispatch'
assert_failure_injection_refusal \
    'wrong GitHub ref context' \
    "$failure_issue7_ghcr" "$failure_issue7_docker" "$new_index" 42 \
    GITHUB_REF='refs/heads/not-main'
assert_failure_injection_refusal \
    'unprotected GitHub ref context' \
    "$failure_issue7_ghcr" "$failure_issue7_docker" "$new_index" 42 \
    GITHUB_REF_PROTECTED='false'
assert_failure_injection_refusal \
    'nonallowlisted disposable target pair' \
    'ghcr.io/williamagh/coolify-remediation-issue99' \
    'docker.io/williamagh/coolify-remediation-issue99' "$new_index" 42
assert_failure_injection_refusal \
    'mixed disposable target pair' \
    "$failure_issue7_ghcr" "$failure_issue9_docker" "$new_index" 42
assert_failure_injection_refusal \
    'production target pair' \
    "$ghcr_repository" "$docker_repository" "$new_index" 42
assert_failure_injection_refusal \
    'non-distinct candidate and alias predecessor' \
    "$failure_issue7_ghcr" "$failure_issue7_docker" "$old_index" 41

prepare_failure_injection_alias_pair "$failure_issue7_ghcr" "$failure_issue7_docker" "$failure_injection_tag"
: > "$log"
failure_injection_error="$state/failure-injection-alias.err"
if "$helper" promote-alias \
    "$failure_issue7_ghcr" "$failure_issue7_docker" "$failure_injection_tag" "$new_index" \
    "$REGCTL_AMD64" "$REGCTL_ARM64" 42 > /dev/null 2> "$failure_injection_error"; then
    fail 'allowlisted promote-alias failure injection unexpectedly succeeded'
fi
grep -Fq 'injected failure after GHCR staging promotion before Docker Hub copy' "$failure_injection_error" \
    || fail 'allowlisted promote-alias failure injection did not report its boundary'
[ "$(get_reference "${failure_issue7_ghcr}:${failure_injection_tag}")" = "$new_index" ] \
    || fail 'allowlisted promote-alias failure injection did not leave GHCR at the candidate'
[ "$(get_reference "${failure_issue7_docker}:${failure_injection_tag}")" = "$old_index" ] \
    || fail 'allowlisted promote-alias failure injection moved Docker Hub before its boundary'
grep -Fq "copy:${failure_issue7_ghcr}@${new_index}:${failure_issue7_ghcr}:${failure_injection_tag}" "$log" \
    || fail 'allowlisted promote-alias failure injection did not move GHCR before stopping'
if grep -Fq "copy:${failure_issue7_ghcr}@${new_index}:${failure_issue7_docker}:${failure_injection_tag}" "$log"; then
    fail 'allowlisted promote-alias failure injection attempted the Docker Hub copy'
fi

prepare_failure_injection_alias_pair "$failure_issue9_ghcr" "$failure_issue9_docker" latest
: > "$log"
if "$helper" promote-latest \
    "$failure_issue9_ghcr" "$failure_issue9_docker" "$new_index" \
    "$REGCTL_AMD64" "$REGCTL_ARM64" 42 > /dev/null 2> "$failure_injection_error"; then
    fail 'allowlisted promote-latest failure injection unexpectedly succeeded'
fi
[ "$(get_reference "${failure_issue9_ghcr}:latest")" = "$new_index" ] \
    || fail 'allowlisted promote-latest failure injection did not leave GHCR at the candidate'
[ "$(get_reference "${failure_issue9_docker}:latest")" = "$old_index" ] \
    || fail 'allowlisted promote-latest failure injection moved Docker Hub before its boundary'

prepare_failure_injection_alias_pair "$failure_issue18_ghcr" "$failure_issue18_docker" "$failure_injection_tag"
: > "$log"
if "$helper" promote-alias \
    "$failure_issue18_ghcr" "$failure_issue18_docker" "$failure_injection_tag" "$new_index" \
    "$REGCTL_AMD64" "$REGCTL_ARM64" 42 > /dev/null 2> "$failure_injection_error"; then
    fail 'issue18 promote-alias failure injection unexpectedly succeeded'
fi
[ "$(get_reference "${failure_issue18_ghcr}:${failure_injection_tag}")" = "$new_index" ] \
    || fail 'issue18 failure injection did not leave GHCR at the candidate'
[ "$(get_reference "${failure_issue18_docker}:${failure_injection_tag}")" = "$old_index" ] \
    || fail 'issue18 failure injection moved Docker Hub before its boundary'
unset PUBLISH_LINUX_IMAGE_FAILURE_INJECTION GITHUB_REPOSITORY GITHUB_EVENT_NAME GITHUB_REF GITHUB_REF_PROTECTED

printf 'PUBLISH_LINUX_IMAGE_HELPER_PASS\n'
