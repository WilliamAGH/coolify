<?php

use App\Actions\Server\UpdateCoolify;
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
    $resolveVersion = $workflow['jobs']['resolve-version'];
    $versionStep = collect($resolveVersion['steps'])->firstWhere('id', 'version');

    expect($resolveVersion['outputs']['should_publish'] ?? null)->toBe('${{ steps.version.outputs.should_publish }}')
        ->and($workflow['jobs']['publish']['if'] ?? null)->toBe("\${{ needs.resolve-version.outputs.should_publish == 'true' }}")
        ->and($versionStep['env']['BEFORE_SHA'] ?? null)->toBe('${{ github.event.before }}')
        ->and($versionStep['run'] ?? '')->toContain('version_compare')
        ->toContain('should_publish=false')
        ->toContain("jq -er '.coolify.v4.version' versions.json");
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
