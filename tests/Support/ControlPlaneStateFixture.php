<?php

namespace Tests\Support;

use LogicException;
use RuntimeException;
use Throwable;

final class ControlPlaneStateFixture
{
    private const DIRECTORY_MODE = 0750;

    private const MARKER_MODE = 0440;

    private const MUTATION_LEASE_MODE = 0660;

    private const FILE_TYPE_MASK = 0170000;

    private const DIRECTORY_TYPE = 0040000;

    private const REGULAR_FILE_TYPE = 0100000;

    private const PERMISSION_MASK = 07777;

    private static bool $supportChecked = false;

    private static ?string $supportReason = null;

    private function __construct(
        public readonly string $directory,
        private readonly int $wwwDataGroupId,
    ) {}

    public static function unsupportedReason(): ?string
    {
        if (self::$supportChecked) {
            return self::$supportReason;
        }

        self::$supportReason = self::detectUnsupportedReason();
        self::$supportChecked = true;

        return self::$supportReason;
    }

    private static function detectUnsupportedReason(): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return 'The root:www-data control-plane filesystem contract requires Linux.';
        }

        if (! function_exists('posix_geteuid')
            || ! function_exists('posix_getgrnam')
            || ! function_exists('chown')
            || ! function_exists('chgrp')) {
            return 'The root:www-data control-plane filesystem contract requires the POSIX extension.';
        }

        if (posix_geteuid() !== 0) {
            return 'The root:www-data control-plane filesystem contract requires a root test process.';
        }

        $wwwDataGroup = posix_getgrnam('www-data');
        if (! is_array($wwwDataGroup)
            || ! isset($wwwDataGroup['gid'])
            || ! is_int($wwwDataGroup['gid'])) {
            return 'The root:www-data control-plane filesystem contract requires the www-data group.';
        }

        return self::probeFilesystemContract($wwwDataGroup['gid']);
    }

    public static function create(string $prefix): self
    {
        $unsupportedReason = self::unsupportedReason();
        if ($unsupportedReason !== null) {
            throw new LogicException($unsupportedReason);
        }

        $wwwDataGroup = posix_getgrnam('www-data');
        if (! is_array($wwwDataGroup) || ! isset($wwwDataGroup['gid']) || ! is_int($wwwDataGroup['gid'])) {
            throw new LogicException('The www-data group disappeared while creating the control-plane fixture.');
        }

        $directory = storage_path('framework/testing/'.$prefix.'-'.bin2hex(random_bytes(8)));
        if (! @mkdir($directory, self::DIRECTORY_MODE)) {
            throw new RuntimeException("Could not create control-plane state fixture: {$directory}");
        }

        $fixture = new self($directory, $wwwDataGroup['gid']);

        try {
            $fixture->applyMetadata($directory, self::DIRECTORY_MODE);
            $fixture->assertMetadata($directory, self::DIRECTORY_TYPE, self::DIRECTORY_MODE);
        } catch (Throwable $exception) {
            $fixture->cleanupIgnoringFailures();

            throw $exception;
        }

        return $fixture;
    }

    public function path(string $filename): string
    {
        if (preg_match('/\A[A-Za-z0-9_.:-]+\z/', $filename) !== 1
            || in_array($filename, ['.', '..'], true)) {
            throw new LogicException("Invalid control-plane fixture filename: {$filename}");
        }

        return $this->directory.'/'.$filename;
    }

    public function writeMarker(string $filename, string $epoch): string
    {
        return $this->writeFile($filename, $epoch, self::MARKER_MODE);
    }

    public function writeMutationLease(): string
    {
        return $this->writeFile('mutation-inflight.lock', '', self::MUTATION_LEASE_MODE);
    }

    public function remove(string $filename): void
    {
        $path = $this->path($filename);
        clearstatcache(true, $path);

        if (@lstat($path) === false) {
            return;
        }

        if (! @unlink($path)) {
            throw new RuntimeException("Could not remove control-plane fixture file: {$path}");
        }
    }

    public function cleanup(): void
    {
        if (! $this->cleanupIgnoringFailures()) {
            throw new RuntimeException("Could not clean up control-plane state fixture: {$this->directory}");
        }
    }

    public function __destruct()
    {
        $this->cleanupIgnoringFailures();
    }

    private function writeFile(string $filename, string $contents, int $mode): string
    {
        $path = $this->path($filename);
        $writtenBytes = @file_put_contents($path, $contents, LOCK_EX);
        if ($writtenBytes !== strlen($contents)) {
            @unlink($path);

            throw new RuntimeException("Could not write control-plane fixture file: {$path}");
        }

        try {
            $this->applyMetadata($path, $mode);
            $this->assertMetadata($path, self::REGULAR_FILE_TYPE, $mode);
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        }

        return $path;
    }

    private function applyMetadata(string $path, int $mode): void
    {
        if (! @chown($path, 0)
            || ! @chgrp($path, $this->wwwDataGroupId)
            || ! @chmod($path, $mode)) {
            throw new RuntimeException("Could not apply root:www-data metadata to control-plane fixture: {$path}");
        }
    }

    private function assertMetadata(string $path, int $fileType, int $mode): void
    {
        if (! self::metadataMatches($path, $this->wwwDataGroupId, $fileType, $mode)) {
            throw new RuntimeException(
                sprintf('Control-plane fixture does not satisfy root:www-data mode-%04o: %s', $mode, $path)
            );
        }
    }

    private static function probeFilesystemContract(int $wwwDataGroupId): ?string
    {
        $directory = storage_path(
            'framework/testing/coolify-control-plane-contract-probe-'.bin2hex(random_bytes(8))
        );
        $markerPath = $directory.'/marker';
        $unsupportedReason = null;

        if (! @mkdir($directory, self::DIRECTORY_MODE)) {
            return 'The test filesystem cannot create a control-plane state directory.';
        }

        try {
            if (! self::applyMetadataForGroup($directory, $wwwDataGroupId, self::DIRECTORY_MODE)
                || ! self::metadataMatches(
                    $directory,
                    $wwwDataGroupId,
                    self::DIRECTORY_TYPE,
                    self::DIRECTORY_MODE,
                )) {
                $unsupportedReason = 'The test filesystem cannot represent a root:www-data mode-0750 directory.';
            }

            if ($unsupportedReason === null) {
                $writtenBytes = @file_put_contents($markerPath, 'probe');
                if ($writtenBytes !== 5
                    || ! self::applyMetadataForGroup($markerPath, $wwwDataGroupId, self::MARKER_MODE)
                    || ! self::metadataMatches(
                        $markerPath,
                        $wwwDataGroupId,
                        self::REGULAR_FILE_TYPE,
                        self::MARKER_MODE,
                    )) {
                    $unsupportedReason = 'The test filesystem cannot represent a root:www-data mode-0440 marker.';
                }
            }
        } finally {
            $cleanupSucceeded = true;
            clearstatcache(true, $markerPath);
            if (@lstat($markerPath) !== false && ! @unlink($markerPath)) {
                $cleanupSucceeded = false;
            }
            $directoryModeRestored = @chmod($directory, 0700);
            $directoryRemoved = @rmdir($directory);
            if (! $directoryModeRestored || ! $directoryRemoved) {
                $cleanupSucceeded = false;
            }
            if (! $cleanupSucceeded && $unsupportedReason === null) {
                $unsupportedReason = 'The test filesystem cannot safely clean up control-plane fixtures.';
            }
        }

        return $unsupportedReason;
    }

    private static function applyMetadataForGroup(string $path, int $wwwDataGroupId, int $mode): bool
    {
        return @chown($path, 0)
            && @chgrp($path, $wwwDataGroupId)
            && @chmod($path, $mode);
    }

    private static function metadataMatches(string $path, int $wwwDataGroupId, int $fileType, int $mode): bool
    {
        clearstatcache(true, $path);
        $metadata = @lstat($path);

        return is_array($metadata)
            && ($metadata['mode'] & self::FILE_TYPE_MASK) === $fileType
            && $metadata['uid'] === 0
            && $metadata['gid'] === $wwwDataGroupId
            && ($metadata['mode'] & self::PERMISSION_MASK) === $mode;
    }

    private function cleanupIgnoringFailures(): bool
    {
        clearstatcache(true, $this->directory);
        if (@lstat($this->directory) === false) {
            return true;
        }

        $entries = @scandir($this->directory);
        if (! is_array($entries)) {
            return false;
        }

        $cleaned = true;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->directory.'/'.$entry;
            if (! @unlink($path)) {
                $cleaned = false;
            }
        }

        $directoryModeRestored = @chmod($this->directory, 0700);
        $directoryRemoved = @rmdir($this->directory);
        if (! $directoryModeRestored || ! $directoryRemoved) {
            $cleaned = false;
        }

        return $cleaned;
    }
}
