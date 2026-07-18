<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use Database\Seeders\GithubAppSeeder;
use Database\Seeders\PrivateKeySeeder;
use Database\Seeders\TeamSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

/**
 * @return list<string>
 */
function productionImageSupplyChainDockerfileLines(string $source): array
{
    $logicalLines = [];
    $pendingLine = '';

    foreach (preg_split('/\R/', $source) ?: [] as $physicalLine) {
        $line = rtrim($physicalLine);
        $pendingLine .= $pendingLine === '' ? $line : ' '.ltrim($line);

        if (str_ends_with($pendingLine, '\\')) {
            $pendingLine = substr($pendingLine, 0, -1);

            continue;
        }

        $logicalLines[] = $pendingLine;
        $pendingLine = '';
    }

    if ($pendingLine !== '') {
        $logicalLines[] = $pendingLine;
    }

    return $logicalLines;
}

/**
 * @return array{
 *     arguments: array<string, string>,
 *     stages: array<string, array{base: string, platform: ?string, instructions: list<array{keyword: string, value: string}>}>,
 *     stage_order: list<string>
 * }
 */
function productionImageSupplyChainDockerfile(string $relativePath): array
{
    $source = file_get_contents(base_path($relativePath));

    if ($source === false) {
        throw new RuntimeException("Unable to read Dockerfile: {$relativePath}");
    }

    $arguments = [];
    $stages = [];
    $stageOrder = [];
    $currentStage = null;

    foreach (productionImageSupplyChainDockerfileLines($source) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || preg_match('/^(?<keyword>[A-Za-z]+)\s+(?<value>.*)$/', $line, $matches) !== 1) {
            continue;
        }

        $keyword = strtoupper($matches['keyword']);
        $value = $matches['value'];

        if ($keyword === 'ARG') {
            [$name, $argumentValue] = array_pad(explode('=', $value, 2), 2, '');
            if ($currentStage === null && $argumentValue !== '') {
                $arguments[trim($name)] = trim($argumentValue);
            }

            continue;
        }

        if ($keyword === 'FROM') {
            $parts = preg_split('/\s+/', $value) ?: [];
            $platform = null;

            while (isset($parts[0]) && str_starts_with($parts[0], '--')) {
                if (str_starts_with($parts[0], '--platform=')) {
                    $platform = substr($parts[0], strlen('--platform='));
                }

                array_shift($parts);
            }

            $base = array_shift($parts);
            if ($base === null) {
                throw new RuntimeException("Dockerfile FROM instruction has no base image: {$relativePath}");
            }

            $asIndex = array_search('AS', array_map('strtoupper', $parts), true);
            $currentStage = $asIndex === false
                ? 'stage-'.count($stageOrder)
                : ($parts[$asIndex + 1] ?? null);

            if ($currentStage === null || $currentStage === '') {
                throw new RuntimeException("Dockerfile FROM instruction has an invalid stage name: {$relativePath}");
            }

            $stages[$currentStage] = [
                'base' => $base,
                'platform' => $platform,
                'instructions' => [],
            ];
            $stageOrder[] = $currentStage;

            continue;
        }

        if ($currentStage !== null) {
            $stages[$currentStage]['instructions'][] = [
                'keyword' => $keyword,
                'value' => $value,
            ];
        }
    }

    return [
        'arguments' => $arguments,
        'stages' => $stages,
        'stage_order' => $stageOrder,
    ];
}

/**
 * @param  array{stages: array<string, array{base: string, platform: ?string, instructions: list<array{keyword: string, value: string}>}>}  $dockerfile
 * @return array{base: string, platform: ?string, instructions: list<array{keyword: string, value: string}>}
 */
function productionImageSupplyChainStage(array $dockerfile, string $stageName): array
{
    $stage = $dockerfile['stages'][$stageName] ?? null;

    if (! is_array($stage)) {
        throw new RuntimeException("Dockerfile stage is missing: {$stageName}");
    }

    return $stage;
}

/**
 * @param  array{stage_order: list<string>, stages: array<string, array{base: string, platform: ?string, instructions: list<array{keyword: string, value: string}>}>}  $dockerfile
 * @return array{base: string, platform: ?string, instructions: list<array{keyword: string, value: string}>}
 */
function productionImageSupplyChainFinalStage(array $dockerfile): array
{
    $stageName = $dockerfile['stage_order'][array_key_last($dockerfile['stage_order'])] ?? null;

    if (! is_string($stageName)) {
        throw new RuntimeException('Dockerfile has no build stages.');
    }

    return productionImageSupplyChainStage($dockerfile, $stageName);
}

/**
 * @return list<string>
 */
function productionImageSupplyChainShellWords(string $command): array
{
    preg_match_all('/(?:[^\s"\']+|"(?:\\.|[^"])*"|\'(?:\\.|[^\'])*\')+/', $command, $matches);

    return array_values(array_map(
        static fn (string $word): string => trim($word, " \t\n\r\0\x0B\"'"),
        $matches[0],
    ));
}

/**
 * @param  array{base: string, platform: ?string, instructions: list<array{keyword: string, value: string}>}  $stage
 * @return list<array{from: ?string, source: string, destination: string}>
 */
function productionImageSupplyChainStageCopies(array $stage): array
{
    $copies = [];

    foreach ($stage['instructions'] as $instruction) {
        if ($instruction['keyword'] !== 'COPY') {
            continue;
        }

        $from = null;
        $paths = [];
        foreach (productionImageSupplyChainShellWords($instruction['value']) as $word) {
            if (str_starts_with($word, '--from=')) {
                $from = substr($word, strlen('--from='));

                continue;
            }

            if (! str_starts_with($word, '--')) {
                $paths[] = $word;
            }
        }

        if (count($paths) < 2) {
            continue;
        }

        $copies[] = [
            'from' => $from,
            'source' => $paths[count($paths) - 2],
            'destination' => $paths[count($paths) - 1],
        ];
    }

    return $copies;
}

/**
 * @param  array{stages: array<string, array{base: string, platform: ?string, instructions: list<array{keyword: string, value: string}>}>}  $dockerfile
 */
function productionImageSupplyChainHasCopy(array $dockerfile, string $from, string $source, string $destination): bool
{
    foreach ($dockerfile['stages'] as $stage) {
        foreach (productionImageSupplyChainStageCopies($stage) as $copy) {
            if ($copy === compact('from', 'source', 'destination')) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @return array<string, mixed>
 */
function productionImageSupplyChainWorkflowStep(array $job, string $stepName): array
{
    foreach ($job['steps'] ?? [] as $step) {
        if (is_array($step) && ($step['name'] ?? null) === $stepName) {
            return $step;
        }
    }

    throw new RuntimeException("Workflow step is missing: {$stepName}");
}

it('pins the shared Dockerfile frontend to one reviewed multi-platform digest', function () {
    $expected = '# syntax=docker/dockerfile:1@sha256:87999aa3d42bdc6bea60565083ee17e86d1f3339802f543c0d03998580f9cb89';

    foreach (['docker/production/Dockerfile', 'docker/testing-host/Dockerfile'] as $dockerfile) {
        $firstLine = strtok((string) file_get_contents(base_path($dockerfile)), "\r\n");

        expect($firstLine)->toBe($expected);
    }
});

it('excludes local-only repository data from the Docker build context', function () {
    $ignorePatterns = file(base_path('.dockerignore'), FILE_IGNORE_NEW_LINES);

    expect($ignorePatterns)
        ->toContain('/.git')
        ->toContain('/storage/debugbar')
        ->toContain('.env*')
        ->toContain('!/.env.development.example')
        ->not->toContain('!/.env.windows-docker-desktop.example')
        ->not->toContain('.env')
        ->not->toContain('.env.production')
        ->not->toContain('.env.secrets');
});

it('preserves immutable cloudflared pins and its structured builder-stage transfer', function () {
    $dockerfile = productionImageSupplyChainDockerfile('docker/production/Dockerfile');

    foreach ([
        'CLOUDFLARED_GO_IMAGE' => 'golang:1.26.5-alpine@sha256:0178a641fbb4858c5f1b48e34bdaabe0350a330a1b1149aabd498d0699ff5fb2',
        'CLOUDFLARED_VERSION' => '2026.7.1',
        'CLOUDFLARED_TAG' => '2026.7.1',
        'CLOUDFLARED_COMMIT' => 'ecb88678f1368581964ca2bb55615502a2ed2dbe',
        'CLOUDFLARED_SOURCE_SHA256' => '94a8f8e057bf4eec41416d6db56a5f8379b833fd40fdce4dcdc668b8cb6b5c65',
    ] as $argument => $value) {
        expect($dockerfile['arguments'][$argument] ?? null)->toBe($value);
    }

    $builder = productionImageSupplyChainStage($dockerfile, 'cloudflared-builder');
    expect($builder['base'])->toBe('${CLOUDFLARED_GO_IMAGE}')
        ->and($builder['platform'])->toBe('$BUILDPLATFORM')
        ->and(productionImageSupplyChainHasCopy($dockerfile, 'cloudflared-builder', '/out/cloudflared', '/usr/local/bin/cloudflared'))->toBeTrue();
});

it('uses the same immutable cloudflared pins and structured stage transfer for realtime', function () {
    $dockerfile = productionImageSupplyChainDockerfile('docker/coolify-realtime/Dockerfile');

    foreach ([
        'CLOUDFLARED_GO_IMAGE' => 'golang:1.26.5-alpine@sha256:0178a641fbb4858c5f1b48e34bdaabe0350a330a1b1149aabd498d0699ff5fb2',
        'CLOUDFLARED_VERSION' => '2026.7.1',
        'CLOUDFLARED_TAG' => '2026.7.1',
        'CLOUDFLARED_COMMIT' => 'ecb88678f1368581964ca2bb55615502a2ed2dbe',
        'CLOUDFLARED_SOURCE_SHA256' => '94a8f8e057bf4eec41416d6db56a5f8379b833fd40fdce4dcdc668b8cb6b5c65',
    ] as $argument => $value) {
        expect($dockerfile['arguments'][$argument] ?? null)->toBe($value);
    }

    $builder = productionImageSupplyChainStage($dockerfile, 'cloudflared-builder');
    expect($builder['base'])->toBe('${CLOUDFLARED_GO_IMAGE}')
        ->and($builder['platform'])->toBe('$BUILDPLATFORM')
        ->and(productionImageSupplyChainHasCopy($dockerfile, 'cloudflared-builder', '/out/cloudflared', '/usr/local/bin/cloudflared'))->toBeTrue();
});

it('preserves the immutable privilege-drop package pin', function () {
    $dockerfile = productionImageSupplyChainDockerfile('docker/production/Dockerfile');
    expect($dockerfile['arguments']['SU_EXEC_VERSION'] ?? null)->toBe('0.3-r0');
});

it('models testing-host as pinned source builders with verified artifact transfers', function () {
    $dockerfile = productionImageSupplyChainDockerfile('docker/testing-host/Dockerfile');

    foreach ([
        'GO_BUILDER_IMAGE' => 'golang:1.26.5-alpine@sha256:0178a641fbb4858c5f1b48e34bdaabe0350a330a1b1149aabd498d0699ff5fb2',
        'TESTING_HOST_BASE_IMAGE' => 'alpine:3.24@sha256:28bd5fe8b56d1bd048e5babf5b10710ebe0bae67db86916198a6eec434943f8b',
        'BASH_VERSION' => '5.3.9-r1',
        'DOCKER_VERSION' => '28.4.0',
        'DOCKER_CLI_TAG' => 'v28.4.0',
        'DOCKER_CLI_COMMIT' => 'd8eb465f86cfceeb57f8582e373d41a558d35503',
        'DOCKER_CLI_SOURCE_SHA256' => '16202845b8c8c019d406461b40e79a76c9a1b154325f9549093950499652621a',
        'DOCKER_COMPOSE_VERSION' => '2.39.2',
        'DOCKER_COMPOSE_TAG' => 'v2.39.2',
        'DOCKER_COMPOSE_COMMIT' => 'c2cb0aef6bbbe1afc8c9e81267621655ac90c5f6',
        'DOCKER_COMPOSE_SOURCE_SHA256' => 'd6167591f51d5faee5ba899051fd8c0398454b2a23ea59377286dff28b9f90ee',
        'DOCKER_BUILDX_VERSION' => '0.29.1',
        'DOCKER_BUILDX_TAG' => 'v0.29.1',
        'DOCKER_BUILDX_COMMIT' => 'a32761aeb3debd39be1eca514af3693af0db334b',
        'DOCKER_BUILDX_SOURCE_SHA256' => 'f38d7e6ecd9477528d9985bfa62d8f26524b6edfe706e1e03f6f60646d77f59b',
    ] as $argument => $value) {
        expect($dockerfile['arguments'][$argument] ?? null)->toBe($value);
    }

    $cliBuilder = productionImageSupplyChainStage($dockerfile, 'docker-cli-builder');
    $composeBuilder = productionImageSupplyChainStage($dockerfile, 'docker-compose-builder');
    $buildxBuilder = productionImageSupplyChainStage($dockerfile, 'docker-buildx-builder');
    $finalStage = productionImageSupplyChainFinalStage($dockerfile);
    expect($cliBuilder['base'])->toBe('${GO_BUILDER_IMAGE}')
        ->and($composeBuilder['base'])->toBe('${GO_BUILDER_IMAGE}')
        ->and($buildxBuilder['base'])->toBe('${GO_BUILDER_IMAGE}')
        ->and($cliBuilder['platform'])->toBe('$BUILDPLATFORM')
        ->and($composeBuilder['platform'])->toBe('$BUILDPLATFORM')
        ->and($buildxBuilder['platform'])->toBe('$BUILDPLATFORM')
        ->and($finalStage['base'])->toBe('${TESTING_HOST_BASE_IMAGE}')
        ->and(productionImageSupplyChainHasCopy($dockerfile, 'docker-cli-builder', '/out/docker', '/usr/local/bin/docker'))->toBeTrue()
        ->and(productionImageSupplyChainHasCopy($dockerfile, 'docker-compose-builder', '/out/docker-compose', '/root/.docker/cli-plugins/docker-compose'))->toBeTrue()
        ->and(productionImageSupplyChainHasCopy($dockerfile, 'docker-buildx-builder', '/out/docker-buildx', '/root/.docker/cli-plugins/docker-buildx'))->toBeTrue();
});

it('parses the reusable testing-host publication and attestation contract', function () {
    $testingHostWorkflow = Yaml::parseFile(base_path('.github/workflows/coolify-testing-host.yml'));
    $publicationWorkflow = Yaml::parseFile(base_path('.github/workflows/publish-linux-image.yml'));
    $publish = $testingHostWorkflow['jobs']['publish'] ?? [];
    $sbom = productionImageSupplyChainWorkflowStep(
        $publicationWorkflow['jobs']['build-and-scan'] ?? [],
        'Generate SPDX SBOM from the local OCI archive',
    );
    $attestations = array_values(array_filter(
        $publicationWorkflow['jobs']['attest-and-verify']['steps'] ?? [],
        static fn (mixed $step): bool => is_array($step) && array_key_exists('sbom-path', $step['with'] ?? []),
    ));
    $attestationTuples = array_map(
        static fn (array $attestation): array => [
            'subject_name' => $attestation['with']['subject-name'] ?? null,
            'subject_digest' => $attestation['with']['subject-digest'] ?? null,
            'sbom_path' => $attestation['with']['sbom-path'] ?? null,
        ],
        $attestations,
    );
    usort($attestationTuples, static fn (array $left, array $right): int => json_encode($left) <=> json_encode($right));
    $expectedAttestationTuples = [
        [
            'subject_name' => '${{ env.DOCKER_TARGET }}',
            'subject_digest' => '${{ needs.stage-candidates.outputs.amd64_digest }}',
            'sbom_path' => 'attestation-input/${{ inputs.artifact_name }}-amd64.sbom.spdx.json',
        ],
        [
            'subject_name' => '${{ env.DOCKER_TARGET }}',
            'subject_digest' => '${{ needs.stage-candidates.outputs.arm64_digest }}',
            'sbom_path' => 'attestation-input/${{ inputs.artifact_name }}-arm64.sbom.spdx.json',
        ],
        [
            'subject_name' => '${{ env.GHCR_TARGET }}',
            'subject_digest' => '${{ needs.stage-candidates.outputs.amd64_digest }}',
            'sbom_path' => 'attestation-input/${{ inputs.artifact_name }}-amd64.sbom.spdx.json',
        ],
        [
            'subject_name' => '${{ env.GHCR_TARGET }}',
            'subject_digest' => '${{ needs.stage-candidates.outputs.arm64_digest }}',
            'sbom_path' => 'attestation-input/${{ inputs.artifact_name }}-arm64.sbom.spdx.json',
        ],
    ];
    usort($expectedAttestationTuples, static fn (array $left, array $right): int => json_encode($left) <=> json_encode($right));

    expect($publish['uses'] ?? null)->toBe('./.github/workflows/publish-linux-image.yml')
        ->and($publish['with']['artifact_name'] ?? null)->toBe('coolify-testing-host')
        ->and($publish['with']['dockerfile'] ?? null)->toBe('docker/testing-host/Dockerfile')
        ->and($publish['with']['release_kind'] ?? null)->toBe('testing-host')
        ->and($sbom['uses'] ?? null)->toBe('anchore/sbom-action@e22c389904149dbc22b58101806040fa8d37a610')
        ->and($sbom['with']['image'] ?? null)->toBe('oci-archive:linux-image/${{ inputs.artifact_name }}-${{ matrix.arch }}.oci.tar')
        ->and($attestationTuples)->toBe($expectedAttestationTuples);

    foreach ($attestations as $attestation) {
        expect($attestation['uses'] ?? null)->toBe('actions/attest@a1948c3f048ba23858d222213b7c278aabede763')
            ->and($attestation['with']['push-to-registry'] ?? null)->toBeTrue();
    }
});

it('runs source provenance checks in an explicit simulation', function () {
    $process = new Process([
        'bash',
        base_path('tests/Integration/ProductionImageSupplyChain/run-simulations.sh'),
        base_path(),
    ]);
    $process->setTimeout(30);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(trim($process->getOutput()))->toBe('PRODUCTION_IMAGE_SUPPLY_CHAIN_SIMULATION result=pass production_image_acceptance=false');
});

it('generates different private keys for independent testing-host runtime disks', function () {
    Storage::fake('testing-host-key');
    $firstPrivateKey = PrivateKeySeeder::testingHostPrivateKey();

    Storage::fake('testing-host-key');
    $secondPrivateKey = PrivateKeySeeder::testingHostPrivateKey();

    expect(PrivateKey::validatePrivateKey($firstPrivateKey))->toBeTrue()
        ->and(PrivateKey::validatePrivateKey($secondPrivateKey))->toBeTrue()
        ->and($secondPrivateKey)->not->toBe($firstPrivateKey);
});

it('generates isolated testing-host runtime key material in an explicit simulation', function () {
    $process = new Process([
        'bash',
        base_path('tests/Integration/ProductionImageSupplyChain/run-simulations.sh'),
        base_path(),
        '--keygen-only',
    ]);
    $process->setTimeout(30);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(trim($process->getOutput()))->toBe('PRODUCTION_IMAGE_SUPPLY_CHAIN_SIMULATION keygen=pass production_image_acceptance=false');
});

it('generates the runtime test host key and seeds only the public GitHub source', function () {
    Storage::fake('ssh-keys');
    Storage::fake('testing-host-key');

    $this->seed([
        UserSeeder::class,
        TeamSeeder::class,
        PrivateKeySeeder::class,
        GithubAppSeeder::class,
    ]);

    $testHostKey = PrivateKey::query()->where('uuid', 'ssh')->sole();
    $publicGithub = GithubApp::query()->sole();

    Storage::disk('testing-host-key')->assertExists('testing-host');
    Storage::disk('ssh-keys')->assertMissing('testing-host');

    expect(Storage::disk('testing-host-key')->get('testing-host'))->toBe($testHostKey->private_key)
        ->and($testHostKey)
        ->uuid->toBe('ssh')
        ->name->toBe('Testing Host Key')
        ->is_git_related->toBeFalse();

    expect(PrivateKey::validatePrivateKey($testHostKey->private_key))->toBeTrue();

    expect($publicGithub)
        ->uuid->toBe('github-public')
        ->name->toBe('Public GitHub')
        ->is_public->toBeTrue()
        ->private_key_id->toBeNull()
        ->app_id->toBeNull()
        ->installation_id->toBeNull()
        ->client_id->toBeNull()
        ->client_secret->toBeNull()
        ->webhook_secret->toBeNull();
});

it('rotates the canonical testing-host key by UUID regardless of its database ID', function () {
    Storage::fake('ssh-keys');
    Storage::fake('testing-host-key');

    $this->seed([
        UserSeeder::class,
        TeamSeeder::class,
    ]);

    $previousPrivateKey = PrivateKey::generateNewKeyPair('ed25519')['private_key'];
    $runtimePrivateKey = PrivateKey::generateNewKeyPair('ed25519')['private_key'];
    Storage::disk('testing-host-key')->put('testing-host', $runtimePrivateKey);

    PrivateKey::forceCreate([
        'id' => 73,
        'uuid' => 'ssh',
        'team_id' => 0,
        'name' => 'Testing Host Key',
        'description' => 'This is a test docker container',
        'private_key' => $previousPrivateKey,
    ]);

    $this->seed(PrivateKeySeeder::class);

    expect(PrivateKey::query()->where('uuid', 'ssh')->sole()->private_key)->toBe($runtimePrivateKey);

    $this->seed(PrivateKeySeeder::class);

    expect(PrivateKey::query()->where('uuid', 'ssh')->count())->toBe(1)
        ->and(PrivateKey::query()->where('uuid', 'ssh')->sole()->private_key)->toBe($runtimePrivateKey);
});

it('does not treat an unrelated key at ID one as the canonical testing-host key', function () {
    Storage::fake('ssh-keys');
    Storage::fake('testing-host-key');

    $this->seed([
        UserSeeder::class,
        TeamSeeder::class,
    ]);

    $privateKey = PrivateKey::forceCreate([
        'id' => 1,
        'uuid' => 'user-managed-key',
        'team_id' => 0,
        'name' => 'User-managed key',
        'description' => 'Must never be replaced by a development seeder',
        'is_git_related' => true,
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);
    $githubApp = GithubApp::forceCreate([
        'uuid' => 'user-managed-source',
        'name' => 'User-managed source',
        'organization' => 'example-organization',
        'api_url' => 'https://api.example.test',
        'html_url' => 'https://example.test',
        'custom_user' => 'deploy',
        'custom_port' => 2222,
        'app_id' => 987654,
        'installation_id' => 876543,
        'client_id' => 'user-managed-client-id',
        'client_secret' => 'user-managed-client-secret',
        'webhook_secret' => 'user-managed-webhook-secret',
        'is_system_wide' => true,
        'is_public' => false,
        'contents' => 'read',
        'metadata' => 'read',
        'pull_requests' => 'write',
        'administration' => 'read',
        'private_key_id' => $privateKey->id,
        'team_id' => 0,
    ]);
    $privateKeySnapshot = $privateKey->refresh()->getAttributes();
    $githubAppSnapshot = $githubApp->refresh()->getAttributes();

    $this->seed(PrivateKeySeeder::class);

    $testingHostKey = PrivateKey::query()->where('uuid', 'ssh')->sole();
    $privateKey->refresh();
    $githubApp->refresh();

    expect($testingHostKey->getKey())->not->toBe(1)
        ->and($privateKey->getAttributes())->toBe($privateKeySnapshot)
        ->and($githubApp->getAttributes())->toBe($githubAppSnapshot)
        ->and($githubApp->privateKey?->is($privateKey))->toBeTrue();
});

it('removes the exact unreferenced legacy development GitHub app and key', function () {
    Storage::fake('ssh-keys');

    $this->seed([
        UserSeeder::class,
        TeamSeeder::class,
    ]);

    PrivateKey::forceCreate([
        'id' => 2,
        'uuid' => 'github-key',
        'team_id' => 0,
        'name' => 'development-github-app',
        'description' => 'This is the key for using the development GitHub app',
        'is_git_related' => true,
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);

    GithubApp::forceCreate([
        'uuid' => 'github-app',
        'name' => 'coolify-laravel-dev-public',
        'organization' => 'coollabsio',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
        'app_id' => 292941,
        'installation_id' => 37267016,
        'client_id' => 'Iv1.220e564d2b0abd8c',
        'client_secret' => 'not-a-secret',
        'webhook_secret' => 'not-a-secret',
        'private_key_id' => 2,
        'team_id' => 0,
    ]);

    $this->seed(GithubAppSeeder::class);

    expect(GithubApp::query()->where('uuid', 'github-app')->exists())->toBeFalse()
        ->and(PrivateKey::query()->find(2))->toBeNull()
        ->and(GithubApp::query()->where('uuid', 'github-public')->exists())->toBeTrue();
});

it('neutralizes an exact legacy GitHub app still referenced by an application', function () {
    Storage::fake('ssh-keys');

    $this->seed([
        UserSeeder::class,
        TeamSeeder::class,
    ]);

    $legacyPrivateKey = PrivateKey::forceCreate([
        'id' => 2,
        'uuid' => 'github-key',
        'team_id' => 0,
        'name' => 'development-github-app',
        'description' => 'This is the key for using the development GitHub app',
        'is_git_related' => true,
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);
    $legacyGithubApp = GithubApp::forceCreate([
        'uuid' => 'github-app',
        'name' => 'coolify-laravel-dev-public',
        'organization' => 'coollabsio',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
        'app_id' => 292941,
        'installation_id' => 37267016,
        'client_id' => 'Iv1.220e564d2b0abd8c',
        'client_secret' => 'not-a-secret',
        'webhook_secret' => 'not-a-secret',
        'private_key_id' => $legacyPrivateKey->id,
        'team_id' => 0,
    ]);
    $serverPrivateKey = PrivateKey::create([
        'team_id' => 0,
        'name' => 'Application host key',
        'description' => 'A non-legacy key for the linked application host',
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);
    $server = Server::factory()->create([
        'team_id' => 0,
        'private_key_id' => $serverPrivateKey->id,
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => 0]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'source_id' => $legacyGithubApp->id,
        'source_type' => GithubApp::class,
    ]);

    $this->seed(GithubAppSeeder::class);

    expect($legacyGithubApp->fresh())
        ->private_key_id->toBeNull()
        ->app_id->toBeNull()
        ->installation_id->toBeNull()
        ->client_id->toBeNull()
        ->client_secret->toBeNull()
        ->webhook_secret->toBeNull();

    expect(PrivateKey::query()->find($legacyPrivateKey->id))->toBeNull();
});

it('leaves every field and private-key relation on a noncanonical github-app record untouched', function () {
    Storage::fake('ssh-keys');

    $this->seed([
        UserSeeder::class,
        TeamSeeder::class,
    ]);

    $privateKey = PrivateKey::forceCreate([
        'uuid' => 'noncanonical-github-key',
        'team_id' => 0,
        'name' => 'Noncanonical GitHub key',
        'description' => 'User-managed source credential',
        'is_git_related' => true,
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);
    $githubApp = GithubApp::forceCreate([
        'uuid' => 'github-app',
        'name' => 'User-managed GitHub App',
        'organization' => 'user-managed-organization',
        'api_url' => 'https://api.user-managed.test',
        'html_url' => 'https://user-managed.test',
        'custom_user' => 'user-managed-git',
        'custom_port' => 2022,
        'app_id' => 192941,
        'installation_id' => 17267016,
        'client_id' => 'user-managed-client-id',
        'client_secret' => 'user-managed-client-secret',
        'webhook_secret' => 'user-managed-webhook-secret',
        'is_system_wide' => true,
        'is_public' => false,
        'contents' => 'write',
        'metadata' => 'read',
        'pull_requests' => 'read',
        'administration' => 'write',
        'private_key_id' => $privateKey->id,
        'team_id' => 0,
    ]);
    $githubAppSnapshot = $githubApp->refresh()->getAttributes();
    $privateKeySnapshot = $privateKey->refresh()->getAttributes();

    $this->seed(GithubAppSeeder::class);

    $githubApp->refresh();
    $privateKey->refresh();

    expect($githubApp->getAttributes())->toBe($githubAppSnapshot)
        ->and($privateKey->getAttributes())->toBe($privateKeySnapshot)
        ->and($githubApp->privateKey?->is($privateKey))->toBeTrue();
});
