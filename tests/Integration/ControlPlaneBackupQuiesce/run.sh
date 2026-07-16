#!/bin/sh

set -eu

SCRIPT_DIRECTORY=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)
exec "$SCRIPT_DIRECTORY/../ControlPlaneBlueGreen/run.sh" backup-quiesce
