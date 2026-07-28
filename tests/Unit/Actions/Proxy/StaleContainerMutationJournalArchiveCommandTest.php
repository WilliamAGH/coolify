<?php

use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;

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
        'journal_boot_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
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
