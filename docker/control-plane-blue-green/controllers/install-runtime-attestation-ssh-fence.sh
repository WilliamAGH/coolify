#!/bin/sh

set -eu
umask 077

SCRIPT_DIRECTORY=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)
readonly SCRIPT_DIRECTORY
readonly BUNDLE_INSTALLER="$SCRIPT_DIRECTORY/../install-host-release-bundle.sh"

fail()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_INSTALL_FAILURE %s\n' "$1" >&2
    exit 1
}

[ "$#" -eq 1 ] \
    || fail 'component-only installation is retired; provide RELEASE_ID for the complete host release bundle'
[ -f "$BUNDLE_INSTALLER" ] && [ ! -L "$BUNDLE_INSTALLER" ] && [ -x "$BUNDLE_INSTALLER" ] \
    || fail 'complete host release bundle installer is unavailable or unsafe'

exec "$BUNDLE_INSTALLER" "$1"
