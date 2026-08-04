<?php

use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\BlueGreenDeploymentColor;
use App\Models\Application;

/**
 * A destination that routes several services gives each one its own backend
 * port, so the port is a sufficient discriminator for container identity. These
 * tests pin both the new per-port behaviour and the unchanged single-pair shape.
 */
function portScopedTarget(?array $portContainerNames = null): BlueGreenRoutingTarget
{
    return new BlueGreenRoutingTarget(
        destinationId: 1,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: 'app-blue',
        greenContainerName: 'app-green',
        port: 8000,
        routingRevision: 3,
        publicProofToken: str_repeat('a', 64),
        ports: [8000, 8080],
        portContainerNames: $portContainerNames,
    );
}

it('keeps one container pair for every port when no per-port map is given', function () {
    $target = portScopedTarget();

    expect($target->portContainerNames)->toBe([])
        ->and($target->containerName(BlueGreenDeploymentColor::BLUE))->toBe('app-blue')
        ->and($target->containerName(BlueGreenDeploymentColor::BLUE, 8000))->toBe('app-blue')
        ->and($target->containerName(BlueGreenDeploymentColor::BLUE, 8080))->toBe('app-blue')
        ->and($target->containerName(BlueGreenDeploymentColor::GREEN, 8080))->toBe('app-green');
});

it('resolves the container for the service that owns each port', function () {
    $target = portScopedTarget([
        8000 => ['blue' => 'app-blue', 'green' => 'app-green'],
        8080 => ['blue' => 'app-queue-blue', 'green' => 'app-queue-green'],
    ]);

    expect($target->containerName(BlueGreenDeploymentColor::BLUE, 8000))->toBe('app-blue')
        ->and($target->containerName(BlueGreenDeploymentColor::BLUE, 8080))->toBe('app-queue-blue')
        ->and($target->containerName(BlueGreenDeploymentColor::GREEN, 8080))->toBe('app-queue-green')
        // An unscoped lookup still answers with the primary pair.
        ->and($target->containerName(BlueGreenDeploymentColor::GREEN))->toBe('app-green');
});

it('refuses a per-port entry for an unconfigured port', function () {
    expect(fn () => portScopedTarget([9999 => ['blue' => 'x-blue', 'green' => 'x-green']]))
        ->toThrow(InvalidArgumentException::class, 'must name a configured backend port');
});

it('refuses a per-port entry whose colors share a container name', function () {
    expect(fn () => portScopedTarget([8080 => ['blue' => 'same', 'green' => 'same']]))
        ->toThrow(InvalidArgumentException::class, 'must differ');
});

it('changes the applied proof only when a per-port topology exists', function () {
    $shared = portScopedTarget()->publicAcknowledgement();
    $again = portScopedTarget()->publicAcknowledgement();
    $perPort = portScopedTarget([
        8080 => ['blue' => 'app-queue-blue', 'green' => 'app-queue-green'],
    ])->publicAcknowledgement();

    // Stability matters: a changed proof for an unchanged topology would make
    // every live destination fail its acknowledgement probe.
    expect($shared)->toBe($again)
        ->and($perPort)->not->toBe($shared);
});

it('advertises only the ports a container actually serves', function () {
    $application = Application::factory()->make(['uuid' => 'appuuid1234567890']);

    $all = generateBlueGreenApplicationContainerLabels(
        $application, 1, BlueGreenDeploymentColor::BLUE, 3, [8000, 8080],
    );
    $onlyQueue = generateBlueGreenApplicationContainerLabels(
        $application, 1, BlueGreenDeploymentColor::BLUE, 3, [8000, 8080], [8080],
    );

    $ports = fn (array $labels): array => array_values(array_filter(
        $labels,
        fn (string $label) => str_contains($label, 'loadbalancer.server.port'),
    ));

    expect($ports($all))->toHaveCount(2)
        ->and($ports($onlyQueue))->toHaveCount(1)
        ->and($ports($onlyQueue)[0])->toContain('=8080');

    // Naming must still reflect the destination's whole inventory, or the
    // discovered service name would not match the compiled document.
    expect($ports($onlyQueue)[0])->toBe($ports($all)[1]);
});

it('refuses served ports outside the backend port inventory', function () {
    $application = Application::factory()->make(['uuid' => 'appuuid1234567890']);

    expect(fn () => generateBlueGreenApplicationContainerLabels(
        $application, 1, BlueGreenDeploymentColor::BLUE, 3, [8000], [9090],
    ))->toThrow(InvalidArgumentException::class, 'subset of the backend ports');
});
