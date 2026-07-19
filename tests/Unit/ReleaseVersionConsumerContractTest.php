<?php

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

/** @return array{generic: array{shared: string, production: string}, fork: array{shared: string, entry: string}} */
function releaseContractShellSemanticVersionPatterns(): array
{
    $root = releaseContractRepositoryRoot();
    $shared = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $production = Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml');
    $fork = Yaml::parseFile($root.'/.github/workflows/publish-fork.yml');
    $sharedTarget = collect($shared['jobs']['validate-inputs']['steps'])->firstWhere('id', 'target');
    $productionVersion = collect($production['jobs']['resolve-version']['steps'])->firstWhere('id', 'version');
    $forkVersion = collect($fork['jobs']['resolve-tag']['steps'])->firstWhere('id', 'version');

    expect($sharedTarget)->toBeArray()
        ->and($productionVersion)->toBeArray()
        ->and($forkVersion)->toBeArray();

    return [
        'generic' => [
            'shared' => (string) $sharedTarget['env']['SEMANTIC_VERSION_PATTERN'],
            'production' => (string) $productionVersion['env']['SEMANTIC_VERSION_PATTERN'],
        ],
        'fork' => [
            'shared' => (string) $sharedTarget['env']['FORK_SEMANTIC_VERSION_PATTERN'],
            'entry' => (string) $forkVersion['env']['VERSION_PATTERN'],
        ],
    ];
}

function releaseContractExecuteForkReleaseGuard(string $script, string $versionVariable, string $version): Process
{
    preg_match('/^FORK_RELEASE_VERSION_PATTERN=.*?^fi$/ms', $script, $matches);

    expect($matches)->toHaveCount(1);

    $process = new Process(['bash', '-s']);
    $process->setInput($versionVariable.'='.escapeshellarg($version)."\n".$matches[0]."\n");
    $process->run();

    return $process;
}

dataset('release semantic versions', [
    'zero version' => ['0.0.0', true],
    'stable version' => ['4.2.10', true],
    'fork prerelease' => ['4.2.10-fork', true],
    'numbered fork prerelease' => ['4.2.10-fork.1', true],
    'prerelease and build metadata' => ['4.2.10-rc.1+build.20260715', false],
    'build metadata only' => ['4.2.10+sha-deadbeef', false],
    'leading-zero major' => ['04.2.10', false],
    'leading-zero prerelease number' => ['4.2.10-01', false],
    'empty prerelease identifier' => ['4.2.10-rc..1', false],
    'trailing dot' => ['4.2.10.', false],
    'empty build identifier' => ['4.2.10+build.', false],
    'prefixed version' => ['v4.2.10', false],
]);

dataset('fork release semantic versions', [
    'fork release' => ['4.2.10-fork', true],
    'numbered fork release' => ['4.2.10-fork.1', false],
    'later numbered fork release' => ['4.2.10-fork.12', false],
    'zero release number' => ['4.2.10-fork.0', false],
    'stable version' => ['4.2.10', false],
    'different prerelease' => ['4.2.10-rc.1', false],
    'leading-zero release number' => ['4.2.10-fork.01', false],
    'prefixed version' => ['v4.2.10-fork.1', false],
]);

dataset('guarded fork release versions', [
    'canonical fork release' => ['4.2.10-fork'],
    'legacy numbered fork release' => ['4.2.10-fork.1'],
]);

it('keeps release workflows on the same strict semantic version grammar', function (string $version, bool $valid) {
    $patterns = releaseContractShellSemanticVersionPatterns()['generic'];

    expect($patterns['shared'])->toBe($patterns['production'])
        ->and(preg_match('/'.$patterns['shared'].'/', $version) === 1)->toBe($valid);

    foreach ($patterns as $pattern) {
        $process = new Process(['grep', '-Eq', $pattern]);
        $process->setInput($version);
        $process->run();

        expect($process->isSuccessful())->toBe($valid);
    }
})->with('release semantic versions');

it('keeps fork release workflows on the same fork version grammar', function (string $version, bool $valid) {
    $patterns = releaseContractShellSemanticVersionPatterns()['fork'];

    expect($patterns['shared'])->toBe($patterns['entry']);

    foreach ($patterns as $pattern) {
        $process = new Process(['grep', '-Eq', $pattern]);
        $process->setInput($version);
        $process->run();

        expect($process->isSuccessful())->toBe($valid);
    }
})->with('fork release semantic versions');

it('serves the workflow-published semantic tag to consumers through versions.json', function () {
    $published = releaseContractPublishedSemanticVersion();
    $constants = releaseContractConstants();
    $semanticVersionPattern = releaseContractShellSemanticVersionPatterns()['generic']['shared'];

    expect($published)->toBe($constants['coolify']['version'])
        ->and(preg_match('/'.$semanticVersionPattern.'/', $published))->toBe(1)
        ->and(releaseContractVersionsJson()['coolify']['v4']['version'])->toBe($published);

    foreach (releaseContractShellSemanticVersionPatterns()['fork'] as $pattern) {
        $process = new Process(['grep', '-Eq', $pattern]);
        $process->setInput($published);
        $process->mustRun();
    }
});

it('orders the fork prerelease below its corresponding upstream stable release', function () {
    $published = releaseContractPublishedSemanticVersion();
    $stable = explode('-fork', $published, 2)[0];

    expect($stable)->not->toBe($published)
        ->and(version_compare($stable, $published, '>'))->toBeTrue();
});

it('publishes production only for an explicit increasing semantic version bump', function () {
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
    $publishedVersion = releaseContractPublishedSemanticVersion();
    $versionCore = explode('-fork', $publishedVersion, 2)[0];
    $versionParts = array_map('intval', explode('.', $versionCore));
    expect($versionParts)->toHaveCount(3);

    $baselineVersion = '0.0.0';
    $nextVersion = implode('.', [
        $versionParts[0],
        $versionParts[1],
        $versionParts[2] + 1,
    ]).'-fork';
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
        (new Process(['git', 'config', 'commit.gpgsign', 'false'], $repository))->mustRun();

        foreach (['config/constants.php', 'versions.json'] as $path) {
            $contents = (string) file_get_contents($repository.'/'.$path);
            file_put_contents($repository.'/'.$path, str_replace($publishedVersion, $baselineVersion, $contents));
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
            file_put_contents($repository.'/'.$path, str_replace($publishedVersion, $nextVersion, $contents));
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
            ['should_publish' => 'true', 'version' => $publishedVersion],
            ['should_publish' => 'true', 'version' => $nextVersion],
            ['should_publish' => 'false', 'version' => $nextVersion],
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

it('executes the guarded install and upgrade paths for canonical and legacy fork releases', function (string $forkVersion) {
    $root = releaseContractRepositoryRoot();
    $installScript = (string) file_get_contents($root.'/scripts/install.sh');
    $upgradeScript = (string) file_get_contents($root.'/scripts/upgrade.sh');

    $installGuard = strpos($installScript, 'FORK_RELEASE_VERSION_PATTERN=');
    $installUpgrade = strpos($installScript, 'upgrade.sh "${LATEST_VERSION:-latest}"');
    $upgradeGuard = strpos($upgradeScript, 'FORK_RELEASE_VERSION_PATTERN=');
    $composeDownload = strpos($upgradeScript, 'curl -fsSL -L $CDN/docker-compose.prod.yml');

    expect($forkVersion)->toMatch('/^\d+\.\d+\.\d+-fork(?:\.[1-9]\d*)?$/')
        ->and($installGuard)->toBeInt()
        ->and($installUpgrade)->toBeInt()
        ->and($upgradeGuard)->toBeInt()
        ->and($composeDownload)->toBeInt()
        ->and($installGuard)->toBeLessThan($installUpgrade)
        ->and($upgradeGuard)->toBeLessThan($composeDownload);

    $installProcess = releaseContractExecuteForkReleaseGuard($installScript, 'LATEST_VERSION', $forkVersion);
    $upgradeProcess = new Process(['bash', $root.'/scripts/upgrade.sh', $forkVersion], $root);
    $upgradeProcess->run();

    foreach ([$installProcess, $upgradeProcess] as $process) {
        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())
            ->toContain("Fork release {$forkVersion} is not published to ghcr.io/coollabsio/coolify.")
            ->toContain('scripts/fork-deploy install --manifest');
    }
})->with('guarded fork release versions');

it('keeps generic compose resolution for upstream image tags without changing its wire format', function () {
    $root = releaseContractRepositoryRoot();

    $compose = Yaml::parseFile($root.'/docker-compose.prod.yml');
    $image = $compose['services']['coolify']['image'];
    expect($image)->toBe('${REGISTRY_URL:-ghcr.io}/coollabsio/coolify:${LATEST_IMAGE:-latest}');

    $resolved = str_replace(
        ['${REGISTRY_URL:-ghcr.io}', '${LATEST_IMAGE:-latest}'],
        ['ghcr.io', '4.2.10'],
        $image,
    );
    expect($resolved)->toBe('ghcr.io/coollabsio/coolify:4.2.10');
});

it('keeps the signed fork deployment path separate from the rejected generic updater', function () {
    $forkDeploy = (string) file_get_contents(releaseContractRepositoryRoot().'/scripts/fork-deploy');

    expect($forkDeploy)->toContain('detached-signature-verified release manifest')
        ->toContain('openssl pkeyutl -verify')
        ->toContain('UPGRADE_SCRIPT_URL "$DISABLED_UPGRADE_SCRIPT_URL"')
        ->not->toContain('bash /data/coolify/source/upgrade.sh')
        ->not->toContain('bash scripts/upgrade.sh');
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
