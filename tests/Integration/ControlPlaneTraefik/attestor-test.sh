#!/usr/bin/env bash

set -Eeuo pipefail

TEST_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_ROOT=$(cd "$TEST_DIR/../../.." && pwd)
SUBJECT=$REPO_ROOT/scripts/control-plane-traefik-attestor
FIXTURE=$(mktemp -d "${TMPDIR:-/tmp}/coolify-traefik-attestor.XXXXXX")
PROXY_ROOT=$FIXTURE/proxy
STATE_ROOT=$FIXTURE/state
BIN=$FIXTURE/bin
LOG=$FIXTURE/commands.log
PASS=0
FAIL=0

cleanup() {
    rm -rf -- "$FIXTURE"
}
trap cleanup EXIT

pass() {
    printf 'ok - %s\n' "$1"
    PASS=$((PASS + 1))
}

fail() {
    printf 'not ok - %s\n' "$1"
    FAIL=$((FAIL + 1))
}

mkdir -p "$PROXY_ROOT/dynamic" "$PROXY_ROOT/.control-plane-managed-traefik" "$STATE_ROOT" "$BIN"
: > "$LOG"

cat > "$BIN/curl" <<'SH'
#!/bin/sh
set -eu
headers=
while [ "$#" -gt 0 ]; do
    if [ "$1" = --dump-header ]; then headers=$2; shift 2; else shift; fi
done
printf 'curl\n' >> "$ATTESTOR_TEST_LOG"
if [ "${ATTESTOR_TEST_CURL_STATUS:-200}" = 200 ]; then
    {
        printf 'HTTP/1.1 200 OK\r\n'
        if [ "${ATTESTOR_TEST_DIRECT_BACKEND:-0}" != 1 ]; then
            printf 'X-Coolify-Control-Plane-Color: blue\r\n'
            printf 'X-Coolify-Control-Plane-Generation: revision-7\r\n'
            printf 'X-Coolify-Control-Plane-Config-Ack: ack:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\r\n'
        fi
        printf 'X-Coolify-Control-Plane-Backend-Member: blue\r\n'
        printf 'X-Coolify-Control-Plane-Backend-Revision: revision-7\r\n'
        printf 'X-Coolify-Control-Plane-Dynamic-Sha256: %s\r\n' "$ATTESTOR_TEST_DYNAMIC_SHA"
        printf '\r\n'
    } > "$headers"
fi
printf '%s' "${ATTESTOR_TEST_CURL_STATUS:-200}"
SH

cat > "$BIN/php" <<'SH'
#!/bin/sh
set -eu
printf 'php %s\n' "$*" >> "$ATTESTOR_TEST_LOG"
[ "${ATTESTOR_TEST_PHP_DELAY_SECONDS:-0}" = 0 ] || sleep "$ATTESTOR_TEST_PHP_DELAY_SECONDS"
event=$(mktemp "$ATTESTOR_TEST_STATE/.received-event.XXXXXX")
cat > "$event"
mv "$event" "$ATTESTOR_TEST_STATE/received-event.json"
attempt_file=$ATTESTOR_TEST_STATE/php-attempts
attempts=0
[ ! -f "$attempt_file" ] || attempts=$(cat "$attempt_file")
attempts=$((attempts + 1))
printf '%s\n' "$attempts" > "$attempt_file"
[ "$attempts" -gt "${ATTESTOR_TEST_PHP_FAIL_ATTEMPTS:-0}" ]
SH

cat > "$BIN/flock" <<'SH'
#!/bin/sh
exit "${ATTESTOR_TEST_FLOCK_STATUS:-0}"
SH

cat > "$BIN/timeout" <<'SH'
#!/bin/sh
set -eu
[ "$1" = -s ] && [ "$2" = TERM ]
shift 3
exec "$@"
SH

cat > "$BIN/date" <<'SH'
#!/bin/sh
set -eu
case "$*" in
    '-u +%s') printf '1784484000\n' ;;
    *'@1784484000'*) printf '2026-07-19T18:00:00Z\n' ;;
    *'@1784484060'*) printf '2026-07-19T18:01:00Z\n' ;;
    '-u +%Y-%m-%dT%H:%M:%SZ') printf '2026-07-19T18:00:00Z\n' ;;
    *) exit 2 ;;
esac
SH

cat > "$BIN/sync" <<'SH'
#!/bin/sh
set -eu
[ "$#" -eq 1 ] || exit 64
printf 'sync %s\n' "$1" >> "$ATTESTOR_TEST_LOG"
SH

chmod 0700 "$BIN/curl" "$BIN/php" "$BIN/date" "$BIN/flock" "$BIN/sync" "$BIN/timeout"

write_managed_state() {
    local document_sha
    printf 'http:\n  routers:\n    coolify-app-port: {}\n' > "$PROXY_ROOT/dynamic/coolify.yaml"
    document_sha=$(sha256sum "$PROXY_ROOT/dynamic/coolify.yaml" | awk '{print $1}')
    jq -nc --arg sha "$document_sha" \
        '{version:1,filename:"coolify.yaml",operation_id:"attestor-owner",revision:7,sha256:$sha}' \
        > "$PROXY_ROOT/.control-plane-managed-traefik/.coolify.yaml.state.json"
    jq -nc --arg sha "$document_sha" \
        '{epoch:3,operation_id:"attestor-owner",member:"blue",container_id:("a"*64),container_name:"coolify-web-blue",image_id:("sha256:"+("b"*64)),dynamic_revision:7,dynamic_sha256:$sha}' \
        > "$PROXY_ROOT/.control-plane-managed-traefik/.coolify.yaml.writer-authority.json"
    export ATTESTOR_TEST_DYNAMIC_SHA=$document_sha
}

run_check() {
    env \
        PATH="$BIN:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/sbin" \
        ATTESTOR_TEST_LOG="$LOG" \
        ATTESTOR_TEST_STATE="$STATE_ROOT" \
        ATTESTOR_TEST_DYNAMIC_SHA="$ATTESTOR_TEST_DYNAMIC_SHA" \
        ATTESTOR_TEST_CURL_STATUS="${ATTESTOR_TEST_CURL_STATUS:-200}" \
        ATTESTOR_TEST_DIRECT_BACKEND="${ATTESTOR_TEST_DIRECT_BACKEND:-0}" \
        ATTESTOR_TEST_FLOCK_STATUS="${ATTESTOR_TEST_FLOCK_STATUS:-0}" \
        ATTESTOR_TEST_PHP_DELAY_SECONDS="${ATTESTOR_TEST_PHP_DELAY_SECONDS:-0}" \
        ATTESTOR_TEST_PHP_FAIL_ATTEMPTS="${ATTESTOR_TEST_PHP_FAIL_ATTEMPTS:-0}" \
        COOLIFY_TRAEFIK_ATTESTOR_PROXY_ROOT="$PROXY_ROOT" \
        COOLIFY_TRAEFIK_ATTESTOR_STATE_ROOT="$STATE_ROOT" \
        COOLIFY_TRAEFIK_ATTESTOR_SERVER_ID=42 \
        COOLIFY_TRAEFIK_ATTESTOR_PROBE_HOST=dashboard.example.test \
        COOLIFY_TRAEFIK_ATTESTOR_PROBE_URL=http://host.docker.internal:8000/api/health \
        COOLIFY_TRAEFIK_ATTESTOR_RETRY_SECONDS=0 \
        "$SUBJECT" check
}

write_managed_state
protected_before=$(sha256sum \
    "$PROXY_ROOT/dynamic/coolify.yaml" \
    "$PROXY_ROOT/.control-plane-managed-traefik/.coolify.yaml.state.json" \
    "$PROXY_ROOT/.control-plane-managed-traefik/.coolify.yaml.writer-authority.json")

start_ns=$(date +%s%N 2>/dev/null || printf '0')
if run_check \
    && jq -e --arg sha "$ATTESTOR_TEST_DYNAMIC_SHA" \
        '.version == 1 and .server_id == 42 and .operation_id == "attestor-owner" and .dynamic_revision == 7 and .dynamic_sha256 == $sha and .writer_operation_id == "attestor-owner" and .writer_epoch == 3 and .writer_member == "blue" and .writer_dynamic_revision == 7 and .writer_dynamic_sha256 == $sha' \
        "$STATE_ROOT/heartbeat.json" >/dev/null \
    && ! grep -q '^php ' "$LOG" \
    && [[ $(grep -c '^sync ' "$LOG") -eq 2 ]]; then
    pass 'healthy cheap tick records bounded heartbeat without full attestation'
else
    fail 'healthy cheap tick records bounded heartbeat without full attestation'
fi

steady_sync_count=$(grep -c '^sync ' "$LOG")
steady_heartbeat_sha=$(sha256sum "$STATE_ROOT/heartbeat.json")
steady_start_ns=$(date +%s%N 2>/dev/null || printf '0')
if run_check \
    && [[ $(grep -c '^sync ' "$LOG") -eq $steady_sync_count ]] \
    && [[ $(sha256sum "$STATE_ROOT/heartbeat.json") == "$steady_heartbeat_sha" ]]; then
    pass 'steady-state ticks avoid heartbeat rewrites and durability barriers'
else
    fail 'steady-state ticks avoid heartbeat rewrites and durability barriers'
fi
steady_end_ns=$(date +%s%N 2>/dev/null || printf '0')
if [[ $steady_start_ns =~ ^[0-9]+$ && $steady_end_ns =~ ^[0-9]+$ && $steady_end_ns -ge $steady_start_ns ]]; then
    steady_heartbeat_ms=$(((steady_end_ns - steady_start_ns) / 1000000))
    printf 'steady_heartbeat_ms=%s budget_ms=500\n' "$steady_heartbeat_ms"
    if [[ $steady_heartbeat_ms -le 500 ]]; then
        pass 'steady-state heartbeat remains within the no-write budget'
    else
        fail 'steady-state heartbeat remains within the no-write budget'
    fi
fi
end_ns=$(date +%s%N 2>/dev/null || printf '0')
if [[ $start_ns =~ ^[0-9]+$ && $end_ns =~ ^[0-9]+$ && $end_ns -ge $start_ns ]]; then
    heartbeat_ms=$(((end_ns - start_ns) / 1000000))
    printf 'heartbeat_ms=%s budget_ms=1500\n' "$heartbeat_ms"
    if [[ $heartbeat_ms -le 1500 ]]; then
        pass 'healthy heartbeat remains within the measured steady-state budget'
    else
        fail 'healthy heartbeat remains within the measured steady-state budget'
    fi
fi

export ATTESTOR_TEST_DIRECT_BACKEND=1
drift_start_ns=$(date +%s%N 2>/dev/null || printf '0')
if ! run_check; then :; fi
for _ in {1..50}; do
    [[ -f $STATE_ROOT/received-event.json ]] \
        && [[ $(jq -r '.status // empty' "$STATE_ROOT/attestation-result.json" 2>/dev/null || true) == passed ]] \
        && break
    sleep 0.02
done
drift_end_ns=$(date +%s%N 2>/dev/null || printf '0')
if jq -e '.reason == "provider-health-drift" and .server_id == 42 and .operation_id == "attestor-owner"' \
    "$STATE_ROOT/received-event.json" >/dev/null \
    && [[ $(grep -c '^php ' "$LOG") -eq 1 ]]; then
    pass 'a direct backend response is rejected and dispatches one canonical full attestation event'
else
    fail 'a direct backend response is rejected and dispatches one canonical full attestation event'
fi
if [[ $drift_start_ns =~ ^[0-9]+$ && $drift_end_ns =~ ^[0-9]+$ && $drift_end_ns -ge $drift_start_ns ]]; then
    drift_dispatch_ms=$(((drift_end_ns - drift_start_ns) / 1000000))
    printf 'drift_attestation_dispatch_ms=%s budget_ms=2000\n' "$drift_dispatch_ms"
    if [[ $drift_dispatch_ms -le 2000 ]]; then
        pass 'drift attestation dispatch remains within the measured recovery budget'
    else
        fail 'drift attestation dispatch remains within the measured recovery budget'
    fi
fi

if ! run_check; then :; fi
sleep 0.05
if [[ $(grep -c '^php ' "$LOG") -eq 1 ]]; then
    pass 'persistent drift remains latched without an escalation storm'
else
    fail 'persistent drift remains latched without an escalation storm'
fi

export ATTESTOR_TEST_DIRECT_BACKEND=0
run_check >/dev/null

rm -f "$STATE_ROOT/php-attempts" "$STATE_ROOT/received-event.json"
export ATTESTOR_TEST_DIRECT_BACKEND=1
export ATTESTOR_TEST_PHP_DELAY_SECONDS=0.2
if ! run_check; then :; fi
export ATTESTOR_TEST_DIRECT_BACKEND=0
run_check >/dev/null
for _ in {1..50}; do
    [[ -f $STATE_ROOT/received-event.json ]] && break
    sleep 0.02
done
if jq -e '.reason == "provider-health-drift" and (.event_id | type == "string")' \
    "$STATE_ROOT/received-event.json" >/dev/null; then
    pass 'full attestation consumes its immutable event after a concurrent healthy tick'
else
    fail 'full attestation consumes its immutable event after a concurrent healthy tick'
fi
export ATTESTOR_TEST_PHP_DELAY_SECONDS=0
run_check >/dev/null

rm -f "$STATE_ROOT/php-attempts" "$STATE_ROOT/received-event.json"
export ATTESTOR_TEST_CURL_STATUS=503
export ATTESTOR_TEST_PHP_FAIL_ATTEMPTS=1
if ! run_check; then :; fi
for _ in {1..50}; do
    [[ $(jq -r '.status // empty' "$STATE_ROOT/attestation-result.json" 2>/dev/null || true) == failed ]] && break
    sleep 0.02
done
if ! run_check; then :; fi
for _ in {1..50}; do
    [[ $(jq -r '.status // empty' "$STATE_ROOT/attestation-result.json" 2>/dev/null || true) == passed ]] && break
    sleep 0.02
done
if [[ $(cat "$STATE_ROOT/php-attempts") -eq 2 ]] \
    && jq -e '.status == "passed" and .attempt == 2' "$STATE_ROOT/attestation-result.json" >/dev/null; then
    pass 'failed full attestation retries once and records the bounded successful outcome'
else
    fail 'failed full attestation retries once and records the bounded successful outcome'
fi

export ATTESTOR_TEST_CURL_STATUS=200
export ATTESTOR_TEST_PHP_FAIL_ATTEMPTS=0
run_check >/dev/null

write_managed_state
jq '.operation_id = "rollback-tombstone" | .epoch = 8' \
    "$PROXY_ROOT/.control-plane-managed-traefik/.coolify.yaml.writer-authority.json" \
    > "$STATE_ROOT/rollback-authority.json"
mv "$STATE_ROOT/rollback-authority.json" "$PROXY_ROOT/.control-plane-managed-traefik/.coolify.yaml.writer-authority.json"
if run_check && jq -e '.operation_id == "attestor-owner" and .writer_operation_id == "rollback-tombstone" and .writer_epoch == 8' \
    "$STATE_ROOT/heartbeat.json" >/dev/null; then
    pass 'rolled-back tombstone authority is accepted without losing restored document identity'
else
    fail 'rolled-back tombstone authority is accepted without losing restored document identity'
fi

write_managed_state
run_check >/dev/null
rm -f "$STATE_ROOT/received-event.json"
php_before=$(grep -c '^php ' "$LOG")
printf '{malformed\n' > "$PROXY_ROOT/.control-plane-managed-traefik/.coolify.yaml.state.json"
if ! run_check; then :; fi
for _ in {1..50}; do
    [[ -f $STATE_ROOT/received-event.json ]] \
        && [[ $(jq -r '.status // empty' "$STATE_ROOT/attestation-result.json" 2>/dev/null || true) == passed ]] \
        && break
    sleep 0.02
done
if jq -e '.reason == "managed-state-invalid"' "$STATE_ROOT/received-event.json" >/dev/null \
    && [[ $(grep -c '^php ' "$LOG") -eq $((php_before + 1)) ]]; then
    pass 'malformed managed state escalates from the last durable good identity'
else
    fail 'malformed managed state escalates from the last durable good identity'
fi

protected_after=$(sha256sum \
    "$PROXY_ROOT/dynamic/coolify.yaml" \
    "$PROXY_ROOT/.control-plane-managed-traefik/.coolify.yaml.state.json" \
    "$PROXY_ROOT/.control-plane-managed-traefik/.coolify.yaml.writer-authority.json")
if [[ $(printf '%s\n' "$protected_before" | sed -n '1p;3p') == $(printf '%s\n' "$protected_after" | sed -n '1p;3p') ]] \
    && ! grep -Eq 'docker|systemctl|nft' "$LOG"; then
    pass 'attestor has no Traefik, Docker, systemd, or nftables mutation path'
else
    fail 'attestor has no Traefik, Docker, systemd, or nftables mutation path'
fi

write_managed_state
run_check >/dev/null
heartbeat_before=$(sha256sum "$STATE_ROOT/heartbeat.json")
export ATTESTOR_TEST_FLOCK_STATUS=1
if run_check && [[ $(sha256sum "$STATE_ROOT/heartbeat.json") == "$heartbeat_before" ]]; then
    pass 'kernel lock contention skips an overlapping cheap tick'
else
    fail 'kernel lock contention skips an overlapping cheap tick'
fi
export ATTESTOR_TEST_FLOCK_STATUS=0
printf 'stale lock contents\n' > "$STATE_ROOT/check.lock"
if run_check; then
    pass 'stale lock-file contents cannot disable future checks'
else
    fail 'stale lock-file contents cannot disable future checks'
fi

printf '1..%d\n' "$((PASS + FAIL))"
[[ $FAIL -eq 0 ]]
