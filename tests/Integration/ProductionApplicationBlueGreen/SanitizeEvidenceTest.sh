#!/bin/sh

set -eu

test_directory=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
temporary_directory=$(mktemp -d)
trap 'rm -rf "$temporary_directory"' EXIT HUP INT TERM
source_file="$temporary_directory/source.log"
destination_file="$temporary_directory/destination.log"

cat >"$source_file" <<'EOF'
2026-07-21T04:00:00Z deploy command failed: token mismatch correlation=run-42
private key path missing for server srv-7
credential helper not found while inspecting image sha256:abc123
Authorization: Bearer actual-authorization-secret
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
    '2026-07-21T04:00:01Z cleanup finished correlation=run-42'; do
    grep -Fqx "$diagnostic" "$destination_file"
done

for secret in \
    actual-authorization-secret \
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

printf '%s\n' 'Production application blue-green evidence sanitizer: PASS'
