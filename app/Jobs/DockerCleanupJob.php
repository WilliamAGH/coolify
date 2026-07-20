<?php

namespace App\Jobs;

use App\Actions\Server\CleanupDocker;
use App\Contracts\SupportsScheduledDispatchOccurrence;
use App\Events\DockerCleanupDone;
use App\Models\DockerCleanupExecution;
use App\Models\Server;
use App\Notifications\Server\DockerCleanupFailed;
use App\Notifications\Server\DockerCleanupSuccess;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DockerCleanupJob implements ShouldBeEncrypted, ShouldQueue, SupportsScheduledDispatchOccurrence
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public $tries = 1;

    public ?string $usageBefore = null;

    public ?DockerCleanupExecution $execution_log = null;

    private ?ScheduledDispatchOccurrence $scheduledDispatchOccurrence = null;

    public function middleware(): array
    {
        $middleware = [(new WithoutOverlapping('docker-cleanup-'.$this->server->uuid))->expireAfter(600)->dontRelease()];

        if ($this->scheduledDispatchOccurrence !== null) {
            array_unshift($middleware, $this->scheduledDispatchOccurrence);
        }

        return $middleware;
    }

    public function withScheduledDispatchOccurrence(ScheduledDispatchOccurrence $occurrence): static
    {
        $this->scheduledDispatchOccurrence = $occurrence;

        return $this;
    }

    public function finalizeScheduledDispatchOccurrenceAfterFailure(): bool
    {
        return $this->scheduledDispatchOccurrence?->completeAfterTerminalFailure() ?? false;
    }

    public function __construct(
        public Server $server,
        public bool $manualCleanup = false,
        public bool $deleteUnusedVolumes = false,
        public bool $deleteUnusedNetworks = false
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            $this->execution_log = DockerCleanupExecution::create([
                'server_id' => $this->server->id,
            ]);

            if (! $this->server->isFunctional()) {
                $this->execution_log->update([
                    'status' => 'failed',
                    'message' => 'Server is not functional (unreachable, unusable, or disabled)',
                    'finished_at' => Carbon::now()->toImmutable(),
                ]);

                return;
            }

            $this->usageBefore = $this->server->getDiskUsage();

            if ($this->manualCleanup || $this->server->settings->force_docker_cleanup) {
                $cleanup_log = CleanupDocker::run(
                    server: $this->server,
                    deleteUnusedVolumes: $this->deleteUnusedVolumes,
                    deleteUnusedNetworks: $this->deleteUnusedNetworks
                );
                $usageAfter = $this->server->getDiskUsage();
                $message = ($this->manualCleanup ? 'Manual' : 'Forced').' Docker cleanup job executed successfully. Disk usage before: '.$this->usageBefore.'%, Disk usage after: '.$usageAfter.'%.';

                $this->execution_log->update([
                    'status' => 'success',
                    'message' => $message,
                    'cleanup_log' => $cleanup_log,
                ]);

                $this->server->team?->notify(new DockerCleanupSuccess($this->server, $message));
                event(new DockerCleanupDone($this->execution_log));

                return;
            }

            if (str($this->usageBefore)->isEmpty() || $this->usageBefore === null || $this->usageBefore === 0) {
                $cleanup_log = CleanupDocker::run(
                    server: $this->server,
                    deleteUnusedVolumes: $this->deleteUnusedVolumes,
                    deleteUnusedNetworks: $this->deleteUnusedNetworks
                );
                $message = 'Docker cleanup job executed successfully, but no disk usage could be determined.';

                $this->execution_log->update([
                    'status' => 'success',
                    'message' => $message,
                    'cleanup_log' => $cleanup_log,
                ]);

                $this->server->team?->notify(new DockerCleanupSuccess($this->server, $message));
                event(new DockerCleanupDone($this->execution_log));

                return;
            }

            if ($this->usageBefore >= $this->server->settings->docker_cleanup_threshold) {
                $cleanup_log = CleanupDocker::run(
                    server: $this->server,
                    deleteUnusedVolumes: $this->deleteUnusedVolumes,
                    deleteUnusedNetworks: $this->deleteUnusedNetworks
                );
                $usageAfter = $this->server->getDiskUsage();
                $diskSaved = $this->usageBefore - $usageAfter;

                if ($diskSaved > 0) {
                    $message = 'Saved '.$diskSaved.'% disk space. Disk usage before: '.$this->usageBefore.'%, Disk usage after: '.$usageAfter.'%.';
                } else {
                    $message = 'Docker cleanup job executed successfully, but no disk space was saved. Disk usage before: '.$this->usageBefore.'%, Disk usage after: '.$usageAfter.'%.';
                }

                $this->execution_log->update([
                    'status' => 'success',
                    'message' => $message,
                    'cleanup_log' => $cleanup_log,
                ]);

                $this->server->team?->notify(new DockerCleanupSuccess($this->server, $message));
                event(new DockerCleanupDone($this->execution_log));
            } else {
                $message = 'No cleanup needed for '.$this->server->name;

                $this->execution_log->update([
                    'status' => 'success',
                    'message' => $message,
                ]);

                $this->server->team?->notify(new DockerCleanupSuccess($this->server, $message));
                event(new DockerCleanupDone($this->execution_log));
            }
        } catch (Throwable $e) {
            if ($this->execution_log) {
                $this->execution_log->update([
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ]);
                event(new DockerCleanupDone($this->execution_log));
            }
            $this->server->team?->notify(new DockerCleanupFailed($this->server, 'Docker cleanup job failed with the following error: '.$e->getMessage()));
            throw $e;
        } finally {
            if ($this->execution_log) {
                $this->execution_log->update([
                    'finished_at' => Carbon::now()->toImmutable(),
                ]);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->finalizeScheduledDispatchOccurrenceAfterFailure();

        Log::channel('scheduled-errors')->error('DockerCleanup permanently failed', [
            'job' => 'DockerCleanupJob',
            'server_id' => $this->server->id,
            'server' => $this->server->name,
            'total_attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);
    }
}
