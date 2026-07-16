#!/usr/bin/env bash

set -euo pipefail

: "${REGCTL_AMD64:?}"
: "${REGCTL_ARM64:?}"
: "${REGCTL_LOG:?}"
: "${REGCTL_STATE:?}"

reference_key() {
    printf '%s' "$1" | sha256sum | awk '{print $1}'
}

reference_path() {
    printf '%s/refs/%s\n' "$REGCTL_STATE" "$(reference_key "$1")"
}

read_reference() {
    local path
    path="$(reference_path "$1")"
    [[ -f "$path" ]] || return 1
    cat "$path"
}

write_reference() {
    local path
    path="$(reference_path "$1")"
    printf '%s\n' "$2" > "$path"
}

delete_reference() {
    rm -f "$(reference_path "$1")"
}

case "${1:-}:${2:-}" in
    image:digest)
        reference="$3"
        if [[ " $* " == *' --platform linux/amd64 '* ]]; then
            if [[ "${REGCTL_FAIL_PLATFORM_REFERENCE:-}" == "$reference" ]]; then
                printf 'sha256:'
                printf '%*s' 64 '' | tr ' ' f
                printf '\n'
                exit 0
            fi
            printf '%s\n' "$REGCTL_AMD64"
            printf 'platform:%s:linux/amd64\n' "$reference" >> "$REGCTL_LOG"
            exit 0
        fi
        if [[ " $* " == *' --platform linux/arm64 '* ]]; then
            if [[ "${REGCTL_FAIL_PLATFORM_REFERENCE:-}" == "$reference" ]]; then
                printf 'sha256:'
                printf '%*s' 64 '' | tr ' ' f
                printf '\n'
                exit 0
            fi
            printf '%s\n' "$REGCTL_ARM64"
            printf 'platform:%s:linux/arm64\n' "$reference" >> "$REGCTL_LOG"
            exit 0
        fi
        read_reference "$reference"
        ;;
    image:copy)
        source="$3"
        target="$4"
        if [[ "${REGCTL_FAIL_TARGET:-}" == "$target" ]]; then
            exit 1
        fi
        if [[ "${REGCTL_IGNORE_COPY_TARGET:-}" == "$target" ]]; then
            printf 'copy:%s:%s\n' "$source" "$target" >> "$REGCTL_LOG"
            exit 0
        fi
        digest="${source##*@}"
        target_repository="${target%:*}"
        write_reference "$target" "$digest"
        write_reference "${target_repository}@${digest}" "$digest"
        printf 'copy:%s:%s\n' "$source" "$target" >> "$REGCTL_LOG"
        ;;
    image:config)
        reference="$3"
        digest="${reference##*@}"
        run_file="$REGCTL_STATE/runs/${digest#sha256:}"
        [[ -f "$run_file" ]] || exit 1
        printf '{"config":{"Labels":{"io.coolify.release-run-id":"%s"}}}\n' "$(cat "$run_file")"
        ;;
    tag:delete)
        target="${!#}"
        delete_reference "$target"
        printf 'delete:%s\n' "$target" >> "$REGCTL_LOG"
        ;;
    *)
        printf 'Unexpected regctl invocation: %s\n' "$*" >&2
        exit 2
        ;;
esac
