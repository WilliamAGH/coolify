<?php

use App\Actions\Application\BlueGreen\ReadBlueGreenManagedRouteMetadata;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use Symfony\Component\Process\Process;

const MANAGED_ROUTE_READ_TEST_FILENAME = 'coolify-blue-green-0123456789abcdef.yaml';

/** @return array{proxyPath: string, lock: string, state: string, managed: string, mutationJournal: string, containerJournal: string} */
function managedRouteReadFixture(): array
{
    $proxyPath = rtrim((string) tempnam(sys_get_temp_dir(), 'bg-read-'), DIRECTORY_SEPARATOR);
    unlink($proxyPath);
    $writer = new WriteBlueGreenProxyConfiguration;
    $paths = [
        'proxyPath' => $proxyPath,
        'lock' => $writer->managedLockPath($proxyPath, MANAGED_ROUTE_READ_TEST_FILENAME),
        'state' => $writer->statePath($proxyPath, MANAGED_ROUTE_READ_TEST_FILENAME),
        'managed' => $writer->managedPath($proxyPath, MANAGED_ROUTE_READ_TEST_FILENAME),
        'mutationJournal' => $writer->mutationJournalPath($proxyPath, MANAGED_ROUTE_READ_TEST_FILENAME),
        'containerJournal' => $writer->containerMutationJournalPath($proxyPath, MANAGED_ROUTE_READ_TEST_FILENAME),
    ];
    foreach (['lock', 'state', 'managed', 'mutationJournal', 'containerJournal'] as $key) {
        if (! is_dir(dirname($paths[$key]))) {
            mkdir(dirname($paths[$key]), 0700, recursive: true);
        }
    }

    return $paths;
}

function runManagedRouteReadCommand(string $proxyPath): Process
{
    $command = (new ReadBlueGreenManagedRouteMetadata)->commandFor($proxyPath, MANAGED_ROUTE_READ_TEST_FILENAME);
    $process = Process::fromShellCommandline('bash -c '.escapeshellarg($command));
    $process->run();

    return $process;
}

function managedRouteReadFlockAvailable(): bool
{
    $probe = Process::fromShellCommandline('command -v flock');
    $probe->run();

    return $probe->isSuccessful();
}

it('reports an absent destination when the lock and every durable remnant are missing', function (): void {
    $paths = managedRouteReadFixture();

    $process = runManagedRouteReadCommand($paths['proxyPath']);

    expect($process->isSuccessful())->toBeTrue()
        ->and(trim($process->getOutput()))->toBe('coolify-blue-green-managed-route:absent');
});

it('reports the lock-absent sentinel instead of failing when remnants exist without a lock', function (): void {
    $paths = managedRouteReadFixture();
    file_put_contents($paths['state'], 'durable-state-bytes');

    $process = runManagedRouteReadCommand($paths['proxyPath']);

    expect($process->isSuccessful())->toBeTrue()
        ->and(trim($process->getOutput()))->toBe('coolify-blue-green-managed-route:lock-absent');
});

it('reports the lock-absent sentinel when only a journal remnant exists without a lock', function (): void {
    $paths = managedRouteReadFixture();
    file_put_contents($paths['containerJournal'], 'journal-bytes');

    $process = runManagedRouteReadCommand($paths['proxyPath']);

    expect($process->isSuccessful())->toBeTrue()
        ->and(trim($process->getOutput()))->toBe('coolify-blue-green-managed-route:lock-absent');
});

it('reports the pending proxy-mutation journal sentinel in-band instead of exiting nonzero', function (): void {
    if (! managedRouteReadFlockAvailable()) {
        $this->markTestSkipped('flock is unavailable on this host; the locked reader paths need util-linux flock.');
    }
    $paths = managedRouteReadFixture();
    touch($paths['lock']);
    file_put_contents($paths['mutationJournal'], 'proxy-journal-bytes');

    $process = runManagedRouteReadCommand($paths['proxyPath']);

    expect($process->isSuccessful())->toBeTrue()
        ->and(trim($process->getOutput()))->toBe(WriteBlueGreenProxyConfiguration::PENDING_PROXY_MUTATION_JOURNAL_OUTPUT);
});

it('reports the proxy-mutation journal ahead of a container-mutation journal when both remain', function (): void {
    if (! managedRouteReadFlockAvailable()) {
        $this->markTestSkipped('flock is unavailable on this host; the locked reader paths need util-linux flock.');
    }
    $paths = managedRouteReadFixture();
    touch($paths['lock']);
    file_put_contents($paths['mutationJournal'], 'proxy-journal-bytes');
    file_put_contents($paths['containerJournal'], 'container-journal-bytes');

    $process = runManagedRouteReadCommand($paths['proxyPath']);

    expect($process->isSuccessful())->toBeTrue()
        ->and(trim($process->getOutput()))->toBe(WriteBlueGreenProxyConfiguration::PENDING_PROXY_MUTATION_JOURNAL_OUTPUT);
});

it('still reports the pending container-mutation journal sentinel under the shared lock', function (): void {
    if (! managedRouteReadFlockAvailable()) {
        $this->markTestSkipped('flock is unavailable on this host; the locked reader paths need util-linux flock.');
    }
    $paths = managedRouteReadFixture();
    touch($paths['lock']);
    file_put_contents($paths['containerJournal'], 'container-journal-bytes');

    $process = runManagedRouteReadCommand($paths['proxyPath']);

    expect($process->isSuccessful())->toBeTrue()
        ->and(trim($process->getOutput()))->toBe(WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT);
});
