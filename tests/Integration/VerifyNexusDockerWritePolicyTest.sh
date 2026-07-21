#!/usr/bin/env bash
set -euo pipefail

repository_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
preflight="$repository_root/scripts/ci/verify-nexus-docker-write-policy.sh"
fixture=$(mktemp -d)
fake_bin="$fixture/bin"
password='test-password'
mkdir -p "$fake_bin"
trap 'rm -rf "$fixture"' EXIT

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

cat >"$fake_bin/curl" <<'SH'
#!/usr/bin/env bash
set -euo pipefail

output_file=
request_url=
curl_user=
while (( $# > 0 )); do
    case "$1" in
        --output | -o | --user | -u | --header | -H | --connect-timeout | --max-time | --retry | --retry-delay)
            case "$1" in
                --output | -o) output_file=$2 ;;
                --user | -u) curl_user=$2 ;;
            esac
            shift 2
            ;;
        --fail | --silent | --show-error | --retry-all-errors)
            shift
            ;;
        --request | -X | --data | --data-raw | --upload-file)
            exit 64
            ;;
        *)
            request_url=$1
            shift
            ;;
    esac
done

[[ "$curl_user" == "$FAKE_EXPECTED_USER" ]] || exit 65
[[ "$request_url" == "$FAKE_EXPECTED_URL" ]] || exit 66
[[ "$FAKE_CURL_EXIT" == 0 ]] || exit "$FAKE_CURL_EXIT"
printf '%s' "$FAKE_CURL_BODY" >"$output_file"
SH
chmod +x "$fake_bin/curl"

run_case() {
    local name=$1 body=$2 curl_exit=$3 username=$4 secret=$5
    case_log="$fixture/$name.log"
    set +e
    env \
        PATH="$fake_bin:$PATH" \
        RUNNER_TEMP="$fixture" \
        NEXUS_BASE_URL=https://nexus.example \
        NEXUS_DOCKER_REPOSITORY=docker-hosted \
        NEXUS_USERNAME="$username" \
        NEXUS_PASSWORD="$secret" \
        FAKE_EXPECTED_USER="$username:$secret" \
        FAKE_EXPECTED_URL=https://nexus.example/service/rest/v1/repositories/docker/hosted/docker-hosted \
        FAKE_CURL_EXIT="$curl_exit" \
        FAKE_CURL_BODY="$body" \
        "$preflight" >"$case_log" 2>&1
    case_exit=$?
    set -e
}

assert_failure() {
    local expected=$1
    [[ "$case_exit" -ne 0 ]] || fail "expected failure containing: $expected"
    grep -Fq "$expected" "$case_log" || fail "missing failure diagnostic: $expected"
    ! grep -Fq "$password" "$case_log" || fail 'credential leaked in failure output'
}

allowed='{"name":"docker-hosted","format":"docker","type":"hosted","online":true,"storage":{"writePolicy":"ALLOW"}}'

run_case allow "$allowed" 0 test-user "$password"
[[ "$case_exit" -eq 0 ]] || fail 'ALLOW policy should pass'
grep -Fq 'preflight passed' "$case_log" || fail 'ALLOW success diagnostic missing'
! grep -Fq "$password" "$case_log" || fail 'credential leaked in success output'

run_case allow-once '{"name":"docker-hosted","format":"docker","type":"hosted","online":true,"storage":{"writePolicy":"ALLOW_ONCE"}}' 0 test-user "$password"
assert_failure 'storage.writePolicy must be ALLOW'

run_case deny '{"name":"docker-hosted","format":"docker","type":"hosted","online":true,"storage":{"writePolicy":"DENY"}}' 0 test-user "$password"
assert_failure 'storage.writePolicy must be ALLOW'

run_case offline '{"name":"docker-hosted","format":"docker","type":"hosted","online":false,"storage":{"writePolicy":"ALLOW"}}' 0 test-user "$password"
assert_failure 'repository must be online'

run_case wrong-repository '{"name":"other-hosted","format":"docker","type":"hosted","online":true,"storage":{"writePolicy":"ALLOW"}}' 0 test-user "$password"
assert_failure 'repository identity must exactly match'

run_case malformed '{' 0 test-user "$password"
assert_failure 'received malformed repository JSON'

run_case transport '' 22 test-user "$password"
assert_failure 'could not retrieve hosted repository configuration'

run_case credentials "$allowed" 0 '' ''
assert_failure 'NEXUS_USERNAME and NEXUS_PASSWORD must both be non-empty'

printf 'ok: Nexus Docker write-policy preflight\n'
