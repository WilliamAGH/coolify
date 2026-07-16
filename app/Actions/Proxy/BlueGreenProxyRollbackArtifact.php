<?php

namespace App\Actions\Proxy;

use InvalidArgumentException;

final readonly class BlueGreenProxyRollbackArtifact
{
    public const MAGIC = 'coolify-blue-green-rollback-v1';

    public const MISSING_STATE = 'missing';

    public const OUTPUT_PREFIX = 'coolify-blue-green-rollback-artifact:';

    public const PRESENT_STATE = 'present';

    public string $sha256;

    public function __construct(
        public BlueGreenProxyRollbackKey $key,
        public bool $existed,
        public string $bytes,
    ) {
        if (! $existed && $bytes !== '') {
            throw new InvalidArgumentException('A missing-file rollback artifact cannot contain prior bytes.');
        }
        $this->sha256 = hash('sha256', $bytes);
    }

    public function serialize(): string
    {
        return implode("\n", [
            self::MAGIC,
            $this->key->managedFilename,
            $this->key->operationId,
            (string) $this->key->routingRevision,
            $this->existed ? self::PRESENT_STATE : self::MISSING_STATE,
            $this->sha256,
            base64_encode($this->bytes),
        ])."\n";
    }

    public static function fromRemoteOutput(BlueGreenProxyRollbackKey $key, string $output): self
    {
        if (! str_starts_with($output, self::OUTPUT_PREFIX)) {
            throw new InvalidArgumentException('Remote output did not contain a blue/green rollback artifact.');
        }
        $encoded = preg_replace('/\s+/', '', substr($output, strlen(self::OUTPUT_PREFIX)));
        if ($encoded === null || ($serialized = base64_decode($encoded, true)) === false) {
            throw new InvalidArgumentException('Remote output contained an invalid blue/green rollback artifact.');
        }

        return self::parse($key, $serialized);
    }

    public static function parse(BlueGreenProxyRollbackKey $key, string $serialized): self
    {
        $lines = explode("\n", $serialized);
        if (end($lines) === '') {
            array_pop($lines);
        }
        if (count($lines) !== 7) {
            throw new InvalidArgumentException('The blue/green rollback artifact has an invalid record shape.');
        }
        [$magic, $managedFilename, $operationId, $routingRevision, $state, $sha256, $payload] = $lines;
        if ($magic !== self::MAGIC
            || $managedFilename !== $key->managedFilename
            || $operationId !== $key->operationId
            || $routingRevision !== (string) $key->routingRevision) {
            throw new InvalidArgumentException('The blue/green rollback artifact is owned by another operation.');
        }
        if (! in_array($state, [self::PRESENT_STATE, self::MISSING_STATE], true)) {
            throw new InvalidArgumentException('The blue/green rollback artifact has an invalid state marker.');
        }
        $bytes = base64_decode($payload, true);
        if ($bytes === false || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1 || ! hash_equals($sha256, hash('sha256', $bytes))) {
            throw new InvalidArgumentException('The blue/green rollback artifact checksum is invalid.');
        }
        $existed = $state === self::PRESENT_STATE;
        if (! $existed && $bytes !== '') {
            throw new InvalidArgumentException('The missing-file rollback artifact contains unexpected bytes.');
        }

        return new self($key, $existed, $bytes);
    }
}
