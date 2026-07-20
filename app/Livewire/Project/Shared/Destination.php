<?php

namespace App\Livewire\Project\Shared;

use App\Actions\Application\BlueGreen\BlueGreenTopologyLock;
use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplication;
use App\Actions\Application\StopApplicationOneServer;
use App\Actions\Docker\GetContainersStatus;
use App\Events\ApplicationStatusChanged;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class Destination extends Component
{
    use AuthorizesRequests;

    public $resource;

    public Collection $networks;

    /** @var array<string, array{phase: string|null, activeColor: string|null}> */
    public array $blueGreenDestinationStates = [];

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ApplicationStatusChanged" => 'loadData',
            "echo-private:team.{$teamId},ServiceStatusChanged" => 'mount',
            'refresh' => 'mount',
        ];
    }

    public function mount()
    {
        $this->networks = collect([]);
        $this->loadData();
    }

    public function loadData()
    {
        $this->blueGreenDestinationStates = $this->resource instanceof Application
            ? $this->resource->blueGreenDeployments()
                ->get(['standalone_docker_id', 'phase', 'active_color'])
                ->mapWithKeys(static fn (ApplicationBlueGreenDeployment $deployment): array => [
                    (string) $deployment->standalone_docker_id => [
                        'phase' => $deployment->phase?->value,
                        'activeColor' => $deployment->active_color?->value,
                    ],
                ])
                ->all()
            : [];
        $all_networks = collect([]);
        $all_networks = $all_networks->push($this->resource->destination);
        $all_networks = $all_networks->merge($this->resource->additional_networks);

        $this->networks = Server::isUsable()->get()->map(function ($server) {
            return $server->standaloneDockers;
        })->flatten();
        $this->networks = $this->networks->reject(function ($network) use ($all_networks) {
            return $all_networks->pluck('id')->contains($network->id);
        });
        $this->networks = $this->networks->reject(function ($network) {
            return $this->resource->destination->server->id == $network->server->id;
        });
        if ($this->resource?->additional_servers?->count() > 0) {
            $this->networks = $this->networks->reject(function ($network) {
                return $this->resource->additional_servers->pluck('id')->contains($network->server->id);
            });
        }
    }

    public function stop($serverId)
    {
        try {
            $this->authorize('deploy', $this->resource);
            $server = Server::ownedByCurrentTeam()->findOrFail($serverId);
            StopApplicationOneServer::run($this->resource, $server);
            $this->refreshServers();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function redeploy(int $network_id, int $server_id)
    {
        try {
            $this->authorize('deploy', $this->resource);
            if ($this->resource->additional_servers->count() > 0 && str($this->resource->docker_registry_image_name)->isEmpty()) {
                $this->dispatch('error', 'Failed to deploy.', 'Before deploying to multiple servers, you must first set a Docker image in the General tab.<br>More information here: <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/multiple-servers">documentation</a>');

                return;
            }
            $deployment_uuid = new_public_id();
            $server = Server::ownedByCurrentTeam()->findOrFail($server_id);
            $destination = $server->standaloneDockers->where('id', $network_id)->firstOrFail();
            $result = queue_application_deployment(
                deployment_uuid: $deployment_uuid,
                application: $this->resource,
                server: $server,
                destination: $destination,
                only_this_server: true,
                no_questions_asked: true,
            );
            if ($result['status'] === 'queue_full') {
                $this->dispatch('error', 'Deployment queue full', $result['message']);

                return;
            }
            if ($result['status'] === 'skipped') {
                $this->dispatch('success', 'Deployment skipped', $result['message']);

                return;
            }

            return redirectRoute($this, 'project.application.deployment.show', [
                'project_uuid' => data_get($this->resource, 'environment.project.uuid'),
                'application_uuid' => data_get($this->resource, 'uuid'),
                'deployment_uuid' => $deployment_uuid,
                'environment_uuid' => data_get($this->resource, 'environment.uuid'),
            ]);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function promote(int $network_id, int $server_id)
    {
        try {
            $server = Server::ownedByCurrentTeam()->findOrFail($server_id);
            StandaloneDocker::ownedByCurrentTeam()->where('server_id', $server->id)->findOrFail($network_id);
            $this->authorize('update', $this->resource);

            $this->resource->getConnection()->transaction(function () use ($network_id, $server_id): void {
                BlueGreenTopologyLock::acquire($this->resource->getConnection());
                $this->reloadResourceTopology();
                $server = Server::ownedByCurrentTeam()->findOrFail($server_id);
                $network = StandaloneDocker::ownedByCurrentTeam()
                    ->where('server_id', $server->id)
                    ->findOrFail($network_id);
                if ($this->isMainDestination($network_id, $server_id)) {
                    return;
                }
                if (! $this->resource->additional_networks()
                    ->whereKey($network_id)
                    ->wherePivot('server_id', $server_id)
                    ->exists()) {
                    throw new \RuntimeException('The destination is no longer attached to this resource and cannot be promoted.');
                }

                $mainDestination = $this->resource->destination;
                if ($this->resource instanceof Application) {
                    $this->resource->prepareBlueGreenAdditionalDestinationAddition($network);
                }
                $this->resource->additional_networks()
                    ->wherePivot('server_id', $server->id)
                    ->detach($network->id);
                $this->resource->update([
                    'destination_id' => $network->id,
                    'destination_type' => StandaloneDocker::class,
                ]);
                $this->resource->additional_networks()
                    ->attach($mainDestination->id, [
                        'server_id' => $mainDestination->server->id,
                    ]);
            });
            $this->resource->refresh();
            $this->refreshServers();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function refreshServers()
    {
        GetContainersStatus::run($this->resource->destination->server);
        $this->loadData();
        $this->dispatch('refresh');
    }

    public function addServer(int $network_id, int $server_id)
    {
        try {
            $server = Server::ownedByCurrentTeam()->findOrFail($server_id);
            StandaloneDocker::ownedByCurrentTeam()->where('server_id', $server->id)->findOrFail($network_id);
            $this->authorize('update', $this->resource);

            $this->resource->getConnection()->transaction(function () use ($network_id, $server_id): void {
                BlueGreenTopologyLock::acquire($this->resource->getConnection());
                $this->reloadResourceTopology();
                $server = Server::ownedByCurrentTeam()->findOrFail($server_id);
                $network = StandaloneDocker::ownedByCurrentTeam()
                    ->where('server_id', $server->id)
                    ->findOrFail($network_id);
                if ($this->resource instanceof Application) {
                    $this->resource->assertAdditionalStandaloneDockerDestinationCanBeAttached($network);
                    $this->resource->prepareBlueGreenAdditionalDestinationAddition($network);
                }
                $this->resource->additional_networks()->attach($network->id, ['server_id' => $server->id]);
            });
            $this->dispatch('refresh');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function removeServer(int $network_id, int $server_id, $password, $selectedActions = [])
    {
        try {
            $this->authorize('update', $this->resource);
            if (! verifyPasswordConfirmation($password, $this)) {
                return 'The provided password is incorrect.';
            }

            if ($this->isMainDestination($network_id, $server_id)) {
                $this->dispatch('error', 'You are trying to remove the main server.');

                return;
            }
            $server = Server::ownedByCurrentTeam()->findOrFail($server_id);
            StandaloneDocker::ownedByCurrentTeam()->where('server_id', $server->id)->findOrFail($network_id);
            $removalProof = null;
            if ($this->resource instanceof Application && $this->resource->requiresBlueGreenDeactivation()) {
                $preparations = DeactivateBlueGreenApplication::make()->removeDestination(
                    $this->resource,
                    $network_id,
                    $server_id,
                );
                $deactivation = $preparations->sole()->deactivation->fresh();
                $removalProof = [
                    'id' => (int) $deactivation->id,
                    'operation_id' => (string) $deactivation->operation_id,
                    'supersession_generation' => (int) $deactivation->supersession_generation,
                ];
            }
            [$shouldStopServer, $server] = $this->resource->getConnection()->transaction(function () use ($network_id, $server_id, $removalProof): array {
                BlueGreenTopologyLock::acquire($this->resource->getConnection());
                $this->reloadResourceTopology();
                $server = Server::ownedByCurrentTeam()->findOrFail($server_id);
                StandaloneDocker::ownedByCurrentTeam()
                    ->where('server_id', $server->id)
                    ->findOrFail($network_id);
                if ($this->isMainDestination($network_id, $server_id)) {
                    return [null, $server];
                }
                if ($this->resource instanceof Application) {
                    if ($removalProof !== null) {
                        $this->resource->consumeBlueGreenDestinationRemovalProof(
                            $network_id,
                            $server_id,
                            $removalProof['id'],
                            $removalProof['operation_id'],
                            $removalProof['supersession_generation'],
                        );
                    } else {
                        $this->resource->assertBlueGreenDestinationCanBeRemoved($network_id);
                    }
                }
                $detachedDestinations = $this->resource->additional_networks()
                    ->wherePivot('server_id', $server_id)
                    ->detach($network_id);

                return [$detachedDestinations > 0 && $removalProof === null, $server];
            });
            if ($shouldStopServer === null) {
                $this->dispatch('error', 'You are trying to remove the main server.');

                return;
            }
            if ($shouldStopServer) {
                StopApplicationOneServer::run($this->resource, $server);
            }
            $this->loadData();
            $this->dispatch('refresh');
            ApplicationStatusChanged::dispatch(data_get($this->resource, 'environment.project.team.id'));

            return true;
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    private function reloadResourceTopology(): void
    {
        $this->resource->refresh();
        $this->resource->load('destination.server');
    }

    private function isMainDestination(int $networkId, int $serverId): bool
    {
        return (int) $this->resource->destination->id === $networkId
            && (int) $this->resource->destination->server->id === $serverId;
    }
}
