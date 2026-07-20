#!/bin/sh

set -eu

authorized_keys=/run/coolify-testing-host/public/authorized_keys

if [ ! -s "$authorized_keys" ]; then
    echo 'The runtime testing-host public key is missing.' >&2
    exit 1
fi

if ! ssh-keygen -lf "$authorized_keys" >/dev/null 2>&1; then
    echo 'The runtime testing-host public key is invalid.' >&2
    exit 1
fi

install -d -o root -g root -m 700 /root/.ssh
install -o root -g root -m 600 "$authorized_keys" /root/.ssh/authorized_keys
install -d -o root -g root -m 755 /run/sshd
ssh-keygen -A >/dev/null 2>&1

exec /usr/sbin/sshd -D -o ListenAddress=0.0.0.0 -o Port=22
