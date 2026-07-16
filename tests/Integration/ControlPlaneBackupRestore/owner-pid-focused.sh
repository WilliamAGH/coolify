#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIRECTORY=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPOSITORY_ROOT=$(cd "$SCRIPT_DIRECTORY/../../.." && pwd)
TOOLS_IMAGE="control-plane-backup-owner-pid-$$"

cleanup()
{
    docker image rm "$TOOLS_IMAGE" >/dev/null 2>&1 || true
}
trap cleanup EXIT HUP INT TERM

docker build --quiet --tag "$TOOLS_IMAGE" --file "$SCRIPT_DIRECTORY/Dockerfile.tools" \
    "$REPOSITORY_ROOT" >/dev/null
docker run --rm --hostname owner-pid-source \
    --env CONTROL_PLANE_BACKUP_QUIESCE_OWNER_PID=999999999 \
    --env GNUPGHOME=/source-gnupg \
    --tmpfs /plaintext:rw,noexec,nosuid,nodev,mode=0700,uid=0,gid=0 \
    --entrypoint bash "$TOOLS_IMAGE" -Eeuo pipefail -c '
        mkdir -m 700 /source-gnupg /offhost-gnupg /output /state /focused-bin
        ln -s /bin/false /focused-bin/docker
        cp -a /opt/lab-fixtures/control-plane-state/applications /state/
        cp -a /opt/lab-fixtures/control-plane-state/databases /state/
        cp -a /opt/lab-fixtures/control-plane-state/services /state/
        cp -a /opt/lab-fixtures/control-plane-state/ssh /state/
        for selected in applications databases services ssh; do
            chown 9999:0 "/state/$selected"
            chmod 700 "/state/$selected"
        done
        gpg --homedir /offhost-gnupg --batch --pinentry-mode loopback --passphrase "" \
            --quick-generate-key "Owner PID Focused Test" rsa2048 encr 0 >/dev/null 2>&1
        fingerprint=$(gpg --homedir /offhost-gnupg --batch --with-colons --fingerprint 2>/dev/null \
            | awk -F: '\''$1 == "fpr" { print toupper($10); exit }'\'')
        gpg --homedir /offhost-gnupg --batch --export "$fingerprint" \
            | gpg --homedir /source-gnupg --batch --import >/dev/null 2>&1
        operator=/opt/lab-hooks/owner-pid-quiesce.sh
        operator_sha256=$(sha256sum "$operator" | awk '\''{print $1}'\'')
        if PATH="/focused-bin:$PATH" /opt/control-plane-backup/capture.sh \
            --operation-id owner-pid-focused \
            --candidate-image-digest docker.io/library/postgres@sha256:3d0f7584ed7d04e27fa050d6683a74746608faf21f202be78460d679cc56461f \
            --expected-source-host-identity owner-pid-source \
            --database-container unavailable-database --database-user postgres \
            --database-name coolify --state-root /state \
            --state-path applications --state-path databases --state-path services --state-path ssh \
            --plaintext-tmpfs-root /plaintext --output-root /output \
            --recipient-fingerprint "$fingerprint" --restore-tool /opt/control-plane-backup/restore-attest.sh \
            --quiesce-operator "$operator" --expected-quiesce-operator-sha256 "$operator_sha256" \
            --quiesce-lease-seconds 1200 > /output/capture.log 2>&1; then
            printf "%s\n" "focused capture unexpectedly reached the unavailable database" >&2
            exit 1
        fi
        mapfile -t owner_events < /output/owner-pid.log
        [[ ${#owner_events[@]} == 3 \
            && ${owner_events[0]} =~ ^acquire:([1-9][0-9]*)$ ]]
        owner_pid=${BASH_REMATCH[1]}
        [[ $owner_pid != 999999999 \
            && ${owner_events[1]} == "status:$owner_pid" \
            && ${owner_events[2]} == "release:$owner_pid" ]]
        printf "ControlPlaneBackupRestore owner-pid-focused: PASS pid=%s actions=acquire,status,release\n" \
            "$owner_pid"
    '
