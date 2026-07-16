#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

fail()
{
    printf 'owner-pid-quiesce: %s\n' "$1" >&2
    exit 1
}

[[ $# -ge 6 && $1 == backup-quiesce ]] || fail 'unexpected command'
action=$2
[[ $3 == --operation-id && $5 == --fencing-token ]] || fail 'unexpected argument order'
operation_id=$4
fencing_token=$6
shift 6
[[ ${CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID:-} =~ ^[1-9][0-9]*$ \
    && $CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID != 999999999 ]] \
    || fail 'capture owner PID did not override the hostile ambient value'
token_sha256=$(printf '%s' "$fencing_token" | sha256sum | awk '{print $1}')
state_file=/output/owner-pid.state
log_file=/output/owner-pid.log

case $action in
    acquire)
        [[ $# == 2 && $1 == --lease-seconds && $2 =~ ^[1-9][0-9]*$ \
            && ! -e $state_file ]] || fail 'invalid acquire'
        acquired_unix=$(date +%s)
        expires_unix=$((acquired_unix + $2))
        {
            printf 'operation_id=%s\n' "$operation_id"
            printf 'owner_pid=%s\n' "$CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID"
            printf 'token_sha256=%s\n' "$token_sha256"
            printf 'expires_unix=%s\n' "$expires_unix"
        } > "$state_file"
        chmod 600 "$state_file"
        printf 'acquire:%s\n' "$CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID" >> "$log_file"
        printf 'backup-quiesce=acquired;operation_id=%s;fencing_token_sha256=%s;lease_acquired_unix=%s;lease_expires_unix=%s\n' \
            "$operation_id" "$token_sha256" "$acquired_unix" "$expires_unix"
        ;;
    status|release)
        [[ $# == 0 && -f $state_file ]] || fail "invalid $action"
        stored_operation_id=$(awk -F= '$1 == "operation_id" { print $2 }' "$state_file")
        stored_owner_pid=$(awk -F= '$1 == "owner_pid" { print $2 }' "$state_file")
        stored_token_sha256=$(awk -F= '$1 == "token_sha256" { print $2 }' "$state_file")
        expires_unix=$(awk -F= '$1 == "expires_unix" { print $2 }' "$state_file")
        [[ $stored_operation_id == "$operation_id" \
            && $stored_owner_pid == "$CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID" \
            && $stored_token_sha256 == "$token_sha256" \
            && $expires_unix =~ ^[1-9][0-9]*$ ]] \
            || fail "$action did not retain acquire identity"
        printf '%s:%s\n' "$action" "$CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID" >> "$log_file"
        if [[ $action == status ]]; then
            printf 'backup-quiesce=status-passed;operation_id=%s;fencing_token_sha256=%s;lease_expires_unix=%s\n' \
                "$operation_id" "$token_sha256" "$expires_unix"
        else
            printf 'backup-quiesce=released;operation_id=%s;fencing_token_sha256=%s;released_unix=%s\n' \
                "$operation_id" "$token_sha256" "$(date +%s)"
        fi
        ;;
    *)
        fail 'unexpected action'
        ;;
esac
