#!/bin/sh

set -eu

LAB_DIRECTORY=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)
REPOSITORY_ROOT=$(CDPATH='' cd -- "$LAB_DIRECTORY/../../.." && pwd -P)
IMMUTABLE_BASELINE_SURPLUS="$REPOSITORY_ROOT/database/migrations/control-plane-immutable-baseline-surplus.list"
CONTROL_PLANE_MIGRATION_FINGERPRINT="$REPOSITORY_ROOT/database/migrations/control-plane-migration-inventory.fingerprint"
BASELINE_COMMIT=e7dff30b7c998c301fd91bd169727b90c59ec291
RUN_IDENTIFIER="$(date -u +%Y%m%dT%H%M%SZ)-$$"
WORK_DIRECTORY=${CONTROL_PLANE_MIGRATION_WORK_DIR:-$(mktemp -d "${TMPDIR:-/tmp}/coolify-control-plane-migration.XXXXXX")}
BASELINE_ROOT="$WORK_DIRECTORY/baseline"
ARTIFACT_DIRECTORY="$LAB_DIRECTORY/.artifacts/$RUN_IDENTIFIER"
SUMMARY_FILE="$ARTIFACT_DIRECTORY/summary.log"
CONTROL_PLANE_MIGRATION_NAMES_FILE="$ARTIFACT_DIRECTORY/control-plane-migrations.txt"
PROJECT_NAME="coolify-cpmigration-$$"
ACTIVE_LAB=0
GATE_FAILURES=0
MALFORMED_REJECTED=0
COMMAND_GUARD_REJECTED=0
FINAL_SCHEMA_REPLAYED=0
FORWARD_ONLY_ROLLBACK_REJECTED=0
TRANSACTION_ROLLBACK_REJECTED=0

export CONTROL_PLANE_MIGRATION_REPOSITORY="$REPOSITORY_ROOT"
export CONTROL_PLANE_MIGRATION_BASELINE="$BASELINE_ROOT"

record()
{
    printf '%s\n' "$1" | tee -a "$SUMMARY_FILE"
}

fail()
{
    record "CONTROL_PLANE_MIGRATION_FAILURE $1"
    exit 1
}

require_command()
{
    command -v "$1" >/dev/null 2>&1 || fail "required command is unavailable: $1"
}

compose()
{
    docker compose --ansi never --project-name "$PROJECT_NAME" --file "$LAB_DIRECTORY/compose.yaml" "$@"
}

cleanup()
{
    exit_status=$?
    trap - EXIT HUP INT TERM

    if [ "$ACTIVE_LAB" -eq 1 ]; then
        compose down --volumes --remove-orphans >/dev/null 2>&1 || true
    fi

    case "$WORK_DIRECTORY" in
        "${TMPDIR:-/tmp}"/coolify-control-plane-migration.*)
            rm -rf "$WORK_DIRECTORY"
            ;;
        *)
            record "WORK_DIRECTORY_RETAINED $WORK_DIRECTORY"
            ;;
    esac

    exit "$exit_status"
}

run_php()
{
    database_name=$1
    working_directory=$2
    shift 2

    compose run --rm --no-deps \
        --env "DB_DATABASE=$database_name" \
        --workdir "$working_directory" \
        runner "$@" < /dev/null
}

run_artisan_migrations()
{
    database_name=$1
    output_file=$2
    shift 2

    set -- artisan migrate --force --no-interaction --isolated=1 "$@"
    if ! run_php "$database_name" /workspace "$@" > "$output_file" 2>&1; then
        tail -80 "$output_file" >&2
        return 1
    fi
}

run_artisan_migrations_with_timeouts()
{
    database_name=$1
    output_file=$2
    shift 2

    if ! compose run --rm --no-deps \
        --env "DB_DATABASE=$database_name" \
        --env 'PGOPTIONS=-c lock_timeout=750ms -c statement_timeout=5s' \
        --workdir /workspace \
        runner artisan migrate --force --no-interaction --isolated=1 "$@" \
        > "$output_file" 2>&1 < /dev/null; then
        return 1
    fi
}

run_baseline_migrations()
{
    output_file=$1

    if ! run_php coolify_baseline /baseline artisan migrate --force --no-interaction > "$output_file" 2>&1; then
        tail -120 "$output_file" >&2
        return 1
    fi
}

psql_database()
{
    database_name=$1
    shift
    compose exec -T postgres psql --set ON_ERROR_STOP=1 --username coolify --dbname "$database_name" "$@"
}

psql_file()
{
    database_name=$1
    sql_file=$2
    psql_database "$database_name" < "$sql_file"
}

clone_baseline()
{
    database_name=$1
    compose exec -T postgres createdb --username coolify --owner coolify --template coolify_baseline "$database_name"
}

clone_database()
{
    source_database=$1
    target_database=$2
    compose exec -T postgres createdb --username coolify --owner coolify \
        --template "$source_database" "$target_database"
}

insert_immutable_baseline_surplus()
{
    database_name=$1
    migration_batch=$2
    printf '%s' "$migration_batch" | grep -Eq '^[1-9][0-9]*$' \
        || fail 'immutable baseline migration surplus batch is unsafe'
    while IFS= read -r migration_name; do
        printf '%s' "$migration_name" | grep -Eq '^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+$' \
            || fail 'immutable baseline migration surplus contains an unsafe name'
        psql_database "$database_name" --command \
            "INSERT INTO migrations (migration, batch) VALUES ('$migration_name', $migration_batch)" \
            >/dev/null < /dev/null
    done < "$IMMUTABLE_BASELINE_SURPLUS"
}

create_current_inventory()
{
    output_file=$1
    : > "$output_file"

    for migration_file in "$REPOSITORY_ROOT"/database/migrations/*.php; do
        basename "$migration_file" .php
    done | LC_ALL=C sort > "$output_file"
}

discover_control_plane_migrations()
{
    output_file=$1

    if ! (
        cd "$REPOSITORY_ROOT"
        php artisan start:migration --print-control-plane-migration-inventory --no-interaction
    ) > "$output_file" 2>&1; then
        tail -80 "$output_file" >&2
        fail 'production control-plane migration inventory command failed'
    fi

    [ -s "$output_file" ] || fail 'production control-plane migration inventory command returned no migrations'
}

capture_input_manifest()
(
    output_file=$1
    {
        for input_file in \
            "$REPOSITORY_ROOT/app/Console/Commands/Migration.php" \
            "$REPOSITORY_ROOT/app/Support/ControlPlaneMigrationInventory.php" \
            "$REPOSITORY_ROOT/app/Support/ControlPlaneMode.php" \
            "$REPOSITORY_ROOT/composer.lock" \
            "$REPOSITORY_ROOT/docker/control-plane-blue-green/compose.rehearsal.yaml" \
            "$REPOSITORY_ROOT/docker/control-plane-blue-green/control-plane-blue-green.sh" \
            "$IMMUTABLE_BASELINE_SURPLUS" \
            "$CONTROL_PLANE_MIGRATION_FINGERPRINT" \
            "$REPOSITORY_ROOT/vendor/laravel/framework/src/Illuminate/Database/DatabaseManager.php" \
            "$REPOSITORY_ROOT/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migration.php" \
            "$REPOSITORY_ROOT"/database/migrations/*.php \
            "$LAB_DIRECTORY/.gitignore" \
            "$LAB_DIRECTORY"/*
        do
            [ -f "$input_file" ] && shasum -a 256 "$input_file"
        done
    } | LC_ALL=C sort > "$output_file"
)

query_ledger()
{
    database_name=$1
    output_file=$2
    psql_database "$database_name" --tuples-only --no-align \
        --command 'SELECT migration FROM migrations ORDER BY migration' \
        | sed '/^[[:space:]]*$/d' > "$output_file"
}

validate_ledger()
{
    database_name=$1
    expected_pending=$2
    label=$3
    immutable_baseline_surplus=${4:-}
    ledger_file="$ARTIFACT_DIRECTORY/${label}-ledger.txt"
    query_ledger "$database_name" "$ledger_file"
    if [ -n "$immutable_baseline_surplus" ]; then
        "$LAB_DIRECTORY/validate-ledger.sh" "$ARTIFACT_DIRECTORY/current-migrations.txt" "$ledger_file" \
            "$expected_pending" "$immutable_baseline_surplus"
    else
        "$LAB_DIRECTORY/validate-ledger.sh" "$ARTIFACT_DIRECTORY/current-migrations.txt" "$ledger_file" "$expected_pending"
    fi
}

migration_path_argument()
{
    printf '%s' "--path=database/migrations/$1.php"
}

assert_sql_scalar()
{
    database_name=$1
    sql=$2
    expected=$3
    description=$4
    actual=$(psql_database "$database_name" --tuples-only --no-align --command "$sql" | tr -d '[:space:]')
    [ "$actual" = "$expected" ] || fail "$description: expected $expected, got $actual"
}

sha256_file()
{
    shasum -a 256 "$1" | awk '{print $1}'
}

dump_schema()
(
    database_name=$1
    output_file=$2
    compose exec -T postgres pg_dump --schema-only --no-owner --no-privileges --no-comments \
        --restrict-key=0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz01 \
        --username coolify --dbname "$database_name" > "$output_file"
)

capture_ledger_rows()
(
    database_name=$1
    output_file=$2
    psql_database "$database_name" --tuples-only --no-align --field-separator=, \
        --command 'SELECT migration, batch FROM migrations ORDER BY migration' > "$output_file"
)

capture_legacy_fixture_fingerprint()
(
    database_name=$1
    output_file=$2
    psql_database "$database_name" --tuples-only --no-align \
        --file /dev/stdin < "$LAB_DIRECTORY/legacy-fixture-fingerprint.sql" > "$output_file"
)

write_control_plane_attestation()
(
    database_name=$1
    artifact_prefix=$2
    identity_file="${artifact_prefix}-identity.env"
    ledger_file="${artifact_prefix}-ledger.csv"
    ledger_names_file="${artifact_prefix}-ledger-names.txt"
    pending_file="${artifact_prefix}-pending.txt"
    attestation_file="${artifact_prefix}-attestation.env"

    run_php "$database_name" /workspace \
        /workspace/tests/Integration/ControlPlaneMigration/database-attestation.php > "$identity_file"
    capture_ledger_rows "$database_name" "$ledger_file"
    query_ledger "$database_name" "$ledger_names_file"
    : > "$pending_file"
    while IFS= read -r migration_name; do
        if ! grep -F -x -q -- "$migration_name" "$ledger_names_file"; then
            printf '%s\n' "$migration_name" >> "$pending_file"
        fi
    done < "$CONTROL_PLANE_MIGRATION_NAMES_FILE"

    expected_batch=$(psql_database "$database_name" --tuples-only --no-align \
        --command 'SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations' | tr -d '[:space:]')
    {
        sed -n '/^system_identifier=/p; /^identity_sha256=/p' "$identity_file"
        printf 'ledger_sha256=%s\n' "$(sha256_file "$ledger_file")"
        printf 'pending_sha256=%s\n' "$(sha256_file "$pending_file")"
        printf 'expected_batch=%s\n' "$expected_batch"
    } > "$attestation_file"
)

attestation_value()
{
    key=$1
    attestation_file=$2
    sed -n "s/^${key}=//p" "$attestation_file"
}

run_control_plane_command()
(
    database_name=$1
    output_file=$2
    live_mode=$3
    rehearsal_mode=$4
    expected_identity_sha256=$5
    live_system_identifier=$6
    expected_ledger_sha256=$7
    expected_pending_sha256=$8
    expected_batch=$9
    command_lock_timeout=${CONTROL_PLANE_COMMAND_LOCK_TIMEOUT:-10s}
    command_operation_id="control-plane-command-${database_name}"
    command_attempt=1
    command_identity_digest=$(printf '%s:%s\n' "$command_operation_id" "$command_attempt" \
        | sha256sum | awk '{print substr($1, 1, 32)}')
    command_application_name="coolify-control-plane-expand-${command_identity_digest}"
    command_attempt_lock_identity="coolify-control-plane-expand-attempt:${command_identity_digest}"

    compose run --rm --no-deps \
        --env "DB_DATABASE=$database_name" \
        --env CONTROL_PLANE_MODE=active \
        --env CONTROL_PLANE_STARTUP_MODE=full \
        --env "CONTROL_PLANE_LIVE_EXPAND_MIGRATION=$live_mode" \
        --env "CONTROL_PLANE_MIGRATION_REHEARSAL=$rehearsal_mode" \
        --env "CONTROL_PLANE_EXPECTED_DATABASE_IDENTITY_SHA256=$expected_identity_sha256" \
        --env "CONTROL_PLANE_LIVE_DATABASE_SYSTEM_IDENTIFIER=$live_system_identifier" \
        --env "CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256=$expected_ledger_sha256" \
        --env "CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256=$expected_pending_sha256" \
        --env "CONTROL_PLANE_EXPECTED_MIGRATION_BATCH=$expected_batch" \
        --env "CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT=$command_lock_timeout" \
        --env 'CONTROL_PLANE_MIGRATION_STATEMENT_TIMEOUT=30s' \
        --env "CONTROL_PLANE_MIGRATION_OPERATION_ID=$command_operation_id" \
        --env "CONTROL_PLANE_MIGRATION_ATTEMPT=$command_attempt" \
        --env "CONTROL_PLANE_MIGRATION_APPLICATION_NAME=$command_application_name" \
        --env "CONTROL_PLANE_MIGRATION_ATTEMPT_LOCK_IDENTITY=$command_attempt_lock_identity" \
        --env "DB_READ_HOST=${CONTROL_PLANE_COMMAND_READ_HOST:-}" \
        --env 'DB_WRITE_HOST=postgres' \
        --env "PGOPTIONS=-c lock_timeout=$command_lock_timeout -c statement_timeout=30s" \
        --workdir /workspace \
        runner artisan start:migration --control-plane-expand > "$output_file" 2>&1 < /dev/null
)

run_normal_startup_migration()
(
    database_name=$1
    output_file=$2

    compose run --rm --no-deps \
        --env "DB_DATABASE=$database_name" \
        --env CONTROL_PLANE_MODE=active \
        --env CONTROL_PLANE_STARTUP_MODE=full \
        --env 'PGOPTIONS=-c lock_timeout=10s -c statement_timeout=30s' \
        --workdir /workspace \
        runner artisan start:migration > "$output_file" 2>&1 < /dev/null
)

expect_control_plane_command_failure()
{
    database_name=$1
    label=$2
    live_mode=$3
    rehearsal_mode=$4
    expected_identity_sha256=$5
    live_system_identifier=$6
    expected_ledger_sha256=$7
    expected_pending_sha256=$8
    expected_batch=$9
    shift 9
    exact_error=$1
    output_file="$ARTIFACT_DIRECTORY/${label}.log"
    schema_before="$ARTIFACT_DIRECTORY/${label}-schema-before.sql"
    schema_after="$ARTIFACT_DIRECTORY/${label}-schema-after.sql"
    ledger_before="$ARTIFACT_DIRECTORY/${label}-ledger-before.csv"
    ledger_after="$ARTIFACT_DIRECTORY/${label}-ledger-after.csv"

    dump_schema "$database_name" "$schema_before"
    capture_ledger_rows "$database_name" "$ledger_before"
    if run_control_plane_command "$database_name" "$output_file" "$live_mode" "$rehearsal_mode" \
        "$expected_identity_sha256" "$live_system_identifier" "$expected_ledger_sha256" \
        "$expected_pending_sha256" "$expected_batch"; then
        fail "control-plane command guard accepted $label"
    fi
    grep -F -q -- "$exact_error" "$output_file" \
        || fail "control-plane command guard failed with the wrong error: $label"
    dump_schema "$database_name" "$schema_after"
    capture_ledger_rows "$database_name" "$ledger_after"
    cmp "$schema_before" "$schema_after" >/dev/null \
        || fail "control-plane command guard changed schema: $label"
    cmp "$ledger_before" "$ledger_after" >/dev/null \
        || fail "control-plane command guard changed ledger: $label"
    COMMAND_GUARD_REJECTED=$((COMMAND_GUARD_REJECTED + 1))
    record "CONTROL_PLANE_COMMAND_GUARD_REJECTED $label"
}

expect_control_plane_transaction_rollback()
{
    database_name=$1
    label=$2
    exact_error=$3
    attestation_prefix="$ARTIFACT_DIRECTORY/${label}-attestation-input"
    output_file="$ARTIFACT_DIRECTORY/${label}.log"
    schema_before="$ARTIFACT_DIRECTORY/${label}-schema-before.sql"
    schema_after="$ARTIFACT_DIRECTORY/${label}-schema-after.sql"
    ledger_before="$ARTIFACT_DIRECTORY/${label}-ledger-before.csv"
    ledger_after="$ARTIFACT_DIRECTORY/${label}-ledger-after.csv"
    fingerprint_before="$ARTIFACT_DIRECTORY/${label}-fingerprint-before.txt"
    fingerprint_after="$ARTIFACT_DIRECTORY/${label}-fingerprint-after.txt"

    write_control_plane_attestation "$database_name" "$attestation_prefix"
    attestation_file="${attestation_prefix}-attestation.env"
    dump_schema "$database_name" "$schema_before"
    capture_ledger_rows "$database_name" "$ledger_before"
    capture_legacy_fixture_fingerprint "$database_name" "$fingerprint_before"
    if run_control_plane_command "$database_name" "$output_file" true false \
        "$(attestation_value identity_sha256 "$attestation_file")" \
        "$(attestation_value system_identifier "$attestation_file")" \
        "$(attestation_value ledger_sha256 "$attestation_file")" \
        "$(attestation_value pending_sha256 "$attestation_file")" \
        "$(attestation_value expected_batch "$attestation_file")"; then
        fail "controlled expand accepted injected transaction failure: $label"
    fi
    grep -F -q -- "$exact_error" "$output_file" \
        || fail "controlled expand failed with the wrong injected error: $label"
    dump_schema "$database_name" "$schema_after"
    capture_ledger_rows "$database_name" "$ledger_after"
    capture_legacy_fixture_fingerprint "$database_name" "$fingerprint_after"
    cmp "$schema_before" "$schema_after" >/dev/null \
        || fail "controlled expand did not roll back schema after: $label"
    cmp "$ledger_before" "$ledger_after" >/dev/null \
        || fail "controlled expand did not roll back ledger after: $label"
    cmp "$fingerprint_before" "$fingerprint_after" >/dev/null \
        || fail "controlled expand did not roll back legacy rows after: $label"
    assert_sql_scalar "$database_name" "SELECT count(*) FROM migrations WHERE migration LIKE '2026_07_12_%'" \
        0 "controlled expand authorized ledger rollback after $label"
    TRANSACTION_ROLLBACK_REJECTED=$((TRANSACTION_ROLLBACK_REJECTED + 1))
    record "CONTROL_PLANE_TRANSACTION_ROLLBACK_PASS label=$label schema=true ledger=true legacy_rows=true"
}

expect_migration_failure()
{
    database_name=$1
    migration_name=$2
    label=$3
    exact_error=$4
    output_file="$ARTIFACT_DIRECTORY/${label}.log"
    schema_before="$ARTIFACT_DIRECTORY/${label}-schema-before.sql"
    schema_after="$ARTIFACT_DIRECTORY/${label}-schema-after.sql"

    assert_sql_scalar "$database_name" "SELECT count(*) FROM migrations WHERE migration = '$migration_name'" \
        0 "malformed precondition ledger row for $label"
    dump_schema "$database_name" "$schema_before"

    if run_artisan_migrations "$database_name" "$output_file" "$(migration_path_argument "$migration_name")" 2>/dev/null; then
        record "MALFORMED_SHAPE_ACCEPTED $label"
        GATE_FAILURES=$((GATE_FAILURES + 1))
        return
    fi

    if ! grep -F -q -- "$exact_error" "$output_file"; then
        record "MALFORMED_SHAPE_WRONG_FAILURE $label"
        GATE_FAILURES=$((GATE_FAILURES + 1))
        return
    fi

    assert_sql_scalar "$database_name" "SELECT count(*) FROM migrations WHERE migration = '$migration_name'" \
        0 "malformed failure ledger row for $label"
    dump_schema "$database_name" "$schema_after"
    if ! cmp "$schema_before" "$schema_after" >/dev/null; then
        record "MALFORMED_SHAPE_CHANGED $label"
        GATE_FAILURES=$((GATE_FAILURES + 1))
        return
    fi

    MALFORMED_REJECTED=$((MALFORMED_REJECTED + 1))
    record "MALFORMED_SHAPE_REJECTED $label"
}

run_without_ledger()
{
    database_name=$1
    migration_name=$2
    output_file=$3

    compose run --rm --no-deps \
        --env "DB_DATABASE=$database_name" \
        --env "CONTROL_PLANE_MIGRATION_NAME=$migration_name" \
        --workdir /workspace \
        runner /workspace/tests/Integration/ControlPlaneMigration/run-migration-without-ledger.php \
        > "$output_file" 2>&1 < /dev/null
}

run_identity_guard()
{
    source_database=$1
    rehearsal_database=$2
    output_file=$3

    compose run --rm --no-deps \
        --env "CONTROL_PLANE_SOURCE_DSN=pgsql:host=postgres;port=5432;dbname=$source_database" \
        --env "CONTROL_PLANE_REHEARSAL_DSN=pgsql:host=postgres;port=5432;dbname=$rehearsal_database" \
        runner /workspace/tests/Integration/ControlPlaneMigration/identity-guard.php \
        > "$output_file" 2>&1 < /dev/null
}

trap cleanup EXIT HUP INT TERM

mkdir -p "$BASELINE_ROOT" "$ARTIFACT_DIRECTORY"
: > "$SUMMARY_FILE"

for required_command in docker git php grep sort comm cmp diff jq mktemp shasum sha256sum shellcheck tar; do
    require_command "$required_command"
done

record "RUN_IDENTIFIER $RUN_IDENTIFIER"
record "BASELINE_COMMIT $BASELINE_COMMIT"
record "ARTIFACT_DIRECTORY $ARTIFACT_DIRECTORY"

[ "$(git -C "$REPOSITORY_ROOT" rev-parse v4.1.2)" = "$BASELINE_COMMIT" ] \
    || fail 'v4.1.2 does not resolve to the authorized baseline commit'

baseline_migration_count=$(git -C "$REPOSITORY_ROOT" ls-tree -r --name-only "$BASELINE_COMMIT" database/migrations | wc -l | tr -d ' ')
[ "$baseline_migration_count" = 337 ] || fail "baseline migration count changed: $baseline_migration_count"
discover_control_plane_migrations "$CONTROL_PLANE_MIGRATION_NAMES_FILE"
authorized_migration_count=$(wc -l < "$CONTROL_PLANE_MIGRATION_NAMES_FILE" | tr -d ' ')
[ "$authorized_migration_count" -ge 2 ] \
    || fail "control-plane migration inventory must contain at least two migrations: $authorized_migration_count"
expected_current_migration_count=$((baseline_migration_count + authorized_migration_count))

capture_input_manifest "$ARTIFACT_DIRECTORY/input-manifest-start.sha256"

git -C "$REPOSITORY_ROOT" archive "$BASELINE_COMMIT" | tar -x -C "$BASELINE_ROOT"
ln -s /workspace/vendor "$BASELINE_ROOT/vendor"
mkdir -p \
    "$BASELINE_ROOT/bootstrap/cache" \
    "$BASELINE_ROOT/storage/framework/cache/data" \
    "$BASELINE_ROOT/storage/framework/sessions" \
    "$BASELINE_ROOT/storage/framework/views" \
    "$BASELINE_ROOT/storage/logs"

create_current_inventory "$ARTIFACT_DIRECTORY/current-migrations.txt"
current_migration_count=$(wc -l < "$ARTIFACT_DIRECTORY/current-migrations.txt" | tr -d ' ')
[ "$current_migration_count" = "$expected_current_migration_count" ] \
    || fail "current migration inventory is not the expected $expected_current_migration_count rows: $current_migration_count"

while IFS= read -r migration_name; do
    shasum -a 256 "$REPOSITORY_ROOT/database/migrations/$migration_name.php"
done < "$CONTROL_PLANE_MIGRATION_NAMES_FILE" > "$ARTIFACT_DIRECTORY/authorized-migration-sha256.txt"

compose up --detach postgres read-postgres >/dev/null
ACTIVE_LAB=1

attempt=0
until compose exec -T postgres pg_isready --username coolify --dbname postgres >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    [ "$attempt" -lt 30 ] || fail 'PostgreSQL 15.18 did not become ready'
    sleep 1
done
attempt=0
until compose exec -T read-postgres pg_isready --username coolify --dbname postgres >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    [ "$attempt" -lt 30 ] || fail 'read-sentinel PostgreSQL 15.18 did not become ready'
    sleep 1
done

compose run --rm --no-deps --entrypoint sh runner -ec \
    'mkdir -p /workspace/storage/framework/cache/data /workspace/storage/framework/sessions /workspace/storage/framework/views /workspace/storage/logs /workspace/bootstrap/cache' \
    >/dev/null

compose exec -T postgres createdb --username coolify --owner coolify clean_install
run_artisan_migrations clean_install "$ARTIFACT_DIRECTORY/clean-install.log" \
    || fail 'blank PostgreSQL full current migration chain failed'
assert_sql_scalar clean_install 'SELECT count(*) FROM migrations' \
    "$current_migration_count" 'blank PostgreSQL full current migration ledger count'
psql_database clean_install --set control_plane_require_fixtures=false \
    < "$LAB_DIRECTORY/validate-schema.sql" \
    > "$ARTIFACT_DIRECTORY/clean-install-schema-validation.log"
capture_ledger_rows clean_install "$ARTIFACT_DIRECTORY/clean-install-ledger-before-rerun.csv"
dump_schema clean_install "$ARTIFACT_DIRECTORY/clean-install-schema-before-rerun.sql"
run_artisan_migrations clean_install "$ARTIFACT_DIRECTORY/clean-install-rerun.log" \
    || fail 'blank PostgreSQL full current migration chain rerun failed'
capture_ledger_rows clean_install "$ARTIFACT_DIRECTORY/clean-install-ledger-after-rerun.csv"
dump_schema clean_install "$ARTIFACT_DIRECTORY/clean-install-schema-after-rerun.sql"
cmp "$ARTIFACT_DIRECTORY/clean-install-ledger-before-rerun.csv" \
    "$ARTIFACT_DIRECTORY/clean-install-ledger-after-rerun.csv" >/dev/null \
    || fail 'blank PostgreSQL full current migration rerun changed the ledger'
cmp "$ARTIFACT_DIRECTORY/clean-install-schema-before-rerun.sql" \
    "$ARTIFACT_DIRECTORY/clean-install-schema-after-rerun.sql" >/dev/null \
    || fail 'blank PostgreSQL full current migration rerun changed the schema'
record "CLEAN_INSTALL_PASS migrations=$current_migration_count rerun_noop=true"

compose exec -T postgres createdb --username coolify --owner coolify coolify_baseline
run_baseline_migrations "$ARTIFACT_DIRECTORY/baseline-migrate.log" || fail 'v4.1.2 baseline migrations failed'
assert_sql_scalar coolify_baseline 'SELECT count(*) FROM migrations' 337 'baseline ledger count'
assert_sql_scalar coolify_baseline "SELECT current_setting('server_version')" 15.18 'PostgreSQL server version'
psql_database coolify_baseline --command 'INSERT INTO instance_settings (id) VALUES (0)' >/dev/null
record 'BASELINE_READY migrations=337 postgresql=15.18'

clone_baseline normal_run
psql_file normal_run "$LAB_DIRECTORY/seed-existing-rows.sql" > "$ARTIFACT_DIRECTORY/normal-seed.log"
capture_legacy_fixture_fingerprint normal_run "$ARTIFACT_DIRECTORY/normal-legacy-fingerprint-before.txt"
psql_file normal_run "$LAB_DIRECTORY/relation-footprint.sql" > "$ARTIFACT_DIRECTORY/normal-footprint-before.txt"
validate_ledger normal_run "$CONTROL_PLANE_MIGRATION_NAMES_FILE" normal-initial >> "$SUMMARY_FILE"
record "INITIAL_PENDING_EXACT count=$authorized_migration_count"

psql_database normal_run --command "INSERT INTO migrations (migration, batch) VALUES ('2099_01_01_000000_unrelated_ledger_row', 999)" >/dev/null
if validate_ledger normal_run "$CONTROL_PLANE_MIGRATION_NAMES_FILE" unrelated-ledger > "$ARTIFACT_DIRECTORY/unrelated-ledger-validator.log" 2>&1; then
    fail 'ledger validator accepted an unrelated ledger row'
fi
psql_database normal_run --command "DELETE FROM migrations WHERE migration = '2099_01_01_000000_unrelated_ledger_row'" >/dev/null
record 'UNRELATED_LEDGER_REJECTED'

set --
while IFS= read -r migration_name; do
    set -- "$@" "$(migration_path_argument "$migration_name")"
done < "$CONTROL_PLANE_MIGRATION_NAMES_FILE"
run_artisan_migrations normal_run "$ARTIFACT_DIRECTORY/normal-migrate.log" "$@" \
    || fail 'normal authorized-migration run failed'
: > "$ARTIFACT_DIRECTORY/empty-pending.txt"
validate_ledger normal_run "$ARTIFACT_DIRECTORY/empty-pending.txt" normal-complete >> "$SUMMARY_FILE"
assert_sql_scalar normal_run "SELECT count(*) FROM migrations WHERE migration LIKE '2026_07_12_%'" \
    "$authorized_migration_count" 'authorized ledger count after normal run'
assert_sql_scalar normal_run "SELECT count(DISTINCT batch) FROM migrations WHERE migration LIKE '2026_07_12_%'" 1 'normal run target batch count'
assert_sql_scalar normal_run 'SELECT count(*) FROM application_settings WHERE is_blue_green_deployment_enabled IS DISTINCT FROM false' 0 'existing setting defaults'
assert_sql_scalar normal_run "SELECT count(*) FROM application_deployment_queues WHERE deployment_uuid IN ('control-plane-migration-queued', 'control-plane-migration-in-progress')" 2 'durable queue rows'
assert_sql_scalar normal_run "SELECT count(*) FROM application_deployment_queues WHERE status = 'in_progress' AND horizon_job_worker IS NOT NULL" 0 'active Horizon worker count'
capture_legacy_fixture_fingerprint normal_run "$ARTIFACT_DIRECTORY/normal-legacy-fingerprint-after.txt"
cmp "$ARTIFACT_DIRECTORY/normal-legacy-fingerprint-before.txt" \
    "$ARTIFACT_DIRECTORY/normal-legacy-fingerprint-after.txt" \
    || fail 'full legacy fixture counts or hashes changed during additive migrations'
psql_file normal_run "$LAB_DIRECTORY/relation-footprint.sql" > "$ARTIFACT_DIRECTORY/normal-footprint-after.txt"
cmp "$ARTIFACT_DIRECTORY/normal-footprint-before.txt" "$ARTIFACT_DIRECTORY/normal-footprint-after.txt" \
    || fail 'existing table relfilenodes or main-fork sizes changed during additive migrations'
psql_file normal_run "$LAB_DIRECTORY/inspect-schema.sql" > "$ARTIFACT_DIRECTORY/normal-schema.txt"
psql_file normal_run "$LAB_DIRECTORY/validate-schema.sql" > "$ARTIFACT_DIRECTORY/normal-schema-validation.log"
settings_fingerprint=$(sed -n '1p' "$ARTIFACT_DIRECTORY/normal-legacy-fingerprint-after.txt")
queue_fingerprint=$(sed -n '2p' "$ARTIFACT_DIRECTORY/normal-legacy-fingerprint-after.txt")
record "NORMAL_RUN_PASS rows_preserved=true relfilenodes_preserved=true main_fork_sizes_preserved=true $settings_fingerprint $queue_fingerprint"

normal_ledger_before=$(psql_database normal_run --tuples-only --no-align --command 'SELECT count(*) FROM migrations' | tr -d '[:space:]')
normal_batch_before=$(psql_database normal_run --tuples-only --no-align --command 'SELECT max(batch) FROM migrations' | tr -d '[:space:]')
run_artisan_migrations normal_run "$ARTIFACT_DIRECTORY/normal-rerun.log" "$@" || fail 'normal migration rerun failed'
assert_sql_scalar normal_run 'SELECT count(*) FROM migrations' "$normal_ledger_before" 'rerun ledger count'
assert_sql_scalar normal_run 'SELECT max(batch) FROM migrations' "$normal_batch_before" 'rerun maximum batch'
record 'NORMAL_RERUN_NOOP'

clone_baseline split_run
head -2 "$CONTROL_PLANE_MIGRATION_NAMES_FILE" > "$ARTIFACT_DIRECTORY/split-first-two.txt"
sed -n '3,$p' "$CONTROL_PLANE_MIGRATION_NAMES_FILE" > "$ARTIFACT_DIRECTORY/split-remaining.txt"
set --
while IFS= read -r migration_name; do
    set -- "$@" "$(migration_path_argument "$migration_name")"
done < "$ARTIFACT_DIRECTORY/split-first-two.txt"
run_artisan_migrations split_run "$ARTIFACT_DIRECTORY/split-first-two.log" "$@" || fail 'split run first two migrations failed'
validate_ledger split_run "$ARTIFACT_DIRECTORY/split-remaining.txt" split-after-two >> "$SUMMARY_FILE"
first_two_batch=$(psql_database split_run --tuples-only --no-align --command "SELECT min(batch) FROM migrations WHERE migration LIKE '2026_07_12_%'" | tr -d '[:space:]')
assert_sql_scalar split_run "SELECT count(*) FROM migrations WHERE migration LIKE '2026_07_12_%' AND batch = $first_two_batch" 2 'split first batch migration count'

set --
while IFS= read -r migration_name; do
    set -- "$@" "$(migration_path_argument "$migration_name")"
done < "$CONTROL_PLANE_MIGRATION_NAMES_FILE"
run_artisan_migrations split_run "$ARTIFACT_DIRECTORY/split-retry.log" "$@" || fail 'split run retry failed'
validate_ledger split_run "$ARTIFACT_DIRECTORY/empty-pending.txt" split-complete >> "$SUMMARY_FILE"
remaining_batch=$(psql_database split_run --tuples-only --no-align --command "SELECT max(batch) FROM migrations WHERE migration LIKE '2026_07_12_%'" | tr -d '[:space:]')
[ "$remaining_batch" -gt "$first_two_batch" ] || fail 'split retry did not use a later migration batch'
remaining_migration_count=$((authorized_migration_count - 2))
assert_sql_scalar split_run "SELECT count(*) FROM migrations WHERE migration LIKE '2026_07_12_%' AND batch = $remaining_batch" \
    "$remaining_migration_count" 'split remaining batch migration count'
record "SPLIT_BATCH_PASS first_batch=$first_two_batch first_count=2 retry_batch=$remaining_batch retry_count=$remaining_migration_count"

clone_baseline live_surplus
psql_database live_surplus --command "INSERT INTO migrations (migration, batch) VALUES
    ('2025_10_10_120000_create_cloud_init_scripts_table', 29),
    ('2025_10_10_120000_create_webhook_notification_settings_table', 29),
    ('2025_10_10_120001_populate_webhook_notification_settings_for_existing_teams', 29)" >/dev/null
validate_ledger live_surplus "$CONTROL_PLANE_MIGRATION_NAMES_FILE" live-surplus-initial \
    "$IMMUTABLE_BASELINE_SURPLUS" >> "$SUMMARY_FILE"
assert_sql_scalar live_surplus 'SELECT count(*) FROM migrations' 340 'live-surplus baseline ledger count'

psql_database live_surplus --command \
    "INSERT INTO migrations (migration, batch) VALUES ('2099_01_01_000000_new_attempt_delta', 30)" >/dev/null
if validate_ledger live_surplus "$CONTROL_PLANE_MIGRATION_NAMES_FILE" live-surplus-new-delta \
    "$IMMUTABLE_BASELINE_SURPLUS" > "$ARTIFACT_DIRECTORY/live-surplus-new-delta-validator.log" 2>&1; then
    fail 'immutable live baseline surplus validation accepted a new attempt delta'
fi
psql_database live_surplus --command \
    "DELETE FROM migrations WHERE migration = '2099_01_01_000000_new_attempt_delta'" >/dev/null

set --
while IFS= read -r migration_name; do
    set -- "$@" "$(migration_path_argument "$migration_name")"
done < "$CONTROL_PLANE_MIGRATION_NAMES_FILE"
run_artisan_migrations live_surplus "$ARTIFACT_DIRECTORY/live-surplus-migrate.log" "$@" \
    || fail 'live-surplus migration run failed'
validate_ledger live_surplus "$ARTIFACT_DIRECTORY/empty-pending.txt" live-surplus-complete \
    "$IMMUTABLE_BASELINE_SURPLUS" >> "$SUMMARY_FILE"
assert_sql_scalar live_surplus \
    "SELECT count(*) FROM migrations WHERE migration IN ('2025_10_10_120000_create_cloud_init_scripts_table', '2025_10_10_120000_create_webhook_notification_settings_table', '2025_10_10_120001_populate_webhook_notification_settings_for_existing_teams') AND batch = 29" \
    3 'immutable live baseline surplus stability'
assert_sql_scalar live_surplus \
    "SELECT count(*) FROM migrations WHERE migration LIKE '2026_07_12_%' AND batch = 30" \
    "$authorized_migration_count" 'live-surplus candidate migration batch'
record "LIVE_LEDGER_SURPLUS_PASS baseline=340 immutable_surplus=3 candidate_pending=$authorized_migration_count candidate_batch=30 new_delta_rejected=true"

if run_identity_guard normal_run normal_run "$ARTIFACT_DIRECTORY/identity-same-database.log"; then
    fail 'database identity guard accepted a rehearsal DSN pointing to the source database'
fi
grep -Fq 'Rehearsal database identity equals the source database identity' "$ARTIFACT_DIRECTORY/identity-same-database.log" \
    || fail 'same-database identity guard failed for the wrong reason'
run_identity_guard normal_run split_run "$ARTIFACT_DIRECTORY/identity-distinct-database.log" \
    || fail 'database identity guard rejected distinct database names'
record 'DATABASE_IDENTITY_GUARD_PASS system_identifier=true database=true address=true port=true same_dsn_rejected=true'

clone_baseline command_modes
insert_immutable_baseline_surplus command_modes 29
write_control_plane_attestation command_modes "$ARTIFACT_DIRECTORY/command-modes"
command_attestation="$ARTIFACT_DIRECTORY/command-modes-attestation.env"
command_identity_sha256=$(attestation_value identity_sha256 "$command_attestation")
command_system_identifier=$(attestation_value system_identifier "$command_attestation")
command_ledger_sha256=$(attestation_value ledger_sha256 "$command_attestation")
command_pending_sha256=$(attestation_value pending_sha256 "$command_attestation")
command_expected_batch=$(attestation_value expected_batch "$command_attestation")
invalid_sha256=$(printf '%s' invalid-control-plane-attestation | shasum -a 256 | awk '{print $1}')
wrong_system_identifier="1${command_system_identifier}"

expect_control_plane_command_failure command_modes command-mode-missing false false \
    "$command_identity_sha256" "$command_system_identifier" "$command_ledger_sha256" \
    "$command_pending_sha256" "$command_expected_batch" \
    'Control-plane expand requires exactly one live or rehearsal mode.'
expect_control_plane_command_failure command_modes command-mode-both true true \
    "$command_identity_sha256" "$command_system_identifier" "$command_ledger_sha256" \
    "$command_pending_sha256" "$command_expected_batch" \
    'Control-plane expand requires exactly one live or rehearsal mode.'
expect_control_plane_command_failure command_modes command-rehearsal-same-cluster false true \
    "$command_identity_sha256" "$command_system_identifier" "$command_ledger_sha256" \
    "$command_pending_sha256" "$command_expected_batch" \
    'Control-plane database identity attestation failed.'
expect_control_plane_command_failure command_modes command-live-wrong-system true false \
    "$command_identity_sha256" "$wrong_system_identifier" "$command_ledger_sha256" \
    "$command_pending_sha256" "$command_expected_batch" \
    'Control-plane database identity attestation failed.'
expect_control_plane_command_failure command_modes command-wrong-identity-hash true false \
    "$invalid_sha256" "$command_system_identifier" "$command_ledger_sha256" \
    "$command_pending_sha256" "$command_expected_batch" \
    'Control-plane database identity attestation failed.'
expect_control_plane_command_failure command_modes command-wrong-ledger-hash true false \
    "$command_identity_sha256" "$command_system_identifier" "$invalid_sha256" \
    "$command_pending_sha256" "$command_expected_batch" \
    'Control-plane migration ledger changed before DDL.'
expect_control_plane_command_failure command_modes command-wrong-pending-hash true false \
    "$command_identity_sha256" "$command_system_identifier" "$command_ledger_sha256" \
    "$invalid_sha256" "$command_expected_batch" \
    'Control-plane migration ledger changed before DDL.'
expect_control_plane_command_failure command_modes command-wrong-batch true false \
    "$command_identity_sha256" "$command_system_identifier" "$command_ledger_sha256" \
    "$command_pending_sha256" 999 \
    'Control-plane migration ledger changed before DDL.'

psql_database command_modes --command \
    "INSERT INTO migrations (migration, batch) VALUES ('2099_01_01_000000_unreviewed_future_migration', 30)" \
    >/dev/null
write_control_plane_attestation command_modes "$ARTIFACT_DIRECTORY/command-unreviewed-inventory"
unreviewed_attestation="$ARTIFACT_DIRECTORY/command-unreviewed-inventory-attestation.env"
expect_control_plane_command_failure command_modes command-unreviewed-inventory true false \
    "$(attestation_value identity_sha256 "$unreviewed_attestation")" \
    "$(attestation_value system_identifier "$unreviewed_attestation")" \
    "$(attestation_value ledger_sha256 "$unreviewed_attestation")" \
    "$(attestation_value pending_sha256 "$unreviewed_attestation")" \
    "$(attestation_value expected_batch "$unreviewed_attestation")" \
    'Migration ledger surplus differs from the exact immutable baseline inventory.'
psql_database command_modes --command \
    "DELETE FROM migrations WHERE migration = '2099_01_01_000000_unreviewed_future_migration'" \
    >/dev/null
record "CONTROL_PLANE_COMMAND_IDENTITY_MODES_PASS rejected=$COMMAND_GUARD_REJECTED schema_unchanged=true ledger_unchanged=true"

clone_baseline partial_command
insert_immutable_baseline_surplus partial_command 29
run_artisan_migrations partial_command "$ARTIFACT_DIRECTORY/partial-command-first.log" \
    "$(migration_path_argument 2026_07_12_000000_add_blue_green_deployment_setting_to_application_settings)" \
    || fail 'partial command prerequisite migration failed'
write_control_plane_attestation partial_command "$ARTIFACT_DIRECTORY/partial-command"
partial_command_attestation="$ARTIFACT_DIRECTORY/partial-command-attestation.env"
expect_control_plane_command_failure partial_command partial-command-gap true false \
    "$(attestation_value identity_sha256 "$partial_command_attestation")" \
    "$(attestation_value system_identifier "$partial_command_attestation")" \
    "$(attestation_value ledger_sha256 "$partial_command_attestation")" \
    "$(attestation_value pending_sha256 "$partial_command_attestation")" \
    "$(attestation_value expected_batch "$partial_command_attestation")" \
    'Candidate migration inventory does not have the exact authorized migration gap.'
record "PARTIAL_CONTROL_PLANE_GAP_REJECTED pending=$((authorized_migration_count - 1)) ddl_unchanged=true ledger_unchanged=true"

clone_baseline nonempty_new_table_command
insert_immutable_baseline_surplus nonempty_new_table_command 29
run_artisan_migrations nonempty_new_table_command \
    "$ARTIFACT_DIRECTORY/nonempty-new-table-command-setup.log" \
    "$(migration_path_argument 2026_07_12_000001_create_application_blue_green_deployments_table)" \
    || fail 'nonempty new-table command setup migration failed'
psql_database nonempty_new_table_command --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000001_create_application_blue_green_deployments_table'; SET session_replication_role = replica; INSERT INTO application_blue_green_deployments (application_id, standalone_docker_id) VALUES (9223372036854775000, 9223372036854775000); RESET session_replication_role" \
    >/dev/null
write_control_plane_attestation nonempty_new_table_command \
    "$ARTIFACT_DIRECTORY/nonempty-new-table-command"
nonempty_new_table_attestation="$ARTIFACT_DIRECTORY/nonempty-new-table-command-attestation.env"
expect_control_plane_command_failure nonempty_new_table_command nonempty-new-table-command true false \
    "$(attestation_value identity_sha256 "$nonempty_new_table_attestation")" \
    "$(attestation_value system_identifier "$nonempty_new_table_attestation")" \
    "$(attestation_value ledger_sha256 "$nonempty_new_table_attestation")" \
    "$(attestation_value pending_sha256 "$nonempty_new_table_attestation")" \
    "$(attestation_value expected_batch "$nonempty_new_table_attestation")" \
    'Authorized control-plane table is not empty: application_blue_green_deployments.'
record 'NONEMPTY_NEW_CONTROL_PLANE_TABLE_REJECTED rows=1 ddl_unchanged=true ledger_unchanged=true'

clone_baseline split_endpoint_command
insert_immutable_baseline_surplus split_endpoint_command 29
write_control_plane_attestation split_endpoint_command "$ARTIFACT_DIRECTORY/split-endpoint-command"
split_endpoint_attestation="$ARTIFACT_DIRECTORY/split-endpoint-command-attestation.env"
primary_container=$(compose ps -q postgres)
read_container=$(compose ps -q read-postgres)
docker logs "$primary_container" > "$ARTIFACT_DIRECTORY/split-endpoint-primary-before.log" 2>&1
docker logs "$read_container" > "$ARTIFACT_DIRECTORY/split-endpoint-read-before.log" 2>&1
primary_log_lines_before=$(wc -l < "$ARTIFACT_DIRECTORY/split-endpoint-primary-before.log" | tr -d '[:space:]')
read_log_lines_before=$(wc -l < "$ARTIFACT_DIRECTORY/split-endpoint-read-before.log" | tr -d '[:space:]')
CONTROL_PLANE_COMMAND_READ_HOST=read-postgres run_control_plane_command split_endpoint_command \
    "$ARTIFACT_DIRECTORY/split-endpoint-command.log" true false \
    "$(attestation_value identity_sha256 "$split_endpoint_attestation")" \
    "$(attestation_value system_identifier "$split_endpoint_attestation")" \
    "$(attestation_value ledger_sha256 "$split_endpoint_attestation")" \
    "$(attestation_value pending_sha256 "$split_endpoint_attestation")" \
    "$(attestation_value expected_batch "$split_endpoint_attestation")" \
    || fail 'control-plane command touched the distinct read endpoint or failed on the write endpoint'
split_endpoint_operation_id=control-plane-command-split_endpoint_command
split_endpoint_identity_digest=$(printf '%s:%s\n' "$split_endpoint_operation_id" 1 \
    | sha256sum | awk '{print substr($1, 1, 32)}')
split_endpoint_application_name="coolify-control-plane-expand-${split_endpoint_identity_digest}"
[ "$(grep -F -c "control-plane-migration-application-name=$split_endpoint_application_name" \
    "$ARTIFACT_DIRECTORY/split-endpoint-command.log")" = 1 ] \
    || fail 'control-plane command did not emit one stable PostgreSQL application name'
[ "$(grep -E -c '^control-plane-migration-backend-pid=[1-9][0-9]*$' \
    "$ARTIFACT_DIRECTORY/split-endpoint-command.log")" = 1 ] \
    || fail 'control-plane command did not emit one stable PostgreSQL backend PID'
docker logs "$primary_container" > "$ARTIFACT_DIRECTORY/split-endpoint-primary-after.log" 2>&1
docker logs "$read_container" > "$ARTIFACT_DIRECTORY/split-endpoint-read-after.log" 2>&1
sed -n "$((primary_log_lines_before + 1)),\$p" \
    "$ARTIFACT_DIRECTORY/split-endpoint-primary-after.log" \
    > "$ARTIFACT_DIRECTORY/split-endpoint-primary-command.log"
sed -n "$((read_log_lines_before + 1)),\$p" \
    "$ARTIFACT_DIRECTORY/split-endpoint-read-after.log" \
    > "$ARTIFACT_DIRECTORY/split-endpoint-read-command.log"
if grep -F -q 'split_endpoint_command' "$ARTIFACT_DIRECTORY/split-endpoint-read-command.log"; then
    fail 'control-plane command sent protocol traffic to the distinct read PostgreSQL endpoint'
fi
sed -n 's/^\([0-9][0-9]*\) \[split_endpoint_command\] LOG: .*/\1/p' \
    "$ARTIFACT_DIRECTORY/split-endpoint-primary-command.log" \
    | LC_ALL=C sort -u > "$ARTIFACT_DIRECTORY/split-endpoint-primary-pids.txt"
[ "$(wc -l < "$ARTIFACT_DIRECTORY/split-endpoint-primary-pids.txt" | tr -d '[:space:]')" = 1 ] \
    || fail 'control-plane timeout, identity, lock, ledger, DDL, and schema replay did not share one write PostgreSQL backend PID'
for protocol_pattern in \
    "set_config('lock_timeout'" \
    'pg_control_system()' \
    'pg_try_advisory_lock' \
    'SELECT migration, batch FROM migrations ORDER BY migration' \
    'alter table' \
    'pg_advisory_unlock'
do
    grep -F -i -q "$protocol_pattern" "$ARTIFACT_DIRECTORY/split-endpoint-primary-command.log" \
        || fail "write-endpoint protocol evidence omitted: $protocol_pattern"
done
assert_sql_scalar split_endpoint_command 'SELECT count(*) FROM application_blue_green_deployments' 0 \
    'controlled expand deployment table row count'
assert_sql_scalar split_endpoint_command 'SELECT count(*) FROM application_blue_green_deactivations' 0 \
    'controlled expand deactivation table row count'
psql_database split_endpoint_command --tuples-only --no-align \
    --command "SELECT migration FROM migrations WHERE migration LIKE '2026_07_12_%' ORDER BY id" \
    > "$ARTIFACT_DIRECTORY/split-endpoint-command-appended.txt"
cmp "$CONTROL_PLANE_MIGRATION_NAMES_FILE" "$ARTIFACT_DIRECTORY/split-endpoint-command-appended.txt" >/dev/null \
    || fail 'controlled expand ledger was not the exact ordered authorized migration append'
assert_sql_scalar split_endpoint_command \
    "SELECT count(*) FROM migrations WHERE migration LIKE '2026_07_12_%' AND batch = $(attestation_value expected_batch "$split_endpoint_attestation")" \
    "$authorized_migration_count" 'controlled expand exact expected batch'
record "READ_WRITE_ENDPOINT_PASS read_protocol_queries=0 write_backend_pid=$(cat "$ARTIFACT_DIRECTORY/split-endpoint-primary-pids.txt") timeout_identity_lock_ledger_ddl_schema=true"

operator_live_env="$WORK_DIRECTORY/operator-live.env"
operator_rehearsal_env="$WORK_DIRECTORY/operator-rehearsal.env"
operator_rehearsal_identity_sha256=$(printf '%s' rehearsal-database-identity | shasum -a 256 | awk '{print $1}')
operator_migration_operation_id=control-plane-migration-operator
operator_migration_attempt=1
operator_migration_identity_digest=$(printf '%s:%s\n' "$operator_migration_operation_id" \
    "$operator_migration_attempt" | sha256sum | awk '{print substr($1, 1, 32)}')
operator_migration_runner_key=$(printf '%s:%s\n' "$operator_migration_operation_id" \
    "$operator_migration_attempt" | sha256sum | awk '{print substr($1, 1, 24)}')
operator_migration_application_name="coolify-control-plane-expand-${operator_migration_identity_digest}"
operator_migration_attempt_lock_identity="coolify-control-plane-expand-attempt:${operator_migration_identity_digest}"
operator_migration_runner_name="coolify-cp-migrate-${operator_migration_runner_key}-a${operator_migration_attempt}"
{
    printf 'CONTROL_PLANE_EXPECTED_DATABASE_IDENTITY_SHA256=%s\n' "$command_identity_sha256"
    printf 'DB_DATABASE=command_modes\n'
} > "$operator_live_env"
{
    printf 'CONTROL_PLANE_EXPECTED_DATABASE_IDENTITY_SHA256=%s\n' "$operator_rehearsal_identity_sha256"
    printf 'DB_DATABASE=rehearsal_clone\n'
} > "$operator_rehearsal_env"
(
    export CONTROL_PLANE_GREEN_IMAGE='serversideup/php:8.5-fpm-nginx-alpine@sha256:1854d81da23fc5c174a26bf039bc7724aeccec5743524717bbc6c10c1c927ac2'
    export CONTROL_PLANE_RUNTIME_ENV_FILE="$operator_live_env"
    export CONTROL_PLANE_REHEARSAL_RUNTIME_ENV_FILE="$operator_rehearsal_env"
    export CONTROL_PLANE_NETWORK=control-plane-migration-live
    export CONTROL_PLANE_REHEARSAL_NETWORK=control-plane-migration-rehearsal
    export CONTROL_PLANE_EXPECTED_DATABASE_IDENTITY_SHA256="$command_identity_sha256"
    export CONTROL_PLANE_LIVE_DATABASE_SYSTEM_IDENTIFIER="$command_system_identifier"
    export CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256="$command_ledger_sha256"
    export CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256="$command_pending_sha256"
    export CONTROL_PLANE_EXPECTED_MIGRATION_BATCH="$command_expected_batch"
    export CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT=750ms
    export CONTROL_PLANE_MIGRATION_STATEMENT_TIMEOUT=30s
    export CONTROL_PLANE_MIGRATION_OPERATION_ID="$operator_migration_operation_id"
    export CONTROL_PLANE_MIGRATION_ATTEMPT="$operator_migration_attempt"
    export CONTROL_PLANE_MIGRATION_APPLICATION_NAME="$operator_migration_application_name"
    export CONTROL_PLANE_MIGRATION_ATTEMPT_LOCK_IDENTITY="$operator_migration_attempt_lock_identity"
    export CONTROL_PLANE_MIGRATION_RUNNER_NAME="$operator_migration_runner_name"
    docker compose --ansi never \
        --file "$REPOSITORY_ROOT/docker/control-plane-blue-green/compose.rehearsal.yaml" \
        config --format json > "$ARTIFACT_DIRECTORY/operator-compose.json"
)
jq -e \
    --arg live_identity "$command_identity_sha256" \
    --arg rehearsal_identity "$operator_rehearsal_identity_sha256" \
    --arg system_identifier "$command_system_identifier" \
    --arg ledger "$command_ledger_sha256" \
    --arg pending "$command_pending_sha256" \
    --arg batch "$command_expected_batch" \
    --arg operation_id "$operator_migration_operation_id" \
    --arg attempt "$operator_migration_attempt" \
    --arg application_name "$operator_migration_application_name" \
    --arg attempt_lock_identity "$operator_migration_attempt_lock_identity" \
    --arg runner_name "$operator_migration_runner_name" '
    .services["live-expand-migrations"].environment.CONTROL_PLANE_LIVE_EXPAND_MIGRATION == "true"
    and ((.services["live-expand-migrations"].environment.CONTROL_PLANE_MIGRATION_REHEARSAL // "false") == "false")
    and .services["migration-rehearsal"].environment.CONTROL_PLANE_MIGRATION_REHEARSAL == "true"
    and ((.services["migration-rehearsal"].environment.CONTROL_PLANE_LIVE_EXPAND_MIGRATION // "false") == "false")
    and .services["live-expand-migrations"].environment.CONTROL_PLANE_EXPECTED_DATABASE_IDENTITY_SHA256 == $live_identity
    and .services["migration-rehearsal"].environment.CONTROL_PLANE_EXPECTED_DATABASE_IDENTITY_SHA256 == $rehearsal_identity
    and ([.services[] | .environment.CONTROL_PLANE_LIVE_DATABASE_SYSTEM_IDENTIFIER] | all(. == $system_identifier))
    and ([.services[] | .environment.CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256] | all(. == $ledger))
    and ([.services[] | .environment.CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256] | all(. == $pending))
    and ([.services[] | .environment.CONTROL_PLANE_EXPECTED_MIGRATION_BATCH] | all(. == $batch))
    and ([.services[] | .environment.CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT] | all(. == "750ms"))
    and ([.services[] | .environment.CONTROL_PLANE_MIGRATION_STATEMENT_TIMEOUT] | all(. == "30s"))
    and .services["live-expand-migrations"].container_name == $runner_name
    and .services["live-expand-migrations"].environment.CONTROL_PLANE_MIGRATION_OPERATION_ID == $operation_id
    and .services["live-expand-migrations"].environment.CONTROL_PLANE_MIGRATION_ATTEMPT == $attempt
    and .services["live-expand-migrations"].environment.CONTROL_PLANE_MIGRATION_APPLICATION_NAME == $application_name
    and .services["live-expand-migrations"].environment.CONTROL_PLANE_MIGRATION_ATTEMPT_LOCK_IDENTITY == $attempt_lock_identity
    and .services["live-expand-migrations"].labels["io.coolify.control-plane.operation-id"] == $operation_id
    and .services["live-expand-migrations"].labels["io.coolify.control-plane.migration-attempt"] == $attempt
    and .services["live-expand-migrations"].labels["io.coolify.control-plane.pg-application-name"] == $application_name
    and .services["live-expand-migrations"].labels["io.coolify.control-plane.migration-attempt-lock"] == $attempt_lock_identity
    and ([.services[] | .command[-4:]] | all(. == ["php", "artisan", "start:migration", "--control-plane-expand"]))
    and ([.services[] | .command[0:2]] | all(. == ["exec", "env"]))
    and ([.services[] | .command[2]] | all(startswith("PGOPTIONS=-c lock_timeout=")))
' "$ARTIFACT_DIRECTORY/operator-compose.json" >/dev/null \
    || fail 'canonical operator Compose did not preserve the migration identity, mode, hash, batch, or timeout environment'
record 'OPERATOR_ENV_PLUMBING_PASS services=2 mode_flags=exclusive identity_hashes=distinct ledger=true pending=true batch=true timeouts=true actual_command=true'

clone_baseline concurrent_command
insert_immutable_baseline_surplus concurrent_command 29
psql_file concurrent_command "$LAB_DIRECTORY/seed-existing-rows.sql" > "$ARTIFACT_DIRECTORY/concurrent-command-seed.log"
capture_legacy_fixture_fingerprint concurrent_command "$ARTIFACT_DIRECTORY/concurrent-command-fingerprint-before.txt"
write_control_plane_attestation concurrent_command "$ARTIFACT_DIRECTORY/concurrent-command-initial"
concurrent_attestation="$ARTIFACT_DIRECTORY/concurrent-command-initial-attestation.env"
concurrent_identity_sha256=$(attestation_value identity_sha256 "$concurrent_attestation")
concurrent_system_identifier=$(attestation_value system_identifier "$concurrent_attestation")
concurrent_ledger_sha256=$(attestation_value ledger_sha256 "$concurrent_attestation")
concurrent_pending_sha256=$(attestation_value pending_sha256 "$concurrent_attestation")
concurrent_expected_batch=$(attestation_value expected_batch "$concurrent_attestation")

compose exec -T --env PGAPPNAME=control-plane-command-table-lock postgres \
    psql --set ON_ERROR_STOP=1 --username coolify --dbname concurrent_command \
    > "$ARTIFACT_DIRECTORY/concurrent-command-table-lock.log" 2>&1 <<'SQL' &
BEGIN;
LOCK TABLE application_settings IN ACCESS SHARE MODE;
SELECT pg_sleep(300);
COMMIT;
SQL
concurrent_table_lock_pid=$!

attempt=0
while :; do
    held_table_lock=$(psql_database concurrent_command --tuples-only --no-align --command \
        "SELECT count(*) FROM pg_locks AS held_lock JOIN pg_stat_activity AS activity ON activity.pid = held_lock.pid WHERE activity.application_name = 'control-plane-command-table-lock' AND held_lock.relation = 'application_settings'::regclass AND held_lock.mode = 'AccessShareLock' AND held_lock.granted" \
        | tr -d '[:space:]')
    [ "$held_table_lock" = 1 ] && break
    attempt=$((attempt + 1))
    [ "$attempt" -lt 30 ] || fail 'concurrent command table lock was not acquired'
    sleep 1
done

CONTROL_PLANE_COMMAND_LOCK_TIMEOUT=30s run_control_plane_command \
    concurrent_command "$ARTIFACT_DIRECTORY/concurrent-command-first.log" true false \
    "$concurrent_identity_sha256" "$concurrent_system_identifier" "$concurrent_ledger_sha256" \
    "$concurrent_pending_sha256" "$concurrent_expected_batch" &
first_control_plane_pid=$!

attempt=0
control_plane_backend_pid=
while :; do
    control_plane_backend_pid=$(psql_database concurrent_command --tuples-only --no-align --command \
        "SELECT COALESCE(min(advisory_lock.pid)::text, '') FROM pg_locks AS advisory_lock JOIN pg_locks AS waiting_table_lock ON waiting_table_lock.pid = advisory_lock.pid WHERE advisory_lock.locktype = 'advisory' AND advisory_lock.granted AND waiting_table_lock.locktype = 'relation' AND waiting_table_lock.relation = 'application_settings'::regclass AND waiting_table_lock.mode = 'AccessExclusiveLock' AND NOT waiting_table_lock.granted" \
        | tr -d '[:space:]')
    [ -n "$control_plane_backend_pid" ] && break
    attempt=$((attempt + 1))
    [ "$attempt" -lt 30 ] || fail 'first control-plane command did not hold the advisory lock and wait for DDL on one PostgreSQL session'
    sleep 1
done

if run_control_plane_command concurrent_command "$ARTIFACT_DIRECTORY/concurrent-command-controlled-contender.log" true false \
    "$concurrent_identity_sha256" "$concurrent_system_identifier" "$concurrent_ledger_sha256" \
    "$concurrent_pending_sha256" "$concurrent_expected_batch"; then
    fail 'second controlled expand unexpectedly bypassed the global advisory lock'
fi
grep -F -q 'Another schema migration holds the PostgreSQL advisory lock.' \
    "$ARTIFACT_DIRECTORY/concurrent-command-controlled-contender.log" \
    || fail 'second controlled expand failed for the wrong reason while the global lock was held'
if run_normal_startup_migration concurrent_command \
    "$ARTIFACT_DIRECTORY/concurrent-command-normal-contender.log"; then
    fail 'normal startup migration unexpectedly bypassed the controlled expand advisory lock'
fi
grep -F -q 'Another schema migration holds the PostgreSQL advisory lock.' \
    "$ARTIFACT_DIRECTORY/concurrent-command-normal-contender.log" \
    || fail 'normal startup migration failed for the wrong reason while controlled expand held the global lock'
psql_database concurrent_command --command \
    "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE application_name = 'control-plane-command-table-lock' AND pid <> pg_backend_pid()" >/dev/null
wait "$concurrent_table_lock_pid" 2>/dev/null || true
wait "$first_control_plane_pid" || fail 'first concurrent control-plane command did not complete after table-lock release'
[ "$(grep -F -c 'control-plane-schema-attestation=passed' "$ARTIFACT_DIRECTORY/concurrent-command-first.log")" = 1 ] \
    || fail 'first control-plane command did not complete exact schema attestation once'
assert_sql_scalar concurrent_command "SELECT count(*) FROM migrations WHERE migration LIKE '2026_07_12_%'" \
    "$authorized_migration_count" \
    'concurrent command authorized migration count'
capture_legacy_fixture_fingerprint concurrent_command "$ARTIFACT_DIRECTORY/concurrent-command-fingerprint-after.txt"
cmp "$ARTIFACT_DIRECTORY/concurrent-command-fingerprint-before.txt" \
    "$ARTIFACT_DIRECTORY/concurrent-command-fingerprint-after.txt" >/dev/null \
    || fail 'concurrent actual command changed legacy fixture counts or hashes'

run_normal_startup_migration concurrent_command \
    "$ARTIFACT_DIRECTORY/concurrent-command-normal-after.log" \
    || fail 'normal startup migration did not acquire the released global schema-migration lock'
record "CONCURRENT_ACTUAL_COMMAND_PASS processes=3 controlled_contender_rejected=1 normal_contender_rejected=1 same_backend_pid=$control_plane_backend_pid same_pdo=true global_lock_released=true"

clone_baseline injected_migration_failure
insert_immutable_baseline_surplus injected_migration_failure 29
psql_file injected_migration_failure "$LAB_DIRECTORY/seed-existing-rows.sql" \
    > "$ARTIFACT_DIRECTORY/injected-migration-failure-seed.log"
psql_database injected_migration_failure >/dev/null <<'SQL'
CREATE FUNCTION reject_authorized_migration() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.migration = '2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues' THEN
        RAISE EXCEPTION 'injected controlled migration failure' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER reject_authorized_migration
BEFORE INSERT ON migrations
FOR EACH ROW EXECUTE FUNCTION reject_authorized_migration();
SQL
expect_control_plane_transaction_rollback injected_migration_failure injected-migration-failure \
    'injected controlled migration failure'

clone_baseline injected_batch_drift
insert_immutable_baseline_surplus injected_batch_drift 29
psql_file injected_batch_drift "$LAB_DIRECTORY/seed-existing-rows.sql" \
    > "$ARTIFACT_DIRECTORY/injected-batch-drift-seed.log"
psql_database injected_batch_drift >/dev/null <<'SQL'
CREATE FUNCTION drift_authorized_migration_batch() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.migration = '2026_07_12_000012_add_blue_green_deactivation_proxy_snapshot' THEN
        NEW.batch := NEW.batch + 1;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER drift_authorized_migration_batch
BEFORE INSERT ON migrations
FOR EACH ROW EXECUTE FUNCTION drift_authorized_migration_batch();
SQL
expect_control_plane_transaction_rollback injected_batch_drift injected-batch-drift \
    'Control-plane migrations did not append the exact ordered authorized migration ledger batch.'

clone_baseline injected_legacy_truncate_drift
insert_immutable_baseline_surplus injected_legacy_truncate_drift 29
psql_file injected_legacy_truncate_drift "$LAB_DIRECTORY/seed-existing-rows.sql" \
    > "$ARTIFACT_DIRECTORY/injected-legacy-truncate-drift-seed.log"
assert_sql_scalar injected_legacy_truncate_drift 'SELECT count(*) FROM application_settings' 8192 \
    'legacy TRUNCATE injection nonempty-table precondition'
psql_database injected_legacy_truncate_drift >/dev/null <<'SQL'
CREATE FUNCTION drift_legacy_rows_with_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.migration = '2026_07_12_000012_add_blue_green_deactivation_proxy_snapshot' THEN
        TRUNCATE TABLE application_settings;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER drift_legacy_rows_with_truncate
BEFORE INSERT ON migrations
FOR EACH ROW EXECUTE FUNCTION drift_legacy_rows_with_truncate();
SQL
expect_control_plane_transaction_rollback injected_legacy_truncate_drift injected-legacy-truncate-drift \
    'Control-plane expand changed legacy table rows.'

clone_baseline injected_legacy_net_zero_mutation
insert_immutable_baseline_surplus injected_legacy_net_zero_mutation 29
psql_file injected_legacy_net_zero_mutation "$LAB_DIRECTORY/seed-existing-rows.sql" \
    > "$ARTIFACT_DIRECTORY/injected-legacy-net-zero-mutation-seed.log"
assert_sql_scalar injected_legacy_net_zero_mutation 'SELECT count(*) FROM application_settings' 8192 \
    'legacy net-zero mutation application-settings row-count precondition'
assert_sql_scalar injected_legacy_net_zero_mutation 'SELECT count(*) FROM application_deployment_queues' 2 \
    'legacy net-zero mutation deployment-queue row-count precondition'
psql_database injected_legacy_net_zero_mutation >/dev/null <<'SQL'
CREATE FUNCTION drift_legacy_rows_without_count_change() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.migration = '2026_07_12_000012_add_blue_green_deactivation_proxy_snapshot' THEN
        UPDATE application_deployment_queues
        SET status = status
        WHERE deployment_uuid = 'control-plane-migration-queued';

        WITH deleted_application_setting AS (
            DELETE FROM application_settings
            WHERE id = (SELECT min(id) FROM application_settings)
            RETURNING *
        )
        INSERT INTO application_settings
        SELECT * FROM deleted_application_setting;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER drift_legacy_rows_without_count_change
BEFORE INSERT ON migrations
FOR EACH ROW EXECUTE FUNCTION drift_legacy_rows_without_count_change();
SQL
expect_control_plane_transaction_rollback injected_legacy_net_zero_mutation injected-legacy-net-zero-mutation \
    'Control-plane expand changed legacy table rows.'

clone_baseline injected_schema_drift
insert_immutable_baseline_surplus injected_schema_drift 29
psql_file injected_schema_drift "$LAB_DIRECTORY/seed-existing-rows.sql" \
    > "$ARTIFACT_DIRECTORY/injected-schema-drift-seed.log"
psql_database injected_schema_drift >/dev/null <<'SQL'
CREATE FUNCTION drift_authorized_schema() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.migration = '2026_07_12_000012_add_blue_green_deactivation_proxy_snapshot' THEN
        ALTER TABLE application_blue_green_deactivations
        ADD COLUMN injected_schema_drift boolean;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER drift_authorized_schema
BEFORE INSERT ON migrations
FOR EACH ROW EXECUTE FUNCTION drift_authorized_schema();
SQL
expect_control_plane_transaction_rollback injected_schema_drift injected-schema-drift \
    'Existing blue-green deactivation PostgreSQL catalog does not match the authorized schema.'
record 'CONTROL_PLANE_ATOMIC_GATES_PASS injected_failure=true batch=true legacy_counts=true legacy_truncate=true legacy_net_zero=true schema=true outer_transaction=true'

replay_number=0
while IFS= read -r migration_name; do
    replay_number=$((replay_number + 1))
    replay_database="replay_$replay_number"
    clone_baseline "$replay_database"

    prerequisite=
    case "$migration_name" in
        2026_07_12_000010_add_blue_green_deactivation_provenance)
            prerequisite=2026_07_12_000001_create_application_blue_green_deployments_table
            ;;
        2026_07_12_000012_add_blue_green_deactivation_proxy_snapshot)
            prerequisite=2026_07_12_000011_create_application_blue_green_deactivations_table
            ;;
    esac
    if [ -n "$prerequisite" ]; then
        run_artisan_migrations "$replay_database" "$ARTIFACT_DIRECTORY/${replay_database}-prerequisite.log" \
            "$(migration_path_argument "$prerequisite")" \
            || fail 'commit-without-ledger prerequisite migration failed'
    fi

    run_without_ledger "$replay_database" "$migration_name" "$ARTIFACT_DIRECTORY/${replay_database}-schema-commit.log" \
        || fail "schema commit without ledger failed for $migration_name"
    assert_sql_scalar "$replay_database" "SELECT count(*) FROM migrations WHERE migration = '$migration_name'" 0 \
        "schema-only commit unexpectedly logged $migration_name"
    run_artisan_migrations "$replay_database" "$ARTIFACT_DIRECTORY/${replay_database}-replay.log" \
        "$(migration_path_argument "$migration_name")" \
        || fail "convergent Artisan replay failed for $migration_name"
    assert_sql_scalar "$replay_database" "SELECT count(*) FROM migrations WHERE migration = '$migration_name'" 1 \
        "convergent replay ledger count for $migration_name"
done < "$CONTROL_PLANE_MIGRATION_NAMES_FILE"
record "COMMIT_WITHOUT_LEDGER_REPLAY_PASS migrations=$authorized_migration_count"

final_replay_number=0
while IFS= read -r migration_name <&3; do
    final_replay_number=$((final_replay_number + 1))
    final_replay_database="final_replay_$final_replay_number"
    clone_database normal_run "$final_replay_database"
    psql_database "$final_replay_database" --command \
        "DELETE FROM migrations WHERE migration = '$migration_name'" >/dev/null
    dump_schema "$final_replay_database" \
        "$ARTIFACT_DIRECTORY/${final_replay_database}-schema-before.sql"
    capture_legacy_fixture_fingerprint "$final_replay_database" \
        "$ARTIFACT_DIRECTORY/${final_replay_database}-fingerprint-before.txt"
    run_artisan_migrations "$final_replay_database" \
        "$ARTIFACT_DIRECTORY/${final_replay_database}.log" \
        "$(migration_path_argument "$migration_name")" \
        || fail "final-schema replay failed for $migration_name"
    assert_sql_scalar "$final_replay_database" \
        "SELECT count(*) FROM migrations WHERE migration = '$migration_name'" 1 \
        "final-schema replay ledger count for $migration_name"
    dump_schema "$final_replay_database" \
        "$ARTIFACT_DIRECTORY/${final_replay_database}-schema-after.sql"
    capture_legacy_fixture_fingerprint "$final_replay_database" \
        "$ARTIFACT_DIRECTORY/${final_replay_database}-fingerprint-after.txt"
    cmp "$ARTIFACT_DIRECTORY/${final_replay_database}-schema-before.sql" \
        "$ARTIFACT_DIRECTORY/${final_replay_database}-schema-after.sql" >/dev/null \
        || fail "final-schema replay changed schema for $migration_name"
    cmp "$ARTIFACT_DIRECTORY/${final_replay_database}-fingerprint-before.txt" \
        "$ARTIFACT_DIRECTORY/${final_replay_database}-fingerprint-after.txt" >/dev/null \
        || fail "final-schema replay changed legacy data for $migration_name"
    FINAL_SCHEMA_REPLAYED=$((FINAL_SCHEMA_REPLAYED + 1))
done 3< "$CONTROL_PLANE_MIGRATION_NAMES_FILE"
record "FINAL_SCHEMA_REPLAY_PASS migrations=$FINAL_SCHEMA_REPLAYED schema_unchanged=true legacy_data_unchanged=true"

rollback_number=0
while IFS= read -r migration_name <&3; do
    rollback_number=$((rollback_number + 1))
    rollback_database="rollback_$rollback_number"
    clone_database normal_run "$rollback_database"
    rollback_log="$ARTIFACT_DIRECTORY/${rollback_database}.log"
    dump_schema "$rollback_database" "$ARTIFACT_DIRECTORY/${rollback_database}-schema-before.sql"
    capture_ledger_rows "$rollback_database" "$ARTIFACT_DIRECTORY/${rollback_database}-ledger-before.csv"
    capture_legacy_fixture_fingerprint "$rollback_database" \
        "$ARTIFACT_DIRECTORY/${rollback_database}-fingerprint-before.txt"

    if run_php "$rollback_database" /workspace artisan migrate:rollback --force --no-interaction \
        "$(migration_path_argument "$migration_name")" > "$rollback_log" 2>&1; then
        fail "forward-only rollback unexpectedly succeeded for $migration_name"
    fi
    grep -F -q -- "Control-plane expand migration is forward-only: $migration_name." "$rollback_log" \
        || fail "forward-only rollback failed with the wrong exception for $migration_name"

    dump_schema "$rollback_database" "$ARTIFACT_DIRECTORY/${rollback_database}-schema-after.sql"
    capture_ledger_rows "$rollback_database" "$ARTIFACT_DIRECTORY/${rollback_database}-ledger-after.csv"
    capture_legacy_fixture_fingerprint "$rollback_database" \
        "$ARTIFACT_DIRECTORY/${rollback_database}-fingerprint-after.txt"
    cmp "$ARTIFACT_DIRECTORY/${rollback_database}-schema-before.sql" \
        "$ARTIFACT_DIRECTORY/${rollback_database}-schema-after.sql" >/dev/null \
        || fail "forward-only rollback changed schema for $migration_name"
    cmp "$ARTIFACT_DIRECTORY/${rollback_database}-ledger-before.csv" \
        "$ARTIFACT_DIRECTORY/${rollback_database}-ledger-after.csv" >/dev/null \
        || fail "forward-only rollback changed ledger for $migration_name"
    cmp "$ARTIFACT_DIRECTORY/${rollback_database}-fingerprint-before.txt" \
        "$ARTIFACT_DIRECTORY/${rollback_database}-fingerprint-after.txt" >/dev/null \
        || fail "forward-only rollback changed legacy data for $migration_name"
    FORWARD_ONLY_ROLLBACK_REJECTED=$((FORWARD_ONLY_ROLLBACK_REJECTED + 1))
done 3< "$CONTROL_PLANE_MIGRATION_NAMES_FILE"
record "FORWARD_ONLY_ROLLBACK_PASS rejected=$FORWARD_ONLY_ROLLBACK_REJECTED ledger_unchanged=true schema_unchanged=true legacy_data_unchanged=true"

clone_baseline malformed_setting
psql_database malformed_setting --command \
    'ALTER TABLE application_settings ADD COLUMN is_blue_green_deployment_enabled boolean NOT NULL DEFAULT true' >/dev/null
expect_migration_failure malformed_setting \
    2026_07_12_000000_add_blue_green_deployment_setting_to_application_settings \
    malformed-setting-default \
    'Existing blue-green deployment setting does not match the authorized PostgreSQL catalog.'

clone_baseline malformed_deployment_table
psql_database malformed_deployment_table --command \
    'CREATE TABLE application_blue_green_deployments (id bigint PRIMARY KEY)' >/dev/null
expect_migration_failure malformed_deployment_table \
    2026_07_12_000001_create_application_blue_green_deployments_table \
    malformed-deployment-table \
    'Existing blue-green deployment PostgreSQL catalog does not match the authorized schema.'

clone_baseline partial_queue
psql_database partial_queue --command \
    'ALTER TABLE application_deployment_queues ADD COLUMN blue_green_color varchar(255)' >/dev/null
expect_migration_failure partial_queue \
    2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues \
    partial-queue-columns \
    'Queue provenance schema is partial; refusing a non-convergent replay.'

clone_baseline partial_deactivation_provenance
run_artisan_migrations partial_deactivation_provenance "$ARTIFACT_DIRECTORY/partial-deactivation-prerequisite.log" \
    "$(migration_path_argument 2026_07_12_000001_create_application_blue_green_deployments_table)" \
    || fail 'partial-deactivation prerequisite failed'
psql_database partial_deactivation_provenance --command \
    'ALTER TABLE application_blue_green_deployments ADD COLUMN deactivation_operation_id varchar(64)' >/dev/null
expect_migration_failure partial_deactivation_provenance \
    2026_07_12_000010_add_blue_green_deactivation_provenance \
    partial-deactivation-columns \
    'Deactivation provenance schema is partial; refusing a non-convergent replay.'

clone_baseline malformed_deactivation_table
psql_database malformed_deactivation_table --command \
    'CREATE TABLE application_blue_green_deactivations (id bigint PRIMARY KEY)' >/dev/null
expect_migration_failure malformed_deactivation_table \
    2026_07_12_000011_create_application_blue_green_deactivations_table \
    malformed-deactivation-table \
    'Existing blue-green deactivation PostgreSQL catalog does not match the authorized schema.'

clone_baseline malformed_deactivation_proxy_snapshot
run_artisan_migrations malformed_deactivation_proxy_snapshot \
    "$ARTIFACT_DIRECTORY/malformed-deactivation-proxy-snapshot-setup.log" \
    "$(migration_path_argument 2026_07_12_000011_create_application_blue_green_deactivations_table)" \
    || fail 'deactivation proxy snapshot setup failed'
psql_database malformed_deactivation_proxy_snapshot --command \
    'ALTER TABLE application_blue_green_deactivations ADD COLUMN proxy_snapshot varchar(255)' >/dev/null
expect_migration_failure malformed_deactivation_proxy_snapshot \
    2026_07_12_000012_add_blue_green_deactivation_proxy_snapshot \
    malformed-deactivation-proxy-snapshot \
    'Existing blue-green deactivation proxy snapshot column does not match.'

clone_baseline malformed_deployment_typmod
run_artisan_migrations malformed_deployment_typmod "$ARTIFACT_DIRECTORY/malformed-deployment-typmod-setup.log" \
    "$(migration_path_argument 2026_07_12_000001_create_application_blue_green_deployments_table)" \
    || fail 'deployment typmod setup failed'
psql_database malformed_deployment_typmod --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000001_create_application_blue_green_deployments_table'; ALTER TABLE application_blue_green_deployments ALTER COLUMN operation_previous_container_id TYPE varchar(63)" >/dev/null
expect_migration_failure malformed_deployment_typmod \
    2026_07_12_000001_create_application_blue_green_deployments_table \
    malformed-deployment-typmod \
    'Existing blue-green deployment PostgreSQL catalog does not match the authorized schema.'

clone_baseline malformed_queue_typmod
run_artisan_migrations malformed_queue_typmod "$ARTIFACT_DIRECTORY/malformed-queue-typmod-setup.log" \
    "$(migration_path_argument 2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues)" \
    || fail 'queue typmod setup failed'
psql_database malformed_queue_typmod --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues'; ALTER TABLE application_deployment_queues ALTER COLUMN blue_green_previous_container_id TYPE varchar(63)" >/dev/null
expect_migration_failure malformed_queue_typmod \
    2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues \
    malformed-queue-typmod \
    'Existing queue provenance columns do not match the authorized PostgreSQL catalog.'

clone_baseline malformed_deactivation_typmod
run_artisan_migrations malformed_deactivation_typmod "$ARTIFACT_DIRECTORY/malformed-deactivation-typmod-prerequisite.log" \
    "$(migration_path_argument 2026_07_12_000001_create_application_blue_green_deployments_table)" \
    || fail 'deactivation typmod prerequisite failed'
run_artisan_migrations malformed_deactivation_typmod "$ARTIFACT_DIRECTORY/malformed-deactivation-typmod-setup.log" \
    "$(migration_path_argument 2026_07_12_000010_add_blue_green_deactivation_provenance)" \
    || fail 'deactivation typmod setup failed'
psql_database malformed_deactivation_typmod --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000010_add_blue_green_deactivation_provenance'; ALTER TABLE application_blue_green_deployments ALTER COLUMN deactivation_operation_id TYPE varchar(63)" >/dev/null
expect_migration_failure malformed_deactivation_typmod \
    2026_07_12_000010_add_blue_green_deactivation_provenance \
    malformed-deactivation-provenance-typmod \
    'Existing deactivation provenance columns do not match the authorized PostgreSQL catalog.'

clone_baseline malformed_deactivation_table_typmod
run_artisan_migrations malformed_deactivation_table_typmod "$ARTIFACT_DIRECTORY/malformed-deactivation-table-typmod-setup.log" \
    "$(migration_path_argument 2026_07_12_000011_create_application_blue_green_deactivations_table)" \
    || fail 'deactivation table typmod setup failed'
psql_database malformed_deactivation_table_typmod --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000011_create_application_blue_green_deactivations_table'; ALTER TABLE application_blue_green_deactivations ALTER COLUMN operation_id TYPE varchar(63)" >/dev/null
expect_migration_failure malformed_deactivation_table_typmod \
    2026_07_12_000011_create_application_blue_green_deactivations_table \
    malformed-deactivation-table-typmod \
    'Existing blue-green deactivation PostgreSQL catalog does not match the authorized schema.'

clone_baseline malformed_deployment_sequence
run_artisan_migrations malformed_deployment_sequence "$ARTIFACT_DIRECTORY/malformed-deployment-sequence-setup.log" \
    "$(migration_path_argument 2026_07_12_000001_create_application_blue_green_deployments_table)" \
    || fail 'deployment sequence setup failed'
psql_database malformed_deployment_sequence --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000001_create_application_blue_green_deployments_table'; ALTER SEQUENCE application_blue_green_deployments_id_seq OWNED BY NONE" >/dev/null
expect_migration_failure malformed_deployment_sequence \
    2026_07_12_000001_create_application_blue_green_deployments_table \
    malformed-deployment-sequence-ownership \
    'Existing blue-green deployment ID sequence ownership/default binding does not match.'

clone_baseline malformed_deactivation_sequence
run_artisan_migrations malformed_deactivation_sequence "$ARTIFACT_DIRECTORY/malformed-deactivation-sequence-setup.log" \
    "$(migration_path_argument 2026_07_12_000011_create_application_blue_green_deactivations_table)" \
    || fail 'deactivation sequence setup failed'
psql_database malformed_deactivation_sequence --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000011_create_application_blue_green_deactivations_table'; ALTER SEQUENCE application_blue_green_deactivations_id_seq OWNED BY NONE" >/dev/null
expect_migration_failure malformed_deactivation_sequence \
    2026_07_12_000011_create_application_blue_green_deactivations_table \
    malformed-deactivation-sequence-ownership \
    'Existing blue-green deactivation ID sequence ownership/default binding does not match.'

clone_baseline malformed_deployment_sequence_binding
run_artisan_migrations malformed_deployment_sequence_binding "$ARTIFACT_DIRECTORY/malformed-deployment-sequence-binding-setup.log" \
    "$(migration_path_argument 2026_07_12_000001_create_application_blue_green_deployments_table)" \
    || fail 'deployment sequence binding setup failed'
psql_database malformed_deployment_sequence_binding --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000001_create_application_blue_green_deployments_table'; ALTER SEQUENCE application_blue_green_deployments_id_seq OWNED BY NONE; CREATE SEQUENCE application_blue_green_deployments_decoy_seq; ALTER SEQUENCE application_blue_green_deployments_decoy_seq OWNED BY application_blue_green_deployments.id" >/dev/null
expect_migration_failure malformed_deployment_sequence_binding \
    2026_07_12_000001_create_application_blue_green_deployments_table \
    malformed-deployment-sequence-binding \
    'Existing blue-green deployment ID sequence ownership/default binding does not match.'

clone_baseline malformed_deactivation_sequence_binding
run_artisan_migrations malformed_deactivation_sequence_binding "$ARTIFACT_DIRECTORY/malformed-deactivation-sequence-binding-setup.log" \
    "$(migration_path_argument 2026_07_12_000011_create_application_blue_green_deactivations_table)" \
    || fail 'deactivation sequence binding setup failed'
psql_database malformed_deactivation_sequence_binding --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000011_create_application_blue_green_deactivations_table'; ALTER SEQUENCE application_blue_green_deactivations_id_seq OWNED BY NONE; CREATE SEQUENCE application_blue_green_deactivations_decoy_seq; ALTER SEQUENCE application_blue_green_deactivations_decoy_seq OWNED BY application_blue_green_deactivations.id" >/dev/null
expect_migration_failure malformed_deactivation_sequence_binding \
    2026_07_12_000011_create_application_blue_green_deactivations_table \
    malformed-deactivation-sequence-binding \
    'Existing blue-green deactivation ID sequence ownership/default binding does not match.'

clone_baseline malformed_setting_generated
psql_database malformed_setting_generated --command \
    'ALTER TABLE application_settings ADD COLUMN is_blue_green_deployment_enabled boolean GENERATED ALWAYS AS (false) STORED' >/dev/null
expect_migration_failure malformed_setting_generated \
    2026_07_12_000000_add_blue_green_deployment_setting_to_application_settings \
    malformed-setting-generated \
    'Existing blue-green deployment setting does not match the authorized PostgreSQL catalog.'

clone_baseline malformed_deployment_default
run_artisan_migrations malformed_deployment_default "$ARTIFACT_DIRECTORY/malformed-deployment-default-setup.log" \
    "$(migration_path_argument 2026_07_12_000001_create_application_blue_green_deployments_table)" \
    || fail 'deployment default setup failed'
psql_database malformed_deployment_default --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000001_create_application_blue_green_deployments_table'; ALTER TABLE application_blue_green_deployments ALTER COLUMN phase SET DEFAULT 'standby'" >/dev/null
expect_migration_failure malformed_deployment_default \
    2026_07_12_000001_create_application_blue_green_deployments_table \
    malformed-deployment-default \
    'Existing blue-green deployment PostgreSQL catalog does not match the authorized schema.'

clone_baseline malformed_deployment_equivalent_default
run_artisan_migrations malformed_deployment_equivalent_default \
    "$ARTIFACT_DIRECTORY/malformed-deployment-equivalent-default-setup.log" \
    "$(migration_path_argument 2026_07_12_000001_create_application_blue_green_deployments_table)" \
    || fail 'deployment equivalent-default setup failed'
psql_database malformed_deployment_equivalent_default --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000001_create_application_blue_green_deployments_table'; ALTER TABLE application_blue_green_deployments ALTER COLUMN routing_revision SET DEFAULT (0::numeric)::bigint" >/dev/null
expect_migration_failure malformed_deployment_equivalent_default \
    2026_07_12_000001_create_application_blue_green_deployments_table \
    malformed-deployment-equivalent-default \
    'Existing blue-green deployment PostgreSQL catalog does not match the authorized schema.'

clone_baseline malformed_deployment_generated
run_artisan_migrations malformed_deployment_generated "$ARTIFACT_DIRECTORY/malformed-deployment-generated-setup.log" \
    "$(migration_path_argument 2026_07_12_000001_create_application_blue_green_deployments_table)" \
    || fail 'deployment generated-column setup failed'
psql_database malformed_deployment_generated --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000001_create_application_blue_green_deployments_table'; ALTER TABLE application_blue_green_deployments DROP COLUMN active_color; ALTER TABLE application_blue_green_deployments ADD COLUMN active_color varchar(255) GENERATED ALWAYS AS ('blue'::varchar) STORED" >/dev/null
expect_migration_failure malformed_deployment_generated \
    2026_07_12_000001_create_application_blue_green_deployments_table \
    malformed-deployment-generated \
    'Existing blue-green deployment PostgreSQL catalog does not match the authorized schema.'

clone_baseline malformed_deployment_index
run_artisan_migrations malformed_deployment_index "$ARTIFACT_DIRECTORY/malformed-deployment-index-setup.log" \
    "$(migration_path_argument 2026_07_12_000001_create_application_blue_green_deployments_table)" \
    || fail 'deployment index setup failed'
psql_database malformed_deployment_index --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000001_create_application_blue_green_deployments_table'; DROP INDEX app_blue_green_standalone_docker_index" >/dev/null
expect_migration_failure malformed_deployment_index \
    2026_07_12_000001_create_application_blue_green_deployments_table \
    malformed-deployment-index \
    'Existing blue-green deployment PostgreSQL catalog does not match the authorized schema.'

clone_baseline malformed_deployment_fk
run_artisan_migrations malformed_deployment_fk "$ARTIFACT_DIRECTORY/malformed-deployment-fk-setup.log" \
    "$(migration_path_argument 2026_07_12_000001_create_application_blue_green_deployments_table)" \
    || fail 'deployment foreign-key setup failed'
psql_database malformed_deployment_fk --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000001_create_application_blue_green_deployments_table'; ALTER TABLE application_blue_green_deployments DROP CONSTRAINT application_blue_green_deployments_application_id_foreign; ALTER TABLE application_blue_green_deployments ADD CONSTRAINT application_blue_green_deployments_application_id_foreign FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE NO ACTION" >/dev/null
expect_migration_failure malformed_deployment_fk \
    2026_07_12_000001_create_application_blue_green_deployments_table \
    malformed-deployment-foreign-key \
    'Existing blue-green deployment PostgreSQL catalog does not match the authorized schema.'

clone_baseline malformed_queue_default
run_artisan_migrations malformed_queue_default "$ARTIFACT_DIRECTORY/malformed-queue-default-setup.log" \
    "$(migration_path_argument 2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues)" \
    || fail 'queue default setup failed'
psql_database malformed_queue_default --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues'; ALTER TABLE application_deployment_queues ALTER COLUMN blue_green_color SET DEFAULT 'blue'" >/dev/null
expect_migration_failure malformed_queue_default \
    2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues \
    malformed-queue-default \
    'Existing queue provenance columns do not match the authorized PostgreSQL catalog.'

clone_baseline malformed_queue_generated
run_artisan_migrations malformed_queue_generated "$ARTIFACT_DIRECTORY/malformed-queue-generated-setup.log" \
    "$(migration_path_argument 2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues)" \
    || fail 'queue generated-column setup failed'
psql_database malformed_queue_generated --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues'; ALTER TABLE application_deployment_queues DROP COLUMN blue_green_color; ALTER TABLE application_deployment_queues ADD COLUMN blue_green_color varchar(255) GENERATED ALWAYS AS ('blue'::varchar) STORED" >/dev/null
expect_migration_failure malformed_queue_generated \
    2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues \
    malformed-queue-generated \
    'Existing queue provenance columns do not match the authorized PostgreSQL catalog.'

clone_baseline malformed_deactivation_provenance_generated
run_artisan_migrations malformed_deactivation_provenance_generated \
    "$ARTIFACT_DIRECTORY/malformed-deactivation-provenance-generated-prerequisite.log" \
    "$(migration_path_argument 2026_07_12_000001_create_application_blue_green_deployments_table)" \
    || fail 'deactivation provenance generated-column prerequisite failed'
run_artisan_migrations malformed_deactivation_provenance_generated \
    "$ARTIFACT_DIRECTORY/malformed-deactivation-provenance-generated-setup.log" \
    "$(migration_path_argument 2026_07_12_000010_add_blue_green_deactivation_provenance)" \
    || fail 'deactivation provenance generated-column setup failed'
psql_database malformed_deactivation_provenance_generated --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000010_add_blue_green_deactivation_provenance'; ALTER TABLE application_blue_green_deployments DROP COLUMN deactivation_operation_id; ALTER TABLE application_blue_green_deployments ADD COLUMN deactivation_operation_id varchar(64) GENERATED ALWAYS AS ('operation'::varchar) STORED" >/dev/null
expect_migration_failure malformed_deactivation_provenance_generated \
    2026_07_12_000010_add_blue_green_deactivation_provenance \
    malformed-deactivation-provenance-generated \
    'Existing deactivation provenance columns do not match the authorized PostgreSQL catalog.'

clone_baseline malformed_deactivation_default
run_artisan_migrations malformed_deactivation_default "$ARTIFACT_DIRECTORY/malformed-deactivation-default-setup.log" \
    "$(migration_path_argument 2026_07_12_000011_create_application_blue_green_deactivations_table)" \
    || fail 'deactivation default setup failed'
psql_database malformed_deactivation_default --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000011_create_application_blue_green_deactivations_table'; ALTER TABLE application_blue_green_deactivations ALTER COLUMN phase SET DEFAULT 'idle'" >/dev/null
expect_migration_failure malformed_deactivation_default \
    2026_07_12_000011_create_application_blue_green_deactivations_table \
    malformed-deactivation-default \
    'Existing blue-green deactivation PostgreSQL catalog does not match the authorized schema.'

clone_baseline malformed_deactivation_generated
run_artisan_migrations malformed_deactivation_generated "$ARTIFACT_DIRECTORY/malformed-deactivation-generated-setup.log" \
    "$(migration_path_argument 2026_07_12_000011_create_application_blue_green_deactivations_table)" \
    || fail 'deactivation generated-column setup failed'
psql_database malformed_deactivation_generated --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000011_create_application_blue_green_deactivations_table'; ALTER TABLE application_blue_green_deactivations DROP COLUMN completed_at; ALTER TABLE application_blue_green_deactivations ADD COLUMN completed_at timestamp(0) GENERATED ALWAYS AS ('2026-07-12 20:52:00'::timestamp) STORED" >/dev/null
expect_migration_failure malformed_deactivation_generated \
    2026_07_12_000011_create_application_blue_green_deactivations_table \
    malformed-deactivation-generated \
    'Existing blue-green deactivation PostgreSQL catalog does not match the authorized schema.'

clone_baseline malformed_deactivation_index
run_artisan_migrations malformed_deactivation_index "$ARTIFACT_DIRECTORY/malformed-deactivation-index-setup.log" \
    "$(migration_path_argument 2026_07_12_000011_create_application_blue_green_deactivations_table)" \
    || fail 'deactivation index setup failed'
psql_database malformed_deactivation_index --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000011_create_application_blue_green_deactivations_table'; DROP INDEX app_blue_green_deactivation_phase_index" >/dev/null
expect_migration_failure malformed_deactivation_index \
    2026_07_12_000011_create_application_blue_green_deactivations_table \
    malformed-deactivation-index \
    'Existing blue-green deactivation PostgreSQL catalog does not match the authorized schema.'

clone_baseline malformed_deactivation_fk
run_artisan_migrations malformed_deactivation_fk "$ARTIFACT_DIRECTORY/malformed-deactivation-fk-setup.log" \
    "$(migration_path_argument 2026_07_12_000011_create_application_blue_green_deactivations_table)" \
    || fail 'deactivation foreign-key setup failed'
psql_database malformed_deactivation_fk --command \
    "DELETE FROM migrations WHERE migration = '2026_07_12_000011_create_application_blue_green_deactivations_table'; ALTER TABLE application_blue_green_deactivations DROP CONSTRAINT application_blue_green_deactivations_standalone_docker_id_forei; ALTER TABLE application_blue_green_deactivations ADD CONSTRAINT application_blue_green_deactivations_standalone_docker_id_forei FOREIGN KEY (standalone_docker_id) REFERENCES standalone_dockers(id) ON DELETE NO ACTION" >/dev/null
expect_migration_failure malformed_deactivation_fk \
    2026_07_12_000011_create_application_blue_green_deactivations_table \
    malformed-deactivation-foreign-key \
    'Existing blue-green deactivation PostgreSQL catalog does not match the authorized schema.'

record "MALFORMED_CATALOG_MATRIX_PASS rejected=$MALFORMED_REJECTED accepted=0 exact_exception=true ledger_absent=true schema_unchanged=true categories=catalog,default,generated,index,foreign-key"

clone_baseline lock_contention
compose exec -T --env PGAPPNAME=control-plane-migration-lock-holder postgres \
    psql --set ON_ERROR_STOP=1 --username coolify --dbname lock_contention \
    > "$ARTIFACT_DIRECTORY/lock-holder.log" 2>&1 <<'SQL' &
BEGIN;
LOCK TABLE application_settings IN ACCESS SHARE MODE;
SELECT pg_sleep(300);
COMMIT;
SQL
lock_holder_pid=$!

attempt=0
while :; do
    held_lock_count=$(psql_database lock_contention --tuples-only --no-align --command \
        "SELECT count(*) FROM pg_locks AS held_lock JOIN pg_stat_activity AS activity ON activity.pid = held_lock.pid WHERE activity.application_name = 'control-plane-migration-lock-holder' AND held_lock.relation = 'application_settings'::regclass AND held_lock.mode = 'AccessShareLock' AND held_lock.granted" \
        | tr -d '[:space:]')
    [ "$held_lock_count" = 1 ] && break
    attempt=$((attempt + 1))
    [ "$attempt" -lt 30 ] || fail 'lock holder did not acquire the application_settings lock'
    sleep 1
done

if run_artisan_migrations_with_timeouts lock_contention "$ARTIFACT_DIRECTORY/lock-contention-failure.log" \
    "$(migration_path_argument 2026_07_12_000000_add_blue_green_deployment_setting_to_application_settings)"; then
    fail 'migration unexpectedly succeeded while an incompatible table lock was held'
fi
grep -Fq 'SQLSTATE[55P03]' "$ARTIFACT_DIRECTORY/lock-contention-failure.log" \
    || fail 'lock contention did not surface SQLSTATE 55P03'
psql_database lock_contention --command \
    "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE application_name = 'control-plane-migration-lock-holder' AND pid <> pg_backend_pid()" >/dev/null
wait "$lock_holder_pid" 2>/dev/null || true
assert_sql_scalar lock_contention \
    "SELECT count(*) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'application_settings' AND column_name = 'is_blue_green_deployment_enabled'" \
    0 'lock-failed migration schema rollback'
assert_sql_scalar lock_contention \
    "SELECT count(*) FROM migrations WHERE migration = '2026_07_12_000000_add_blue_green_deployment_setting_to_application_settings'" \
    0 'lock-failed migration ledger rollback'
run_artisan_migrations_with_timeouts lock_contention "$ARTIFACT_DIRECTORY/lock-contention-resume.log" \
    "$(migration_path_argument 2026_07_12_000000_add_blue_green_deployment_setting_to_application_settings)" \
    || fail 'migration did not resume after lock contention cleared'
record 'LOCK_CONTENTION_PASS lock_timeout=750ms statement_timeout=5s sqlstate=55P03 resumed=true'

capture_input_manifest "$ARTIFACT_DIRECTORY/input-manifest-end.sha256"
cmp "$ARTIFACT_DIRECTORY/input-manifest-start.sha256" \
    "$ARTIFACT_DIRECTORY/input-manifest-end.sha256" >/dev/null \
    || fail 'runtime, migration, dependency, operator, or lab inputs changed during acceptance'
record "INPUT_MANIFEST_STABLE runtime=true migrations=$current_migration_count dependencies=true operator=true lab=true"

[ "$COMMAND_GUARD_REJECTED" -eq 11 ] \
    || fail "actual control-plane command guard count changed: $COMMAND_GUARD_REJECTED"
[ "$FINAL_SCHEMA_REPLAYED" -eq "$authorized_migration_count" ] \
    || fail "final-schema replay count changed: $FINAL_SCHEMA_REPLAYED"
[ "$FORWARD_ONLY_ROLLBACK_REJECTED" -eq "$authorized_migration_count" ] \
    || fail "forward-only rollback rejection count changed: $FORWARD_ONLY_ROLLBACK_REJECTED"
[ "$TRANSACTION_ROLLBACK_REJECTED" -eq 5 ] \
    || fail "transaction rollback rejection count changed: $TRANSACTION_ROLLBACK_REJECTED"
[ "$MALFORMED_REJECTED" -eq 27 ] \
    || fail "malformed catalog rejection count changed: $MALFORMED_REJECTED"

if [ "$GATE_FAILURES" -ne 0 ]; then
    fail "acceptance accumulated $GATE_FAILURES gate failures"
fi

record "ACCEPTANCE_COUNTS command_guards=11 concurrent_processes=3 transaction_rollbacks=$TRANSACTION_ROLLBACK_REJECTED final_schema_replays=$authorized_migration_count crash_replays=$authorized_migration_count rollback_rejections=$authorized_migration_count malformed_rejections=27 malformed_acceptances=0 operator_services=2"
record 'CONTROL_PLANE_MIGRATION_PASS'
