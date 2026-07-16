#!/usr/bin/env bash

set -Eeuo pipefail

fail()
{
    printf 'candidate-api-probe-hook-header-test: %s\n' "$1" >&2
    exit 1
}

script_directory=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)
repository_root=$(CDPATH='' cd -- "$script_directory/../../.." && pwd -P)
hook="$repository_root/docker/control-plane-blue-green/backup/candidate-api-probe-hook.sh"
test_directory=$(mktemp -d "${TMPDIR:-/tmp}/candidate-api-probe-hook-header.XXXXXX")
fake_bin="$test_directory/bin"
token_file="$test_directory/token"
ack_file="$test_directory/ack"
proof_file="$test_directory/proof"
candidate='candidate'
database='database'
redis='redis'
network='candidate-network'
acknowledgement='candidate-ack-0123456789'
proof_sha256='state-proof-sha256'

cleanup()
{
    rm -rf "$test_directory"
}
trap cleanup EXIT HUP INT TERM

mkdir -p "$fake_bin"
printf '%s' 'candidate-token-0123456789' > "$token_file"
printf '%s' "$acknowledgement" > "$ack_file"
chmod 600 "$token_file" "$ack_file"

cat > "$fake_bin/docker" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail

case ${1:-} in
    inspect)
        printf '%s\n' "$FAKE_NETWORK"
        ;;
    network)
        if [[ ${4:-} == '{{.Internal}}' ]]; then
            printf '%s\n' true
        else
            printf '%s\n' "$FAKE_CANDIDATE" "$FAKE_DATABASE" "$FAKE_REDIS" | LC_ALL=C sort
        fi
        ;;
    exec)
        shift
        if [[ ${1:-} == -i ]]; then
            shift
            [[ ${1:-} == "$FAKE_CANDIDATE" ]] || exit 64
            printf '%b' "$FAKE_RESPONSE"
        else
            target=${1:-}
            shift
            if [[ $target == "$FAKE_DATABASE" ]]; then
                printf '%s:%s\n' "$FAKE_SYSTEM_IDENTIFIER" "$FAKE_MIGRATION_BATCH"
            elif [[ ${1:-} == /usr/local/bin/control-plane-state-proof ]]; then
                printf 'control_plane_state_proof_sha256=%s\n' "$FAKE_PROOF_SHA256"
            fi
        fi
        ;;
    *)
        exit 64
        ;;
esac
EOF
cat > "$fake_bin/stat" <<'EOF'
#!/bin/sh
printf '%s\n' '0:0:600'
EOF
cat > "$fake_bin/seq" <<'EOF'
#!/bin/sh
printf '%s\n' 1
EOF
cat > "$fake_bin/sleep" <<'EOF'
#!/bin/sh
exit 0
EOF
chmod 700 "$fake_bin/docker" "$fake_bin/stat" "$fake_bin/seq" "$fake_bin/sleep"

export FAKE_CANDIDATE="$candidate"
export FAKE_DATABASE="$database"
export FAKE_REDIS="$redis"
export FAKE_NETWORK="$network"
export FAKE_SYSTEM_IDENTIFIER=restored-system-identifier
export FAKE_MIGRATION_BATCH=7
export FAKE_PROOF_SHA256="$proof_sha256"

run_hook()
{
    FAKE_RESPONSE=$1
    export FAKE_RESPONSE
    PATH="$fake_bin:$PATH" \
        CONTROL_PLANE_CANDIDATE_IMAGE_DIGEST=sha256:candidate \
        CONTROL_PLANE_RESTORE_DATABASE_CONTAINER="$database" \
        CONTROL_PLANE_RESTORE_DATABASE_USER=postgres \
        CONTROL_PLANE_RESTORE_DATABASE_NAME=coolify \
        CONTROL_PLANE_RESTORE_PG_SYSTEM_IDENTIFIER="$FAKE_SYSTEM_IDENTIFIER" \
        CONTROL_PLANE_RESTORED_STATE_SELECTION=all \
        CONTROL_PLANE_RESTORED_STATE_PROOF_SHA256="$proof_sha256" \
        CONTROL_PLANE_CANDIDATE_STATE_PROOF_FILE="$proof_file" \
        CONTROL_PLANE_RESTORE_REDIS_CONTAINER="$redis" \
        CONTROL_PLANE_NETWORK="$network" \
        CONTROL_PLANE_LIVE_NETWORK=live-network \
        CONTROL_PLANE_GREEN_CONTAINER="$candidate" \
        CONTROL_PLANE_CANDIDATE_RUNTIME_CONTAINER="$candidate" \
        CONTROL_PLANE_BACKEND_PORT=8080 \
        CONTROL_PLANE_DIRECT_PROBE_PATH=/api/control-plane/probe \
        CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE="$token_file" \
        CONTROL_PLANE_GREEN_APPLIED_ACK_FILE="$ack_file" \
        CONTROL_PLANE_EXPECTED_MIGRATION_BATCH=7 \
        "$hook"
}

response_for()
{
    printf 'HTTP/1.1 204 No Content\\r\\n%s\\r\\n\\r\\ncontrol-plane-http-status=204\\n' "$1"
}

expect_failure()
{
    scenario=$1
    response=$2
    rm -f "$proof_file"
    if run_hook "$response" >/dev/null 2>&1; then
        fail "$scenario was accepted"
    fi
    [[ ! -e $proof_file ]] || fail "$scenario wrote a success proof"
}

run_hook "$(response_for "x-control-plane-applied-config: $acknowledgement")" >/dev/null
[[ $(cat "$proof_file") == "control_plane_state_proof_sha256=$proof_sha256" ]] \
    || fail 'exact acknowledgement did not preserve the candidate proof'

expect_failure duplicate-response-header "$(response_for \
    "X-Control-Plane-Applied-Config: $acknowledgement\\r\\nX-Control-Plane-Applied-Config: $acknowledgement")"
expect_failure prefix-response-value "$(response_for \
    "X-Control-Plane-Applied-Config: prefix$acknowledgement")"
expect_failure suffix-response-value "$(response_for \
    "X-Control-Plane-Applied-Config: ${acknowledgement}suffix")"
expect_failure wrong-response-header-name "$(response_for \
    "X-Control-Plane-Applied-Config-Other: $acknowledgement")"
expect_failure missing-response-header "$(response_for 'X-Other: absent')"
expect_failure folded-response-header "$(response_for \
    "X-Control-Plane-Applied-Config: $acknowledgement\\r\\n\\tcontinued")"
expect_failure malformed-response-header "$(response_for \
    "X-Control-Plane-Applied-Config : $acknowledgement")"

printf '%s\n' "$acknowledgement" > "$ack_file"
expect_failure newline-acknowledgement-file "$(response_for \
    "X-Control-Plane-Applied-Config: $acknowledgement")"
printf '%s' "$acknowledgement" > "$ack_file"

printf '%s\n' 'candidate-api-probe-hook-header-test: passed'
