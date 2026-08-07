<?php

use App\Actions\Application\BlueGreen\BlueGreenManagedRouteMetadataForOperationResult;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @param array<string, string> $environment */
function runCommittedContainerJournalCommand(string $command, array $environment = []): Process
{
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(10);
    if ($environment !== []) {
        $process->setEnv($environment);
    }
    $process->run();

    return $process;
}

/**
 * @return array{
 *     boot_id_path: string,
 *     expected: BlueGreenProxyState,
 *     journal_path: string,
 *     journal_sha256: string,
 *     managed_filename: string,
 *     managed_path: string,
 *     marker_path: string,
 *     proxy_path: string,
 *     replacement: BlueGreenProxyState,
 *     root: string,
 *     state_path: string,
 *     writer: WriteBlueGreenProxyConfiguration
 * }
 */
function committedContainerJournalFixture(bool $presentManagedRoute = false): array
{
    $filesystem = new Filesystem;
    $root = sys_get_temp_dir().'/coolify-committed-container-journal-'.bin2hex(random_bytes(8));
    $proxyPath = $root.'/proxy';
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);
    $managedFilename = BlueGreenRoutingTarget::managedFilename('app-committed-journal', 42);
    $managedRouteBytes = $presentManagedRoute ? "http:\n  routers: {}\n" : null;
    $expected = new BlueGreenProxyState(
        managedFilename: $managedFilename,
        applicationUuid: 'app-committed-journal',
        destinationId: 42,
        operationId: 'predecessor-operation',
        mutationSequence: 1,
        destinationFenceEpoch: $managedRouteBytes === null ? 0 : 1,
        routingRevision: $managedRouteBytes === null ? 0 : 1,
        managedSha256: $managedRouteBytes === null ? null : hash('sha256', $managedRouteBytes),
        activeColor: $managedRouteBytes === null ? null : BlueGreenDeploymentColor::BLUE,
        activeDeploymentUuid: $managedRouteBytes === null ? null : 'deployment-blue',
        activeContainerName: $managedRouteBytes === null ? null : 'app-committed-journal-blue',
        activeContainerId: $managedRouteBytes === null ? null : '0123456789abcdef',
        applicationRoutingConfigDigest: hash('sha256', 'routing'),
        destinationTopologyDigest: hash('sha256', 'topology'),
    );
    $replacement = $expected->withMutationOwner('committed-operation');
    $writer = new WriteBlueGreenProxyConfiguration;
    $statePath = $writer->statePath($proxyPath, $managedFilename);
    $managedPath = $writer->managedPath($proxyPath, $managedFilename);
    $journalPath = $writer->containerMutationJournalPath($proxyPath, $managedFilename);
    $markerPath = $root.'/mutation-marker';
    $bootId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $bootIdPath = $root.'/boot-id';
    $filesystem->mkdir(dirname($statePath), 0700);
    file_put_contents($statePath, $expected->serialize());
    chmod($statePath, 0600);
    if ($managedRouteBytes !== null) {
        file_put_contents($managedPath, $managedRouteBytes);
        chmod($managedPath, 0600);
    }
    file_put_contents($markerPath, 'before');
    file_put_contents($bootIdPath, $bootId);

    $crashingWriter = new class($bootIdPath) extends WriteBlueGreenProxyConfiguration
    {
        public function __construct(private readonly string $bootIdPath) {}

        /** @return list<string> */
        protected function afterContainerMutationCommands(): array
        {
            return ['exit 87'];
        }

        protected function bootIdentityAssertionCommand(string $expectedBootIdShellValue): string
        {
            return 'test "$(cat '.escapeshellarg($this->bootIdPath).')" = '.$expectedBootIdShellValue;
        }
    };
    $interrupted = runCommittedContainerJournalCommand(
        $crashingWriter->fencedDestinationCommandFor(
            proxyPath: $proxyPath,
            managedFilename: $managedFilename,
            expectedState: $expected,
            replacementState: $replacement,
            expectedBootId: $bootId,
            commands: [
                'printf %s mutation-applied > '.escapeshellarg($markerPath),
            ],
            completionCommands: [
                'test "$(cat '.escapeshellarg($markerPath).')" = mutation-applied',
            ],
        ),
    );
    if ($interrupted->getExitCode() !== 87 || ! is_file($journalPath)) {
        $filesystem->remove($root);
        throw new RuntimeException('The committed container-journal fixture did not stop in the intended crash window.');
    }

    $writer = new class($bootIdPath) extends WriteBlueGreenProxyConfiguration
    {
        public function __construct(private readonly string $bootIdPath) {}

        protected function bootIdentityAssertionCommand(string $expectedBootIdShellValue): string
        {
            return 'test "$(cat '.escapeshellarg($this->bootIdPath).')" = '.$expectedBootIdShellValue;
        }
    };

    return [
        'boot_id_path' => $bootIdPath,
        'expected' => $expected,
        'journal_path' => $journalPath,
        'journal_sha256' => hash_file('sha256', $journalPath),
        'managed_filename' => $managedFilename,
        'managed_path' => $managedPath,
        'marker_path' => $markerPath,
        'proxy_path' => $proxyPath,
        'replacement' => $replacement,
        'root' => $root,
        'state_path' => $statePath,
        'writer' => $writer,
    ];
}

it('archives only a journal whose exact replacement sidecar is already durable', function (): void {
    $fixture = committedContainerJournalFixture();
    $filesystem = new Filesystem;

    try {
        file_put_contents($fixture['state_path'], $fixture['replacement']->serialize());
        chmod($fixture['state_path'], 0600);

        $inspection = runCommittedContainerJournalCommand(
            $fixture['writer']->inspectCommittedContainerMutationJournalCommandFor(
                $fixture['proxy_path'],
                $fixture['managed_filename'],
            ),
        );
        expect($inspection->isSuccessful())->toBeTrue($inspection->getErrorOutput())
            ->and($inspection->getOutput())
            ->toContain(WriteBlueGreenProxyConfiguration::COMMITTED_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX.'|committed|'.$fixture['journal_sha256'])
            ->and(is_file($fixture['journal_path']))->toBeTrue();

        $archive = runCommittedContainerJournalCommand(
            $fixture['writer']->archiveCommittedContainerMutationJournalCommandFor(
                proxyPath: $fixture['proxy_path'],
                managedFilename: $fixture['managed_filename'],
                expectedJournalSha256: $fixture['journal_sha256'],
                expectedState: $fixture['expected'],
                replacementState: $fixture['replacement'],
            ),
        );
        $archivePath = dirname($fixture['journal_path']).'/'.$fixture['writer']
            ->committedContainerMutationJournalArchiveFilename(
                $fixture['managed_filename'],
                $fixture['journal_sha256'],
            );
        $manifestPath = $archivePath.'.manifest';

        expect($archive->isSuccessful())->toBeTrue($archive->getErrorOutput())
            ->and(trim($archive->getOutput()))->toBe(
                WriteBlueGreenProxyConfiguration::COMMITTED_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX
                .'|archived|'.$fixture['journal_sha256'].'|'.basename($archivePath),
            )
            ->and(file_exists($fixture['journal_path']))->toBeFalse()
            ->and(hash_file('sha256', $archivePath))->toBe($fixture['journal_sha256'])
            ->and(is_file($manifestPath))->toBeTrue()
            ->and(file_get_contents($manifestPath))->toContain(
                'coolify-blue-green-committed-container-journal-archive-v1',
                $fixture['journal_sha256'],
                hash('sha256', $fixture['replacement']->serialize()),
                basename($archivePath),
            )
            ->and(file_get_contents($fixture['state_path']))->toBe($fixture['replacement']->serialize())
            ->and(file_get_contents($fixture['marker_path']))->toBe('mutation-applied');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('leaves a committed journal untouched when its replacement sidecar reverts before archival', function (): void {
    $fixture = committedContainerJournalFixture();
    $filesystem = new Filesystem;

    try {
        file_put_contents($fixture['state_path'], $fixture['replacement']->serialize());
        chmod($fixture['state_path'], 0600);
        $inspection = runCommittedContainerJournalCommand(
            $fixture['writer']->inspectCommittedContainerMutationJournalCommandFor(
                $fixture['proxy_path'],
                $fixture['managed_filename'],
            ),
        );
        expect($inspection->isSuccessful())->toBeTrue($inspection->getErrorOutput());

        file_put_contents($fixture['state_path'], $fixture['expected']->serialize());
        chmod($fixture['state_path'], 0600);
        $archive = runCommittedContainerJournalCommand(
            $fixture['writer']->archiveCommittedContainerMutationJournalCommandFor(
                proxyPath: $fixture['proxy_path'],
                managedFilename: $fixture['managed_filename'],
                expectedJournalSha256: $fixture['journal_sha256'],
                expectedState: $fixture['expected'],
                replacementState: $fixture['replacement'],
            ),
        );
        $archivePath = dirname($fixture['journal_path']).'/'.$fixture['writer']
            ->committedContainerMutationJournalArchiveFilename(
                $fixture['managed_filename'],
                $fixture['journal_sha256'],
            );

        expect($archive->isSuccessful())->toBeFalse()
            ->and(is_file($fixture['journal_path']))->toBeTrue()
            ->and(file_exists($archivePath))->toBeFalse()
            ->and(file_exists($archivePath.'.manifest'))->toBeFalse()
            ->and(file_get_contents($fixture['state_path']))->toBe($fixture['expected']->serialize())
            ->and(file_get_contents($fixture['marker_path']))->toBe('mutation-applied');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('refuses to archive an absent replacement that gained a managed route', function (): void {
    $fixture = committedContainerJournalFixture();
    $filesystem = new Filesystem;

    try {
        file_put_contents($fixture['state_path'], $fixture['replacement']->serialize());
        chmod($fixture['state_path'], 0600);
        file_put_contents($fixture['managed_path'], "http:\n  routers: {unexpected: {}}\n");
        chmod($fixture['managed_path'], 0600);
        $archive = runCommittedContainerJournalCommand(
            $fixture['writer']->archiveCommittedContainerMutationJournalCommandFor(
                proxyPath: $fixture['proxy_path'],
                managedFilename: $fixture['managed_filename'],
                expectedJournalSha256: $fixture['journal_sha256'],
                expectedState: $fixture['expected'],
                replacementState: $fixture['replacement'],
            ),
        );
        $archivePath = dirname($fixture['journal_path']).'/'.$fixture['writer']
            ->committedContainerMutationJournalArchiveFilename(
                $fixture['managed_filename'],
                $fixture['journal_sha256'],
            );

        expect($archive->isSuccessful())->toBeFalse()
            ->and(is_file($fixture['journal_path']))->toBeTrue()
            ->and(file_exists($archivePath))->toBeFalse()
            ->and(file_get_contents($fixture['state_path']))->toBe($fixture['replacement']->serialize())
            ->and(file_get_contents($fixture['managed_path']))->toBe("http:\n  routers: {unexpected: {}}\n")
            ->and(file_get_contents($fixture['marker_path']))->toBe('mutation-applied');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('refuses to archive a present replacement whose managed route checksum drifted', function (): void {
    $fixture = committedContainerJournalFixture(presentManagedRoute: true);
    $filesystem = new Filesystem;

    try {
        file_put_contents($fixture['state_path'], $fixture['replacement']->serialize());
        chmod($fixture['state_path'], 0600);
        file_put_contents($fixture['managed_path'], "http:\n  routers: {drifted: {}}\n");
        chmod($fixture['managed_path'], 0600);
        $archive = runCommittedContainerJournalCommand(
            $fixture['writer']->archiveCommittedContainerMutationJournalCommandFor(
                proxyPath: $fixture['proxy_path'],
                managedFilename: $fixture['managed_filename'],
                expectedJournalSha256: $fixture['journal_sha256'],
                expectedState: $fixture['expected'],
                replacementState: $fixture['replacement'],
            ),
        );
        $archivePath = dirname($fixture['journal_path']).'/'.$fixture['writer']
            ->committedContainerMutationJournalArchiveFilename(
                $fixture['managed_filename'],
                $fixture['journal_sha256'],
            );

        expect($archive->isSuccessful())->toBeFalse()
            ->and(is_file($fixture['journal_path']))->toBeTrue()
            ->and(file_exists($archivePath))->toBeFalse()
            ->and(file_get_contents($fixture['state_path']))->toBe($fixture['replacement']->serialize())
            ->and(file_get_contents($fixture['managed_path']))->toBe("http:\n  routers: {drifted: {}}\n")
            ->and(file_get_contents($fixture['marker_path']))->toBe('mutation-applied');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('preserves expected-snapshot and cross-boot work without replay or archival', function (): void {
    $fixture = committedContainerJournalFixture();
    $filesystem = new Filesystem;

    try {
        file_put_contents($fixture['marker_path'], 'must-not-replay');
        $journalBefore = file_get_contents($fixture['journal_path']);
        $inspection = runCommittedContainerJournalCommand(
            $fixture['writer']->inspectCommittedContainerMutationJournalCommandFor(
                $fixture['proxy_path'],
                $fixture['managed_filename'],
            ),
        );

        expect($inspection->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['journal_path']))->toBe($journalBefore)
            ->and(file_get_contents($fixture['state_path']))->toBe($fixture['expected']->serialize())
            ->and(file_get_contents($fixture['marker_path']))->toBe('must-not-replay');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('refuses an absent journal inspection when the destination boot changed after capture', function (): void {
    $fixture = committedContainerJournalFixture();
    $filesystem = new Filesystem;

    try {
        file_put_contents($fixture['marker_path'], 'must-not-replay');
        $capturedBootId = trim((string) file_get_contents($fixture['boot_id_path']));
        unlink($fixture['journal_path']);
        file_put_contents($fixture['boot_id_path'], 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff');
        $command = $fixture['writer']->inspectContainerMutationJournalCommandFor(
            $fixture['proxy_path'],
            $fixture['managed_filename'],
            $capturedBootId,
        );
        $inspection = runCommittedContainerJournalCommand($command);

        expect($inspection->isSuccessful())->toBeFalse()
            ->and(trim($inspection->getOutput()))->toBe('')
            ->and(file_get_contents($fixture['marker_path']))->toBe('must-not-replay');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('archives a pending expected-sidecar journal through the typed CAS without executing stored scripts', function (): void {
    $fixture = committedContainerJournalFixture();
    $filesystem = new Filesystem;

    try {
        file_put_contents($fixture['marker_path'], 'must-not-replay');
        $bootId = trim((string) file_get_contents($fixture['boot_id_path']));
        $inspectionCommand = $fixture['writer']->inspectContainerMutationJournalCommandFor(
            $fixture['proxy_path'],
            $fixture['managed_filename'],
        );
        $inspection = runCommittedContainerJournalCommand($inspectionCommand);
        $archiveCommand = $fixture['writer']->archivePendingContainerMutationJournalCommandFor(
            proxyPath: $fixture['proxy_path'],
            managedFilename: $fixture['managed_filename'],
            expectedJournalSha256: $fixture['journal_sha256'],
            expectedJournalBootId: $bootId,
            expectedState: $fixture['expected'],
            replacementState: $fixture['replacement'],
        );
        $archive = runCommittedContainerJournalCommand($archiveCommand);
        $archivePath = dirname($fixture['journal_path']).'/'.$fixture['writer']
            ->containerMutationJournalArchiveFilename(
                $fixture['managed_filename'],
                $fixture['journal_sha256'],
            );

        expect($inspectionCommand)->not->toContain(
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        )
            ->and($inspection->isSuccessful())->toBeTrue($inspection->getErrorOutput())
            ->and($inspection->getOutput())->toStartWith(implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                $fixture['journal_sha256'],
                $bootId,
            ]))
            ->and($archiveCommand)->not->toContain(
                'sh "$operation_container_mutation_decoded"',
                'sh "$operation_container_completion_decoded"',
            )
            ->and($archive->isSuccessful())->toBeTrue($archive->getErrorOutput())
            ->and(trim($archive->getOutput()))->toBe(implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                $fixture['journal_sha256'],
                basename($archivePath),
            ]))
            ->and(file_exists($fixture['journal_path']))->toBeFalse()
            ->and(hash_file('sha256', $archivePath))->toBe($fixture['journal_sha256'])
            ->and(file_get_contents($fixture['state_path']))->toBe($fixture['expected']->serialize())
            ->and(file_get_contents($fixture['marker_path']))->toBe('must-not-replay');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('archives an authenticated old-boot journal under the separately fenced current boot', function (bool $finalizeReplacement): void {
    $fixture = committedContainerJournalFixture();
    $filesystem = new Filesystem;

    try {
        file_put_contents($fixture['marker_path'], 'must-not-replay');
        $journalBootId = trim((string) file_get_contents($fixture['boot_id_path']));
        $currentBootId = 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff';
        file_put_contents($fixture['boot_id_path'], $currentBootId);
        if ($finalizeReplacement) {
            file_put_contents($fixture['state_path'], $fixture['replacement']->serialize());
            chmod($fixture['state_path'], 0600);
        }
        $command = $finalizeReplacement
            ? $fixture['writer']->finalizePendingContainerMutationJournalCommandFor(
                proxyPath: $fixture['proxy_path'],
                managedFilename: $fixture['managed_filename'],
                expectedJournalSha256: $fixture['journal_sha256'],
                expectedJournalBootId: $journalBootId,
                expectedState: $fixture['expected'],
                replacementState: $fixture['replacement'],
                expectedCurrentBootId: $currentBootId,
            )
            : $fixture['writer']->archivePendingContainerMutationJournalCommandFor(
                proxyPath: $fixture['proxy_path'],
                managedFilename: $fixture['managed_filename'],
                expectedJournalSha256: $fixture['journal_sha256'],
                expectedJournalBootId: $journalBootId,
                expectedState: $fixture['expected'],
                replacementState: $fixture['replacement'],
                expectedCurrentBootId: $currentBootId,
            );
        $archive = runCommittedContainerJournalCommand($command);
        $archivePath = dirname($fixture['journal_path']).'/'.$fixture['writer']
            ->containerMutationJournalArchiveFilename(
                $fixture['managed_filename'],
                $fixture['journal_sha256'],
            );
        $expectedSidecarStatus = $finalizeReplacement
            ? BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR
            : BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR;

        expect($archive->isSuccessful())->toBeTrue($archive->getErrorOutput())
            ->and(trim($archive->getOutput()))->toBe(implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                $expectedSidecarStatus,
                $fixture['journal_sha256'],
                basename($archivePath),
            ]))
            ->and(file_exists($fixture['journal_path']))->toBeFalse()
            ->and(hash_file('sha256', $archivePath))->toBe($fixture['journal_sha256'])
            ->and(file($archivePath.'.manifest', FILE_IGNORE_NEW_LINES)[5] ?? null)->toBe($journalBootId)
            ->and(file_get_contents($fixture['state_path']))->toBe(
                ($finalizeReplacement ? $fixture['replacement'] : $fixture['expected'])->serialize(),
            )
            ->and(file_get_contents($fixture['marker_path']))->toBe('must-not-replay');
    } finally {
        $filesystem->remove($fixture['root']);
    }
})->with([
    'pending expected sidecar' => [false],
    'committed replacement sidecar' => [true],
]);

it('idempotently archives an exact regenerated live journal beside its typed pending archive', function (): void {
    $fixture = committedContainerJournalFixture();
    $filesystem = new Filesystem;

    try {
        file_put_contents($fixture['marker_path'], 'must-not-replay');
        $bootId = trim((string) file_get_contents($fixture['boot_id_path']));
        $command = $fixture['writer']->archivePendingContainerMutationJournalCommandFor(
            proxyPath: $fixture['proxy_path'],
            managedFilename: $fixture['managed_filename'],
            expectedJournalSha256: $fixture['journal_sha256'],
            expectedJournalBootId: $bootId,
            expectedState: $fixture['expected'],
            replacementState: $fixture['replacement'],
        );
        $archivePath = dirname($fixture['journal_path']).'/'.$fixture['writer']
            ->containerMutationJournalArchiveFilename(
                $fixture['managed_filename'],
                $fixture['journal_sha256'],
            );
        $manifestPath = $archivePath.'.manifest';
        $seededArchive = runCommittedContainerJournalCommand($command);
        $archiveBytes = (string) file_get_contents($archivePath);
        $manifestBytes = (string) file_get_contents($manifestPath);

        file_put_contents($fixture['journal_path'], $archiveBytes);
        chmod($fixture['journal_path'], 0600);
        $idempotentArchive = runCommittedContainerJournalCommand($command);

        expect($seededArchive->isSuccessful())->toBeTrue($seededArchive->getErrorOutput())
            ->and($idempotentArchive->isSuccessful())->toBeTrue($idempotentArchive->getErrorOutput())
            ->and(trim($idempotentArchive->getOutput()))->toBe(implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                $fixture['journal_sha256'],
                basename($archivePath),
            ]))
            ->and(file_exists($fixture['journal_path']))->toBeFalse()
            ->and(file_get_contents($archivePath))->toBe($archiveBytes)
            ->and(file_get_contents($manifestPath))->toBe($manifestBytes)
            ->and(file_get_contents($fixture['state_path']))->toBe($fixture['expected']->serialize())
            ->and(file_get_contents($fixture['marker_path']))->toBe('must-not-replay');

        $mismatchedJournalBytes = $archiveBytes."foreign\n";
        file_put_contents($fixture['journal_path'], $mismatchedJournalBytes);
        chmod($fixture['journal_path'], 0600);
        $mismatchedArchive = runCommittedContainerJournalCommand($command);

        expect($mismatchedArchive->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['journal_path']))->toBe($mismatchedJournalBytes)
            ->and(file_get_contents($archivePath))->toBe($archiveBytes)
            ->and(file_get_contents($manifestPath))->toBe($manifestBytes)
            ->and(file_get_contents($fixture['state_path']))->toBe($fixture['expected']->serialize())
            ->and(file_get_contents($fixture['marker_path']))->toBe('must-not-replay');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('retries finalization after a replacement-sidecar crash against the exact finalized manifest', function (): void {
    $fixture = committedContainerJournalFixture();
    $filesystem = new Filesystem;

    try {
        file_put_contents($fixture['marker_path'], 'must-not-replay');
        $bootId = trim((string) file_get_contents($fixture['boot_id_path']));
        $command = $fixture['writer']->finalizePendingContainerMutationJournalCommandFor(
            proxyPath: $fixture['proxy_path'],
            managedFilename: $fixture['managed_filename'],
            expectedJournalSha256: $fixture['journal_sha256'],
            expectedJournalBootId: $bootId,
            expectedState: $fixture['expected'],
            replacementState: $fixture['replacement'],
        );
        $archivePath = dirname($fixture['journal_path']).'/'.$fixture['writer']
            ->containerMutationJournalArchiveFilename(
                $fixture['managed_filename'],
                $fixture['journal_sha256'],
            );
        $manifestPath = $archivePath.'.manifest';
        $expectedManifest = [
            'coolify-blue-green-finalized-container-journal-archive-v1',
            $fixture['managed_filename'],
            $fixture['journal_sha256'],
            hash('sha256', $fixture['expected']->serialize()),
            hash('sha256', $fixture['replacement']->serialize()),
            $bootId,
            'missing',
            hash('sha256', ''),
            BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
            basename($archivePath),
        ];
        $crashed = runCommittedContainerJournalCommand($command, [
            'COOLIFY_BLUE_GREEN_CONTAINER_JOURNAL_CAS_CRASH_AFTER_REPLACEMENT_SIDECAR' => '1',
        ]);

        expect($command)->not->toContain(
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        )
            ->and($crashed->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['state_path']))->toBe($fixture['replacement']->serialize())
            ->and(is_file($fixture['journal_path']))->toBeTrue()
            ->and(file_exists($archivePath))->toBeFalse()
            ->and(file($manifestPath, FILE_IGNORE_NEW_LINES))->toBe($expectedManifest)
            ->and(file_get_contents($fixture['marker_path']))->toBe('must-not-replay');

        $retried = runCommittedContainerJournalCommand($command);

        expect($retried->isSuccessful())->toBeTrue($retried->getErrorOutput())
            ->and(trim($retried->getOutput()))->toBe(implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                $fixture['journal_sha256'],
                basename($archivePath),
            ]))
            ->and(file_exists($fixture['journal_path']))->toBeFalse()
            ->and(hash_file('sha256', $archivePath))->toBe($fixture['journal_sha256'])
            ->and(file($manifestPath, FILE_IGNORE_NEW_LINES))->toBe($expectedManifest)
            ->and(file_get_contents($fixture['state_path']))->toBe($fixture['replacement']->serialize())
            ->and(file_get_contents($fixture['marker_path']))->toBe('must-not-replay');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('archives an old-boot committed journal only when historical and current boots are independently attested', function (): void {
    $fixture = committedContainerJournalFixture();
    $filesystem = new Filesystem;

    try {
        file_put_contents($fixture['marker_path'], 'must-not-replay');
        file_put_contents($fixture['state_path'], $fixture['replacement']->serialize());
        chmod($fixture['state_path'], 0600);
        $journalBootId = trim((string) file_get_contents($fixture['boot_id_path']));
        $currentBootId = 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff';
        file_put_contents($fixture['boot_id_path'], $currentBootId);
        $wrongHistoricalBoot = runCommittedContainerJournalCommand(
            $fixture['writer']->archiveCommittedContainerMutationJournalCommandFor(
                proxyPath: $fixture['proxy_path'],
                managedFilename: $fixture['managed_filename'],
                expectedJournalSha256: $fixture['journal_sha256'],
                expectedState: $fixture['expected'],
                replacementState: $fixture['replacement'],
                expectedJournalBootId: $currentBootId,
                expectedCurrentBootId: $currentBootId,
            ),
        );
        $wrongCurrentBoot = runCommittedContainerJournalCommand(
            $fixture['writer']->archiveCommittedContainerMutationJournalCommandFor(
                proxyPath: $fixture['proxy_path'],
                managedFilename: $fixture['managed_filename'],
                expectedJournalSha256: $fixture['journal_sha256'],
                expectedState: $fixture['expected'],
                replacementState: $fixture['replacement'],
                expectedJournalBootId: $journalBootId,
                expectedCurrentBootId: 'cccccccc-dddd-eeee-ffff-000000000000',
            ),
        );
        $archiveCommand = $fixture['writer']->archiveCommittedContainerMutationJournalCommandFor(
            proxyPath: $fixture['proxy_path'],
            managedFilename: $fixture['managed_filename'],
            expectedJournalSha256: $fixture['journal_sha256'],
            expectedState: $fixture['expected'],
            replacementState: $fixture['replacement'],
            expectedJournalBootId: $journalBootId,
            expectedCurrentBootId: $currentBootId,
        );
        $archive = runCommittedContainerJournalCommand($archiveCommand);
        $archivePath = dirname($fixture['journal_path']).'/'.$fixture['writer']
            ->committedContainerMutationJournalArchiveFilename(
                $fixture['managed_filename'],
                $fixture['journal_sha256'],
            );

        expect($wrongHistoricalBoot->isSuccessful())->toBeFalse()
            ->and($wrongCurrentBoot->isSuccessful())->toBeFalse()
            ->and($archiveCommand)->toContain($journalBootId)
            ->and($archiveCommand)->toContain($currentBootId)
            ->and($archiveCommand)->not->toContain(
                'sh "$committed_container_mutation_decoded"',
                'sh "$committed_container_completion_decoded"',
            )
            ->and($archive->isSuccessful())->toBeTrue($archive->getErrorOutput())
            ->and(is_file($fixture['journal_path']))->toBeFalse()
            ->and(hash_file('sha256', $archivePath))->toBe($fixture['journal_sha256'])
            ->and(file($archivePath.'.manifest', FILE_IGNORE_NEW_LINES)[5] ?? null)->toBe($journalBootId)
            ->and(file_get_contents($fixture['state_path']))->toBe($fixture['replacement']->serialize())
            ->and(file_get_contents($fixture['marker_path']))->toBe('must-not-replay');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('rejects corrupt, permissive, and hard-linked committed journal evidence', function (Closure $corrupt): void {
    $fixture = committedContainerJournalFixture();
    $filesystem = new Filesystem;

    try {
        file_put_contents($fixture['state_path'], $fixture['replacement']->serialize());
        chmod($fixture['state_path'], 0600);
        $cleanup = $corrupt($fixture);
        $inspection = runCommittedContainerJournalCommand(
            $fixture['writer']->inspectCommittedContainerMutationJournalCommandFor(
                $fixture['proxy_path'],
                $fixture['managed_filename'],
            ),
        );
        $cleanup?->__invoke();

        expect($inspection->isSuccessful())->toBeFalse()
            ->and(file_exists($fixture['journal_path']))->toBeTrue();
    } finally {
        $filesystem->remove($fixture['root']);
    }
})->with([
    'corrupt trailing record' => fn (array $fixture): null => file_put_contents($fixture['journal_path'], "unexpected\n", FILE_APPEND) ? null : null,
    'permissive mode' => fn (array $fixture): null => chmod($fixture['journal_path'], 0644) ? null : null,
    'hard link' => function (array $fixture): Closure {
        $hardLink = $fixture['root'].'/journal-hard-link';
        link($fixture['journal_path'], $hardLink);

        return static fn (): bool => unlink($hardLink);
    },
    'permissive replacement sidecar' => fn (array $fixture): null => chmod($fixture['state_path'], 0644) ? null : null,
    'hard-linked replacement sidecar' => function (array $fixture): Closure {
        $hardLink = $fixture['root'].'/state-hard-link';
        link($fixture['state_path'], $hardLink);

        return static fn (): bool => unlink($hardLink);
    },
]);

it('retries the journal archive safely across manifest crash windows', function (
    string $crashEnvironment,
    bool $manifestShouldExist,
): void {
    $fixture = committedContainerJournalFixture();
    $filesystem = new Filesystem;

    try {
        file_put_contents($fixture['state_path'], $fixture['replacement']->serialize());
        chmod($fixture['state_path'], 0600);
        $command = $fixture['writer']->archiveCommittedContainerMutationJournalCommandFor(
            proxyPath: $fixture['proxy_path'],
            managedFilename: $fixture['managed_filename'],
            expectedJournalSha256: $fixture['journal_sha256'],
            expectedState: $fixture['expected'],
            replacementState: $fixture['replacement'],
        );
        $crashed = runCommittedContainerJournalCommand($command, [
            $crashEnvironment => '1',
        ]);
        $archivePath = dirname($fixture['journal_path']).'/'.$fixture['writer']
            ->committedContainerMutationJournalArchiveFilename(
                $fixture['managed_filename'],
                $fixture['journal_sha256'],
            );
        expect(is_file($archivePath.'.manifest'))->toBe($manifestShouldExist);
        $retried = runCommittedContainerJournalCommand($command);

        expect($crashed->isSuccessful())->toBeFalse()
            ->and($retried->isSuccessful())->toBeTrue($retried->getErrorOutput())
            ->and(file_exists($fixture['journal_path']))->toBeFalse();
    } finally {
        $filesystem->remove($fixture['root']);
    }
})->with([
    'before manifest rename' => [
        'COOLIFY_DURABLE_REMOTE_ARTIFACT_CRASH_AFTER_CANDIDATE_FSYNC',
        false,
    ],
    'after durable manifest commit' => [
        'COOLIFY_BLUE_GREEN_COMMITTED_CONTAINER_JOURNAL_CRASH_AFTER_MANIFEST',
        true,
    ],
]);

it('serializes concurrent archive attempts through the managed route lock', function (): void {
    $fixture = committedContainerJournalFixture();
    $filesystem = new Filesystem;

    try {
        file_put_contents($fixture['state_path'], $fixture['replacement']->serialize());
        chmod($fixture['state_path'], 0600);
        $command = $fixture['writer']->archiveCommittedContainerMutationJournalCommandFor(
            proxyPath: $fixture['proxy_path'],
            managedFilename: $fixture['managed_filename'],
            expectedJournalSha256: $fixture['journal_sha256'],
            expectedState: $fixture['expected'],
            replacementState: $fixture['replacement'],
        );
        $first = Process::fromShellCommandline($command);
        $second = Process::fromShellCommandline($command);
        $first->setTimeout(10);
        $second->setTimeout(10);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
            ->and(file_exists($fixture['journal_path']))->toBeFalse();
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('rejects an archive CAS scoped to a different operation replacement', function (): void {
    $fixture = committedContainerJournalFixture();
    $filesystem = new Filesystem;

    try {
        file_put_contents($fixture['state_path'], $fixture['replacement']->serialize());
        chmod($fixture['state_path'], 0600);
        $foreignReplacement = $fixture['expected']->withMutationOwner('foreign-operation');
        $archive = runCommittedContainerJournalCommand(
            $fixture['writer']->archiveCommittedContainerMutationJournalCommandFor(
                proxyPath: $fixture['proxy_path'],
                managedFilename: $fixture['managed_filename'],
                expectedJournalSha256: $fixture['journal_sha256'],
                expectedState: $fixture['expected'],
                replacementState: $foreignReplacement,
            ),
        );

        expect($archive->isSuccessful())->toBeFalse()
            ->and(is_file($fixture['journal_path']))->toBeTrue()
            ->and(file_get_contents($fixture['state_path']))->toBe($fixture['replacement']->serialize());
    } finally {
        $filesystem->remove($fixture['root']);
    }
});
