#!/bin/sh

set -eu

readonly DEFAULT_STATE_DIRECTORY=/var/lib/coolify/control-plane-backup-quiesce
readonly INSTALLED_OPERATOR=/usr/local/sbin/control-plane-blue-green
readonly INSTALLED_SERVICE_UNIT=/etc/systemd/system/control-plane-backup-quiesce-watchdog.service
readonly INSTALLED_TIMER_UNIT=/etc/systemd/system/control-plane-backup-quiesce-watchdog.timer
readonly LEASE_CONTROLLER_MARGIN_SECONDS=10
readonly REMOTE_MUTATION_DIRECTORY=/root/control-plane-backup-quiesce

watchdog_pid_file=
test_systemd_watchdog=0
test_hold_after_watchdog_seconds=0
test_hold_before_database_restore_seconds=0
test_hold_before_remote_launch_seconds=0
test_hold_before_remote_marker_seconds=0
test_hold_remote_mutation_seconds=0
test_fail_after_mutation_input_label=
test_distinct_mutation_group=

fail()
{
    printf 'CONTROL_PLANE_BACKUP_QUIESCE_FAILURE %s\n' "$1" >&2
    exit 1
}

require_command()
{
    command -v "$1" >/dev/null 2>&1 || fail "required command is unavailable: $1"
}

require_value()
{
    [ -n "$2" ] || fail "required setting is empty: $1"
}

validate_identifier()
{
    printf '%s' "$1" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$' \
        || fail "$2 is not a safe identifier"
}

validate_database_identifier()
{
    printf '%s' "$1" | grep -Eq '^[a-z_][a-z0-9_]{0,62}$' \
        || fail "$2 is not a safe PostgreSQL identifier"
}

validate_sha256()
{
    printf '%s' "$1" | grep -Eq '^[0-9a-f]{64}$' \
        || fail "$2 must be a lowercase SHA-256 digest"
}

validate_positive_integer()
{
    printf '%s' "$1" | grep -Eq '^[1-9][0-9]*$' \
        || fail "$2 must be a positive integer"
}

validate_path()
{
    case "$1" in
        /*)
            ;;
        *)
            fail "$2 must be an absolute path"
            ;;
    esac
    case "$1" in
        */../*|*/./*|*/..|*/.)
            fail "$2 must not contain dot segments"
            ;;
    esac
}

sha256_file()
{
    sha256sum "$1" | awk '{print $1}'
}

file_uid()
{
    stat -c '%u' "$1" 2>/dev/null || stat -f '%u' "$1"
}

file_gid()
{
    stat -c '%g' "$1" 2>/dev/null || stat -f '%g' "$1"
}

file_mode()
{
    stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"
}

current_boot_id()
{
    if [ "$test_mode" = 1 ]; then
        require_value CONTROL_PLANE_TEST_BACKUP_QUIESCE_BOOT_ID \
            "${CONTROL_PLANE_TEST_BACKUP_QUIESCE_BOOT_ID:-}"
        printf '%s\n' "$CONTROL_PLANE_TEST_BACKUP_QUIESCE_BOOT_ID"
        return
    fi
    assert_regular_non_symlink /proc/sys/kernel/random/boot_id 'kernel boot identity'
    cat /proc/sys/kernel/random/boot_id
}

process_start_identity()
{
    process_pid=$1
    if [ -r "/proc/$process_pid/stat" ]; then
        process_stat=$(sed 's/^.*) //' "/proc/$process_pid/stat")
        process_state=$(printf '%s\n' "$process_stat" | awk '{print $1}')
        [ "$process_state" != Z ] || return 1
        process_started=$(printf '%s\n' "$process_stat" | awk '{print $20}')
    elif [ "$test_mode" = 1 ]; then
        process_state=$(ps -o stat= -p "$process_pid" 2>/dev/null \
            | sed 's/^[[:space:]]*//;s/[[:space:]].*$//')
        case "$process_state" in
            ''|Z*) return 1 ;;
        esac
        process_started=$(ps -o lstart= -p "$process_pid" 2>/dev/null \
            | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
    else
        return 1
    fi
    [ -n "$process_started" ] || return 1
    printf '%s' "$process_started" | sha256sum | awk '{print $1}'
}

assert_production_owner_ancestor()
{
    [ "$test_mode" = 0 ] || return 0
    ancestor_pid=$PPID
    while [ "$ancestor_pid" -gt 1 ]; do
        [ "$ancestor_pid" -ne "$lease_owner_pid" ] || return 0
        [ -r "/proc/$ancestor_pid/stat" ] \
            || fail 'backup quiesce owner process ancestry changed during acquisition'
        ancestor_pid=$(sed 's/^.*) //' "/proc/$ancestor_pid/stat" | awk '{print $2}')
        printf '%s' "$ancestor_pid" | grep -Eq '^[1-9][0-9]*$' \
            || fail 'backup quiesce owner process ancestry is malformed'
    done
    fail 'CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID must identify an ancestor capture process'
}

assert_regular_non_symlink()
{
    [ -f "$1" ] && [ ! -L "$1" ] && [ -r "$1" ] \
        || fail "$2 must be a readable regular non-symlink file"
}

acquisition_owner_alive()
{
    [ "$(current_boot_id)" = "$q_acquire_boot_id" ] && lease_owner_present
}

assert_acquisition_owner_alive()
{
    [ "${acquisition_owner_guard:-0}" = 1 ] || return 0
    acquisition_owner_alive \
        || fail 'backup quiesce acquisition owner exited or the acquiring boot changed'
}

run_bounded_command()
{
    command_bound=$1
    shift
    assert_acquisition_owner_alive
    timeout --kill-after=2s "$command_bound" "$@" &
    bounded_pid=$!
    while :; do
        bounded_state=$(ps -o stat= -p "$bounded_pid" 2>/dev/null \
            | sed 's/^[[:space:]]*//;s/[[:space:]].*$//')
        case "$bounded_state" in
            ''|Z*) break ;;
        esac
        if [ "${acquisition_owner_guard:-0}" = 1 ] \
            && ! acquisition_owner_alive; then
            kill "$bounded_pid" >/dev/null 2>&1 || true
            wait "$bounded_pid" >/dev/null 2>&1 || true
            fail 'backup quiesce acquisition owner exited or the acquiring boot changed'
        fi
        sleep 0.1
    done
    bounded_status=0
    wait "$bounded_pid" || bounded_status=$?
    return "$bounded_status"
}

prepare_remote_mutation_boundary()
{
    mutation_container=$1
    # shellcheck disable=SC2016 # Variables expand only inside the container.
    bounded_external docker exec --user 0 "$mutation_container" /bin/sh -ec '
        directory=$1
        parent=${directory%/*}
        [ -x /bin/busybox ] && [ ! -L /bin/busybox ] \
            && [ "$(stat -c %u:%g:%a /bin/busybox)" = 0:0:755 ] \
            || exit 1
        [ -d "$parent" ] && [ ! -L "$parent" ] \
            && [ "$(stat -c %u:%g:%a "$parent")" = 0:0:700 ] \
            || exit 1
        if [ -e "$directory" ] || [ -L "$directory" ]; then
            [ -d "$directory" ] && [ ! -L "$directory" ] \
                && [ "$(stat -c %u:%g:%a "$directory")" = 0:0:700 ] \
                || exit 1
        else
            umask 077
            mkdir "$directory"
            chown 0:0 "$directory"
            chmod 700 "$directory"
        fi
        [ -d "$directory" ] && [ ! -L "$directory" ] \
            && [ "$(stat -c %u:%g:%a "$directory")" = 0:0:700 ]
    ' sh "$REMOTE_MUTATION_DIRECTORY" \
        || fail 'remote backup quiesce mutation boundary is not root-owned mode 0700'
}

load_remote_mutation_identity()
{
    mutation_container=$1
    [ "$mutation_container" = "$q_live_container_id" ] \
        || [ "$mutation_container" = "$q_database_container_id" ] \
        || fail 'remote backup quiesce mutation container is not state-bound'

    mutation_configured_user=$(bounded_external docker inspect \
        --format '{{.Config.User}}' "$mutation_container")
    # shellcheck disable=SC2016 # Identity expressions expand only inside the container.
    mutation_default_identity=$(bounded_external docker exec "$mutation_container" \
        /bin/sh -ec '
            printf "uid=%s\n" "$(/usr/bin/id -u)"
            printf "gid=%s\n" "$(/usr/bin/id -g)"
            sed -n "s/^Groups:[[:space:]]*/groups=/p" /proc/self/status
        ')
    mutation_default_uid=$(printf '%s\n' "$mutation_default_identity" \
        | sed -n 's/^uid=//p')
    mutation_default_gid=$(printf '%s\n' "$mutation_default_identity" \
        | sed -n 's/^gid=//p')
    mutation_default_groups=$(printf '%s\n' "$mutation_default_identity" \
        | sed -n 's/^groups=//p' \
        | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
    if [ "$test_mode" = 1 ] && [ -n "$test_distinct_mutation_group" ] \
        && [ "$mutation_container" = "$q_live_container_id" ]; then
        mutation_default_groups="$mutation_default_groups $test_distinct_mutation_group"
        test_distinct_mutation_group=
    fi
    [ "$(printf '%s\n' "$mutation_default_identity" | wc -l | tr -d '[:space:]')" = 3 ] \
        || fail 'remote backup quiesce mutation default identity is incomplete'
    printf '%s:%s' "$mutation_default_uid" "$mutation_default_gid" \
        | grep -Eq '^[0-9]+:[0-9]+$' \
        || fail 'remote backup quiesce mutation default numeric identity is malformed'
    [ -n "$mutation_default_groups" ] \
        || fail 'remote backup quiesce mutation supplemental group inventory is empty'
    for mutation_default_group in $mutation_default_groups; do
        printf '%s' "$mutation_default_group" | grep -Eq '^[0-9]+$' \
            || fail 'remote backup quiesce mutation supplemental group is malformed'
    done

    case "$mutation_configured_user" in
        '')
            [ "$mutation_default_uid:$mutation_default_gid" = 0:0 ] \
                || fail 'empty container user configuration does not resolve to root'
            ;;
        *:*)
            mutation_configured_uid=${mutation_configured_user%%:*}
            mutation_configured_gid=${mutation_configured_user#*:}
            printf '%s:%s' "$mutation_configured_uid" "$mutation_configured_gid" \
                | grep -Eq '^[0-9]+:[0-9]+$' \
                || fail 'named or malformed container user:group configuration is unsupported'
            [ "$mutation_default_uid:$mutation_default_gid" \
                = "$mutation_configured_uid:$mutation_configured_gid" ] \
                || fail 'container user:group configuration differs from its effective identity'
            ;;
        *[!0-9]*)
            printf '%s' "$mutation_configured_user" \
                | grep -Eq '^[A-Za-z_][A-Za-z0-9_.-]*$' \
                || fail 'container user configuration is unsafe'
            # shellcheck disable=SC2016 # Identity expressions expand only inside the container.
            mutation_resolved_identity=$(bounded_external docker exec --user 0 \
                "$mutation_container" /bin/sh -ec \
                '/usr/bin/id -u "$1"; /usr/bin/id -g "$1"' sh \
                "$mutation_configured_user")
            mutation_resolved_uid=$(printf '%s\n' "$mutation_resolved_identity" \
                | sed -n '1p')
            mutation_resolved_gid=$(printf '%s\n' "$mutation_resolved_identity" \
                | sed -n '2p')
            [ "$(printf '%s\n' "$mutation_resolved_identity" \
                | wc -l | tr -d '[:space:]')" = 2 ] \
                || fail 'named container user identity is incomplete'
            [ "$mutation_default_uid:$mutation_default_gid" \
                = "$mutation_resolved_uid:$mutation_resolved_gid" ] \
                || fail 'named container user differs from its effective identity'
            ;;
        *)
            [ "$mutation_default_uid" = "$mutation_configured_user" ] \
                || fail 'numeric container user differs from its effective identity'
            ;;
    esac

    mutation_default_is_root=0
    if [ "$mutation_default_uid:$mutation_default_gid" = 0:0 ]; then
        mutation_default_is_root=1
        return
    fi
    for mutation_default_group in $mutation_default_groups; do
        [ "$mutation_default_group" = "$mutation_default_gid" ] \
            || fail 'remote backup quiesce mutation container has a distinct supplemental group'
    done
    # shellcheck disable=SC2016 # Metadata expressions expand only inside the container.
    bounded_external docker exec --user 0 "$mutation_container" /bin/sh -ec '
        [ -x /sbin/su-exec ] && [ ! -L /sbin/su-exec ] \
            && [ "$(stat -c %u:%g:%a /sbin/su-exec)" = 0:0:755 ]
    ' || fail 'remote backup quiesce mutation privilege-drop executable is untrusted'
}

resolve_bound_mutation_container()
{
    mutation_requested_container=$1
    if [ "$mutation_requested_container" = "$q_live_container" ]; then
        mutation_bound_container_id=$q_live_container_id
        mutation_bound_image_id=$q_live_image_id
    elif [ "$mutation_requested_container" = "$q_database_container" ]; then
        mutation_bound_container_id=$q_database_container_id
        mutation_bound_image_id=$q_database_image_id
    else
        fail 'remote backup quiesce mutation container is not state-bound'
    fi
    [ "$(container_id "$mutation_bound_container_id")" = "$mutation_bound_container_id" ] \
        && [ "$(container_image_id "$mutation_bound_container_id")" \
            = "$mutation_bound_image_id" ] \
        || fail 'state-bound remote mutation container identity is unavailable'
    assert_bound_mutation_container_name
    mutation_container=$mutation_bound_container_id
}

assert_bound_mutation_container_name()
{
    [ "$(container_id "$mutation_requested_container")" = "$mutation_bound_container_id" ] \
        && [ "$(container_image_id "$mutation_requested_container")" \
            = "$mutation_bound_image_id" ] \
        || fail 'canonical remote mutation container name changed before launch'
}

create_remote_mutation_permit()
{
    mutation_container=$1
    mutation_permit=$2
    mutation_active=$3
    mutation_revoking=$4
    # shellcheck disable=SC2016 # Variables expand only inside the container.
    bounded_external docker exec --user 0 "$mutation_container" /bin/sh -ec '
        boundary=$1
        permit=$2
        active=$3
        revoking=$4
        [ -d /root ] && [ ! -L /root ] \
            && [ "$(stat -c %u:%g:%a /root)" = 0:0:700 ] \
            && [ -d "$boundary" ] && [ ! -L "$boundary" ] \
            && [ "$(stat -c %u:%g:%a "$boundary")" = 0:0:700 ] \
            || exit 1
        for state_path in "$permit" "$active" "$revoking"; do
            [ ! -e "$state_path" ] && [ ! -L "$state_path" ] || exit 1
        done
        umask 077
        mkdir "$permit"
        chown 0:0 "$permit"
        chmod 700 "$permit"
        [ -d "$permit" ] && [ ! -L "$permit" ] \
            && [ "$(stat -c %u:%g:%a "$permit")" = 0:0:700 ]
    ' sh "$REMOTE_MUTATION_DIRECTORY" "$mutation_permit" "$mutation_active" \
        "$mutation_revoking" \
        || fail 'remote backup quiesce mutation launch permit could not be created exactly'
}

stop_remote_mutation()
{
    mutation_container=$1
    mutation_permit=$2
    mutation_active=$3
    mutation_revoking=$4
    # shellcheck disable=SC2016 # Variables and process fields expand only inside the container.
    mutation_revocation_script='
            boundary=$1
            permit=$2
            active=$3
            revoking=$4

            trusted_state_directory()
            {
                state_path=$1
                [ -d "$state_path" ] && [ ! -L "$state_path" ] \
                    && [ "$(stat -c %u:%g:%a "$state_path")" = 0:0:700 ]
            }

            [ -d /root ] && [ ! -L /root ] \
                && [ "$(stat -c %u:%g:%a /root)" = 0:0:700 ] \
                && [ -d "$boundary" ] && [ ! -L "$boundary" ] \
                && [ "$(stat -c %u:%g:%a "$boundary")" = 0:0:700 ] \
                || exit 1
            for state_path in "$permit" "$active" "$revoking"; do
                if [ -e "$state_path" ] || [ -L "$state_path" ]; then
                    trusted_state_directory "$state_path" || exit 1
                fi
            done

            if [ -d "$permit" ]; then
                [ ! -e "$revoking" ] && [ ! -L "$revoking" ] || exit 1
                if ! mv "$permit" "$revoking"; then
                    [ ! -e "$permit" ] && [ ! -L "$permit" ] \
                        && trusted_state_directory "$active" || exit 1
                fi
            fi

            state_directory=
            state_count=0
            for state_path in "$active" "$revoking"; do
                if [ -e "$state_path" ] || [ -L "$state_path" ]; then
                    trusted_state_directory "$state_path" || exit 1
                    state_directory=$state_path
                    state_count=$((state_count + 1))
                fi
            done
            [ "$state_count" -le 1 ] || exit 1
            [ ! -e "$permit" ] && [ ! -L "$permit" ] || exit 1
            [ "$state_count" -eq 1 ] || exit 0

            identity="$state_directory/identity"
            identity_candidate="$state_directory/identity.candidate"
            pre_marker_ready="$state_directory/pre-marker-ready"
            if [ -e "$pre_marker_ready" ] || [ -L "$pre_marker_ready" ]; then
                [ -f "$pre_marker_ready" ] && [ ! -L "$pre_marker_ready" ] \
                    && [ "$(stat -c %u:%g:%a "$pre_marker_ready")" = 0:0:600 ] \
                    || exit 1
            fi
            if [ -e "$identity_candidate" ] || [ -L "$identity_candidate" ]; then
                [ -f "$identity_candidate" ] && [ ! -L "$identity_candidate" ] \
                    && [ "$(stat -c %u:%g:%a "$identity_candidate")" = 0:0:600 ] \
                    || exit 1
            fi
            if [ ! -e "$identity" ] && [ ! -L "$identity" ]; then
                [ "$state_directory" = "$revoking" ] || exit 1
                if [ -e "$identity_candidate" ]; then
                    rm -f "$identity_candidate"
                fi
                if [ -e "$pre_marker_ready" ]; then
                    identity=$pre_marker_ready
                else
                    rmdir "$state_directory"
                    exit 0
                fi
            fi
            [ -f "$identity" ] && [ ! -L "$identity" ] \
                && [ "$(stat -c %u:%g:%a "$identity")" = 0:0:600 ] \
                && [ ! -e "$identity_candidate" ] && [ ! -L "$identity_candidate" ] \
                || exit 1
            if [ "$identity" != "$pre_marker_ready" ]; then
                [ ! -e "$pre_marker_ready" ] && [ ! -L "$pre_marker_ready" ] \
                    || exit 1
            fi
            mutation_identity=$(cat "$identity")
            printf "%s" "$mutation_identity" \
                | grep -Eq "^[1-9][0-9]*:[1-9][0-9]*:[1-9][0-9]*:[1-9][0-9]*$" \
                || exit 1
            saved_ifs=$IFS
            IFS=:
            set -- $mutation_identity
            IFS=$saved_ifs
            pid=$1
            expected_start=$2
            expected_process_group=$3
            expected_session=$4
            [ "$pid" -gt 1 ] && [ "$expected_process_group" = "$pid" ] \
                && [ "$expected_session" = "$pid" ] || exit 1

            if [ -r "/proc/$pid/stat" ]; then
                process_stat=$(sed "s/^.*) //" "/proc/$pid/stat") || exit 1
                set -- $process_stat
                [ "$#" -ge 20 ] || exit 1
                current_process_group=$3
                current_session=$4
                shift 19
                current_start=$1
                [ "$current_start:$current_process_group:$current_session" \
                    = "$expected_start:$expected_process_group:$expected_session" ] \
                    || exit 1
            fi

            session_member_count()
            {
                member_count=0
                for process_stat_path in /proc/[0-9]*/stat; do
                    [ -r "$process_stat_path" ] || continue
                    member_stat=$(sed "s/^.*) //" "$process_stat_path" 2>/dev/null) \
                        || continue
                    set -- $member_stat
                    [ "$#" -ge 4 ] || continue
                    member_state=$1
                    member_session=$4
                    if [ "$member_state" != Z ] \
                        && [ "$member_session" = "$expected_session" ]; then
                        member_count=$((member_count + 1))
                    fi
                done
                printf "%s\n" "$member_count"
            }

            signal_session_members()
            {
                member_signal=$1
                for process_stat_path in /proc/[0-9]*/stat; do
                    [ -r "$process_stat_path" ] || continue
                    member_pid=${process_stat_path#/proc/}
                    member_pid=${member_pid%/stat}
                    case "$member_pid" in
                        ""|*[!0-9]*) continue ;;
                    esac
                    [ "$member_pid" -gt 1 ] || exit 1
                    member_stat=$(sed "s/^.*) //" "$process_stat_path" 2>/dev/null) \
                        || continue
                    set -- $member_stat
                    [ "$#" -ge 20 ] || continue
                    member_state=$1
                    member_session=$4
                    shift 19
                    member_start=$1
                    if [ "$member_state" != Z ] \
                        && [ "$member_session" = "$expected_session" ]; then
                        refreshed_member_stat=$(sed "s/^.*) //" \
                            "$process_stat_path" 2>/dev/null) || continue
                        set -- $refreshed_member_stat
                        [ "$#" -ge 20 ] || continue
                        refreshed_member_state=$1
                        refreshed_member_session=$4
                        shift 19
                        refreshed_member_start=$1
                        [ "$refreshed_member_state" != Z ] \
                            && [ "$refreshed_member_session" = "$expected_session" ] \
                            && [ "$refreshed_member_start" = "$member_start" ] \
                            || continue
                        kill "-$member_signal" "$member_pid" 2>/dev/null || true
                    fi
                done
            }

            remaining_members=$(session_member_count)
            attempts=0
            while [ "$remaining_members" -gt 0 ] && [ "$attempts" -lt 10 ]; do
                signal_session_members TERM
                sleep 0.1
                remaining_members=$(session_member_count)
                attempts=$((attempts + 1))
            done
            attempts=0
            while [ "$remaining_members" -gt 0 ] && [ "$attempts" -lt 10 ]; do
                signal_session_members KILL
                sleep 0.1
                remaining_members=$(session_member_count)
                attempts=$((attempts + 1))
            done
            [ "$remaining_members" -eq 0 ] || exit 1
            [ -f "$identity" ] && [ ! -L "$identity" ] \
                && [ "$(stat -c %u:%g:%a "$identity")" = 0:0:600 ] \
                && [ "$(cat "$identity")" = "$mutation_identity" ] \
                || exit 1
            rm -f "$identity"
            rmdir "$state_directory"
        '
    if [ "$test_mode" = 1 ]; then
        timeout --kill-after=1s 2s docker exec --user 0 "$mutation_container" \
            /bin/sh -n -c "$mutation_revocation_script" \
            || fail 'generated remote mutation revocation script is malformed'
    fi
    timeout --kill-after=1s 5s docker exec --user 0 "$mutation_container" \
        /bin/sh -ec "$mutation_revocation_script" sh \
        "$REMOTE_MUTATION_DIRECTORY" "$mutation_permit" "$mutation_active" \
        "$mutation_revoking" \
        || fail 'remote backup quiesce mutation ticket could not be revoked exactly'
}

test_fail_after_mutation_input()
{
    [ "$test_mode" = 1 ] \
        && [ -n "$test_fail_after_mutation_input_label" ] \
        && [ "$mutation_label" = "$test_fail_after_mutation_input_label" ] \
        || return 0
    test_fail_after_mutation_input_label=
    fail "injected backup quiesce failure after staged mutation input: $mutation_label"
}

run_bounded_container_mutation()
{
    mutation_bound=$1
    mutation_requested_container=$2
    mutation_mode=$3
    mutation_label=$4
    shift 4
    validate_identifier "$mutation_label" remote_mutation_label
    mutation_ticket_prefix="$REMOTE_MUTATION_DIRECTORY/${q_operation_id}-${q_token_sha256}"
    mutation_permit="${mutation_ticket_prefix}.permit"
    mutation_active="${mutation_ticket_prefix}.active"
    mutation_revoking="${mutation_ticket_prefix}.revoking"
    mutation_input=
    mutation_pre_marker_delay=0
    if [ "$test_mode" = 1 ] && [ "$mutation_label" = held-test-mutation ]; then
        mutation_pre_marker_delay=$test_hold_before_remote_marker_seconds
    fi
    assert_acquisition_owner_alive
    resolve_bound_mutation_container "$mutation_requested_container"
    prepare_remote_mutation_boundary "$mutation_container"
    case "$mutation_mode" in
        default|interactive)
            mutation_execution_identity=default
            load_remote_mutation_identity "$mutation_container"
            if [ "$mutation_default_is_root" = 1 ]; then
                mutation_execution_identity=root
            fi
            ;;
        root|interactive-root)
            mutation_execution_identity=root
            mutation_default_uid=0
            mutation_default_gid=0
            ;;
        *)
            fail 'remote backup quiesce mutation mode is invalid'
            ;;
    esac
    stop_remote_mutation "$mutation_container" "$mutation_permit" \
        "$mutation_active" "$mutation_revoking"
    create_remote_mutation_permit "$mutation_container" "$mutation_permit" \
        "$mutation_active" "$mutation_revoking"
    if [ "$test_mode" = 1 ] && [ "$mutation_label" = maintenance-acquire ] \
        && [ "$test_hold_before_remote_launch_seconds" -gt 0 ]; then
        acquisition_guarded_sleep "$test_hold_before_remote_launch_seconds"
    fi
    assert_bound_mutation_container_name
    if [ "${acquisition_owner_guard:-0}" = 1 ] \
        && ! acquisition_owner_alive; then
        stop_remote_mutation "$mutation_container" "$mutation_permit" \
            "$mutation_active" "$mutation_revoking"
        fail 'backup quiesce acquisition owner exited or the acquiring boot changed'
    fi
    # shellcheck disable=SC2016 # Session variables expand only inside the target container.
    mutation_session_script='
        permit=$1
        active=$2
        execution_identity=$3
        selected_uid=$4
        selected_gid=$5
        pre_marker_delay=$6
        shift 6
            process_stat=$(sed "s/^.*) //" /proc/$$/stat) || exit 1
            process_group=$(
                set -- $process_stat
                [ "$#" -ge 20 ] || exit 1
                printf "%s" "$3"
            ) || exit 1
            session=$(
                set -- $process_stat
                [ "$#" -ge 20 ] || exit 1
                printf "%s" "$4"
            ) || exit 1
            start=$(
                set -- $process_stat
                [ "$#" -ge 20 ] || exit 1
                shift 19
                printf "%s" "$1"
            ) || exit 1
            [ -n "$process_group" ] && [ -n "$session" ] && [ -n "$start" ]
            [ "$$" -gt 1 ] && [ "$process_group" = "$$" ] && [ "$session" = "$$" ]
            printf "%s:%s" "$selected_uid" "$selected_gid" \
                | grep -Eq "^[0-9]+:[0-9]+$"
            [ -d "$permit" ] && [ ! -L "$permit" ] \
                && [ "$(stat -c %u:%g:%a "$permit")" = 0:0:700 ] \
                && [ ! -e "$active" ] && [ ! -L "$active" ]
            printf "%s" "$pre_marker_delay" | grep -Eq "^[0-9]+$"
            pre_marker_ready="$permit/pre-marker-ready"
            if [ "$pre_marker_delay" -gt 0 ]; then
                umask 077
                set -C
                printf "%s:%s:%s:%s\n" "$$" "$start" "$process_group" "$session" \
                    > "$pre_marker_ready"
                set +C
                chown 0:0 "$pre_marker_ready"
                chmod 600 "$pre_marker_ready"
                sleep "$pre_marker_delay"
                rm -f "$pre_marker_ready"
            fi
            identity="$permit/identity"
            identity_candidate="$permit/identity.candidate"
            [ ! -e "$identity" ] && [ ! -L "$identity" ] \
                && [ ! -e "$identity_candidate" ] && [ ! -L "$identity_candidate" ]
            umask 077
            set -C
            printf "%s:%s:%s:%s\n" "$$" "$start" "$process_group" "$session" \
                > "$identity_candidate"
            set +C
            chown 0:0 "$identity_candidate"
            chmod 600 "$identity_candidate"
            mv "$identity_candidate" "$identity"
            mv "$permit" "$active"
            case "$execution_identity" in
                default) exec /sbin/su-exec "$selected_uid:$selected_gid" "$@" ;;
                root) exec "$@" ;;
                *) exit 1 ;;
            esac
        '
    # shellcheck disable=SC2016 # Wrapper variables expand only inside the target container.
    mutation_wrapper='
            session_script=$1
            permit=$2
            active=$3
            execution_identity=$4
            selected_uid=$5
            selected_gid=$6
            pre_marker_delay=$7
            shift 7
            mutation_status=0
            /bin/busybox setsid /bin/sh -ec "$session_script" sh \
                "$permit" "$active" "$execution_identity" "$selected_uid" \
                "$selected_gid" "$pre_marker_delay" "$@" || mutation_status=$?
            exit "$mutation_status"
        '
    if [ "$test_mode" = 1 ]; then
        timeout --kill-after=1s 2s docker exec --user 0 "$mutation_container" \
            /bin/sh -n -c "$mutation_session_script" \
            || fail 'generated remote mutation session script is malformed'
        timeout --kill-after=1s 2s docker exec --user 0 "$mutation_container" \
            /bin/sh -n -c "$mutation_wrapper" \
            || fail 'generated remote mutation wrapper script is malformed'
    fi
    case "$mutation_mode" in
        default)
            timeout --kill-after=2s "$mutation_bound" docker exec --user 0 \
                "$mutation_container" /bin/sh -ec "$mutation_wrapper" sh \
                "$mutation_session_script" "$mutation_permit" "$mutation_active" \
                "$mutation_execution_identity" \
                "$mutation_default_uid" "$mutation_default_gid" \
                "$mutation_pre_marker_delay" "$@" &
            ;;
        root)
            timeout --kill-after=2s "$mutation_bound" docker exec --user 0 \
                "$mutation_container" /bin/sh -ec "$mutation_wrapper" sh \
                "$mutation_session_script" "$mutation_permit" "$mutation_active" \
                "$mutation_execution_identity" \
                "$mutation_default_uid" "$mutation_default_gid" \
                "$mutation_pre_marker_delay" "$@" &
            ;;
        interactive)
            mutation_input="$quiesce_operation_directory/.mutation-input.$$"
            umask 077
            timeout --kill-after=1s "$mutation_bound" cat > "$mutation_input" \
                || fail "bounded remote backup quiesce input capture failed (command=$mutation_label)"
            test_fail_after_mutation_input
            timeout --kill-after=2s "$mutation_bound" docker exec --interactive --user 0 \
                "$mutation_container" /bin/sh -ec "$mutation_wrapper" sh \
                "$mutation_session_script" "$mutation_permit" "$mutation_active" \
                "$mutation_execution_identity" \
                "$mutation_default_uid" "$mutation_default_gid" \
                "$mutation_pre_marker_delay" "$@" \
                < "$mutation_input" &
            ;;
        interactive-root)
            mutation_input="$quiesce_operation_directory/.mutation-input.$$"
            umask 077
            timeout --kill-after=1s "$mutation_bound" cat > "$mutation_input" \
                || fail "bounded remote backup quiesce input capture failed (command=$mutation_label)"
            test_fail_after_mutation_input
            timeout --kill-after=2s "$mutation_bound" docker exec --interactive --user 0 \
                "$mutation_container" /bin/sh -ec "$mutation_wrapper" sh \
                "$mutation_session_script" "$mutation_permit" "$mutation_active" \
                "$mutation_execution_identity" \
                "$mutation_default_uid" "$mutation_default_gid" \
                "$mutation_pre_marker_delay" "$@" \
                < "$mutation_input" &
            ;;
    esac
    mutation_client_pid=$!
    owner_lost=0
    while :; do
        mutation_client_state=$(ps -o stat= -p "$mutation_client_pid" 2>/dev/null \
            | sed 's/^[[:space:]]*//;s/[[:space:]].*$//')
        case "$mutation_client_state" in
            ''|Z*) break ;;
        esac
        if [ "${acquisition_owner_guard:-0}" = 1 ] \
            && ! acquisition_owner_alive; then
            owner_lost=1
            break
        fi
        sleep 0.1
    done
    if [ "$owner_lost" = 1 ]; then
        stop_remote_mutation "$mutation_container" "$mutation_permit" \
            "$mutation_active" "$mutation_revoking"
        kill "$mutation_client_pid" >/dev/null 2>&1 || true
        mutation_status=0
        wait "$mutation_client_pid" || mutation_status=$?
        stop_remote_mutation "$mutation_container" "$mutation_permit" \
            "$mutation_active" "$mutation_revoking"
    else
        mutation_status=0
        wait "$mutation_client_pid" || mutation_status=$?
        stop_remote_mutation "$mutation_container" "$mutation_permit" \
            "$mutation_active" "$mutation_revoking"
    fi
    # shellcheck disable=SC2016 # Variables expand only inside the container.
    timeout --kill-after=1s 2s docker exec --user 0 "$mutation_container" \
        /bin/sh -ec '
            for identity_path in "$1" "$2" "$3"; do
                [ ! -e "$identity_path" ] && [ ! -L "$identity_path" ] || exit 1
            done
        ' sh "$mutation_permit" "$mutation_active" "$mutation_revoking" \
        || fail 'remote backup quiesce mutation identity residue remains after stop'
    [ -z "$mutation_input" ] || rm -f "$mutation_input"
    [ "$owner_lost" = 0 ] \
        || fail 'backup quiesce acquisition owner exited or the acquiring boot changed'
    [ "$mutation_status" = 0 ] \
        || fail "bounded remote backup quiesce command failed (command=$mutation_label status=$mutation_status)"
}

revoke_remote_mutations()
{
    [ "$(container_id "$q_live_container_id")" = "$q_live_container_id" ] \
        && [ "$(container_image_id "$q_live_container_id")" = "$q_live_image_id" ] \
        || fail 'state-bound live container changed before mutation cleanup'
    [ "$(container_id "$q_database_container_id")" = "$q_database_container_id" ] \
        && [ "$(container_image_id "$q_database_container_id")" = "$q_database_image_id" ] \
        || fail 'state-bound database container changed before mutation cleanup'
    for mutation_cleanup_container in "$q_live_container_id" "$q_database_container_id"; do
        prepare_remote_mutation_boundary "$mutation_cleanup_container"
        mutation_cleanup_prefix="$REMOTE_MUTATION_DIRECTORY/${q_operation_id}-${q_token_sha256}"
        stop_remote_mutation "$mutation_cleanup_container" \
            "${mutation_cleanup_prefix}.permit" "${mutation_cleanup_prefix}.active" \
            "${mutation_cleanup_prefix}.revoking"
    done
    [ "$(container_id "$q_live_container")" = "$q_live_container_id" ] \
        && [ "$(container_image_id "$q_live_container")" = "$q_live_image_id" ] \
        || fail 'canonical live control-plane container changed during mutation cleanup'
    [ "$(container_id "$q_database_container")" = "$q_database_container_id" ] \
        && [ "$(container_image_id "$q_database_container")" = "$q_database_image_id" ] \
        || fail 'canonical database container changed during mutation cleanup'
}

cleanup_local_mutation_inputs()
{
    for mutation_input_path in "$quiesce_operation_directory"/.mutation-input.*; do
        [ -e "$mutation_input_path" ] || [ -L "$mutation_input_path" ] || continue
        [ -f "$mutation_input_path" ] && [ ! -L "$mutation_input_path" ] \
            && [ "$(file_mode "$mutation_input_path")" = 600 ] \
            || fail 'staged backup quiesce mutation input is untrusted'
        if [ "$test_mode" = 0 ]; then
            [ "$(file_uid "$mutation_input_path")" = 0 ] \
                && [ "$(file_gid "$mutation_input_path")" = 0 ] \
                || fail 'production staged mutation input must be owned by root:root'
        fi
        rm -f "$mutation_input_path"
    done
}

acquisition_guarded_sleep()
{
    sleep_seconds=$1
    if [ "${acquisition_owner_guard:-0}" != 1 ]; then
        sleep "$sleep_seconds"
        return
    fi
    sleep_remaining=$sleep_seconds
    while [ "$sleep_remaining" -gt 0 ]; do
        assert_acquisition_owner_alive
        sleep 1
        sleep_remaining=$((sleep_remaining - 1))
    done
    assert_acquisition_owner_alive
}

bounded_external()
{
    run_bounded_command "${probe_timeout_seconds}s" "$@"
}

bounded_s6_external()
{
    s6_wait_seconds=$(((q_s6_wait_milliseconds + 999) / 1000))
    run_bounded_command "$((s6_wait_seconds + q_probe_timeout_seconds))s" "$@"
}

bounded_realtime_stop()
{
    run_bounded_command "$((q_realtime_stop_seconds + q_probe_timeout_seconds))s" "$@"
}

bounded_realtime_start()
{
    run_bounded_command \
        "$((q_realtime_stop_seconds + q_probe_timeout_seconds + LEASE_CONTROLLER_MARGIN_SECONDS))s" \
        "$@"
}

container_id()
{
    bounded_external docker inspect --format '{{.Id}}' "$1"
}

container_image_id()
{
    bounded_external docker inspect --format '{{.Image}}' "$1"
}

container_running()
{
    bounded_external docker inspect --format '{{.State.Running}}' "$1"
}

container_label()
{
    bounded_external docker inspect --format "{{index .Config.Labels \"$2\"}}" "$1"
}

state_value()
{
    state_key=$1
    state_matches=$(grep -c "^${state_key}=" "$quiesce_state_file")
    [ "$state_matches" = 1 ] || fail "quiesce state has invalid field cardinality: $state_key"
    sed -n "s/^${state_key}=//p" "$quiesce_state_file"
}

write_state()
{
    state_candidate="$quiesce_operation_directory/.state.$$"
    umask 077
    {
        printf 'schema_version=2\n'
        printf 'phase=%s\n' "$q_phase"
        printf 'test_mode=%s\n' "$q_test_mode"
        printf 'operation_id=%s\n' "$q_operation_id"
        printf 'fencing_token_sha256=%s\n' "$q_token_sha256"
        printf 'lease_acquired_unix=%s\n' "$q_acquired_unix"
        printf 'lease_expires_unix=%s\n' "$q_expires_unix"
        printf 'released_unix=%s\n' "$q_released_unix"
        printf 'operator_path=%s\n' "$q_operator_path"
        printf 'operator_sha256=%s\n' "$q_operator_sha256"
        printf 'controller_path=%s\n' "$q_controller_path"
        printf 'controller_sha256=%s\n' "$q_controller_sha256"
        printf 'service_unit_path=%s\n' "$q_service_unit_path"
        printf 'service_unit_sha256=%s\n' "$q_service_unit_sha256"
        printf 'timer_unit_path=%s\n' "$q_timer_unit_path"
        printf 'timer_unit_sha256=%s\n' "$q_timer_unit_sha256"
        printf 'live_container=%s\n' "$q_live_container"
        printf 'live_container_id=%s\n' "$q_live_container_id"
        printf 'live_image_id=%s\n' "$q_live_image_id"
        printf 'live_compose_project=%s\n' "$q_live_compose_project"
        printf 'live_compose_service=%s\n' "$q_live_compose_service"
        printf 'database_container=%s\n' "$q_database_container"
        printf 'database_container_id=%s\n' "$q_database_container_id"
        printf 'database_image_id=%s\n' "$q_database_image_id"
        printf 'database_system_identifier=%s\n' "$q_database_system_identifier"
        printf 'database_oid=%s\n' "$q_database_oid"
        printf 'database_name=%s\n' "$q_database_name"
        printf 'database_admin_user=%s\n' "$q_database_admin_user"
        printf 'application_database_role=%s\n' "$q_application_database_role"
        printf 'application_database_role_oid=%s\n' "$q_application_database_role_oid"
        printf 'role_read_only_prestate=%s\n' "$q_role_read_only_prestate"
        printf 'realtime_container=%s\n' "$q_realtime_container"
        printf 'realtime_container_id=%s\n' "$q_realtime_container_id"
        printf 'realtime_image_id=%s\n' "$q_realtime_image_id"
        printf 'realtime_prestate=%s\n' "$q_realtime_prestate"
        printf 'maintenance_prestate=%s\n' "$q_maintenance_prestate"
        printf 'maintenance_prestate_sha256=%s\n' "$q_maintenance_prestate_sha256"
        printf 'maintenance_prestate_uid=%s\n' "$q_maintenance_prestate_uid"
        printf 'maintenance_prestate_gid=%s\n' "$q_maintenance_prestate_gid"
        printf 'maintenance_prestate_mode=%s\n' "$q_maintenance_prestate_mode"
        printf 'fence_sha256=%s\n' "$q_fence_sha256"
        printf 'scheduler_prestate=%s\n' "$q_scheduler_prestate"
        printf 'horizon_prestate=%s\n' "$q_horizon_prestate"
        printf 'nightwatch_prestate=%s\n' "$q_nightwatch_prestate"
        printf 'drain_timeout_seconds=%s\n' "$q_drain_timeout_seconds"
        printf 'probe_timeout_seconds=%s\n' "$q_probe_timeout_seconds"
        printf 's6_wait_milliseconds=%s\n' "$q_s6_wait_milliseconds"
        printf 'realtime_stop_seconds=%s\n' "$q_realtime_stop_seconds"
        printf 'minimum_capture_seconds=%s\n' "$q_minimum_capture_seconds"
        printf 'lease_acquisition_bound_seconds=%s\n' "$q_lease_acquisition_bound_seconds"
        printf 'watchdog_owner=%s\n' "$q_watchdog_owner"
        printf 'acquire_boot_id=%s\n' "$q_acquire_boot_id"
        printf 'owner_pid=%s\n' "$q_owner_pid"
        printf 'owner_start_sha256=%s\n' "$q_owner_start_sha256"
    } > "$state_candidate"
    chmod 600 "$state_candidate"
    sync
    mv "$state_candidate" "$quiesce_state_file"
    sync
}

load_state()
{
    assert_regular_non_symlink "$quiesce_state_file" 'backup quiesce state'
    [ "$(file_mode "$quiesce_state_file")" = 600 ] \
        || fail 'backup quiesce state must have mode 0600'
    if [ "$test_mode" = 0 ]; then
        [ "$(file_uid "$quiesce_state_file")" = 0 ] \
            && [ "$(file_gid "$quiesce_state_file")" = 0 ] \
            || fail 'production backup quiesce state must be owned by root:root'
    fi
    [ "$(wc -l < "$quiesce_state_file" | tr -d '[:space:]')" = 54 ] \
        || fail 'backup quiesce state must contain exactly 54 fields'
    [ "$(state_value schema_version)" = 2 ] || fail 'backup quiesce state version is unsupported'
    q_phase=$(state_value phase)
    q_test_mode=$(state_value test_mode)
    q_operation_id=$(state_value operation_id)
    q_token_sha256=$(state_value fencing_token_sha256)
    q_acquired_unix=$(state_value lease_acquired_unix)
    q_expires_unix=$(state_value lease_expires_unix)
    q_released_unix=$(state_value released_unix)
    q_operator_path=$(state_value operator_path)
    q_operator_sha256=$(state_value operator_sha256)
    q_controller_path=$(state_value controller_path)
    q_controller_sha256=$(state_value controller_sha256)
    q_service_unit_path=$(state_value service_unit_path)
    q_service_unit_sha256=$(state_value service_unit_sha256)
    q_timer_unit_path=$(state_value timer_unit_path)
    q_timer_unit_sha256=$(state_value timer_unit_sha256)
    q_live_container=$(state_value live_container)
    q_live_container_id=$(state_value live_container_id)
    q_live_image_id=$(state_value live_image_id)
    q_live_compose_project=$(state_value live_compose_project)
    q_live_compose_service=$(state_value live_compose_service)
    q_database_container=$(state_value database_container)
    q_database_container_id=$(state_value database_container_id)
    q_database_image_id=$(state_value database_image_id)
    q_database_system_identifier=$(state_value database_system_identifier)
    q_database_oid=$(state_value database_oid)
    q_database_name=$(state_value database_name)
    q_database_admin_user=$(state_value database_admin_user)
    q_application_database_role=$(state_value application_database_role)
    q_application_database_role_oid=$(state_value application_database_role_oid)
    q_role_read_only_prestate=$(state_value role_read_only_prestate)
    q_realtime_container=$(state_value realtime_container)
    q_realtime_container_id=$(state_value realtime_container_id)
    q_realtime_image_id=$(state_value realtime_image_id)
    q_realtime_prestate=$(state_value realtime_prestate)
    q_maintenance_prestate=$(state_value maintenance_prestate)
    q_maintenance_prestate_sha256=$(state_value maintenance_prestate_sha256)
    q_maintenance_prestate_uid=$(state_value maintenance_prestate_uid)
    q_maintenance_prestate_gid=$(state_value maintenance_prestate_gid)
    q_maintenance_prestate_mode=$(state_value maintenance_prestate_mode)
    q_fence_sha256=$(state_value fence_sha256)
    q_scheduler_prestate=$(state_value scheduler_prestate)
    q_horizon_prestate=$(state_value horizon_prestate)
    q_nightwatch_prestate=$(state_value nightwatch_prestate)
    q_drain_timeout_seconds=$(state_value drain_timeout_seconds)
    q_probe_timeout_seconds=$(state_value probe_timeout_seconds)
    probe_timeout_seconds=$q_probe_timeout_seconds
    q_s6_wait_milliseconds=$(state_value s6_wait_milliseconds)
    q_realtime_stop_seconds=$(state_value realtime_stop_seconds)
    q_minimum_capture_seconds=$(state_value minimum_capture_seconds)
    q_lease_acquisition_bound_seconds=$(state_value lease_acquisition_bound_seconds)
    q_watchdog_owner=$(state_value watchdog_owner)
    q_acquire_boot_id=$(state_value acquire_boot_id)
    q_owner_pid=$(state_value owner_pid)
    q_owner_start_sha256=$(state_value owner_start_sha256)

    case "$q_phase" in
        prepared|acquiring|acquired|releasing|finalizing|released)
            ;;
        *)
            fail 'backup quiesce state phase is invalid'
            ;;
    esac
    case "$q_test_mode" in 0|1) ;; *) fail 'backup quiesce test mode is invalid' ;; esac
    [ "$q_test_mode" = "$test_mode" ] \
        || fail 'backup quiesce state test mode differs from the controller runtime'
    validate_identifier "$q_operation_id" operation_id
    validate_sha256 "$q_token_sha256" fencing_token_sha256
    validate_positive_integer "$q_acquired_unix" lease_acquired_unix
    validate_positive_integer "$q_expires_unix" lease_expires_unix
    [ "$q_expires_unix" -gt "$q_acquired_unix" ] || fail 'backup quiesce lease interval is invalid'
    [ "$q_released_unix" = none ] || validate_positive_integer "$q_released_unix" released_unix
    validate_path "$q_operator_path" operator_path
    validate_sha256 "$q_operator_sha256" operator_sha256
    validate_path "$q_controller_path" controller_path
    validate_sha256 "$q_controller_sha256" controller_sha256
    validate_path "$q_service_unit_path" service_unit_path
    validate_sha256 "$q_service_unit_sha256" service_unit_sha256
    validate_path "$q_timer_unit_path" timer_unit_path
    validate_sha256 "$q_timer_unit_sha256" timer_unit_sha256
    validate_identifier "$q_live_container" live_container
    validate_identifier "$q_live_compose_project" live_compose_project
    validate_identifier "$q_live_compose_service" live_compose_service
    validate_identifier "$q_database_container" database_container
    validate_positive_integer "$q_database_system_identifier" database_system_identifier
    validate_positive_integer "$q_database_oid" database_oid
    validate_database_identifier "$q_database_name" database_name
    validate_database_identifier "$q_database_admin_user" database_admin_user
    validate_database_identifier "$q_application_database_role" application_database_role
    validate_positive_integer "$q_application_database_role_oid" application_database_role_oid
    validate_identifier "$q_realtime_container" realtime_container
    validate_positive_integer "$q_drain_timeout_seconds" drain_timeout_seconds
    validate_positive_integer "$q_probe_timeout_seconds" probe_timeout_seconds
    validate_positive_integer "$q_s6_wait_milliseconds" s6_wait_milliseconds
    validate_positive_integer "$q_realtime_stop_seconds" realtime_stop_seconds
    validate_positive_integer "$q_minimum_capture_seconds" minimum_capture_seconds
    validate_positive_integer "$q_lease_acquisition_bound_seconds" \
        lease_acquisition_bound_seconds
    [ "$q_drain_timeout_seconds" -le 600 ] \
        && [ "$q_probe_timeout_seconds" -le 30 ] \
        && [ "$q_s6_wait_milliseconds" -le 300000 ] \
        && [ "$q_realtime_stop_seconds" -le 300 ] \
        && [ "$q_minimum_capture_seconds" -le 3600 ] \
        || fail 'persisted backup quiesce timeout or capture budget exceeds its bound'
    q_s6_wait_seconds=$(((q_s6_wait_milliseconds + 999) / 1000))
    q_probe_allowance_multiplier=72
    [ "$q_test_mode" = 0 ] || q_probe_allowance_multiplier=12
    q_expected_acquisition_bound=$((
        (q_drain_timeout_seconds * 4) \
        + (q_probe_timeout_seconds * q_probe_allowance_multiplier) \
        + (q_s6_wait_seconds * 3) + q_realtime_stop_seconds \
        + LEASE_CONTROLLER_MARGIN_SECONDS
    ))
    [ "$q_lease_acquisition_bound_seconds" -eq "$q_expected_acquisition_bound" ] \
        || fail 'persisted backup quiesce acquisition bound is invalid'
    [ $((q_expires_unix - q_acquired_unix)) \
        -ge $((q_lease_acquisition_bound_seconds + q_minimum_capture_seconds)) ] \
        || fail 'persisted backup quiesce lease is shorter than its acquisition and capture budgets'
    [ $((q_expires_unix - q_acquired_unix)) -le 7200 ] \
        || fail 'persisted backup quiesce lease exceeds its maximum duration'
    validate_sha256 "$q_live_container_id" live_container_id
    [ "${q_live_image_id#sha256:}" != "$q_live_image_id" ] \
        || fail 'live image identity must include a sha256 prefix'
    validate_sha256 "${q_live_image_id#sha256:}" live_image_id
    validate_sha256 "$q_database_container_id" database_container_id
    [ "${q_database_image_id#sha256:}" != "$q_database_image_id" ] \
        || fail 'database image identity must include a sha256 prefix'
    validate_sha256 "${q_database_image_id#sha256:}" database_image_id
    validate_sha256 "$q_realtime_container_id" realtime_container_id
    [ "${q_realtime_image_id#sha256:}" != "$q_realtime_image_id" ] \
        || fail 'realtime image identity must include a sha256 prefix'
    validate_sha256 "${q_realtime_image_id#sha256:}" realtime_image_id
    case "$q_role_read_only_prestate" in unset|on|off) ;; *) fail 'role prestate is invalid' ;; esac
    case "$q_realtime_prestate" in running|stopped) ;; *) fail 'realtime prestate is invalid' ;; esac
    case "$q_maintenance_prestate" in
        absent)
            [ "$q_maintenance_prestate_sha256" = none ] \
                && [ "$q_maintenance_prestate_uid" = none ] \
                && [ "$q_maintenance_prestate_gid" = none ] \
                && [ "$q_maintenance_prestate_mode" = none ] \
                || fail 'absent maintenance prestate metadata is invalid'
            ;;
        present)
            validate_sha256 "$q_maintenance_prestate_sha256" maintenance_prestate_sha256
            printf '%s:%s:%s' "$q_maintenance_prestate_uid" "$q_maintenance_prestate_gid" \
                "$q_maintenance_prestate_mode" \
                | grep -Eq '^[0-9]+:[0-9]+:[0-7]{3,4}$' \
                || fail 'present maintenance prestate metadata is invalid'
            ;;
        *)
            fail 'maintenance prestate is invalid'
            ;;
    esac
    assert_maintenance_prestate_snapshot
    [ "$q_fence_sha256" = none ] || validate_sha256 "$q_fence_sha256" fence_sha256
    case "$q_scheduler_prestate" in up|down) ;; *) fail 'scheduler prestate is invalid' ;; esac
    case "$q_horizon_prestate" in up|paused|down) ;; *) fail 'Horizon prestate is invalid' ;; esac
    case "$q_nightwatch_prestate" in up|down) ;; *) fail 'Nightwatch prestate is invalid' ;; esac
    case "$q_watchdog_owner" in systemd|detached-test) ;; *) fail 'watchdog owner is invalid' ;; esac
    [ "$q_test_mode" = 1 ] || [ "$q_watchdog_owner" = systemd ] \
        || fail 'production backup quiesce state must use the permanent systemd watchdog'
    validate_identifier "$q_acquire_boot_id" acquire_boot_id
    validate_positive_integer "$q_owner_pid" owner_pid
    validate_sha256 "$q_owner_start_sha256" owner_start_sha256
}

prepare_state_directory()
{
    validate_path "$quiesce_state_directory" CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR
    umask 077
    mkdir -p "$quiesce_state_directory"
    [ -d "$quiesce_state_directory" ] && [ ! -L "$quiesce_state_directory" ] \
        || fail 'backup quiesce state root must be a non-symlink directory'
    chmod 700 "$quiesce_state_directory"
    if [ "$test_mode" = 0 ]; then
        [ "$(file_uid "$quiesce_state_directory")" = 0 ] \
            && [ "$(file_gid "$quiesce_state_directory")" = 0 ] \
            && [ "$(file_mode "$quiesce_state_directory")" = 700 ] \
            || fail 'production backup quiesce state root must be root:root mode 0700'
    fi
}

acquire_global_lock()
{
    quiesce_lock_file="$quiesce_state_directory/.lock"
    [ ! -L "$quiesce_lock_file" ] \
        || fail 'backup quiesce global lock must not be a symlink'
    exec 8> "$quiesce_lock_file"
    [ -f "$quiesce_lock_file" ] && [ ! -L "$quiesce_lock_file" ] \
        && [ "$(file_mode "$quiesce_lock_file")" = 600 ] \
        || fail 'backup quiesce global lock must be a mode-0600 regular file'
    if [ "$test_mode" = 0 ]; then
        [ "$(file_uid "$quiesce_lock_file")" = 0 ] \
            && [ "$(file_gid "$quiesce_lock_file")" = 0 ] \
            || fail 'production backup quiesce global lock must be owned by root:root'
    fi
    flock -w 10 8 || fail 'another backup quiesce operation holds the global lock'
}

release_global_lock()
{
    flock -u 8 >/dev/null 2>&1 || true
    exec 8>&-
}

set_operation_paths()
{
    quiesce_operation_directory="$quiesce_state_directory/$operation_id"
    quiesce_state_file="$quiesce_operation_directory/state"
    maintenance_prestate_file="$quiesce_operation_directory/maintenance-prestate"
    quiesce_active_file="$quiesce_state_directory/active"
}

assert_operation_directory()
{
    [ -d "$quiesce_operation_directory" ] && [ ! -L "$quiesce_operation_directory" ] \
        && [ "$(file_mode "$quiesce_operation_directory")" = 700 ] \
        || fail 'backup quiesce operation directory is not a secure mode-0700 directory'
    if [ "$test_mode" = 0 ]; then
        [ "$(file_uid "$quiesce_operation_directory")" = 0 ] \
            && [ "$(file_gid "$quiesce_operation_directory")" = 0 ] \
            || fail 'production backup quiesce operation directory must be owned by root:root'
    fi
}

write_active_operation()
{
    active_candidate="$quiesce_state_directory/.active.$$"
    printf '%s\n' "$q_operation_id" > "$active_candidate"
    chmod 600 "$active_candidate"
    sync
    mv "$active_candidate" "$quiesce_active_file"
    sync
}

active_operation()
{
    if [ ! -e "$quiesce_active_file" ] && [ ! -L "$quiesce_active_file" ]; then
        printf '%s\n' none
        return
    fi
    assert_regular_non_symlink "$quiesce_active_file" 'active backup quiesce pointer'
    [ "$(file_mode "$quiesce_active_file")" = 600 ] \
        || fail 'active backup quiesce pointer must have mode 0600'
    if [ "$test_mode" = 0 ]; then
        [ "$(file_uid "$quiesce_active_file")" = 0 ] \
            && [ "$(file_gid "$quiesce_active_file")" = 0 ] \
            || fail 'production active backup quiesce pointer must be owned by root:root'
    fi
    active_name=$(cat "$quiesce_active_file")
    validate_identifier "$active_name" 'active backup quiesce operation'
    printf '%s\n' "$active_name"
}

clear_active_operation()
{
    if [ -f "$quiesce_active_file" ] && [ ! -L "$quiesce_active_file" ]; then
        [ "$(cat "$quiesce_active_file")" = "$q_operation_id" ] \
            || fail 'active backup quiesce pointer belongs to another operation'
        rm -f "$quiesce_active_file"
        sync
    fi
}

load_public_configuration()
{
    test_mode=${CONTROL_PLANE_TEST_MODE:-0}
    case "$test_mode" in 0|1) ;; *) fail 'CONTROL_PLANE_TEST_MODE must be exactly 0 or 1' ;; esac
    quiesce_state_directory=${CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR:-$DEFAULT_STATE_DIRECTORY}
    live_container=${CONTROL_PLANE_BACKUP_LIVE_CONTAINER:-${CONTROL_PLANE_BLUE_CONTAINER:-}}
    database_container=${CONTROL_PLANE_DATABASE_CONTAINER:-coolify-db}
    realtime_container=${CONTROL_PLANE_BACKUP_REALTIME_CONTAINER:-${CONTROL_PLANE_SOKETI_CONTAINER:-coolify-realtime}}
    database_name=${CONTROL_PLANE_DATABASE_NAME:-coolify}
    database_admin_user=${CONTROL_PLANE_DATABASE_ADMIN_USER:-${CONTROL_PLANE_DATABASE_USER:-}}
    application_database_role=${CONTROL_PLANE_BACKUP_APPLICATION_DATABASE_ROLE:-${CONTROL_PLANE_DATABASE_USER:-}}
    drain_timeout_seconds=${CONTROL_PLANE_BACKUP_QUIESCE_DRAIN_TIMEOUT_SECONDS:-60}
    probe_timeout_seconds=${CONTROL_PLANE_BACKUP_QUIESCE_PROBE_TIMEOUT_SECONDS:-5}
    s6_wait_milliseconds=${CONTROL_PLANE_S6_WAIT_MILLISECONDS:-30000}
    realtime_stop_seconds=${CONTROL_PLANE_BACKUP_QUIESCE_REALTIME_STOP_SECONDS:-10}
    minimum_capture_seconds=${CONTROL_PLANE_BACKUP_QUIESCE_MINIMUM_CAPTURE_SECONDS:-300}
    operator_path=${CONTROL_PLANE_BACKUP_QUIESCE_OPERATOR_PATH:-}
    operator_sha256=${CONTROL_PLANE_BACKUP_QUIESCE_OPERATOR_SHA256:-}
    expected_controller_path=${CONTROL_PLANE_BACKUP_QUIESCE_CONTROLLER_PATH:-}
    expected_controller_sha256=${CONTROL_PLANE_BACKUP_QUIESCE_CONTROLLER_SHA256:-}
    service_unit_path=${CONTROL_PLANE_BACKUP_QUIESCE_SERVICE_UNIT_PATH:-}
    expected_service_unit_sha256=${CONTROL_PLANE_BACKUP_QUIESCE_SERVICE_UNIT_SHA256:-}
    timer_unit_path=${CONTROL_PLANE_BACKUP_QUIESCE_TIMER_UNIT_PATH:-}
    expected_timer_unit_sha256=${CONTROL_PLANE_BACKUP_QUIESCE_TIMER_UNIT_SHA256:-}
    watchdog_pid_file=${CONTROL_PLANE_TEST_BACKUP_QUIESCE_WATCHDOG_PID_FILE:-}
    test_systemd_watchdog=${CONTROL_PLANE_TEST_BACKUP_QUIESCE_SYSTEMD_WATCHDOG:-0}
    test_hold_after_watchdog_seconds=${CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_AFTER_WATCHDOG_SECONDS:-0}
    test_hold_before_database_restore_seconds=${CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_BEFORE_DATABASE_RESTORE_SECONDS:-0}
    test_hold_before_remote_launch_seconds=${CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_BEFORE_REMOTE_LAUNCH_SECONDS:-0}
    test_hold_before_remote_marker_seconds=${CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_BEFORE_REMOTE_MARKER_SECONDS:-0}
    test_hold_remote_mutation_seconds=${CONTROL_PLANE_TEST_BACKUP_QUIESCE_HOLD_REMOTE_MUTATION_SECONDS:-0}
    test_fail_after_mutation_input_label=${CONTROL_PLANE_TEST_BACKUP_QUIESCE_FAIL_AFTER_MUTATION_INPUT_LABEL:-}
    test_distinct_mutation_group=${CONTROL_PLANE_TEST_BACKUP_QUIESCE_DISTINCT_MUTATION_GROUP:-}
    lease_owner_pid=${CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID:-}

    require_value CONTROL_PLANE_BACKUP_LIVE_CONTAINER "$live_container"
    require_value CONTROL_PLANE_DATABASE_CONTAINER "$database_container"
    require_value CONTROL_PLANE_BACKUP_REALTIME_CONTAINER "$realtime_container"
    require_value CONTROL_PLANE_DATABASE_NAME "$database_name"
    require_value CONTROL_PLANE_DATABASE_ADMIN_USER "$database_admin_user"
    require_value CONTROL_PLANE_BACKUP_APPLICATION_DATABASE_ROLE "$application_database_role"
    require_value CONTROL_PLANE_BACKUP_QUIESCE_OPERATOR_PATH "$operator_path"
    require_value CONTROL_PLANE_BACKUP_QUIESCE_OPERATOR_SHA256 "$operator_sha256"
    require_value CONTROL_PLANE_BACKUP_QUIESCE_CONTROLLER_PATH "$expected_controller_path"
    require_value CONTROL_PLANE_BACKUP_QUIESCE_CONTROLLER_SHA256 "$expected_controller_sha256"
    require_value CONTROL_PLANE_BACKUP_QUIESCE_SERVICE_UNIT_PATH "$service_unit_path"
    require_value CONTROL_PLANE_BACKUP_QUIESCE_SERVICE_UNIT_SHA256 "$expected_service_unit_sha256"
    require_value CONTROL_PLANE_BACKUP_QUIESCE_TIMER_UNIT_PATH "$timer_unit_path"
    require_value CONTROL_PLANE_BACKUP_QUIESCE_TIMER_UNIT_SHA256 "$expected_timer_unit_sha256"
    validate_identifier "$live_container" CONTROL_PLANE_BACKUP_LIVE_CONTAINER
    validate_identifier "$database_container" CONTROL_PLANE_DATABASE_CONTAINER
    validate_identifier "$realtime_container" CONTROL_PLANE_BACKUP_REALTIME_CONTAINER
    validate_database_identifier "$database_name" CONTROL_PLANE_DATABASE_NAME
    validate_database_identifier "$database_admin_user" CONTROL_PLANE_DATABASE_ADMIN_USER
    validate_database_identifier "$application_database_role" \
        CONTROL_PLANE_BACKUP_APPLICATION_DATABASE_ROLE
    validate_positive_integer "$drain_timeout_seconds" \
        CONTROL_PLANE_BACKUP_QUIESCE_DRAIN_TIMEOUT_SECONDS
    validate_positive_integer "$probe_timeout_seconds" \
        CONTROL_PLANE_BACKUP_QUIESCE_PROBE_TIMEOUT_SECONDS
    validate_positive_integer "$s6_wait_milliseconds" CONTROL_PLANE_S6_WAIT_MILLISECONDS
    validate_positive_integer "$realtime_stop_seconds" \
        CONTROL_PLANE_BACKUP_QUIESCE_REALTIME_STOP_SECONDS
    validate_positive_integer "$minimum_capture_seconds" \
        CONTROL_PLANE_BACKUP_QUIESCE_MINIMUM_CAPTURE_SECONDS
    [ "$drain_timeout_seconds" -le 600 ] \
        || fail 'CONTROL_PLANE_BACKUP_QUIESCE_DRAIN_TIMEOUT_SECONDS must not exceed 600'
    [ "$probe_timeout_seconds" -le 30 ] \
        || fail 'CONTROL_PLANE_BACKUP_QUIESCE_PROBE_TIMEOUT_SECONDS must not exceed 30'
    [ "$s6_wait_milliseconds" -le 300000 ] \
        || fail 'CONTROL_PLANE_S6_WAIT_MILLISECONDS must not exceed 300000'
    [ "$realtime_stop_seconds" -le 300 ] \
        || fail 'CONTROL_PLANE_BACKUP_QUIESCE_REALTIME_STOP_SECONDS must not exceed 300'
    [ "$minimum_capture_seconds" -le 3600 ] \
        || fail 'CONTROL_PLANE_BACKUP_QUIESCE_MINIMUM_CAPTURE_SECONDS must not exceed 3600'
    s6_wait_seconds=$(((s6_wait_milliseconds + 999) / 1000))
    probe_allowance_multiplier=72
    [ "$test_mode" = 0 ] || probe_allowance_multiplier=12
    lease_acquisition_bound_seconds=$((
        (drain_timeout_seconds * 4) \
        + (probe_timeout_seconds * probe_allowance_multiplier) \
        + (s6_wait_seconds * 3) + realtime_stop_seconds \
        + LEASE_CONTROLLER_MARGIN_SECONDS
    ))
    minimum_lease_seconds=$((lease_acquisition_bound_seconds + minimum_capture_seconds))
    [ "$minimum_lease_seconds" -le 7200 ] \
        || fail 'configured backup quiesce acquisition and capture budgets exceed the maximum lease'
    validate_path "$operator_path" CONTROL_PLANE_BACKUP_QUIESCE_OPERATOR_PATH
    validate_sha256 "$operator_sha256" CONTROL_PLANE_BACKUP_QUIESCE_OPERATOR_SHA256
    validate_path "$expected_controller_path" CONTROL_PLANE_BACKUP_QUIESCE_CONTROLLER_PATH
    validate_sha256 "$expected_controller_sha256" CONTROL_PLANE_BACKUP_QUIESCE_CONTROLLER_SHA256
    validate_path "$service_unit_path" CONTROL_PLANE_BACKUP_QUIESCE_SERVICE_UNIT_PATH
    validate_sha256 "$expected_service_unit_sha256" CONTROL_PLANE_BACKUP_QUIESCE_SERVICE_UNIT_SHA256
    validate_path "$timer_unit_path" CONTROL_PLANE_BACKUP_QUIESCE_TIMER_UNIT_PATH
    validate_sha256 "$expected_timer_unit_sha256" CONTROL_PLANE_BACKUP_QUIESCE_TIMER_UNIT_SHA256
    if [ -n "$watchdog_pid_file" ]; then
        [ "$test_mode" = 1 ] \
            || fail 'test watchdog PID file is unavailable outside lab mode'
        validate_path "$watchdog_pid_file" CONTROL_PLANE_TEST_BACKUP_QUIESCE_WATCHDOG_PID_FILE
    fi
    case "$test_systemd_watchdog" in 0|1) ;; *) fail 'test systemd watchdog mode must be 0 or 1' ;; esac
    case "$test_hold_after_watchdog_seconds" in
        ''|*[!0-9]*) fail 'test watchdog hold must be a nonnegative integer' ;;
    esac
    case "$test_hold_before_database_restore_seconds" in
        ''|*[!0-9]*) fail 'test database-restore hold must be a nonnegative integer' ;;
    esac
    case "$test_hold_before_remote_launch_seconds" in
        ''|*[!0-9]*) fail 'test pre-launch hold must be a nonnegative integer' ;;
    esac
    case "$test_hold_before_remote_marker_seconds" in
        ''|*[!0-9]*) fail 'test pre-marker hold must be a nonnegative integer' ;;
    esac
    case "$test_hold_remote_mutation_seconds" in
        ''|*[!0-9]*) fail 'test remote-mutation hold must be a nonnegative integer' ;;
    esac
    if [ -n "$test_fail_after_mutation_input_label" ]; then
        validate_identifier "$test_fail_after_mutation_input_label" \
            CONTROL_PLANE_TEST_BACKUP_QUIESCE_FAIL_AFTER_MUTATION_INPUT_LABEL
    fi
    if [ -n "$test_distinct_mutation_group" ]; then
        validate_positive_integer "$test_distinct_mutation_group" \
            CONTROL_PLANE_TEST_BACKUP_QUIESCE_DISTINCT_MUTATION_GROUP
    fi
    if [ "$test_systemd_watchdog" = 1 ] \
        || [ "$test_hold_after_watchdog_seconds" -gt 0 ] \
        || [ "$test_hold_before_database_restore_seconds" -gt 0 ] \
        || [ "$test_hold_before_remote_launch_seconds" -gt 0 ] \
        || [ "$test_hold_before_remote_marker_seconds" -gt 0 ] \
        || [ "$test_hold_remote_mutation_seconds" -gt 0 ] \
        || [ -n "$test_fail_after_mutation_input_label" ] \
        || [ -n "$test_distinct_mutation_group" ]; then
        [ "$test_mode" = 1 ] \
            || fail 'test watchdog controls are unavailable outside lab mode'
    fi
    if [ "$action" = acquire ]; then
        require_value CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID "$lease_owner_pid"
        validate_positive_integer "$lease_owner_pid" CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID
        assert_production_owner_ancestor
        lease_owner_start_sha256=$(process_start_identity "$lease_owner_pid") \
            || fail 'backup quiesce owner process is not alive'
        validate_sha256 "$lease_owner_start_sha256" \
            CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID
    fi
    boot_id=$(current_boot_id)
    validate_identifier "$boot_id" boot_id
    prepare_state_directory
}

validate_lease_budget()
{
    [ "$lease_seconds" -ge "$minimum_lease_seconds" ] \
        || fail "backup quiesce lease must be at least $minimum_lease_seconds seconds (acquisition_bound_seconds=$lease_acquisition_bound_seconds minimum_capture_seconds=$minimum_capture_seconds)"
}

assert_pinned_runtime()
{
    controller_path=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)/$(basename -- "$0")
    [ "$controller_path" = "$expected_controller_path" ] \
        || fail 'backup quiesce controller path differs from its pinned release path'
    assert_regular_non_symlink "$operator_path" 'backup quiesce operator'
    assert_regular_non_symlink "$controller_path" 'backup quiesce controller'
    assert_regular_non_symlink "$service_unit_path" 'backup quiesce watchdog service unit'
    assert_regular_non_symlink "$timer_unit_path" 'backup quiesce watchdog timer unit'
    [ "$(sha256_file "$operator_path")" = "$operator_sha256" ] \
        || fail 'backup quiesce operator differs from its pinned digest'
    [ "$(sha256_file "$controller_path")" = "$expected_controller_sha256" ] \
        || fail 'backup quiesce controller differs from its pinned digest'
    [ "$(sha256_file "$service_unit_path")" = "$expected_service_unit_sha256" ] \
        || fail 'backup quiesce watchdog service unit differs from its pinned digest'
    [ "$(sha256_file "$timer_unit_path")" = "$expected_timer_unit_sha256" ] \
        || fail 'backup quiesce watchdog timer unit differs from its pinned digest'
    if [ "$test_mode" = 0 ]; then
        [ "$operator_path" = "$INSTALLED_OPERATOR" ] \
            && [ "$service_unit_path" = "$INSTALLED_SERVICE_UNIT" ] \
            && [ "$timer_unit_path" = "$INSTALLED_TIMER_UNIT" ] \
            || fail 'production backup quiesce must use immutable installed operator/controller/unit paths'
        case "$controller_path" in
            /usr/local/lib/coolify-control-plane/releases/*/backup-quiesce/control-plane-backup-quiesce.sh) ;;
            *) fail 'production backup quiesce controller is outside the active versioned release' ;;
        esac
        for immutable_path in "$operator_path" "$controller_path" "$service_unit_path" "$timer_unit_path"; do
            [ "$(file_uid "$immutable_path")" = 0 ] \
                && [ "$(file_gid "$immutable_path")" = 0 ] \
                || fail "production backup quiesce asset must be owned by root:root: $immutable_path"
        done
        [ "$(file_mode "$operator_path")" = 755 ] \
            && [ "$(file_mode "$controller_path")" = 755 ] \
            && [ "$(file_mode "$service_unit_path")" = 644 ] \
            && [ "$(file_mode "$timer_unit_path")" = 644 ] \
            || fail 'production backup quiesce asset modes must be operator/controller 0755 and units 0644'
        bounded_external systemctl is-enabled --quiet control-plane-backup-quiesce-watchdog.timer \
            || fail 'permanent backup quiesce watchdog timer is not enabled'
        bounded_external systemctl is-active --quiet control-plane-backup-quiesce-watchdog.timer \
            || fail 'permanent backup quiesce watchdog timer is not active'
    fi
}

run_database_psql()
{
    database_mutation_mode=$1
    database_mutation_label=$2
    shift 2
    run_bounded_container_mutation "${probe_timeout_seconds}s" \
        "$q_database_container" "$database_mutation_mode" "$database_mutation_label" \
        env 'PGOPTIONS=-c default_transaction_read_only=off -c lock_timeout=5s -c statement_timeout=15s' \
        psql --no-psqlrc --tuples-only --no-align --quiet \
        --set ON_ERROR_STOP=1 --username "$q_database_admin_user" \
        --dbname "$q_database_name" "$@"
}

database_psql()
{
    run_database_psql interactive database-psql "$@"
}

database_command_psql()
{
    run_database_psql default database-command-psql "$@"
}

database_mutation_psql()
{
    run_database_psql interactive database-mutation-psql "$@"
}

database_mutation_command_psql()
{
    run_database_psql default database-mutation-command-psql "$@"
}

database_role_psql()
{
    printf '%s\n' "$1" | database_psql \
        --set "application_role=$q_application_database_role"
}

role_read_only_setting()
{
    database_role_psql \
        "SELECT COALESCE((SELECT split_part(setting, '=', 2) FROM unnest(role.rolconfig) AS setting WHERE split_part(setting, '=', 1) = 'default_transaction_read_only'), 'unset') FROM pg_roles AS role WHERE role.rolname = :'application_role'" \
        | tr -d '[:space:]'
}

set_role_read_only()
{
    database_mutation_command_psql --command \
        "SET default_transaction_read_only=off; ALTER ROLE $q_application_database_role SET default_transaction_read_only TO on" \
        >/dev/null
}

restore_role_read_only()
{
    case "$q_role_read_only_prestate" in
        unset)
            database_mutation_command_psql --command \
                "SET default_transaction_read_only=off; ALTER ROLE $q_application_database_role RESET default_transaction_read_only" \
                >/dev/null
            ;;
        on|off)
            database_mutation_command_psql --command \
                "SET default_transaction_read_only=off; ALTER ROLE $q_application_database_role SET default_transaction_read_only TO $q_role_read_only_prestate" \
                >/dev/null
            ;;
    esac
}

terminate_application_sessions()
{
    printf '%s\n' \
        "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE usename = :'application_role' AND pid <> pg_backend_pid()" \
        | database_mutation_psql --set "application_role=$q_application_database_role" \
        >/dev/null
}

active_writer_session_count()
{
    database_role_psql \
        "SELECT count(*) FROM pg_stat_activity WHERE usename = :'application_role' AND pid <> pg_backend_pid() AND backend_xid IS NOT NULL" \
        | tr -d '[:space:]'
}

active_mutation_count()
{
    database_command_psql --command \
        "SELECT (SELECT count(*) FROM application_deployment_queues WHERE status = 'in_progress') + (SELECT count(*) FROM scheduled_database_backup_executions WHERE status = 'running') + (SELECT count(*) FROM scheduled_task_executions WHERE status = 'running') + (SELECT count(*) FROM docker_cleanup_executions WHERE status = 'running')" \
        | tr -d '[:space:]'
}

service_state()
{
    service_name=$1
    if [ "$q_test_mode" = 1 ]; then
        bounded_external docker exec "$q_live_container" /usr/local/bin/control-plane-lab-service \
            service-state "$service_name"
        return
    fi
    if bounded_external docker exec --user 0 "$q_live_container" /command/s6-svstat -u \
        "/run/service/$service_name" >/dev/null 2>&1; then
        printf '%s\n' up
    elif bounded_external docker exec --user 0 "$q_live_container" /command/s6-svstat -d \
        "/run/service/$service_name" >/dev/null 2>&1; then
        printf '%s\n' down
    else
        fail "could not determine supervised service state: $service_name"
    fi
}

horizon_state()
{
    if [ "$q_test_mode" = 1 ]; then
        bounded_external docker exec "$q_live_container" \
            /usr/local/bin/control-plane-lab-service service-state horizon
        return
    fi
    if [ "$(service_state horizon)" = down ]; then
        printf '%s\n' down
        return
    fi
    horizon_master_status=$(horizon_master_state)
    case "$horizon_master_status" in
        running) printf '%s\n' up ;;
        paused) printf '%s\n' paused ;;
        *) fail 'could not identify the exact running Horizon master' ;;
    esac
}

horizon_master_state()
{
    if [ "$q_test_mode" = 1 ]; then
        bounded_external docker exec "$q_live_container" \
            /usr/local/bin/control-plane-lab-service horizon-master-state
        return
    fi
    # shellcheck disable=SC2016 # Shell and PHP variables expand only inside the container.
    bounded_external docker exec --user 0 "$q_live_container" /bin/sh -ec '
        master_pid=$(/command/s6-svstat -o pid /run/service/horizon 2>/dev/null || true)
        case "$master_pid" in ""|*[!0-9]*) printf "missing\n"; exit 0 ;; esac
        CONTROL_PLANE_HORIZON_MASTER_PID=$master_pid php -r '\''
            require "/var/www/html/vendor/autoload.php";
            $app = require "/var/www/html/bootstrap/app.php";
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $expectedPid = (string) getenv("CONTROL_PLANE_HORIZON_MASTER_PID");
            $masters = collect(app(Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all())
                ->filter(fn ($master) => (string) $master->pid === $expectedPid)
                ->values();
            if ($masters->count() === 0) { echo "missing\n"; exit(0); }
            if ($masters->count() !== 1) { fwrite(STDERR, "duplicate exact Horizon master\n"); exit(1); }
            $status = (string) $masters->first()->status;
            if (! in_array($status, ["running", "paused"], true)) { exit(1); }
            echo $status, PHP_EOL;
        '\''
    '
}

wait_for_horizon_master_state()
{
    expected_horizon_state=$1
    horizon_deadline=$(( $(date -u +%s) + q_drain_timeout_seconds ))
    horizon_probe_required=1
    while [ "$horizon_probe_required" = 1 ] \
        || [ "$(date -u +%s)" -lt "$horizon_deadline" ]; do
        horizon_probe_required=0
        [ "$(horizon_master_state)" = "$expected_horizon_state" ] && return
        acquisition_guarded_sleep 1
    done
    fail "exact Horizon master did not register as $expected_horizon_state within the bound"
}

service_down()
{
    service_name=$1
    if [ "$q_test_mode" = 1 ]; then
        run_bounded_container_mutation "${probe_timeout_seconds}s" \
            "$q_live_container" default "service-down-$service_name" \
            /usr/local/bin/control-plane-lab-service down "$service_name"
        return
    fi
    # shellcheck disable=SC2016 # Positional parameters expand inside the container shell.
    s6_wait_seconds=$(((q_s6_wait_milliseconds + 999) / 1000))
    # shellcheck disable=SC2016 # Positional parameters expand only inside the container shell.
    run_bounded_container_mutation \
        "$((s6_wait_seconds + q_probe_timeout_seconds))s" \
        "$q_live_container" root "service-down-$service_name" env \
        "CONTROL_PLANE_S6_WAIT_MILLISECONDS=$q_s6_wait_milliseconds" /bin/sh -ec '
            service="/run/service/$1"
            test -d "$service"
            : > "$service/down"
            /command/s6-svc -d -wD -T "$CONTROL_PLANE_S6_WAIT_MILLISECONDS" "$service"
            /command/s6-svstat -d "$service" >/dev/null
        ' sh "$service_name" >/dev/null
}

service_up()
{
    service_name=$1
    if [ "$q_test_mode" = 1 ]; then
        run_bounded_container_mutation "${probe_timeout_seconds}s" \
            "$q_live_container" default "service-up-$service_name" \
            /usr/local/bin/control-plane-lab-service up "$service_name"
        return
    fi
    # shellcheck disable=SC2016 # Positional parameters expand inside the container shell.
    s6_wait_seconds=$(((q_s6_wait_milliseconds + 999) / 1000))
    # shellcheck disable=SC2016 # Positional parameters expand only inside the container shell.
    run_bounded_container_mutation \
        "$((s6_wait_seconds + q_probe_timeout_seconds))s" \
        "$q_live_container" root "service-up-$service_name" env \
        "CONTROL_PLANE_S6_WAIT_MILLISECONDS=$q_s6_wait_milliseconds" /bin/sh -ec '
            service="/run/service/$1"
            test -d "$service"
            rm -f "$service/down"
            /command/s6-svc -u -wU -T "$CONTROL_PLANE_S6_WAIT_MILLISECONDS" "$service"
            /command/s6-svstat -u "$service" >/dev/null
        ' sh "$service_name" >/dev/null
}

pause_horizon()
{
    if [ "$q_test_mode" = 1 ]; then
        run_bounded_container_mutation "${probe_timeout_seconds}s" \
            "$q_live_container" default horizon-pause \
            /usr/local/bin/control-plane-lab-service pause horizon
    else
        run_bounded_container_mutation "${probe_timeout_seconds}s" \
            "$q_live_container" default horizon-pause \
            php /var/www/html/artisan horizon:pause >/dev/null
    fi
    [ "$(horizon_state)" = paused ] || fail 'Horizon did not enter paused state'
}

terminate_horizon()
{
    if [ "$q_test_mode" = 1 ]; then
        run_bounded_container_mutation "${probe_timeout_seconds}s" \
            "$q_live_container" default horizon-terminate \
            /usr/local/bin/control-plane-lab-service terminate horizon
    else
        run_bounded_container_mutation "${probe_timeout_seconds}s" \
            "$q_live_container" root horizon-supervisor-down /bin/sh -ec '
            test -d /run/service/horizon
            : > /run/service/horizon/down
        '
        run_bounded_container_mutation "${probe_timeout_seconds}s" \
            "$q_live_container" default horizon-terminate \
            php /var/www/html/artisan horizon:terminate >/dev/null
    fi
    drain_deadline=$(( $(date -u +%s) + q_drain_timeout_seconds ))
    while [ "$(horizon_state)" != down ]; do
        [ "$(date -u +%s)" -lt "$drain_deadline" ] \
            || fail 'Horizon did not terminate within the bounded drain'
        acquisition_guarded_sleep 1
    done
}

restore_horizon()
{
    case "$q_horizon_prestate" in
        down)
            service_down horizon
            ;;
        up)
            service_up horizon
            if [ "$q_test_mode" = 1 ]; then
                run_bounded_container_mutation "${probe_timeout_seconds}s" \
                    "$q_live_container" default horizon-continue \
                    /usr/local/bin/control-plane-lab-service continue horizon
            else
                run_bounded_container_mutation "${probe_timeout_seconds}s" \
                    "$q_live_container" default horizon-continue \
                    php /var/www/html/artisan horizon:continue >/dev/null
            fi
            wait_for_horizon_master_state running
            ;;
        paused)
            if [ "$(horizon_state)" != paused ]; then
                service_up horizon
                wait_for_horizon_master_state running
                pause_horizon
            fi
            wait_for_horizon_master_state paused
            ;;
    esac
}

wait_for_scheduler_idle()
{
    drain_deadline=$(( $(date -u +%s) + q_drain_timeout_seconds ))
    scheduler_probe_required=1
    while [ "$scheduler_probe_required" = 1 ] \
        || [ "$(date -u +%s)" -lt "$drain_deadline" ]; do
        scheduler_probe_required=0
        if [ "$q_test_mode" = 1 ]; then
            scheduler_status=0
            bounded_external docker exec "$q_live_container" \
                /usr/local/bin/control-plane-lab-service schedule-idle \
                || scheduler_status=$?
            case "$scheduler_status" in
                0) return ;;
                1) ;;
                *) fail 'scheduler-idle probe failed or timed out' ;;
            esac
        else
            scheduler_status=0
            bounded_external docker exec "$q_live_container" /bin/sh -ec '
                ps -eo args | grep -F "artisan schedule:run" | grep -v grep >/dev/null
            ' || scheduler_status=$?
            case "$scheduler_status" in
                0) ;;
                1) return ;;
                *) fail 'scheduler-process probe failed or timed out' ;;
            esac
        fi
        acquisition_guarded_sleep 1
    done
    fail 'scheduled work did not stop within the bounded drain'
}

reserved_work_count()
{
    if [ "$q_test_mode" = 1 ]; then
        bounded_external docker exec "$q_live_container" \
            /usr/local/bin/control-plane-lab-service reserved-count \
            | tr -d '[:space:]'
        return
    fi
    # shellcheck disable=SC2016 # PHP variables are evaluated only by the container PHP runtime.
    bounded_external docker exec "$q_live_container" php -r '
        require "/var/www/html/vendor/autoload.php";
        $app = require "/var/www/html/bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $environment = $app->environment();
        $supervisors = config("horizon.environments.$environment", config("horizon.environments.*", []));
        $defaults = config("horizon.defaults", []);
        $connection = app("redis")->connection(config("queue.connections.redis.connection", "default"));
        $queues = [];
        foreach ($supervisors as $name => $supervisor) {
            $configuration = array_merge($defaults[$name] ?? [], $supervisor);
            $configured = $configuration["queue"] ?? [];
            if (is_string($configured)) {
                $configured = explode(",", $configured);
            }
            foreach ($configured as $queue) {
                $queue = trim((string) $queue);
                if ($queue !== "") {
                    $queues[$queue] = true;
                }
            }
        }
        $total = 0;
        foreach (array_keys($queues) as $queue) {
            $total += (int) $connection->zcard("queues:{$queue}:reserved");
        }
        echo $total, PHP_EOL;
    ' | tr -d '[:space:]'
}

wait_for_active_mutations()
{
    drain_deadline=$(( $(date -u +%s) + q_drain_timeout_seconds ))
    drain_probe_required=1
    while [ "$drain_probe_required" = 1 ] \
        || [ "$(date -u +%s)" -lt "$drain_deadline" ]; do
        drain_probe_required=0
        reserved_count=$(reserved_work_count)
        mutation_count=$(active_mutation_count)
        printf '%s' "$reserved_count" | grep -Eq '^[0-9]+$' \
            || fail 'reserved Horizon work probe did not return a count'
        printf '%s' "$mutation_count" | grep -Eq '^[0-9]+$' \
            || fail 'active control-plane mutation probe did not return a count'
        if [ "$reserved_count" = 0 ] && [ "$mutation_count" = 0 ]; then
            acquisition_guarded_sleep 1
            [ "$(reserved_work_count)" = 0 ] && [ "$(active_mutation_count)" = 0 ] \
                && return
        fi
        acquisition_guarded_sleep 1
    done
    fail 'control-plane jobs did not reach a stable zero-mutation state within the bounded drain'
}

maintenance_state()
{
    if [ "$q_test_mode" = 1 ]; then
        bounded_external docker exec "$q_live_container" \
            /usr/local/bin/control-plane-lab-service maintenance-state
        return
    fi
    # shellcheck disable=SC2016 # Shell variables expand inside the container shell.
    bounded_external docker exec "$q_live_container" /bin/sh -ec '
        maintenance=/var/www/html/storage/framework/down
        if [ -f "$maintenance" ] && [ ! -L "$maintenance" ]; then
            set -- $(sha256sum "$maintenance")
            printf "present:%s\n" "$1"
        elif [ ! -e "$maintenance" ] && [ ! -L "$maintenance" ]; then
            printf "absent:none\n"
        else
            exit 1
        fi
    '
}

maintenance_prestate_metadata()
{
    if [ "$q_test_mode" = 1 ]; then
        bounded_external docker exec "$q_live_container" /usr/local/bin/control-plane-lab-service \
            maintenance-metadata
        return
    fi
    bounded_external docker exec "$q_live_container" stat -c '%u:%g:%a' \
        /var/www/html/storage/framework/down
}

maintenance_prestate_export()
{
    if [ "$q_test_mode" = 1 ]; then
        bounded_external docker exec "$q_live_container" /usr/local/bin/control-plane-lab-service \
            maintenance-export
        return
    fi
    bounded_external docker exec "$q_live_container" cat \
        /var/www/html/storage/framework/down
}

assert_maintenance_prestate_snapshot()
{
    if [ "$q_maintenance_prestate" = absent ]; then
        [ ! -e "$maintenance_prestate_file" ] && [ ! -L "$maintenance_prestate_file" ] \
            || fail 'absent maintenance prestate has an unexpected durable snapshot'
        return
    fi
    assert_regular_non_symlink "$maintenance_prestate_file" \
        'durable maintenance prestate snapshot'
    [ "$(file_mode "$maintenance_prestate_file")" = 600 ] \
        && [ "$(sha256_file "$maintenance_prestate_file")" \
            = "$q_maintenance_prestate_sha256" ] \
        || fail 'durable maintenance prestate snapshot identity is invalid'
    if [ "$q_test_mode" = 0 ]; then
        [ "$(file_uid "$maintenance_prestate_file")" = 0 ] \
            && [ "$(file_gid "$maintenance_prestate_file")" = 0 ] \
            || fail 'production maintenance prestate snapshot must be owned by root:root'
    fi
}

snapshot_maintenance_prestate()
{
    maintenance_metadata=$(maintenance_prestate_metadata)
    q_maintenance_prestate_uid=${maintenance_metadata%%:*}
    maintenance_metadata_tail=${maintenance_metadata#*:}
    q_maintenance_prestate_gid=${maintenance_metadata_tail%%:*}
    q_maintenance_prestate_mode=${maintenance_metadata_tail#*:}
    printf '%s' "$maintenance_metadata" | grep -Eq '^[0-9]+:[0-9]+:[0-7]{3,4}$' \
        || fail 'canonical live control-plane maintenance metadata is malformed'
    maintenance_prestate_candidate="$quiesce_operation_directory/.maintenance-prestate.$$"
    maintenance_prestate_export > "$maintenance_prestate_candidate"
    chmod 600 "$maintenance_prestate_candidate"
    sync
    mv "$maintenance_prestate_candidate" "$maintenance_prestate_file"
    sync
    q_maintenance_prestate_sha256=$(sha256_file "$maintenance_prestate_file")
    assert_maintenance_prestate_snapshot
}

restore_maintenance_prestate_snapshot()
{
    assert_maintenance_prestate_snapshot
    if [ "$q_test_mode" = 1 ]; then
        run_bounded_container_mutation "${probe_timeout_seconds}s" \
            "$q_live_container" interactive-root maintenance-restore \
            /usr/local/bin/control-plane-lab-service maintenance-restore \
            "$q_maintenance_prestate_uid" "$q_maintenance_prestate_gid" \
            "$q_maintenance_prestate_mode" < "$maintenance_prestate_file"
        return
    fi
    # shellcheck disable=SC2016 # Shell variables expand inside the container shell.
    run_bounded_container_mutation "${probe_timeout_seconds}s" \
        "$q_live_container" interactive-root maintenance-restore /bin/sh -ec '
        target=/var/www/html/storage/framework/down
        candidate="${target}.backup-quiesce.$$"
        umask 077
        cat > "$candidate"
        chown "$1:$2" "$candidate"
        chmod "$3" "$candidate"
        mv "$candidate" "$target"
        sync
    ' sh "$q_maintenance_prestate_uid" "$q_maintenance_prestate_gid" \
        "$q_maintenance_prestate_mode" < "$maintenance_prestate_file"
}

enter_maintenance_fence()
{
    if [ "$q_test_mode" = 1 ]; then
        run_bounded_container_mutation "${probe_timeout_seconds}s" \
            "$q_live_container" default maintenance-acquire \
            /usr/local/bin/control-plane-lab-service maintenance-acquire
        if [ "$test_hold_remote_mutation_seconds" -gt 0 ]; then
            run_bounded_container_mutation \
                "$((test_hold_remote_mutation_seconds + probe_timeout_seconds))s" \
                "$q_live_container" default held-test-mutation \
                /usr/local/bin/control-plane-lab-service hold-mutation \
                "$test_hold_remote_mutation_seconds"
        fi
    else
        run_bounded_container_mutation "${probe_timeout_seconds}s" \
            "$q_live_container" default maintenance-acquire \
            php /var/www/html/artisan down --retry=60 >/dev/null
    fi
    maintenance_observed=$(maintenance_state)
    case "$maintenance_observed" in
        present:*)
            q_fence_sha256=${maintenance_observed#present:}
            validate_sha256 "$q_fence_sha256" fence_sha256
            ;;
        *)
            fail 'control-plane maintenance fence was not persisted'
            ;;
    esac
}

restore_maintenance()
{
    case "$q_maintenance_prestate" in
        absent)
            if [ "$q_test_mode" = 1 ]; then
                run_bounded_container_mutation "${probe_timeout_seconds}s" \
                    "$q_live_container" default maintenance-release \
                    /usr/local/bin/control-plane-lab-service maintenance-release
            else
                run_bounded_container_mutation "${probe_timeout_seconds}s" \
                    "$q_live_container" default maintenance-release \
                    php /var/www/html/artisan up >/dev/null
            fi
            [ "$(maintenance_state)" = absent:none ] \
                || fail 'absent maintenance prestate was not restored'
            ;;
        present)
            restore_maintenance_prestate_snapshot
            [ "$(maintenance_state)" = "present:$q_maintenance_prestate_sha256" ] \
                && [ "$(maintenance_prestate_metadata)" \
                    = "$q_maintenance_prestate_uid:$q_maintenance_prestate_gid:$q_maintenance_prestate_mode" ] \
                || fail 'pre-existing maintenance state was not restored exactly'
            ;;
    esac
}

maintenance_matches_prestate()
{
    case "$q_maintenance_prestate" in
        absent)
            [ "$(maintenance_state)" = absent:none ]
            ;;
        present)
            [ "$(maintenance_state)" = "present:$q_maintenance_prestate_sha256" ] \
                && [ "$(maintenance_prestate_metadata)" \
                    = "$q_maintenance_prestate_uid:$q_maintenance_prestate_gid:$q_maintenance_prestate_mode" ]
            ;;
    esac
}

assert_restored_prestate()
{
    assert_bound_runtime_identity
    [ "$(service_state scheduler-worker)" = "$q_scheduler_prestate" ] \
        && [ "$(horizon_state)" = "$q_horizon_prestate" ] \
        && [ "$(service_state nightwatch-agent)" = "$q_nightwatch_prestate" ] \
        || fail 'control-plane service prestate was not restored exactly'
    case "$q_realtime_prestate" in
        running)
            [ "$(container_running "$q_realtime_container")" = true ] \
                || fail 'running realtime prestate was not restored'
            ;;
        stopped)
            [ "$(container_running "$q_realtime_container")" = false ] \
                || fail 'stopped realtime prestate was not restored'
            ;;
    esac
    [ "$(role_read_only_setting)" = "$q_role_read_only_prestate" ] \
        || fail 'application database role prestate was not restored exactly'
    maintenance_matches_prestate \
        || fail 'control-plane maintenance prestate was not restored exactly'
}

finalize_release()
{
    assert_restored_prestate
    q_released_unix=$(date -u +%s)
    q_phase=released
    write_state
    clear_active_operation
}

capture_identity_and_prestate()
{
    q_live_container=$live_container
    q_database_container=$database_container
    q_realtime_container=$realtime_container
    [ "$(container_running "$q_live_container")" = true ] \
        || fail 'canonical live control-plane container is not running'
    [ "$(container_running "$q_database_container")" = true ] \
        || fail 'canonical control-plane database container is not running'
    bounded_external docker exec "$q_live_container" /bin/sh -ec \
        'command -v setsid >/dev/null' \
        || fail 'canonical live control-plane container lacks setsid'
    bounded_external docker exec "$q_database_container" /bin/sh -ec \
        'command -v setsid >/dev/null' \
        || fail 'canonical control-plane database container lacks setsid'
    q_live_container_id=$(container_id "$q_live_container")
    q_live_image_id=$(container_image_id "$q_live_container")
    q_live_compose_project=$(container_label "$q_live_container" com.docker.compose.project)
    q_live_compose_service=$(container_label "$q_live_container" com.docker.compose.service)
    q_database_container_id=$(container_id "$q_database_container")
    q_database_image_id=$(container_image_id "$q_database_container")
    q_realtime_container_id=$(container_id "$q_realtime_container")
    q_realtime_image_id=$(container_image_id "$q_realtime_container")
    validate_identifier "$q_live_compose_project" live_compose_project
    validate_identifier "$q_live_compose_service" live_compose_service
    q_database_name=$database_name
    q_database_admin_user=$database_admin_user
    q_application_database_role=$application_database_role
    q_database_system_identifier=$(database_command_psql --command \
        'SELECT system_identifier::text FROM pg_control_system()' | tr -d '[:space:]')
    q_database_oid=$(database_command_psql --command \
        'SELECT oid::text FROM pg_database WHERE datname = current_database()' | tr -d '[:space:]')
    live_database_name=$(database_command_psql --command 'SELECT current_database()' | tr -d '[:space:]')
    [ "$live_database_name" = "$q_database_name" ] \
        || fail 'live database name differs from the canonical backup quiesce database'
    q_application_database_role_oid=$(database_role_psql \
        "SELECT oid::text FROM pg_roles WHERE rolname = :'application_role'" | tr -d '[:space:]')
    validate_positive_integer "$q_database_system_identifier" database_system_identifier
    validate_positive_integer "$q_database_oid" database_oid
    validate_positive_integer "$q_application_database_role_oid" application_database_role_oid
    q_role_read_only_prestate=$(role_read_only_setting)
    case "$q_role_read_only_prestate" in unset|on|off) ;; *) fail 'application role read-only prestate is unsupported' ;; esac
    if [ "$(container_running "$q_realtime_container")" = true ]; then
        q_realtime_prestate=running
    else
        q_realtime_prestate=stopped
    fi
    maintenance_observed=$(maintenance_state)
    case "$maintenance_observed" in
        absent:none)
            q_maintenance_prestate=absent
            q_maintenance_prestate_sha256=none
            q_maintenance_prestate_uid=none
            q_maintenance_prestate_gid=none
            q_maintenance_prestate_mode=none
            assert_maintenance_prestate_snapshot
            ;;
        present:*)
            q_maintenance_prestate=present
            observed_maintenance_sha256=${maintenance_observed#present:}
            validate_sha256 "$observed_maintenance_sha256" maintenance_prestate_sha256
            snapshot_maintenance_prestate
            [ "$q_maintenance_prestate_sha256" = "$observed_maintenance_sha256" ] \
                || fail 'maintenance prestate changed while its durable snapshot was captured'
            ;;
        *)
            fail 'canonical live control-plane maintenance state is malformed'
            ;;
    esac
    q_fence_sha256=none
    q_scheduler_prestate=$(service_state scheduler-worker)
    q_horizon_prestate=$(horizon_state)
    q_nightwatch_prestate=$(service_state nightwatch-agent)
    case "$q_scheduler_prestate" in up|down) ;; *) fail 'scheduler prestate is invalid' ;; esac
    case "$q_horizon_prestate" in up|paused|down) ;; *) fail 'Horizon prestate is invalid' ;; esac
    case "$q_nightwatch_prestate" in up|down) ;; *) fail 'Nightwatch prestate is invalid' ;; esac
}

assert_bound_runtime_identity()
{
    assert_regular_non_symlink "$q_operator_path" 'persisted backup quiesce operator'
    assert_regular_non_symlink "$q_controller_path" 'persisted backup quiesce controller'
    assert_regular_non_symlink "$q_service_unit_path" 'persisted backup quiesce service unit'
    assert_regular_non_symlink "$q_timer_unit_path" 'persisted backup quiesce timer unit'
    [ "$(sha256_file "$q_operator_path")" = "$q_operator_sha256" ] \
        && [ "$(sha256_file "$q_controller_path")" = "$q_controller_sha256" ] \
        && [ "$(sha256_file "$q_service_unit_path")" = "$q_service_unit_sha256" ] \
        && [ "$(sha256_file "$q_timer_unit_path")" = "$q_timer_unit_sha256" ] \
        || fail 'persisted backup quiesce operator/controller/unit identity changed'
    [ "$(container_id "$q_live_container")" = "$q_live_container_id" ] \
        && [ "$(container_image_id "$q_live_container")" = "$q_live_image_id" ] \
        && [ "$(container_label "$q_live_container" com.docker.compose.project)" = "$q_live_compose_project" ] \
        && [ "$(container_label "$q_live_container" com.docker.compose.service)" = "$q_live_compose_service" ] \
        || fail 'canonical live control-plane container identity changed during backup quiesce'
    [ "$(container_id "$q_database_container")" = "$q_database_container_id" ] \
        && [ "$(container_image_id "$q_database_container")" = "$q_database_image_id" ] \
        || fail 'canonical control-plane database container identity changed during backup quiesce'
    [ "$(container_id "$q_realtime_container")" = "$q_realtime_container_id" ] \
        && [ "$(container_image_id "$q_realtime_container")" = "$q_realtime_image_id" ] \
        || fail 'canonical control-plane realtime container identity changed during backup quiesce'
    [ "$(database_command_psql --command 'SELECT system_identifier::text FROM pg_control_system()' | tr -d '[:space:]')" = "$q_database_system_identifier" ] \
        && [ "$(database_command_psql --command 'SELECT oid::text FROM pg_database WHERE datname = current_database()' | tr -d '[:space:]')" = "$q_database_oid" ] \
        && [ "$(database_command_psql --command 'SELECT current_database()' | tr -d '[:space:]')" = "$q_database_name" ] \
        || fail 'canonical control-plane PostgreSQL identity changed during backup quiesce'
    [ "$(database_role_psql "SELECT oid::text FROM pg_roles WHERE rolname = :'application_role'" | tr -d '[:space:]')" = "$q_application_database_role_oid" ] \
        || fail 'canonical application database role identity changed during backup quiesce'
}

lease_owner_present()
{
    observed_owner_start_sha256=$(process_start_identity "$q_owner_pid" 2>/dev/null) \
        || return 1
    [ "$observed_owner_start_sha256" = "$q_owner_start_sha256" ]
}

lease_is_ownerless()
{
    observed_boot_id=$(current_boot_id)
    if [ "$observed_boot_id" = "$q_acquire_boot_id" ] && lease_owner_present; then
        return 1
    fi
    return 0
}

assert_quiesced_state()
{
    now=$(date -u +%s)
    [ "$now" -lt "$q_expires_unix" ] || fail 'backup quiesce lease expired'
    if [ "$(current_boot_id)" != "$q_acquire_boot_id" ] || ! lease_owner_present; then
        fail 'backup quiesce lease owner is no longer alive on the acquiring boot'
    fi
    assert_bound_runtime_identity
    [ "$(container_running "$q_live_container")" = true ] \
        && [ "$(container_running "$q_database_container")" = true ] \
        || fail 'control-plane application or database stopped during backup quiesce'
    [ "$(container_running "$q_realtime_container")" = false ] \
        || fail 'control-plane realtime writer is running during backup quiesce'
    [ "$(maintenance_state)" = "present:$q_fence_sha256" ] \
        || fail 'control-plane maintenance fence changed during backup quiesce'
    [ "$(service_state scheduler-worker)" = down ] \
        && [ "$(horizon_state)" = down ] \
        && [ "$(service_state nightwatch-agent)" = down ] \
        || fail 'control-plane background writers are not fully stopped'
    [ "$(reserved_work_count)" = 0 ] \
        && [ "$(active_mutation_count)" = 0 ] \
        || fail 'control-plane active mutations reappeared during backup quiesce'
    [ "$(role_read_only_setting)" = on ] \
        || fail 'application database role is not default read-only during backup quiesce'
    [ "$(active_writer_session_count)" = 0 ] \
        || fail 'an application-role writer transaction exists after the backup fence'
}

assert_capture_budget_remaining()
{
    remaining_lease_seconds=$((q_expires_unix - $(date -u +%s)))
    required_remaining_seconds=$((q_minimum_capture_seconds + LEASE_CONTROLLER_MARGIN_SECONDS))
    [ "$remaining_lease_seconds" -ge "$required_remaining_seconds" ] \
        || fail "backup quiesce lease lacks the required capture budget after acquisition (remaining_seconds=$remaining_lease_seconds required_seconds=$required_remaining_seconds)"
}

test_acquire_failure()
{
    [ "$q_test_mode" = 1 ] || return 0
    [ "${CONTROL_PLANE_TEST_BACKUP_QUIESCE_FAIL_AT:-}" != "$1" ] \
        || fail "injected backup quiesce acquire failure: $1"
}

test_restore_failure()
{
    [ "$q_test_mode" = 1 ] || return 0
    [ "${CONTROL_PLANE_TEST_BACKUP_QUIESCE_FAIL_AT:-}" != "$1" ] \
        || fail "injected backup quiesce restore failure: $1"
}

apply_quiesce()
{
    q_phase=acquiring
    write_state
    enter_maintenance_fence
    write_state
    test_acquire_failure after-maintenance
    service_down scheduler-worker
    wait_for_scheduler_idle
    if [ "$(horizon_state)" != down ]; then
        pause_horizon
    fi
    wait_for_active_mutations
    terminate_horizon
    service_down nightwatch-agent
    if [ "$(container_running "$q_realtime_container")" = true ]; then
        bounded_realtime_stop docker stop --time "$q_realtime_stop_seconds" \
            "$q_realtime_container" >/dev/null
    fi
    wait_for_active_mutations
    test_acquire_failure after-drain
    set_role_read_only
    terminate_application_sessions
    [ "$(role_read_only_setting)" = on ] \
        || fail 'application database role did not become default read-only'
    [ "$(active_writer_session_count)" = 0 ] \
        || fail 'application-role writer sessions remained after the backup fence'
    test_acquire_failure after-role-read-only
    q_phase=acquired
    write_state
    assert_quiesced_state
    assert_capture_budget_remaining
}

restore_service_prestate()
{
    service_name=$1
    service_prestate=$2
    case "$service_prestate" in
        up) service_up "$service_name" ;;
        down) service_down "$service_name" ;;
        *) fail "invalid service prestate for restore: $service_name" ;;
    esac
}

restore_quiesce_state()
{
    acquisition_owner_guard=0
    if [ "$q_phase" = released ]; then
        return
    fi
    revoke_remote_mutations
    if [ "$q_phase" = prepared ]; then
        [ "$q_fence_sha256" = none ] \
            || fail 'prepared quiesce unexpectedly persisted a maintenance fence'
        finalize_release
        return
    fi
    if [ "$q_phase" = finalizing ]; then
        if maintenance_matches_prestate; then
            finalize_release
            return
        fi
        [ "$q_fence_sha256" != none ] \
            && [ "$(maintenance_state)" = "present:$q_fence_sha256" ] \
            || fail 'finalizing quiesce has neither the exact fence nor restored maintenance prestate'
        restore_maintenance
        test_restore_failure after-maintenance-restore
        finalize_release
        return
    fi
    q_phase=releasing
    write_state
    assert_bound_runtime_identity
    [ "$q_fence_sha256" = none ] \
        || [ "$(maintenance_state)" = "present:$q_fence_sha256" ] \
        || fail 'secretless maintenance fence changed before quiesce restore'
    set_role_read_only
    terminate_application_sessions
    [ "$(role_read_only_setting)" = on ] \
        || fail 'application database role was not re-fenced before service restore'
    restore_service_prestate scheduler-worker down
    restore_service_prestate nightwatch-agent down
    if [ "$(container_running "$q_realtime_container")" = true ]; then
        bounded_realtime_stop docker stop --time "$q_realtime_stop_seconds" \
            "$q_realtime_container" >/dev/null
    fi
    restore_horizon
    restore_service_prestate scheduler-worker "$q_scheduler_prestate"
    restore_service_prestate nightwatch-agent "$q_nightwatch_prestate"
    case "$q_realtime_prestate" in
        running)
            [ "$(container_running "$q_realtime_container")" = true ] \
                || bounded_realtime_start docker start "$q_realtime_container" >/dev/null
            ;;
        stopped)
            if [ "$(container_running "$q_realtime_container")" = true ]; then
                bounded_realtime_stop docker stop --time "$q_realtime_stop_seconds" \
                    "$q_realtime_container" >/dev/null
            fi
            ;;
    esac
    [ "$(reserved_work_count)" = 0 ] \
        && [ "$(active_mutation_count)" = 0 ] \
        || fail 'control-plane work became active while restoring under the fence'
    if [ "$q_horizon_prestate" = paused ]; then
        [ "$(horizon_master_state)" = paused ] \
            || fail 'paused Horizon prestate was not proven before database write restoration'
    fi
    if [ "${test_hold_before_database_restore_seconds:-0}" -gt 0 ]; then
        sleep "$test_hold_before_database_restore_seconds"
    fi
    restore_role_read_only
    terminate_application_sessions
    [ "$(role_read_only_setting)" = "$q_role_read_only_prestate" ] \
        || fail 'application database role prestate was not restored'
    q_phase=finalizing
    write_state
    restore_maintenance
    test_restore_failure after-maintenance-restore
    finalize_release
}

start_watchdog()
{
    if [ "$q_watchdog_owner" = detached-test ]; then
        nohup "$q_controller_path" watchdog-wait \
            --state-directory "$quiesce_state_directory" \
            --operation-id "$q_operation_id" 8>&- </dev/null >/dev/null 2>&1 &
        watchdog_pid=$!
        kill -0 "$watchdog_pid" >/dev/null 2>&1 \
            || fail 'detached lab backup quiesce watchdog did not start'
        if [ -n "$watchdog_pid_file" ]; then
            printf '%s\n' "$watchdog_pid" > "$watchdog_pid_file"
            chmod 600 "$watchdog_pid_file"
        fi
        return
    fi
}

acquire_cleanup()
{
    cleanup_status=$?
    trap - EXIT HUP INT TERM
    acquisition_owner_guard=0
    if [ "${acquire_cleanup_armed:-0}" = 1 ] \
        && [ -f "${quiesce_state_file:-/nonexistent}" ]; then
        load_state
        if ! cleanup_output=$( (restore_quiesce_state) 2>&1); then
            printf 'CONTROL_PLANE_BACKUP_QUIESCE_CLEANUP_FAILURE operation_id=%s;state_preserved=true\n' \
                "$q_operation_id" >&2
            [ -z "$cleanup_output" ] || printf '%s\n' "$cleanup_output" >&2
            cleanup_status=1
        fi
        if ! mutation_cleanup_output=$( (
            load_state
            revoke_remote_mutations
            cleanup_local_mutation_inputs
        ) 2>&1); then
            printf 'CONTROL_PLANE_BACKUP_QUIESCE_CLEANUP_FAILURE operation_id=%s;mutation_residue_preserved=true\n' \
                "$q_operation_id" >&2
            [ -z "$mutation_cleanup_output" ] \
                || printf '%s\n' "$mutation_cleanup_output" >&2
            cleanup_status=1
        fi
    elif [ "${acquire_cleanup_armed:-0}" = 1 ] \
        && [ -d "${quiesce_operation_directory:-/nonexistent}" ] \
        && [ ! -L "$quiesce_operation_directory" ]; then
        rm -f "$quiesce_operation_directory/.maintenance-prestate.$$" \
            "$quiesce_operation_directory/.mutation-input.$$" \
            "$quiesce_operation_directory/.state.$$" \
            "$quiesce_operation_directory/maintenance-prestate"
        rmdir "$quiesce_operation_directory" \
            || fail 'failed to remove an uncommitted backup quiesce operation directory'
    fi
    release_global_lock >/dev/null 2>&1 || true
    exit "$cleanup_status"
}

print_acquired()
{
    printf 'backup-quiesce=acquired;operation_id=%s;fencing_token_sha256=%s;lease_acquired_unix=%s;lease_expires_unix=%s\n' \
        "$q_operation_id" "$q_token_sha256" "$q_acquired_unix" "$q_expires_unix"
}

print_status()
{
    printf 'backup-quiesce=status-passed;operation_id=%s;fencing_token_sha256=%s;lease_expires_unix=%s\n' \
        "$q_operation_id" "$q_token_sha256" "$q_expires_unix"
}

print_released()
{
    printf 'backup-quiesce=released;operation_id=%s;fencing_token_sha256=%s;released_unix=%s\n' \
        "$q_operation_id" "$q_token_sha256" "$q_released_unix"
}

assert_token()
{
    supplied_token_sha256=$(printf '%s' "$fencing_token" | sha256sum | awk '{print $1}')
    [ "$supplied_token_sha256" = "$q_token_sha256" ] \
        || fail 'backup quiesce fencing token does not match durable state'
}

reap_active_if_expired()
{
    active_name=$(active_operation)
    [ "$active_name" != none ] || return 0
    saved_operation_id=$operation_id
    operation_id=$active_name
    set_operation_paths
    assert_operation_directory
    load_state
    now=$(date -u +%s)
    if [ "$q_phase" != released ] \
        && { [ "$now" -ge "$q_expires_unix" ] || lease_is_ownerless; }; then
        restore_quiesce_state
    elif [ "$q_phase" = released ]; then
        clear_active_operation
    else
        operation_id=$saved_operation_id
        set_operation_paths
        return
    fi
    operation_id=$saved_operation_id
    set_operation_paths
}

acquire_action()
{
    load_public_configuration
    validate_lease_budget
    set_operation_paths
    acquire_global_lock
    assert_pinned_runtime
    reap_active_if_expired
    active_name=$(active_operation)
    if [ "$active_name" != none ]; then
        [ "$active_name" = "$operation_id" ] \
            || fail "another unexpired backup quiesce operation is active: $active_name"
        assert_operation_directory
        load_state
        assert_token
        [ "$q_phase" = acquired ] || fail 'matching backup quiesce operation is not acquired'
        assert_quiesced_state
        print_acquired
        release_global_lock
        return
    fi
    [ ! -e "$quiesce_operation_directory" ] && [ ! -L "$quiesce_operation_directory" ] \
        || fail 'backup quiesce operation ID was already used'
    mkdir "$quiesce_operation_directory"
    chmod 700 "$quiesce_operation_directory"
    assert_operation_directory
    q_phase=prepared
    q_test_mode=$test_mode
    q_operation_id=$operation_id
    q_token_sha256=$(printf '%s' "$fencing_token" | sha256sum | awk '{print $1}')
    q_acquired_unix=$(date -u +%s)
    q_expires_unix=$((q_acquired_unix + lease_seconds))
    q_released_unix=none
    q_operator_path=$operator_path
    q_operator_sha256=$operator_sha256
    q_controller_path=$controller_path
    q_controller_sha256=$expected_controller_sha256
    q_service_unit_path=$service_unit_path
    q_service_unit_sha256=$expected_service_unit_sha256
    q_timer_unit_path=$timer_unit_path
    q_timer_unit_sha256=$expected_timer_unit_sha256
    q_drain_timeout_seconds=$drain_timeout_seconds
    q_probe_timeout_seconds=$probe_timeout_seconds
    q_s6_wait_milliseconds=$s6_wait_milliseconds
    q_realtime_stop_seconds=$realtime_stop_seconds
    q_minimum_capture_seconds=$minimum_capture_seconds
    q_lease_acquisition_bound_seconds=$lease_acquisition_bound_seconds
    q_acquire_boot_id=$boot_id
    q_owner_pid=$lease_owner_pid
    q_owner_start_sha256=$lease_owner_start_sha256
    acquisition_owner_guard=1
    if [ "$test_mode" = 1 ] && [ "$test_systemd_watchdog" = 0 ]; then
        q_watchdog_owner=detached-test
    else
        q_watchdog_owner=systemd
    fi
    acquire_cleanup_armed=1
    trap acquire_cleanup EXIT HUP INT TERM
    capture_identity_and_prestate
    assert_acquisition_owner_alive
    write_state
    write_active_operation
    start_watchdog
    assert_acquisition_owner_alive
    if [ "$test_hold_after_watchdog_seconds" -gt 0 ]; then
        acquisition_guarded_sleep "$test_hold_after_watchdog_seconds"
    fi
    assert_capture_budget_remaining
    apply_quiesce
    acquisition_owner_guard=0
    acquire_cleanup_armed=0
    trap - EXIT HUP INT TERM
    print_acquired
    release_global_lock
}

status_action()
{
    load_public_configuration
    set_operation_paths
    acquire_global_lock
    assert_pinned_runtime
    assert_operation_directory
    load_state
    assert_token
    [ "$q_phase" = acquired ] || fail 'backup quiesce operation is not acquired'
    assert_quiesced_state
    print_status
    release_global_lock
}

release_action()
{
    load_public_configuration
    set_operation_paths
    acquire_global_lock
    assert_pinned_runtime
    assert_operation_directory
    load_state
    assert_token
    restore_quiesce_state
    print_released
    release_global_lock
}

watchdog_release_operation()
{
    operation_id=$1
    set_operation_paths
    assert_operation_directory
    load_state
    if [ "$q_phase" = released ]; then
        clear_active_operation
        return
    fi
    now=$(date -u +%s)
    if [ "$now" -lt "$q_expires_unix" ] && ! lease_is_ownerless; then
        return 0
    fi
    restore_attempt=0
    while [ "$restore_attempt" -lt 60 ]; do
        if bounded_external docker info >/dev/null 2>&1 \
            && bounded_external docker inspect "$q_live_container" >/dev/null 2>&1 \
            && bounded_external docker inspect "$q_database_container" >/dev/null 2>&1 \
            && bounded_external docker inspect "$q_realtime_container" >/dev/null 2>&1 \
            && database_command_psql --command 'SELECT 1' >/dev/null 2>&1; then
            restore_quiesce_state
            return
        fi
        restore_attempt=$((restore_attempt + 1))
        sleep 2
    done
    fail "expired backup quiesce could not be restored within the watchdog bound: $operation_id"
}

watchdog_scan()
{
    test_mode=${CONTROL_PLANE_TEST_MODE:-0}
    quiesce_state_directory=$1
    quiesce_active_file="$quiesce_state_directory/active"
    prepare_state_directory
    acquire_global_lock
    active_name=$(active_operation)
    if [ "$active_name" != none ]; then
        watchdog_release_operation "$active_name"
    fi
    release_global_lock
}

watchdog_wait()
{
    test_mode=1
    quiesce_state_directory=$1
    operation_id=$2
    prepare_state_directory
    set_operation_paths
    while :; do
        [ -f "$quiesce_state_file" ] || exit 0
        load_state
        [ "$q_phase" != released ] || exit 0
        now=$(date -u +%s)
        if [ "$now" -ge "$q_expires_unix" ] || lease_is_ownerless; then
            set +e
            (
                set -e
                acquire_global_lock
                load_state
                watchdog_release_operation "$operation_id"
                release_global_lock
            )
            watchdog_retry_status=$?
            set -e
            if [ "$watchdog_retry_status" -eq 0 ]; then
                exit 0
            fi
            sleep 1
            continue
        fi
        sleep 1
    done
}

parse_public_action()
{
    action=$1
    shift
    case "$action" in
        acquire)
            [ "$#" -eq 6 ] \
                && [ "$1" = --operation-id ] \
                && [ "$3" = --fencing-token ] \
                && [ "$5" = --lease-seconds ] \
                || fail 'backup-quiesce acquire arguments are malformed or out of order'
            operation_id=$2
            fencing_token=$4
            lease_seconds=$6
            ;;
        status|release)
            [ "$#" -eq 4 ] \
                && [ "$1" = --operation-id ] \
                && [ "$3" = --fencing-token ] \
                || fail "backup-quiesce $action arguments are malformed or out of order"
            operation_id=$2
            fencing_token=$4
            ;;
        *)
            fail 'backup-quiesce action must be acquire, status, or release'
            ;;
    esac
    validate_identifier "$operation_id" operation_id
    printf '%s' "$fencing_token" | grep -Eq '^[0-9a-f]{64}$' \
        || fail 'backup quiesce fencing token must contain exactly 64 lowercase hex characters'
    if [ "$action" = acquire ]; then
        validate_positive_integer "$lease_seconds" lease_seconds
        [ "$lease_seconds" -le 7200 ] \
            || fail 'backup quiesce lease must not exceed 7200 seconds'
    fi
}

main()
{
    require_command awk
    require_command cat
    require_command date
    require_command docker
    require_command flock
    require_command grep
    require_command kill
    require_command ps
    require_command sed
    require_command sha256sum
    require_command stat
    require_command sync
    require_command timeout
    require_command tr
    require_command wc
    [ "$#" -ge 1 ] || fail 'backup quiesce command is missing'
    case "$1" in
        acquire|status|release)
            action=$1
            parse_public_action "$@"
            case "$action" in
                acquire) acquire_action ;;
                status) status_action ;;
                release) release_action ;;
            esac
            ;;
        watchdog-scan)
            [ "$#" -eq 3 ] && [ "$2" = --state-directory ] \
                || fail 'watchdog-scan arguments are malformed'
            validate_path "$3" state_directory
            watchdog_scan "$3"
            ;;
        watchdog-wait)
            [ "$#" -eq 5 ] && [ "$2" = --state-directory ] && [ "$4" = --operation-id ] \
                || fail 'watchdog-wait arguments are malformed'
            validate_path "$3" state_directory
            validate_identifier "$5" operation_id
            watchdog_wait "$3" "$5"
            ;;
        *)
            fail 'unknown backup quiesce controller command'
            ;;
    esac
}

main "$@"
