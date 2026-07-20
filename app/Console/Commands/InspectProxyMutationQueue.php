<?php

namespace App\Console\Commands;

use App\Support\ProxyMutationQueueDiagnostics;
use Illuminate\Console\Command;
use Throwable;

class InspectProxyMutationQueue extends Command
{
    protected $signature = 'proxy-mutations:inspect
        {--limit=100 : Maximum payloads to inspect from each queue state (1-1000)}';

    protected $description = 'Explicitly inspect and validate redacted proxy-mutation queue payloads';

    public function handle(ProxyMutationQueueDiagnostics $diagnostics): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > ProxyMutationQueueDiagnostics::MAX_LIMIT) {
            $this->error('The --limit option must be an integer between 1 and '.ProxyMutationQueueDiagnostics::MAX_LIMIT.'.');

            return self::INVALID;
        }

        try {
            $result = $diagnostics->inspect($limit);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Proxy-mutation queue diagnostics failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $snapshot = $result['snapshot'];
        $this->line('Freeze owner: '.($snapshot->freezeOperationId ?? '<none>'));
        $this->line("Cardinality: pending={$snapshot->pending} reserved={$snapshot->reserved} delayed={$snapshot->delayed}");

        $rows = array_map(static fn (array $entry): array => [
            $entry['state'],
            $entry['uuid'] ?? '<unavailable>',
            $entry['display_name'] ?? '<redacted>',
            $entry['valid'] ? 'valid' : 'INVALID: '.$entry['error'],
        ], $result['entries']);
        $this->table(['State', 'UUID', 'Display name', 'Status'], $rows);

        foreach (['pending', 'reserved', 'delayed'] as $state) {
            if ($result['inspected'][$state] < $snapshot->{$state}) {
                $this->warn("{$state} output truncated at {$result['inspected'][$state]} of {$snapshot->{$state}} payloads.");
            }
        }

        if (collect($result['entries'])->contains(fn (array $entry): bool => ! $entry['valid'])) {
            $this->error('One or more proxy-mutation payloads failed validation. Raw payload bodies were not printed.');

            return self::FAILURE;
        }

        $this->info('All inspected proxy-mutation payloads are marker-backed and have canonical identities.');

        return self::SUCCESS;
    }
}
