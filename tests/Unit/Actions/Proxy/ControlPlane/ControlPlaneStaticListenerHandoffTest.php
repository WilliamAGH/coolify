<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneStaticListenerHandoff;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function staticListenerHandoffState(
    ControlPlaneProxyExposure $exposure = ControlPlaneProxyExposure::Public,
    string $operationId = 'static-listener-handoff',
): ControlPlaneProxyEnrollmentState {
    return new ControlPlaneProxyEnrollmentState(
        phase: ControlPlaneProxyEnrollmentPhase::Preparing,
        operationId: $operationId,
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
        activeBackendDnsNames: ['coolify-web-a'],
        staticPredecessorBytes: "name: coolify-proxy\nservices:\n  traefik:\n    image: traefik:v3.6\n    ports:\n      - 80:80\n",
        staticReplacementBytes: "name: coolify-proxy\nservices:\n  traefik:\n    image: traefik:v3.6\n    command:\n      - --entrypoints.coolify.address=:8000\n    ports:\n      - 80:80\n      - 8000:8000\n",
        sourceOverrideBytes: "services:\n  coolify:\n    ports: !reset []\n",
        dynamicPredecessorBytes: null,
        dynamicReplacementBytes: "http:\n  routers:\n    coolify-app-port: {}\n",
        createdAt: '2026-07-19T00:00:00Z',
        updatedAt: '2026-07-19T00:00:00Z',
    );
}

/** @return array{root: string, proxy_compose: string, source_compose: string, source_production_compose: string, source_custom_compose: string, source_postgres_upgrade_compose: string, source_override: string, source_override_quarantine: string, source_environment: string, lock: string, attestor_state: string, rollback_journal: string, bin: string, state: string, log: string} */
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
    requested_port=${2:-}
    case "$container" in coolify) private_port=8080 ;; coolify-proxy) private_port=8000 ;; *) exit 1 ;; esac
    if [ -n "$requested_port" ] && [ "$requested_port" != "$private_port/tcp" ]; then exit 1; fi
    while IFS= read -r binding; do
      [ -n "$binding" ] || continue
      printf '%s/tcp -> %s\n' "$private_port" "$binding"
    done < "$state_directory/${container}_port"
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
    file_put_contents($binDirectory.'/python3', <<<'SH'
#!/bin/sh
set -eu

if [ "$#" = 6 ] && [ "$1" = - ] && [ "$2" = --validate-historical ]; then
  if [ "${FAKE_HISTORICAL_EVIDENCE_MODE:-}" = aba-journal ]; then
    validator_source="$FAKE_DOCKER_STATE/historical-evidence-validator.py"
    instrumented_source="$FAKE_DOCKER_STATE/historical-evidence-validator-instrumented.py"
    /bin/cat > "$validator_source"
    /usr/bin/sed '/^        quarantine_path_stat = os.stat/i\
        os.replace(os.environ["FAKE_HISTORICAL_EVIDENCE_ABA_JOURNAL_PATH"], journal_path)
' "$validator_source" > "$instrumented_source"
    exec /usr/bin/python3 "$instrumented_source" "$2" "$3" "$4" "$5" "$6"
  fi
  exec /usr/bin/python3 "$@"
fi
[ "$#" = 3 ] || exit 64
[ "$1" = - ] || exit 64
source_path=$2
destination_path=$3
/bin/cat >/dev/null

case "${FAKE_RENAME_NOREPLACE_MODE:-success}" in
  unavailable) exit 127 ;;
  unsupported) exit 95 ;;
  late-file)
    printf '%s\n' "${FAKE_RENAME_NOREPLACE_DESTINATION_BYTES:-late destination}" > "$destination_path"
    exit 17
    ;;
  late-directory)
    /bin/mkdir "$destination_path"
    exit 17
    ;;
  success) ;;
  *) exit 64 ;;
esac

if [ -e "$destination_path" ] || [ -L "$destination_path" ]; then exit 17; fi
/bin/ln "$source_path" "$destination_path" || exit 17
/bin/rm -f -- "$source_path"
SH
    );
    chmod($binDirectory.'/python3', 0700);

    return [
        'root' => $root,
        'proxy_compose' => $proxyCompose,
        'source_compose' => $sourceCompose,
        'source_production_compose' => $sourceProductionCompose,
        'source_custom_compose' => $sourceCustomCompose,
        'source_postgres_upgrade_compose' => $sourcePostgresUpgradeCompose,
        'source_override' => $sourceOverride,
        'source_override_quarantine' => $sourceDirectory.'/.control-plane-source-override-rollback.'.$state->operationId.'.quarantine',
        'source_environment' => $sourceEnvironment,
        'lock' => $proxyDirectory.'/.handoff.lock',
        'attestor_state' => $root.'/control-plane-attestor',
        'rollback_journal' => $proxyDirectory.'/.control-plane-static-listener-rollback.'.$state->operationId.'.journal',
        'bin' => $binDirectory,
        'state' => $stateDirectory,
        'log' => $stateDirectory.'/commands.log',
    ];
}

/** @param array{proxy_compose: string, source_compose: string, source_production_compose: string, source_custom_compose: string, source_postgres_upgrade_compose: string, source_override: string, source_environment: string, lock: string, attestor_state: string} $fixture */
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
        attestorStateDirectory: $fixture['attestor_state'],
    );
}

/** @param array{bin: string, state: string, log: string, source_override: string, proxy_compose: string} $fixture */
function staticListenerHandoffProcess(string $command, array $fixture, array $environment = []): Process
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

    return $process;
}

/** @param array{bin: string, state: string, log: string, source_override: string, proxy_compose: string} $fixture */
function runStaticListenerHandoffCommand(string $command, array $fixture, array $environment = []): Process
{
    $process = staticListenerHandoffProcess($command, $fixture, $environment);
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
            ->and(is_dir($fixture['attestor_state']))->toBeTrue()
            ->and(fileperms($fixture['attestor_state']) & 0777)->toBe(0700)
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

it('rolls back a post-success static listener handoff once and replays exact legacy ownership', function (): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $fixture = staticListenerHandoffFixtures($state);

    try {
        $writer = staticListenerHandoffWriter($fixture);
        $activation = runStaticListenerHandoffCommand($writer->commandFor($state), $fixture);
        $first = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor($state, $state->operationId, 'test-token'),
            $fixture,
        );
        $rolledBackState = $state
            ->withPhase(ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement, '2026-07-19T00:02:00Z')
            ->withPhase(ControlPlaneProxyEnrollmentPhase::RolledBack, '2026-07-19T00:03:00Z');
        $second = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor($rolledBackState, $rolledBackState->operationId, 'test-token'),
            $fixture,
        );
        $commands = file_get_contents($fixture['log']);

        expect($activation->isSuccessful())->toBeTrue()
            ->and($first->isSuccessful())->toBeTrue()
            ->and(trim($first->getOutput()))->toBe(ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT)
            ->and($second->isSuccessful())->toBeTrue()
            ->and(trim($second->getOutput()))->toBe(ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT)
            ->and(file_get_contents($fixture['proxy_compose']))->toBe($state->staticPredecessorBytes)
            ->and(file_exists($fixture['source_override']))->toBeFalse()
            ->and(file_get_contents($fixture['source_override_quarantine']))->toBe($state->sourceOverrideBytes)
            ->and(file_get_contents($fixture['state'].'/coolify_port'))->toBe("0.0.0.0:8000\n")
            ->and(file_get_contents($fixture['state'].'/coolify-proxy_port'))->toBe('')
            ->and(file_get_contents($fixture['rollback_journal']))->toContain(
                'orphaned_source_override_audit_sha256='.hash('sha256', $state->sourceOverrideBytes),
            )
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolled-back')
            ->and(substr_count($commands, 'compose '))->toBe(4)
            ->and(strrpos($commands, 'traefik'))->toBeLessThan(strrpos($commands, 'coolify'));
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('preserves an exact completed audit artifact and replays it from durable journal provenance', function (): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $fixture = staticListenerHandoffFixtures($state);
    $orphanedOverrideBytes = "services:\n  coolify:\n    environment:\n      COOLIFY_CONTROL_PLANE_OPERATION_ID: stale-operation\n";
    $authorizedSha256 = hash('sha256', $orphanedOverrideBytes);

    try {
        file_put_contents($fixture['source_override'], $orphanedOverrideBytes);
        file_put_contents($fixture['state'].'/coolify_port', '');

        $writer = staticListenerHandoffWriter($fixture);
        $interrupted = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor(
                $state,
                $state->operationId,
                'test-token',
                $authorizedSha256,
            ),
            $fixture,
            ['COOLIFY_CONTROL_PLANE_ROLLBACK_FAIL_AFTER_STATIC' => '1'],
        );

        expect($interrupted->isSuccessful())->toBeFalse()
            ->and(file_exists($fixture['source_override']))->toBeFalse()
            ->and(file_get_contents($fixture['source_override_quarantine']))->toBe($orphanedOverrideBytes)
            ->and(file_get_contents($fixture['proxy_compose']))->toBe($state->staticPredecessorBytes)
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolling-back')
            ->and(file_get_contents($fixture['log']))->toBe('');

        $resumed = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor(
                $state,
                $state->operationId,
                'test-token',
                $authorizedSha256,
            ),
            $fixture,
        );
        $commands = file_get_contents($fixture['log']);

        expect($resumed->isSuccessful())->toBeTrue()
            ->and(trim($resumed->getOutput()))->toBe(ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT)
            ->and(file_get_contents($fixture['source_override_quarantine']))->toBe($orphanedOverrideBytes)
            ->and(file_get_contents($fixture['state'].'/coolify_port'))->toBe("0.0.0.0:8000\n")
            ->and(file_get_contents($fixture['state'].'/coolify-proxy_port'))->toBe('')
            ->and(file_get_contents($fixture['rollback_journal']))->toContain(
                "orphaned_source_override_audit_sha256={$authorizedSha256}",
            )
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolled-back')
            ->and(strrpos($commands, 'traefik'))->toBeLessThan(strrpos($commands, 'coolify'));

        $awaitingAcknowledgement = $state
            ->withPhase(ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement, '2026-07-19T00:02:00Z');
        $terminalReplay = runStaticListenerHandoffCommand(
            $writer->reassertAwaitingRollbackCommandFor(
                $awaitingAcknowledgement,
                $awaitingAcknowledgement->operationId,
                'test-token',
            ),
            $fixture,
        );
        $freshState = staticListenerHandoffState(operationId: 'fresh-static-listener-handoff');
        $freshEnrollment = runStaticListenerHandoffCommand($writer->commandFor($freshState), $fixture);

        expect($terminalReplay->isSuccessful())->toBeTrue()
            ->and(trim($terminalReplay->getOutput()))->toBe(ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT)
            ->and(file_get_contents($fixture['source_override_quarantine']))->toBe($orphanedOverrideBytes)
            ->and($freshEnrollment->isSuccessful())->toBeTrue()
            ->and(trim($freshEnrollment->getOutput()))->toBe(ControlPlaneStaticListenerHandoff::APPLIED_OUTPUT)
            ->and(file_get_contents($fixture['source_override']))->toBe($freshState->sourceOverrideBytes)
            ->and(file_get_contents($fixture['source_override_quarantine']))->toBe($orphanedOverrideBytes);
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('rejects tampered or unpaired historical rollback evidence before a fresh enrollment mutation', function (): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $fixture = staticListenerHandoffFixtures($state);
    $orphanedOverrideBytes = "services:\n  coolify:\n    environment:\n      COOLIFY_CONTROL_PLANE_OPERATION_ID: retained-evidence\n";

    try {
        file_put_contents($fixture['source_override'], $orphanedOverrideBytes);
        file_put_contents($fixture['state'].'/coolify_port', '');
        $writer = staticListenerHandoffWriter($fixture);
        expect(runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor(
                $state,
                $state->operationId,
                'test-token',
                hash('sha256', $orphanedOverrideBytes),
            ),
            $fixture,
        )->isSuccessful())->toBeTrue();

        $freshState = staticListenerHandoffState(operationId: 'fresh-after-retained-evidence');
        $freshCommand = $writer->commandFor($freshState);
        $journalBytes = file_get_contents($fixture['rollback_journal']);
        $commandsAfterRollback = file_get_contents($fixture['log']);

        file_put_contents(
            $fixture['rollback_journal'],
            str_replace('phase=rolled-back', 'phase=rolling-back', $journalBytes),
        );
        $inProgress = runStaticListenerHandoffCommand($freshCommand, $fixture);
        file_put_contents($fixture['rollback_journal'], $journalBytes);

        file_put_contents($fixture['source_override_quarantine'], $orphanedOverrideBytes."tampered\n");
        $tampered = runStaticListenerHandoffCommand($freshCommand, $fixture);
        file_put_contents($fixture['source_override_quarantine'], $orphanedOverrideBytes);

        $abaJournalPath = $fixture['rollback_journal'].'.aba';
        file_put_contents($abaJournalPath, $journalBytes);
        chmod($abaJournalPath, 0600);
        $abaSwapped = runStaticListenerHandoffCommand(
            $freshCommand,
            $fixture,
            [
                'FAKE_HISTORICAL_EVIDENCE_MODE' => 'aba-journal',
                'FAKE_HISTORICAL_EVIDENCE_ABA_JOURNAL_PATH' => $abaJournalPath,
            ],
        );
        file_put_contents($fixture['rollback_journal'], $journalBytes);
        chmod($fixture['rollback_journal'], 0600);

        unlink($fixture['rollback_journal']);
        $unpaired = runStaticListenerHandoffCommand($freshCommand, $fixture);
        file_put_contents($fixture['rollback_journal'], $journalBytes);
        chmod($fixture['rollback_journal'], 0600);

        $hardlinkPath = $fixture['source_override_quarantine'].'.hardlink';
        link($fixture['source_override_quarantine'], $hardlinkPath);
        $hardlinked = runStaticListenerHandoffCommand($freshCommand, $fixture);
        unlink($hardlinkPath);

        $foreignArtifactPath = dirname($fixture['source_override'])
            .'/.control-plane-source-override-rollback.foreign';
        file_put_contents($foreignArtifactPath, "foreign\n");
        $foreign = runStaticListenerHandoffCommand($freshCommand, $fixture);

        expect($inProgress->isSuccessful())->toBeFalse()
            ->and($tampered->isSuccessful())->toBeFalse()
            ->and($abaSwapped->isSuccessful())->toBeFalse()
            ->and($unpaired->isSuccessful())->toBeFalse()
            ->and($hardlinked->isSuccessful())->toBeFalse()
            ->and($foreign->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['source_override_quarantine']))->toBe($orphanedOverrideBytes)
            ->and(file_get_contents($fixture['rollback_journal']))->toBe($journalBytes)
            ->and(file_exists($fixture['source_override']))->toBeFalse()
            ->and(file_get_contents($fixture['proxy_compose']))->toBe($state->staticPredecessorBytes)
            ->and(file_get_contents($fixture['log']))->toBe($commandsAfterRollback);
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('rejects a historical evidence FIFO without blocking the enrollment lock', function (string $pathKey): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $fixture = staticListenerHandoffFixtures($state);
    $orphanedOverrideBytes = "services:\n  coolify:\n    environment:\n      COOLIFY_CONTROL_PLANE_OPERATION_ID: fifo-evidence\n";

    try {
        file_put_contents($fixture['source_override'], $orphanedOverrideBytes);
        file_put_contents($fixture['state'].'/coolify_port', '');
        $writer = staticListenerHandoffWriter($fixture);
        expect(runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor(
                $state,
                $state->operationId,
                'test-token',
                hash('sha256', $orphanedOverrideBytes),
            ),
            $fixture,
        )->isSuccessful())->toBeTrue();

        $evidencePath = $fixture[$pathKey];
        $evidenceBackupPath = $evidencePath.'.regular';
        rename($evidencePath, $evidenceBackupPath);
        (new Process(['mkfifo', $evidencePath]))->mustRun();
        $commandsAfterRollback = file_get_contents($fixture['log']);
        $startedAt = microtime(true);
        $result = runStaticListenerHandoffCommand(
            $writer->commandFor(staticListenerHandoffState(operationId: 'fresh-after-fifo-evidence')),
            $fixture,
        );
        $elapsedSeconds = microtime(true) - $startedAt;
        unlink($evidencePath);
        rename($evidenceBackupPath, $evidencePath);

        expect($result->isSuccessful())->toBeFalse()
            ->and($elapsedSeconds)->toBeLessThan(5.0)
            ->and(file_get_contents($fixture['source_override_quarantine']))->toBe($orphanedOverrideBytes)
            ->and(file_exists($fixture['source_override']))->toBeFalse()
            ->and(file_get_contents($fixture['log']))->toBe($commandsAfterRollback);
    } finally {
        $filesystem->remove($fixture['root']);
    }
})->with([
    'quarantine FIFO' => 'source_override_quarantine',
    'journal FIFO' => 'rollback_journal',
]);

it('rejects an unused orphan authorization before creating a rollback journal', function (bool $managedOverridePresent): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $fixture = staticListenerHandoffFixtures($state);

    try {
        if ($managedOverridePresent) {
            file_put_contents($fixture['source_override'], $state->sourceOverrideBytes);
        }
        $sourceOverrideBefore = $managedOverridePresent
            ? file_get_contents($fixture['source_override'])
            : null;
        $result = runStaticListenerHandoffCommand(
            staticListenerHandoffWriter($fixture)->rollbackCommandFor(
                $state,
                $state->operationId,
                'test-token',
                str_repeat('a', 64),
            ),
            $fixture,
        );

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_exists($fixture['rollback_journal']))->toBeFalse()
            ->and(file_exists($fixture['source_override_quarantine']))->toBeFalse()
            ->and(file_exists($fixture['source_override']))->toBe($managedOverridePresent)
            ->and($managedOverridePresent ? file_get_contents($fixture['source_override']) : null)
            ->toBe($sourceOverrideBefore)
            ->and(file_get_contents($fixture['proxy_compose']))->toBe($state->staticPredecessorBytes)
            ->and(file_get_contents($fixture['log']))->toBe('');
    } finally {
        $filesystem->remove($fixture['root']);
    }
})->with([
    'managed override present' => true,
    'override absent' => false,
]);

it('replays a crash after completed audit preservation without repeating authorization', function (): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $fixture = staticListenerHandoffFixtures($state);
    $orphanedOverrideBytes = "services:\n  coolify:\n    environment:\n      COOLIFY_CONTROL_PLANE_OPERATION_ID: crash-before-journal-completion\n";
    $authorizedSha256 = hash('sha256', $orphanedOverrideBytes);

    try {
        file_put_contents($fixture['source_override'], $orphanedOverrideBytes);
        file_put_contents($fixture['state'].'/coolify_port', '');
        $writer = staticListenerHandoffWriter($fixture);

        $interrupted = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor(
                $state,
                $state->operationId,
                'test-token',
                $authorizedSha256,
            ),
            $fixture,
            ['COOLIFY_CONTROL_PLANE_ROLLBACK_FAIL_AFTER_AUDIT_COMPLETION' => '1'],
        );

        expect($interrupted->isSuccessful())->toBeFalse()
            ->and(file_exists($fixture['source_override']))->toBeFalse()
            ->and(file_get_contents($fixture['source_override_quarantine']))->toBe($orphanedOverrideBytes)
            ->and(file_get_contents($fixture['rollback_journal']))->toContain(
                "orphaned_source_override_audit_sha256={$authorizedSha256}",
            )
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolling-back')
            ->and(file_get_contents($fixture['state'].'/coolify_port'))->toBe("0.0.0.0:8000\n")
            ->and(file_get_contents($fixture['state'].'/coolify-proxy_port'))->toBe('');

        $resumed = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor($state, $state->operationId, 'test-token'),
            $fixture,
        );

        expect($resumed->isSuccessful())->toBeTrue()
            ->and(trim($resumed->getOutput()))->toBe(ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT)
            ->and(file_get_contents($fixture['source_override_quarantine']))->toBe($orphanedOverrideBytes)
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolled-back');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('preserves a pathname swap in quarantine without allowing durable authorization replacement', function (): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $fixture = staticListenerHandoffFixtures($state);
    $authorizedOverrideBytes = "services:\n  coolify:\n    environment:\n      COOLIFY_CONTROL_PLANE_OPERATION_ID: authorized-stale-operation\n";
    $swappedOverrideBytes = "services:\n  coolify:\n    environment:\n      COOLIFY_CONTROL_PLANE_OPERATION_ID: pathname-swap\n";
    $swapMarker = $fixture['state'].'/source-override-swapped';
    $swapEnvironment = [
        'SWAP_SOURCE_PATH' => $fixture['source_override'],
        'SWAP_ONCE_PATH' => $swapMarker,
        'SWAP_REPLACEMENT_BASE64' => base64_encode($swappedOverrideBytes),
    ];

    try {
        file_put_contents($fixture['source_override'], $authorizedOverrideBytes);
        file_put_contents($fixture['state'].'/coolify_port', '');
        file_put_contents($fixture['bin'].'/sync', <<<'SH'
#!/bin/sh
set -eu

if [ "$#" -eq 1 ] && [ "$1" = "$SWAP_SOURCE_PATH" ] && [ ! -e "$SWAP_ONCE_PATH" ]; then
  swap_candidate="$SWAP_SOURCE_PATH.pathname-swap"
  printf %s "$SWAP_REPLACEMENT_BASE64" | base64 -d > "$swap_candidate"
  chmod 600 "$swap_candidate"
  /bin/mv -f -- "$swap_candidate" "$SWAP_SOURCE_PATH"
  : > "$SWAP_ONCE_PATH"
fi
exec /bin/sync "$@"
SH
        );
        chmod($fixture['bin'].'/sync', 0700);

        $writer = staticListenerHandoffWriter($fixture);
        $swapped = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor(
                $state,
                $state->operationId,
                'test-token',
                hash('sha256', $authorizedOverrideBytes),
            ),
            $fixture,
            $swapEnvironment,
        );

        expect($swapped->isSuccessful())->toBeFalse()
            ->and(file_exists($swapMarker))->toBeTrue()
            ->and(file_exists($fixture['source_override']))->toBeFalse()
            ->and(file_get_contents($fixture['source_override_quarantine']))->toBe($swappedOverrideBytes)
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolling-back')
            ->and(file_get_contents($fixture['log']))->toBe('');

        $reauthorized = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor(
                $state,
                $state->operationId,
                'test-token',
                hash('sha256', $swappedOverrideBytes),
            ),
            $fixture,
            $swapEnvironment,
        );

        expect($reauthorized->isSuccessful())->toBeFalse()
            ->and(file_exists($fixture['source_override']))->toBeFalse()
            ->and(file_get_contents($fixture['source_override_quarantine']))->toBe($swappedOverrideBytes)
            ->and(file_get_contents($fixture['rollback_journal']))->toContain(
                'orphaned_source_override_audit_sha256='.hash('sha256', $authorizedOverrideBytes),
            )
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolling-back');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('preserves both paths when the no-clobber destination appears late', function (
    string $mode,
    bool $destinationIsDirectory,
): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $fixture = staticListenerHandoffFixtures($state);
    $authorizedOverrideBytes = "services:\n  coolify:\n    environment:\n      COOLIFY_CONTROL_PLANE_OPERATION_ID: no-clobber-source\n";
    $destinationBytes = "late destination must survive\n";

    try {
        file_put_contents($fixture['source_override'], $authorizedOverrideBytes);
        file_put_contents($fixture['state'].'/coolify_port', '');
        $result = runStaticListenerHandoffCommand(
            staticListenerHandoffWriter($fixture)->rollbackCommandFor(
                $state,
                $state->operationId,
                'test-token',
                hash('sha256', $authorizedOverrideBytes),
            ),
            $fixture,
            [
                'FAKE_RENAME_NOREPLACE_MODE' => $mode,
                'FAKE_RENAME_NOREPLACE_DESTINATION_BYTES' => trim($destinationBytes),
            ],
        );

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['source_override']))->toBe($authorizedOverrideBytes)
            ->and(is_dir($fixture['source_override_quarantine']))->toBe($destinationIsDirectory)
            ->and($destinationIsDirectory
                ? null
                : file_get_contents($fixture['source_override_quarantine']))->toBe(
                    $destinationIsDirectory ? null : $destinationBytes,
                )
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolling-back')
            ->and(file_get_contents($fixture['log']))->toBe('');
    } finally {
        $filesystem->remove($fixture['root']);
    }
})->with([
    'late regular file' => ['late-file', false],
    'late directory' => ['late-directory', true],
]);

it('preserves the authorized source when atomic no-clobber support is unavailable', function (string $mode): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $fixture = staticListenerHandoffFixtures($state);
    $authorizedOverrideBytes = "services:\n  coolify:\n    environment:\n      COOLIFY_CONTROL_PLANE_OPERATION_ID: helper-unavailable\n";

    try {
        file_put_contents($fixture['source_override'], $authorizedOverrideBytes);
        file_put_contents($fixture['state'].'/coolify_port', '');
        $result = runStaticListenerHandoffCommand(
            staticListenerHandoffWriter($fixture)->rollbackCommandFor(
                $state,
                $state->operationId,
                'test-token',
                hash('sha256', $authorizedOverrideBytes),
            ),
            $fixture,
            ['FAKE_RENAME_NOREPLACE_MODE' => $mode],
        );

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['source_override']))->toBe($authorizedOverrideBytes)
            ->and(file_exists($fixture['source_override_quarantine']))->toBeFalse()
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolling-back')
            ->and(file_get_contents($fixture['log']))->toBe('');
    } finally {
        $filesystem->remove($fixture['root']);
    }
})->with([
    'python helper unavailable' => 'unavailable',
    'renameat2 unsupported' => 'unsupported',
]);

it('preserves a final pathname swap as audit evidence instead of unlinking it', function (): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $fixture = staticListenerHandoffFixtures($state);
    $authorizedOverrideBytes = "services:\n  coolify:\n    environment:\n      COOLIFY_CONTROL_PLANE_OPERATION_ID: authorized-final-cleanup\n";
    $swappedOverrideBytes = "services:\n  coolify:\n    environment:\n      COOLIFY_CONTROL_PLANE_OPERATION_ID: final-pathname-swap\n";
    $authorizedSha256 = hash('sha256', $authorizedOverrideBytes);
    $syncCountPath = $fixture['state'].'/quarantine-sync-count';
    $swapEnvironment = [
        'SWAP_QUARANTINE_PATH' => $fixture['source_override_quarantine'],
        'SWAP_SYNC_COUNT_PATH' => $syncCountPath,
        'SWAP_REPLACEMENT_BASE64' => base64_encode($swappedOverrideBytes),
    ];

    try {
        file_put_contents($fixture['source_override'], $authorizedOverrideBytes);
        file_put_contents($fixture['state'].'/coolify_port', '');
        file_put_contents($fixture['bin'].'/sync', <<<'SH'
#!/bin/sh
set -eu

if [ "$#" -eq 1 ] && [ "$1" = "$SWAP_QUARANTINE_PATH" ]; then
  sync_count=0
  if [ -e "$SWAP_SYNC_COUNT_PATH" ]; then IFS= read -r sync_count < "$SWAP_SYNC_COUNT_PATH"; fi
  sync_count=$((sync_count + 1))
  printf '%s\n' "$sync_count" > "$SWAP_SYNC_COUNT_PATH"
  if [ "$sync_count" = 2 ]; then
    swap_candidate="$SWAP_QUARANTINE_PATH.pathname-swap"
    printf %s "$SWAP_REPLACEMENT_BASE64" | base64 -d > "$swap_candidate"
    chmod 600 "$swap_candidate"
    /bin/mv -f -- "$swap_candidate" "$SWAP_QUARANTINE_PATH"
  fi
fi
exec /bin/sync "$@"
SH
        );
        chmod($fixture['bin'].'/sync', 0700);
        $writer = staticListenerHandoffWriter($fixture);

        $swapped = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor(
                $state,
                $state->operationId,
                'test-token',
                $authorizedSha256,
            ),
            $fixture,
            $swapEnvironment,
        );

        expect($swapped->isSuccessful())->toBeFalse()
            ->and(trim((string) file_get_contents($syncCountPath)))->toBe('2')
            ->and(file_exists($fixture['source_override']))->toBeFalse()
            ->and(file_get_contents($fixture['source_override_quarantine']))->toBe($swappedOverrideBytes)
            ->and(file_get_contents($fixture['rollback_journal']))->toContain(
                "orphaned_source_override_audit_sha256={$authorizedSha256}",
            )
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolling-back')
            ->and(file_get_contents($fixture['state'].'/coolify_port'))->toBe("0.0.0.0:8000\n")
            ->and(file_get_contents($fixture['state'].'/coolify-proxy_port'))->toBe('');

        $replayed = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor(
                $state,
                $state->operationId,
                'test-token',
                hash('sha256', $swappedOverrideBytes),
            ),
            $fixture,
            $swapEnvironment,
        );

        expect($replayed->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['source_override_quarantine']))->toBe($swappedOverrideBytes)
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolling-back');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('fails closed on orphaned source override drift without its exact lowercase authorization', function (): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $fixture = staticListenerHandoffFixtures($state);
    $orphanedOverrideBytes = "services:\n  coolify:\n    environment:\n      COOLIFY_CONTROL_PLANE_OPERATION_ID: stale-operation\n";

    try {
        file_put_contents($fixture['source_override'], $orphanedOverrideBytes);
        file_put_contents($fixture['state'].'/coolify_port', '');
        $writer = staticListenerHandoffWriter($fixture);

        $unauthorized = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor($state, $state->operationId, 'test-token'),
            $fixture,
        );
        $wrongAuthorization = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor(
                $state,
                $state->operationId,
                'test-token',
                str_repeat('0', 64),
            ),
            $fixture,
        );

        expect($unauthorized->isSuccessful())->toBeFalse()
            ->and($wrongAuthorization->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['source_override']))->toBe($orphanedOverrideBytes)
            ->and(file_exists($fixture['source_override_quarantine']))->toBeFalse()
            ->and(file_get_contents($fixture['proxy_compose']))->toBe($state->staticPredecessorBytes)
            ->and(file_exists($fixture['rollback_journal']))->toBeFalse()
            ->and(file_get_contents($fixture['log']))->toBe('')
            ->and(fn (): string => $writer->rollbackCommandFor(
                $state,
                $state->operationId,
                'test-token',
                str_repeat('A', 64),
            ))->toThrow(InvalidArgumentException::class, 'exact lowercase SHA-256');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('limits orphaned source override authorization to durable rolling-back state', function (): void {
    $rollingBack = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $awaitingAcknowledgement = $rollingBack
        ->withPhase(ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement, '2026-07-19T00:02:00Z');
    $rolledBack = $awaitingAcknowledgement
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RolledBack, '2026-07-19T00:03:00Z');
    $writer = new ControlPlaneStaticListenerHandoff;
    $authorization = str_repeat('a', 64);

    expect($writer->rollbackCommandFor(
        $rollingBack,
        $rollingBack->operationId,
        'test-token',
        $authorization,
    ))->toContain("authorized_orphaned_source_override_sha256='{$authorization}'")
        ->and(fn (): string => $writer->rollbackCommandFor(
            $awaitingAcknowledgement,
            $awaitingAcknowledgement->operationId,
            'test-token',
            $authorization,
        ))->toThrow(InvalidArgumentException::class, 'requires durable rolling-back state')
        ->and(fn (): string => $writer->rollbackCommandFor(
            $rolledBack,
            $rolledBack->operationId,
            'test-token',
            $authorization,
        ))->toThrow(InvalidArgumentException::class, 'invalid after static rollback');
});

it('fails closed when a delayed activation conflicts with retained rollback evidence', function (): void {
    $filesystem = new Filesystem;
    $rollingBackState = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $awaitingState = $rollingBackState
        ->withPhase(ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement, '2026-07-19T00:02:00Z');
    $fixture = staticListenerHandoffFixtures($rollingBackState);

    try {
        $writer = staticListenerHandoffWriter($fixture);
        expect(runStaticListenerHandoffCommand($writer->commandFor($rollingBackState), $fixture)->isSuccessful())->toBeTrue();
        expect(runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor($rollingBackState, $rollingBackState->operationId, 'test-token'),
            $fixture,
        )->isSuccessful())->toBeTrue();

        file_put_contents($fixture['proxy_compose'], $rollingBackState->staticReplacementBytes);
        file_put_contents($fixture['source_override'], $rollingBackState->sourceOverrideBytes);
        file_put_contents($fixture['state'].'/coolify_port', '');
        file_put_contents($fixture['state'].'/coolify-proxy_port', "0.0.0.0:8000\n");
        $proxyBytesAfterDelayedActivation = file_get_contents($fixture['proxy_compose']);
        $sourceOverrideBytesAfterDelayedActivation = file_get_contents($fixture['source_override']);
        $reassertedRollback = runStaticListenerHandoffCommand(
            $writer->reassertAwaitingRollbackCommandFor($awaitingState, $awaitingState->operationId, 'test-token'),
            $fixture,
        );

        expect($proxyBytesAfterDelayedActivation)->toBe($rollingBackState->staticReplacementBytes)
            ->and($sourceOverrideBytesAfterDelayedActivation)->toBe($rollingBackState->sourceOverrideBytes)
            ->and($reassertedRollback->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['proxy_compose']))->toBe($rollingBackState->staticReplacementBytes)
            ->and(file_get_contents($fixture['source_override']))->toBe($rollingBackState->sourceOverrideBytes)
            ->and(file_get_contents($fixture['source_override_quarantine']))->toBe($rollingBackState->sourceOverrideBytes)
            ->and(file_get_contents($fixture['state'].'/coolify_port'))->toBe('')
            ->and(file_get_contents($fixture['state'].'/coolify-proxy_port'))->toBe("0.0.0.0:8000\n")
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolled-back');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('fails closed when awaiting rollback reassertion has no durable rollback journal', function (): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z')
        ->withPhase(ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement, '2026-07-19T00:02:00Z');
    $fixture = staticListenerHandoffFixtures($state);

    try {
        $result = runStaticListenerHandoffCommand(
            staticListenerHandoffWriter($fixture)->reassertAwaitingRollbackCommandFor(
                $state,
                $state->operationId,
                'test-token',
            ),
            $fixture,
        );

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_exists($fixture['rollback_journal']))->toBeFalse()
            ->and(file_get_contents($fixture['proxy_compose']))->toBe($state->staticPredecessorBytes)
            ->and(file_exists($fixture['source_override']))->toBeFalse()
            ->and(file_get_contents($fixture['log']))->toBe('');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('keeps completed evidence unchanged across repeated delayed-activation reassertions', function (): void {
    $filesystem = new Filesystem;
    $rollingBackState = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $awaitingState = $rollingBackState
        ->withPhase(ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement, '2026-07-19T00:02:00Z');
    $fixture = staticListenerHandoffFixtures($rollingBackState);

    try {
        $writer = staticListenerHandoffWriter($fixture);
        expect(runStaticListenerHandoffCommand($writer->commandFor($rollingBackState), $fixture)->isSuccessful())->toBeTrue()
            ->and(runStaticListenerHandoffCommand(
                $writer->rollbackCommandFor($rollingBackState, $rollingBackState->operationId, 'test-token'),
                $fixture,
            )->isSuccessful())->toBeTrue();

        file_put_contents($fixture['proxy_compose'], $rollingBackState->staticReplacementBytes);
        file_put_contents($fixture['source_override'], $rollingBackState->sourceOverrideBytes);
        file_put_contents($fixture['state'].'/coolify_port', '');
        file_put_contents($fixture['state'].'/coolify-proxy_port', "0.0.0.0:8000\n");

        $interrupted = runStaticListenerHandoffCommand(
            $writer->reassertAwaitingRollbackCommandFor($awaitingState, $awaitingState->operationId, 'test-token'),
            $fixture,
            ['COOLIFY_CONTROL_PLANE_ROLLBACK_FAIL_AFTER_TRAEFIK' => '1'],
        );
        expect($interrupted->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolled-back')
            ->and(file_get_contents($fixture['state'].'/coolify_port'))->toBe('')
            ->and(file_get_contents($fixture['state'].'/coolify-proxy_port'))->toBe("0.0.0.0:8000\n");

        $resumed = runStaticListenerHandoffCommand(
            $writer->reassertAwaitingRollbackCommandFor($awaitingState, $awaitingState->operationId, 'test-token'),
            $fixture,
        );

        expect($resumed->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['proxy_compose']))->toBe($rollingBackState->staticReplacementBytes)
            ->and(file_get_contents($fixture['source_override']))->toBe($rollingBackState->sourceOverrideBytes)
            ->and(file_get_contents($fixture['source_override_quarantine']))->toBe($rollingBackState->sourceOverrideBytes)
            ->and(file_get_contents($fixture['state'].'/coolify_port'))->toBe('')
            ->and(file_get_contents($fixture['state'].'/coolify-proxy_port'))->toBe("0.0.0.0:8000\n")
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolled-back');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('rejects a queued activation after the durable rollback tombstone exists', function (): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $fixture = staticListenerHandoffFixtures($state);

    try {
        $writer = staticListenerHandoffWriter($fixture);
        $activationCommand = $writer->commandFor($state);
        expect(runStaticListenerHandoffCommand($activationCommand, $fixture)->isSuccessful())->toBeTrue()
            ->and(runStaticListenerHandoffCommand(
                $writer->rollbackCommandFor($state, $state->operationId, 'test-token'),
                $fixture,
            )->isSuccessful())->toBeTrue();
        $rollbackJournalBytes = file_get_contents($fixture['rollback_journal']);
        $commandsAfterRollback = file_get_contents($fixture['log']);
        unlink($fixture['rollback_journal']);

        $flockReady = $fixture['state'].'/flock-ready';
        $flockRelease = $fixture['state'].'/flock-release';
        file_put_contents($fixture['bin'].'/flock', <<<'SH'
#!/bin/sh
set -eu
: > "$FAKE_FLOCK_READY"
while [ ! -e "$FAKE_FLOCK_RELEASE" ]; do sleep 0.01; done
SH
        );
        chmod($fixture['bin'].'/flock', 0700);

        $queuedActivation = staticListenerHandoffProcess($activationCommand, $fixture, [
            'FAKE_FLOCK_READY' => $flockReady,
            'FAKE_FLOCK_RELEASE' => $flockRelease,
        ]);
        $queuedActivation->start();
        $deadline = microtime(true) + 2;
        while (! file_exists($flockReady) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        $reachedFlock = file_exists($flockReady);
        file_put_contents($fixture['rollback_journal'], $rollbackJournalBytes);
        touch($flockRelease);
        $queuedActivation->wait();

        expect($reachedFlock)->toBeTrue()
            ->and($queuedActivation->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['proxy_compose']))->toBe($state->staticPredecessorBytes)
            ->and(file_exists($fixture['source_override']))->toBeFalse()
            ->and(file_get_contents($fixture['state'].'/coolify_port'))->toBe("0.0.0.0:8000\n")
            ->and(file_get_contents($fixture['state'].'/coolify-proxy_port'))->toBe('')
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolled-back')
            ->and(file_get_contents($fixture['log']))->toBe($commandsAfterRollback);
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('fails closed before mutation when rollback artifacts drift or belong to another owner', function (string $pathKey): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $fixture = staticListenerHandoffFixtures($state);

    try {
        $writer = staticListenerHandoffWriter($fixture);
        expect(runStaticListenerHandoffCommand($writer->commandFor($state), $fixture)->isSuccessful())->toBeTrue();
        file_put_contents($fixture[$pathKey], "foreign rollback artifact\n");
        $commandsBeforeRollback = file_get_contents($fixture['log']);

        $result = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor($state, $state->operationId, 'test-token'),
            $fixture,
        );

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture[$pathKey]))->toBe("foreign rollback artifact\n")
            ->and(file_get_contents($fixture['log']))->toBe($commandsBeforeRollback)
            ->and(file_exists($fixture['rollback_journal']))->toBeFalse()
            ->and(fn (): string => $writer->rollbackCommandFor($state, 'foreign-operation', 'foreign-token'))
            ->toThrow(InvalidArgumentException::class, 'owned by another operation');
    } finally {
        $filesystem->remove($fixture['root']);
    }
})->with([
    'Traefik Compose drift' => 'proxy_compose',
    'source override drift' => 'source_override',
]);

it('fails closed before mutation when post-success listener ownership drifts', function (): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $fixture = staticListenerHandoffFixtures($state);

    try {
        $writer = staticListenerHandoffWriter($fixture);
        expect(runStaticListenerHandoffCommand($writer->commandFor($state), $fixture)->isSuccessful())->toBeTrue();
        file_put_contents($fixture['state'].'/coolify-proxy_port', "127.0.0.1:8000\n");
        $commandsBeforeRollback = file_get_contents($fixture['log']);

        $result = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor($state, $state->operationId, 'test-token'),
            $fixture,
        );

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['proxy_compose']))->toBe($state->staticReplacementBytes)
            ->and(file_get_contents($fixture['source_override']))->toBe($state->sourceOverrideBytes)
            ->and(file_get_contents($fixture['log']))->toBe($commandsBeforeRollback)
            ->and(file_exists($fixture['rollback_journal']))->toBeFalse();
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('resumes an interrupted static listener rollback from its durable journal', function (): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $fixture = staticListenerHandoffFixtures($state);

    try {
        $writer = staticListenerHandoffWriter($fixture);
        expect(runStaticListenerHandoffCommand($writer->commandFor($state), $fixture)->isSuccessful())->toBeTrue();
        $interrupted = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor($state, $state->operationId, 'test-token'),
            $fixture,
            ['COOLIFY_CONTROL_PLANE_ROLLBACK_FAIL_AFTER_TRAEFIK' => '1'],
        );

        expect($interrupted->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['proxy_compose']))->toBe($state->staticPredecessorBytes)
            ->and(file_exists($fixture['source_override']))->toBeFalse()
            ->and(file_get_contents($fixture['state'].'/coolify_port'))->toBe('')
            ->and(file_get_contents($fixture['state'].'/coolify-proxy_port'))->toBe('')
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolling-back');

        $resumed = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor($state, $state->operationId, 'test-token'),
            $fixture,
        );

        expect($resumed->isSuccessful())->toBeTrue()
            ->and(trim($resumed->getOutput()))->toBe(ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT)
            ->and(file_get_contents($fixture['state'].'/coolify_port'))->toBe("0.0.0.0:8000\n")
            ->and(file_get_contents($fixture['state'].'/coolify-proxy_port'))->toBe('')
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolled-back');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('reclaims an exact interrupted rollback journal from the legacy runtime uid before replay', function (): void {
    $filesystem = new Filesystem;
    $state = staticListenerHandoffState()
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T00:01:00Z');
    $fixture = staticListenerHandoffFixtures($state);

    try {
        $writer = staticListenerHandoffWriter($fixture);
        expect(runStaticListenerHandoffCommand($writer->commandFor($state), $fixture)->isSuccessful())->toBeTrue();
        $interrupted = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor($state, $state->operationId, 'test-token'),
            $fixture,
            ['COOLIFY_CONTROL_PLANE_ROLLBACK_FAIL_AFTER_TRAEFIK' => '1'],
        );
        expect($interrupted->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolling-back');

        file_put_contents($fixture['bin'].'/stat', <<<'SH'
#!/bin/sh
set -eu

last=
for argument in "$@"; do last=$argument; done
if [ "$last" = "$LEGACY_JOURNAL_PATH" ] && [ ! -e "$LEGACY_JOURNAL_STATE/reclaimed" ]; then
  case "$*" in
    *%u*) printf '%s\n' 9999; exit 0 ;;
  esac
fi
exec /usr/bin/stat "$@"
SH
        );
        file_put_contents($fixture['bin'].'/chown', <<<'SH'
#!/bin/sh
set -eu

[ "$#" = 3 ] || exit 1
[ "$1" = -h ] || exit 1
[ "$2" = "$(id -u):$(id -g)" ] || exit 1
[ "$3" = "$LEGACY_JOURNAL_PATH" ] || exit 1
: > "$LEGACY_JOURNAL_STATE/reclaimed"
SH
        );
        chmod($fixture['bin'].'/stat', 0700);
        chmod($fixture['bin'].'/chown', 0700);

        $resumed = runStaticListenerHandoffCommand(
            $writer->rollbackCommandFor($state, $state->operationId, 'test-token'),
            $fixture,
            [
                'LEGACY_JOURNAL_PATH' => $fixture['rollback_journal'],
                'LEGACY_JOURNAL_STATE' => $fixture['state'],
            ],
        );

        expect($resumed->isSuccessful())->toBeTrue()
            ->and(trim($resumed->getOutput()))->toBe(ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT)
            ->and(file_exists($fixture['state'].'/reclaimed'))->toBeTrue()
            ->and(fileperms($fixture['rollback_journal']) & 0777)->toBe(0600)
            ->and(file_get_contents($fixture['rollback_journal']))->toContain('phase=rolled-back');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});
