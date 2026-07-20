#!/usr/bin/env bash

set -euo pipefail

repository_root="$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)"
state="$(mktemp -d "${TMPDIR:-/tmp}/coolify-daemon-config.XXXXXX")"
mock_bin="$state/bin"
command_log="$state/commands.log"

fail() {
    printf 'DOCKER_DAEMON_CONFIGURATION_FAILURE %s\n' "$1" >&2
    exit 1
}

cleanup() {
    rm -rf "$state"
}

inode_of() {
    stat -c '%i' "$1" 2>/dev/null || stat -f '%i' "$1"
}

mode_of() {
    stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"
}

assert_no_temporary_files() {
    local directory="$1"

    if find "$directory" -maxdepth 1 -name '.daemon.json.tmp.*' -print -quit | grep -q .; then
        fail "temporary daemon configuration remained in $directory"
    fi
}

write_valid_configuration() {
    local path="$1"

    cat > "$path" <<'JSON'
{
  "data-root": "/srv/docker",
  "features": {"containerd-snapshotter": true},
  "log-driver": "local",
  "log-opts": {"compress": "true", "max-size": "100m"},
  "default-address-pools": [{"base": "172.28.0.0/16", "size": 24}]
}
JSON
}

trap cleanup EXIT INT TERM
mkdir -p "$mock_bin"
: > "$command_log"

real_mv="$(command -v mv)"
cat > "$mock_bin/sync" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf 'sync:%s\n' "$*" >> "${DAEMON_CONFIG_COMMAND_LOG:?}"
if [[ "${DAEMON_CONFIG_FAIL_SYNC:-false}" == true ]]; then
    exit 71
fi
SH
cat > "$mock_bin/mv" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf 'mv:%s\n' "$*" >> "${DAEMON_CONFIG_COMMAND_LOG:?}"
if [[ "${DAEMON_CONFIG_FAIL_MV:-false}" == true ]]; then
    exit 72
fi
exec "${DAEMON_CONFIG_REAL_MV:?}" "$@"
SH
chmod +x "$mock_bin/sync" "$mock_bin/mv"

export PATH="$mock_bin:$PATH"
export DAEMON_CONFIG_COMMAND_LOG="$command_log"
export DAEMON_CONFIG_REAL_MV="$real_mv"

# Sourcing exposes the canonical owner without executing the installer.
# shellcheck source=/dev/null
source "$repository_root/scripts/install.sh"

merge_case="$state/merge/docker"
mkdir -p "$merge_case"
merge_config="$merge_case/daemon.json"
merge_sidecar="$merge_config.coolify"
write_valid_configuration "$merge_config"
cat > "$merge_sidecar" <<'JSON'
{
  "registry-mirrors": ["https://registry.example.test"],
  "log-opts": {"labels": "service"}
}
JSON

result="$(configure_docker_daemon "$merge_config" "$merge_sidecar" '10.0.0.0/8' 24 false)"
[[ "$result" == changed ]] || fail 'valid configuration merge did not report a change'
jq -e '
    .["data-root"] == "/srv/docker" and
    .features["containerd-snapshotter"] == true and
    .["registry-mirrors"] == ["https://registry.example.test"] and
    .["log-driver"] == "json-file" and
    .["log-opts"] == {
        "compress": "true",
        "labels": "service",
        "max-file": "3",
        "max-size": "10m"
    } and
    .["default-address-pools"] == [{"base": "172.28.0.0/16", "size": 24}]
' "$merge_config" >/dev/null || fail 'merge did not preserve unrelated keys and enforce Coolify settings'
[[ "$(mode_of "$merge_config")" == 600 ]] || fail 'written daemon configuration mode is not 0600'
grep -Eq '^sync:-f .*/\.daemon\.json\.tmp\.' "$command_log" || fail 'candidate file was not durably synced before replacement'
grep -Eq '^mv:-f .*/\.daemon\.json\.tmp\.[^ ]+ .*/daemon\.json$' "$command_log" || fail 'daemon configuration was not atomically replaced from the same directory'
grep -Eq '^sync:-f .*/docker$' "$command_log" || fail 'configuration directory was not synced after replacement'
assert_no_temporary_files "$merge_case"

force_case="$state/force/docker"
mkdir -p "$force_case"
force_config="$force_case/daemon.json"
write_valid_configuration "$force_config"
result="$(configure_docker_daemon "$force_config" "$force_config.coolify" '10.42.0.0/16' 26 true)"
[[ "$result" == changed ]] || fail 'forced pool replacement did not report a change'
jq -e '.["default-address-pools"] == [{"base": "10.42.0.0/16", "size": 26}]' "$force_config" >/dev/null \
    || fail 'forced address pool was not enforced'

malformed_case="$state/malformed/docker"
mkdir -p "$malformed_case"
for malformed_content in '{not-json' '{} {}'; do
    malformed_config="$malformed_case/daemon.json"
    printf '%s\n' "$malformed_content" > "$malformed_config"
    malformed_before="$(cksum "$malformed_config")"
    malformed_inode="$(inode_of "$malformed_config")"
    if configure_docker_daemon "$malformed_config" "$malformed_config.coolify" '10.0.0.0/8' 24 false >/dev/null 2>&1; then
        fail 'malformed existing daemon configuration unexpectedly succeeded'
    fi
    [[ "$(cksum "$malformed_config")" == "$malformed_before" ]] || fail 'malformed daemon configuration was replaced'
    [[ "$(inode_of "$malformed_config")" == "$malformed_inode" ]] || fail 'malformed daemon configuration inode changed'
    assert_no_temporary_files "$malformed_case"
done

sidecar_case="$state/malformed-sidecar/docker"
mkdir -p "$sidecar_case"
sidecar_config="$sidecar_case/daemon.json"
write_valid_configuration "$sidecar_config"
printf '["not-an-object"]\n' > "$sidecar_config.coolify"
sidecar_before="$(cksum "$sidecar_config")"
if configure_docker_daemon "$sidecar_config" "$sidecar_config.coolify" '10.0.0.0/8' 24 false >/dev/null 2>&1; then
    fail 'non-object sidecar unexpectedly succeeded'
fi
[[ "$(cksum "$sidecar_config")" == "$sidecar_before" ]] || fail 'invalid sidecar changed the daemon configuration'
assert_no_temporary_files "$sidecar_case"

noop_case="$state/noop/docker"
mkdir -p "$noop_case"
noop_config="$noop_case/daemon.json"
cat > "$noop_config" <<'JSON'
{"log-opts":{"max-file":"3","max-size":"10m"},"log-driver":"json-file","data-root":"/srv/docker","default-address-pools":[{"size":24,"base":"10.0.0.0/8"}]}
JSON
chmod 600 "$noop_config"
noop_before="$(cksum "$noop_config")"
noop_inode="$(inode_of "$noop_config")"
result="$(configure_docker_daemon "$noop_config" "$noop_config.coolify" '10.0.0.0/8' 24 false)"
[[ "$result" == unchanged ]] || fail 'semantic no-op did not report unchanged'
[[ "$(cksum "$noop_config")" == "$noop_before" ]] || fail 'semantic no-op changed file content'
[[ "$(inode_of "$noop_config")" == "$noop_inode" ]] || fail 'semantic no-op replaced the inode'
assert_no_temporary_files "$noop_case"

for failed_command in sync mv; do
    failure_case="$state/fail-$failed_command/docker"
    mkdir -p "$failure_case"
    failure_config="$failure_case/daemon.json"
    write_valid_configuration "$failure_config"
    failure_before="$(cksum "$failure_config")"
    failure_inode="$(inode_of "$failure_config")"

    # shellcheck disable=SC2016
    if env "DAEMON_CONFIG_FAIL_${failed_command^^}=true" bash -c '
        set -euo pipefail
        source "$1/scripts/install.sh"
        configure_docker_daemon "$2" "$2.coolify" "10.0.0.0/8" 24 false
    ' _ "$repository_root" "$failure_config" >/dev/null 2>&1; then
        fail "$failed_command failure injection unexpectedly succeeded"
    fi
    [[ "$(cksum "$failure_config")" == "$failure_before" ]] || fail "$failed_command failure changed file content"
    [[ "$(inode_of "$failure_config")" == "$failure_inode" ]] || fail "$failed_command failure replaced the inode"
    assert_no_temporary_files "$failure_case"
done

printf 'DOCKER_DAEMON_CONFIGURATION_OK\n'
