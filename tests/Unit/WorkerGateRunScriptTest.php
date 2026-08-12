<?php

/**
 * Pins the s6 worker-gate contract (issue #211): the horizon and
 * scheduler-worker run scripts must consult the container environment
 * (immune to .env staging races on fork-deploy activation boots) and keep
 * the .env grep fallback, under a with-contenv shebang that actually
 * exposes that environment.
 */
function workerGateScript(string $image, string $service): string
{
    $path = getcwd()."/docker/{$image}/etc/s6-overlay/s6-rc.d/{$service}/run";
    $content = file_get_contents($path);
    expect($content)->toBeString();

    return $content;
}

it('gates horizon on container env before the .env fallback', function (string $image) {
    $script = workerGateScript($image, 'horizon');

    expect($script)
        ->toStartWith('#!/command/with-contenv sh')
        ->toContain('[ "${HORIZON_ENABLED:-}" = "false" ]')
        ->toContain("grep -qE '^HORIZON_ENABLED=false' .env")
        ->toContain('exec sleep infinity')
        ->toContain('exec php artisan horizon');
})->with(['production', 'development']);

it('gates the scheduler on container env before the .env fallback', function (string $image) {
    $script = workerGateScript($image, 'scheduler-worker');

    expect($script)
        ->toStartWith('#!/command/with-contenv sh')
        ->toContain('[ "${SCHEDULER_ENABLED:-}" = "false" ]')
        ->toContain("grep -qE '^SCHEDULER_ENABLED=false' .env")
        ->toContain('exec sleep infinity')
        ->toContain('exec php artisan schedule:work');
})->with(['production', 'development']);

it('keeps the worker gate scripts executable', function () {
    foreach (['production', 'development'] as $image) {
        foreach (['horizon', 'scheduler-worker'] as $service) {
            $path = getcwd()."/docker/{$image}/etc/s6-overlay/s6-rc.d/{$service}/run";
            expect(is_executable($path))->toBeTrue("{$path} must be executable");
        }
    }
});
