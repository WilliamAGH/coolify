#!/usr/bin/env bash

set -euo pipefail

TEST_DIRECTORY=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)
REPOSITORY_ROOT=$(CDPATH='' cd -- "$TEST_DIRECTORY/../../.." && pwd -P)
INSTALL_SCRIPT=$REPOSITORY_ROOT/scripts/install.sh
TEMPORARY_DIRECTORY=$(mktemp -d)
FUNCTION_HARNESS=$TEMPORARY_DIRECTORY/daemon-configuration-functions.sh
trap 'rm -rf "$TEMPORARY_DIRECTORY"' EXIT HUP INT TERM

fail() {
    printf 'install-docker-daemon-configuration: %s\n' "$1" >&2
    exit 1
}

awk '
    /^update_docker_daemon_configuration\(\)/ { copy = 1 }
    /^compare_address_pools\(\)/ { copy = 0 }
    copy { print }
' "$INSTALL_SCRIPT" > "$FUNCTION_HARNESS"
# shellcheck source=/dev/null
. "$FUNCTION_HARNESS"

daemon_path=$TEMPORARY_DIRECTORY/daemon.json
printf '%s\n' '{' \
    '  "default-address-pools": [{"base":"172.31.0.0/16","size":24}],' \
    '  "registry-mirrors": ["https://registry.example.test"],' \
    '  "insecure-registries": ["registry.lan:5000"],' \
    '  "features": {"containerd-snapshotter": true}' \
'}' > "$daemon_path"
chmod 0600 "$daemon_path"

update_docker_daemon_configuration "$daemon_path" 10.0.0.0/8 24 false \
    || fail 'existing address-pool merge failed'
[ "$(stat -c '%a' "$daemon_path" 2>/dev/null || stat -f '%Lp' "$daemon_path")" = 600 ] \
    || fail 'existing daemon configuration mode was not preserved'
jq -e '
    .["default-address-pools"] == [{"base":"172.31.0.0/16","size":24}]
    and .["registry-mirrors"] == ["https://registry.example.test"]
    and .["insecure-registries"] == ["registry.lan:5000"]
    and .features["containerd-snapshotter"] == true
    and .["log-driver"] == "json-file"
    and .["log-opts"] == {"max-size":"10m","max-file":"3"}
' "$daemon_path" >/dev/null \
    || fail 'existing address-pool merge lost custom daemon settings'

merged_inode=$(stat -c '%d:%i' "$daemon_path" 2>/dev/null || stat -f '%d:%i' "$daemon_path")
unchanged_status=0
update_docker_daemon_configuration "$daemon_path" 10.0.0.0/8 24 false || unchanged_status=$?
[ "$unchanged_status" -eq 10 ] \
    || fail 'idempotent daemon merge did not report unchanged state'
[ "$(stat -c '%d:%i' "$daemon_path" 2>/dev/null || stat -f '%d:%i' "$daemon_path")" = "$merged_inode" ] \
    || fail 'idempotent daemon merge replaced an unchanged file'

update_docker_daemon_configuration "$daemon_path" 10.88.0.0/16 24 true \
    || fail 'forced address-pool merge failed'
jq -e '
    .["default-address-pools"] == [{"base":"10.88.0.0/16","size":24}]
    and .["registry-mirrors"] == ["https://registry.example.test"]
    and .["insecure-registries"] == ["registry.lan:5000"]
    and .features["containerd-snapshotter"] == true
' "$daemon_path" >/dev/null \
    || fail 'forced address-pool merge lost custom registry settings'

for candidate_path in "$TEMPORARY_DIRECTORY"/.daemon.json.coolify.*; do
    [ ! -e "$candidate_path" ] && [ ! -L "$candidate_path" ] \
        || fail 'daemon merge left an unpublished temporary file'
done

printf '%s\n' '{not-json' > "$daemon_path"
malformed_before=$(sha256sum "$daemon_path" | awk '{print $1}')
malformed_status=0
update_docker_daemon_configuration "$daemon_path" 10.0.0.0/8 24 false || malformed_status=$?
[ "$malformed_status" -eq 2 ] \
    || fail 'malformed daemon configuration did not fail closed'
[ "$(sha256sum "$daemon_path" | awk '{print $1}')" = "$malformed_before" ] \
    || fail 'malformed daemon configuration was overwritten'

new_daemon_path=$TEMPORARY_DIRECTORY/new-daemon.json
update_docker_daemon_configuration "$new_daemon_path" 10.0.0.0/8 24 true \
    || fail 'new daemon configuration creation failed'
[ "$(stat -c '%a' "$new_daemon_path" 2>/dev/null || stat -f '%Lp' "$new_daemon_path")" = 600 ] \
    || fail 'new daemon configuration was not private'

printf '%s\n' 'install-docker-daemon-configuration: passed'
