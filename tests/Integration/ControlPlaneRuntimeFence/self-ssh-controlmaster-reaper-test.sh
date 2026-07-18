#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

TEST_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
readonly TEST_DIRECTORY
REPOSITORY_ROOT=$(cd -- "$TEST_DIRECTORY/../../.." && pwd -P)
readonly REPOSITORY_ROOT
readonly REAPER=$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/self-ssh-controlmaster-reaper.sh
readonly RETIRED_A_ID=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
readonly RETIRED_B_ID=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
readonly RETIRED_A_NAME=legacy
readonly RETIRED_B_NAME=retired-b
readonly CONTROL_PATH=/var/www/html/storage/app/ssh/mux/mux_11111111-2222-3333-4444-555555555555

fail()
{
    printf 'CONTROL_PLANE_SELF_SSH_REAPER_TEST_FAILURE %s\n' "$1" >&2
    exit 1
}

fixture=$(mktemp -d /tmp/control-plane-self-ssh-reaper.XXXXXX)
trap 'rm -rf "$fixture"' EXIT HUP INT TERM
proc_root=$fixture/proc
bin_directory=$fixture/bin
network_inventory=$fixture/network-inventory.tsv
signal_log=$fixture/signals.log
mkdir -p "$proc_root" "$bin_directory"
printf 'coolify\tbridge\t%s\t172.18.0.0/16\t172.18.0.1\n' \
    dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd > "$network_inventory"

cat > "$bin_directory/docker" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail

case "${1:-}" in
    inspect)
        [[ ${2:-} == --format && ${3:-} == '{{.Id}}' \
            && ${4:-} == "$CONTROL_PLANE_REAPER_TEST_RETIRED_CONTAINER" ]] || exit 1
        printf '%s\n' "$CONTROL_PLANE_REAPER_TEST_RETIRED_ID"
        ;;
    top)
        [[ ${2:-} == "$CONTROL_PLANE_REAPER_TEST_RETIRED_CONTAINER" \
            && ${3:-} == -eo && ${4:-} == pid,args ]] || exit 1
        for pid in $CONTROL_PLANE_REAPER_TEST_TOP_PIDS; do
            printf '%s %s\n' "$pid" \
                '/usr/bin/ssh -fN -o ControlMaster=auto -o ControlPath=/misleading root@host.docker.internal'
        done
        ;;
    *) exit 1 ;;
esac
EOF
cat > "$bin_directory/conntrack" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
printf '%s\n' "$*" >> "$CONTROL_PLANE_REAPER_TEST_CONNTRACK_LOG"
exit 1
EOF
cat > "$bin_directory/sleep" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
cat > "$fixture/bash-env" <<'EOF'
write_reaper_stat()
{
    local stat_file=$1 pid=$2 start_time=$3

    {
        printf '%s (ssh) S' "$pid"
        for _ in {1..18}; do
            printf ' 0'
        done
        printf ' %s 0 0 0\n' "$start_time"
    } > "$stat_file"
}

kill()
{
    local signal=$1 pid=$2

    printf '%s %s\n' "$signal" "$pid" >> "$CONTROL_PLANE_REAPER_TEST_SIGNAL_LOG"
    case "$signal:${CONTROL_PLANE_REAPER_TEST_TERM_BEHAVIOR:-remove}" in
        -TERM:remove)
            rm -rf -- "$CONTROL_PLANE_RUNTIME_PROC_ROOT/$pid"
            ;;
        -TERM:reuse)
            write_reaper_stat "$CONTROL_PLANE_RUNTIME_PROC_ROOT/$pid/stat" "$pid" 222
            ;;
    esac
}
EOF
chmod +x "$bin_directory/docker" "$bin_directory/conntrack" "$bin_directory/sleep"

write_stat()
{
    local pid=$1 start_time=$2

    {
        printf '%s (ssh) S' "$pid"
        for _ in {1..18}; do
            printf ' 0'
        done
        printf ' %s 0 0 0\n' "$start_time"
    } > "$proc_root/$pid/stat"
}

write_process()
{
    local pid=$1 target=$2 control_master=$3 control_path=$4 start_time=$5 retired_id=$6

    mkdir -p "$proc_root/$pid"
    printf '%s\0' /usr/bin/ssh -fN -o "$control_master" -o "ControlPath=$control_path" \
        -o ControlPersist=60 "$target" > "$proc_root/$pid/cmdline"
    printf '0::/docker/%s\n' "$retired_id" > "$proc_root/$pid/cgroup"
    write_stat "$pid" "$start_time"
}

run_reaper()
{
    local role=$1 container=$2 retired_id=$3

    PATH="$bin_directory:$PATH" \
    BASH_ENV="$fixture/bash-env" \
    CONTROL_PLANE_RUNTIME_RETIRED_ROLE="$role" \
    CONTROL_PLANE_RUNTIME_RETIRED_CONTAINER="$container" \
    CONTROL_PLANE_RUNTIME_RETIRED_ID="$retired_id" \
    CONTROL_PLANE_RUNTIME_NETWORK_INVENTORY="$network_inventory" \
    CONTROL_PLANE_RUNTIME_PROC_ROOT="$proc_root" \
    CONTROL_PLANE_RUNTIME_SELF_SSH_TARGET=host.docker.internal \
    CONTROL_PLANE_REAPER_TEST_RETIRED_CONTAINER="$container" \
    CONTROL_PLANE_REAPER_TEST_RETIRED_ID="$retired_id" \
    CONTROL_PLANE_REAPER_TEST_SIGNAL_LOG="$signal_log" \
    CONTROL_PLANE_REAPER_TEST_CONNTRACK_LOG="$fixture/conntrack.log" \
    "$REAPER"
}

write_process 101 root@host.docker.internal ControlMaster=auto "$CONTROL_PATH" 111 "$RETIRED_A_ID"
write_process 102 root@host.docker.internal.attacker ControlMaster=auto "$CONTROL_PATH" 111 "$RETIRED_A_ID"
write_process 103 root@host.docker.internal ControlMaster=auto /tmp/untrusted-mux 111 "$RETIRED_A_ID"

CONTROL_PLANE_REAPER_TEST_TOP_PIDS='101 102 103' \
CONTROL_PLANE_REAPER_TEST_TERM_BEHAVIOR=remove \
    run_reaper retired_a "$RETIRED_A_NAME" "$RETIRED_A_ID" > "$fixture/normal.out"
grep -F -x -q version=2 "$fixture/normal.out"
grep -F -x -q role=retired_a "$fixture/normal.out"
grep -F -x -q "container_name=$RETIRED_A_NAME" "$fixture/normal.out"
grep -F -x -q "container_id=$RETIRED_A_ID" "$fixture/normal.out"
grep -F -x -q terminated=1 "$fixture/normal.out"
grep -F -x -q conntrack_scope=attested-bridge-subnets:tcp22 "$fixture/normal.out"
grep -F -x -q -- '-TERM 101' "$signal_log"
if grep -F -q -- '102' "$signal_log" || grep -F -q -- '103' "$signal_log"; then
    fail 'a decoy ControlMaster received a signal'
fi
[[ ! -e $proc_root/101 && -d $proc_root/102 && -d $proc_root/103 ]] \
    || fail 'exact argv validation did not isolate the attested ControlMaster'

rm -rf "$proc_root"
mkdir -p "$proc_root"
: > "$signal_log"
write_process 151 root@host.docker.internal ControlMaster=auto "$CONTROL_PATH" 111 "$RETIRED_B_ID"
CONTROL_PLANE_REAPER_TEST_TOP_PIDS=151 \
CONTROL_PLANE_REAPER_TEST_TERM_BEHAVIOR=remove \
    run_reaper retired_b "$RETIRED_B_NAME" "$RETIRED_B_ID" > "$fixture/retired-b.out"
grep -F -x -q version=2 "$fixture/retired-b.out"
grep -F -x -q role=retired_b "$fixture/retired-b.out"
grep -F -x -q "container_name=$RETIRED_B_NAME" "$fixture/retired-b.out"
grep -F -x -q "container_id=$RETIRED_B_ID" "$fixture/retired-b.out"
grep -F -x -q terminated=1 "$fixture/retired-b.out"

rm -rf "$proc_root"
mkdir -p "$proc_root"
: > "$signal_log"
write_process 201 root@host.docker.internal ControlMaster=auto "$CONTROL_PATH" 111 "$RETIRED_A_ID"

if CONTROL_PLANE_REAPER_TEST_TOP_PIDS=201 \
    CONTROL_PLANE_REAPER_TEST_TERM_BEHAVIOR=reuse \
    run_reaper retired_a "$RETIRED_A_NAME" "$RETIRED_A_ID" > "$fixture/pid-reuse.out" 2>&1; then
    fail 'PID reuse was accepted for KILL escalation'
fi
grep -F -q 'refusing to KILL a PID whose start time changed: 201' "$fixture/pid-reuse.out"
grep -F -x -q -- '-TERM 201' "$signal_log"
if grep -F -q -- '-KILL 201' "$signal_log"; then
    fail 'a reused PID received KILL escalation'
fi

printf 'CONTROL_PLANE_SELF_SSH_REAPER_TEST passed\n'
