<?php

use Symfony\Component\Yaml\Yaml;

it('uses Reverb as the first-party broadcast server while preserving Pusher credentials', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $broadcasting = file_get_contents(config_path('broadcasting.php'));
    $echoClient = file_get_contents(resource_path('views/layouts/base.blade.php'));

    expect($composer['require']['laravel/reverb'] ?? null)->toBe('^1.10')
        ->and($broadcasting)
        ->toContain("'default' => env('BROADCAST_CONNECTION', env('BROADCAST_DRIVER', 'reverb'))")
        ->toContain("'reverb' => [")
        ->toContain("'driver' => 'reverb'")
        ->toContain("'key' => env('PUSHER_APP_KEY', 'coolify')")
        ->toContain("'secret' => env('PUSHER_APP_SECRET', 'coolify')")
        ->toContain("'app_id' => env('PUSHER_APP_ID', 'coolify')")
        ->toContain("'host' => env('PUSHER_BACKEND_HOST', '127.0.0.1')")
        ->toContain("'port' => env('PUSHER_BACKEND_PORT', 6001)")
        ->and(config('broadcasting.connections.pusher.options.host'))
        ->toBe(config('broadcasting.connections.reverb.options.host'))
        ->and(config('broadcasting.connections.pusher.options.port'))
        ->toBe(config('broadcasting.connections.reverb.options.port'))
        ->and(config('broadcasting.connections.pusher.options.scheme'))
        ->toBe(config('broadcasting.connections.reverb.options.scheme'))
        ->and($echoClient)->toContain("broadcaster: 'reverb'")
        ->and(config_path('reverb.php'))->toBeFile();
});

it('keeps Reverb listener and client settings distinct on the compatibility ports', function () {
    $reverb = file_get_contents(config_path('reverb.php'));
    $constants = file_get_contents(config_path('constants.php'));

    expect($reverb)
        ->toContain("'host' => env('REVERB_SERVER_HOST', '0.0.0.0')")
        ->toContain("'port' => env('PUSHER_BACKEND_PORT', env('REVERB_SERVER_PORT', 6001))")
        ->toContain("'key' => env('PUSHER_APP_KEY', 'coolify')")
        ->toContain("'secret' => env('PUSHER_APP_SECRET', 'coolify')")
        ->toContain("'app_id' => env('PUSHER_APP_ID', 'coolify')")
        ->toContain("'port' => env('PUSHER_PORT', env('SOKETI_PORT', 6001))")
        ->toContain("'enabled' => env('REVERB_SCALING_ENABLED', false)")
        ->and($constants)
        ->toContain("'port' => env('PUSHER_PORT', env('SOKETI_PORT'))");
});

it('runs Reverb and the terminal websocket as supervised services in the Coolify image', function (string $dockerfile, string $dependencyService) {
    $dockerfileContents = file_get_contents(base_path($dockerfile));
    $serviceRoot = dirname($dockerfile).'/etc/s6-overlay/s6-rc.d';

    expect($dockerfileContents)
        ->toMatch('/COPY docker\/coolify-terminal\/package\*\.json (?:\/terminal\/|\.\/)/')
        ->toMatch('/COPY(?: --chown=[^ ]+)? docker\/coolify-terminal\/terminal-server\.js \/terminal\/terminal-server\.js/')
        ->toMatch('/COPY(?: --chown=[^ ]+)? docker\/coolify-terminal\/terminal-utils\.js \/terminal\/terminal-utils\.js/')
        ->and(file_get_contents(base_path($serviceRoot.'/reverb/run')))
        ->toContain('exec php artisan reverb:start')
        ->toContain('${PUSHER_BACKEND_PORT:-6001}')
        ->and(file_get_contents(base_path($serviceRoot.'/terminal-server/run')))
        ->toContain('exec node /terminal/terminal-server.js')
        ->and(base_path($serviceRoot."/reverb/dependencies.d/{$dependencyService}"))->toBeFile()
        ->and(base_path($serviceRoot."/terminal-server/dependencies.d/{$dependencyService}"))->toBeFile()
        ->and(base_path($serviceRoot.'/user/contents.d/reverb'))->toBeFile()
        ->and(base_path($serviceRoot.'/user/contents.d/terminal-server'))->toBeFile();
})->with([
    'production image' => ['docker/production/Dockerfile', 'init-script'],
    'development image' => ['docker/development/Dockerfile', 'init-setup'],
]);

it('keeps native terminal build dependencies out of the production image', function () {
    $dockerfile = file_get_contents(base_path('docker/production/Dockerfile'));
    $terminalBuilder = strpos($dockerfile, 'AS terminal-assets');
    $terminalDependencyInstall = strpos($dockerfile, 'npm ci', $terminalBuilder);
    $productionStage = strrpos($dockerfile, 'FROM serversideup/php:');
    $terminalRuntimeCopy = strpos(
        $dockerfile,
        'COPY --from=terminal-assets /terminal/node_modules /terminal/node_modules',
    );
    $productionInstructions = substr($dockerfile, $productionStage);

    expect($terminalBuilder)->not->toBeFalse()
        ->and($terminalDependencyInstall)->not->toBeFalse()->toBeGreaterThan($terminalBuilder)
        ->and($productionStage)->not->toBeFalse()->toBeGreaterThan($terminalDependencyInstall)
        ->and($terminalRuntimeCopy)->not->toBeFalse()->toBeGreaterThan($productionStage)
        ->and($productionInstructions)
        ->toContain('STOPSIGNAL SIGTERM')
        ->not->toContain('apk add --no-cache make g++ python3')
        ->not->toContain('npm ci')
        ->not->toContain('apk del')
        ->not->toContain('--update-binary');
});

it('bundles ports 6001 and 6002 into the Coolify service without a Soketi service', function (string $composeFile) {
    $compose = Yaml::parseFile(base_path($composeFile));
    $services = $compose['services'] ?? [];
    $coolify = $services['coolify'] ?? [];
    $serialized = json_encode($compose, JSON_THROW_ON_ERROR);

    expect($services)->toHaveKey('coolify')
        ->not->toHaveKey('soketi')
        ->and($serialized)->not->toContain('coolify-realtime')
        ->and(json_encode([$coolify['ports'] ?? [], $coolify['expose'] ?? []], JSON_THROW_ON_ERROR))
        ->toContain('6001')
        ->toContain('6002');
})->with([
    'base compose' => 'docker-compose.yml',
    'production compose' => 'docker-compose.prod.yml',
    'development compose' => 'docker-compose.dev.yml',
    'maxio development compose' => 'docker-compose-maxio.dev.yml',
    'windows compose' => 'docker-compose.windows.yml',
]);

it('proxies Reverb application and terminal paths to the Coolify container', function () {
    $server = file_get_contents(app_path('Models/Server.php'));

    expect($server)
        ->toContain("'coolify-reverb-ws' => [")
        ->toContain("'coolify-reverb-api' => [")
        ->toContain("['coolify-reverb-wss']")
        ->toContain("['coolify-reverb-api-https']")
        ->toContain('PathPrefix(`/app`)')
        ->toContain('PathPrefix(`/apps`)')
        ->toContain('PathPrefix(`/terminal/ws`)')
        ->toContain("'url' => 'http://coolify:6001'")
        ->toContain("'url' => 'http://coolify:6002'")
        ->toContain('reverse_proxy coolify:6001')
        ->toContain('reverse_proxy coolify:6002')
        ->not->toContain('reverse_proxy coolify-realtime');
});

it('normalizes the legacy public Pusher app port during install and upgrade', function (string $script) {
    $contents = file_get_contents(base_path($script));

    expect($contents)
        ->toContain('normalize_pusher_port')
        ->toContain('^PUSHER_PORT=8080$')
        ->toContain('update_env_var "PUSHER_PORT" "6001"')
        ->toContain('update_env_var "PUSHER_BACKEND_PORT" "6001"')
        ->toContain('update_env_var "TERMINAL_BACKEND_PORT" "6002"');
})->with([
    'production install' => 'scripts/install.sh',
    'production upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);

it('keeps the public terminal port optional so same-origin proxying still works', function (string $environmentTemplate) {
    expect(file_get_contents(base_path($environmentTemplate)))
        ->not->toContain("\nTERMINAL_PORT=");
})->with([
    'production environment' => '.env.production',
    'nightly production environment' => 'other/nightly/.env.production',
    'Windows environment example' => '.env.windows-docker-desktop.example',
]);

it('removes obsolete realtime image ownership but retains an exact legacy-container migration', function () {
    $constants = file_get_contents(config_path('constants.php'));
    $versions = file_get_contents(base_path('versions.json'));
    $nightlyVersions = file_get_contents(base_path('other/nightly/versions.json'));
    $upgrade = file_get_contents(base_path('scripts/upgrade.sh'));
    $nightlyUpgrade = file_get_contents(base_path('other/nightly/upgrade.sh'));
    $forkDeploy = file_get_contents(base_path('scripts/fork-deploy'));

    expect($constants)->not->toContain('realtime_version')
        ->not->toContain('realtime_image')
        ->and($versions)->not->toContain('"realtime"')
        ->and($nightlyVersions)->not->toContain('"realtime"')
        ->and(base_path('docker/coolify-realtime/Dockerfile'))->not->toBeFile()
        ->and(base_path('docker/coolify-realtime/package.json'))->not->toBeFile()
        ->and(base_path('docker/coolify-realtime/soketi-entrypoint.sh'))->not->toBeFile()
        ->and(base_path('.github/workflows/coolify-realtime.yml'))->not->toBeFile()
        ->and(base_path('.github/workflows/coolify-realtime-next.yml'))->not->toBeFile()
        ->and($upgrade)->toContain('stop_legacy_realtime_container')
        ->toContain('remove_proven_legacy_realtime_container')
        ->toContain("ORPHAN_CLEANUP=''")
        ->toContain('http://127.0.0.1:6001/up')
        ->toContain('http://127.0.0.1:6002/ready')
        ->toContain('coolify-realtime')
        ->and($nightlyUpgrade)->toContain('stop_legacy_realtime_container')
        ->toContain('remove_proven_legacy_realtime_container')
        ->toContain("ORPHAN_CLEANUP=''")
        ->toContain('http://127.0.0.1:6001/up')
        ->toContain('http://127.0.0.1:6002/ready')
        ->and($forkDeploy)->toContain('fd_stop_legacy_realtime_container')
        ->toContain('fd_remove_proven_legacy_realtime_container')
        ->toContain('http://127.0.0.1:6001/up')
        ->toContain('http://127.0.0.1:6002/ready')
        ->toContain('coolify-realtime');

    foreach ([$upgrade, $nightlyUpgrade] as $upgradeScript) {
        $candidateStart = strpos($upgradeScript, 'up -d \${ORPHAN_CLEANUP} --wait');
        $routeProof = strpos($upgradeScript, 'if ! wait_for_bundled_realtime_routes;', $candidateStart);
        $legacyRemoval = strrpos($upgradeScript, 'remove_proven_legacy_realtime_container');

        expect($candidateStart)->not->toBeFalse()
            ->and($routeProof)->not->toBeFalse()->toBeGreaterThan($candidateStart)
            ->and($legacyRemoval)->not->toBeFalse()->toBeGreaterThan($routeProof);
    }
});

it('keeps the signed fork release graph main-only without dropping attestations', function () {
    $workflow = file_get_contents(base_path('.github/workflows/publish-linux-image.yml'));
    $forkDeploy = file_get_contents(base_path('scripts/fork-deploy'));

    expect($workflow)
        ->not->toContain('coolify-realtime')
        ->not->toContain('REALTIME_TARGET')
        ->not->toContain('REALTIME_INDEX_DIGEST')
        ->not->toContain('fork_realtime_digest')
        ->toContain('main-amd64.sbom.sigstore.json')
        ->toContain('main-arm64.sbom.sigstore.json')
        ->toContain('main.provenance.sigstore.json')
        ->toContain('main.binding.sigstore.json')
        ->toContain('verify_bundle "oci://${MAIN_TARGET}@${MAIN_INDEX_DIGEST}"')
        ->and($forkDeploy)
        ->toContain('local acceptance=${1:-v2-only}')
        ->toContain('coolify-fork-release/v2)')
        ->toContain('coolify-fork-release/v1)')
        ->toContain('v1 manifests are accepted only for the active predecessor of a v2 update')
        ->toContain('fd_load_recorded_release "$current" active-v1-predecessor')
        ->toContain('fd_load_recorded_release "$target"');
});
