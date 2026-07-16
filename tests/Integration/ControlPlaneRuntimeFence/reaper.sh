#!/usr/bin/env bash
set -euo pipefail

[[ ${CONTROL_PLANE_RUNTIME_RETIRED_ROLE:-} =~ ^retired_[ab]$ ]]
[[ ${CONTROL_PLANE_RUNTIME_RETIRED_CONTAINER:-} =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]]
[[ ${CONTROL_PLANE_RUNTIME_RETIRED_ID:-} =~ ^[a-f0-9]{64}$ ]]
[[ -s ${CONTROL_PLANE_RUNTIME_NETWORK_INVENTORY:-} ]]
printf 'version=2\nrole=%s\ncontainer_name=%s\ncontainer_id=%s\n' \
    "$CONTROL_PLANE_RUNTIME_RETIRED_ROLE" "$CONTROL_PLANE_RUNTIME_RETIRED_CONTAINER" \
    "$CONTROL_PLANE_RUNTIME_RETIRED_ID"
printf 'terminated=0\nconntrack_scope=attested-bridge-subnets:tcp22\n'
