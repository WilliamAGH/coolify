<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneStaticListenerHandoff;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function staticListenerHandoffState(
    ControlPlaneProxyExposure $exposure = ControlPlaneProxyExposure::Public,
): ControlPlaneProxyEnrollmentState {
    return new ControlPlaneProxyEnrollmentState(
        phase: ControlPlaneProxyEnrollmentPhase::Preparing,
        operationId: 'static-listener-handoff',
        tokenSha256: hash('sha256', 'test-token'),
        serverId: 1,
        appPort: 8000,
        exposure: $exposure,
        managedFilename: 'coolify.yaml',
        dynamicRevision: 1,
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        expectedMember: 'blue',
        expectedRevision: 'revision-42',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        staticPredecessorBytes: "name: coolify-proxy\nservices:\n  traefik:\n    image: traefik:v3.6\n    ports:\n      - 80:80\n",
        staticReplacementBytes: "name: coolify-proxy\nservices:\n  traefik:\n    image: traefik:v3.6\n    command:\n      - --entrypoints.coolify.address=:8000\n    ports:\n      - 80:80\n      - 8000:8000\n",
        sourceOverrideBytes: "services:\n  coolify:\n    ports: !reset []\n",
        dynamicPredecessorBytes: null,
        dynamicReplacementBytes: "http:\n  routers:\n    coolify-app-port: {}\n",
        createdAt: '2026-07-19T00:00:00Z',
        updatedAt: '2026-07-19T00:00:00Z',
    );
}

/** @return array{root: string, proxy_compose: string, source_compose: string, source_production_compose: string, source_custom_compose: string, source_postgres_upgrade_compose: string, source_override: string, source_environment: string, lock: string, bin: string, state: string, log: string} */
function staticListenerHandoffFixtures(ControlPlaneProxyEnrollmentState $state): array
{
    $root = sys_get_temp_dir().'/coolify-static-listener-handoff-'.bin2hex(random_bytes(8));
    $proxyDirectory = $root.'/proxy';
    $sourceDirectory = $root.'/source';
    $binDirectory = $root.'/bin';
    $stateDirectory = $root.'/docker-state';
    (new Filesystem)->mkdir([$proxyDirectory, $sourceDirectory, $binDirectory, $stateDirectory], 0700);

    $proxyCompose = $proxyDirectory.'/docker-compose.yml';
    $sourceCompose = $sourceDirectory.'/docker-compose.yml';
    $sourceProductionCompose = $sourceDirectory.'/docker-compose.prod.yml';
    $sourceCustomCompose = $sourceDirectory.'/docker-compose.custom.yml';
    $sourcePostgresUpgradeCompose = $sourceDirectory.'/docker-compose.postgres-upgrade.yml';
    $sourceOverride = $sourceDirectory.'/docker-compose.control-plane-listener.yml';
    $sourceEnvironment = $sourceDirectory.'/.env';
    file_put_contents($proxyCompose, $state->staticPredecessorBytes);
    file_put_contents($sourceCompose, "services:\n  coolify: {}\n");
    file_put_contents($sourceProductionCompose, "services:\n  coolify:\n    ports:\n      - \"\${APP_PORT:-8000}:8080\"\n");
    file_put_contents($sourceEnvironment, "APP_PORT=8000\n");
    file_put_contents($stateDirectory.'/coolify_port', "0.0.0.0:8000\n");
    file_put_contents($stateDirectory.'/coolify-proxy_port', '');
    file_put_contents($stateDirectory.'/commands.log', '');
    file_put_contents($binDirectory.'/docker', <<<'SH'
#!/bin/sh
set -eu

state_directory=$FAKE_DOCKER_STATE
command_log=$FAKE_DOCKER_LOG
command=$1
shift

case "$command" in
  compose)
    printf 'compose %s\n' "$*" >> "$command_log"
    service=
    for argument in "$@"; do service=$argument; done
    case "$service" in
      coolify)
        if [ -e "$FAKE_SOURCE_OVERRIDE" ] && [ ! -L "$FAKE_SOURCE_OVERRIDE" ]; then
          : > "$state_directory/coolify_port"
        else
          printf '0.0.0.0:%s\n' "$FAKE_APP_PORT" > "$state_directory/coolify_port"
        fi
        ;;
      traefik)
        if grep -Fq -- '--entrypoints.coolify.address=:8000' "$FAKE_PROXY_COMPOSE"; then
          printf '%s\n' "${FAKE_PROXY_BINDING:-0.0.0.0:$FAKE_APP_PORT}" > "$state_directory/coolify-proxy_port"
        else
          : > "$state_directory/coolify-proxy_port"
        fi
        ;;
    esac
    ;;
  inspect)
    container=
    for argument in "$@"; do container=$argument; done
    case "$container" in coolify|coolify-proxy) exit 0 ;; *) exit 1 ;; esac
    ;;
  port)
    container=$1
    cat "$state_directory/${container}_port"
    ;;
  ps)
    printf '%s\n' coolify coolify-proxy
    ;;
  *)
    exit 1
    ;;
esac
SH
    );
    chmod($binDirectory.'/docker', 0700);

    return [
        'root' => $root,
        'proxy_compose' => $proxyCompose,
        'source_compose' => $sourceCompose,
        'source_production_compose' => $sourceProductionCompose,
        'source_custom_compose' => $sourceCustomCompose,
        'source_postgres_upgrade_compose' => $sourcePostgresUpgradeCompose,
        'source_override' => $sourceOverride,
        'source_environment' => $sourceEnvironment,
        'lock' => $proxyDirectory.'/.handoff.lock',
        'bin' => $binDirectory,
        'state' => $stateDirectory,
        'log' => $stateDirectory.'/commands.log',
    ];
}

/** @param array{proxy_compose: string, source_compose: string, source_production_compose: string, source_custom_compose: string, source_postgres_upgrade_compose: string, source_override: string, source_environment: string, lock: string} $fixture */
function staticListenerHandoffWriter(array $fixture): ControlPlaneStaticListenerHandoff
{
    return new ControlPlaneStaticListenerHandoff(
        proxyComposePath: $fixture['proxy_compose'],
        sourceComposePath: $fixture['source_compose'],
        sourceProductionComposePath: $fixture['source_production_compose'],
        sourceOverridePath: $fixture['source_override'],
        sourceEnvironmentPath: $fixture['source_environment'],
        sourceCustomComposePath: $fixture['source_custom_compose'],
        sourcePostgresUpgradeComposePath: $fixture['source_postgres_upgrade_compose'],
        enrollmentLockPath: $fixture['lock'],
    );
}

/** @param array{bin: string, state: string, log: string, source_override: string, proxy_compose: string} $fixture */
function runStaticListenerHandoffCommand(string $command, array $fixture, array $environment = []): Process
{
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(10);
    $process->setEnv(array_replace([
        'PATH' => $fixture['bin'].':'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'FAKE_DOCKER_STATE' => $fixture['state'],
        'FAKE_DOCKER_LOG' => $fixture['log'],
        'FAKE_SOURCE_OVERRIDE' => $fixture['source_override'],
        'FAKE_PROXY_COMPOSE' => $fixture['proxy_compose'],
        'FAKE_APP_PORT' => '8000',
    ], $environment));
    $process->run();

    return $process;
}

it('hands off the public APP_PORT listener to Traefik exactly once and replays idempotently', function () {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState();
    $fixture = staticListenerHandoffFixtures($state);

    try {
        file_put_contents($fixture['source_custom_compose'], "services:\n  coolify: {}\n");
        file_put_contents($fixture['source_postgres_upgrade_compose'], "services:\n  postgres: {}\n");
        $writer = staticListenerHandoffWriter($fixture);
        $first = runStaticListenerHandoffCommand($writer->commandFor($state), $fixture);
        $second = runStaticListenerHandoffCommand($writer->commandFor($state), $fixture);
        $commands = file_get_contents($fixture['log']);

        expect($first->isSuccessful())->toBeTrue()
            ->and(trim($first->getOutput()))->toBe(ControlPlaneStaticListenerHandoff::APPLIED_OUTPUT)
            ->and($second->isSuccessful())->toBeTrue()
            ->and(file_get_contents($fixture['proxy_compose']))->toBe($state->staticReplacementBytes)
            ->and(file_get_contents($fixture['source_override']))->toBe($state->sourceOverrideBytes)
            ->and(file_get_contents($fixture['state'].'/coolify_port'))->toBe('')
            ->and(file_get_contents($fixture['state'].'/coolify-proxy_port'))->toBe("0.0.0.0:8000\n")
            ->and($commands)->toContain(
                'compose --project-directory '.dirname($fixture['source_compose'])
                .' --env-file '.$fixture['source_environment']
                .' -f '.$fixture['source_compose']
                .' -f '.$fixture['source_production_compose']
                .' -f '.$fixture['source_custom_compose']
                .' -f '.$fixture['source_postgres_upgrade_compose']
                .' -f '.$fixture['source_override']
                .' up -d --force-recreate --no-deps coolify',
                'compose --project-directory '.dirname($fixture['proxy_compose'])
                .' -f '.$fixture['proxy_compose'].' up -d --force-recreate --no-deps traefik',
            )
            ->and(substr_count($commands, 'compose '))->toBe(2)
            ->and($commands)->not->toContain('restart');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('proves the requested loopback APP_PORT exposure is owned by Traefik', function () {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState(ControlPlaneProxyExposure::Loopback);
    $fixture = staticListenerHandoffFixtures($state);

    try {
        $result = runStaticListenerHandoffCommand(
            staticListenerHandoffWriter($fixture)->commandFor($state),
            $fixture,
            ['FAKE_PROXY_BINDING' => '127.0.0.1:8000'],
        );

        expect($result->isSuccessful())->toBeTrue()
            ->and(file_get_contents($fixture['state'].'/coolify_port'))->toBe('')
            ->and(file_get_contents($fixture['state'].'/coolify-proxy_port'))->toBe("127.0.0.1:8000\n");
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('restores exact legacy bytes and listener ownership after an injected handoff failure', function () {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState();
    $fixture = staticListenerHandoffFixtures($state);

    try {
        $result = runStaticListenerHandoffCommand(
            staticListenerHandoffWriter($fixture)->commandFor($state),
            $fixture,
            ['COOLIFY_CONTROL_PLANE_HANDOFF_FAIL_AFTER_COOLIFY' => '1'],
        );

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['proxy_compose']))->toBe($state->staticPredecessorBytes)
            ->and(file_exists($fixture['source_override']))->toBeFalse()
            ->and(file_get_contents($fixture['state'].'/coolify_port'))->toBe("0.0.0.0:8000\n")
            ->and(file_get_contents($fixture['state'].'/coolify-proxy_port'))->toBe('')
            ->and(substr_count(file_get_contents($fixture['log']), '-f '.$fixture['source_override']))->toBe(1);
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('fails closed on a symlinked static configuration or enrollment override before any compose mutation', function (string $pathKey) {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState();
    $fixture = staticListenerHandoffFixtures($state);

    try {
        $outside = $fixture['root'].'/outside-compose.yml';
        file_put_contents($outside, $state->staticPredecessorBytes);
        if (file_exists($fixture[$pathKey]) || is_link($fixture[$pathKey])) {
            unlink($fixture[$pathKey]);
        }
        symlink($outside, $fixture[$pathKey]);

        $result = runStaticListenerHandoffCommand(staticListenerHandoffWriter($fixture)->commandFor($state), $fixture);

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_get_contents($outside))->toBe($state->staticPredecessorBytes)
            ->and(file_get_contents($fixture['log']))->toBe('')
            ->and(is_link($fixture[$pathKey]))->toBeTrue();
    } finally {
        $filesystem->remove($fixture['root']);
    }
})->with([
    'proxy configuration' => 'proxy_compose',
    'source override' => 'source_override',
]);

it('rejects an exposure mismatch and restores the legacy listener instead of accepting a wrong proxy binding', function () {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState();
    $fixture = staticListenerHandoffFixtures($state);

    try {
        $result = runStaticListenerHandoffCommand(
            staticListenerHandoffWriter($fixture)->commandFor($state),
            $fixture,
            ['FAKE_PROXY_BINDING' => '127.0.0.1:8000'],
        );

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['proxy_compose']))->toBe($state->staticPredecessorBytes)
            ->and(file_exists($fixture['source_override']))->toBeFalse()
            ->and(file_get_contents($fixture['state'].'/coolify_port'))->toBe("0.0.0.0:8000\n")
            ->and(file_get_contents($fixture['state'].'/coolify-proxy_port'))->toBe('');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});
