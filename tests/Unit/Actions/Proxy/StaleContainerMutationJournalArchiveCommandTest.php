<?php

use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;

it('builds a root-only journal quarantine command that cannot replay the stale scripts', function (): void {
    $writer = new WriteBlueGreenProxyConfiguration;
    $managedFilename = BlueGreenRoutingTarget::managedFilename('stale_journal_application', 62);
    $command = $writer->quarantineStaleContainerMutationJournalCommandFor(
        '/data/coolify/proxy',
        $managedFilename,
        'stale_journal_application',
        62,
        62,
        '11111111-2222-3333-4444-555555555555',
        str_repeat('a', 64),
    );

    expect($command)->toContain('test "$(id -u)" = 0')
        ->and($command)->toContain("flock -x -w '15' 9")
        ->and($command)->not->toContain('flock -x 9')
        ->and($command)->toContain('test "$(durable_remote_owner_uid "$container_journal_source")" = 0')
        ->and($command)->toContain('test "$(durable_remote_permissions "$container_journal_source")" = 600')
        ->and($command)->toContain('test "$container_journal_expected_boot_id" !=')
        ->and($command)->toContain('test "$container_journal_expected_state" = absent')
        ->and($command)->toContain('test "$container_journal_managed_file_state" = missing')
        ->and($command)->toContain('durable_remote_replace "$container_journal_path" "$container_journal_archive_path"')
        ->and($command)->toContain('coolify-blue-green-stale-container-journal-archive-v1')
        ->and($command)->not->toContain('sh "$container_journal_mutation_decoded"')
        ->and($command)->not->toContain('sh "$container_journal_completion_decoded"')
        ->and($command)->not->toContain('durable_remote_remove "$container_journal_path"');
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
