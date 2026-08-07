<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Models\Application;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

final class ExecuteBlueGreenDestinationMutation
{
    use AsAction;

    public function __construct(private ?WriteBlueGreenProxyConfiguration $writer = null) {}

    /**
     * @param  non-empty-list<string>  $commands
     * @param  non-empty-list<string>  $completionCommands
     */
    public function handle(
        Server $server,
        ?BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        array $commands,
        array $completionCommands,
        string $expectedServerBootId,
    ): void {
        instant_privileged_remote_script(
            $this->commandFor(
                $server->proxyPath(),
                $expectedState,
                $replacementState,
                $commands,
                $completionCommands,
                $expectedServerBootId,
            ),
            $server,
        );
    }

    public function replacementStateFor(
        Application $application,
        BlueGreenDeploymentClaim $claim,
        ?BlueGreenProxyState $expectedState,
    ): BlueGreenProxyState {
        $managedFilename = BlueGreenRoutingTarget::managedFilename(
            (string) $application->uuid,
            $claim->standaloneDockerId,
        );
        if ($expectedState === null) {
            return new BlueGreenProxyState(
                managedFilename: $managedFilename,
                applicationUuid: (string) $application->uuid,
                destinationId: $claim->standaloneDockerId,
                operationId: $claim->deploymentUuid,
                mutationSequence: 1,
                destinationFenceEpoch: 0,
                routingRevision: $claim->expectedRoutingRevision - 1,
                managedSha256: null,
                activeColor: null,
                activeDeploymentUuid: null,
                activeContainerName: null,
                activeContainerId: null,
                applicationRoutingConfigDigest: $claim->routingConfigDigest,
                destinationTopologyDigest: $claim->operationTopologyDigest,
            );
        }
        if ($expectedState->managedFilename !== $managedFilename
            || $expectedState->applicationUuid !== (string) $application->uuid
            || $expectedState->destinationId !== $claim->standaloneDockerId) {
            throw new BlueGreenDeploymentTransitionException('The current destination state does not match the claimed application topology.');
        }
        $isRefreshableAbsentRoute = $expectedState->managedSha256 === null
            && $claim->previousActiveColor === null;
        if ($isRefreshableAbsentRoute) {
            return $expectedState->withAbsentRouteMutationOwner(
                $claim->deploymentUuid,
                $claim->routingConfigDigest,
                $claim->operationTopologyDigest,
            );
        }

        return $expectedState->withMutationOwner($claim->deploymentUuid);
    }

    /**
     * @param  non-empty-list<string>  $commands
     * @param  non-empty-list<string>  $completionCommands
     */
    public function executeForClaim(
        Application $application,
        Server $server,
        BlueGreenDeploymentClaim $claim,
        ?BlueGreenProxyState $expectedState,
        array $commands,
        array $completionCommands,
    ): BlueGreenProxyState {
        $replacementState = $this->replacementStateFor($application, $claim, $expectedState);
        $this->handle(
            $server,
            $expectedState,
            $replacementState,
            $commands,
            $completionCommands,
            $claim->serverBootId,
        );
        try {
            RecordBlueGreenDestinationState::run($claim, $expectedState, $replacementState);
        } catch (\Throwable $exception) {
            throw new BlueGreenDestinationStateRecordingException(
                $expectedState,
                $replacementState,
                $exception,
            );
        }

        return $replacementState;
    }

    /**
     * @param  non-empty-list<string>  $commands
     * @param  non-empty-list<string>  $completionCommands
     */
    public function commandFor(
        string $proxyPath,
        ?BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        array $commands,
        array $completionCommands,
        string $expectedServerBootId,
    ): string {
        return ($this->writer ?? new WriteBlueGreenProxyConfiguration)->fencedDestinationCommandFor(
            $proxyPath,
            $replacementState->managedFilename,
            $expectedState,
            $replacementState,
            $expectedServerBootId,
            $commands,
            $completionCommands,
        );
    }
}
