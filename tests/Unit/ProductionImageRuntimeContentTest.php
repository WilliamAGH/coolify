<?php

use Symfony\Component\Yaml\Yaml;

function productionImageRuntimeContentRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @return array<string, mixed>|null
 */
function productionImageRuntimeFixtureMount(string $composePath, string $service, string $source): ?array
{
    $compose = Yaml::parseFile($composePath);

    foreach ($compose['services'][$service]['volumes'] ?? [] as $volume) {
        if (is_array($volume) && ($volume['type'] ?? null) === 'bind' && ($volume['source'] ?? null) === $source) {
            return $volume;
        }
    }

    return null;
}

it('keeps development-only private key material out of the production runtime image', function () {
    $root = productionImageRuntimeContentRoot();
    $dockerfile = (string) file_get_contents($root.'/docker/production/Dockerfile');
    $dockerignore = (string) file_get_contents($root.'/.dockerignore');

    expect($dockerfile)
        ->toContain('COPY --chown=www-data:www-data database/migrations ./database/migrations')
        ->not->toContain('COPY --chown=www-data:www-data database ./database')
        ->not->toContain('database/factories/')
        ->not->toContain('database/seeders/PrivateKeySeeder.php')
        ->not->toContain('database/seeders/DevelopmentRailpackExamplesSeeder.php')
        ->not->toContain('docker/testing-host/development-private_key');

    foreach ([
        'database/seeders/ProductionSeeder.php',
        'database/seeders/OauthSettingSeeder.php',
        'database/seeders/PopulateSshKeysDirectorySeeder.php',
        'database/seeders/SentinelSeeder.php',
        'database/seeders/RootUserSeeder.php',
        'database/seeders/CaSslCertSeeder.php',
    ] as $productionSeeder) {
        expect($dockerfile)->toContain($productionSeeder);
    }

    expect($dockerignore)->toContain('docker/testing-host/development-private_key');
});

it('installs the patched Git LFS APK with per-architecture verification', function () {
    $root = productionImageRuntimeContentRoot();
    $dockerfile = (string) file_get_contents($root.'/docker/production/Dockerfile');
    $logicalDockerfile = (string) preg_replace('/\\\\\R\s*/', ' ', $dockerfile);

    expect($dockerfile)
        ->toContain('ARG GIT_LFS_VERSION=3.7.1-r1')
        ->toContain('ARG GIT_LFS_AMD64_SHA256=a43b0a41e8009d422d43806fe732c2b978e3ceec568394309251c1f611e098e6')
        ->toContain('ARG GIT_LFS_ARM64_SHA256=2bb47c493735a7681cf385303835c60ada86a811e107029e9bb3042d279ffa19')
        ->toContain('amd64) GIT_LFS_APK_ARCH=x86_64; GIT_LFS_APK_SHA256="${GIT_LFS_AMD64_SHA256}" ;;')
        ->toContain('arm64) GIT_LFS_APK_ARCH=aarch64; GIT_LFS_APK_SHA256="${GIT_LFS_ARM64_SHA256}" ;;')
        ->toContain('*) echo "Unsupported TARGETARCH for Git LFS: ${TARGETARCH}" >&2; exit 1 ;;')
        ->toContain('https://dl-cdn.alpinelinux.org/alpine/edge/community/${GIT_LFS_APK_ARCH}/git-lfs-${GIT_LFS_VERSION}.apk')
        ->toContain('echo "${GIT_LFS_APK_SHA256}  ${GIT_LFS_APK}" | sha256sum -c -')
        ->toContain('apk verify "${GIT_LFS_APK}"')
        ->toContain('apk add --no-cache "${GIT_LFS_APK}";')
        ->toContain('apk info -e "git-lfs=${GIT_LFS_VERSION}" >/dev/null')
        ->toContain('GIT_LFS_RUNTIME_VERSION="$(git lfs version)"')
        ->toContain('"git-lfs/${GIT_LFS_VERSION%-r*} "*) ;;')
        ->not->toContain('--allow-untrusted');

    expect(substr_count($logicalDockerfile, 'apk add --no-cache "${GIT_LFS_APK}";'))->toBe(1)
        ->and($logicalDockerfile)->not->toMatch("/\\bapk add\\b[^;\\n]*(?:^|\\s)['\"]?git-lfs(?:[='\"]|\\s|$)/");

    $repositoryMutationLines = array_filter(
        explode("\n", $logicalDockerfile),
        static fn (string $line): bool => str_contains($line, '/etc/apk/repositories'),
    );

    expect(implode("\n", $repositoryMutationLines))->not->toContain('/alpine/edge/');

    $checksumOffset = strpos(
        $dockerfile,
        'echo "${GIT_LFS_APK_SHA256}  ${GIT_LFS_APK}" | sha256sum -c -',
    );
    $signatureOffset = strpos($dockerfile, 'apk verify "${GIT_LFS_APK}"');
    $installOffset = strpos($dockerfile, 'apk add --no-cache "${GIT_LFS_APK}";');

    expect($checksumOffset)->toBeInt()
        ->and($signatureOffset)->toBeInt()
        ->and($installOffset)->toBeInt()
        ->and($checksumOffset < $signatureOffset)->toBeTrue()
        ->and($signatureOffset < $installOffset)->toBeTrue();
});

it('mounts the Windows testing-host private-key fixture read-only only for Windows compose consumers', function () {
    $root = productionImageRuntimeContentRoot();

    expect(productionImageRuntimeFixtureMount(
        $root.'/docker-compose.windows.yml',
        'coolify',
        './docker/testing-host/development-private_key',
    ))->toBe([
        'type' => 'bind',
        'source' => './docker/testing-host/development-private_key',
        'target' => '/run/coolify-testing-host/private/testing-host',
        'read_only' => true,
    ]);

    expect(productionImageRuntimeFixtureMount(
        $root.'/other/nightly/docker-compose.windows.yml',
        'coolify',
        '../../docker/testing-host/development-private_key',
    ))->toBe([
        'type' => 'bind',
        'source' => '../../docker/testing-host/development-private_key',
        'target' => '/run/coolify-testing-host/private/testing-host',
        'read_only' => true,
    ]);

    foreach ([
        'docker-compose.yml',
        'docker-compose.dev.yml',
        'docker-compose-maxio.dev.yml',
        'docker-compose.prod.yml',
    ] as $composeFile) {
        expect((string) file_get_contents($root.'/'.$composeFile))
            ->not->toContain('development-private_key');
    }
});
