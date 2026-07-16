#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

fail()
{
    printf 'CONTROL_PLANE_SELF_SSH_REAPER_FAILURE %s\n' "$1" >&2
    exit 1
}

retired_role=${CONTROL_PLANE_RUNTIME_RETIRED_ROLE:-}
retired_container=${CONTROL_PLANE_RUNTIME_RETIRED_CONTAINER:-}
retired_id=${CONTROL_PLANE_RUNTIME_RETIRED_ID:-}
network_inventory=${CONTROL_PLANE_RUNTIME_NETWORK_INVENTORY:-}
self_ssh_target=${CONTROL_PLANE_RUNTIME_SELF_SSH_TARGET:-}
proc_root=${CONTROL_PLANE_RUNTIME_PROC_ROOT:-/proc}
readonly CONTROL_PATH_PREFIX='/var/www/html/storage/app/ssh/mux/mux_'

is_retired_container_process()
{
    local cgroup_file=$1

    [[ -r $cgroup_file ]] \
        && grep -E -q "(^|[^0-9a-f])${retired_id}([^0-9a-f]|$)" "$cgroup_file"
}

read_process_argv()
{
    local cmdline_file=$1

    candidate_argv=()
    [[ -r $cmdline_file ]] || return 1
    mapfile -d '' -t candidate_argv < "$cmdline_file"
    [[ ${#candidate_argv[@]} -gt 0 ]]
}

is_expected_control_path()
{
    local option=$1 control_path

    [[ $option == ControlPath="$CONTROL_PATH_PREFIX"* ]] || return 1
    control_path=${option#ControlPath=}
    [[ ${control_path#"$CONTROL_PATH_PREFIX"} =~ ^[A-Za-z0-9-]{1,128}$ ]]
}

is_expected_target()
{
    local target=$1 user suffix

    [[ $target == "$self_ssh_target" ]] && return 0
    suffix="@$self_ssh_target"
    [[ $target == *"$suffix" ]] || return 1
    user=${target%"$suffix"}
    [[ $user =~ ^[A-Za-z_][A-Za-z0-9_.-]{0,63}$ ]]
}

is_attested_controlmaster()
{
    local pid=$1 argument option executable last_index
    local index=0 control_master_count=0 control_path_count=0 has_background_mode=0

    is_retired_container_process "$proc_root/$pid/cgroup" || return 1
    read_process_argv "$proc_root/$pid/cmdline" || return 1

    executable=${candidate_argv[0]##*/}
    [[ $executable == ssh ]] || return 1
    [[ ${#candidate_argv[@]} -ge 2 ]] || return 1

    last_index=$((${#candidate_argv[@]} - 1))
    is_expected_target "${candidate_argv[$last_index]}" || return 1

    while [[ $index -lt ${#candidate_argv[@]} ]]; do
        argument=${candidate_argv[$index]}
        case "$argument" in
            -fN)
                has_background_mode=1
                ;;
            -o)
                index=$((index + 1))
                [[ $index -lt ${#candidate_argv[@]} ]] || return 1
                option=${candidate_argv[$index]}
                case "$option" in
                    ControlMaster=auto)
                        control_master_count=$((control_master_count + 1))
                        ;;
                    ControlPath=*)
                        is_expected_control_path "$option" || return 1
                        control_path_count=$((control_path_count + 1))
                        ;;
                esac
                ;;
            -oControlMaster=auto)
                control_master_count=$((control_master_count + 1))
                ;;
            -oControlPath=*)
                option=${argument#-o}
                is_expected_control_path "$option" || return 1
                control_path_count=$((control_path_count + 1))
                ;;
        esac
        index=$((index + 1))
    done

    [[ $has_background_mode -eq 1 && $control_master_count -eq 1 && $control_path_count -eq 1 ]]
}

process_start_time()
{
    local pid=$1 stat_file stat_line stat_tail
    local -a stat_fields=()

    stat_file=$proc_root/$pid/stat
    [[ -r $stat_file ]] || return 1
    IFS= read -r stat_line < "$stat_file" || return 1
    [[ $stat_line == "$pid ("* ]] || return 1
    stat_tail=${stat_line##*) }
    read -r -a stat_fields <<< "$stat_tail"
    [[ ${#stat_fields[@]} -ge 20 && ${stat_fields[19]} =~ ^[1-9][0-9]*$ ]] || return 1
    printf '%s\n' "${stat_fields[19]}"
}

[[ $retired_role =~ ^retired_[ab]$ ]] || fail 'retired pool role is unsafe'
[[ $retired_container =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]] \
    || fail 'retired container name is unsafe'
[[ $retired_id =~ ^[a-f0-9]{64}$ ]] || fail 'retired container ID is malformed'
[[ $self_ssh_target =~ ^[A-Za-z0-9_.:-]+$ ]] || fail 'self-SSH target is unsafe'
[[ -f $network_inventory && ! -L $network_inventory ]] \
    || fail 'network inventory is not a regular non-symlink file'
[[ -d $proc_root && ! -L $proc_root ]] || fail 'process root is not a directory'
[[ $(docker inspect --format '{{.Id}}' "$retired_container") == "$retired_id" ]] \
    || fail 'retired container identity changed before ControlMaster termination'

declare -a candidate_pid=()
declare -a candidate_start_time=()
declare -A seen_pid=()
top_output=$(docker top "$retired_container" -eo pid,args) \
    || fail 'docker top failed while enumerating retired ControlMasters'
candidate_output=$(awk '$1 ~ /^[1-9][0-9]*$/ { print $1 }' <<< "$top_output") \
    || fail 'retired process PID parsing failed'
while IFS= read -r pid; do
    [[ -n $pid ]] || continue
    [[ $pid =~ ^[1-9][0-9]*$ ]] || fail 'docker top returned a malformed PID'
    [[ -z ${seen_pid[$pid]+present} ]] || continue
    seen_pid[$pid]=1
    is_attested_controlmaster "$pid" || continue
    start_time=$(process_start_time "$pid") \
        || fail "candidate SSH PID has an unreadable start time: $pid"
    candidate_pid+=("$pid")
    candidate_start_time+=("$start_time")
done <<< "$candidate_output"

terminated=0
for index in "${!candidate_pid[@]}"; do
    pid=${candidate_pid[$index]}
    is_attested_controlmaster "$pid" \
        || fail "candidate SSH PID changed before termination: $pid"
    [[ $(process_start_time "$pid") == "${candidate_start_time[$index]}" ]] \
        || fail "candidate SSH PID start time changed before termination: $pid"
    kill -TERM "$pid"
    terminated=$((terminated + 1))
done

for _ in 1 2 3 4 5; do
    remaining=0
    for pid in "${candidate_pid[@]}"; do
        [[ -e $proc_root/$pid ]] && remaining=$((remaining + 1))
    done
    [[ $remaining -eq 0 ]] && break
    sleep 0.2
done

for index in "${!candidate_pid[@]}"; do
    pid=${candidate_pid[$index]}
    if [[ -e $proc_root/$pid ]]; then
        [[ $(process_start_time "$pid") == "${candidate_start_time[$index]}" ]] \
            || fail "refusing to KILL a PID whose start time changed: $pid"
        is_attested_controlmaster "$pid" \
            || fail "refusing to KILL a reused or changed PID: $pid"
        kill -KILL "$pid"
    fi
done

while IFS=$'\t' read -r _ _ _ _ subnet gateway; do
    if [[ $subnet == *:* ]]; then
        family=ipv6
    else
        family=ipv4
    fi
    status=0
    conntrack --delete --family "$family" --proto tcp --orig-src "$subnet" \
        --orig-dst "$gateway" --dport 22 \
        >/dev/null 2>&1 || status=$?
    [[ $status -eq 0 || $status -eq 1 ]] \
        || fail "conntrack deletion failed for attested subnet: $subnet"
done < "$network_inventory"

printf 'version=2\nrole=%s\ncontainer_name=%s\ncontainer_id=%s\n' \
    "$retired_role" "$retired_container" "$retired_id"
printf 'terminated=%s\nconntrack_scope=attested-bridge-subnets:tcp22\n' "$terminated"
