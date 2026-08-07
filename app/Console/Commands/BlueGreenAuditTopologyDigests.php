<?php

namespace App\Console\Commands;

use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Models\ApplicationBlueGreenDeployment;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Read-only rollout audit for the DB-only blue-green routing topology fence. */
class BlueGreenAuditTopologyDigests extends Command
{
    private const int ITERATION_CHUNK_SIZE = 100;

    private const int FINDING_OUTPUT_LIMIT = 100;

    /** Distinct from FAILURE so an unconvergeable owner is not read as remediable drift. */
    public const int UNAUDITABLE_EXIT_CODE = 2;

    protected $signature = 'blue-green:audit-topology-digests
        {--application-id= : Restrict the audit to one application id}';

    protected $description = 'Audit blue-green destination routing topology digests (read-only)';

    public function handle(): int
    {
        $query = ApplicationBlueGreenDeployment::query()
            ->orderBy('id');
        $applicationId = $this->option('application-id');
        if ($applicationId !== null) {
            $applicationId = $this->positiveIntegerOption($applicationId);
            if ($applicationId === null) {
                $this->error('--application-id must be a canonical positive integer.');

                return self::INVALID;
            }
            $query->where('application_id', $applicationId);
        }

        $fingerprint = new ComputeBlueGreenDeploymentFingerprint;
        $counts = ['current' => 0, 'claim_establishes' => 0, 'missing' => 0, 'drifted' => 0, 'unauditable' => 0];
        $findings = [];
        $omittedFindings = 0;

        foreach ($query
            ->with([
                'application' => static function (BelongsTo $relation): void {
                    $relation->withTrashed()->with('settings');
                },
                'standaloneDocker.server',
            ])
            ->lazyById(self::ITERATION_CHUNK_SIZE) as $state) {
            $application = $state->application;
            $destination = $state->standaloneDocker;
            if ($application === null || $application->trashed() || $destination === null || $destination->server === null) {
                $counts['unauditable']++;
                $this->recordFinding(
                    $findings,
                    [$state->id, $state->application_id, $state->standalone_docker_id, 'unauditable', 'application or destination row missing'],
                    $omittedFindings,
                );

                continue;
            }
            $storedDigest = $state->destination_routing_topology_digest;
            if ($storedDigest === null) {
                if (ClaimBlueGreenDeployment::stateDefersRoutingTopologyDigestToClaim(
                    $application,
                    $destination,
                    $state,
                )) {
                    $counts['claim_establishes']++;
                    $this->recordFinding(
                        $findings,
                        [
                            $state->id,
                            $state->application_id,
                            $state->standalone_docker_id,
                            'claim-establishes',
                            'clean absent route; the next exact claim establishes the digest before activation',
                        ],
                        $omittedFindings,
                    );

                    continue;
                }
                $counts['missing']++;
                $this->recordFinding(
                    $findings,
                    [$state->id, $state->application_id, $state->standalone_docker_id, 'missing', 'requires live-attested rehydration'],
                    $omittedFindings,
                );

                continue;
            }
            $currentDigest = $fingerprint->routingTopologyDigestFor($application, $destination);
            if (! is_string($storedDigest) || ! hash_equals($storedDigest, $currentDigest)) {
                $counts['drifted']++;
                $this->recordFinding(
                    $findings,
                    [
                        $state->id,
                        $state->application_id,
                        $state->standalone_docker_id,
                        'drifted',
                        'stored routing topology no longer matches the current destination',
                    ],
                    $omittedFindings,
                );

                continue;
            }
            $counts['current']++;
        }

        $this->line(sprintf(
            'Audited %d durable states: current=%d claim_establishes=%d missing=%d drifted=%d unauditable=%d',
            array_sum($counts),
            $counts['current'],
            $counts['claim_establishes'],
            $counts['missing'],
            $counts['drifted'],
            $counts['unauditable'],
        ));
        if ($findings !== []) {
            $this->table(['State id', 'Application', 'Destination', 'Formula', 'Detail'], $findings);
        }
        if ($omittedFindings > 0) {
            $this->warn(sprintf(
                'Only the first %d findings are shown; %d additional %s %s omitted.',
                self::FINDING_OUTPUT_LIMIT,
                $omittedFindings,
                $omittedFindings === 1 ? 'finding' : 'findings',
                $omittedFindings === 1 ? 'was' : 'were',
            ));
        }
        if ($counts['missing'] > 0 || $counts['drifted'] > 0) {
            $this->error('Every durable destination must have one current routing topology digest before activation.');

            return self::FAILURE;
        }
        if ($counts['unauditable'] > 0) {
            /**
             * A trashed or orphaned owner is a real alarm, but no command can converge it:
             * blue-green:rehydrate-routing-topology also refuses these rows. Exit distinctly
             * so an operator can tell an unconvergeable row from remediable digest drift
             * instead of conflating both into one permanently red gate.
             */
            $this->error('Some durable destinations have no auditable application or destination owner; they cannot be converged by blue-green:rehydrate-routing-topology.');

            return self::UNAUDITABLE_EXIT_CODE;
        }

        return self::SUCCESS;
    }

    private function recordFinding(array &$findings, array $finding, int &$omittedFindings): void
    {
        if (count($findings) < self::FINDING_OUTPUT_LIMIT) {
            $findings[] = $finding;

            return;
        }

        $omittedFindings++;
    }

    private function positiveIntegerOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($integer) ? $integer : null;
    }
}
