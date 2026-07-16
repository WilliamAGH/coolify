<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerRemovalPlan;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteOutcome;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteResult;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationTransportException;
use App\Actions\Application\BlueGreen\BlueGreenProxyDeactivationSnapshot;
use App\Actions\Application\BlueGreen\BlueGreenProxyEvictionState;
use App\Actions\Application\BlueGreen\DrainAndRemoveBlueGreenApplicationContainers;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDeactivationRemoteCommand;
use App\Actions\Application\BlueGreen\InstallBlueGreenProxyEvictionTombstone;
use App\Actions\Application\BlueGreen\RemoveBlueGreenApplicationContainers;
use App\Actions\Application\BlueGreen\RemoveBlueGreenProxyEvictionTombstone;
use App\Actions\Application\BlueGreen\WaitForBlueGreenProxyEviction;
use App\Models\ApplicationBlueGreenDeactivation;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Symfony\Component\Process\Process;

function proxyDeactivationSnapshot(int $deadline = 2_000_000_000): BlueGreenProxyDeactivationSnapshot
{
    $source = "http:\n  routers: {}\n";
    $tombstone = "http:\n  routers: {}\n";

    $destinationClockObservedAt = $deadline
        - BlueGreenProxyDeactivationSnapshot::DEACTIVATION_WINDOW_SECONDS
        + BlueGreenProxyDeactivationSnapshot::FINALIZATION_RESERVE_SECONDS;

    return new BlueGreenProxyDeactivationSnapshot(
        managedFilename: 'coolify-blue-green-a5adf99dc3d81b09.yaml',
        sourceYaml: $source,
        sourceSha256: hash('sha256', $source),
        tombstoneYaml: $tombstone,
        tombstoneSha256: hash('sha256', $tombstone),
        tombstoneAcknowledgement: str_repeat('a', 64),
        routes: [
            ['router' => 'coolify-bg-a5adf99dc3d81b09-http-public', 'url' => 'http://application.test/'],
            ['router' => 'coolify-bg-a5adf99dc3d81b09-https-public', 'url' => 'https://application.test/'],
        ],
        backendPort: 3000,
        destinationClockObservedAtUnixSeconds: $destinationClockObservedAt,
        drainDeadlineUnixSeconds: $deadline,
        deactivationDeadlineUnixSeconds: $destinationClockObservedAt
            + BlueGreenProxyDeactivationSnapshot::DEACTIVATION_WINDOW_SECONDS,
    );
}

function expectValidShell(string ...$commands): void
{
    foreach ($commands as $command) {
        $syntax = new Process(['sh', '-n']);
        $syntax->setInput($command);
        $syntax->run();
        expect($syntax->isSuccessful())->toBeTrue($syntax->getErrorOutput());
    }
}

it('builds cache-bypassed exact tombstone and final-absence route gates without a Traefik API', function () {
    $snapshot = proxyDeactivationSnapshot();
    $gate = new WaitForBlueGreenProxyEviction;
    $tombstone = $gate->commandFor($snapshot, BlueGreenProxyEvictionState::Tombstone, 10);
    $absent = $gate->commandFor($snapshot, BlueGreenProxyEvictionState::Absent, 10);
    expectValidShell($tombstone, $absent);

    expect($tombstone)->toContain(
        'Cache-Control: no-cache, no-store, max-age=0',
        'Pragma: no-cache',
        'Connection: close',
        '__coolify_blue_green_eviction=${nonce}-0',
        '__coolify_blue_green_eviction=${nonce}-1',
        'The durable blue-green deactivation deadline expired',
        'case "$status" in 000|5??)',
        '"$status" != 418',
        'x-coolify-probe-ack',
        $snapshot->tombstoneAcknowledgement,
        '--insecure',
    )->not->toContain('127.0.0.1:8080', '/api/rawdata')
        ->and(substr_count($tombstone, 'nonce=$(cat /proc/sys/kernel/random/uuid)'))->toBe(2)
        ->and($absent)->toContain('"$status" != 404', '[ -n "$acknowledgements" ]')
        ->and($absent)->not->toContain('127.0.0.1:8080', '/api/rawdata');
});

it('checksum-guards the atomic source-to-tombstone replacement and exact tombstone removal', function () {
    $snapshot = proxyDeactivationSnapshot();
    $install = (new InstallBlueGreenProxyEvictionTombstone)->commandFor('/data/coolify/proxy', $snapshot);
    $remove = (new RemoveBlueGreenProxyEvictionTombstone)->commandFor('/data/coolify/proxy', $snapshot);
    expectValidShell($install, $remove);
    $installLock = strpos($install, 'flock -x 9');
    $installChecksum = strpos($install, 'current_checksum=$(sha256sum');
    $installMove = strpos($install, 'mv -fT -- "$stage"');
    $removeLock = strpos($remove, 'flock -x 9');
    $removeChecksum = strpos($remove, 'current_checksum=$(sha256sum');
    $removeFile = strpos($remove, 'rm -f --');

    expect($install)->toContain(
        $snapshot->sourceSha256,
        $snapshot->tombstoneSha256,
        'current_checksum=$(sha256sum',
        'flock -x 9',
        'mv -fT -- "$stage"',
        'tombstone-present',
        'already-absent',
    )->and(substr_count($install, $snapshot->sourceSha256))->toBeGreaterThanOrEqual(2)
        ->and($remove)->toContain(
            'test "${current_checksum%% *}" =',
            $snapshot->tombstoneSha256,
            'rm -f --',
            'flock -x 9',
        )
        ->and($installLock)->toBeLessThan($installChecksum)
        ->and($installChecksum)->toBeLessThan($installMove)
        ->and($removeLock)->toBeLessThan($removeChecksum)
        ->and($removeChecksum)->toBeLessThan($removeFile);
});

it('uses one absolute deadline and rechecks the tombstone immediately before every exact-id stop', function () {
    $snapshot = proxyDeactivationSnapshot(deadline: 2_000_000_123);
    $plan = new BlueGreenContainerRemovalPlan(
        applicationId: 42,
        blueContainerName: 'application-blue',
        blueRoutingRevision: 7,
        greenContainerName: 'application-green',
        greenRoutingRevision: 8,
        legacyContainerName: 'application-legacy',
        stopGracePeriodSeconds: 5,
    );
    $command = (new DrainAndRemoveBlueGreenApplicationContainers)->commandFor(
        '/data/coolify/proxy',
        $snapshot,
        $plan,
    );
    $firstStop = strpos($command, 'docker stop --time=5');
    $lock = strpos($command, 'flock -x 9');
    $firstTombstoneAssertion = strpos($command, 'tombstone_checksum=$(sha256sum');
    $lastTombstoneAssertion = strrpos(substr($command, 0, $firstStop), 'tombstone_checksum=$(sha256sum');
    expectValidShell($command);

    expect($command)->toContain(
        '"$(date +%s)" -ge 2000000123',
        'attempt_deadline=$(($(date +%s) + 240))',
        '"$(date +%s)" -ge "$attempt_deadline"',
        'stable_zero_observations',
        '"$stable_zero_observations" -ge 3',
        'observed_at - stable_zero_started_at',
        '/proc/$pid/net/tcp',
        '/proc/$pid/net/tcp6',
        '$4 == "01"',
        'backend_port=$(printf \'%04X\' 3000)',
        '$blue_container_id',
        '$green_container_id',
        '$legacy_container_id',
        '{{.Name}}',
        '/application-blue|42|true|blue|7',
        '/application-green|42|true|green|8',
        '/application-legacy|42',
    )->and(substr_count($command, 'tombstone_checksum=$(sha256sum'))->toBeGreaterThanOrEqual(5)
        ->and($firstStop)->not->toBeFalse()
        ->and($lock)->toBeLessThan($firstTombstoneAssertion)
        ->and($lock)->toBeLessThan($firstStop)
        ->and($lastTombstoneAssertion)->not->toBeFalse()
        ->and($lastTombstoneAssertion)->toBeLessThan($firstStop)
        ->and($command)->not->toContain('attempts=', 'while [ "$attempt"');
});

it('propagates executed remote exit status as a typed invariant outcome', function () {
    $executor = new ExecuteBlueGreenDeactivationRemoteCommand;
    $command = $executor->commandFor('printf \'remote invariant\\n\' >&2; exit 23');
    expectValidShell($command);
    $process = Process::fromShellCommandline($command);
    $process->mustRun();
    $result = $executor->decode(trim($process->getOutput()));

    expect($result->outcome)->toBe(BlueGreenDeactivationRemoteOutcome::InvariantViolation)
        ->and($result->exitStatus)->toBe(23)
        ->and($result->output)->toBe("remote invariant\n")
        ->and($executor->decode($executor->encode(new BlueGreenDeactivationRemoteResult(
            BlueGreenDeactivationRemoteOutcome::Success,
            0,
            "exact output\n",
        )))->output)->toBe("exact output\n");
});

it('keeps absent or malformed remote envelopes classified as resumable transport', function () {
    $executor = new ExecuteBlueGreenDeactivationRemoteCommand;

    expect(fn () => $executor->decode(''))->toThrow(BlueGreenDeactivationTransportException::class, 'remains resumable')
        ->and(fn () => $executor->decode('wrong-prefix|success|0|'))
        ->toThrow(BlueGreenDeactivationTransportException::class, 'remains resumable')
        ->and(fn () => $executor->decode('coolify-blue-green-deactivation-remote-v1|success|0|***'))
        ->toThrow(BlueGreenDeactivationTransportException::class, 'remains resumable')
        ->and(fn () => $executor->decode('coolify-blue-green-deactivation-remote-v1|success|1|'))
        ->toThrow(BlueGreenDeactivationTransportException::class, 'remains resumable')
        ->and(fn () => $executor->decode('coolify-blue-green-deactivation-remote-v1|invariant|0|'))
        ->toThrow(BlueGreenDeactivationTransportException::class, 'remains resumable');
});

it('re-proves exact names, full ids, and provenance in route-less container cleanup', function () {
    $plan = new BlueGreenContainerRemovalPlan(
        applicationId: 42,
        blueContainerName: 'application-blue',
        blueRoutingRevision: 7,
        greenContainerName: 'application-green',
        greenRoutingRevision: 8,
        legacyContainerName: 'application-legacy',
        stopGracePeriodSeconds: 5,
    );
    $command = (new RemoveBlueGreenApplicationContainers)->commandFor($plan);
    expectValidShell($command);

    expect($command)->toContain(
        '{{.Id}} {{.Name}}',
        '/application-blue 42 true blue 7',
        '/application-green 42 true green 8',
        '/application-legacy 42',
        'test "${#container_id}" -eq 64',
        'docker stop --time=5 "$container_id"',
    );
});

it('bounds each recovery attempt inside the durable destination-clock window', function () {
    expect(BlueGreenProxyDeactivationSnapshot::ROUTE_CONVERGENCE_ATTEMPTS * 2
        + BlueGreenProxyDeactivationSnapshot::DRAIN_ATTEMPT_SECONDS)
        ->toBeLessThan(
            BlueGreenProxyDeactivationSnapshot::DEACTIVATION_WINDOW_SECONDS
                - BlueGreenProxyDeactivationSnapshot::FINALIZATION_RESERVE_SECONDS,
        );
});

it('round-trips the durable absolute drain deadline with the exact proxy bytes', function () {
    $snapshot = proxyDeactivationSnapshot(deadline: 2_000_000_456);

    expect(BlueGreenProxyDeactivationSnapshot::decode($snapshot->encode()))->toEqual($snapshot)
        ->and($snapshot->encode()['drainDeadlineUnixSeconds'])->toBe(2_000_000_456)
        ->and($snapshot->deactivationDeadlineUnixSeconds)->toBe(2_000_000_516)
        ->and($snapshot->destinationClockObservedAtUnixSeconds)->toBe(1_999_999_616);
});

it('strictly encrypts durable proxy snapshots and rejects plaintext fallback rows', function () {
    ApplicationBlueGreenDeactivation::encryptUsing(new Encrypter(random_bytes(32), 'AES-256-CBC'));

    try {
        $deactivation = new ApplicationBlueGreenDeactivation;
        $deactivation->proxy_snapshot = proxyDeactivationSnapshot()->encode();
        $ciphertext = $deactivation->getAttributes()['proxy_snapshot'];

        expect($ciphertext)->toBeString()
            ->not->toContain('sourceYaml', 'tombstoneYaml');

        $deactivation->setRawAttributes(['proxy_snapshot' => json_encode(['version' => 2], JSON_THROW_ON_ERROR)]);
        expect(fn () => $deactivation->proxy_snapshot)->toThrow(DecryptException::class);
    } finally {
        ApplicationBlueGreenDeactivation::encryptUsing(null);
    }
});
