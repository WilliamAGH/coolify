<?php

namespace App\Livewire\Project\Application;

use App\Actions\Application\BlueGreen\BlueGreenDeactivationFailure;
use App\Actions\Application\StopApplication;
use App\Actions\Docker\GetContainersStatus;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Heading extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public ?string $lastDeploymentInfo = null;

    public ?string $lastDeploymentLink = null;

    /** @var array<string, int|string|null>|null */
    public ?array $blueGreenInactiveRetirement = null;

    /** @var array{phase: string, sourcePhase: string, reason: string, destinationId: int}|null */
    public ?array $blueGreenIntervention = null;

    public array $parameters;

    protected string $deploymentUuid;

    public bool $docker_cleanup = true;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceStatusChanged" => 'checkStatus',
            "echo-private:team.{$teamId},ServiceChecked" => '$refresh',
            'compose_loaded' => '$refresh',
            'update_links' => '$refresh',
        ];
    }

    public function mount()
    {
        $this->authorize('view', $this->application);

        $this->parameters = [
            'project_uuid' => $this->application->project()->uuid,
            'environment_uuid' => $this->application->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ];
        $lastDeployment = $this->application->get_last_successful_deployment();
        $this->lastDeploymentInfo = data_get_str($lastDeployment, 'commit')->limit(7).' '.data_get($lastDeployment, 'commit_message');
        $this->lastDeploymentLink = $this->application->gitCommitLink(data_get($lastDeployment, 'commit'));
        $this->refreshBlueGreenIntervention();
        $this->refreshBlueGreenInactiveRetirement();
    }

    public function checkStatus()
    {
        $this->refreshBlueGreenIntervention();
        $this->refreshBlueGreenInactiveRetirement();
        if ($this->application->destination->server->isFunctional()) {
            GetContainersStatus::dispatch($this->application->destination->server);
        } else {
            $this->dispatch('error', 'Server is not functional.');
        }
    }

    private function refreshBlueGreenIntervention(): void
    {
        $deactivation = ApplicationBlueGreenDeactivation::query()
            ->where('application_id', $this->application->id)
            ->where('phase', 'intervention_required')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
        $deployment = ApplicationBlueGreenDeployment::query()
            ->where('application_id', $this->application->id)
            ->where('phase', 'intervention_required')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
        $owner = $deactivation;
        $ownerPhase = 'deactivation';
        if ($deployment !== null && ($deactivation === null || $deployment->updated_at->gt($deactivation->updated_at))) {
            $owner = $deployment;
            $ownerPhase = 'deployment';
        }
        if ($owner === null) {
            $this->blueGreenIntervention = null;

            return;
        }

        $this->blueGreenIntervention = [
            'phase' => $ownerPhase,
            'sourcePhase' => $owner->intervention_phase ?? 'unknown',
            'reason' => BlueGreenDeactivationFailure::publicReason(
                $owner->intervention_reason
                    ?? 'A blue-green safety check requires an operator recovery decision.',
            ),
            'destinationId' => (int) $owner->standalone_docker_id,
        ];
    }

    private function refreshBlueGreenInactiveRetirement(): void
    {
        $state = ApplicationBlueGreenDeployment::query()
            ->where('application_id', $this->application->id)
            ->whereNotNull('inactive_retirement_owner_deployment_uuid')
            ->first();
        if ($state === null) {
            $this->blueGreenInactiveRetirement = null;

            return;
        }

        $status = match (true) {
            $state->inactive_retirement_intervention_required_at !== null => 'intervention_required',
            $state->inactive_retirement_stopped_at !== null => 'stopped',
            $state->inactive_retirement_observed_at !== null => 'draining',
            default => 'retained',
        };
        $this->blueGreenInactiveRetirement = [
            'color' => $state->inactive_retirement_color?->value,
            'containerId' => $state->inactive_retirement_container_id,
            'notBeforeAt' => $state->inactive_retirement_not_before_at?->toIso8601String(),
            'activeConnections' => $state->inactive_retirement_last_observed_connections,
            'status' => $status,
        ];
    }

    public function manualCheckStatus()
    {
        $this->checkStatus();
    }

    public function force_deploy_without_cache()
    {
        try {
            $this->authorize('deploy', $this->application);

            $this->deploy(force_rebuild: true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function deploy(bool $force_rebuild = false)
    {
        try {
            $this->authorize('deploy', $this->application);

            if ($this->application->build_pack === 'dockercompose' && is_null($this->application->docker_compose_raw)) {
                $this->dispatch('error', 'Failed to deploy', 'Please load a Compose file first.');

                return;
            }
            if ($this->application->destination->server->isSwarm() && str($this->application->docker_registry_image_name)->isEmpty()) {
                $this->dispatch('error', 'Failed to deploy.', 'To deploy to a Swarm cluster you must set a Docker image name first.');

                return;
            }
            if (data_get($this->application, 'settings.is_build_server_enabled') && str($this->application->docker_registry_image_name)->isEmpty()) {
                $this->dispatch('error', 'Failed to deploy.', 'To use a build server, you must first set a Docker image.<br>More information here: <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/build-server">documentation</a>');

                return;
            }
            if ($this->application->additional_servers->count() > 0 && str($this->application->docker_registry_image_name)->isEmpty()) {
                $this->dispatch('error', 'Failed to deploy.', 'Before deploying to multiple servers, you must first set a Docker image in the General tab.<br>More information here: <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/multiple-servers">documentation</a>');

                return;
            }
            $this->setDeploymentUuid();
            $result = queue_application_deployment(
                application: $this->application,
                deployment_uuid: $this->deploymentUuid,
                force_rebuild: $force_rebuild,
            );
            if ($result['status'] === 'queue_full') {
                $this->dispatch('error', 'Deployment queue full', $result['message']);

                return;
            }
            if ($result['status'] === 'skipped') {
                $this->dispatch('error', 'Deployment skipped', $result['message']);

                return;
            }

            return $this->redirectRoute('project.application.deployment.show', [
                'project_uuid' => $this->parameters['project_uuid'],
                'application_uuid' => $this->parameters['application_uuid'],
                'deployment_uuid' => $this->deploymentUuid,
                'environment_uuid' => $this->parameters['environment_uuid'],
            ], navigate: false);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    protected function setDeploymentUuid()
    {
        $this->deploymentUuid = new_public_id();
        $this->parameters['deployment_uuid'] = $this->deploymentUuid;
    }

    public function stop()
    {
        try {
            $this->authorize('deploy', $this->application);

            $this->dispatch('info', 'Gracefully stopping application.<br/>It could take a while depending on the application.');
            StopApplication::dispatch($this->application, false, $this->docker_cleanup);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function restart()
    {
        try {
            $this->authorize('deploy', $this->application);

            if ($this->application->additional_servers->count() > 0 && str($this->application->docker_registry_image_name)->isEmpty()) {
                $this->dispatch('error', 'Failed to deploy', 'Before deploying to multiple servers, you must first set a Docker image in the General tab.<br>More information here: <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/multiple-servers">documentation</a>');

                return;
            }

            $this->setDeploymentUuid();
            $result = queue_application_deployment(
                application: $this->application,
                deployment_uuid: $this->deploymentUuid,
                restart_only: true,
            );
            if ($result['status'] === 'queue_full') {
                $this->dispatch('error', 'Deployment queue full', $result['message']);

                return;
            }
            if ($result['status'] === 'skipped') {
                $this->dispatch('success', 'Deployment skipped', $result['message']);

                return;
            }

            return $this->redirectRoute('project.application.deployment.show', [
                'project_uuid' => $this->parameters['project_uuid'],
                'application_uuid' => $this->parameters['application_uuid'],
                'deployment_uuid' => $this->deploymentUuid,
                'environment_uuid' => $this->parameters['environment_uuid'],
            ], navigate: false);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.heading', [
            'checkboxes' => [
                ['id' => 'docker_cleanup', 'label' => __('resource.docker_cleanup')],
            ],
        ]);
    }
}
