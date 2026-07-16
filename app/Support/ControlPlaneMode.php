<?php

namespace App\Support;

use App\Exceptions\ControlPlaneMutationLockedException;
use Closure;
use Illuminate\Queue\Jobs\RedisJob;
use InvalidArgumentException;
use LogicException;
use Throwable;

enum ControlPlaneMode: string
{
    private const STARTUP_MODE_FULL = 'full';

    private const STARTUP_MODE_WEB_ONLY = 'web-only';

    private const STATE_DIRECTORY_MODE = 0750;

    private const MARKER_MODE = 0440;

    private const MUTATION_LEASE_MODE = 0660;

    private const FILE_TYPE_MASK = 0170000;

    private const DIRECTORY_TYPE = 0040000;

    private const REGULAR_FILE_TYPE = 0100000;

    private const PERMISSION_MASK = 07777;

    private const MUTATION_LEASE_STRICT_PRODUCER = 'strict-producer';

    private const MUTATION_LEASE_DIRECT_OPERATION = 'direct-operation';

    private const MUTATION_LEASE_ACCEPTED_EXECUTION = 'accepted-execution';

    case Active = 'active';
    case Passive = 'passive';

    public static function fromEnvironment(): self
    {
        return self::parse(env('CONTROL_PLANE_MODE', self::Active->value));
    }

    public static function configured(): self
    {
        $environmentMode = env('CONTROL_PLANE_MODE');

        return self::parse($environmentMode ?? config('control-plane.mode', self::Active->value));
    }

    public static function backgroundServicesAllowed(): bool
    {
        if (! self::writerOwnershipProven()) {
            return false;
        }

        try {
            return ! self::mutationFreezeActive();
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public static function writerOwnershipProven(): bool
    {
        if (self::configured() === self::Passive) {
            return false;
        }

        if (self::startupMode() === self::STARTUP_MODE_FULL) {
            return true;
        }

        $writerEpoch = env('CONTROL_PLANE_WRITER_EPOCH', config('control-plane.writer_epoch'));
        $writerMarkerPath = env(
            'CONTROL_PLANE_WRITER_MARKER_PATH',
            config('control-plane.writer_marker_path'),
        );

        if (($writerEpoch === null || $writerEpoch === '')
            && ($writerMarkerPath === null || $writerMarkerPath === '')) {
            return false;
        }

        return self::markerMatches(
            self::validatedWriterMarkerPath($writerMarkerPath),
            self::validatedWriterEpoch($writerEpoch),
        );
    }

    public static function startupWorkAllowed(): bool
    {
        return self::backgroundServicesAllowed();
    }

    public static function isActiveWebOnly(): bool
    {
        return self::configured() === self::Active
            && self::startupMode() === self::STARTUP_MODE_WEB_ONLY;
    }

    public static function webOwnershipProven(): bool
    {
        if (self::configured() === self::Passive) {
            return false;
        }

        if (self::startupMode() === self::STARTUP_MODE_FULL) {
            return true;
        }

        $webEpoch = env('CONTROL_PLANE_WEB_EPOCH', config('control-plane.web_epoch'));
        if (! is_string($webEpoch) || preg_match('/\A[A-Za-z0-9._:-]{16,128}\z/', $webEpoch) !== 1) {
            throw new InvalidArgumentException(
                'CONTROL_PLANE_WEB_EPOCH must contain 16 to 128 safe token characters.'
            );
        }

        $webMarkerPath = env('CONTROL_PLANE_WEB_MARKER_PATH', config('control-plane.web_marker_path'));

        return self::markerMatches(
            self::validatedMarkerPath($webMarkerPath, 'CONTROL_PLANE_WEB_MARKER_PATH'),
            $webEpoch,
        );
    }

    public static function routeDrainActive(): bool
    {
        return self::activeRouteDrainEpoch() !== null;
    }

    public static function activeRouteDrainEpoch(): ?string
    {
        $routeDrainConfiguration = self::routeDrainConfiguration();
        $markerMetadata = self::validatedRegularFileMetadata(
            $routeDrainConfiguration['markerPath'],
            self::MARKER_MODE,
            'Control-plane route-drain marker',
            true,
        );
        if ($markerMetadata === null) {
            return null;
        }

        $markerPath = $routeDrainConfiguration['markerPath'];
        $markerHandle = @fopen($markerPath, 'rb');
        if ($markerHandle === false) {
            throw new InvalidArgumentException("Control-plane route-drain marker could not be opened safely: {$markerPath}");
        }

        try {
            $openedMarkerMetadata = fstat($markerHandle);
            if (! is_array($openedMarkerMetadata)
                || ! self::regularFileMetadataMatches($openedMarkerMetadata, self::MARKER_MODE)
                || $openedMarkerMetadata['dev'] !== $markerMetadata['dev']
                || $openedMarkerMetadata['ino'] !== $markerMetadata['ino']) {
                throw new InvalidArgumentException(
                    "Control-plane route-drain marker changed during validation: {$markerPath}"
                );
            }

            $expectedEpoch = $routeDrainConfiguration['epoch'];
            if ($openedMarkerMetadata['size'] !== strlen($expectedEpoch)) {
                throw new InvalidArgumentException(
                    "Control-plane route-drain marker does not match its configured epoch: {$markerPath}"
                );
            }

            $markerEpoch = stream_get_contents($markerHandle);
            if (! is_string($markerEpoch) || ! hash_equals($expectedEpoch, $markerEpoch)) {
                throw new InvalidArgumentException(
                    "Control-plane route-drain marker does not match its configured epoch: {$markerPath}"
                );
            }

            return $expectedEpoch;
        } finally {
            fclose($markerHandle);
        }
    }

    public static function mutationFreezeActive(): bool
    {
        $freezeConfiguration = self::mutationFreezeConfiguration();
        if ($freezeConfiguration === null) {
            return false;
        }

        return self::strictMutationFreezeMarkerMatches(
            $freezeConfiguration['markerPath'],
            $freezeConfiguration['epoch'],
        );
    }

    public static function mutationLeasePath(): ?string
    {
        $freezeConfiguration = self::mutationFreezeConfiguration();
        if ($freezeConfiguration === null) {
            return null;
        }

        $leasePath = self::mutationLeasePathFor($freezeConfiguration['markerPath']);
        self::validatedRegularFileMetadata(
            $leasePath,
            self::MUTATION_LEASE_MODE,
            'Control-plane mutation lease',
        );

        return $leasePath;
    }

    /**
     * @param  Closure(): mixed  $operation
     */
    public static function withMutationLease(Closure $operation): mixed
    {
        return self::withMutationLeaseMode($operation, self::MUTATION_LEASE_STRICT_PRODUCER);
    }

    /**
     * Run a direct remote operation inside an already-authorized queue drain.
     *
     * @param  Closure(): mixed  $operation
     */
    public static function withMutationOperationLease(Closure $operation): mixed
    {
        return self::withMutationLeaseMode($operation, self::MUTATION_LEASE_DIRECT_OPERATION);
    }

    /**
     * Execute queue work that was accepted before the durable mutation freeze.
     *
     * @param  Closure(): mixed  $operation
     */
    public static function withMutationDrainLease(
        RedisJob $reservedJob,
        Closure $operation,
        ?object $transport = null,
    ): mixed {
        return self::withMutationLeaseMode(
            static function () use ($reservedJob, $operation, $transport): mixed {
                try {
                    ProxyMutationQueue::assertReservedDrainJob($reservedJob, $transport);
                } catch (InvalidArgumentException|LogicException $exception) {
                    throw new ControlPlaneMutationLockedException(
                        'Control-plane mutation drain requires a genuinely reserved canonical Redis job.',
                        previous: $exception,
                    );
                }

                self::acceptedMutationExecutionDepth(1);
                try {
                    return $operation();
                } finally {
                    self::acceptedMutationExecutionDepth(-1);
                }
            },
            self::MUTATION_LEASE_ACCEPTED_EXECUTION,
        );
    }

    public static function acceptedMutationExecutionActive(): bool
    {
        return self::acceptedMutationExecutionDepth() > 0;
    }

    public static function acceptedMutationDrainExecutionActive(): bool
    {
        return self::acceptedMutationExecutionActive() && self::mutationFreezeActive();
    }

    private static function acceptedMutationExecutionDepth(int $change = 0): int
    {
        static $depth = 0;

        $depth += $change;
        if ($depth < 0) {
            throw new LogicException('Accepted proxy-mutation execution context is unbalanced.');
        }

        return $depth;
    }

    /**
     * @param  Closure(): mixed  $operation
     */
    private static function withMutationLeaseMode(Closure $operation, string $requestedMode): mixed
    {
        /** @var array{handle: resource, path: string, metadata: array{dev: int, ino: int, mode: int, uid: int, gid: int, size: int}}|null $mutationLease */
        static $mutationLease = null;
        static $leaseDepth = 0;
        static $leaseAuthorizationMode = null;

        try {
            if (self::configured() === self::Passive) {
                throw new ControlPlaneMutationLockedException(
                    'Control-plane mutations are unavailable in passive control plane mode.'
                );
            }
        } catch (InvalidArgumentException $exception) {
            throw new ControlPlaneMutationLockedException(
                'Control-plane mutation state is invalid.',
                previous: $exception,
            );
        }

        if ($leaseDepth > 0) {
            if (! is_array($mutationLease) || ! is_resource($mutationLease['handle'])) {
                throw new ControlPlaneMutationLockedException('Control-plane mutation lease is no longer held.');
            }

            if ($requestedMode === self::MUTATION_LEASE_ACCEPTED_EXECUTION) {
                throw new ControlPlaneMutationLockedException(
                    'Control-plane mutation drain may only begin at a reserved Redis job boundary.'
                );
            }

            try {
                self::assertMutationLeaseIdentity($mutationLease);
                if (self::nestedMutationLeaseRequiresStrictFreezeCheck(
                    $requestedMode,
                    $leaseAuthorizationMode,
                )) {
                    self::assertStrictMutationFreezeAllowsOperation();
                }
            } catch (InvalidArgumentException $exception) {
                throw new ControlPlaneMutationLockedException(
                    'Control-plane mutation state is invalid.',
                    previous: $exception,
                );
            }

            $leaseDepth++;
            try {
                return $operation();
            } finally {
                $leaseDepth--;
            }
        }

        try {
            $freezeConfiguration = self::mutationFreezeConfiguration();
        } catch (InvalidArgumentException $exception) {
            throw new ControlPlaneMutationLockedException(
                'Control-plane mutation state is invalid.',
                previous: $exception,
            );
        }

        if ($freezeConfiguration === null) {
            return $operation();
        }

        try {
            $openedMutationLease = self::acquireMutationLease($freezeConfiguration['markerPath']);
        } catch (InvalidArgumentException $exception) {
            throw new ControlPlaneMutationLockedException(
                'Control-plane mutation state is invalid.',
                previous: $exception,
            );
        }

        $leaseReleased = false;
        try {
            try {
                $freezeActive = self::strictMutationFreezeMarkerMatches(
                    $freezeConfiguration['markerPath'],
                    $freezeConfiguration['epoch'],
                );
            } catch (InvalidArgumentException $exception) {
                throw new ControlPlaneMutationLockedException(
                    'Control-plane mutation state is invalid.',
                    previous: $exception,
                );
            }

            if ($freezeActive && $requestedMode !== self::MUTATION_LEASE_ACCEPTED_EXECUTION) {
                throw new ControlPlaneMutationLockedException('Control-plane mutations are temporarily locked.');
            }

            $mutationLease = $openedMutationLease;
            $leaseDepth = 1;
            $leaseAuthorizationMode = $requestedMode;

            try {
                return $operation();
            } finally {
                $leaseDepth--;
                if ($leaseDepth === 0) {
                    try {
                        self::releaseMutationLease($mutationLease);
                    } catch (InvalidArgumentException $exception) {
                        throw new ControlPlaneMutationLockedException(
                            'Control-plane mutation state is invalid.',
                            previous: $exception,
                        );
                    } finally {
                        $mutationLease = null;
                        $leaseAuthorizationMode = null;
                        $leaseReleased = true;
                    }
                }
            }
        } finally {
            if (! $leaseReleased) {
                self::closeMutationLease($openedMutationLease);
            }
        }
    }

    private static function nestedMutationLeaseRequiresStrictFreezeCheck(
        string $requestedMode,
        mixed $leaseAuthorizationMode,
    ): bool {
        return $requestedMode === self::MUTATION_LEASE_STRICT_PRODUCER
            || $leaseAuthorizationMode !== self::MUTATION_LEASE_ACCEPTED_EXECUTION;
    }

    private static function assertStrictMutationFreezeAllowsOperation(): void
    {
        $freezeConfiguration = self::mutationFreezeConfiguration();
        if ($freezeConfiguration === null) {
            return;
        }

        if (self::strictMutationFreezeMarkerMatches(
            $freezeConfiguration['markerPath'],
            $freezeConfiguration['epoch'],
        )) {
            throw new ControlPlaneMutationLockedException('Control-plane mutations are temporarily locked.');
        }
    }

    /**
     * @return array{epoch: string, markerPath: string}|null
     */
    private static function mutationFreezeConfiguration(): ?array
    {
        $liveFreezeConfiguration = self::liveMutationFreezeConfiguration();

        if ($liveFreezeConfiguration !== null) {
            $freezeEpoch = $liveFreezeConfiguration['epoch'];
            $freezeMarkerPath = $liveFreezeConfiguration['markerPath'];
        } else {
            $freezeEpoch = config('control-plane.mutation_freeze_epoch');
            $freezeMarkerPath = config('control-plane.mutation_freeze_marker_path');
        }

        if ($freezeEpoch === null && $freezeMarkerPath === null) {
            return null;
        }
        if ($freezeEpoch === null || $freezeMarkerPath === null) {
            throw new InvalidArgumentException(
                'CONTROL_PLANE_MUTATION_FREEZE_EPOCH and CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH must be configured together.'
            );
        }
        if (! is_string($freezeEpoch) || preg_match('/\A[A-Za-z0-9._:-]{16,128}\z/', $freezeEpoch) !== 1) {
            throw new InvalidArgumentException(
                'CONTROL_PLANE_MUTATION_FREEZE_EPOCH must contain 16 to 128 safe token characters.'
            );
        }

        return [
            'epoch' => $freezeEpoch,
            'markerPath' => self::validatedMarkerPath(
                $freezeMarkerPath,
                'CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH'
            ),
        ];
    }

    /**
     * @return array{epoch: string, markerPath: string}|null
     */
    private static function liveMutationFreezeConfiguration(): ?array
    {
        $epochName = 'CONTROL_PLANE_MUTATION_FREEZE_EPOCH';
        $markerPathName = 'CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH';
        $sources = [
            'live environment' => [getenv($epochName), getenv($markerPathName)],
            '$_ENV' => [
                array_key_exists($epochName, $_ENV) ? $_ENV[$epochName] : false,
                array_key_exists($markerPathName, $_ENV) ? $_ENV[$markerPathName] : false,
            ],
            '$_SERVER' => [
                array_key_exists($epochName, $_SERVER) ? $_SERVER[$epochName] : false,
                array_key_exists($markerPathName, $_SERVER) ? $_SERVER[$markerPathName] : false,
            ],
        ];

        foreach ($sources as $source => [$epoch, $markerPath]) {
            if ($epoch === false && $markerPath === false) {
                continue;
            }

            if (! is_string($epoch) || ! is_string($markerPath)) {
                throw new InvalidArgumentException(
                    "CONTROL_PLANE_MUTATION_FREEZE_EPOCH and CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH must be configured together from {$source}."
                );
            }

            return [
                'epoch' => $epoch,
                'markerPath' => $markerPath,
            ];
        }

        return null;
    }

    /**
     * @return array{epoch: string, markerPath: string}
     */
    private static function routeDrainConfiguration(): array
    {
        $routeDrainEpoch = env('CONTROL_PLANE_ROUTE_DRAIN_EPOCH', config('control-plane.route_drain_epoch'));
        if (! is_string($routeDrainEpoch) || preg_match('/\A[A-Za-z0-9._:-]{16,128}\z/', $routeDrainEpoch) !== 1) {
            throw new InvalidArgumentException(
                'CONTROL_PLANE_ROUTE_DRAIN_EPOCH must contain 16 to 128 safe token characters.'
            );
        }

        $routeDrainMarkerPath = env(
            'CONTROL_PLANE_ROUTE_DRAIN_MARKER_PATH',
            config('control-plane.route_drain_marker_path')
        );

        return [
            'epoch' => $routeDrainEpoch,
            'markerPath' => self::validatedMarkerPath(
                $routeDrainMarkerPath,
                'CONTROL_PLANE_ROUTE_DRAIN_MARKER_PATH'
            ),
        ];
    }

    public static function ensureActive(string $operation): void
    {
        if (self::configured() === self::Passive) {
            throw new LogicException("{$operation} is unavailable in passive control plane mode.");
        }
    }

    private static function startupMode(): string
    {
        $startupMode = env(
            'CONTROL_PLANE_STARTUP_MODE',
            config('control-plane.startup_mode', self::STARTUP_MODE_FULL)
        );

        if (in_array($startupMode, [self::STARTUP_MODE_FULL, self::STARTUP_MODE_WEB_ONLY], true)) {
            return $startupMode;
        }

        throw new InvalidArgumentException('CONTROL_PLANE_STARTUP_MODE must be one of: full, web-only.');
    }

    private static function validatedWriterEpoch(mixed $writerEpoch): string
    {
        if (is_string($writerEpoch) && preg_match('/\A[A-Za-z0-9._:-]{16,128}\z/', $writerEpoch) === 1) {
            return $writerEpoch;
        }

        throw new InvalidArgumentException('CONTROL_PLANE_WRITER_EPOCH must contain 16 to 128 safe token characters.');
    }

    private static function validatedWriterMarkerPath(mixed $writerMarkerPath): string
    {
        return self::validatedMarkerPath($writerMarkerPath, 'CONTROL_PLANE_WRITER_MARKER_PATH');
    }

    private static function validatedMarkerPath(mixed $markerPath, string $environmentName): string
    {
        if (! is_string($markerPath)
            || preg_match('#\A/(?!tmp(?:/|\z))(?!.*(?:\A|/)\.\.?(/|\z))[A-Za-z0-9_./:-]+\z#', $markerPath) !== 1) {
            throw new InvalidArgumentException(
                "{$environmentName} must be an absolute persistent path without dot segments."
            );
        }

        return $markerPath;
    }

    private static function markerMatches(string $markerPath, string $expectedEpoch): bool
    {
        return self::markerMatchState($markerPath, $expectedEpoch) === true;
    }

    private static function strictMutationFreezeMarkerMatches(string $markerPath, string $expectedEpoch): bool
    {
        $markerState = self::markerMatchState($markerPath, $expectedEpoch);
        if ($markerState === false) {
            throw new InvalidArgumentException(
                "Control-plane mutation-freeze marker does not match its configured epoch: {$markerPath}"
            );
        }

        return $markerState === true;
    }

    /**
     * @return bool|null true for a match, false for a mismatched valid marker, null when absent
     */
    private static function markerMatchState(string $markerPath, string $expectedEpoch): ?bool
    {
        $markerMetadata = self::validatedRegularFileMetadata(
            $markerPath,
            self::MARKER_MODE,
            'Control-plane marker',
            true,
        );
        if ($markerMetadata === null) {
            return null;
        }

        $markerHandle = @fopen($markerPath, 'rb');
        if ($markerHandle === false) {
            throw new InvalidArgumentException("Control-plane marker could not be opened safely: {$markerPath}");
        }

        try {
            $openedMarkerMetadata = fstat($markerHandle);
            if (! is_array($openedMarkerMetadata)
                || ! self::regularFileMetadataMatches($openedMarkerMetadata, self::MARKER_MODE)
                || $openedMarkerMetadata['dev'] !== $markerMetadata['dev']
                || $openedMarkerMetadata['ino'] !== $markerMetadata['ino']) {
                throw new InvalidArgumentException("Control-plane marker changed during validation: {$markerPath}");
            }

            if ($openedMarkerMetadata['size'] !== strlen($expectedEpoch)) {
                return false;
            }

            $markerEpoch = stream_get_contents($markerHandle);
            if (! is_string($markerEpoch)) {
                throw new InvalidArgumentException("Control-plane marker could not be read safely: {$markerPath}");
            }

            return hash_equals($expectedEpoch, $markerEpoch);
        } finally {
            fclose($markerHandle);
        }
    }

    /**
     * @return array{handle: resource, path: string, metadata: array{dev: int, ino: int, mode: int, uid: int, gid: int, size: int}}
     */
    private static function acquireMutationLease(string $freezeMarkerPath): array
    {
        $leasePath = self::mutationLeasePathFor($freezeMarkerPath);
        $leaseMetadata = self::validatedRegularFileMetadata(
            $leasePath,
            self::MUTATION_LEASE_MODE,
            'Control-plane mutation lease',
        );

        $openedLeaseHandle = @fopen($leasePath, 'rb');
        if ($openedLeaseHandle === false) {
            throw new InvalidArgumentException("Control-plane mutation lease could not be opened safely: {$leasePath}");
        }

        try {
            $openedLeaseMetadata = fstat($openedLeaseHandle);
            if (! is_array($openedLeaseMetadata)
                || ! self::regularFileMetadataMatches($openedLeaseMetadata, self::MUTATION_LEASE_MODE)
                || $openedLeaseMetadata['dev'] !== $leaseMetadata['dev']
                || $openedLeaseMetadata['ino'] !== $leaseMetadata['ino']) {
                throw new InvalidArgumentException("Control-plane mutation lease changed during validation: {$leasePath}");
            }

            if (! flock($openedLeaseHandle, LOCK_SH)) {
                throw new InvalidArgumentException("Control-plane mutation lease could not be acquired: {$leasePath}");
            }

            $mutationLease = [
                'handle' => $openedLeaseHandle,
                'path' => $leasePath,
                'metadata' => $leaseMetadata,
            ];
            self::assertMutationLeaseIdentity($mutationLease);
        } catch (Throwable $exception) {
            fclose($openedLeaseHandle);

            throw $exception;
        }

        return $mutationLease;
    }

    private static function mutationLeasePathFor(string $freezeMarkerPath): string
    {
        return dirname($freezeMarkerPath).'/mutation-inflight.lock';
    }

    /**
     * @param  array{handle: resource, path: string, metadata: array{dev: int, ino: int, mode: int, uid: int, gid: int, size: int}}  $mutationLease
     */
    private static function assertMutationLeaseIdentity(array $mutationLease): void
    {
        $leaseMetadata = self::validatedRegularFileMetadata(
            $mutationLease['path'],
            self::MUTATION_LEASE_MODE,
            'Control-plane mutation lease',
        );
        $openedLeaseMetadata = fstat($mutationLease['handle']);
        if (! is_array($openedLeaseMetadata)
            || ! self::regularFileMetadataMatches($openedLeaseMetadata, self::MUTATION_LEASE_MODE)
            || $leaseMetadata['dev'] !== $mutationLease['metadata']['dev']
            || $leaseMetadata['ino'] !== $mutationLease['metadata']['ino']
            || $openedLeaseMetadata['dev'] !== $mutationLease['metadata']['dev']
            || $openedLeaseMetadata['ino'] !== $mutationLease['metadata']['ino']) {
            throw new InvalidArgumentException(
                "Control-plane mutation lease changed while held: {$mutationLease['path']}"
            );
        }
    }

    /**
     * @param  array{handle: resource, path: string, metadata: array{dev: int, ino: int, mode: int, uid: int, gid: int, size: int}}  $mutationLease
     */
    private static function releaseMutationLease(array $mutationLease): void
    {
        try {
            self::assertMutationLeaseIdentity($mutationLease);
        } finally {
            self::closeMutationLease($mutationLease);
        }
    }

    /**
     * @param  array{handle: resource, path: string, metadata: array{dev: int, ino: int, mode: int, uid: int, gid: int, size: int}}  $mutationLease
     */
    private static function closeMutationLease(array $mutationLease): void
    {
        if (! is_resource($mutationLease['handle'])) {
            return;
        }

        flock($mutationLease['handle'], LOCK_UN);
        fclose($mutationLease['handle']);
    }

    /**
     * @return array{dev: int, ino: int, mode: int, uid: int, gid: int, size: int}|null
     */
    private static function validatedRegularFileMetadata(
        string $path,
        int $expectedMode,
        string $name,
        bool $allowMissing = false,
    ): ?array {
        self::validateStateDirectory(dirname($path));

        clearstatcache(true, $path);
        $metadata = @lstat($path);
        if ($metadata === false) {
            if ($allowMissing) {
                return null;
            }

            throw new InvalidArgumentException("{$name} is missing: {$path}");
        }

        if (! self::regularFileMetadataMatches($metadata, $expectedMode)) {
            throw new InvalidArgumentException(
                sprintf('%s must be a non-symlink regular root:www-data mode-%04o file: %s', $name, $expectedMode, $path)
            );
        }

        return $metadata;
    }

    private static function validateStateDirectory(string $directory): void
    {
        clearstatcache(true, $directory);
        $metadata = @lstat($directory);
        if ($metadata === false
            || ($metadata['mode'] & self::FILE_TYPE_MASK) !== self::DIRECTORY_TYPE
            || $metadata['uid'] !== 0
            || $metadata['gid'] !== self::stateGroupId()
            || ($metadata['mode'] & self::PERMISSION_MASK) !== self::STATE_DIRECTORY_MODE) {
            throw new InvalidArgumentException(
                "Control-plane state directory must be a non-symlink root:www-data mode-0750 directory: {$directory}"
            );
        }
    }

    /** @param array{mode: int, uid: int, gid: int} $metadata */
    private static function regularFileMetadataMatches(array $metadata, int $expectedMode): bool
    {
        return ($metadata['mode'] & self::FILE_TYPE_MASK) === self::REGULAR_FILE_TYPE
            && $metadata['uid'] === 0
            && $metadata['gid'] === self::stateGroupId()
            && ($metadata['mode'] & self::PERMISSION_MASK) === $expectedMode;
    }

    private static function stateGroupId(): int
    {
        if (! function_exists('posix_getgrnam')) {
            throw new InvalidArgumentException('The POSIX extension is required for control-plane state validation.');
        }

        $wwwDataGroup = posix_getgrnam('www-data');
        if (! is_array($wwwDataGroup) || ! isset($wwwDataGroup['gid']) || ! is_int($wwwDataGroup['gid'])) {
            throw new InvalidArgumentException('The www-data group is required for control-plane state validation.');
        }

        return $wwwDataGroup['gid'];
    }

    private static function parse(mixed $mode): self
    {
        if (is_string($mode)) {
            $controlPlaneMode = self::tryFrom($mode);
            if ($controlPlaneMode !== null) {
                return $controlPlaneMode;
            }
        }

        throw new InvalidArgumentException('CONTROL_PLANE_MODE must be one of: active, passive.');
    }
}
