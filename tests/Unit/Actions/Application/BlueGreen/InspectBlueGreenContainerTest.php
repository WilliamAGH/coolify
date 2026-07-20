<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Enums\BlueGreenDeploymentColor;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('uses full Docker IDs for named container uniqueness assertions', function () {
    $expectation = new BlueGreenContainerExpectation(
        name: 'coolify-app-blue-1',
        dockerId: null,
        applicationId: 42,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: 'deployment-uuid',
        color: BlueGreenDeploymentColor::BLUE,
        routingRevision: 7,
    );

    $assertions = (new InspectBlueGreenContainer)->runningMutationCompletionAssertionsFor($expectation);
    $uniquenessAssertion = collect($assertions)
        ->first(fn (string $assertion): bool => str_contains($assertion, 'docker ps -aq'));

    expect($uniquenessAssertion)
        ->toBe('test "$(docker ps -aq --no-trunc --filter \'label=coolify.applicationId=42\' --filter \'label=coolify.blueGreen.managed=true\' --filter \'label=coolify.blueGreen.deploymentUuid=deployment-uuid\' --filter \'label=coolify.blueGreen.color=blue\' --filter \'label=coolify.blueGreen.routingRevision=7\')" = "$(docker inspect --format=\'{{.Id}}\' \'coolify-app-blue-1\')"')
        ->not->toContain('docker ps -aq --filter');

    $filesystem = new Filesystem;
    $fakeDockerDirectory = sys_get_temp_dir().'/coolify-inspect-blue-green-container-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($fakeDockerDirectory, 0700);
    $fakeDockerPath = $fakeDockerDirectory.'/docker';
    file_put_contents($fakeDockerPath, <<<'SH'
#!/bin/sh
if [ "$1" = ps ]; then
    printf %s "$FAKE_DOCKER_PS_ID"
elif [ "$1" = inspect ]; then
    printf %s "$FAKE_DOCKER_INSPECT_ID"
else
    exit 1
fi
SH);
    chmod($fakeDockerPath, 0700);

    try {
        $fullDockerId = str_repeat('a', 64);
        $environment = [
            'PATH' => $fakeDockerDirectory.PATH_SEPARATOR.getenv('PATH'),
            'FAKE_DOCKER_PS_ID' => $fullDockerId,
            'FAKE_DOCKER_INSPECT_ID' => $fullDockerId,
        ];
        $fullIdProof = Process::fromShellCommandline($uniquenessAssertion, env: $environment);
        $fullIdProof->run();

        $environment['FAKE_DOCKER_PS_ID'] = substr($fullDockerId, 0, 12);
        $truncatedIdProof = Process::fromShellCommandline($uniquenessAssertion, env: $environment);
        $truncatedIdProof->run();

        expect($fullDockerId)->toHaveLength(64)
            ->and($fullIdProof->isSuccessful())->toBeTrue()
            ->and($truncatedIdProof->isSuccessful())->toBeFalse();
    } finally {
        $filesystem->remove($fakeDockerDirectory);
    }
});
