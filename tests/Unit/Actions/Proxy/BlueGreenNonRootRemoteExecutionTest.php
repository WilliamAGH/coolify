<?php

use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactCommitter;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactReader;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactRestorer;
use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\RemoveBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\ProxyTypes;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function blueGreenProxyNonRootRemoteServer(): Server
{
    Storage::fake('ssh-keys');
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '192.0.2.10',
        'user' => 'ubuntu',
        'port' => 22,
    ]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();

    return $server->fresh();
}

function blueGreenProxyNonRootConfiguration(): BlueGreenProxyConfiguration
{
    $managedFilename = 'coolify-blue-green-aaaaaaaaaaaaaaaa.yaml';
    $yaml = <<<'YAML'
http:
  routers:
    managed:
      rule: Host(`nonroot.example.test`)
      service: managed
  services:
    managed:
      loadBalancer:
        servers:
          - url: http://127.0.0.1:8080
YAML;
    $sha256 = hash('sha256', $yaml);

    return new BlueGreenProxyConfiguration(
        managedFilename: $managedFilename,
        yaml: $yaml,
        sha256: $sha256,
        state: new BlueGreenProxyState(
            managedFilename: $managedFilename,
            applicationUuid: 'nonrootproxy',
            destinationId: 1,
            operationId: 'nonroot-write',
            mutationSequence: 1,
            destinationFenceEpoch: 1,
            routingRevision: 1,
            managedSha256: $sha256,
            activeColor: BlueGreenDeploymentColor::BLUE,
            activeDeploymentUuid: 'nonroot-deployment',
            activeContainerName: 'nonrootproxy-blue',
            activeContainerId: str_repeat('a', 64),
            applicationRoutingConfigDigest: hash('sha256', 'nonroot-routing'),
            destinationTopologyDigest: hash('sha256', 'nonroot-topology'),
        ),
    );
}

/** @param list<PendingProcess> $processes */
function assertBlueGreenProxyNonRootProcessPayloads(array $processes, array $expectedScripts): void
{
    expect($processes)->toHaveCount(count($expectedScripts));

    foreach ($expectedScripts as $index => $script) {
        $process = $processes[$index];

        expect($process->input)->toBe($script)
            ->and($process->input)->toContain('=')
            ->and($process->command)->toContain('sudo bash -se')
            ->and(substr_count($process->command, 'sudo bash -se'))->toBe(1)
            ->and($process->command)->not->toContain('sudo sudo')
            ->and($process->command)->not->toContain($script);
    }
}

it('executes every proxy mutation and rollback script through one unmodified sudo stdin boundary', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    $server = blueGreenProxyNonRootRemoteServer();
    $configuration = blueGreenProxyNonRootConfiguration();
    $writer = new WriteBlueGreenProxyConfiguration;
    $writeKey = new BlueGreenProxyRollbackKey('nonroot-write', null, $configuration->state);
    $removeState = $configuration->state->withoutManagedRoute(2, 'nonroot-remove');
    $removeKey = new BlueGreenProxyRollbackKey('nonroot-remove', $configuration->state, $removeState);
    $restoredState = $configuration->state->withDestinationFenceEpoch(3, 'nonroot-remove', 2);
    $bootId = '11111111-2222-3333-4444-555555555555';

    $expectedScripts = [
        $writer->commandFor($server->proxyPath(), $configuration, $writeKey, $bootId),
        $writer->repairCommandFor($server->proxyPath(), $configuration, $bootId),
        (new RemoveBlueGreenProxyConfiguration)->commandFor($server->proxyPath(), $removeKey, $bootId),
        (new BlueGreenProxyRollbackArtifactReader)->commandFor($server->proxyPath(), $writeKey),
        (new BlueGreenProxyRollbackArtifactRestorer)->commandFor($server->proxyPath(), $writeKey, $bootId),
        $writer->rollbackArtifactRestoreFromStateCommandFor(
            $server->proxyPath(),
            $removeKey,
            $removeState,
            $restoredState,
            $bootId,
        ),
        (new BlueGreenProxyRollbackArtifactCommitter)->commandFor($server->proxyPath(), $writeKey),
    ];
    $writeArtifact = new BlueGreenProxyRollbackArtifact($writeKey, false, '');
    $removeArtifact = new BlueGreenProxyRollbackArtifact($removeKey, true, $configuration->yaml);
    $outputs = [
        BlueGreenProxyRollbackArtifact::OUTPUT_PREFIX.base64_encode($writeArtifact->serialize()),
        WriteBlueGreenProxyConfiguration::REPAIR_HEALTHY_OUTPUT,
        BlueGreenProxyRollbackArtifact::OUTPUT_PREFIX.base64_encode($removeArtifact->serialize()),
        BlueGreenProxyRollbackArtifactReader::ABSENT_OUTPUT,
        '',
        '',
        '',
    ];
    $processes = [];
    Process::fake(function (PendingProcess $process) use (&$outputs, &$processes) {
        $processes[] = $process;

        return Process::result(output: array_shift($outputs) ?? '');
    });

    expect($writer->handle($server, $configuration, $writeKey, $bootId)->existed)->toBeFalse()
        ->and($writer->repairManagedConfiguration($server, $configuration, $bootId))
        ->toBe(WriteBlueGreenProxyConfiguration::REPAIR_HEALTHY_OUTPUT)
        ->and((new RemoveBlueGreenProxyConfiguration)->handle($server, $removeKey, $bootId)->existed)
        ->toBeTrue()
        ->and((new BlueGreenProxyRollbackArtifactReader)->handle($server, $writeKey))
        ->toBeNull();
    (new BlueGreenProxyRollbackArtifactRestorer)->handle($server, $writeKey, $bootId);
    (new BlueGreenProxyRollbackArtifactRestorer)->restoreFromCurrentState(
        $server,
        $removeKey,
        $removeState,
        $restoredState,
        $bootId,
    );
    (new BlueGreenProxyRollbackArtifactCommitter)->handle($server, $writeKey);

    assertBlueGreenProxyNonRootProcessPayloads($processes, $expectedScripts);
});
