<?php

declare(strict_types=1);

require '/workspace/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Laravel\Horizon\SupervisorOptions;
use Symfony\Component\HttpFoundation\Response;

final class ReservationProbeQueueManager extends QueueManager
{
    public int $reservationAttempt = 0;

    public function connection($name = null)
    {
        $this->reservationAttempt++;

        throw new RuntimeException('maintenance-mode worker attempted to reserve queued work');
    }
}

final class MaintenanceProbeWorker extends Worker
{
    protected function supportsAsyncSignals()
    {
        return false;
    }

    protected function pauseWorker(
        WorkerOptions $options,
        $lastRestart,
        $startTime = 0,
        $jobsProcessed = 0,
        $lastJobProcessedAt = null,
    ) {
        return [0, null];
    }
}

final class ProbeExceptionHandler implements ExceptionHandler
{
    public function report(Throwable $exception): void {}

    public function shouldReport(Throwable $exception): bool
    {
        return true;
    }

    public function render($request, Throwable $exception): Response
    {
        return new Response('', 500);
    }

    public function renderForConsole($output, Throwable $exception): void {}
}

$horizonOptions = new SupervisorOptions('restore-probe', 'redis', 'default');
if ($horizonOptions->force !== false) {
    fwrite(STDERR, "Horizon supervisor unexpectedly forces workers through maintenance\n");
    exit(1);
}

$container = new Container;
$events = new Dispatcher($container);
$queue = new ReservationProbeQueueManager($container);
$worker = new MaintenanceProbeWorker(
    $queue,
    $events,
    new ProbeExceptionHandler,
    static fn (): bool => true,
);
$status = $worker->daemon(
    'redis',
    'default',
    new WorkerOptions(force: $horizonOptions->force),
);

if ($status !== 0 || $queue->reservationAttempt !== 0) {
    fwrite(STDERR, "maintenance-mode Horizon worker reserved or executed queued work\n");
    exit(1);
}

fwrite(STDOUT, "horizon-maintenance=passed;force=false;reservation_attempt=0;executed=0\n");
