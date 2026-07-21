#!/usr/bin/env bash

set -Eeuo pipefail

REPO_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
readonly REPO_ROOT
FIXTURE=$(mktemp -d "${TMPDIR:-/tmp}/coolify-release-download.XXXXXX")
readonly FIXTURE
trap 'rm -rf "$FIXTURE"' EXIT

mkdir -p "$FIXTURE/bin" "$FIXTURE/downloads" "$FIXTURE/source"
printf 'verified release asset\n' > "$FIXTURE/source/release.env"
printf '0\n' > "$FIXTURE/attempts"

cat > "$FIXTURE/bin/gh" <<'SH'
#!/usr/bin/env bash
set -Eeuo pipefail

attempts=$(cat "${DOWNLOAD_ATTEMPTS:?}")
attempts=$((attempts + 1))
printf '%d\n' "$attempts" > "$DOWNLOAD_ATTEMPTS"

while (( $# > 0 )); do
    case "$1" in
        --pattern)
            asset_name=$2
            shift 2
            ;;
        --dir)
            download_directory=$2
            shift 2
            ;;
        *)
            shift
            ;;
    esac
done

printf 'partial\n' > "${download_directory:?}/${asset_name:?}"
if (( attempts <= DOWNLOAD_FAILURES )); then
    exit 1
fi
cp "${DOWNLOAD_SOURCE:?}/$asset_name" "$download_directory/$asset_name"
SH
chmod 0755 "$FIXTURE/bin/gh"

export DOWNLOAD_ATTEMPTS="$FIXTURE/attempts"
export DOWNLOAD_FAILURES=2
export DOWNLOAD_SOURCE="$FIXTURE/source"

"$REPO_ROOT/scripts/ci/download-github-release-asset.sh" \
    "$FIXTURE/bin/gh" williamacallahan/coolify 4.13.8-fork release.env \
    "$FIXTURE/downloads" 3 0
cmp "$FIXTURE/source/release.env" "$FIXTURE/downloads/release.env"
[[ "$(cat "$FIXTURE/attempts")" == 3 ]]

rm -f "$FIXTURE/downloads/release.env"
printf '0\n' > "$FIXTURE/attempts"
export DOWNLOAD_FAILURES=3

if "$REPO_ROOT/scripts/ci/download-github-release-asset.sh" \
    "$FIXTURE/bin/gh" williamacallahan/coolify 4.13.8-fork release.env \
    "$FIXTURE/downloads" 2 0; then
    printf 'Expected exhausted release download retries to fail.\n' >&2
    exit 1
fi
[[ "$(cat "$FIXTURE/attempts")" == 2 ]]
[[ ! -e "$FIXTURE/downloads/release.env" ]]

printf 'GitHub release asset retry integration passed.\n'
