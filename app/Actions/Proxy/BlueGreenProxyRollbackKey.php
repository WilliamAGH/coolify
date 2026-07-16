<?php

namespace App\Actions\Proxy;

use InvalidArgumentException;

final readonly class BlueGreenProxyRollbackKey
{
    public function __construct(
        public string $managedFilename,
        public string $operationId,
        public int $routingRevision,
    ) {
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('The blue/green rollback operation ID is invalid.');
        }
        if ($routingRevision < 0) {
            throw new InvalidArgumentException('The blue/green rollback routing revision must be nonnegative.');
        }
    }

    public function artifactFilename(): string
    {
        return ".{$this->managedFilename}.{$this->operationId}.r{$this->routingRevision}.coolify-rollback";
    }
}
