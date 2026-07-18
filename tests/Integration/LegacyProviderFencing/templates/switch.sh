#!/bin/sh
set -eu

readonly managed=/dynamic/coolify-blue-green-managed.yml

if [ "${1:-}" = --remove ]; then
  rm -f "$managed"
  exit 0
fi

source_path="${1:?a source configuration is required}"
temporary_path="/dynamic/.coolify-blue-green-managed.$$.tmp"
cp "$source_path" "$temporary_path"
mv -f "$temporary_path" "$managed"
