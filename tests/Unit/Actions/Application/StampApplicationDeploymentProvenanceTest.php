<?php

use App\Actions\Application\StampApplicationDeploymentProvenance;

it('replaces list-form caller provenance for every service while preserving unrelated labels', function () {
    $deploymentUuid = 'deployment-authoritative';

    $compose = (new StampApplicationDeploymentProvenance)->handle([
        'services' => [
            'web' => [
                'labels' => [
                    'coolify.deploymentId',
                    'traefik.enable=true',
                    'coolify.deploymentId=caller-web',
                    'example.owner=web',
                ],
            ],
            'worker' => [
                'labels' => [
                    'coolify.deploymentId=caller-worker',
                    'example.owner=worker',
                ],
            ],
        ],
    ], $deploymentUuid);

    expect($compose['services']['web']['labels'])->toBe([
        'traefik.enable=true',
        'example.owner=web',
        'coolify.deploymentId=deployment-authoritative',
    ])->and($compose['services']['worker']['labels'])->toBe([
        'example.owner=worker',
        'coolify.deploymentId=deployment-authoritative',
    ]);
});

it('replaces mapping-form caller provenance for every service while preserving unrelated labels', function () {
    $deploymentUuid = 'deployment-authoritative';

    $compose = (new StampApplicationDeploymentProvenance)->handle([
        'services' => [
            'web' => [
                'labels' => [
                    'traefik.enable' => 'true',
                    'coolify.deploymentId' => 'caller-web',
                    'example.owner' => 'web',
                ],
            ],
            'worker' => [
                'labels' => ['example.owner' => 'worker'],
            ],
        ],
    ], $deploymentUuid);

    expect($compose['services']['web']['labels'])->toBe([
        'traefik.enable' => 'true',
        'coolify.deploymentId' => 'deployment-authoritative',
        'example.owner' => 'web',
    ])->and($compose['services']['worker']['labels'])->toBe([
        'example.owner' => 'worker',
        'coolify.deploymentId' => 'deployment-authoritative',
    ]);
});
