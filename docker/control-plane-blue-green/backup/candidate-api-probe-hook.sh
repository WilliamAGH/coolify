#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

fail() { printf 'control-plane-candidate-api-probe-hook: %s\n' "$1" >&2; exit 1; }
usage()
{
    printf '%s\n' \
        'Required CONTROL_PLANE_* environment: candidate image/runtime container; restored PostgreSQL and Redis clone containers; isolated candidate+live networks; restored database user/name/system ID; restored selection+proof/output; green container/backend port/direct probe path/token/ack; expected migration batch.' >&2
    exit 64
}
required() { [[ -n ${!1:-} ]] || { printf 'absent: %s\n' "$1" >&2; usage; }; }

read_secret()
{
    local secret_file=$1 secret size
    [[ $secret_file == /* && -f $secret_file && ! -L $secret_file \
        && $(stat -c '%u:%g:%a' "$secret_file") == 0:0:600 ]] \
        || fail 'candidate probe secret must be root:root mode 0600'
    secret=$(<"$secret_file")
    size=$(wc -c < "$secret_file" | tr -d '[:space:]')
    [[ $size == "${#secret}" && $secret =~ ^[A-Za-z0-9._:-]{16,128}$ ]] \
        || fail 'candidate probe secret grammar is invalid'
    printf '%s' "$secret"
}

has_single_exact_response_header()
{
    local header_file=$1 expected_name=$2 expected_value=$3 expected_name_lower
    local header_count=0 header_value='' header_folded=0 previous_target=0
    local header_line header_actual_name header_actual_name_lower tab=$'\t'

    expected_name_lower=$(printf '%s' "$expected_name" | LC_ALL=C tr '[:upper:]' '[:lower:]')
    while IFS= read -r header_line || [[ -n $header_line ]]; do
        [[ $header_line != *$'\r' ]] || header_line=${header_line%$'\r'}
        if [[ $header_line == ' '* || $header_line == $tab* ]]; then
            [[ $previous_target -eq 0 ]] || header_folded=1
            continue
        fi
        previous_target=0
        [[ $header_line == *:* ]] || continue
        header_actual_name=${header_line%%:*}
        header_actual_name_lower=$(printf '%s' "$header_actual_name" \
            | LC_ALL=C tr '[:upper:]' '[:lower:]')
        [[ $header_actual_name_lower == "$expected_name_lower" ]] || continue

        header_count=$((header_count + 1))
        previous_target=1
        header_value=${header_line#*:}
        while [[ $header_value == ' '* || $header_value == $tab* ]]; do
            header_value=${header_value:1}
        done
    done < "$header_file"

    [[ $header_count -eq 1 && $header_folded -eq 0 && $header_value == "$expected_value" ]]
}

for name in CONTROL_PLANE_CANDIDATE_IMAGE_DIGEST CONTROL_PLANE_RESTORE_DATABASE_CONTAINER \
    CONTROL_PLANE_RESTORE_DATABASE_USER CONTROL_PLANE_RESTORE_DATABASE_NAME \
    CONTROL_PLANE_RESTORE_PG_SYSTEM_IDENTIFIER CONTROL_PLANE_RESTORED_STATE_SELECTION \
    CONTROL_PLANE_RESTORED_STATE_PROOF_SHA256 CONTROL_PLANE_CANDIDATE_STATE_PROOF_FILE \
    CONTROL_PLANE_RESTORE_REDIS_CONTAINER CONTROL_PLANE_NETWORK CONTROL_PLANE_LIVE_NETWORK \
    CONTROL_PLANE_GREEN_CONTAINER CONTROL_PLANE_CANDIDATE_RUNTIME_CONTAINER \
    CONTROL_PLANE_BACKEND_PORT \
    CONTROL_PLANE_DIRECT_PROBE_PATH CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE \
    CONTROL_PLANE_GREEN_APPLIED_ACK_FILE CONTROL_PLANE_EXPECTED_MIGRATION_BATCH; do
    required "$name"
done
[[ $CONTROL_PLANE_GREEN_CONTAINER == "$CONTROL_PLANE_CANDIDATE_RUNTIME_CONTAINER" ]] \
    || fail 'probed candidate differs from the attested runtime container'
[[ $CONTROL_PLANE_NETWORK != "$CONTROL_PLANE_LIVE_NETWORK" ]] \
    || fail 'candidate network is not isolated from the live control-plane network'
[[ $CONTROL_PLANE_GREEN_CONTAINER != "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" \
    && $CONTROL_PLANE_GREEN_CONTAINER != "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER" \
    && $CONTROL_PLANE_RESTORE_DATABASE_CONTAINER != \
        "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER" ]] \
    || fail 'candidate and restored clone container identities must be distinct'
[[ $CONTROL_PLANE_BACKEND_PORT =~ ^[1-9][0-9]{0,4}$ \
    && $CONTROL_PLANE_BACKEND_PORT -le 65535 \
    && $CONTROL_PLANE_DIRECT_PROBE_PATH == /* ]] \
    || fail 'candidate backend port or direct probe path is invalid'
candidate_networks=$(docker inspect --format \
    '{{range $network, $_ := .NetworkSettings.Networks}}{{println $network}}{{end}}' \
    "$CONTROL_PLANE_GREEN_CONTAINER" | awk 'NF { print }' | LC_ALL=C sort)
[[ $candidate_networks == "$CONTROL_PLANE_NETWORK" ]] \
    || fail 'candidate is not attached exclusively to its isolated clone network'
[[ $(docker network inspect --format '{{.Internal}}' "$CONTROL_PLANE_NETWORK") == true ]] \
    || fail 'candidate clone network does not block external routing'
expected_network_members=$(printf '%s\n' "$CONTROL_PLANE_GREEN_CONTAINER" \
    "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER" \
    | LC_ALL=C sort)
observed_network_members=$(docker network inspect --format \
    '{{range .Containers}}{{println .Name}}{{end}}' "$CONTROL_PLANE_NETWORK" \
    | awk 'NF { print }' | LC_ALL=C sort)
[[ $observed_network_members == "$expected_network_members" ]] \
    || fail 'candidate clone network does not contain exactly the candidate and restored clones'
token=$(read_secret "$CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE")
expected_ack=$(read_secret "$CONTROL_PLANE_GREEN_APPLIED_ACK_FILE")

headers=$(mktemp "$(dirname "$CONTROL_PLANE_CANDIDATE_STATE_PROOF_FILE")/probe.XXXXXX")
trap 'rm -f -- "$headers"' EXIT HUP INT TERM
http_status=
for _ in $(seq 1 30); do
    if ! printf '%s\n' "$token" | docker exec -i "$CONTROL_PLANE_GREEN_CONTAINER" \
        sh -ec '
            IFS= read -r probe_token
            exec curl --silent --show-error --output /dev/null --dump-header - \
                --write-out "control-plane-http-status=%{http_code}\\n" \
                --header "X-Control-Plane-Probe: $probe_token" \
                "http://127.0.0.1:${1}${2}"
        ' sh "$CONTROL_PLANE_BACKEND_PORT" "$CONTROL_PLANE_DIRECT_PROBE_PATH" \
        > "$headers" 2>/dev/null; then
        :
    fi
    http_status=$(awk -F= '$1 == "control-plane-http-status" { print $2; exit }' \
        "$headers")
    [[ $http_status == 204 ]] && break
    sleep 1
done
[[ $http_status == 204 ]] || fail 'candidate direct API probe did not return HTTP 204'
has_single_exact_response_header "$headers" X-Control-Plane-Applied-Config "$expected_ack" \
    || fail 'candidate direct API probe acknowledgement is unexpected'

database_proof=$(docker exec "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" psql --no-psqlrc \
    --tuples-only --no-align --quiet --set ON_ERROR_STOP=1 \
    --username "$CONTROL_PLANE_RESTORE_DATABASE_USER" \
    --dbname "$CONTROL_PLANE_RESTORE_DATABASE_NAME" --command \
    "SELECT (SELECT system_identifier::text FROM pg_control_system()) || ':' ||
            COALESCE((SELECT max(batch)::text FROM migrations), '0')")
database_proof=${database_proof//[[:space:]]/}
[[ $database_proof == \
    "$CONTROL_PLANE_RESTORE_PG_SYSTEM_IDENTIFIER:$CONTROL_PLANE_EXPECTED_MIGRATION_BATCH" ]] \
    || fail 'candidate database identity or canonical migration batch proof failed'

state_proof=$(docker exec "$CONTROL_PLANE_GREEN_CONTAINER" \
    /usr/local/bin/control-plane-state-proof /restored-state \
    "$CONTROL_PLANE_RESTORED_STATE_SELECTION") \
    || fail 'candidate could not read the restored state mount'
[[ $state_proof == \
    "control_plane_state_proof_sha256=$CONTROL_PLANE_RESTORED_STATE_PROOF_SHA256" ]] \
    || fail 'candidate observed a different control-plane state proof'
docker exec "$CONTROL_PLANE_GREEN_CONTAINER" sh -ec '
    [ "$(id -u):$(id -g)" = 9999:9999 ]
    [ -z "$(find /restored-state -type s -print -quit)" ]
    for directory in ssh applications databases services backups; do
        test_path="/var/www/html/storage/app/$directory/.backup-restore-write-test"
        printf "%s\n" write-test > "$test_path"
        [ "$(cat "$test_path")" = write-test ]
        rm "$test_path"
    done
' || fail 'candidate UID 9999 cannot safely read/write restored runtime mounts'
printf '%s\n' "$state_proof" > "$CONTROL_PLANE_CANDIDATE_STATE_PROOF_FILE"
chmod 400 "$CONTROL_PLANE_CANDIDATE_STATE_PROOF_FILE"
printf '%s\n' 'control-plane-candidate-api-probe=passed'
