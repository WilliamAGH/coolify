<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationRuntime;

function controlPlaneGenerationRuntimePayload(): array
{
    return [
        'predecessor' => [
            'coolify-web-a' => [
                'container_id' => str_repeat('a', 64),
                'image_id' => 'sha256:'.str_repeat('b', 64),
            ],
            'coolify-web-b' => [
                'container_id' => str_repeat('c', 64),
                'image_id' => 'sha256:'.str_repeat('d', 64),
            ],
        ],
        'successor' => [
            'coolify-web-c' => [
                'container_id' => str_repeat('e', 64),
                'image_id' => 'sha256:'.str_repeat('f', 64),
            ],
            'coolify-web-d' => [
                'container_id' => str_repeat('1', 64),
                'image_id' => 'sha256:'.str_repeat('2', 64),
            ],
        ],
        'writer' => [
            'name' => 'coolify-web-c',
            'container_id' => str_repeat('e', 64),
        ],
    ];
}

it('round-trips a canonical generation runtime and resolves exact Docker identities', function (): void {
    $payload = controlPlaneGenerationRuntimePayload();
    $runtime = ControlPlaneGenerationRuntime::fromArray($payload);
    $json = $runtime->toJson();

    expect($runtime->toArray())->toBe($payload)
        ->and($json)->toBe(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->and(ControlPlaneGenerationRuntime::fromJson($json))->toEqual($runtime)
        ->and($runtime->predecessorDockerIdFor('coolify-web-a'))->toBe(str_repeat('a', 64))
        ->and($runtime->successorDockerIdFor('coolify-web-d'))->toBe(str_repeat('1', 64))
        ->and($runtime->writerIdentity())->toBe([
            'name' => 'coolify-web-c',
            'container_id' => str_repeat('e', 64),
            'image_id' => 'sha256:'.str_repeat('f', 64),
        ])
        ->and($json)->not->toContain('raw-token');
});

it('requires sorted independent member maps and a successor writer identity', function (): void {
    $payload = controlPlaneGenerationRuntimePayload();

    $unsorted = $payload;
    $unsorted['predecessor'] = [
        'coolify-web-b' => $payload['predecessor']['coolify-web-b'],
        'coolify-web-a' => $payload['predecessor']['coolify-web-a'],
    ];

    $unknownWriter = $payload;
    $unknownWriter['writer']['name'] = 'coolify-web-e';

    $mismatchedWriterId = $payload;
    $mismatchedWriterId['writer']['container_id'] = str_repeat('0', 64);

    expect(fn (): ControlPlaneGenerationRuntime => ControlPlaneGenerationRuntime::fromArray($unsorted))
        ->toThrow(InvalidArgumentException::class, 'sorted and unique');
    expect(fn (): ControlPlaneGenerationRuntime => ControlPlaneGenerationRuntime::fromArray($unknownWriter))
        ->toThrow(InvalidArgumentException::class, 'must identify a successor');
    expect(fn (): ControlPlaneGenerationRuntime => ControlPlaneGenerationRuntime::fromArray($mismatchedWriterId))
        ->toThrow(InvalidArgumentException::class, 'must match its successor');
});

it('rejects unsafe names, weak identifiers, unexpected member shapes, and noncanonical JSON', function (): void {
    $payload = controlPlaneGenerationRuntimePayload();

    $unsafeName = $payload;
    $unsafeName['predecessor'] = [
        'coolify-web-a; curl attacker.test' => $payload['predecessor']['coolify-web-a'],
        'coolify-web-b' => $payload['predecessor']['coolify-web-b'],
    ];

    $weakContainerId = $payload;
    $weakContainerId['successor']['coolify-web-c']['container_id'] = str_repeat('e', 63);

    $weakImageId = $payload;
    $weakImageId['successor']['coolify-web-c']['image_id'] = str_repeat('f', 64);

    $unexpectedMemberShape = $payload;
    $unexpectedMemberShape['predecessor']['coolify-web-a']['unexpected'] = 'value';

    $noncanonicalJson = json_encode([
        'writer' => $payload['writer'],
        'predecessor' => $payload['predecessor'],
        'successor' => $payload['successor'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    expect(fn (): ControlPlaneGenerationRuntime => ControlPlaneGenerationRuntime::fromArray($unsafeName))
        ->toThrow(InvalidArgumentException::class, 'Docker-safe routed DNS name');
    expect(fn (): ControlPlaneGenerationRuntime => ControlPlaneGenerationRuntime::fromArray($weakContainerId))
        ->toThrow(InvalidArgumentException::class, 'full Docker ID');
    expect(fn (): ControlPlaneGenerationRuntime => ControlPlaneGenerationRuntime::fromArray($weakImageId))
        ->toThrow(InvalidArgumentException::class, 'full sha256 image ID');
    expect(fn (): ControlPlaneGenerationRuntime => ControlPlaneGenerationRuntime::fromArray($unexpectedMemberShape))
        ->toThrow(InvalidArgumentException::class, 'unexpected shape');
    expect(fn (): ControlPlaneGenerationRuntime => ControlPlaneGenerationRuntime::fromJson($noncanonicalJson))
        ->toThrow(InvalidArgumentException::class, 'unexpected shape');
    expect(fn (): string => ControlPlaneGenerationRuntime::fromArray($payload)->successorDockerIdFor('coolify-web-z'))
        ->toThrow(InvalidArgumentException::class, 'has no member');
});
