<?php

namespace App\Livewire\Server;

use App\Actions\Server\QueueServerDeletion;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Delete extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public bool $delete_from_hetzner = false;

    public bool $force_delete_resources = false;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete($password, $selectedActions = [])
    {
        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        if (! empty($selectedActions)) {
            $this->delete_from_hetzner = in_array('delete_from_hetzner', $selectedActions);
            $this->force_delete_resources = in_array('force_delete_resources', $selectedActions);
        }
        try {
            $this->authorize('delete', $this->server);
            if ($this->server->hasDefinedResources() && ! $this->force_delete_resources) {
                $this->dispatch('error', 'Server has defined resources. Please delete them first or select "Delete all resources".');

                return;
            }

            QueueServerDeletion::run(
                $this->server,
                $this->force_delete_resources,
                $this->delete_from_hetzner,
            );

            return redirectRoute($this, 'server.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        $checkboxes = [];

        if ($this->server->hasDefinedResources()) {
            $resourceCount = $this->server->definedResources()->count();
            $checkboxes[] = [
                'id' => 'force_delete_resources',
                'label' => "Delete all resources ({$resourceCount} total)",
                'default_warning' => 'Server cannot be deleted while it has resources.',
            ];
        }

        if ($this->server->hetzner_server_id) {
            $checkboxes[] = [
                'id' => 'delete_from_hetzner',
                'label' => 'Also delete server from Hetzner Cloud',
                'default_warning' => 'The actual server on Hetzner Cloud will NOT be deleted.',
            ];
        }

        return view('livewire.server.delete', [
            'checkboxes' => $checkboxes,
        ]);
    }
}
