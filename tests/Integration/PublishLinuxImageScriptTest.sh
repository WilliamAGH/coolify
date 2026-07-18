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
    case ",${REGCTL_LEGACY_CONFIG_REFERENCES:-}," in
        *",${reference},"*)
            printf '%s\n' '{"config":{"Labels":{}}}'
            exit 0
            ;;
    esac
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

printf 'PUBLISH_LINUX_IMAGE_HELPER_PASS\n'
