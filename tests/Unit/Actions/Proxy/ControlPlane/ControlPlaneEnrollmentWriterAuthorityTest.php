<?php

use App\Actions\Proxy\ControlPlane\BootstrapControlPlaneEnrollmentWriterAuthority;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneEnrollmentWriterIdentity;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\InspectControlPlaneEnrollmentWriter;
use App\Actions\Proxy\ControlPlane\InspectControlPlaneEnrollmentWriterAuthority;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentMutation;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriterAuthority;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function enrollmentWriterAuthorityState(): ControlPlaneProxyEnrollmentState
{
    $dynamicBytes = "http:\n  routers:\n    coolify: {}\n";

    return new ControlPlaneProxyEnrollmentState(
        phase: ControlPlaneProxyEnrollmentPhase::Activating,
        operationId: 'enrollment-writer-authority',
        tokenSha256: hash('sha256', 'enrollment-writer-authority-token'),
        serverId: 0,
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
        managedFilename: ControlPlaneDynamicConfiguration::MANAGED_FILENAME,
        dynamicRevision: 1,
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        expectedMember: 'blue',
        expectedRevision: 'release-1',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        activeBackendDnsNames: ['coolify-web-a'],
        staticPredecessorBytes: "services:\n  traefik: {}\n",
        staticReplacementBytes: "services:\n  traefik:\n    ports: ['80:80']\n",
        sourceOverrideBytes: "services:\n  coolify:\n    ports: !reset []\n",
        dynamicPredecessorBytes: null,
        dynamicReplacementBytes: $dynamicBytes,
        createdAt: '2026-07-20T12:00:00Z',
        updatedAt: '2026-07-20T12:00:00Z',
    );
}

/** @return array{mutation: ManagedTraefikDocumentMutation, root: string, bin: string, state: string, log: string, docker_id: string, image_id: string} */
function enrollmentWriterAuthorityFixture(): array
{
    $root = sys_get_temp_dir().'/coolify-enrollment-writer-authority-'.bin2hex(random_bytes(8));
    $bin = $root.'/bin';
    $dynamicDirectory = $root.'/proxy/dynamic';
    $stateDirectory = $root.'/proxy/.control-plane-managed-traefik';
    $mutation = new ManagedTraefikDocumentMutation(
        dynamicDirectory: $dynamicDirectory,
        stateDirectory: $stateDirectory,
        filename: ControlPlaneDynamicConfiguration::MANAGED_FILENAME,
        operationId: 'enrollment-writer-authority',
        revision: 1,
        expectedSha256: null,
        expectedOperationId: null,
        expectedRevision: null,
        replacementBytes: enrollmentWriterAuthorityState()->dynamicReplacementBytes,
    );
    (new Filesystem)->mkdir([$bin, $dynamicDirectory, $stateDirectory], 0700);
    file_put_contents($mutation->documentPath(), $mutation->replacementBytes);
    file_put_contents($mutation->sidecarPath(), $mutation->replacementSidecar());
    file_put_contents($mutation->lockPath(), '');
    file_put_contents($root.'/docker-state', implode("\n", [
        'docker_id='.str_repeat('a', 64),
        'container_name=coolify',
        'image_id=sha256:'.str_repeat('b', 64),
        'running=true',
        '',
    ]));
    file_put_contents($root.'/docker.log', '');
    file_put_contents($bin.'/docker', <<<'SH'
#!/bin/sh
set -eu

state=$FAKE_DOCKER_STATE
log=$FAKE_DOCKER_LOG
command=$1
shift
case "$command" in
  inspect)
    selector=
    for argument in "$@"; do selector=$argument; done
    printf 'inspect %s\n' "$selector" >> "$log"
    if [ "${FAKE_DOCKER_INSPECTION+x}" = x ]; then
      printf '%s\n' "$FAKE_DOCKER_INSPECTION"
      exit 0
    fi
    . "$state"
    case "$selector" in "$container_name"|"$docker_id") ;; *) exit 1 ;; esac
    printf '%s|/%s|%s|%s\n' "$docker_id" "$container_name" "$image_id" "$running"
    ;;
  *) exit 1 ;;
esac
SH
    );
    file_put_contents($bin.'/id', <<<'SH'
#!/bin/sh
[ "${1:-}" = -u ] || exit 64
printf '%s\n' 0
SH
    );
    file_put_contents($bin.'/stat', <<<'SH'
#!/bin/sh
set -eu

case "$1" in
  -c|-f) format=$2; path=$3 ;;
  *) exit 64 ;;
esac
case "$format" in
  %u) printf '%s\n' "${FAKE_STAT_OWNER_UID:-0}" ;;
  %h|%l) printf '%s\n' "${FAKE_STAT_LINK_COUNT:-1}" ;;
  %a|%Lp) printf '%s\n' "${FAKE_STAT_MODE:-640}" ;;
  %u:%g:%a|%u:%g:%Lp) printf '%s\n' "${FAKE_AUTHORITY_METADATA:-0:9999:640}" ;;
  *) exit 64 ;;
esac
SH
    );
    file_put_contents($bin.'/chown', <<<'SH'
#!/bin/sh
set -eu
printf 'chown %s %s\n' "$1" "$2" >> "$FAKE_DOCKER_LOG"
[ "$1" = root:9999 ]
SH
    );
    file_put_contents($bin.'/flock', "#!/bin/sh\nexit 0\n");
    file_put_contents($bin.'/sync', "#!/bin/sh\nexit 0\n");
    foreach (['docker', 'id', 'stat', 'chown', 'flock', 'sync'] as $command) {
        chmod($bin.'/'.$command, 0700);
    }

    return [
        'mutation' => $mutation,
        'root' => $root,
        'bin' => $bin,
        'state' => $root.'/docker-state',
        'log' => $root.'/docker.log',
        'docker_id' => str_repeat('a', 64),
        'image_id' => 'sha256:'.str_repeat('b', 64),
    ];
}

/** @param array{bin: string, state: string, log: string, mutation: ManagedTraefikDocumentMutation} $fixture */
function runEnrollmentWriterAuthorityCommand(string $command, array $fixture, array $environment = []): Process
{
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(5);
    $process->setEnv(array_replace([
        'PATH' => $fixture['bin'].':'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'FAKE_DOCKER_STATE' => $fixture['state'],
        'FAKE_DOCKER_LOG' => $fixture['log'],
        'FAKE_AUTHORITY_PATH' => $fixture['mutation']->writerAuthorityPath(),
    ], $environment));
    $process->run();

    return $process;
}

it('requires an exact inspection transcript for the expected routed container', function (): void {
    $filesystem = new Filesystem;
    $fixture = enrollmentWriterAuthorityFixture();
    $inspector = new InspectControlPlaneEnrollmentWriter;

    try {
        $result = runEnrollmentWriterAuthorityCommand($inspector->commandFor('coolify'), $fixture);
        $identity = $inspector->handle($result->getOutput(), 'coolify');

        expect($result->isSuccessful())->toBeTrue()
            ->and($identity)->toBeInstanceOf(ControlPlaneEnrollmentWriterIdentity::class)
            ->and($identity->containerId)->toBe($fixture['docker_id'])
            ->and($identity->containerName)->toBe('coolify')
            ->and($identity->imageId)->toBe($fixture['image_id'])
            ->and(fn (): ControlPlaneEnrollmentWriterIdentity => $inspector->handle(
                InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN."\nforeign\n".InspectControlPlaneEnrollmentWriter::TRANSCRIPT_END,
                'coolify',
            ))->toThrow(InvalidArgumentException::class, 'record is invalid');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('bootstraps only an absent exact epoch-one authority after the final container identity is re-attested', function (): void {
    $filesystem = new Filesystem;
    $fixture = enrollmentWriterAuthorityFixture();
    $bootstrap = new BootstrapControlPlaneEnrollmentWriterAuthority;
    $state = enrollmentWriterAuthorityState();
    $identity = new ControlPlaneEnrollmentWriterIdentity($fixture['docker_id'], 'coolify', $fixture['image_id']);
    $authority = $bootstrap->authorityFor($state, $identity);
    $command = $bootstrap->commandFor($fixture['mutation'], $authority);

    try {
        expect(file_exists($fixture['mutation']->writerAuthorityPath()))->toBeFalse();
        $first = runEnrollmentWriterAuthorityCommand($command, $fixture);
        $authorityInode = fileinode($fixture['mutation']->writerAuthorityPath());
        $second = runEnrollmentWriterAuthorityCommand($command, $fixture);
        clearstatcache(true, $fixture['mutation']->writerAuthorityPath());

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($first->getOutput())->toBe(BootstrapControlPlaneEnrollmentWriterAuthority::APPLIED_OUTPUT)
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
            ->and($second->getOutput())->toBe(BootstrapControlPlaneEnrollmentWriterAuthority::APPLIED_OUTPUT)
            ->and(file_get_contents($fixture['mutation']->writerAuthorityPath()))->toBe($authority->toJson())
            ->and(fileperms($fixture['mutation']->writerAuthorityPath()) & 0777)->toBe(0640)
            ->and(fileinode($fixture['mutation']->writerAuthorityPath()))->toBe($authorityInode)
            ->and(file_get_contents($fixture['log']))->toContain('chown root:9999')
            ->toContain('inspect '.$fixture['docker_id'])
            ->and(substr_count((string) file_get_contents($fixture['log']), 'inspect '.$fixture['docker_id']))->toBe(4);
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('inspects absent and exact canonical authority while deriving one fenced enrollment rollback', function (): void {
    $filesystem = new Filesystem;
    $fixture = enrollmentWriterAuthorityFixture();
    $bootstrap = new BootstrapControlPlaneEnrollmentWriterAuthority;
    $inspector = new InspectControlPlaneEnrollmentWriterAuthority;
    $state = enrollmentWriterAuthorityState();
    $identity = new ControlPlaneEnrollmentWriterIdentity($fixture['docker_id'], 'coolify', $fixture['image_id']);
    $authority = $bootstrap->authorityFor($state, $identity);
    $rolledBackAuthority = $bootstrap->rolledBackAuthorityFor($state, $identity);

    try {
        $absent = runEnrollmentWriterAuthorityCommand($inspector->commandFor($fixture['mutation']), $fixture);
        file_put_contents($fixture['mutation']->writerAuthorityPath(), $authority->toJson());
        $present = runEnrollmentWriterAuthorityCommand($inspector->commandFor($fixture['mutation']), $fixture);
        $trustedLegacy = runEnrollmentWriterAuthorityCommand($inspector->commandFor($fixture['mutation']), $fixture, [
            'FAKE_STAT_OWNER_UID' => 9999,
        ]);

        expect($absent->isSuccessful())->toBeTrue($absent->getErrorOutput())
            ->and($inspector->handle($absent->getOutput()))->toBeNull()
            ->and($present->isSuccessful())->toBeTrue($present->getErrorOutput())
            ->and($inspector->handle($present->getOutput())?->toJson())->toBe($authority->toJson())
            ->and($trustedLegacy->isSuccessful())->toBeTrue($trustedLegacy->getErrorOutput())
            ->and($inspector->handle($trustedLegacy->getOutput())?->toJson())->toBe($authority->toJson())
            ->and($rolledBackAuthority->epoch)->toBe(2)
            ->and($rolledBackAuthority->dynamicSha256)->toBe(hash('sha256', ''))
            ->and($rolledBackAuthority->hasSameWriterIdentityAs($authority))->toBeTrue()
            ->and(fn (): ?ManagedTraefikDocumentWriterAuthority => $inspector->handle(
                InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN."\nforeign\n".InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_END,
            ))->toThrow(InvalidArgumentException::class, 'record is invalid');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('rejects writable or untrusted authority metadata without mutating it', function (): void {
    $filesystem = new Filesystem;
    $fixture = enrollmentWriterAuthorityFixture();
    $inspector = new InspectControlPlaneEnrollmentWriterAuthority;
    $authorityPath = $fixture['mutation']->writerAuthorityPath();
    $authority = "foreign authority\n";

    try {
        file_put_contents($authorityPath, $authority);
        $writable = runEnrollmentWriterAuthorityCommand($inspector->commandFor($fixture['mutation']), $fixture, [
            'FAKE_STAT_MODE' => 662,
        ]);
        $untrusted = runEnrollmentWriterAuthorityCommand($inspector->commandFor($fixture['mutation']), $fixture, [
            'FAKE_STAT_OWNER_UID' => 1234,
        ]);

        expect($writable->isSuccessful())->toBeFalse()
            ->and($untrusted->isSuccessful())->toBeFalse()
            ->and(file_get_contents($authorityPath))->toBe($authority);
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('reports an already removed enrollment state directory without recreating it', function (): void {
    $filesystem = new Filesystem;
    $fixture = enrollmentWriterAuthorityFixture();
    $inspector = new InspectControlPlaneEnrollmentWriterAuthority;

    try {
        $filesystem->remove($fixture['mutation']->stateDirectory);
        $result = runEnrollmentWriterAuthorityCommand($inspector->commandFor($fixture['mutation']), $fixture);

        expect($result->isSuccessful())->toBeTrue($result->getErrorOutput())
            ->and($inspector->handle($result->getOutput()))->toBeNull()
            ->and(file_exists($fixture['mutation']->stateDirectory))->toBeFalse();
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('fails closed for document drift, runtime drift, and a foreign preexisting authority without overwriting it', function (): void {
    $filesystem = new Filesystem;
    $fixture = enrollmentWriterAuthorityFixture();
    $bootstrap = new BootstrapControlPlaneEnrollmentWriterAuthority;
    $state = enrollmentWriterAuthorityState();
    $identity = new ControlPlaneEnrollmentWriterIdentity($fixture['docker_id'], 'coolify', $fixture['image_id']);
    $command = $bootstrap->commandFor($fixture['mutation'], $bootstrap->authorityFor($state, $identity));

    try {
        file_put_contents($fixture['mutation']->documentPath(), "http:\n  routers:\n    drift: {}\n");
        $documentDrift = runEnrollmentWriterAuthorityCommand($command, $fixture);
        $authorityAbsentAfterDocumentDrift = ! file_exists($fixture['mutation']->writerAuthorityPath());
        file_put_contents($fixture['mutation']->documentPath(), $fixture['mutation']->replacementBytes);
        file_put_contents($fixture['mutation']->sidecarPath(), "foreign sidecar\n");
        $sidecarDrift = runEnrollmentWriterAuthorityCommand($command, $fixture);
        $authorityAbsentAfterSidecarDrift = ! file_exists($fixture['mutation']->writerAuthorityPath());
        file_put_contents($fixture['mutation']->sidecarPath(), $fixture['mutation']->replacementSidecar());
        $runtimeDrift = runEnrollmentWriterAuthorityCommand($command, $fixture, [
            'FAKE_DOCKER_INSPECTION' => str_repeat('0', 64).'|/coolify|'.$fixture['image_id'].'|true',
        ]);
        $foreignAuthority = "foreign authority\n";
        file_put_contents($fixture['mutation']->writerAuthorityPath(), $foreignAuthority);
        $preexistingAuthority = runEnrollmentWriterAuthorityCommand($command, $fixture);

        expect($documentDrift->isSuccessful())->toBeFalse()
            ->and($authorityAbsentAfterDocumentDrift)->toBeTrue()
            ->and($sidecarDrift->isSuccessful())->toBeFalse()
            ->and($authorityAbsentAfterSidecarDrift)->toBeTrue()
            ->and($runtimeDrift->isSuccessful())->toBeFalse()
            ->and($preexistingAuthority->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['mutation']->writerAuthorityPath()))->toBe($foreignAuthority);
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('renders a POSIX-shell-safe authority bootstrap that cannot bootstrap a non-epoch-one replacement', function (): void {
    $filesystem = new Filesystem;
    $fixture = enrollmentWriterAuthorityFixture();
    $bootstrap = new BootstrapControlPlaneEnrollmentWriterAuthority;
    $state = enrollmentWriterAuthorityState();
    $identity = new ControlPlaneEnrollmentWriterIdentity($fixture['docker_id'], 'coolify', $fixture['image_id']);
    $authority = $bootstrap->authorityFor($state, $identity);
    $commandPath = $fixture['root'].'/bootstrap.sh';

    try {
        file_put_contents($commandPath, $bootstrap->commandFor($fixture['mutation'], $authority));
        foreach ([['dash', '-n', $commandPath], ['bash', '-n', $commandPath], ['shellcheck', '-s', 'sh', $commandPath]] as $arguments) {
            $result = new Process($arguments);
            $result->run();

            expect($result->isSuccessful())->toBeTrue($result->getErrorOutput());
        }

        $nonInitialAuthority = new ManagedTraefikDocumentWriterAuthority(
            epoch: 2,
            operationId: $authority->operationId,
            member: $authority->member,
            containerId: $authority->containerId,
            containerName: $authority->containerName,
            imageId: $authority->imageId,
            dynamicRevision: $authority->dynamicRevision,
            dynamicSha256: $authority->dynamicSha256,
        );
        expect(fn (): string => $bootstrap->commandFor($fixture['mutation'], $nonInitialAuthority))
            ->toThrow(InvalidArgumentException::class, 'epoch-one replacement authority');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});
