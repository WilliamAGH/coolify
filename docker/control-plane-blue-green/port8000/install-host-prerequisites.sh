#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

SCRIPT_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
readonly SCRIPT_DIRECTORY
readonly CONFIG_DIRECTORY=/etc/coolify-control-plane-port8000
readonly HAPROXY_VERSION=2.8.26
readonly HAPROXY_SOURCE_SHA256=88c28dae25ea46672e66f8db0dadd1fb5920e06ee2415ceb9f281c256b537727
readonly HAPROXY_SOURCE_URL=https://www.haproxy.org/download/2.8/src/haproxy-2.8.26.tar.gz
readonly HAPROXY_RUNTIME_DIRECTORY=/usr/local/lib/coolify-control-plane-port8000/haproxy-2.8.26
readonly HAPROXY_RUNTIME_BINARY="$HAPROXY_RUNTIME_DIRECTORY/haproxy"
readonly HAPROXY_BUILD_ATTESTATION="$HAPROXY_RUNTIME_DIRECTORY/provenance"

fail()
{
    printf 'CONTROL_PLANE_PORT8000_INSTALL_FAILURE %s\n' "$1" >&2
    exit 1
}

[[ $(id -u) -eq 0 ]] || fail 'host prerequisite installation requires root'
# shellcheck disable=SC1091 # Runtime distribution metadata has a fixed host path.
. /etc/os-release
[[ $(printf '%s:%s' "$ID" "$VERSION_ID") == ubuntu:24.04 ]] \
    || fail 'installer is pinned to Ubuntu 24.04'
[[ $(dpkg-query -W -f='${Version}' nftables 2>/dev/null || true) == 1.0.9-1ubuntu0.1 ]] \
    || fail 'exact nftables 1.0.9-1ubuntu0.1 must already be installed'
[[ $(dpkg-query -W -f='${Version}' conntrack 2>/dev/null || true) == 1:1.4.8-1ubuntu1 ]] \
    || fail 'exact conntrack 1:1.4.8-1ubuntu1 must already be installed'
for build_command in curl make cc pkg-config tar; do
    command -v "$build_command" >/dev/null \
        || fail "HAProxy source build requires $build_command"
done

build_directory=$(mktemp -d /tmp/coolify-haproxy-2.8.26.XXXXXX)
runtime_candidate=
trap 'rm -rf -- "$build_directory"; [[ -z ${runtime_candidate:-} ]] || rm -rf -- "$runtime_candidate"' EXIT
source_archive=${CONTROL_PLANE_PORT8000_HAPROXY_SOURCE_ARCHIVE:-}
if [[ -n $source_archive ]]; then
    [[ $source_archive == /* && -f $source_archive && ! -L $source_archive ]] \
        || fail 'provided HAProxy source archive must be an absolute regular non-symlink file'
    cp -- "$source_archive" "$build_directory/haproxy.tar.gz"
else
    curl --proto '=https' --tlsv1.2 --fail --location --silent --show-error \
        --output "$build_directory/haproxy.tar.gz" "$HAPROXY_SOURCE_URL"
fi
printf '%s  %s\n' "$HAPROXY_SOURCE_SHA256" "$build_directory/haproxy.tar.gz" \
    | sha256sum --check --status \
    || fail 'official HAProxy 2.8.26 source checksum differs from the pinned identity'
tar -xzf "$build_directory/haproxy.tar.gz" -C "$build_directory"
source_directory="$build_directory/haproxy-$HAPROXY_VERSION"
[[ -d $source_directory && ! -L $source_directory ]] \
    || fail 'official HAProxy 2.8.26 source archive layout is unexpected'
make -C "$source_directory" TARGET=linux-glibc USE_SYSTEMD=1
version_line=$(
    "$source_directory/haproxy" -v 2>&1 | sed -n '1s/^HAProxy version \([^ ]*\).*/\1/p'
)
[[ $version_line =~ ^2\.8\.26(-[0-9A-Fa-f]+)?$ ]] \
    || fail 'compiled HAProxy runtime is not exact official 2.8.26'
"$source_directory/haproxy" -vv 2>&1 | grep -F '+SYSTEMD' >/dev/null \
    || fail 'compiled HAProxy 2.8.26 runtime omitted USE_SYSTEMD=1'
install -d -m 0755 -o root -g root /usr/local/lib/coolify-control-plane-port8000
runtime_candidate=/usr/local/lib/coolify-control-plane-port8000/.haproxy-2.8.26.$$
[[ ! -e $runtime_candidate && ! -L $runtime_candidate ]] \
    || fail 'HAProxy runtime publication candidate already exists'
install -d -m 0755 -o root -g root "$runtime_candidate"
install -m 0755 -o root -g root "$source_directory/haproxy" "$runtime_candidate/haproxy"
runtime_binary_sha256=$(sha256sum "$runtime_candidate/haproxy" | awk '{print $1}')
{
    printf 'version=1\n'
    printf 'haproxy_version=%s\n' "$HAPROXY_VERSION"
    printf 'haproxy_source_sha256=%s\n' "$HAPROXY_SOURCE_SHA256"
    printf 'haproxy_build_options=TARGET=linux-glibc USE_SYSTEMD=1\n'
    printf 'haproxy_binary=%s\n' "$HAPROXY_RUNTIME_BINARY"
    printf 'haproxy_binary_sha256=%s\n' "$runtime_binary_sha256"
} > "$runtime_candidate/provenance"
chmod 0600 "$runtime_candidate/provenance"
sync "$runtime_candidate/haproxy" "$runtime_candidate/provenance" "$runtime_candidate"
if [[ -e $HAPROXY_RUNTIME_DIRECTORY || -L $HAPROXY_RUNTIME_DIRECTORY ]]; then
    [[ -d $HAPROXY_RUNTIME_DIRECTORY && ! -L $HAPROXY_RUNTIME_DIRECTORY \
        && -f $HAPROXY_RUNTIME_BINARY && ! -L $HAPROXY_RUNTIME_BINARY \
        && -f $HAPROXY_BUILD_ATTESTATION && ! -L $HAPROXY_BUILD_ATTESTATION \
        && $(stat -c '%a:%u:%g' "$HAPROXY_RUNTIME_DIRECTORY") == 755:0:0 \
        && $(stat -c '%a:%u:%g' "$HAPROXY_RUNTIME_BINARY") == 755:0:0 \
        && $(stat -c '%a:%u:%g' "$HAPROXY_BUILD_ATTESTATION") == 600:0:0 \
        && $(sha256sum "$HAPROXY_RUNTIME_BINARY" | awk '{print $1}') \
            == "$runtime_binary_sha256" \
        && $(sha256sum "$HAPROXY_BUILD_ATTESTATION" | awk '{print $1}') \
            == "$(sha256sum "$runtime_candidate/provenance" | awk '{print $1}')" ]] \
        || fail 'published HAProxy 2.8.26 runtime/provenance identity differs'
    rm -rf -- "$runtime_candidate"
else
    mv -- "$runtime_candidate" "$HAPROXY_RUNTIME_DIRECTORY"
    sync /usr/local/lib/coolify-control-plane-port8000
fi

install -d -m 0700 -o root -g root "$CONFIG_DIRECTORY"
install -d -m 0755 -o root -g root /usr/local/libexec
install -m 0644 -o root -g root "$SCRIPT_DIRECTORY/coolify-port8000-haproxy@.service" \
    /etc/systemd/system/coolify-port8000-haproxy@.service
install -m 0644 -o root -g root "$SCRIPT_DIRECTORY/coolify-port8000-phase-b-authorizer.service" \
    /etc/systemd/system/coolify-port8000-phase-b-authorizer.service
install -m 0644 -o root -g root "$SCRIPT_DIRECTORY/coolify-port8000-nft.service" \
    /etc/systemd/system/coolify-port8000-nft.service
install -m 0755 -o root -g root "$SCRIPT_DIRECTORY/apply-active-nft.sh" \
    /usr/local/libexec/coolify-port8000-apply-active-nft
install -m 0700 -o root -g root "$SCRIPT_DIRECTORY/../controllers/haproxy-port8000.sh" \
    /usr/local/libexec/coolify-haproxy-port8000-controller
systemctl daemon-reload
systemctl enable coolify-port8000-haproxy@phase-a.service \
    coolify-port8000-haproxy@phase-b.service coolify-port8000-phase-b-authorizer.service \
    coolify-port8000-nft.service >/dev/null
[[ -L /etc/systemd/system/docker.service.requires/coolify-port8000-nft.service \
    && -L /etc/systemd/system/docker.socket.requires/coolify-port8000-nft.service ]] \
    || fail 'nftables restore was not installed as a required dependency of both Docker activation paths'
printf '%s\n' 'CONTROL_PLANE_PORT8000_INSTALL complete=true services_started=false reboot_acceptance_required=true'
