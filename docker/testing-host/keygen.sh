#!/bin/sh

set -eu

private_directory=/run/coolify-testing-host/private
private_key="$private_directory/testing-host"
public_directory=/run/coolify-testing-host/public
authorized_keys="$public_directory/authorized_keys"

: "${COOLIFY_TESTING_KEY_UID:?COOLIFY_TESTING_KEY_UID is required}"
: "${COOLIFY_TESTING_KEY_GID:?COOLIFY_TESTING_KEY_GID is required}"

case "$COOLIFY_TESTING_KEY_UID:$COOLIFY_TESTING_KEY_GID" in
    *[!0-9:]* | :* | *:)
        echo 'COOLIFY_TESTING_KEY_UID and COOLIFY_TESTING_KEY_GID must be numeric.' >&2
        exit 1
        ;;
esac

umask 077
install -d -o "$COOLIFY_TESTING_KEY_UID" -g "$COOLIFY_TESTING_KEY_GID" -m 700 "$private_directory"
install -d -o root -g root -m 755 "$public_directory"

if [ -f "$private_key" ]; then
    chown "$COOLIFY_TESTING_KEY_UID:$COOLIFY_TESTING_KEY_GID" "$private_key"
    chmod 600 "$private_key"

    public_key_tmp="$(mktemp "$public_directory/.authorized_keys.XXXXXX")"
    ssh-keygen -y -f "$private_key" > "$public_key_tmp"
    chown root:root "$public_key_tmp"
    chmod 644 "$public_key_tmp"

    if [ -f "$authorized_keys" ] && ! cmp -s "$public_key_tmp" "$authorized_keys"; then
        rm -f "$public_key_tmp"
        echo 'The runtime testing-host public key does not match its private key.' >&2
        exit 1
    fi

    if [ ! -f "$authorized_keys" ]; then
        mv "$public_key_tmp" "$authorized_keys"
    else
        rm -f "$public_key_tmp"
    fi

    exit 0
fi

if [ -e "$authorized_keys" ]; then
    echo 'A runtime testing-host public key exists without its private key.' >&2
    exit 1
fi

private_key_tmp="$(mktemp "$private_directory/.testing-host.XXXXXX")"
rm -f "$private_key_tmp"
ssh-keygen -q -t ed25519 -N '' -C coolify-testing-host -f "$private_key_tmp"

public_key_tmp="$(mktemp "$public_directory/.authorized_keys.XXXXXX")"
ssh-keygen -y -f "$private_key_tmp" > "$public_key_tmp"

chown "$COOLIFY_TESTING_KEY_UID:$COOLIFY_TESTING_KEY_GID" "$private_key_tmp"
chmod 600 "$private_key_tmp"
chown root:root "$public_key_tmp"
chmod 644 "$public_key_tmp"

mv "$private_key_tmp" "$private_key"
mv "$public_key_tmp" "$authorized_keys"
rm -f "$private_key_tmp.pub"
