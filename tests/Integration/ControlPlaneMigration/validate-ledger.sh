#!/bin/sh

set -eu

if [ "$#" -lt 3 ] || [ "$#" -gt 4 ]; then
    printf 'usage: %s CURRENT_MIGRATIONS LEDGER_MIGRATIONS EXPECTED_PENDING [IMMUTABLE_BASELINE_SURPLUS]\n' "$0" >&2
    exit 64
fi

current_migrations=$1
ledger_migrations=$2
expected_pending=$3
immutable_baseline_surplus=${4:-}
work_directory=$(mktemp -d "${TMPDIR:-/tmp}/coolify-migration-ledger.XXXXXX")

cleanup()
{
    rm -rf "$work_directory"
}

trap cleanup EXIT HUP INT TERM

LC_ALL=C sort "$current_migrations" > "$work_directory/current"
LC_ALL=C sort "$ledger_migrations" > "$work_directory/ledger"
LC_ALL=C sort "$expected_pending" > "$work_directory/expected"
if [ -n "$immutable_baseline_surplus" ]; then
    LC_ALL=C sort "$immutable_baseline_surplus" > "$work_directory/authorized-surplus"
else
    : > "$work_directory/authorized-surplus"
fi

if [ -s "$work_directory/ledger" ] && [ -n "$(uniq -d "$work_directory/ledger")" ]; then
    printf 'migration ledger contains duplicate rows\n' >&2
    exit 1
fi

comm -13 "$work_directory/current" "$work_directory/ledger" > "$work_directory/unknown-ledger"
if ! cmp -s "$work_directory/authorized-surplus" "$work_directory/unknown-ledger"; then
    printf 'migration ledger rows absent from the current inventory do not equal the immutable baseline surplus\n' >&2
    diff -u "$work_directory/authorized-surplus" "$work_directory/unknown-ledger" >&2 || true
    exit 1
fi

comm -12 "$work_directory/current" "$work_directory/authorized-surplus" > "$work_directory/surplus-in-current"
if [ -s "$work_directory/surplus-in-current" ]; then
    printf 'immutable baseline surplus overlaps the current migration inventory\n' >&2
    exit 1
fi

comm -23 "$work_directory/current" "$work_directory/ledger" > "$work_directory/pending"
if ! cmp -s "$work_directory/expected" "$work_directory/pending"; then
    printf 'pending migration set does not match the authorized inventory\n' >&2
    diff -u "$work_directory/expected" "$work_directory/pending" >&2 || true
    exit 1
fi

printf 'LEDGER_VALID pending=%s ledger=%s inventory=%s immutable_surplus=%s\n' \
    "$(wc -l < "$work_directory/pending" | tr -d ' ')" \
    "$(wc -l < "$work_directory/ledger" | tr -d ' ')" \
    "$(wc -l < "$work_directory/current" | tr -d ' ')" \
    "$(wc -l < "$work_directory/authorized-surplus" | tr -d ' ')"
