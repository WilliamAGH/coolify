#!/bin/sh

set -eu

script_directory=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
target="$script_directory/verify-traefik-tombstone.sh"
compose="$script_directory/compose.yaml"
work_directory=$(mktemp -d "${TMPDIR:-/tmp}/verify-traefik-tombstone.XXXXXX")
trap 'rm -rf "$work_directory"' EXIT INT TERM

grep -qF "      - \${EVIDENCE_DIRECTORY:?Set EVIDENCE_DIRECTORY}/nested-images.tar:/evidence/nested-images.tar:ro" "$compose"
grep -qF "      - \${EVIDENCE_DIRECTORY:?Set EVIDENCE_DIRECTORY}:/runtime-evidence" "$compose"
[ "$(grep -cF "\${EVIDENCE_DIRECTORY:?Set EVIDENCE_DIRECTORY}/nested-images.tar:" "$compose")" = 1 ]
[ "$(grep -cF "\${EVIDENCE_DIRECTORY:?Set EVIDENCE_DIRECTORY}:/runtime-evidence" "$compose")" = 2 ]
if grep -qF "\${EVIDENCE_DIRECTORY:?Set EVIDENCE_DIRECTORY}:/evidence" "$compose"; then
    exit 1
fi
grep -qF 'evidence=/runtime-evidence/traefik-tombstone-matrix.jsonl' "$target"
grep -qF 'probe_evidence=/runtime-evidence/traefik-tombstone-probes.jsonl' "$target"

awk '/^probe_tombstone\(\)/,/^}/' "$target" >"$work_directory/probe.sh"
[ -s "$work_directory/probe.sh" ]
# shellcheck source=/dev/null
. "$work_directory/probe.sh"

sleep()
{
    :
}

curl()
{
    curl_headers=
    while [ "$#" -gt 0 ]; do
        if [ "$1" = --dump-header ]; then
            shift
            curl_headers=$1
        fi
        shift
    done
    [ -n "$curl_headers" ]
    curl_attempt=$(($(cat "$curl_state") + 1))
    printf '%s\n' "$curl_attempt" >"$curl_state"

    case "$curl_scenario:$curl_attempt" in
        empty-then-ack:1)
            printf '000'
            return 52
            ;;
        empty-then-ack:*)
            printf 'HTTP/1.1 418 I am a teapot\r\nX-Coolify-Probe-Ack: %s\r\n\r\n' "$test_token" \
                >"$curl_headers"
            printf '418'
            ;;
        reset-then-ack:1)
            printf '000'
            return 56
            ;;
        reset-then-ack:*)
            printf 'HTTP/1.1 418 I am a teapot\r\nX-Coolify-Probe-Ack: %s\r\n\r\n' "$test_token" \
                >"$curl_headers"
            printf '418'
            ;;
        first-5xx:1)
            printf 'HTTP/1.1 503 Service Unavailable\r\n\r\n' >"$curl_headers"
            printf '503'
            ;;
        ready-then-000:1)
            printf 'HTTP/1.1 404 Not Found\r\n\r\n' >"$curl_headers"
            printf '404'
            ;;
        ready-then-000:2)
            printf '000'
            return 7
            ;;
        *)
            return 99
            ;;
    esac
}

run_scenario()
{
    curl_scenario=$1
    curl_state="$work_directory/$curl_scenario.state"
    probe_headers="$work_directory/$curl_scenario.headers"
    probe_evidence="$work_directory/$curl_scenario.jsonl"
    printf '0\n' >"$curl_state"
    : >"$probe_evidence"
}

test_token=1c0c3d6e872f367b34c48fbb6d14bf3d6023870fce5feca6547bdf54be290758

run_scenario empty-then-ack
probe_tombstone traefik:test 49152 "$probe_headers" "$test_token"
[ "$(cat "$curl_state")" = 2 ]
grep -qF '"attempt":1,"kind":"transport","curlExit":52,"status":"000","completedResponseObserved":false' "$probe_evidence"
grep -qF '"attempt":2,"kind":"http","curlExit":0,"status":418,"completedResponseObserved":true' "$probe_evidence"

run_scenario reset-then-ack
probe_tombstone traefik:test 49152 "$probe_headers" "$test_token"
[ "$(cat "$curl_state")" = 2 ]
grep -qF '"attempt":1,"kind":"transport","curlExit":56,"status":"000","completedResponseObserved":false' "$probe_evidence"
grep -qF '"attempt":2,"kind":"http","curlExit":0,"status":418,"completedResponseObserved":true' "$probe_evidence"

run_scenario first-5xx
if probe_tombstone traefik:test 49152 "$probe_headers" "$test_token"; then
    exit 1
fi
[ "$(cat "$curl_state")" = 1 ]
grep -qF '"attempt":1,"kind":"http","curlExit":0,"status":503,"completedResponseObserved":true' "$probe_evidence"

run_scenario ready-then-000
if probe_tombstone traefik:test 49152 "$probe_headers" "$test_token"; then
    exit 1
fi
[ "$(cat "$curl_state")" = 2 ]
grep -qF '"attempt":1,"kind":"http","curlExit":0,"status":404,"completedResponseObserved":true' "$probe_evidence"
grep -qF '"attempt":2,"kind":"transport","curlExit":7,"status":"000","completedResponseObserved":true' "$probe_evidence"

printf 'TRAEFIK_TOMBSTONE_PROBE_TEST_PASS\n'
