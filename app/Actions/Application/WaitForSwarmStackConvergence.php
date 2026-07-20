<?php

namespace App\Actions\Application;

use App\Models\Server;
use Closure;
use Illuminate\Support\Sleep;
use JsonException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class WaitForSwarmStackConvergence
{
    use AsAction;

    /**
     * @param  Closure(): mixed  $deploy
     * @param  null|Closure(string, int): ?string  $remoteExecutor
     * @param  null|Closure(int): void  $sleeper
     * @param  null|Closure(): float  $clock
     */
    public function deployAndWait(
        Server $server,
        string $stackName,
        int $healthCheckStartPeriod,
        int $healthCheckInterval,
        int $healthCheckRetries,
        Closure $deploy,
        ?Closure $remoteExecutor = null,
        ?Closure $sleeper = null,
        ?Closure $clock = null,
    ): void {
        $deploy();
        $this->handle(
            $server,
            $stackName,
            $healthCheckStartPeriod,
            $healthCheckInterval,
            $healthCheckRetries,
            $remoteExecutor,
            $sleeper,
            $clock,
        );
    }

    /**
     * @param  null|Closure(string, int): ?string  $remoteExecutor
     * @param  null|Closure(int): void  $sleeper
     * @param  null|Closure(): float  $clock
     */
    public function handle(
        Server $server,
        string $stackName,
        int $healthCheckStartPeriod,
        int $healthCheckInterval,
        int $healthCheckRetries,
        ?Closure $remoteExecutor = null,
        ?Closure $sleeper = null,
        ?Closure $clock = null,
    ): void {
        validateShellSafePath($stackName, 'Swarm stack name');

        $sleeper ??= static function (int $seconds): void {
            Sleep::for($seconds)->seconds();
        };
        $clock ??= static fn (): float => hrtime(true) / 1_000_000_000;
        $startPeriod = max(0, $healthCheckStartPeriod);
        $interval = max(1, $healthCheckInterval);
        $attempts = max(1, $healthCheckRetries);
        $timeoutSeconds = $startPeriod + ($interval * $attempts);
        $deadline = $clock() + $timeoutSeconds;
        $executeRemote = $remoteExecutor ?? static fn (string $command, int $timeout): ?string => instant_remote_process(
            [$command],
            $server,
            timeout: $timeout,
        );
        $remoteExecutor = function (string $command) use ($clock, $deadline, $executeRemote, $stackName): ?string {
            $timeout = $this->remainingTimeout($clock, $deadline, $stackName);
            $result = $executeRemote($command, $timeout);
            $this->remainingTimeout($clock, $deadline, $stackName);

            return $result;
        };
        $services = $this->servicesForStack($stackName, $remoteExecutor);
        $pendingServices = [];

        if ($startPeriod > 0) {
            $sleeper($startPeriod);
        }

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $pendingServices = [];

            foreach ($services as $service) {
                $updateStatus = $this->updateStatusFor($service, $remoteExecutor);

                if ($updateStatus === null) {
                    if ($this->tasksHaveConverged($service, $remoteExecutor)) {
                        continue;
                    }

                    $pendingServices[] = $this->serviceDescription($service, 'UpdateStatus is not available yet');

                    continue;
                }

                $state = $updateStatus['state'];

                if ($state === 'completed') {
                    if ($this->tasksHaveConverged($service, $remoteExecutor)) {
                        continue;
                    }

                    $pendingServices[] = $this->serviceDescription($service, 'update completed but replicas or tasks are still pending');

                    continue;
                }

                if ($state === 'updating') {
                    $this->tasksHaveConverged($service, $remoteExecutor);
                    $pendingServices[] = $this->serviceDescription($service, $updateStatus['message']);

                    continue;
                }

                $this->throwForTerminalState($service, $state, $updateStatus['message']);
            }

            if ($pendingServices === []) {
                return;
            }

            if ($attempt < $attempts) {
                $sleeper($interval);
            }
        }

        throw new RuntimeException(
            "Swarm stack {$stackName} did not converge after {$attempts} checks within {$timeoutSeconds} seconds; still updating: ".implode(', ', $pendingServices).'.',
        );
    }

    /**
     * @param  Closure(string): ?string  $remoteExecutor
     * @return list<array{id: string, name: string}>
     */
    private function servicesForStack(string $stackName, Closure $remoteExecutor): array
    {
        $filter = 'label=com.docker.stack.namespace='.$stackName;
        $services = $this->decodeJsonLines(
            $remoteExecutor('docker service ls --filter '.escapeShellValue($filter)." --format '{{json .}}'"),
            "Swarm stack {$stackName} service list",
        );

        $stackServices = [];
        foreach ($services as $service) {
            $id = trim((string) ($service['ID'] ?? ''));
            $name = trim((string) ($service['Name'] ?? ''));

            if ($id === '' || $name === '') {
                throw new RuntimeException("Swarm stack {$stackName} returned a service without an ID or name.");
            }

            validateShellSafePath($id, 'Swarm service identifier');
            validateShellSafePath($name, 'Swarm service name');
            $stackServices[$id] = ['id' => $id, 'name' => $name];
        }

        if ($stackServices === []) {
            throw new RuntimeException("Swarm stack {$stackName} did not create any services to verify.");
        }

        return array_values($stackServices);
    }

    /**
     * @param  array{id: string, name: string}  $service
     * @param  Closure(string): ?string  $remoteExecutor
     * @return null|array{state: string, message: string}
     */
    private function updateStatusFor(array $service, Closure $remoteExecutor): ?array
    {
        $output = trim((string) $remoteExecutor("docker service inspect --format '{{json .UpdateStatus}}' ".escapeShellValue($service['id'])));

        if ($output === 'null') {
            return null;
        }

        $updateStatus = $this->decodeJsonObject(
            $output,
            "Swarm service {$service['name']} update status",
        );
        $state = strtolower(trim((string) ($updateStatus['State'] ?? '')));

        if ($state === '') {
            throw new RuntimeException("Swarm service {$service['name']} did not report an update state.");
        }

        return [
            'state' => $state,
            'message' => $this->messageFrom($updateStatus, 'Message'),
        ];
    }

    /**
     * @param  array{id: string, name: string}  $service
     * @param  Closure(string): ?string  $remoteExecutor
     */
    private function tasksHaveConverged(array $service, Closure $remoteExecutor): bool
    {
        [$actualReplicas, $desiredReplicas] = $this->replicaCountsFor($service, $remoteExecutor);
        $tasks = $this->decodeJsonLines(
            $remoteExecutor("docker service ps --no-trunc --format '{{json .}}' ".escapeShellValue($service['id'])),
            "Swarm service {$service['name']} tasks",
            allowEmpty: true,
        );
        $desiredRunningTasks = 0;
        $runningTasks = 0;

        foreach ($tasks as $task) {
            $desiredState = strtolower(trim((string) ($task['DesiredState'] ?? '')));

            if ($desiredState === '') {
                throw new RuntimeException("Swarm service {$service['name']} returned a task without a desired state.");
            }

            if ($desiredState !== 'running') {
                continue;
            }

            $desiredRunningTasks++;
            $currentState = strtolower(trim((string) ($task['CurrentState'] ?? '')));

            if (str_starts_with($currentState, 'rejected')) {
                $message = $this->messageFrom($task, 'Error');
                $message = $message === '' ? $currentState : $message;

                throw new RuntimeException("Swarm service {$service['name']} has a rejected task: {$message}");
            }

            if (str_starts_with($currentState, 'running')) {
                $runningTasks++;

                continue;
            }

            if (in_array(strtok($currentState, ' '), ['new', 'allocated', 'pending', 'assigned', 'accepted', 'preparing', 'ready', 'starting'], true)) {
                continue;
            }

            $message = $this->messageFrom($task, 'Error');
            $message = $message === '' ? $currentState : $message;

            throw new RuntimeException("Swarm service {$service['name']} has a non-running desired task: {$message}");
        }

        return $actualReplicas === $desiredReplicas
            && $desiredRunningTasks === $desiredReplicas
            && $runningTasks === $desiredReplicas;
    }

    /**
     * @param  array{id: string, name: string}  $service
     * @param  Closure(string): ?string  $remoteExecutor
     * @return array{int, int}
     */
    private function replicaCountsFor(array $service, Closure $remoteExecutor): array
    {
        $filter = 'id='.$service['id'];
        $records = $this->decodeJsonLines(
            $remoteExecutor('docker service ls --filter '.escapeShellValue($filter)." --format '{{json .}}'"),
            "Swarm service {$service['name']} replica status",
        );
        $record = collect($records)->first(
            static fn (array $record): bool => trim((string) ($record['ID'] ?? '')) === $service['id'],
        );

        if ($record === null) {
            throw new RuntimeException("Swarm service {$service['name']} disappeared before convergence.");
        }

        $replicas = trim((string) ($record['Replicas'] ?? ''));

        if (preg_match('/^(\d+)\s*\/\s*(\d+)(?:\s|$)/', $replicas, $matches) !== 1) {
            throw new RuntimeException("Swarm service {$service['name']} returned an invalid replica status.");
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /** @param Closure(): float $clock */
    private function remainingTimeout(Closure $clock, float $deadline, string $stackName): int
    {
        $remaining = $deadline - $clock();

        if ($remaining <= 0) {
            throw new RuntimeException("Swarm stack {$stackName} convergence deadline expired.");
        }

        return max(1, (int) ceil($remaining));
    }

    /** @param array{id: string, name: string} $service */
    private function throwForTerminalState(array $service, string $state, string $message): never
    {
        $description = $this->serviceDescription($service, $message);

        if (in_array($state, ['paused', 'rollback_started', 'rollback_paused', 'rollback_completed'], true)) {
            throw new RuntimeException("Swarm service {$description} update {$state}.");
        }

        throw new RuntimeException("Swarm service {$description} reported unknown update state {$state}.");
    }

    /** @param array<string, mixed> $record */
    private function messageFrom(array $record, string $field): string
    {
        return trim(str_replace(["\r", "\n"], ' ', (string) ($record[$field] ?? '')));
    }

    /** @param array{id: string, name: string} $service */
    private function serviceDescription(array $service, string $message): string
    {
        return $message === '' ? $service['name'] : "{$service['name']}: {$message}";
    }

    /** @return list<array<string, mixed>> */
    private function decodeJsonLines(?string $output, string $context, bool $allowEmpty = false): array
    {
        $output = trim((string) $output);

        if ($output === '') {
            if ($allowEmpty) {
                return [];
            }

            throw new RuntimeException("{$context} returned no JSON output.");
        }

        $records = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $records[] = $this->decodeJsonObject($line, $context);
        }

        return $records;
    }

    /** @return array<string, mixed> */
    private function decodeJsonObject(?string $output, string $context): array
    {
        try {
            $decoded = json_decode(trim((string) $output), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("{$context} returned invalid JSON.", previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("{$context} did not return a JSON object.");
        }

        return $decoded;
    }
}
