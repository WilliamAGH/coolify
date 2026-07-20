<?php

namespace App\Actions\User;

use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DeleteUserResources
{
    private User $user;

    private bool $isDryRun;

    public function __construct(User $user, bool $isDryRun = false)
    {
        $this->user = $user;
        $this->isDryRun = $isDryRun;
    }

    public function getResourcesPreview(): array
    {
        $applications = collect();
        $databases = collect();
        $services = collect();

        // Get all teams the user belongs to
        $teams = $this->user->teams()->withCount('members')->get();

        foreach ($teams as $team) {
            // Only delete resources from teams that will be FULLY DELETED
            // This means: user is the ONLY member of the team
            //
            // DO NOT delete resources if:
            // - User is just a member (not owner)
            // - Team has other members (ownership will be transferred or user just removed)

            $userRole = $team->pivot->role;
            $memberCount = $team->members_count;

            // Skip if user is not owner
            if ($userRole !== 'owner') {
                continue;
            }

            // Skip if team has other members (will be transferred/user removed, not deleted)
            if ($memberCount > 1) {
                continue;
            }

            // Only delete resources from teams where user is the ONLY member
            // These teams will be fully deleted

            $applications = $applications->merge(
                Application::withTrashed()
                    ->whereHas('environment.project', fn (Builder $query): Builder => $query->where('team_id', $team->id))
                    ->get(),
            );

            // Get all servers for this team
            $servers = $team->servers()->get();

            foreach ($servers as $server) {
                // Get databases (custom method returns Collection)
                $serverDatabases = $server->databases();
                $databases = $databases->merge($serverDatabases);

                // Get services (relationship needs ->get())
                $serverServices = $server->services()->get();
                $services = $services->merge($serverServices);
            }
        }

        return [
            'applications' => $applications->unique('id'),
            'databases' => $databases->unique('id'),
            'services' => $services->unique('id'),
        ];
    }

    /**
     * Refuse a user deletion before it can mutate resources when an application
     * on any team the user would delete has not completed permanent deletion.
     */
    public function assertBlueGreenApplicationsReadyForPermanentDeletion(): void
    {
        foreach ($this->user->teams()->withCount('members')->useWritePdo()->get() as $team) {
            if ($team->pivot->role !== 'owner' || $team->id === 0 || $team->members_count !== 1) {
                continue;
            }

            Application::withTrashed()
                ->whereHas('environment.project', fn (Builder $query): Builder => $query->where('team_id', $team->id))
                ->useWritePdo()
                ->get()
                ->each(function (Application $application): void {
                    $application->assertBlueGreenDeletionAuthorized();
                });
        }
    }

    public function execute(): array
    {
        $this->assertBlueGreenApplicationsReadyForPermanentDeletion();

        if ($this->isDryRun) {
            return [
                'applications' => 0,
                'databases' => 0,
                'services' => 0,
            ];
        }

        $deletedCounts = [
            'applications' => 0,
            'databases' => 0,
            'services' => 0,
        ];

        $resources = $this->getResourcesPreview();

        // Delete applications only after their permanent-deletion fence has
        // already completed; user deletion must never initiate blue-green remote cleanup.
        foreach ($resources['applications'] as $application) {
            try {
                if ($application->forceDelete()) {
                    $deletedCounts['applications']++;
                }
            } catch (\Exception $e) {
                \Log::error("Failed to delete application {$application->id}: ".$e->getMessage());
                throw $e; // Re-throw to trigger rollback
            }
        }

        // Delete databases
        foreach ($resources['databases'] as $database) {
            try {
                $database->forceDelete();
                $deletedCounts['databases']++;
            } catch (\Exception $e) {
                \Log::error("Failed to delete database {$database->id}: ".$e->getMessage());
                throw $e; // Re-throw to trigger rollback
            }
        }

        // Delete services
        foreach ($resources['services'] as $service) {
            try {
                $service->forceDelete();
                $deletedCounts['services']++;
            } catch (\Exception $e) {
                \Log::error("Failed to delete service {$service->id}: ".$e->getMessage());
                throw $e; // Re-throw to trigger rollback
            }
        }

        return $deletedCounts;
    }
}
