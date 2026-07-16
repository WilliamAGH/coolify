#!/bin/sh

set -eu

MIGRATION_DIRECTORY=/var/www/html/database/migrations
MIGRATION_BASELINE_SURPLUS_INVENTORY="$MIGRATION_DIRECTORY/control-plane-immutable-baseline-surplus.list"

case "${PGOPTIONS:-}" in
    *'-c lock_timeout='*' -c statement_timeout='*)
        ;;
    *)
        exit 64
        ;;
esac

[ "${CONTROL_PLANE_LAB_FAIL_MIGRATION:-0}" != 1 ] || exit 70

if [ "${CONTROL_PLANE_LAB_MIGRATION_DELAY_SECONDS:-0}" != 0 ]; then
    sleep "${CONTROL_PLANE_LAB_MIGRATION_DELAY_SECONDS}"
fi
if [ -n "${CONTROL_PLANE_LAB_MIGRATION_GATE_FILE:-}" ]; then
    printf '%s\n' ready > "${CONTROL_PLANE_LAB_MIGRATION_GATE_FILE}.ready"
    while [ ! -e "${CONTROL_PLANE_LAB_MIGRATION_GATE_FILE}.release" ]; do
        sleep 0.1
    done
fi

attest_database_identity()
{
    identity_mode=$1
    actual_system_identifier=$(psql --tuples-only --no-align --command \
        'SELECT system_identifier::text FROM pg_control_system()' | tr -d '[:space:]')
    actual_database_name=$(psql --tuples-only --no-align --command 'SELECT current_database()' | tr -d '[:space:]')
    actual_server_address=$(psql --tuples-only --no-align --command 'SELECT host(inet_server_addr())' | tr -d '[:space:]')
    actual_server_port=$(psql --tuples-only --no-align --command 'SELECT inet_server_port()' | tr -d '[:space:]')
    actual_database_oid=$(psql --tuples-only --no-align --command \
        'SELECT oid::text FROM pg_database WHERE datname = current_database()' | tr -d '[:space:]')
    actual_instance_marker=$(printf '%s' "${actual_system_identifier}:${actual_database_oid}" \
        | sha256sum | awk '{print $1}')
    actual_identity_payload="system_identifier=${actual_system_identifier};database=${actual_database_name};server_address=${actual_server_address};server_port=${actual_server_port};instance_marker=${actual_instance_marker}"
    actual_identity_sha256=$(printf '%s' "$actual_identity_payload" | sha256sum | awk '{print $1}')
    printf 'control-plane-database-identity=%s\n' "$actual_identity_payload"
    printf 'control-plane-database-identity-sha256=%s\n' "$actual_identity_sha256"
    [ "$actual_identity_sha256" = "${CONTROL_PLANE_EXPECTED_DATABASE_IDENTITY_SHA256:-}" ] || exit 65
    case "$identity_mode" in
        live)
            [ "$actual_system_identifier" = "${CONTROL_PLANE_LIVE_DATABASE_SYSTEM_IDENTIFIER:-}" ] || exit 65
            ;;
        rehearsal)
            [ "$actual_system_identifier" != "${CONTROL_PLANE_LIVE_DATABASE_SYSTEM_IDENTIFIER:-}" ] || exit 65
            ;;
        *)
            exit 64
            ;;
    esac
}

attest_migration_ledger()
{
    ledger_file=$(mktemp)
    pending_file=$(mktemp)
    candidate_file=$(mktemp)
    ordered_surplus_inventory_file=$(mktemp)
    trap 'rm -f "$ledger_file" "$pending_file" "$candidate_file" "$ordered_surplus_inventory_file"' EXIT HUP INT TERM
    php85 /usr/local/bin/run-control-plane-migrations.php --validate-migration-inventory \
        > "$candidate_file" || exit 66
    [ -f "$MIGRATION_BASELINE_SURPLUS_INVENTORY" ] \
        && [ ! -L "$MIGRATION_BASELINE_SURPLUS_INVENTORY" ] \
        && grep -Eq '^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+$' \
            "$MIGRATION_BASELINE_SURPLUS_INVENTORY" \
        || exit 66
    LC_ALL=C sort -u "$MIGRATION_BASELINE_SURPLUS_INVENTORY" > "$ordered_surplus_inventory_file"
    cmp -s "$MIGRATION_BASELINE_SURPLUS_INVENTORY" "$ordered_surplus_inventory_file" || exit 66
    psql --tuples-only --no-align --quiet --command \
        "COPY (SELECT migration, batch FROM migrations ORDER BY migration) TO STDOUT WITH (FORMAT csv)" \
        > "$ledger_file"
    awk -F, \
        -v candidate_file="$candidate_file" \
        -v surplus_file="$MIGRATION_DIRECTORY/control-plane-immutable-baseline-surplus.list" \
        -v ledger_file="$ledger_file" '
        FILENAME == candidate_file {
            if (NF != 1 || $1 == "" || candidate[$1]++) { exit 1 }
            next
        }
        FILENAME == surplus_file {
            if (NF != 1 || $1 == "" || surplus[$1]++ || ($1 in candidate)) { exit 1 }
            next
        }
        FILENAME == ledger_file {
            if (NF != 2 || $1 == "" || $2 !~ /^[1-9][0-9]*$/ || ledger[$1]++) { exit 1 }
            if (!($1 in candidate)) {
                if (!($1 in surplus)) { exit 1 }
                observed_surplus[$1]++
            }
            next
        }
        END {
            for (migration in surplus) {
                if (observed_surplus[migration] != 1) { exit 1 }
            }
        }
    ' "$candidate_file" \
        "$MIGRATION_BASELINE_SURPLUS_INVENTORY" \
        "$ledger_file" || exit 66
    : > "$pending_file"
    while IFS= read -r migration_name; do
        if ! awk -F, -v migration="$migration_name" \
            '$1 == migration && $2 ~ /^[1-9][0-9]*$/ { found++ } END { exit(found == 1 ? 0 : 1) }' \
            "$ledger_file"; then
            printf '%s\n' "$migration_name" >> "$pending_file"
        fi
    done < "$candidate_file"
    ledger_sha256=$(sha256sum "$ledger_file" | awk '{print $1}')
    pending_sha256=$(sha256sum "$pending_file" | awk '{print $1}')
    migration_batch=$(psql --tuples-only --no-align --command \
        'SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations' | tr -d '[:space:]')
    migration_pending_count=$(wc -l < "$pending_file" | tr -d '[:space:]')
    [ "$ledger_sha256" = "${CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256:-}" ] || exit 66
    [ "$pending_sha256" = "${CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256:-}" ] || exit 66
    [ "$migration_batch" = "${CONTROL_PLANE_EXPECTED_MIGRATION_BATCH:-}" ] || exit 66
    printf 'control-plane-migration-ledger-sha256=%s\n' "$ledger_sha256"
    printf 'control-plane-migration-pending-sha256=%s\n' "$pending_sha256"
    printf 'control-plane-migration-batch=%s\n' "$migration_batch"
    rm -f "$ledger_file" "$pending_file" "$candidate_file" \
        "$ordered_surplus_inventory_file"
    trap - EXIT HUP INT TERM
}

if [ "${CONTROL_PLANE_LIVE_EXPAND_MIGRATION:-false}" = true ]; then
    identity_mode=live
else
    identity_mode=rehearsal
fi

attest_database_identity "$identity_mode"
attest_migration_ledger
pending_before_migration=$migration_pending_count
php85 /usr/local/bin/run-control-plane-migrations.php

if [ "$identity_mode" = live ]; then
    if [ "$pending_before_migration" -gt 0 ]; then
        migration_count=$(cat /lab-state/migration-count 2>/dev/null || printf '%s\n' 0)
        migration_count=$((migration_count + 1))
        printf '%s\n' "$migration_count" > /lab-state/migration-count
    fi
    printf '%s\n' 'control-plane-schema-attestation=passed'
    printf '%s\n' 'live expand migration completed'
else
    printf '%s\n' 'control-plane-schema-attestation=passed'
    printf '%s\n' 'migration rehearsal completed'
fi
