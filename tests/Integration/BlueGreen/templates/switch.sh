#!/bin/sh
set -eu

managed_file=/dynamic/coolify-blue-green-managed.yml
readonly managed_file
temporary_file=

cleanup() {
  if [ -n "$temporary_file" ]; then
    rm -f "$temporary_file"
  fi
}

trap cleanup EXIT HUP INT TERM

if [ "${1:-}" = "--remove" ]; then
  rm -f "$managed_file"
  printf 'BLUEGREEN_FILE_PROVIDER_REMOVED path=%s\n' "$managed_file"
  exit 0
fi

source_file="${1:?a source configuration is required}"
case "$source_file" in
  /templates/*.yml) ;;
  *)
    printf 'source configuration must be a lab template: %s\n' "$source_file" >&2
    exit 64
    ;;
esac

if [ ! -f "$source_file" ]; then
  printf 'source configuration does not exist: %s\n' "$source_file" >&2
  exit 64
fi

temporary_file="$(mktemp "${managed_file}.XXXXXX")"
cp "$source_file" "$temporary_file"
mv -f "$temporary_file" "$managed_file"
temporary_file=
printf 'BLUEGREEN_FILE_PROVIDER_APPLIED source=%s destination=%s\n' "$source_file" "$managed_file"
