<?php

namespace App\Actions\Proxy;

use InvalidArgumentException;

final readonly class BlueGreenProxyRollbackArtifact
{
    public const MAGIC = 'coolify-blue-green-rollback-v2';

    public const MISSING_STATE = 'missing';

    public const OUTPUT_PREFIX = 'coolify-blue-green-rollback-artifact:';

    public const PRESENT_STATE = 'present';

    public string $sha256;

    public function __construct(
        public BlueGreenProxyRollbackKey $key,
        public bool $existed,
        public string $bytes,
    ) {
        $expectedManagedSha256 = $key->expectedState?->managedSha256;
        if (($existed && $expectedManagedSha256 === null)
            || (! $existed && ($expectedManagedSha256 !== null || $bytes !== ''))) {
            throw new InvalidArgumentException('The rollback artifact does not match the expected managed-file state.');
        }
        $this->sha256 = hash('sha256', $bytes);
        if ($expectedManagedSha256 !== null && ! hash_equals($expectedManagedSha256, $this->sha256)) {
            throw new InvalidArgumentException('The rollback artifact bytes do not match the expected managed-file checksum.');
        }
    }

    public function serialize(): string
    {
        return implode("\n", [
            self::MAGIC,
            $this->key->managedFilename(),
            $this->key->operationId,
            self::encodedState($this->key->expectedState),
            self::encodedState($this->key->replacementState),
            self::encodedState($this->key->rollbackState()),
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
        if (count($lines) !== 9) {
            throw new InvalidArgumentException('The blue/green rollback artifact has an invalid record shape.');
        }
        [$magic, $managedFilename, $operationId, $expectedState, $replacementState, $rollbackState, $state, $sha256, $payload] = $lines;
        if ($magic !== self::MAGIC
            || $managedFilename !== $key->managedFilename()
            || $operationId !== $key->operationId
            || $expectedState !== self::encodedState($key->expectedState)
            || $replacementState !== self::encodedState($key->replacementState)
            || $rollbackState !== self::encodedState($key->rollbackState())) {
            throw new InvalidArgumentException('The blue/green rollback artifact is owned by another operation or route identity.');
        }
        if (! in_array($state, [self::PRESENT_STATE, self::MISSING_STATE], true)) {
            throw new InvalidArgumentException('The blue/green rollback artifact has an invalid state marker.');
        }
        $bytes = base64_decode($payload, true);
        if ($bytes === false || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1 || ! hash_equals($sha256, hash('sha256', $bytes))) {
            throw new InvalidArgumentException('The blue/green rollback artifact checksum is invalid.');
        }

        return new self($key, $state === self::PRESENT_STATE, $bytes);
    }

    public static function encodedState(?BlueGreenProxyState $state): string
    {
        return $state === null ? 'absent' : base64_encode($state->serialize());
    }
}
