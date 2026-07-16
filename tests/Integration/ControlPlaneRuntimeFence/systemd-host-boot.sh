#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

readonly EVIDENCE_DIRECTORY=/evidence
readonly GENERATION_FILE=$EVIDENCE_DIRECTORY/boot-generation
readonly BOOT_RECORD=$EVIDENCE_DIRECTORY/boot-record
readonly RUN_MARKER=/run/coolify-runtime-fence-host-boot-generation

fail()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_SYSTEMD_HOST_BOOT_FAILURE %s\n' "$1" >&2
    exit 1
}

install_root_self_ssh_identity()
{
    local private_key=/root/.ssh/id_ed25519 public_key=/root/.ssh/id_ed25519.pub

    install -d -m 0700 -o root -g root /root/.ssh /run/sshd
    if [[ ! -f $private_key ]]; then
        ssh-keygen -q -t ed25519 -N '' -f "$private_key"
    fi
    [[ -f $private_key && ! -L $private_key && -f $public_key && ! -L $public_key ]] \
        || fail 'root self-SSH identity is unsafe'
    chmod 0600 "$private_key" "$public_key"
    chown root:root "$private_key" "$public_key"
    install -m 0600 -o root -g root "$public_key" /root/.ssh/authorized_keys
}

publish_boot_generation()
{
    local current=0 next candidate

    install -d -m 0700 -o root -g root "$EVIDENCE_DIRECTORY"
    if [[ -e $GENERATION_FILE ]]; then
        [[ -f $GENERATION_FILE && ! -L $GENERATION_FILE \
            && $(stat -c '%u:%g:%a' "$GENERATION_FILE") == 0:0:600 ]] \
            || fail 'persistent boot generation is unsafe'
        current=$(<"$GENERATION_FILE")
    fi
    [[ $current =~ ^[0-9]+$ ]] || fail 'persistent boot generation is malformed'
    next=$((current + 1))

    candidate=$EVIDENCE_DIRECTORY/.boot-generation.$$
    printf '%s\n' "$next" > "$candidate"
    chown root:root "$candidate"
    chmod 0600 "$candidate"
    sync "$candidate"
    mv -f -- "$candidate" "$GENERATION_FILE"
    sync "$EVIDENCE_DIRECTORY"

    printf '%s\n' "$next" > "$RUN_MARKER"
    chown root:root "$RUN_MARKER"
    chmod 0400 "$RUN_MARKER"
    printf 'generation=%s\npid1_started_at=%s\n' "$next" \
        "$(systemctl show --property=ActiveEnterTimestampMonotonic --value basic.target)" \
        > "$BOOT_RECORD"
    chown root:root "$BOOT_RECORD"
    chmod 0600 "$BOOT_RECORD"
}

install_root_self_ssh_identity
publish_boot_generation
