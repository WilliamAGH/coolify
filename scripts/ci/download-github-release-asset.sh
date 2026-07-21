#!/usr/bin/env bash

set -Eeuo pipefail

if [[ $# -ne 7 ]]; then
    printf 'Usage: %s <gh-cli> <repository> <tag> <asset> <directory> <attempts> <delay-seconds>\n' "$0" >&2
    exit 64
fi

readonly gh_cli=$1
readonly repository=$2
readonly tag=$3
readonly asset_name=$4
readonly download_directory=$5
readonly max_attempts=$6
readonly retry_delay_seconds=$7
readonly destination="$download_directory/$asset_name"

[[ -x "$gh_cli" ]]
[[ "$repository" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]
[[ "$tag" =~ ^[A-Za-z0-9._-]+$ ]]
[[ "$asset_name" =~ ^[A-Za-z0-9._-]+$ ]]
[[ -d "$download_directory" && ! -L "$download_directory" ]]
[[ "$max_attempts" =~ ^[1-9][0-9]*$ && "$max_attempts" -le 10 ]]
[[ "$retry_delay_seconds" =~ ^[0-9]+$ && "$retry_delay_seconds" -le 30 ]]
[[ ! -e "$destination" && ! -L "$destination" ]]

for ((attempt = 1; attempt <= max_attempts; attempt++)); do
    if "$gh_cli" release download "$tag" --repo "$repository" \
        --pattern "$asset_name" --dir "$download_directory"; then
        [[ -f "$destination" && ! -L "$destination" ]]
        exit 0
    else
        status=$?
    fi

    if [[ -L "$destination" || ( -e "$destination" && ! -f "$destination" ) ]]; then
        printf 'GitHub release download left an unsafe destination: %s\n' "$destination" >&2
        exit 1
    fi
    rm -f "$destination"

    if (( attempt == max_attempts )); then
        printf 'GitHub release asset download failed after %d attempts: %s\n' \
            "$max_attempts" "$asset_name" >&2
        exit "$status"
    fi

    printf 'GitHub release asset download attempt %d/%d failed; retrying: %s\n' \
        "$attempt" "$max_attempts" "$asset_name" >&2
    sleep "$retry_delay_seconds"
done
