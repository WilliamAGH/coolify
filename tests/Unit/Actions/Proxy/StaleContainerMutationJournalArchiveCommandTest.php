<?php

use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function staleContainerMutationJournalTestWriter(): WriteBlueGreenProxyConfiguration
{
    return new class extends WriteBlueGreenProxyConfiguration
    {
        protected function bootIdentityAssertionCommand(string $expectedBootIdShellValue): string
        {
            return 'true';
        }
    };
}

function staleContainerMutationJournalTestCommand(string $command): string
{
    $command = preg_replace(
        '/^case "\\$container_journal_expected_boot_id" in .*$/m',
        'true',
        $command,
        1,
    ) ?? $command;

    return str_replace(
        [
            'test "$(id -u)" = 0',
            'test "$(durable_remote_owner_uid "$container_journal_source")" = 0',
            'container_journal_line_count=$(wc -l < "$container_journal_source")',
        ],
        [
            'true',
            'test "$(durable_remote_owner_uid "$container_journal_source")" = "$(id -u)"',
            'container_journal_line_count=$(wc -l < "$container_journal_source" | tr -d \'[:space:]\')',
        ],
        $command,
    );
}

function writeStaleContainerMutationJournalTestFixture(
    WriteBlueGreenProxyConfiguration $writer,
    string $proxyPath,
    BlueGreenProxyState $replacementState,
    string $journalBootId,
): void {
    $filesystem = new Filesystem;
    $journalPath = $writer->containerMutationJournalPath($proxyPath, $replacementState->managedFilename);
    $filesystem->mkdir(dirname($journalPath), 0700);
    $mutation = "set -eu\ntrue\n";
    $completion = "set -eu\ntrue\n";
    file_put_contents($journalPath, implode("\n", [
        'coolify-blue-green-container-mutation-v1',
        $replacementState->managedFilename,
        $journalBootId,
        BlueGreenProxyRollbackArtifact::encodedState(null),
        hash('sha256', ''),
        BlueGreenProxyRollbackArtifact::encodedState($replacementState),
        hash('sha256', $replacementState->serialize()),
        'missing',
        hash('sha256', ''),
        hash('sha256', $mutation),
        hash('sha256', $completion),
        base64_encode($mutation),
        base64_encode($completion),
        '',
    ]));
    chmod($journalPath, 0600);
}

it('builds a root-only journal quarantine command that cannot replay the stale scripts', function (): void {
    $writer = new WriteBlueGreenProxyConfiguration;
    $managedFilename = BlueGreenRoutingTarget::managedFilename('stale_journal_application', 62);
    $operationId = 'failed-first-adoption-operation';
    $journalBootId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $routingConfigDigest = str_repeat('b', 64);
    $topologyDigest = str_repeat('c', 64);
    $provenanceSha256 = $writer->staleContainerMutationJournalProvenanceSha256For(
        $managedFilename,
        'stale_journal_application',
        62,
        $operationId,
        $journalBootId,
        $routingConfigDigest,
        $topologyDigest,
    );
    $command = $writer->quarantineStaleContainerMutationJournalCommandFor(
        '/data/coolify/proxy',
        $managedFilename,
        'stale_journal_application',
        62,
        62,
        '11111111-2222-3333-4444-555555555555',
        str_repeat('a', 64),
        $operationId,
        $journalBootId,
        $routingConfigDigest,
        $topologyDigest,
    );

    expect($command)->toContain('test "$(id -u)" = 0')
        ->and($command)->toContain("flock -x -w '15' 9")
        ->and($command)->not->toContain('flock -x 9')
        ->and($command)->toContain('test "$(durable_remote_owner_uid "$container_journal_source")" = 0')
        ->and($command)->toContain('test "$(durable_remote_permissions "$container_journal_source")" = 600')
        ->and($command)->toContain('test "$container_journal_expected_boot_id" !=')
        ->and($command)->toContain('test "$container_journal_expected_boot_id" = '.escapeshellarg($journalBootId))
        ->and($command)->toContain('test "$container_journal_replacement_state_checksum" = ')
        ->and($command)->toContain(escapeshellarg($provenanceSha256))
        ->and($command)->toContain('test "$container_journal_expected_state" = absent')
        ->and($command)->toContain('test "$container_journal_managed_file_state" = missing')
        ->and($command)->toContain('durable_remote_replace "$container_journal_path" "$container_journal_archive_path"')
        ->and($command)->toContain('coolify-blue-green-stale-container-journal-archive-v1')
        ->and($command)->not->toContain('sh "$container_journal_mutation_decoded"')
        ->and($command)->not->toContain('sh "$container_journal_completion_decoded"')
        ->and($command)->not->toContain('durable_remote_remove "$container_journal_path"');
});

it('changes the authenticated stale-journal provenance for every failed-attempt identity field', function (
    string $field,
    string $replacement,
): void {
    $writer = new WriteBlueGreenProxyConfiguration;
    $managedFilename = BlueGreenRoutingTarget::managedFilename('stale_journal_application', 62);
    $baseline = [
        'operation_id' => 'failed-first-adoption-operation',
        'journal_boot_id' => '22222222-3333-4444-5555-666666666666',
        'routing_config_digest' => str_repeat('b', 64),
        'topology_digest' => str_repeat('c', 64),
    ];
    $changed = [...$baseline, $field => $replacement];
    $provenance = static fn (array $values): string => $writer->staleContainerMutationJournalProvenanceSha256For(
        $managedFilename,
        'stale_journal_application',
        62,
        $values['operation_id'],
        $values['journal_boot_id'],
        $values['routing_config_digest'],
        $values['topology_digest'],
    );

    expect($provenance($changed))->not->toBe($provenance($baseline));
})->with([
    'operation ID' => ['operation_id', 'another-failed-operation'],
    'journal boot ID' => ['journal_boot_id', 'ffffffff-1111-2222-3333-444444444444'],
    'routing digest' => ['routing_config_digest', str_repeat('d', 64)],
    'topology digest' => ['topology_digest', str_repeat('e', 64)],
]);

it('executes only when the embedded journal belongs to the exact failed attempt', function (
    string $field,
    string $replacement,
): void {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-stale-journal-provenance-'.bin2hex(random_bytes(8));
    $writer = staleContainerMutationJournalTestWriter();
    $managedFilename = BlueGreenRoutingTarget::managedFilename('stale_journal_application', 62);
    $baseline = [
        'operation_id' => 'failed-first-adoption-operation',
        'journal_boot_id' => '22222222-3333-4444-5555-666666666666',
        'routing_config_digest' => str_repeat('b', 64),
        'topology_digest' => str_repeat('c', 64),
    ];
    $changed = [...$baseline, $field => $replacement];
    $replacementState = new BlueGreenProxyState(
        managedFilename: $managedFilename,
        applicationUuid: 'stale_journal_application',
        destinationId: 62,
        operationId: $baseline['operation_id'],
        mutationSequence: 1,
        destinationFenceEpoch: 0,
        routingRevision: 0,
        managedSha256: null,
        activeColor: null,
        activeDeploymentUuid: null,
        activeContainerName: null,
        activeContainerId: null,
        applicationRoutingConfigDigest: $baseline['routing_config_digest'],
        destinationTopologyDigest: $baseline['topology_digest'],
    );

    try {
        writeStaleContainerMutationJournalTestFixture(
            $writer,
            $proxyPath,
            $replacementState,
            $baseline['journal_boot_id'],
        );
        $command = static fn (array $values): string => staleContainerMutationJournalTestCommand(
            $writer->inspectStaleContainerMutationJournalCommandFor(
                $proxyPath,
                $managedFilename,
                'stale_journal_application',
                62,
                62,
                '11111111-2222-3333-4444-555555555555',
                $values['operation_id'],
                $values['journal_boot_id'],
                $values['routing_config_digest'],
                $values['topology_digest'],
            ),
        );
        $exact = Process::fromShellCommandline($command($baseline));
        $exact->mustRun();
        $mismatch = Process::fromShellCommandline($command($changed));
        $mismatch->run();

        expect($exact->getOutput())->toEndWith(
            '|'.$writer->staleContainerMutationJournalProvenanceSha256For(
                $managedFilename,
                'stale_journal_application',
                62,
                $baseline['operation_id'],
                $baseline['journal_boot_id'],
                $baseline['routing_config_digest'],
                $baseline['topology_digest'],
            ),
        )->and($mismatch->isSuccessful())->toBeFalse();
    } finally {
        $filesystem->remove($proxyPath);
    }
})->with([
    'operation ID mismatch' => ['operation_id', 'another-failed-operation'],
    'journal boot ID mismatch' => ['journal_boot_id', '77777777-8888-9999-1111-222222222222'],
    'routing digest mismatch' => ['routing_config_digest', str_repeat('d', 64)],
    'topology digest mismatch' => ['topology_digest', str_repeat('e', 64)],
]);

it('rejects partially specified failed-attempt journal provenance', function (): void {
    $writer = new WriteBlueGreenProxyConfiguration;

    expect(fn (): string => $writer->inspectStaleContainerMutationJournalCommandFor(
        '/data/coolify/proxy',
        BlueGreenRoutingTarget::managedFilename('stale_journal_application', 62),
        'stale_journal_application',
        62,
        62,
        '11111111-2222-3333-4444-555555555555',
        'failed-first-adoption-operation',
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects stale journal command scopes outside the exact managed routing namespace', function (): void {
    $writer = new WriteBlueGreenProxyConfiguration;

    expect(fn (): string => $writer->inspectStaleContainerMutationJournalCommandFor(
        '/data/coolify/proxy',
        'foreign-route.yaml',
        'stale_journal_application',
        62,
        62,
        '11111111-2222-3333-4444-555555555555',
    ))->toThrow(InvalidArgumentException::class);
});
