#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

fail() { printf 'control-plane-rehearsal-hook: %s\n' "$1" >&2; exit 1; }
usage()
{
    printf '%s\n' \
        'Required CONTROL_PLANE_* environment: operation/candidate/source+restore PG identities; pinned rehearsal compose; root:root 0600 clone runtime env; isolated rehearsal+live networks; ledger/pending SHA-256; migration batch; lock+statement timeouts; candidate state-proof output path.' >&2
    exit 64
}
required()
{
    local name=$1
    [[ -n ${!name:-} ]] || { printf 'absent: %s\n' "$name" >&2; usage; }
}
runtime_env_value()
{
    local key=$1

    awk -F= -v requested_key="$key" '$1 == requested_key { print substr($0, length($1) + 2) }' \
        "$CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE"
}
container_has_network()
{
    docker inspect --format '{{range $name, $_ := .NetworkSettings.Networks}}{{println $name}}{{end}}' \
        "$1" | grep -Fqx "$2"
}

for name in CONTROL_PLANE_OPERATION_ID CONTROL_PLANE_CANDIDATE_IMAGE_DIGEST \
    CONTROL_PLANE_SOURCE_PG_SYSTEM_IDENTIFIER CONTROL_PLANE_RESTORE_PG_SYSTEM_IDENTIFIER \
    CONTROL_PLANE_RESTORE_DATABASE_CONTAINER CONTROL_PLANE_RESTORE_DATABASE_USER \
    CONTROL_PLANE_RESTORE_DATABASE_NAME CONTROL_PLANE_SOURCE_REDIS_ENDPOINT \
    CONTROL_PLANE_RESTORE_REDIS_CONTAINER CONTROL_PLANE_RESTORE_REDIS_ENDPOINT \
    CONTROL_PLANE_RESTORE_REDIS_NETWORK \
    CONTROL_PLANE_REHEARSAL_COMPOSE_FILE CONTROL_PLANE_EXPECTED_REHEARSAL_COMPOSE_SHA256 \
    CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE CONTROL_PLANE_REHEARSAL_NETWORK \
    CONTROL_PLANE_LIVE_NETWORK CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256 \
    CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256 CONTROL_PLANE_EXPECTED_MIGRATION_BATCH \
    CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT CONTROL_PLANE_MIGRATION_STATEMENT_TIMEOUT \
    CONTROL_PLANE_CANDIDATE_STATE_PROOF_FILE; do
    required "$name"
done
[[ $CONTROL_PLANE_REHEARSAL_COMPOSE_FILE == /* \
    && -f $CONTROL_PLANE_REHEARSAL_COMPOSE_FILE \
    && ! -L $CONTROL_PLANE_REHEARSAL_COMPOSE_FILE ]] \
    || fail 'canonical rehearsal compose file is unsafe or unavailable'
[[ $(sha256sum "$CONTROL_PLANE_REHEARSAL_COMPOSE_FILE" | awk '{print $1}') \
    == "$CONTROL_PLANE_EXPECTED_REHEARSAL_COMPOSE_SHA256" ]] \
    || fail 'canonical rehearsal compose file differs from its governed digest'
case ${CONTROL_PLANE_TEST_MODE:-0} in
    0) rehearsal_owner=0:0 ;;
    1) rehearsal_owner="$(id -u):$(id -g)" ;;
    *) fail 'CONTROL_PLANE_TEST_MODE must be exactly 0 or 1' ;;
esac
[[ $CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE == /* \
    && -f $CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE \
    && ! -L $CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE \
    && $(stat -c '%u:%g:%a' "$CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE") \
        == "$rehearsal_owner:600" ]] \
    || fail 'rehearsal runtime environment file has unsafe ownership or mode'
export CONTROL_PLANE_RUNTIME_ENV_FILE="$CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE"
[[ $CONTROL_PLANE_REHEARSAL_NETWORK != "$CONTROL_PLANE_LIVE_NETWORK" ]] \
    || fail 'rehearsal network must be isolated from the live network'
[[ $CONTROL_PLANE_SOURCE_PG_SYSTEM_IDENTIFIER =~ ^[1-9][0-9]*$ \
    && $CONTROL_PLANE_RESTORE_PG_SYSTEM_IDENTIFIER =~ ^[1-9][0-9]*$ \
    && $CONTROL_PLANE_SOURCE_PG_SYSTEM_IDENTIFIER != \
        "$CONTROL_PLANE_RESTORE_PG_SYSTEM_IDENTIFIER" ]] \
    || fail 'rehearsal database identity is not a distinct restored clone'
[[ $(runtime_env_value DB_HOST) == "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" \
    || $(runtime_env_value PGHOST) == "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" ]] \
    || fail 'rehearsal runtime environment does not point to the restored PostgreSQL clone'
[[ $(runtime_env_value REDIS_HOST) == "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER" \
    && $CONTROL_PLANE_RESTORE_REDIS_ENDPOINT == \
        "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER:6379" \
    && $CONTROL_PLANE_RESTORE_REDIS_ENDPOINT != "$CONTROL_PLANE_SOURCE_REDIS_ENDPOINT" ]] \
    || fail 'rehearsal runtime environment does not point to the restored Redis clone'
[[ $(docker network inspect --format '{{.Internal}}' "$CONTROL_PLANE_REHEARSAL_NETWORK") == true ]] \
    || fail 'rehearsal network must be an internal Docker network'
! container_has_network "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" "$CONTROL_PLANE_LIVE_NETWORK" \
    || fail 'restored PostgreSQL clone is attached to the live network'
! container_has_network "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER" "$CONTROL_PLANE_LIVE_NETWORK" \
    || fail 'restored Redis clone is attached to the live network'
if ! container_has_network "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" \
    "$CONTROL_PLANE_REHEARSAL_NETWORK"; then
    docker network connect "$CONTROL_PLANE_REHEARSAL_NETWORK" \
        "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" >/dev/null
fi
if ! container_has_network "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER" \
    "$CONTROL_PLANE_REHEARSAL_NETWORK"; then
    docker network connect "$CONTROL_PLANE_REHEARSAL_NETWORK" \
        "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER" >/dev/null
fi
for digest in "$CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256" \
    "$CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256"; do
    [[ $digest =~ ^[0-9a-f]{64}$ ]] || fail 'migration inventory digest is malformed'
done
[[ $CONTROL_PLANE_EXPECTED_MIGRATION_BATCH =~ ^[1-9][0-9]*$ ]] \
    || fail 'expected migration batch is malformed'

proof_directory=$(dirname "$CONTROL_PLANE_CANDIDATE_STATE_PROOF_FILE")
runner_key=$(printf '%s' "$CONTROL_PLANE_OPERATION_ID" \
    | sha256sum | awk '{print substr($1, 1, 20)}')
rehearsal_project="backup-rehearsal-$runner_key"
state_file="$proof_directory/operator-rehearsal-$runner_key.state"
state_candidate="${state_file}.new"
log_file="$proof_directory/operator-rehearsal-$runner_key.log"
log_candidate="${log_file}.new"
plan_directory="$proof_directory/operator-rehearsal-$runner_key.plan"
plan_candidate="${plan_directory}.new"
ledger_before_file="$plan_directory/ledger-before.csv"
pending_file="$plan_directory/pending"
inspect_error="$proof_directory/.operator-rehearsal-inspect-$runner_key"
lock_file="$proof_directory/.operator-rehearsal-$runner_key.lock"
backend_application_name=coolify-control-plane-expand

test_crash()
{
    local crash_point=$1

    if [[ ${CONTROL_PLANE_TEST_MODE:-0} == 1 \
        && ${CONTROL_PLANE_TEST_CRASH_AT:-} == "$crash_point" ]]; then
        kill -KILL "$$"
    fi
}

sha256_file()
{
    sha256sum "$1" | awk '{print $1}'
}

database_psql()
{
    docker exec \
        --env "PGOPTIONS=-c lock_timeout=$CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT -c statement_timeout=$CONTROL_PLANE_MIGRATION_STATEMENT_TIMEOUT" \
        "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" psql --no-psqlrc \
        --tuples-only --no-align --quiet --set ON_ERROR_STOP=1 \
        --username "$CONTROL_PLANE_RESTORE_DATABASE_USER" \
        --dbname "$CONTROL_PLANE_RESTORE_DATABASE_NAME" "$@"
}

ledger_snapshot()
{
    database_psql --command \
        "COPY (SELECT migration, batch FROM migrations ORDER BY migration) TO STDOUT WITH (FORMAT csv)" \
        > "$1"
}

container_presence()
{
    local container_name=$1 presence_status

    presence=unavailable
    [[ ! -e $inspect_error && ! -L $inspect_error ]] \
        || fail 'restore rehearsal Docker Engine response path already exists'
    if ! (umask 077; set -C; : > "$inspect_error") 2>/dev/null; then
        fail 'could not create the restore rehearsal Docker Engine response file'
    fi
    if ! presence_status=$(curl --silent --show-error \
        --connect-timeout 5 --max-time 10 \
        --unix-socket /var/run/docker.sock \
        --output "$inspect_error" --write-out '%{http_code}' \
        "http://localhost/containers/${container_name}/json"); then
        rm -f -- "$inspect_error"
        fail 'Docker API is unavailable while checking the restore rehearsal runner'
    fi

    case "$presence_status" in
        200)
            jq --exit-status --arg container "$container_name" '
                type == "object"
                and (.Id | type == "string" and test("^[0-9a-f]{64}$"))
                and .Name == ("/" + $container)
            ' "$inspect_error" >/dev/null \
                || fail 'Docker API returned malformed restore rehearsal runner identity'
            presence=present
            ;;
        404)
            jq --exit-status --arg message "No such container: $container_name" '
                type == "object" and keys == ["message"] and .message == $message
            ' "$inspect_error" >/dev/null \
                || fail 'Docker API returned an unvalidated restore rehearsal 404'
            presence=absent
            ;;
        *)
            fail "Docker API returned unavailable status while checking the restore rehearsal runner: $presence_status"
            ;;
    esac
    rm -f -- "$inspect_error"
}

state_value()
{
    local key=$1 document=${2:-$state_file}

    awk -F= -v expected_key="$key" '
        $1 == expected_key { matches++; result = substr($0, index($0, "=") + 1) }
        END { if (matches != 1) exit 1; print result }
    ' "$document" || fail "restore rehearsal state key is absent or duplicated: $key"
}

load_state()
{
    local document=${1:-$state_file}

    [[ -f $document && ! -L $document \
        && $(stat -c '%u:%g:%a' "$document") == "$rehearsal_owner:600" ]] \
        || fail 'restore rehearsal state ownership or mode is invalid'
    awk -F= '
        BEGIN {
            expected[1]="version"; expected[2]="operation_id"; expected[3]="status";
            expected[4]="mode"; expected[5]="generation"; expected[6]="runner_name";
            expected[7]="runner_id"; expected[8]="runner_exit_code";
            expected[9]="runner_log_sha256"; expected[10]="image_id";
            expected[11]="database_container"; expected[12]="database_container_id";
            expected[13]="database_image_id"; expected[14]="database_system_identifier";
            expected[15]="database_name"; expected[16]="database_identity_sha256";
            expected[17]="rehearsal_network_id"; expected[18]="hook_sha256";
            expected[19]="compose_sha256"; expected[20]="runtime_env_sha256";
            expected[21]="ledger_before_sha256"; expected[22]="pending_sha256";
            expected[23]="expected_batch"; expected[24]="authorization_ledger_sha256";
            expected[25]="authorization_pending_sha256";
            expected[26]="authorization_batch"; expected[27]="backend_application_name";
            expected[28]="database_outcome";
        }
        NF != 2 || $1 != expected[NR] || seen[$1]++ { exit 1 }
        END { exit(NR == 28 ? 0 : 1) }
    ' "$document" || fail 'restore rehearsal state key inventory is noncanonical'
    [[ $(state_value version "$document") == 2 \
        && $(state_value operation_id "$document") == "$CONTROL_PLANE_OPERATION_ID" ]] \
        || fail 'restore rehearsal state version or operation identity changed'
    status=$(state_value status "$document")
    mode=$(state_value mode "$document")
    generation=$(state_value generation "$document")
    runner_name=$(state_value runner_name "$document")
    runner_id=$(state_value runner_id "$document")
    runner_exit_code=$(state_value runner_exit_code "$document")
    runner_log_sha256=$(state_value runner_log_sha256 "$document")
    state_image_id=$(state_value image_id "$document")
    state_database_container=$(state_value database_container "$document")
    state_database_container_id=$(state_value database_container_id "$document")
    state_database_image_id=$(state_value database_image_id "$document")
    state_database_system_identifier=$(state_value database_system_identifier "$document")
    state_database_name=$(state_value database_name "$document")
    state_database_identity_sha256=$(state_value database_identity_sha256 "$document")
    state_network_id=$(state_value rehearsal_network_id "$document")
    state_hook_sha256=$(state_value hook_sha256 "$document")
    state_compose_sha256=$(state_value compose_sha256 "$document")
    state_runtime_env_sha256=$(state_value runtime_env_sha256 "$document")
    state_ledger_before_sha256=$(state_value ledger_before_sha256 "$document")
    state_pending_sha256=$(state_value pending_sha256 "$document")
    state_expected_batch=$(state_value expected_batch "$document")
    authorization_ledger_sha256=$(state_value authorization_ledger_sha256 "$document")
    authorization_pending_sha256=$(state_value authorization_pending_sha256 "$document")
    authorization_batch=$(state_value authorization_batch "$document")
    state_backend_application_name=$(state_value backend_application_name "$document")
    database_outcome=$(state_value database_outcome "$document")
    [[ $status =~ ^(planned|launched|active|passed|failed|blocked)$ \
        && $mode =~ ^(apply|verify)$ \
        && $generation =~ ^[1-9][0-9]*$ \
        && $runner_name == "coolify-cp-restore-rehearse-$runner_key-g$generation" \
        && ($runner_id == none || $runner_id =~ ^[0-9a-f]{64}$) \
        && ($runner_exit_code == none || $runner_exit_code =~ ^[0-9]+$) \
        && ($runner_log_sha256 == none || $runner_log_sha256 =~ ^[0-9a-f]{64}$) \
        && $state_image_id =~ ^sha256:[0-9a-f]{64}$ \
        && $state_database_container_id =~ ^[0-9a-f]{64}$ \
        && $state_database_image_id =~ ^sha256:[0-9a-f]{64}$ \
        && $state_database_system_identifier =~ ^[1-9][0-9]*$ \
        && $state_database_identity_sha256 =~ ^[0-9a-f]{64}$ \
        && $state_network_id =~ ^[0-9a-f]{64}$ \
        && $state_hook_sha256 =~ ^[0-9a-f]{64}$ \
        && $state_compose_sha256 =~ ^[0-9a-f]{64}$ \
        && $state_runtime_env_sha256 =~ ^[0-9a-f]{64}$ \
        && $state_ledger_before_sha256 =~ ^[0-9a-f]{64}$ \
        && $state_pending_sha256 =~ ^[0-9a-f]{64}$ \
        && $state_expected_batch =~ ^[1-9][0-9]*$ \
        && $authorization_ledger_sha256 =~ ^[0-9a-f]{64}$ \
        && $authorization_pending_sha256 =~ ^[0-9a-f]{64}$ \
        && $authorization_batch =~ ^[1-9][0-9]*$ \
        && $state_backend_application_name == coolify-control-plane-expand \
        && $database_outcome =~ ^(unknown|unchanged|applied|drift|unverified)$ ]] \
        || fail 'restore rehearsal state contains a malformed typed value'
    case "$status" in
        planned)
            [[ $runner_id:$runner_exit_code:$runner_log_sha256:$database_outcome \
                == none:none:none:unknown ]] \
                || fail 'planned restore rehearsal contains launched or terminal evidence'
            ;;
        launched|active)
            [[ $runner_id != none \
                && $runner_exit_code:$runner_log_sha256:$database_outcome == none:none:unknown ]] \
                || fail 'nonterminal restore rehearsal evidence is inconsistent'
            ;;
        passed)
            [[ $runner_log_sha256 != none && $database_outcome == applied ]] \
                || fail 'passed restore rehearsal lacks applied evidence'
            ;;
        failed)
            [[ $runner_log_sha256 != none && $database_outcome == unchanged ]] \
                || fail 'failed restore rehearsal lacks unchanged evidence'
            ;;
        blocked)
            [[ $runner_log_sha256 != none \
                && $database_outcome =~ ^(drift|unverified)$ ]] \
                || fail 'blocked restore rehearsal lacks unsafe clone evidence'
            ;;
    esac
}

write_state()
{
    local next_status=$1

    rm -f -- "$state_candidate"
    (umask 077; set -C; : > "$state_candidate") 2>/dev/null \
        || fail 'could not create restore rehearsal state candidate'
    {
        printf '%s\n' 'version=2'
        printf 'operation_id=%s\n' "$CONTROL_PLANE_OPERATION_ID"
        printf 'status=%s\n' "$next_status"
        printf 'mode=%s\n' "$mode"
        printf 'generation=%s\n' "$generation"
        printf 'runner_name=%s\n' "$runner_name"
        printf 'runner_id=%s\n' "$runner_id"
        printf 'runner_exit_code=%s\n' "$runner_exit_code"
        printf 'runner_log_sha256=%s\n' "$runner_log_sha256"
        printf 'image_id=%s\n' "$expected_image_id"
        printf 'database_container=%s\n' "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER"
        printf 'database_container_id=%s\n' "$database_container_id"
        printf 'database_image_id=%s\n' "$database_image_id"
        printf 'database_system_identifier=%s\n' "$database_system_identifier"
        printf 'database_name=%s\n' "$database_name"
        printf 'database_identity_sha256=%s\n' "$database_identity_sha256"
        printf 'rehearsal_network_id=%s\n' "$network_id"
        printf 'hook_sha256=%s\n' "$hook_sha256"
        printf 'compose_sha256=%s\n' "$compose_sha256"
        printf 'runtime_env_sha256=%s\n' "$runtime_env_sha256"
        printf 'ledger_before_sha256=%s\n' "$ledger_before_sha256"
        printf 'pending_sha256=%s\n' "$pending_sha256"
        printf 'expected_batch=%s\n' "$CONTROL_PLANE_EXPECTED_MIGRATION_BATCH"
        printf 'authorization_ledger_sha256=%s\n' "$authorization_ledger_sha256"
        printf 'authorization_pending_sha256=%s\n' "$authorization_pending_sha256"
        printf 'authorization_batch=%s\n' "$authorization_batch"
        printf 'backend_application_name=%s\n' "$backend_application_name"
        printf 'database_outcome=%s\n' "$database_outcome"
    } > "$state_candidate"
    chmod 600 "$state_candidate"
    load_state "$state_candidate"
    sync -f "$state_candidate"
    mv -f "$state_candidate" "$state_file"
    sync -f "$proof_directory"
    status=$next_status
    [[ $next_status =~ ^(passed|failed|blocked)$ ]] \
        && test_crash after-restore-rehearsal-terminal-state
    return 0
}

assert_plan_identity()
{
    load_state
    [[ $state_image_id == "$expected_image_id" \
        && $state_database_container == "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" \
        && $state_database_container_id == "$database_container_id" \
        && $state_database_image_id == "$database_image_id" \
        && $state_database_system_identifier == "$database_system_identifier" \
        && $state_database_name == "$database_name" \
        && $state_database_identity_sha256 == "$database_identity_sha256" \
        && $state_network_id == "$network_id" \
        && $state_hook_sha256 == "$hook_sha256" \
        && $state_compose_sha256 == "$compose_sha256" \
        && $state_runtime_env_sha256 == "$runtime_env_sha256" \
        && $state_ledger_before_sha256 == "$ledger_before_sha256" \
        && $state_pending_sha256 == "$pending_sha256" \
        && $state_expected_batch == "$CONTROL_PLANE_EXPECTED_MIGRATION_BATCH" ]] \
        || fail 'restore rehearsal immutable plan changed during recovery'
}

observe_activity()
{
    local activity

    activity=$(database_psql --field-separator=, --command "
WITH lock_key AS (SELECT hashtextextended('coolify-control-plane-expand', 0) AS value),
backend AS (
    SELECT pid FROM pg_stat_activity
    WHERE pid <> pg_backend_pid() AND application_name = 'coolify-control-plane-expand'
), held_lock AS (
    SELECT lock.pid FROM pg_locks AS lock CROSS JOIN lock_key
    WHERE lock.locktype = 'advisory' AND lock.granted AND lock.objsubid = 1
      AND lock.classid = (((lock_key.value >> 32) & 4294967295)::oid)
      AND lock.objid = ((lock_key.value & 4294967295)::oid)
)
SELECT (SELECT count(*) FROM backend), (SELECT count(*) FROM held_lock),
       (SELECT count(*) FROM held_lock JOIN backend USING (pid))" | tr -d '[:space:]')
    [[ $activity =~ ^[0-9]+,[0-9]+,[0-9]+$ ]] \
        || fail 'restore rehearsal PostgreSQL lock truth is malformed'
    IFS=, read -r backend_count advisory_lock_count backend_lock_count <<< "$activity"
}

wait_for_database_idle()
{
    local attempt=0

    while (( attempt < 30 )); do
        observe_activity
        (( backend_count == 0 && advisory_lock_count == 0 )) && return
        ((attempt += 1))
        sleep 1
    done
    fail 'restore rehearsal PostgreSQL backend or advisory lock did not become idle'
}

classify_ledger()
{
    local current_ledger="$proof_directory/.operator-rehearsal-$runner_key.current-ledger"

    rm -f -- "$current_ledger"
    ledger_snapshot "$current_ledger"
    current_ledger_sha256=$(sha256_file "$current_ledger")
    if [[ $current_ledger_sha256 == "$ledger_before_sha256" ]]; then
        ledger_outcome=unchanged
    elif awk -F, -v pre_file="$ledger_before_file" -v pending_file="$pending_file" \
        -v current_file="$current_ledger" \
        -v expected_batch="$CONTROL_PLANE_EXPECTED_MIGRATION_BATCH" '
        FILENAME == pre_file {
            if (NF != 2 || $1 == "" || $2 !~ /^[1-9][0-9]*$/ || pre[$1]++) exit 1
            pre_batch[$1]=$2; next
        }
        FILENAME == pending_file {
            if (NF != 1 || $1 == "" || pending[$1]++) exit 1
            next
        }
        FILENAME == current_file {
            if (NF != 2 || $1 == "" || $2 !~ /^[1-9][0-9]*$/ || current[$1]++) exit 1
            if ($1 in pre_batch) {
                if ($2 != pre_batch[$1]) exit 1
                preserved[$1]=1; next
            }
            if (($1 in pending) && $2 == expected_batch) {
                applied[$1]=1; next
            }
            exit 1
        }
        END {
            for (migration in pre_batch) if (!(migration in preserved)) exit 1
            for (migration in pending) if (!(migration in applied)) exit 1
        }
    ' "$ledger_before_file" "$pending_file" "$current_ledger"; then
        ledger_outcome=applied
    else
        ledger_outcome=drift
    fi
    rm -f -- "$current_ledger"
}

prepare_plan()
{
    local inventory_file

    if [[ -d $plan_candidate && ! -L $plan_candidate ]]; then
        rm -f -- "$plan_candidate/ledger-before.csv" "$plan_candidate/pending" \
            "$plan_candidate/inventory"
        rmdir "$plan_candidate" \
            || fail 'stale restore rehearsal plan candidate contains unexpected entries'
    fi
    [[ ! -e $plan_directory && ! -L $plan_directory \
        && ! -e $plan_candidate && ! -L $plan_candidate ]] \
        || fail 'restore rehearsal plan path is unsafe before initial planning'
    (umask 077; mkdir "$plan_candidate")
    ledger_snapshot "$plan_candidate/ledger-before.csv"
    inventory_file="$plan_candidate/inventory"
    docker run --rm --network none --pull never --entrypoint /bin/sh \
        "$CONTROL_PLANE_CANDIDATE_IMAGE_DIGEST" -ec \
        "find /var/www/html/database/migrations -maxdepth 1 -type f -name '*.php' -print | sed 's!.*/!!; s/\\.php$//' | LC_ALL=C sort" \
        > "$inventory_file"
    awk -F, -v inventory="$inventory_file" \
        -v ledger="$plan_candidate/ledger-before.csv" '
        FILENAME == inventory {
            if (NF != 1 || $1 == "" || candidate[$1]++) exit 1
            order[++candidate_count]=$1; next
        }
        FILENAME == ledger {
            if (NF != 2 || $1 == "" || $2 !~ /^[1-9][0-9]*$/ || seen[$1]++) exit 1
            applied[$1]=1; next
        }
        END {
            for (candidate_index=1; candidate_index<=candidate_count; candidate_index++)
                if (!(order[candidate_index] in applied)) print order[candidate_index]
        }
    ' "$inventory_file" "$plan_candidate/ledger-before.csv" > "$plan_candidate/pending"
    rm -f -- "$inventory_file"
    ledger_before_sha256=$(sha256_file "$plan_candidate/ledger-before.csv")
    pending_sha256=$(sha256_file "$plan_candidate/pending")
    [[ $ledger_before_sha256 == "$CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256" \
        && $pending_sha256 == "$CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256" ]] \
        || fail 'restore rehearsal clone ledger or pending set differs from its authorization'
    observed_batch=$(database_psql --command \
        'SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations' | tr -d '[:space:]')
    [[ $observed_batch == "$CONTROL_PLANE_EXPECTED_MIGRATION_BATCH" ]] \
        || fail 'restore rehearsal clone batch differs from its authorization'
    mv "$plan_candidate" "$plan_directory"
    sync -f "$proof_directory"
}

assert_plan_files()
{
    [[ -f $ledger_before_file && ! -L $ledger_before_file \
        && -f $pending_file && ! -L $pending_file ]] \
        || fail 'restore rehearsal plan artifacts are absent or unsafe'
    ledger_before_sha256=$(sha256_file "$ledger_before_file")
    pending_sha256=$(sha256_file "$pending_file")
    [[ $ledger_before_sha256 == "$CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256" \
        && $pending_sha256 == "$CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256" ]] \
        || fail 'restore rehearsal plan artifacts changed'
}

set_generation()
{
    mode=$1
    generation=$2
    runner_name="coolify-cp-restore-rehearse-$runner_key-g$generation"
    runner_id=none
    runner_exit_code=none
    runner_log_sha256=none
    database_outcome=unknown
    case "$mode" in
        apply)
            authorization_ledger_sha256=$ledger_before_sha256
            authorization_pending_sha256=$pending_sha256
            authorization_batch=$CONTROL_PLANE_EXPECTED_MIGRATION_BATCH
            ;;
        verify)
            authorization_ledger_sha256=$current_ledger_sha256
            authorization_pending_sha256=$(printf '' | sha256sum | awk '{print $1}')
            authorization_batch=$((CONTROL_PLANE_EXPECTED_MIGRATION_BATCH + 1))
            ;;
        *) fail 'restore rehearsal generation mode is invalid' ;;
    esac
    write_state planned
}

assert_runner()
{
    local expected_id=$1

    observed_runner_id=$(docker inspect --format '{{.Id}}' "$runner_name") \
        || fail 'Docker API is unavailable while reading restore rehearsal runner identity'
    observed_runner_status=$(docker inspect --format '{{.State.Status}}' "$runner_name")
    observed_runner_exit_code=$(docker inspect --format '{{.State.ExitCode}}' "$runner_name")
    [[ ($expected_id == none || $observed_runner_id == "$expected_id") \
        && $(docker inspect --format '{{.Image}}' "$runner_name") == "$expected_image_id" \
        && $(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' \
            "$runner_name") == "$rehearsal_project" \
        && $(docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}}' \
            "$runner_name") == migration-rehearsal \
        && $(docker inspect --format '{{index .Config.Labels "io.coolify.control-plane.operation-id"}}' \
            "$runner_name") == "$CONTROL_PLANE_OPERATION_ID" \
        && $(docker inspect --format '{{index .Config.Labels "io.coolify.control-plane.restore-rehearsal"}}' \
            "$runner_name") == true \
        && $(docker inspect --format '{{index .Config.Labels "io.coolify.control-plane.rehearsal-generation"}}' \
            "$runner_name") == "$generation" \
        && $(docker inspect --format '{{index .Config.Labels "io.coolify.control-plane.rehearsal-mode"}}' \
            "$runner_name") == "$mode" \
        && $(docker inspect --format '{{index .Config.Labels "io.coolify.control-plane.database-identity-sha256"}}' \
            "$runner_name") == "$database_identity_sha256" \
        && $(docker inspect --format '{{index .Config.Labels "io.coolify.control-plane.migration-ledger-sha256"}}' \
            "$runner_name") == "$authorization_ledger_sha256" \
        && $(docker inspect --format '{{index .Config.Labels "io.coolify.control-plane.migration-pending-sha256"}}' \
            "$runner_name") == "$authorization_pending_sha256" \
        && $(docker inspect --format '{{index .Config.Labels "io.coolify.control-plane.migration-batch"}}' \
            "$runner_name") == "$authorization_batch" \
        && $(docker inspect --format '{{index .Config.Labels "io.coolify.control-plane.pg-application-name"}}' \
            "$runner_name") == "$backend_application_name" ]] \
        || fail 'restore rehearsal runner identity differs from its durable plan'
    if ! container_has_network "$runner_name" "$CONTROL_PLANE_REHEARSAL_NETWORK" \
        || container_has_network "$runner_name" "$CONTROL_PLANE_LIVE_NETWORK"; then
        fail 'restore rehearsal runner network identity is unsafe'
    fi
    [[ $observed_runner_status =~ ^(created|running|exited)$ \
        && $observed_runner_exit_code =~ ^[0-9]+$ ]] \
        || fail 'restore rehearsal runner Docker state is unsafe'
}

observe_runner()
{
    container_presence "$runner_name"
    runner_presence=$presence
    observed_runner_id=none
    observed_runner_status=absent
    observed_runner_exit_code=none
    [[ $runner_presence == absent ]] || assert_runner "$runner_id"
}

start_runner()
{
    test_crash before-restore-rehearsal-runner-create
    created_runner_id=$(docker compose --ansi never --project-name "$rehearsal_project" \
        --file "$CONTROL_PLANE_REHEARSAL_COMPOSE_FILE" run --detach --no-deps --pull never \
        --name "$runner_name" --env "PGAPPNAME=$backend_application_name" \
        --env "CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256=$authorization_ledger_sha256" \
        --env "CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256=$authorization_pending_sha256" \
        --env "CONTROL_PLANE_EXPECTED_MIGRATION_BATCH=$authorization_batch" \
        --label "io.coolify.control-plane.operation-id=$CONTROL_PLANE_OPERATION_ID" \
        --label 'io.coolify.control-plane.restore-rehearsal=true' \
        --label "io.coolify.control-plane.rehearsal-generation=$generation" \
        --label "io.coolify.control-plane.rehearsal-mode=$mode" \
        --label "io.coolify.control-plane.database-identity-sha256=$database_identity_sha256" \
        --label "io.coolify.control-plane.migration-ledger-sha256=$authorization_ledger_sha256" \
        --label "io.coolify.control-plane.migration-pending-sha256=$authorization_pending_sha256" \
        --label "io.coolify.control-plane.migration-batch=$authorization_batch" \
        --label "io.coolify.control-plane.pg-application-name=$backend_application_name" \
        migration-rehearsal)
    [[ $created_runner_id =~ ^[0-9a-f]{64}$ ]] \
        || fail 'restore rehearsal runner did not return a full container ID'
    test_crash after-restore-rehearsal-runner-create
    runner_id=$created_runner_id
    assert_runner "$runner_id"
    write_state launched
    test_crash after-restore-rehearsal-runner-id-persist
    write_state active
    test_crash after-restore-rehearsal-runner-active
}

write_terminal_log()
{
    rm -f -- "$log_candidate"
    if [[ $runner_presence == present ]]; then
        docker logs "$observed_runner_id" > "$log_candidate" 2>&1 \
            || fail 'Docker API is unavailable while capturing restore rehearsal logs'
    else
        printf '%s\n' \
            'restore rehearsal runner disappeared; terminal outcome reconciled from exact clone truth' \
            > "$log_candidate"
    fi
    {
        printf '\noperator_generation=%s\n' "$generation"
        printf 'operator_mode=%s\n' "$mode"
        printf 'operator_runner_id=%s\n' "$runner_id"
        printf 'operator_runner_exit_code=%s\n' "$runner_exit_code"
        printf 'operator_postgres_backend_count=%s\n' "$backend_count"
        printf 'operator_postgres_advisory_lock_count=%s\n' "$advisory_lock_count"
        printf 'operator_postgres_backend_lock_count=%s\n' "$backend_lock_count"
        printf 'operator_ledger_sha256=%s\n' "$current_ledger_sha256"
        printf 'operator_database_outcome=%s\n' "$database_outcome"
    } >> "$log_candidate"
    chmod 600 "$log_candidate"
    sync -f "$log_candidate"
    test_crash after-restore-rehearsal-log-fsync
    if [[ -e $log_file ]]; then
        cmp -s "$log_candidate" "$log_file" \
            || fail 'restore rehearsal terminal log differs from its renamed bytes'
        rm -f -- "$log_candidate"
    else
        mv -f "$log_candidate" "$log_file"
    fi
    test_crash after-restore-rehearsal-log-rename
    sync -f "$proof_directory"
    runner_log_sha256=$(sha256_file "$log_file")
}

remove_runner()
{
    observe_runner
    if [[ $runner_presence == present ]]; then
        [[ $observed_runner_status == exited ]] \
            || fail 'refusing to remove a nonterminal restore rehearsal runner'
        docker rm "$observed_runner_id" >/dev/null
    fi
    container_presence "$runner_name"
    [[ $presence == absent ]] || fail 'restore rehearsal runner remained after cleanup'
}

finish_terminal()
{
    local terminal_status=$1 terminal_outcome=$2

    database_outcome=$terminal_outcome
    write_terminal_log
    write_state "$terminal_status"
    remove_runner
    test_crash after-restore-rehearsal-cleanup
    case "$terminal_status" in
        passed) return ;;
        failed) fail 'canonical candidate migration rehearsal failed without clone mutation' ;;
        blocked) fail 'restore rehearsal clone has unsafe partial, wrong-batch, or unverified state' ;;
    esac
}

trap 'rm -f -- "$log_candidate" "$state_candidate" "$inspect_error"' EXIT HUP INT TERM
exec 9> "$lock_file"
flock -n 9 || fail 'restore rehearsal operation is already active'

expected_image_id=$(docker image inspect --format '{{.Id}}' \
    "$CONTROL_PLANE_CANDIDATE_IMAGE_DIGEST")
database_container_id=$(docker inspect --format '{{.Id}}' \
    "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER")
database_image_id=$(docker inspect --format '{{.Image}}' \
    "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER")
database_system_identifier=$(database_psql --command \
    'SELECT system_identifier::text FROM pg_control_system()' | tr -d '[:space:]')
database_name=$(database_psql --command 'SELECT current_database()' | tr -d '[:space:]')
database_oid=$(database_psql --command \
    'SELECT oid::text FROM pg_database WHERE datname = current_database()' | tr -d '[:space:]')
database_address=$(docker inspect --format \
    "{{with index .NetworkSettings.Networks \"$CONTROL_PLANE_REHEARSAL_NETWORK\"}}{{.IPAddress}}{{end}}" \
    "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER")
[[ $database_system_identifier == "$CONTROL_PLANE_RESTORE_PG_SYSTEM_IDENTIFIER" \
    && $database_name == "$CONTROL_PLANE_RESTORE_DATABASE_NAME" \
    && $database_oid =~ ^[1-9][0-9]*$ \
    && $database_address =~ ^[0-9a-fA-F:.]+$ ]] \
    || fail 'restored clone database identity differs from its explicit plan'
database_instance_marker=$(printf '%s' "$database_system_identifier:$database_oid" \
    | sha256sum | awk '{print $1}')
database_identity_payload="system_identifier=$database_system_identifier;database=$database_name;server_address=$database_address;server_port=5432;instance_marker=$database_instance_marker"
database_identity_sha256=$(printf '%s' "$database_identity_payload" \
    | sha256sum | awk '{print $1}')
runtime_database_identity_sha256=$(runtime_env_value \
    CONTROL_PLANE_EXPECTED_DATABASE_IDENTITY_SHA256)
[[ $runtime_database_identity_sha256 == "$database_identity_sha256" ]] \
    || fail 'restore rehearsal runtime environment has the wrong clone database identity'
network_id=$(docker network inspect --format '{{.Id}}' "$CONTROL_PLANE_REHEARSAL_NETWORK")
hook_sha256=$(sha256_file "$0")
compose_sha256=$(sha256_file "$CONTROL_PLANE_REHEARSAL_COMPOSE_FILE")
runtime_env_sha256=$(sha256_file "$CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE")

if [[ ! -e $plan_directory && ! -L $plan_directory ]]; then
    prepare_plan
else
    assert_plan_files
fi
if [[ ! -e $state_file && ! -L $state_file ]]; then
    classify_ledger
    [[ $ledger_outcome == unchanged ]] \
        || fail 'restore rehearsal clone changed before planned state committed'
    set_generation apply 1
fi
assert_plan_files
assert_plan_identity

export CONTROL_PLANE_GREEN_IMAGE="$CONTROL_PLANE_CANDIDATE_IMAGE_DIGEST"
export CONTROL_PLANE_LIVE_DATABASE_SYSTEM_IDENTIFIER="$CONTROL_PLANE_SOURCE_PG_SYSTEM_IDENTIFIER"
observe_runner
observe_activity

case "$status" in
    passed|failed|blocked)
        (( backend_count == 0 && advisory_lock_count == 0 )) \
            || fail 'terminal restore rehearsal still owns a PostgreSQL backend or lock'
        classify_ledger
        case "$status:$database_outcome:$ledger_outcome" in
            passed:applied:applied|failed:unchanged:unchanged|blocked:drift:drift|blocked:unverified:applied)
                ;;
            *) fail 'terminal restore rehearsal no longer matches exact clone truth' ;;
        esac
        [[ $(sha256_file "$log_file") == "$runner_log_sha256" ]] \
            || fail 'terminal restore rehearsal log digest changed'
        remove_runner
        [[ $status == passed ]] || fail "restore rehearsal is terminal: $status"
        printf '%s\n' 'control-plane-restore-rehearsal=passed'
        exit 0
        ;;
esac

if [[ $runner_presence == present && $runner_id == none ]]; then
    runner_id=$observed_runner_id
    write_state launched
    if [[ $observed_runner_status == created ]]; then
        docker start "$runner_id" >/dev/null
    fi
    write_state active
fi
if [[ $runner_presence == absent ]]; then
    (( backend_count == 0 && advisory_lock_count == 0 )) || wait_for_database_idle
    classify_ledger
    case "$ledger_outcome" in
        unchanged)
            if [[ $runner_id != none ]]; then
                set_generation apply "$((generation + 1))"
            fi
            start_runner
            observe_runner
            ;;
        applied)
            set_generation verify "$((generation + 1))"
            start_runner
            observe_runner
            ;;
        drift)
            runner_exit_code=none
            current_ledger_sha256=${current_ledger_sha256:-unknown}
            finish_terminal blocked drift
            ;;
    esac
fi
if [[ $observed_runner_status == created ]]; then
    docker start "$observed_runner_id" >/dev/null
    write_state active
    observe_runner
fi
if [[ $observed_runner_status == running ]]; then
    docker wait "$observed_runner_id" >/dev/null \
        || fail 'Docker API is unavailable while waiting for restore rehearsal'
    observe_runner
fi
[[ $observed_runner_status == exited ]] \
    || fail 'restore rehearsal runner did not reach a terminal Docker state'
runner_exit_code=$observed_runner_exit_code
test_crash after-restore-rehearsal-runner-exit
wait_for_database_idle
classify_ledger
if [[ $runner_exit_code == 0 && $ledger_outcome == applied \
    && $(grep -Fxc "control-plane-database-identity=$database_identity_payload" \
        < <(docker logs "$observed_runner_id" 2>&1)) == 1 \
    && $(docker logs "$observed_runner_id" 2>&1 \
        | grep -Fxc "control-plane-database-identity-sha256=$database_identity_sha256") == 1 \
    && $(docker logs "$observed_runner_id" 2>&1 \
        | grep -Fxc 'control-plane-schema-attestation=passed') == 1 ]]; then
    finish_terminal passed applied
elif [[ $ledger_outcome == unchanged ]]; then
    finish_terminal failed unchanged
elif [[ $ledger_outcome == drift ]]; then
    finish_terminal blocked drift
else
    finish_terminal blocked unverified
fi
printf '%s\n' 'control-plane-restore-rehearsal=passed'
