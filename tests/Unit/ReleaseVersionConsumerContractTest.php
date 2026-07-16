<?php

use App\Actions\Server\UpdateCoolify;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function releaseContractRepositoryRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @return array<string, mixed> */
function releaseContractVersionsJson(): array
{
    $versions = json_decode((string) file_get_contents(releaseContractRepositoryRoot().'/versions.json'), true);
    expect($versions)->toBeArray();

    return $versions;
}

function releaseContractPublishedSemanticVersion(): string
{
    $process = new Process(['php', 'bootstrap/getVersion.php'], releaseContractRepositoryRoot());
    $process->mustRun();

    return trim($process->getOutput());
}

/** @return array<string, mixed> */
function releaseContractConstants(): array
{
    return include releaseContractRepositoryRoot().'/config/constants.php';
}

/** @return array{shared: string, production: string} */
function releaseContractShellSemanticVersionPatterns(): array
{
    $root = releaseContractRepositoryRoot();
    $shared = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $production = Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml');

    return [
        'shared' => (string) $shared['jobs']['validate-inputs']['steps'][0]['env']['SEMANTIC_VERSION_PATTERN'],
        'production' => (string) $production['jobs']['resolve-version']['steps'][1]['env']['SEMANTIC_VERSION_PATTERN'],
    ];
}

function releaseContractUpdateSemanticVersionPattern(): string
{
    $constant = (new ReflectionClass(UpdateCoolify::class))
        ->getReflectionConstant('SEMANTIC_VERSION_PATTERN');

    expect($constant)->not->toBeFalse();

    return (string) $constant->getValue();
}

dataset('release semantic versions', [
    'zero version' => ['0.0.0', true],
    'stable version' => ['4.2.10', true],
    'prerelease and build metadata' => ['4.2.10-rc.1+build.20260715', false],
    'build metadata only' => ['4.2.10+sha-deadbeef', false],
    'leading-zero major' => ['04.2.10', false],
    'leading-zero prerelease number' => ['4.2.10-01', false],
    'empty prerelease identifier' => ['4.2.10-rc..1', false],
    'trailing dot' => ['4.2.10.', false],
    'empty build identifier' => ['4.2.10+build.', false],
    'prefixed version' => ['v4.2.10', false],
]);

it('keeps release workflow and update validation on the same strict semantic version grammar', function (string $version, bool $valid) {
    $patterns = releaseContractShellSemanticVersionPatterns();

    expect($patterns['shared'])->toBe($patterns['production'])
        ->and(preg_match(releaseContractUpdateSemanticVersionPattern(), $version) === 1)->toBe($valid);

    foreach ($patterns as $pattern) {
        $process = new Process(['grep', '-Eq', $pattern]);
        $process->setInput($version);
        $process->run();

        expect($process->isSuccessful())->toBe($valid);
    }
})->with('release semantic versions');

it('serves the workflow-published semantic tag to consumers through versions.json', function () {
    $published = releaseContractPublishedSemanticVersion();

    expect($published)->toMatch('/^\d+\.\d+\.\d+$/')
        ->and(releaseContractVersionsJson()['coolify']['v4']['version'])->toBe($published);
});

it('publishes production only for an explicit increasing canonical version bump', function () {
    $workflow = Yaml::parseFile(releaseContractRepositoryRoot().'/.github/workflows/coolify-production-build.yml');
    $applicationValidation = Yaml::parseFile(releaseContractRepositoryRoot().'/.github/workflows/application-validation.yml');
    $resolveVersion = $workflow['jobs']['resolve-version'];
    $versionStep = collect($resolveVersion['steps'])->firstWhere('id', 'version');

    expect($resolveVersion['outputs']['should_publish'] ?? null)->toBe('${{ steps.version.outputs.should_publish }}')
        ->and($workflow['jobs']['publish']['if'] ?? null)->toBe("\${{ needs.resolve-version.outputs.should_publish == 'true' }}")
        ->and($applicationValidation['concurrency']['cancel-in-progress'] ?? null)->toBe("\${{ github.event_name == 'pull_request' }}")
        ->and($versionStep['env']['BEFORE_SHA'] ?? null)->toBe('${{ github.event.before }}')
        ->and($versionStep['run'] ?? '')->toContain('version_compare')
        ->toContain('should_publish=false')
        ->toContain("jq -er '.coolify.v4.version' versions.json");
});

it('keeps the newest version publishable after an intermediate pending bump is evicted', function () {
    $root = releaseContractRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml');
    $versionStep = collect($workflow['jobs']['resolve-version']['steps'])->firstWhere('id', 'version');
    $script = (string) ($versionStep['run'] ?? '');
    $semanticVersionPattern = (string) ($versionStep['env']['SEMANTIC_VERSION_PATTERN'] ?? '');
    $filesystem = new Filesystem;
    $repository = sys_get_temp_dir().'/coolify-release-sequence-'.bin2hex(random_bytes(8));
    $runnerTemp = $repository.'/runner';

    $filesystem->mkdir([$repository.'/bootstrap', $repository.'/config', $runnerTemp]);
    $filesystem->copy($root.'/bootstrap/getVersion.php', $repository.'/bootstrap/getVersion.php');
    $filesystem->copy($root.'/config/constants.php', $repository.'/config/constants.php');
    $filesystem->copy($root.'/versions.json', $repository.'/versions.json');

    try {
        (new Process(['git', 'init'], $repository))->mustRun();
        (new Process(['git', 'config', 'user.email', 'release-contract@coolify.invalid'], $repository))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Release Contract'], $repository))->mustRun();

        foreach (['config/constants.php', 'versions.json'] as $path) {
            $contents = (string) file_get_contents($repository.'/'.$path);
            file_put_contents($repository.'/'.$path, str_replace('4.1.3', '4.1.2', $contents));
        }
        (new Process(['git', 'add', '.'], $repository))->mustRun();
        (new Process(['git', 'commit', '-m', 'baseline'], $repository))->mustRun();
        $baseline = trim((new Process(['git', 'rev-parse', 'HEAD'], $repository))->mustRun()->getOutput());

        $filesystem->copy($root.'/config/constants.php', $repository.'/config/constants.php', true);
        $filesystem->copy($root.'/versions.json', $repository.'/versions.json', true);
        (new Process(['git', 'add', '.'], $repository))->mustRun();
        (new Process(['git', 'commit', '-m', 'bump'], $repository))->mustRun();
        $firstBump = trim((new Process(['git', 'rev-parse', 'HEAD'], $repository))->mustRun()->getOutput());

        foreach (['config/constants.php', 'versions.json'] as $path) {
            $contents = (string) file_get_contents($repository.'/'.$path);
            file_put_contents($repository.'/'.$path, str_replace('4.1.3', '4.1.4', $contents));
        }
        (new Process(['git', 'add', '.'], $repository))->mustRun();
        (new Process(['git', 'commit', '-m', 'pending bump'], $repository))->mustRun();
        $evictedBump = trim((new Process(['git', 'rev-parse', 'HEAD'], $repository))->mustRun()->getOutput());

        file_put_contents($repository.'/unchanged', "follow-up\n");
        (new Process(['git', 'add', '.'], $repository))->mustRun();
        (new Process(['git', 'commit', '-m', 'unchanged'], $repository))->mustRun();
        $unchanged = trim((new Process(['git', 'rev-parse', 'HEAD'], $repository))->mustRun()->getOutput());

        $decisions = [];
        foreach ([
            [$firstBump, $baseline],
            [$unchanged, $evictedBump],
            [$unchanged, str_repeat('0', 40)],
        ] as [$revision, $before]) {
            (new Process(['git', 'checkout', '--detach', $revision], $repository))->mustRun();
            $output = tempnam($runnerTemp, 'output-');
            expect($output)->not->toBeFalse();

            $process = new Process(['bash', '-c', $script], $repository, [
                'BEFORE_SHA' => $before,
                'GITHUB_OUTPUT' => $output,
                'RUNNER_TEMP' => $runnerTemp,
                'SEMANTIC_VERSION_PATTERN' => $semanticVersionPattern,
            ]);
            $process->mustRun();
            parse_str(str_replace("\n", '&', trim((string) file_get_contents($output))), $decision);
            $decisions[] = $decision;
        }

        expect($decisions)->toBe([
            ['should_publish' => 'true', 'version' => '4.1.3'],
            ['should_publish' => 'true', 'version' => '4.1.4'],
            ['should_publish' => 'false', 'version' => '4.1.4'],
        ]);
    } finally {
        $filesystem->remove($repository);
    }
});

it('resolves the published tag through the install script versions.json parse pipeline', function () {
    // Replicates the exact LATEST_VERSION pipeline from scripts/install.sh so a
    // versions.json reshaping that breaks the installer fails this test first.
    $pipeline = "cat versions.json | grep -i version | xargs | awk '{print $2}' | tr -d ','";
    $process = Process::fromShellCommandline($pipeline, releaseContractRepositoryRoot());
    $process->mustRun();

    expect(trim($process->getOutput()))->toBe(releaseContractPublishedSemanticVersion());
});

it('hands the installer-resolved version to upgrade.sh as the production compose image tag', function () {
    $root = releaseContractRepositoryRoot();

    $installScript = (string) file_get_contents($root.'/scripts/install.sh');
    expect($installScript)->toContain('upgrade.sh "${LATEST_VERSION:-latest}"');

    $upgradeScript = (string) file_get_contents($root.'/scripts/upgrade.sh');
    expect($upgradeScript)->toContain('LATEST_IMAGE=${1:-latest}');

    $compose = Yaml::parseFile($root.'/docker-compose.prod.yml');
    $image = $compose['services']['coolify']['image'];
    expect($image)->toBe('${REGISTRY_URL:-ghcr.io}/coollabsio/coolify:${LATEST_IMAGE:-latest}');

    $published = releaseContractPublishedSemanticVersion();
    $resolved = str_replace(
        ['${REGISTRY_URL:-ghcr.io}', '${LATEST_IMAGE:-latest}'],
        ['ghcr.io', $published],
        $image,
    );
    expect($resolved)->toBe("ghcr.io/coollabsio/coolify:{$published}");
});

it('keeps helper and realtime versions consistent across versions.json, constants, and production compose', function () {
    $versions = releaseContractVersionsJson();
    $constants = releaseContractConstants();

    expect($versions['coolify']['helper']['version'])->toBe($constants['coolify']['helper_version'])
        ->and($versions['coolify']['realtime']['version'])->toBe($constants['coolify']['realtime_version']);

    $compose = Yaml::parseFile(releaseContractRepositoryRoot().'/docker-compose.prod.yml');
    $realtimeImage = $compose['services']['soketi']['image'];
    expect($realtimeImage)->toEndWith(':'.$constants['coolify']['realtime_version']);
});
