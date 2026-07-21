#!/bin/sh

set -eu

test_directory=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
temporary_directory=$(mktemp -d)
trap 'rm -rf "$temporary_directory"' EXIT HUP INT TERM
source_file="$temporary_directory/source.log"
destination_file="$temporary_directory/destination.log"
source_tree="$temporary_directory/source-tree"
destination_tree="$temporary_directory/destination-tree"
outside_file="$temporary_directory/outside.log"

cat >"$source_file" <<'EOF'
2026-07-21T04:00:00Z deploy command failed: token mismatch correlation=run-42
private key path missing for server srv-7
credential helper not found while inspecting image sha256:abc123
authorization probe failed before a response header was received correlation=run-42
diagnostic: authorization: header parser was unavailable correlation=run-42
Authorization: Bearer actual-authorization-secret
authorization: Token actual-token-scheme-secret
AUTHORIZATION: Signature keyId="actual-signature-secret",algorithm="ed25519"
Authorization: AWS4-HMAC-SHA256 Credential=actual-aws-secret,SignedHeaders=host,Signature=deadbeef
Cookie: session=actual-cookie-secret; Path=/
password=hunter2 command=restore
"token": "actual-json-token"
https://operator:actual-url-password@example.invalid/v2/
docker login --password actual-option-secret registry.invalid
-----BEGIN OPENSSH PRIVATE KEY-----
actual-private-key-material
-----END OPENSSH PRIVATE KEY-----
2026-07-21T04:00:01Z cleanup finished correlation=run-42
EOF

python3 "$test_directory/sanitize-evidence.py" "$source_file" "$destination_file"

for diagnostic in \
    '2026-07-21T04:00:00Z deploy command failed: token mismatch correlation=run-42' \
    'private key path missing for server srv-7' \
    'credential helper not found while inspecting image sha256:abc123' \
    'authorization probe failed before a response header was received correlation=run-42' \
    'diagnostic: authorization: header parser was unavailable correlation=run-42' \
    '2026-07-21T04:00:01Z cleanup finished correlation=run-42'; do
    grep -Fqx "$diagnostic" "$destination_file"
done

for secret in \
    actual-authorization-secret \
    actual-token-scheme-secret \
    actual-signature-secret \
    actual-aws-secret \
    actual-cookie-secret \
    hunter2 \
    actual-json-token \
    actual-url-password \
    actual-option-secret \
    actual-private-key-material; do
    if grep -Fq "$secret" "$destination_file"; then
        printf 'secret leaked into sanitized evidence: %s\n' "$secret" >&2
        exit 1
    fi
done

for marker in \
    '[REDACTED authorization-header]' \
    '[REDACTED cookie-value]' \
    '[REDACTED sensitive-value:password]' \
    '[REDACTED sensitive-value:token]' \
    '[REDACTED credential-url-password]' \
    '[REDACTED command-option-value]' \
    '[REDACTED private-key-material]'; do
    grep -Fq "$marker" "$destination_file"
done

mkdir -p "$source_tree/nested/headers"
cat >"$source_tree/nested/headers/request.log" <<'EOF'
Authorization: Bearer actual-tree-authorization-secret
diagnostic: authorization: header parser was unavailable correlation=tree-42
EOF
cat >"$source_tree/nested/command.log" <<'EOF'
password=tree-secret command=restore
EOF
printf '%s\n' 'archive payload must never be retained' >"$source_tree/images.tar"
printf '\0binary evidence\n' >"$source_tree/nested/binary.bin"

python3 "$test_directory/sanitize-evidence.py" "$source_tree" "$destination_tree"

test -f "$destination_tree/nested/headers/request.log"
test -f "$destination_tree/nested/command.log"
test ! -e "$destination_tree/images.tar"
test ! -e "$destination_tree/nested/binary.bin"
grep -Fqx 'diagnostic: authorization: header parser was unavailable correlation=tree-42' \
    "$destination_tree/nested/headers/request.log"
grep -Fq '[REDACTED authorization-header]' "$destination_tree/nested/headers/request.log"
grep -Fq '[REDACTED sensitive-value:password]' "$destination_tree/nested/command.log"
if grep -Fq 'actual-tree-authorization-secret' "$destination_tree/nested/headers/request.log" \
    || grep -Fq 'tree-secret' "$destination_tree/nested/command.log"; then
    printf '%s\n' 'tree sanitizer leaked a secret' >&2
    exit 1
fi

if python3 "$test_directory/sanitize-evidence.py" \
    "$source_tree" "$source_tree/unsafe-destination"; then
    printf '%s\n' 'tree sanitizer accepted a destination inside its source tree' >&2
    exit 1
fi
test ! -e "$source_tree/unsafe-destination"

printf '%s\n' 'outside evidence' >"$outside_file"
ln -s "$outside_file" "$source_tree/nested/outside-link.log"
if python3 "$test_directory/sanitize-evidence.py" \
    "$source_tree" "$temporary_directory/symlink-source-destination"; then
    printf '%s\n' 'tree sanitizer accepted a symlink in its source tree' >&2
    exit 1
fi
test ! -e "$temporary_directory/symlink-source-destination"
rm "$source_tree/nested/outside-link.log"

ln -s "$source_tree" "$temporary_directory/source-link"
if python3 "$test_directory/sanitize-evidence.py" \
    "$temporary_directory/source-link" "$temporary_directory/source-link-destination"; then
    printf '%s\n' 'tree sanitizer accepted a symlink source root' >&2
    exit 1
fi
test ! -e "$temporary_directory/source-link-destination"

ln -s "$temporary_directory/safe-destination" "$temporary_directory/destination-link"
if python3 "$test_directory/sanitize-evidence.py" \
    "$source_tree" "$temporary_directory/destination-link"; then
    printf '%s\n' 'tree sanitizer accepted a symlink destination' >&2
    exit 1
fi

printf '%s\n' 'Production application blue-green evidence sanitizer: PASS'
