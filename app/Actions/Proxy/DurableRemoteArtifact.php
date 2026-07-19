<?php

namespace App\Actions\Proxy;

final class DurableRemoteArtifact
{
    /** @return list<string> */
    public static function shellFunctions(): array
    {
        return [
            'durable_remote_replace() {',
            '  [ "$#" -eq 3 ] || return 64',
            '  [ -d "$3" ] && [ ! -L "$3" ] || return 1',
            '  if [ -e "$1" ] || [ -L "$1" ]; then',
            '    [ -f "$1" ] && [ ! -L "$1" ] || return 1',
            '    if [ -e "$2" ] || [ -L "$2" ]; then [ -f "$2" ] && [ ! -L "$2" ] || return 1; fi',
            '    sync "$1" || return 1',
            '    mv -f -- "$1" "$2" || return 1',
            '  else',
            '    [ -f "$2" ] && [ ! -L "$2" ] || return 1',
            '    sync "$2" || return 1',
            '  fi',
            '  sync "$3" || return 1',
            '}',
            'durable_remote_remove() {',
            '  [ "$#" -eq 2 ] || return 64',
            '  [ -d "$2" ] && [ ! -L "$2" ] || return 1',
            '  rm -f -- "$1" || return 1',
            '  sync "$2" || return 1',
            '}',
            'durable_remote_reaffirm() {',
            '  [ "$#" -eq 2 ] || return 64',
            '  [ -d "$2" ] && [ ! -L "$2" ] || return 1',
            '  [ -f "$1" ] && [ ! -L "$1" ] || return 1',
            '  sync "$1" || return 1',
            '  sync "$2" || return 1',
            '}',
        ];
    }
}
