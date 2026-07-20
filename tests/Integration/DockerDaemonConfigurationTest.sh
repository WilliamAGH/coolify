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

real_chmod="$(command -v chmod)"
real_mv="$(command -v mv)"
cat > "$mock_bin/sync" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf 'sync:%s\n' "$*" >> "${DAEMON_CONFIG_COMMAND_LOG:?}"
if [[ "${DAEMON_CONFIG_FAIL_SYNC:-false}" == true ]] || { [[ "${DAEMON_CONFIG_FAIL_DIRECTORY_SYNC:-false}" == true ]] && [[ -d "$1" ]]; }; then
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
cat > "$mock_bin/chmod" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf 'chmod:%s\n' "$*" >> "${DAEMON_CONFIG_COMMAND_LOG:?}"
if [[ "${DAEMON_CONFIG_FAIL_CHMOD:-false}" == true ]]; then
    exit 73
fi
exec "${DAEMON_CONFIG_REAL_CHMOD:?}" "$@"
SH
cat > "$mock_bin/dockerd" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf 'dockerd:%s\n' "$*" >> "${DAEMON_CONFIG_COMMAND_LOG:?}"
config_file=''
while (( $# > 0 )); do
    if [[ "$1" == --config-file ]]; then
        config_file="$2"
        break
    fi
    shift
done
[[ -n "$config_file" ]] || exit 74
jq -e '
    all(. ["default-address-pools"][]?;
        (.base | split("/")) as $cidr
        | ($cidr | length) == 2
        and (($cidr[0] | split(".")) as $octets
            | ($octets | length) == 4
            and all($octets[]; (tonumber? // -1) >= 0 and (tonumber? // 256) <= 255))
        and (($cidr[1] | tonumber? // -1) >= 0 and ($cidr[1] | tonumber? // 33) <= 32)
        and (.size | type) == "number"
        and .size >= 16
        and .size <= 28
    )
' "$config_file" >/dev/null
SH
chmod +x "$mock_bin/sync" "$mock_bin/mv" "$mock_bin/chmod" "$mock_bin/dockerd"

export PATH="$mock_bin:$PATH"
export DAEMON_CONFIG_COMMAND_LOG="$command_log"
export DAEMON_CONFIG_REAL_CHMOD="$real_chmod"
export DAEMON_CONFIG_REAL_MV="$real_mv"

# Sourcing exposes the canonical owner without executing the installer.
# shellcheck source=/dev/null
source "$repository_root/scripts/install.sh"

[[ "$(coolify_cdn_for_release)" == 'https://cdn.coollabs.io/coolify' ]] || fail 'stable installer did not select the stable CDN owner'
[[ "$(coolify_cdn_for_release next)" == 'https://cdn.coollabs.io/coolify-nightly' ]] || fail 'next installer did not select the nightly CDN owner'
[[ "$(coolify_cdn_for_release 4.13.2)" == 'https://cdn.coollabs.io/coolify' ]] || fail 'versioned installer did not select the stable CDN owner'

merge_case="$state/merge/docker"
mkdir -p "$merge_case"
merge_config="$merge_case/daemon.json"
merge_sidecar="$merge_config.coolify"
write_valid_configuration "$merge_config"
cat > "$merge_sidecar" <<'JSON'
{
  "data-root": "/stale-sidecar",
  "features": {"containerd-snapshotter": false},
  "registry-mirrors": ["https://registry.example.test"],
  "log-opts": {"labels": "service"}
}
JSON

result="$(bash "$repository_root/scripts/install.sh" --configure-docker-daemon "$merge_config" "$merge_sidecar" '10.0.0.0/8' 24 false true)"
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
mapfile -t merge_commands < "$command_log"
[[ "${merge_commands[0]}" =~ ^dockerd:--validate\ --config-file\ .*/\.daemon\.json\.tmp\. ]] || fail 'candidate was not validated before publication'
[[ "${merge_commands[1]}" =~ ^chmod:600\ .*/\.daemon\.json\.tmp\. ]] || fail 'candidate mode was not restricted before publication'
[[ "${merge_commands[2]}" =~ ^sync:.*/\.daemon\.json\.tmp\. ]] || fail 'candidate file was not durably synced before replacement'
[[ "${merge_commands[3]}" =~ ^mv:-f\ .*/\.daemon\.json\.tmp\.[^\ ]+\ .*/daemon\.json$ ]] || fail 'daemon configuration was not atomically replaced from the same directory'
[[ "${merge_commands[4]}" =~ ^sync:.*/docker$ ]] || fail 'configuration directory was not synced after replacement'
assert_no_temporary_files "$merge_case"

force_case="$state/force/docker"
mkdir -p "$force_case"
force_config="$force_case/daemon.json"
write_valid_configuration "$force_config"
result="$(configure_docker_daemon "$force_config" "$force_config.coolify" '10.42.0.0/16' 26 true)"
[[ "$result" == changed ]] || fail 'forced pool replacement did not report a change'
jq -e '.["default-address-pools"] == [{"base": "10.42.0.0/16", "size": 26}]' "$force_config" >/dev/null \
    || fail 'forced address pool was not enforced'

new_case="$state/new/docker"
new_config="$new_case/daemon.json"
result="$(configure_docker_daemon "$new_config" "$new_config.coolify" '10.52.0.0/16' 25 false)"
[[ "$result" == changed ]] || fail 'missing daemon configuration did not report a change'
jq -e '
    .["log-driver"] == "json-file" and
    .["log-opts"] == {"max-file": "3", "max-size": "10m"} and
    .["default-address-pools"] == [{"base": "10.52.0.0/16", "size": 25}]
' "$new_config" >/dev/null || fail 'missing daemon configuration did not receive canonical defaults'
assert_no_temporary_files "$new_case"

no_pool_case="$state/no-pool/docker"
mkdir -p "$no_pool_case"
no_pool_config="$no_pool_case/daemon.json"
printf '{"data-root":"/srv/docker"}\n' > "$no_pool_config"
result="$(configure_docker_daemon "$no_pool_config" "$no_pool_config.coolify" '10.0.0.0/8' 24 false false)"
[[ "$result" == changed ]] || fail 'log-only configuration did not report a change'
jq -e '.["data-root"] == "/srv/docker" and has("default-address-pools") == false' "$no_pool_config" >/dev/null \
    || fail 'log-only configuration unexpectedly created an address pool'

invalid_cidr_case="$state/invalid-cidr/docker"
mkdir -p "$invalid_cidr_case"
invalid_cidr_config="$invalid_cidr_case/daemon.json"
write_valid_configuration "$invalid_cidr_config"
invalid_cidr_before="$(cksum "$invalid_cidr_config")"
if configure_docker_daemon "$invalid_cidr_config" "$invalid_cidr_config.coolify" '999.999.999.999/99' 24 true >/dev/null 2>&1; then
    fail 'dockerd-invalid address pool unexpectedly succeeded'
fi
[[ "$(cksum "$invalid_cidr_config")" == "$invalid_cidr_before" ]] || fail 'dockerd-invalid address pool changed the daemon configuration'
assert_no_temporary_files "$invalid_cidr_case"

symlink_case="$state/symlink/docker"
mkdir -p "$symlink_case"
symlink_target="$symlink_case/operator-daemon.json"
symlink_config="$symlink_case/daemon.json"
write_valid_configuration "$symlink_target"
symlink_target_before="$(cksum "$symlink_target")"
symlink_target_inode="$(inode_of "$symlink_target")"
ln -s "$symlink_target" "$symlink_config"
if configure_docker_daemon "$symlink_config" "$symlink_config.coolify" '10.0.0.0/8' 24 false >/dev/null 2>&1; then
    fail 'symlinked daemon configuration unexpectedly succeeded'
fi
[[ -L "$symlink_config" ]] || fail 'symlinked daemon configuration was replaced'
[[ "$(cksum "$symlink_target")" == "$symlink_target_before" ]] || fail 'symlink target content changed'
[[ "$(inode_of "$symlink_target")" == "$symlink_target_inode" ]] || fail 'symlink target inode changed'
assert_no_temporary_files "$symlink_case"

sidecar_symlink_case="$state/sidecar-symlink/docker"
mkdir -p "$sidecar_symlink_case"
sidecar_symlink_config="$sidecar_symlink_case/daemon.json"
sidecar_symlink_target="$sidecar_symlink_case/operator-sidecar.json"
sidecar_symlink="$sidecar_symlink_config.coolify"
write_valid_configuration "$sidecar_symlink_config"
printf '{"registry-mirrors":["https://registry.example.test"]}\n' > "$sidecar_symlink_target"
sidecar_symlink_before="$(cksum "$sidecar_symlink_target")"
sidecar_symlink_inode="$(inode_of "$sidecar_symlink_target")"
ln -s "$sidecar_symlink_target" "$sidecar_symlink"
if configure_docker_daemon "$sidecar_symlink_config" "$sidecar_symlink" '10.0.0.0/8' 24 false >/dev/null 2>&1; then
    fail 'symlinked daemon sidecar unexpectedly succeeded'
fi
[[ -L "$sidecar_symlink" ]] || fail 'symlinked daemon sidecar was replaced'
[[ "$(cksum "$sidecar_symlink_target")" == "$sidecar_symlink_before" ]] || fail 'sidecar symlink target content changed'
[[ "$(inode_of "$sidecar_symlink_target")" == "$sidecar_symlink_inode" ]] || fail 'sidecar symlink target inode changed'
assert_no_temporary_files "$sidecar_symlink_case"

for dangling_name in daemon.json daemon.json.coolify; do
    dangling_case="$state/dangling-${dangling_name//./-}/docker"
    mkdir -p "$dangling_case"
    dangling_config="$dangling_case/daemon.json"
    dangling_sidecar="$dangling_config.coolify"
    if [[ "$dangling_name" == daemon.json ]]; then
        ln -s "$dangling_case/missing-target" "$dangling_config"
    else
        write_valid_configuration "$dangling_config"
        ln -s "$dangling_case/missing-sidecar" "$dangling_sidecar"
    fi
    if configure_docker_daemon "$dangling_config" "$dangling_sidecar" '10.0.0.0/8' 24 false >/dev/null 2>&1; then
        fail "dangling $dangling_name symlink unexpectedly succeeded"
    fi
    [[ -L "$dangling_case/$dangling_name" ]] || fail "dangling $dangling_name symlink was replaced"
    assert_no_temporary_files "$dangling_case"
done

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

for failed_command in sync mv chmod; do
    failure_case="$state/fail-$failed_command/docker"
    mkdir -p "$failure_case"
    failure_config="$failure_case/daemon.json"
    write_valid_configuration "$failure_config"
    failure_before="$(cksum "$failure_config")"
    failure_inode="$(inode_of "$failure_config")"

    failure_variable="DAEMON_CONFIG_FAIL_${failed_command^^}"
    export "$failure_variable=true"
    if configure_docker_daemon "$failure_config" "$failure_config.coolify" '10.0.0.0/8' 24 false >/dev/null 2>&1; then
        fail "$failed_command failure injection unexpectedly succeeded"
    fi
    unset "$failure_variable"
    [[ "$(cksum "$failure_config")" == "$failure_before" ]] || fail "$failed_command failure changed file content"
    [[ "$(inode_of "$failure_config")" == "$failure_inode" ]] || fail "$failed_command failure replaced the inode"
    assert_no_temporary_files "$failure_case"
done

directory_sync_case="$state/fail-directory-sync/docker"
mkdir -p "$directory_sync_case"
directory_sync_config="$directory_sync_case/daemon.json"
write_valid_configuration "$directory_sync_config"
export DAEMON_CONFIG_FAIL_DIRECTORY_SYNC=true
result="$(configure_docker_daemon "$directory_sync_config" "$directory_sync_config.coolify" '10.0.0.0/8' 24 false 2> "$directory_sync_case/error.log")"
unset DAEMON_CONFIG_FAIL_DIRECTORY_SYNC
[[ "$result" == changed ]] || fail 'committed configuration did not report changed after directory sync failure'
grep -q 'configuration was replaced, but its directory sync failed' "$directory_sync_case/error.log" \
    || fail 'directory sync failure did not emit a durability warning'
jq -e '.["log-driver"] == "json-file"' "$directory_sync_config" >/dev/null \
    || fail 'directory sync failure lost the committed configuration'
assert_no_temporary_files "$directory_sync_case"

printf 'DOCKER_DAEMON_CONFIGURATION_OK\n'
