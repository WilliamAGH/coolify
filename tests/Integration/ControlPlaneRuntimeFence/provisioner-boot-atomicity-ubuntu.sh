#!/usr/bin/env bash

set -Eeuo pipefail

TEST_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
REPOSITORY_ROOT=$(cd -- "$TEST_DIRECTORY/../../.." && pwd -P)
readonly TEST_DIRECTORY REPOSITORY_ROOT
readonly UBUNTU_IMAGE=docker.io/library/ubuntu@sha256:4fbb8e6a8395de5a7550b33509421a2bafbc0aab6c06ba2cef9ebffbc7092d90

docker image inspect "$UBUNTU_IMAGE" >/dev/null \
    || { printf '%s\n' 'pinned Ubuntu 24.04 boot-atomicity test image is not present' >&2; exit 1; }

docker run --rm --volume "$REPOSITORY_ROOT:/workspace:ro" "$UBUNTU_IMAGE" \
    bash /workspace/tests/Integration/ControlPlaneRuntimeFence/provisioner-boot-atomicity-ubuntu-inner.sh
