<?php

namespace App\Console\Commands;

use App\Actions\Stripe\CancelSubscription;
use App\Actions\User\DeleteUserResources;
use App\Actions\User\DeleteUserServers;
use App\Actions\User\DeleteUserTeams;
use App\Models\Application;
use App\Models\Service;
use App\Models\User;
use Illuminate\Cache\Lock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminDeleteUser extends Command
{
    private const LOCK_TTL_SECONDS = 86400;

    protected $signature = 'admin:delete-user {email}
                            {--dry-run : Preview what will be deleted without actually deleting}
                            {--skip-stripe : Skip Stripe subscription cancellation}
                            {--skip-resources : Skip resource deletion}
                            {--auto-confirm : Skip all confirmation prompts between phases}
                            {--force : Continue past non-lock safety warnings; active deletion locks are never bypassed}';

    protected $description = 'Delete a user with comprehensive resource cleanup and phase-by-phase confirmation (works on cloud and self-hosted)';

    private bool $isDryRun = false;

    private bool $skipStripe = false;

    private bool $skipResources = false;

    private User $user;

    private ?Lock $lock = null;

    private bool $lockAcquired = false;

    private bool $databaseTransactionStarted = false;

    private array $deletionState = [
        'phase_1_overview' => false,
        'phase_2_resources' => false,
        'phase_3_servers' => false,
        'phase_4_teams' => false,
        'phase_4_committed' => false,
        'phase_5_user_profile' => false,
        'phase_5_committed' => false,
        'phase_6_stripe' => false,
        'db_committed' => false,
    ];

    public function handle()
    {
        // Register signal handlers for graceful shutdown (Ctrl+C handling)
        $this->registerSignalHandlers();

        $email = Str::lower((string) $this->argument('email'));
        $this->isDryRun = $this->option('dry-run');
        $this->skipStripe = $this->option('skip-stripe');
        $this->skipResources = $this->option('skip-resources');
        $force = $this->option('force');

        if ($force) {
            $this->warn('⚠️  FORCE MODE - active deletion lock ownership is still required');
            $this->newLine();
        }

        if ($this->isDryRun) {
            $this->info('🔍 DRY RUN MODE - No data will be deleted');
            $this->newLine();
        }

        if ($this->output->isVerbose()) {
            $this->info('📊 VERBOSE MODE - Full stack traces will be shown on errors');
            $this->newLine();
        } else {
            $this->comment('💡 Tip: Use -v flag for detailed error stack traces');
            $this->newLine();
        }

        if (! $this->isDryRun && ! $this->option('auto-confirm')) {
            $this->info('🔄 INTERACTIVE MODE - You will be asked to confirm after each phase');
            $this->comment('   Use --auto-confirm to skip phase confirmations');
            $this->newLine();
        }

        // Notify about instance type and Stripe
        if (isCloud()) {
            $this->comment('☁️  Cloud instance - Stripe subscriptions will be handled');
        } else {
            $this->comment('🏠 Self-hosted instance - Stripe operations will be skipped');
        }
        $this->newLine();

        try {
            $this->user = User::whereEmail($email)->firstOrFail();
        } catch (\Exception $e) {
            $this->error("User with email '{$email}' not found.");

            return 1;
        }

        if (DB::transactionLevel() !== 0) {
            $this->error('User deletion cannot run inside an existing database transaction.');
            $this->error('Commit or roll back the caller transaction before retrying.');

            return 1;
        }

        $lockKey = self::deletionLockKey((int) $this->user->getKey());
        $this->lock = Cache::lock($lockKey, self::LOCK_TTL_SECONDS);
        $this->lockAcquired = (bool) $this->lock->get();

        if (! $this->lockAcquired) {
            $this->error('Another deletion process is already running for this user.');
            $this->error('Active lock ownership cannot be bypassed, including with --force.');
            $this->logAction("Deletion blocked for user {$email}: Another process owns the deletion lock");

            return 1;
        }

        try {
            $this->logAction("Starting user deletion process for: {$email}");

            try {
                (new DeleteUserResources($this->user))->assertBlueGreenApplicationsReadyForPermanentDeletion();
            } catch (\RuntimeException $exception) {
                $this->error($exception->getMessage());
                $this->logAction("User deletion refused for {$email}: {$exception->getMessage()}");

                return 1;
            }

            // Phase 1: Show User Overview (outside transaction)
            if (! $this->showUserOverview()) {
                $this->info('User deletion cancelled by operator.');

                return 0;
            }
            $this->deletionState['phase_1_overview'] = true;

            if (! $this->isDryRun && $this->skipResources) {
                $resources = (new DeleteUserResources($this->user))->getResourcesPreview();
                if ($resources['applications']->isNotEmpty()
                    || $resources['databases']->isNotEmpty()
                    || $resources['services']->isNotEmpty()) {
                    $this->error('The --skip-resources option cannot be used because teams selected for deletion still contain resources.');
                    $this->error('Retry without --skip-resources so the resources can be reviewed and deleted safely.');

                    return 1;
                }
            }

            try {
                if (! $this->skipResources && ! $this->deleteResources()) {
                    $this->info('User deletion cancelled at resource preview.');

                    return 0;
                }

                if (! $this->deleteServers()) {
                    $this->info('User deletion cancelled at server preview.');

                    return 0;
                }

                if (! $this->handleTeams()) {
                    $this->info('User deletion cancelled at team preview.');

                    return 0;
                }

                if (! $this->isDryRun && ! $this->renewDeletionLock('Canonical User Deletion')) {
                    return 1;
                }

                if (! $this->deleteUserProfile()) {
                    $this->info('User deletion cancelled before the canonical transaction.');

                    return 0;
                }

                if (! $this->isDryRun) {
                    $this->deletionState['phase_2_resources'] = true;
                    $this->deletionState['phase_3_servers'] = true;
                    $this->deletionState['phase_4_teams'] = true;
                    $this->deletionState['phase_4_committed'] = true;
                    $this->deletionState['phase_5_user_profile'] = true;
                    $this->deletionState['phase_5_committed'] = true;
                    $this->deletionState['db_committed'] = true;

                    $this->newLine();
                    $this->info('✅ Canonical user deletion transaction committed successfully.');
                    $this->logAction("Database deletion completed for: {$email}");
                }

                if (! $this->skipStripe && isCloud()) {
                    if (! $this->isDryRun && ! $this->renewDeletionLock('Phase 6: Stripe Cancellation')) {
                        return 1;
                    }

                    if (! $this->cancelStripeSubscriptions()) {
                        if ($this->isDryRun) {
                            $this->info('User deletion would be cancelled at Stripe cancellation phase.');

                            return 0;
                        }

                        $this->newLine();
                        $this->error('User data was deleted, but Stripe subscription cancellation failed.');
                        $this->displayRecoverySteps();
                        $this->logAction("INCONSISTENT STATE: User {$email} deleted but Stripe cancellation failed");

                        return 1;
                    }
                }
                $this->deletionState['phase_6_stripe'] = true;

                $this->newLine();
                if ($this->isDryRun) {
                    $this->info('✅ DRY RUN completed successfully! No data was deleted.');
                } else {
                    $this->info('✅ User deletion completed successfully!');
                    $this->logAction("User deletion completed for: {$email}");
                }
            } catch (\Exception $e) {
                $this->databaseTransactionStarted = false;
                $this->newLine();
                $this->error('═══════════════════════════════════════');
                $this->error('❌ EXCEPTION DURING USER DELETION');
                $this->error('═══════════════════════════════════════');
                $this->error('Exception: '.get_class($e));
                $this->error('Message: '.$e->getMessage());
                $this->error('File: '.$e->getFile().':'.$e->getLine());
                $this->newLine();

                if ($this->output->isVerbose()) {
                    $this->error('Stack Trace:');
                    $this->error($e->getTraceAsString());
                    $this->newLine();
                } else {
                    $this->info('Run with -v for full stack trace');
                    $this->newLine();
                }

                $this->displayErrorState('Exception during canonical deletion');
                $this->displayRecoverySteps();
                $this->logAction("User deletion failed for {$email}: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");

                return 1;
            }

            return 0;
        } finally {
            // Ensure lock is always released
            $this->releaseLock();
        }
    }

    private function showUserOverview(): bool
    {
        $this->info('═══════════════════════════════════════');
        $this->info('PHASE 1: USER OVERVIEW');
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        $teams = $this->user->teams()->get();
        $ownedTeams = $teams->filter(fn ($team) => $team->pivot->role === 'owner');
        $memberTeams = $teams->filter(fn ($team) => $team->pivot->role !== 'owner');

        // Collect servers and resources ONLY from teams that will be FULLY DELETED
        // This means: user is owner AND is the ONLY member
        //
        // Resources from these teams will NOT be deleted:
        // - Teams where user is just a member
        // - Teams where user is owner but has other members (will be transferred/user removed)
        $allServers = collect();
        $allApplications = collect();
        $allDatabases = collect();
        $allServices = collect();
        $activeSubscriptions = collect();

        foreach ($teams as $team) {
            $userRole = $team->pivot->role;
            $memberCount = $team->members->count();

            // Only show resources from teams where user is the ONLY member
            // These are the teams that will be fully deleted
            if ($userRole !== 'owner' || $memberCount > 1) {
                continue;
            }

            $servers = $team->servers()->get();
            $allServers = $allServers->merge($servers);

            foreach ($servers as $server) {
                $resources = $server->definedResources();
                foreach ($resources as $resource) {
                    if ($resource instanceof Application) {
                        $allApplications->push($resource);
                    } elseif ($resource instanceof Service) {
                        $allServices->push($resource);
                    } else {
                        $allDatabases->push($resource);
                    }
                }
            }

            // Only collect subscriptions on cloud instances
            if (isCloud() && $team->subscription && $team->subscription->stripe_subscription_id) {
                $activeSubscriptions->push($team->subscription);
            }
        }

        // Build table data
        $tableData = [
            ['User', $this->user->email],
            ['User ID', $this->user->id],
            ['Created', $this->user->created_at->format('Y-m-d H:i:s')],
            ['Last Login', $this->user->updated_at->format('Y-m-d H:i:s')],
            ['Teams (Total)', $teams->count()],
            ['Teams (Owner)', $ownedTeams->count()],
            ['Teams (Member)', $memberTeams->count()],
            ['Servers', $allServers->unique('id')->count()],
            ['Applications', $allApplications->count()],
            ['Databases', $allDatabases->count()],
            ['Services', $allServices->count()],
        ];

        // Only show Stripe subscriptions on cloud instances
        if (isCloud()) {
            $tableData[] = ['Active Stripe Subscriptions', $activeSubscriptions->count()];
        }

        $this->table(['Property', 'Value'], $tableData);

        $this->newLine();

        $this->warn('⚠️  WARNING: This will permanently delete the user and all associated data!');
        $this->newLine();

        if (! $this->confirm('Do you want to continue with the deletion process?', false)) {
            return false;
        }

        return true;
    }

    private function deleteResources(): bool
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('PHASE 2: RESOURCE DELETION PREVIEW');
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        $action = new DeleteUserResources($this->user, $this->isDryRun);
        $resources = $action->getResourcesPreview();

        if ($resources['applications']->isEmpty() &&
            $resources['databases']->isEmpty() &&
            $resources['services']->isEmpty()) {
            $this->info('No resources to delete.');

            return true;
        }

        $this->info('Resources to be deleted:');
        $this->newLine();

        if ($resources['applications']->isNotEmpty()) {
            $this->warn("Applications to be deleted ({$resources['applications']->count()}):");
            $this->table(
                ['Name', 'UUID', 'Server', 'Status'],
                $resources['applications']->map(function ($app) {
                    return [
                        $app->name,
                        $app->uuid,
                        $app->destination->server->name,
                        $app->status ?? 'unknown',
                    ];
                })->toArray()
            );
            $this->newLine();
        }

        if ($resources['databases']->isNotEmpty()) {
            $this->warn("Databases to be deleted ({$resources['databases']->count()}):");
            $this->table(
                ['Name', 'Type', 'UUID', 'Server'],
                $resources['databases']->map(function ($db) {
                    return [
                        $db->name,
                        class_basename($db),
                        $db->uuid,
                        $db->destination->server->name,
                    ];
                })->toArray()
            );
            $this->newLine();
        }

        if ($resources['services']->isNotEmpty()) {
            $this->warn("Services to be deleted ({$resources['services']->count()}):");
            $this->table(
                ['Name', 'UUID', 'Server'],
                $resources['services']->map(function ($service) {
                    return [
                        $service->name,
                        $service->uuid,
                        $service->server->name,
                    ];
                })->toArray()
            );
            $this->newLine();
        }

        $this->error('⚠️  THIS ACTION CANNOT BE UNDONE!');
        if (! $this->confirm('Are you sure you want to delete all these resources?', false)) {
            return false;
        }

        return true;
    }

    private function deleteServers(): bool
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('PHASE 3: SERVER DELETION PREVIEW');
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        $action = new DeleteUserServers($this->user, $this->isDryRun);
        $servers = $action->getServersPreview();

        if ($servers->isEmpty()) {
            $this->info('No servers to delete.');

            return true;
        }

        $this->warn("Servers to be deleted ({$servers->count()}):");
        $this->table(
            ['ID', 'Name', 'IP', 'Description', 'Resources Count'],
            $servers->map(function ($server) {
                $resourceCount = $server->definedResources()->count();

                return [
                    $server->id,
                    $server->name,
                    $server->ip,
                    $server->description ?? '-',
                    $resourceCount,
                ];
            })->toArray()
        );
        $this->newLine();

        $this->error('⚠️  WARNING: Deleting servers will remove all server configurations!');
        if (! $this->confirm('Are you sure you want to delete all these servers?', false)) {
            return false;
        }

        return true;
    }

    private function handleTeams(): bool
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('PHASE 4: TEAM CHANGE PREVIEW');
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        $action = new DeleteUserTeams($this->user, $this->isDryRun);
        $preview = $action->getTeamsPreview();

        // Check for edge cases first - EXIT IMMEDIATELY if found
        if ($preview['edge_cases']->isNotEmpty()) {
            $this->error('═══════════════════════════════════════');
            $this->error('⚠️  EDGE CASES DETECTED - CANNOT PROCEED');
            $this->error('═══════════════════════════════════════');
            $this->newLine();

            foreach ($preview['edge_cases'] as $edgeCase) {
                $team = $edgeCase['team'];
                $reason = $edgeCase['reason'];
                $this->error("Team: {$team->name} (ID: {$team->id})");
                $this->error("Issue: {$reason}");

                // Show team members for context
                $this->info('Current members:');
                foreach ($team->members as $member) {
                    $role = $member->pivot->role;
                    $this->line("  - {$member->name} ({$member->email}) - Role: {$role}");
                }

                // Check for active resources
                $resourceCount = 0;
                foreach ($team->servers()->get() as $server) {
                    $resources = $server->definedResources();
                    $resourceCount += $resources->count();
                }

                if ($resourceCount > 0) {
                    $this->warn("  ⚠️  This team has {$resourceCount} active resources!");
                }

                // Show subscription details if relevant
                if ($team->subscription && $team->subscription->stripe_subscription_id) {
                    $this->warn('  ⚠️  Active Stripe subscription details:');
                    $this->warn("    Subscription ID: {$team->subscription->stripe_subscription_id}");
                    $this->warn("    Customer ID: {$team->subscription->stripe_customer_id}");

                    // Show other owners who could potentially take over
                    $otherOwners = $team->members
                        ->where('id', '!=', $this->user->id)
                        ->filter(function ($member) {
                            return $member->pivot->role === 'owner';
                        });

                    if ($otherOwners->isNotEmpty()) {
                        $this->info('  Other owners who could take over billing:');
                        foreach ($otherOwners as $owner) {
                            $this->line("    - {$owner->name} ({$owner->email})");
                        }
                    }
                }

                $this->newLine();
            }

            $this->error('Please resolve these issues manually before retrying:');

            // Check if any edge case involves subscription payment issues
            $hasSubscriptionIssue = $preview['edge_cases']->contains(function ($edgeCase) {
                return str_contains($edgeCase['reason'], 'Stripe subscription');
            });

            if ($hasSubscriptionIssue) {
                $this->info('For teams with subscription payment issues:');
                $this->info('1. Cancel the subscription through Stripe dashboard, OR');
                $this->info('2. Transfer the subscription to another owner\'s payment method, OR');
                $this->info('3. Have the other owner create a new subscription after cancelling this one');
                $this->newLine();
            }

            $hasNoOwnerReplacement = $preview['edge_cases']->contains(function ($edgeCase) {
                return str_contains($edgeCase['reason'], 'No suitable owner replacement');
            });

            if ($hasNoOwnerReplacement) {
                $this->info('For teams with no suitable owner replacement:');
                $this->info('1. Assign an admin role to a trusted member, OR');
                $this->info('2. Transfer team resources to another team, OR');
                $this->info('3. Delete the team manually if no longer needed');
                $this->newLine();
            }

            $this->error('USER DELETION ABORTED DUE TO EDGE CASES');
            $this->logAction("User deletion aborted for {$this->user->email}: Edge cases in team handling");

            // Return false to trigger proper cleanup and lock release
            return false;
        }

        if ($preview['to_delete']->isEmpty() &&
            $preview['to_transfer']->isEmpty() &&
            $preview['to_leave']->isEmpty()) {
            $this->info('No team changes needed.');

            return true;
        }

        if ($preview['to_delete']->isNotEmpty()) {
            $this->warn('Teams to be DELETED (user is the only member):');
            $this->table(
                ['ID', 'Name', 'Resources', 'Subscription'],
                $preview['to_delete']->map(function ($team) {
                    $resourceCount = 0;
                    foreach ($team->servers()->get() as $server) {
                        $resourceCount += $server->definedResources()->count();
                    }
                    $hasSubscription = $team->subscription && $team->subscription->stripe_subscription_id
                        ? '⚠️ YES - '.$team->subscription->stripe_subscription_id
                        : 'No';

                    return [
                        $team->id,
                        $team->name,
                        $resourceCount,
                        $hasSubscription,
                    ];
                })->toArray()
            );
            $this->newLine();
        }

        if ($preview['to_transfer']->isNotEmpty()) {
            $this->warn('Teams where ownership will be TRANSFERRED:');
            $this->table(
                ['Team ID', 'Team Name', 'New Owner', 'New Owner Email'],
                $preview['to_transfer']->map(function ($item) {
                    return [
                        $item['team']->id,
                        $item['team']->name,
                        $item['new_owner']->name,
                        $item['new_owner']->email,
                    ];
                })->toArray()
            );
            $this->newLine();
        }

        if ($preview['to_leave']->isNotEmpty()) {
            $this->warn('Teams where user will be REMOVED (other owners/admins exist):');
            $userId = $this->user->id;
            $this->table(
                ['ID', 'Name', 'User Role', 'Other Members'],
                $preview['to_leave']->map(function ($team) use ($userId) {
                    $userRole = $team->members->where('id', $userId)->first()->pivot->role;
                    $otherMembers = $team->members->count() - 1;

                    return [
                        $team->id,
                        $team->name,
                        $userRole,
                        $otherMembers,
                    ];
                })->toArray()
            );
            $this->newLine();
        }

        $this->error('⚠️  WARNING: Team changes affect access control and ownership!');
        if (! $this->confirm('Are you sure you want to proceed with these team changes?', false)) {
            return false;
        }

        return true;
    }

    private function cancelStripeSubscriptions(): bool
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('PHASE 6: CANCEL STRIPE SUBSCRIPTIONS');
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        $action = new CancelSubscription($this->user, $this->isDryRun);
        $subscriptions = $action->getSubscriptionsPreview();

        if ($subscriptions->isEmpty()) {
            $this->info('No Stripe subscriptions to cancel.');

            return true;
        }

        // Verify subscriptions in Stripe before showing details
        $this->info('Verifying subscriptions in Stripe...');
        $verification = $action->verifySubscriptionsInStripe();

        if (! empty($verification['errors'])) {
            $this->warn('⚠️  Errors occurred during verification:');
            foreach ($verification['errors'] as $error) {
                $this->warn("  - {$error}");
            }
            $this->newLine();
        }

        if ($verification['not_found']->isNotEmpty()) {
            $this->warn('⚠️  Subscriptions not found or inactive in Stripe:');
            foreach ($verification['not_found'] as $item) {
                $subscription = $item['subscription'];
                $reason = $item['reason'];
                $this->line("  - {$subscription->stripe_subscription_id} (Team: {$subscription->team->name}) - {$reason}");
            }
            $this->newLine();
        }

        if ($verification['verified']->isEmpty()) {
            $this->info('No active subscriptions found in Stripe to cancel.');

            return true;
        }

        $this->info('Active Stripe subscriptions to cancel:');
        $this->newLine();

        $totalMonthlyValue = 0;
        foreach ($verification['verified'] as $item) {
            $subscription = $item['subscription'];
            $stripeStatus = $item['stripe_status'];
            $team = $subscription->team;
            $planId = $subscription->stripe_plan_id;

            // Try to get the price from config
            $monthlyValue = $this->getSubscriptionMonthlyValue($planId);
            $totalMonthlyValue += $monthlyValue;

            $this->line("  - {$subscription->stripe_subscription_id} (Team: {$team->name})");
            $this->line("    Stripe Status: {$stripeStatus}");
            if ($monthlyValue > 0) {
                $this->line("    Monthly value: \${$monthlyValue}");
            }
            if ($subscription->stripe_cancel_at_period_end) {
                $this->line('    ⚠️  Already set to cancel at period end');
            }
        }

        if ($totalMonthlyValue > 0) {
            $this->newLine();
            $this->warn("Total monthly value: \${$totalMonthlyValue}");
        }
        $this->newLine();

        $this->error('⚠️  WARNING: Subscriptions will be cancelled IMMEDIATELY (not at period end)!');
        $this->warn('⚠️  NOTE: This operation happens AFTER database commit and cannot be rolled back!');
        if (! $this->confirm('Are you sure you want to cancel all these subscriptions immediately?', false)) {
            return false;
        }

        if (! $this->isDryRun) {
            $this->info('Cancelling subscriptions...');
            $result = $action->execute();
            $this->info("Cancelled {$result['cancelled']} subscriptions, {$result['failed']} failed");
            if ($result['failed'] > 0 && ! empty($result['errors'])) {
                $this->error('Failed subscriptions:');
                foreach ($result['errors'] as $error) {
                    $this->error("  - {$error}");
                }

                return false;
            }
            $this->logAction("Cancelled {$result['cancelled']} Stripe subscriptions for user {$this->user->email}");
        }

        return true;
    }

    public static function deletionLockKey(int $userId): string
    {
        return "user_deletion_{$userId}";
    }

    private function deleteUserProfile(): bool
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('PHASE 5: DELETE USER PROFILE');
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        $this->warn('⚠️  FINAL STEP - This action is IRREVERSIBLE!');
        $this->newLine();

        $this->info('User profile to be deleted:');
        $this->table(
            ['Property', 'Value'],
            [
                ['Email', $this->user->email],
                ['Name', $this->user->name],
                ['User ID', $this->user->id],
                ['Created', $this->user->created_at->format('Y-m-d H:i:s')],
                ['Email Verified', $this->user->email_verified_at ? 'Yes' : 'No'],
                ['2FA Enabled', $this->user->two_factor_confirmed_at ? 'Yes' : 'No'],
            ]
        );

        $this->newLine();

        $this->warn("Type 'DELETE {$this->user->email}' to confirm final deletion:");
        $confirmation = $this->ask('Confirmation');

        if ($confirmation !== "DELETE {$this->user->email}") {
            $this->error('Confirmation text does not match. Deletion cancelled.');

            return false;
        }

        if (! $this->isDryRun) {
            $this->info('Deleting user profile...');

            try {
                $this->databaseTransactionStarted = true;
                try {
                    if ($this->user->delete() !== true) {
                        throw new \RuntimeException('Canonical user deletion did not delete the user.');
                    }
                } finally {
                    $this->databaseTransactionStarted = false;
                }
                $this->info('✓ User profile deleted successfully.');
                $this->logAction("User profile deleted: {$this->user->email}");
            } catch (\Exception $e) {
                $this->error('Failed to delete user profile:');
                $this->error('Exception: '.get_class($e));
                $this->error('Message: '.$e->getMessage());
                $this->error('File: '.$e->getFile().':'.$e->getLine());

                if ($this->output->isVerbose()) {
                    $this->error('Stack Trace:');
                    $this->error($e->getTraceAsString());
                }

                $this->logAction("Failed to delete user profile {$this->user->email}: {$e->getMessage()}");

                throw $e; // Re-throw to trigger rollback
            }
        }

        return true;
    }

    private function getSubscriptionMonthlyValue(string $planId): int
    {
        // Try to get pricing from subscription metadata or config
        // Since we're using dynamic pricing, return 0 for now
        // This could be enhanced by fetching the actual price from Stripe API

        // Check if this is a dynamic pricing plan
        $dynamicMonthlyPlanId = config('subscription.stripe_price_id_dynamic_monthly');
        $dynamicYearlyPlanId = config('subscription.stripe_price_id_dynamic_yearly');

        if ($planId === $dynamicMonthlyPlanId || $planId === $dynamicYearlyPlanId) {
            // For dynamic pricing, we can't determine the exact amount without calling Stripe API
            // Return 0 to indicate dynamic/usage-based pricing
            return 0;
        }

        // For any other plans, return 0 as we don't have hardcoded prices
        return 0;
    }

    private function logAction(string $message): void
    {
        $logMessage = "[CloudDeleteUser] {$message}";

        if ($this->isDryRun) {
            $logMessage = "[DRY RUN] {$logMessage}";
        }

        Log::channel('single')->info($logMessage);

        // Also log to a dedicated user deletion log file
        $logFile = storage_path('logs/user-deletions.log');

        // Ensure the logs directory exists
        $logDir = dirname($logFile);
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = now()->format('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$logMessage}\n", FILE_APPEND | LOCK_EX);
    }

    private function displayErrorState(string $failedAt): void
    {
        $this->newLine();
        $this->error('═══════════════════════════════════════');
        $this->error('DELETION STATE AT FAILURE');
        $this->error('═══════════════════════════════════════');
        $this->error("Failed at: {$failedAt}");
        $this->newLine();

        $stateTable = [];
        foreach ($this->deletionState as $phase => $completed) {
            $phaseLabel = str_replace('_', ' ', ucwords($phase, '_'));
            $status = $completed ? '✓ Completed' : '✗ Not completed';
            $stateTable[] = [$phaseLabel, $status];
        }

        $this->table(['Phase', 'Status'], $stateTable);
        $this->newLine();

        if ($this->deletionState['phase_4_committed'] || $this->deletionState['phase_5_committed']) {
            $this->error('⚠️  The canonical database transaction committed and cannot be rolled back.');
        } else {
            $this->info('✓ Canonical database changes were rolled back or never started.');
        }

        $this->newLine();
        $this->error('User email: '.$this->user->email);
        $this->error('User ID: '.$this->user->id);
        $this->error('Timestamp: '.now()->format('Y-m-d H:i:s'));
        $this->newLine();
    }

    private function displayRecoverySteps(): void
    {
        $this->error('═══════════════════════════════════════');
        $this->error('RECOVERY STEPS');
        $this->error('═══════════════════════════════════════');

        if (! $this->deletionState['phase_4_committed'] && ! $this->deletionState['phase_5_committed']) {
            $this->info('✓ Canonical database changes were rolled back or never started.');
        } else {
            $this->error('Committed database-only changes:');
            if ($this->deletionState['phase_4_committed']) {
                $this->error('- Team memberships and owned teams');
            }
            if ($this->deletionState['phase_5_committed']) {
                $this->error('- User profile (email: '.$this->user->email.')');
            }
            $this->newLine();
        }

        if (! $this->deletionState['phase_6_stripe'] && isCloud()) {
            $this->error('Stripe subscriptions were NOT cancelled:');
            $this->error('1. Go to Stripe Dashboard: https://dashboard.stripe.com/');
            $this->error('2. Search for: '.$this->user->email);
            $this->error('3. Cancel all active subscriptions manually');
            $this->newLine();
        }

        $this->error('Log file: storage/logs/user-deletions.log');
        $this->error('Check logs for detailed error information');
        $this->newLine();
    }

    private function renewDeletionLock(string $phase): bool
    {
        if (! $this->lockAcquired || $this->lock === null) {
            $this->error("Deletion lock ownership is unavailable before {$phase}; refusing destructive work.");
            $this->displayErrorState("{$phase} (lock ownership unavailable)");
            $this->displayRecoverySteps();

            return false;
        }

        try {
            if ($this->lock->refresh(self::LOCK_TTL_SECONDS)) {
                return true;
            }
        } catch (\Throwable $throwable) {
            Log::warning('Unable to renew the administrative user deletion lock.', [
                'user_id' => $this->user->id,
                'phase' => $phase,
                'exception' => $throwable,
            ]);
            $this->error("Deletion lock ownership could not be validated before {$phase}; refusing destructive work.");
            $this->displayErrorState("{$phase} (lock ownership validation failed)");
            $this->displayRecoverySteps();

            return false;
        }

        $this->lockAcquired = false;
        $this->error("Deletion lock ownership was lost before {$phase}; refusing destructive work.");
        $this->error('Another operator may now own this deletion. Inspect committed phase state before retrying.');
        $this->displayErrorState("{$phase} (lock ownership lost)");
        $this->displayRecoverySteps();

        return false;
    }

    /**
     * Register signal handlers for graceful shutdown on Ctrl+C (SIGINT) and SIGTERM
     */
    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            // pcntl extension not available, skip signal handling
            return;
        }

        // Handle Ctrl+C (SIGINT)
        pcntl_signal(SIGINT, function () {
            $this->newLine();
            $this->warn('═══════════════════════════════════════');
            $this->warn('⚠️  PROCESS INTERRUPTED (Ctrl+C)');
            $this->warn('═══════════════════════════════════════');
            $this->info('Rolling back command transaction before releasing lock...');
            $this->displaySignalCleanupResult($this->cleanUpAfterSignal());
            exit(130); // Standard exit code for SIGINT
        });

        // Handle SIGTERM
        pcntl_signal(SIGTERM, function () {
            $this->newLine();
            $this->warn('═══════════════════════════════════════');
            $this->warn('⚠️  PROCESS TERMINATED (SIGTERM)');
            $this->warn('═══════════════════════════════════════');
            $this->info('Rolling back command transaction before releasing lock...');
            $this->displaySignalCleanupResult($this->cleanUpAfterSignal());
            exit(143); // Standard exit code for SIGTERM
        });

        // Enable async signal handling
        pcntl_async_signals(true);
    }

    /**
     * @return array{safe_to_release: bool, transaction_rolled_back: bool, lock_released: bool}
     */
    private function cleanUpAfterSignal(): array
    {
        $transactionRolledBack = false;

        if ($this->databaseTransactionStarted) {
            try {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                    $transactionRolledBack = true;
                }
                $this->databaseTransactionStarted = false;
            } catch (\Throwable $throwable) {
                Log::critical('Unable to roll back the administrative user deletion transaction during signal cleanup.', [
                    'user_id' => $this->user->id,
                    'exception' => $throwable,
                ]);

                return [
                    'safe_to_release' => false,
                    'transaction_rolled_back' => false,
                    'lock_released' => false,
                ];
            }
        }

        return [
            'safe_to_release' => true,
            'transaction_rolled_back' => $transactionRolledBack,
            'lock_released' => $this->releaseLock(),
        ];
    }

    /** @param array{safe_to_release: bool, transaction_rolled_back: bool, lock_released: bool} $result */
    private function displaySignalCleanupResult(array $result): void
    {
        if (! $result['safe_to_release']) {
            $this->error('Transaction rollback failed; deletion lock retained until expiry for safety.');

            return;
        }

        if ($result['transaction_rolled_back']) {
            $this->info('Command-owned database transaction rolled back.');
        }

        $this->info($result['lock_released']
            ? 'Lock released. Exiting gracefully.'
            : 'Lock was no longer owned; it was not released.');
    }

    /**
     * Release the lock if it exists
     */
    private function releaseLock(): bool
    {
        if (! $this->lockAcquired || $this->lock === null) {
            return false;
        }

        try {
            $released = $this->lock->release();
            $this->lockAcquired = false;

            return $released;
        } catch (\Throwable $throwable) {
            Log::warning('Unable to release the administrative user deletion lock.', [
                'user_id' => $this->user->id,
                'exception' => $throwable,
            ]);

            return false;
        }
    }
}
