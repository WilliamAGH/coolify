<?php

namespace App\Actions\Proxy;

final class BlueGreenProxyRollbackCommands
{
    public function artifactPath(string $activePath, BlueGreenProxyRollbackKey $key): string
    {
        return dirname($activePath).'/'.$key->artifactFilename();
    }

    /** @return list<string> */
    public function createIfMissing(
        string $activePath,
        string $artifactPath,
        BlueGreenProxyRollbackKey $key,
    ): array {
        $directory = dirname($activePath);

        return [
            'if [ ! -e '.escapeshellarg($artifactPath).' ] && [ ! -L '.escapeshellarg($artifactPath).' ]; then',
            '  rollback_stage=$(mktemp '.escapeshellarg($directory.'/.coolify-rollback-stage.XXXXXX').')',
            '  rollback_source=$(mktemp '.escapeshellarg($directory.'/.coolify-rollback-source.XXXXXX').')',
            '  trap \'rm -f -- "$rollback_stage" "$rollback_source"\' 0 HUP INT TERM',
            '  if [ -e '.escapeshellarg($activePath).' ] || [ -L '.escapeshellarg($activePath).' ]; then',
            '    test -f '.escapeshellarg($activePath),
            '    test ! -L '.escapeshellarg($activePath),
            '    cp -- '.escapeshellarg($activePath).' "$rollback_source"',
            '    rollback_state='.BlueGreenProxyRollbackArtifact::PRESENT_STATE,
            '  else',
            '    : > "$rollback_source"',
            '    rollback_state='.BlueGreenProxyRollbackArtifact::MISSING_STATE,
            '  fi',
            '  rollback_checksum=$(sha256sum "$rollback_source")',
            '  rollback_checksum=${rollback_checksum%% *}',
            '  {',
            '    printf \'%s\\n\' '.escapeshellarg(BlueGreenProxyRollbackArtifact::MAGIC),
            '    printf \'%s\\n\' '.escapeshellarg($key->managedFilename),
            '    printf \'%s\\n\' '.escapeshellarg($key->operationId),
            '    printf \'%s\\n\' '.escapeshellarg((string) $key->routingRevision),
            '    printf \'%s\\n\' "$rollback_state"',
            '    printf \'%s\\n\' "$rollback_checksum"',
            '    base64 "$rollback_source" | tr -d \'\\n\'',
            '    printf \'\\n\'',
            '  } > "$rollback_stage"',
            '  chmod 600 "$rollback_stage"',
            '  sync -f "$rollback_stage"',
            '  if ln -- "$rollback_stage" '.escapeshellarg($artifactPath).'; then',
            '    :',
            '  else',
            '    test -f '.escapeshellarg($artifactPath),
            '  fi',
            '  sync -f '.escapeshellarg($directory),
            '  rm -f -- "$rollback_stage" "$rollback_source"',
            '  trap - 0 HUP INT TERM',
            'fi',
        ];
    }

    /** @return list<string> */
    public function validate(
        string $artifactPath,
        BlueGreenProxyRollbackKey $key,
    ): array {
        $directory = dirname($artifactPath);

        return [
            'test -f '.escapeshellarg($artifactPath),
            'test ! -L '.escapeshellarg($artifactPath),
            'test "$(stat -c %u -- '.escapeshellarg($artifactPath).')" = "$(id -u)"',
            'test "$(stat -c %a -- '.escapeshellarg($artifactPath).')" = 600',
            'exec 3< '.escapeshellarg($artifactPath),
            'IFS= read -r rollback_magic <&3',
            'IFS= read -r rollback_filename <&3',
            'IFS= read -r rollback_operation <&3',
            'IFS= read -r rollback_revision <&3',
            'IFS= read -r rollback_state <&3',
            'IFS= read -r rollback_checksum <&3',
            'rollback_decoded=$(mktemp '.escapeshellarg($directory.'/.coolify-rollback-decoded.XXXXXX').')',
            'trap \'rm -f -- "$rollback_decoded"\' 0 HUP INT TERM',
            'base64 -d <&3 > "$rollback_decoded"',
            'exec 3<&-',
            'test "$rollback_magic" = '.escapeshellarg(BlueGreenProxyRollbackArtifact::MAGIC),
            'test "$rollback_filename" = '.escapeshellarg($key->managedFilename),
            'test "$rollback_operation" = '.escapeshellarg($key->operationId),
            'test "$rollback_revision" = '.escapeshellarg((string) $key->routingRevision),
            'case "$rollback_state" in '.BlueGreenProxyRollbackArtifact::PRESENT_STATE.'|'.BlueGreenProxyRollbackArtifact::MISSING_STATE.') : ;; *) exit 1 ;; esac',
            'case "$rollback_checksum" in *[!0-9a-f]*|\'\') exit 1 ;; esac',
            'test "${#rollback_checksum}" -eq 64',
            'rollback_actual_checksum=$(sha256sum "$rollback_decoded")',
            'test "${rollback_actual_checksum%% *}" = "$rollback_checksum"',
            'if [ "$rollback_state" = '.BlueGreenProxyRollbackArtifact::MISSING_STATE.' ]; then test ! -s "$rollback_decoded"; fi',
        ];
    }

    /** @return list<string> */
    public function discardDecoded(): array
    {
        return [
            'rm -f -- "$rollback_decoded"',
            'trap - 0 HUP INT TERM',
        ];
    }
}
