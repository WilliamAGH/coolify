#!/bin/sh

set -eu

row_proof=$(psql --no-psqlrc --tuples-only --no-align --quiet --set ON_ERROR_STOP=1 \
    --command "SELECT count(*) || ':' || count(*) FILTER (WHERE rehearsal_verified) FROM control_plane_widget")
[ "$row_proof" = '3:3' ] || exit 70
grep -qx 'revision=7' /restored-state/applications/operation-state || exit 70

exec socat "TCP-LISTEN:${CONTROL_PLANE_BACKEND_PORT:-8080},reuseaddr,fork" \
    EXEC:/usr/local/bin/probe-response
