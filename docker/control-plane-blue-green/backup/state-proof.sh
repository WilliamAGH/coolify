#!/usr/bin/env bash

set -Eeuo pipefail

fail()
{
    printf 'control-plane-state-proof: %s\n' "$1" >&2
    exit 1
}

[[ $# == 2 ]] || fail 'usage: state-proof.sh ABSOLUTE_STATE_ROOT COMMA_SEPARATED_SELECTION'
state_root=$1
selection=$2
[[ $state_root == /* && -d $state_root && ! -L $state_root ]] \
    || fail 'state root must be an absolute non-symlink directory'
[[ -n $selection ]] || fail 'state selection is empty'

selection_file=$(mktemp)
trap 'rm -f -- "$selection_file"' EXIT HUP INT TERM
: > "$selection_file"
IFS=',' read -r -a selected_path <<< "$selection"
for path in "${selected_path[@]}"; do
    [[ $path =~ ^[A-Za-z0-9._-]+$ && -e $state_root/$path && ! -L $state_root/$path ]] \
        || fail 'state selection contains an unsafe or unavailable path'
    printf '%s\0' "$path" >> "$selection_file"
done
[[ $(printf '%s\n' "${selected_path[@]}" | LC_ALL=C sort -u | wc -l | tr -d '[:space:]') \
    == "${#selected_path[@]}" ]] || fail 'state selection contains duplicates'
[[ -z $(find "$state_root" \( -type l -o ! \( -type d -o -type f \) \) -print -quit) ]] \
    || fail 'state root contains a link or unsupported entry type'

proof_input=$(mktemp)
trap 'rm -f -- "$selection_file" "$proof_input"' EXIT HUP INT TERM
for path in "${selected_path[@]}"; do
    find "$state_root/$path" -print
done | LC_ALL=C sort | while IFS= read -r entry; do
    relative_entry=${entry#"$state_root"/}
    [[ $relative_entry != *$'\n'* && $relative_entry != *$'\r'* \
        && $relative_entry != *$'\t'* ]] || fail 'state path contains an unsafe character'
    if [[ -d $entry ]]; then
        printf 'd\t%s\t%s\t%s\t%s\n' "$relative_entry" "$(stat -c '%u' "$entry")" \
            "$(stat -c '%g' "$entry")" "$(stat -c '%a' "$entry")"
    else
        printf 'f\t%s\t%s\t%s\t%s\t%s\t%s\n' "$relative_entry" \
            "$(stat -c '%u' "$entry")" "$(stat -c '%g' "$entry")" \
            "$(stat -c '%a' "$entry")" "$(stat -c '%s' "$entry")" \
            "$(sha256sum "$entry" | awk '{print $1}')"
    fi
done > "$proof_input"
proof=$(sha256sum "$proof_input" | awk '{print $1}')
[[ $proof =~ ^[0-9a-f]{64}$ ]] || fail 'state proof generation failed'
printf 'control_plane_state_proof_sha256=%s\n' "$proof"
