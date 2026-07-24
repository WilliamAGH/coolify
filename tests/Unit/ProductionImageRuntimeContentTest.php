<?php

use Symfony\Component\Yaml\Yaml;

function productionImageRuntimeContentRoot(): string
{
    return dirname(__DIR__, 2);
}

it('packages the runtime version catalog used by proxy startup', function (): void {
    $root = productionImageRuntimeContentRoot();
    $dockerfile = (string) file_get_contents($root.'/docker/production/Dockerfile');

    expect($dockerfile)
        ->toContain('COPY --chown=www-data:www-data versions.json ./versions.json');
});

it('keeps the production database payload to migrations and the runtime seeder allowlist', function (): void {
    $root = productionImageRuntimeContentRoot();
    $dockerfile = (string) file_get_contents($root.'/docker/production/Dockerfile');
    $dockerignore = (string) file_get_contents($root.'/.dockerignore');
    $logicalDockerfile = (string) preg_replace('/\\\\\R\s*/', ' ', $dockerfile);
    $databaseCopyInstructions = array_values(array_filter(
        explode("\n", $logicalDockerfile),
        static fn (string $line): bool => preg_match('/^\s*(?:COPY|ADD)\b.*\bdatabase(?:\/|\s)/', $line) === 1,
    ));
    $runtimeSeederCopy = <<<'DOCKERFILE'
COPY --chown=www-data:www-data \
    database/seeders/CaSslCertSeeder.php \
    database/seeders/DatabaseSeeder.php \
    database/seeders/OauthSettingSeeder.php \
    database/seeders/PopulateSshKeysDirectorySeeder.php \
    database/seeders/ProductionSeeder.php \
    database/seeders/RootUserSeeder.php \
    database/seeders/SentinelSeeder.php \
    ./database/seeders/
DOCKERFILE;

    expect($dockerfile)
        ->toContain('COPY --chown=www-data:www-data database/migrations ./database/migrations')
        ->toContain($runtimeSeederCopy)
        ->not->toContain('COPY --chown=www-data:www-data database ./database')
        ->not->toContain('database/schema')
        ->not->toContain('database/factories/')
        ->not->toContain('database/seeders/PrivateKeySeeder.php')
        ->not->toContain('database/seeders/DevelopmentRailpackExamplesSeeder.php')
        ->not->toContain('docker/testing-host/development-private_key');

    expect($databaseCopyInstructions)->toHaveCount(2)
        ->and($dockerignore)->not->toContain('development-private_key')
        ->and(is_file($root.'/docker/testing-host/development-private_key'))->toBeFalse();
});

it('builds Git LFS from pinned upstream Go source instead of an Alpine package', function (): void {
    $root = productionImageRuntimeContentRoot();
    $dockerfile = (string) file_get_contents($root.'/docker/production/Dockerfile');

    expect($dockerfile)
        ->toMatch('/^ARG GO_BUILD_IMAGE=golang:1\\.26\\.5-alpine@sha256:[a-f0-9]{64}$/m')
        ->toContain('ARG GIT_LFS_VERSION=3.7.1')
        ->toContain('ARG GIT_LFS_TAG=v3.7.1')
        ->toMatch('/^ARG GIT_LFS_COMMIT=[a-f0-9]{40}$/m')
        ->toMatch('/^ARG GIT_LFS_X_NET_VERSION=v0\\.56\\.0$/m')
        ->toMatch('/^ARG GIT_LFS_X_TEXT_VERSION=v0\\.39\\.0$/m')
        ->toContain('FROM go-source-builder AS git-lfs-builder')
        ->toContain('https://github.com/git-lfs/git-lfs/archive/${GIT_LFS_COMMIT}.tar.gz')
        ->toContain('echo "${GIT_LFS_SOURCE_SHA256}  /tmp/git-lfs.tar.gz" | sha256sum -c -')
        ->toContain('go get "golang.org/x/net@${GIT_LFS_X_NET_VERSION}" "golang.org/x/text@${GIT_LFS_X_TEXT_VERSION}"')
        ->toContain('$2 == "golang.org/x/text" && $3 == expected')
        ->toContain('go mod verify')
        ->toContain('test "$(go env GOVERSION)" = \'go1.26.5\'')
        ->toContain('go version -m /out/git-lfs')
        ->toContain('COPY --from=git-lfs-builder --chmod=755 /out/git-lfs /usr/local/bin/git-lfs')
        ->not->toContain('/alpine/edge/')
        ->not->toContain('GIT_LFS_APK')
        ->not->toContain('git-lfs-${GIT_LFS_VERSION}.apk')
        ->not->toContain('apk add --no-cache "${GIT_LFS_APK}"')
        ->not->toContain('apk verify "${GIT_LFS_APK}"')
        ->not->toMatch('/\bapk\s+add\b[^\n]*\bgit-lfs\b/')
        ->not->toContain('--allow-untrusted');
});

it('builds cloudflared with the patched pinned gRPC dependency', function (): void {
    $root = productionImageRuntimeContentRoot();
    $dockerfile = (string) file_get_contents($root.'/docker/production/Dockerfile');

    expect($dockerfile)
        ->toContain('ARG CLOUDFLARED_GRPC_VERSION=v1.82.1')
        ->toContain('ARG CLOUDFLARED_X_NET_VERSION=v0.56.0')
        ->toContain('ARG CLOUDFLARED_X_TEXT_VERSION=v0.39.0')
        ->toContain('ARG CLOUDFLARED_GO_MOD_SHA256=e0f8109466e24fe9cb240c700025acdc4ec16b054702ea95f7f84e6a746120b1')
        ->toContain('ARG CLOUDFLARED_GO_SUM_SHA256=15826bd98600e4f7ebbe7f4042145037fb40a2e9739251a388eb6e46ca6c5c52')
        ->toContain('ARG CLOUDFLARED_VENDOR_MODULES_SHA256=a275b5c08eb99e8d9c4c3e877add4f73a2a716306420a7e90fd704411b43a5de')
        ->toContain('go get "google.golang.org/grpc@${CLOUDFLARED_GRPC_VERSION}" "golang.org/x/net@${CLOUDFLARED_X_NET_VERSION}" "golang.org/x/text@${CLOUDFLARED_X_TEXT_VERSION}"')
        ->toContain('$2 == "golang.org/x/net" && $3 == expected')
        ->toContain('go mod verify')
        ->toContain('go mod vendor')
        ->toContain('go version -m /out/cloudflared')
        ->toContain('$2 == "google.golang.org/grpc" && $3 == expected')
        ->toContain('cloudflared --version | grep -F "cloudflared version ${CLOUDFLARED_VERSION}"');
});

it('generates and fences Windows testing-host key material with named volumes', function (): void {
    $root = productionImageRuntimeContentRoot();

    foreach ([
        'docker-compose.windows.yml',
        'other/nightly/docker-compose.windows.yml',
    ] as $composeFile) {
        $compose = Yaml::parseFile($root.'/'.$composeFile);
        $testingHost = $compose['services']['coolify-testing-host'] ?? null;
        $coolify = $compose['services']['coolify'] ?? null;

        expect($testingHost)->toBeArray()
            ->and($coolify)->toBeArray();

        $testingHostVolumes = $testingHost['volumes'] ?? [];
        $coolifyVolumes = $coolify['volumes'] ?? [];
        $legacyFixtureVolumes = array_values(array_filter(
            [...$testingHostVolumes, ...$coolifyVolumes],
            static fn (mixed $volume): bool => str_contains(
                is_string($volume) ? $volume : (string) ($volume['source'] ?? ''),
                'development-private_key',
            ),
        ));

        expect($testingHostVolumes)
            ->toContain('coolify-testing-host-private:/run/coolify-testing-host/private')
            ->toContain('coolify-testing-host-public:/run/coolify-testing-host/public')
            ->and(implode(' ', $testingHost['entrypoint'] ?? []))
            ->toContain('/usr/local/bin/coolify-testing-host-keygen')
            ->toContain('exec /usr/local/bin/coolify-testing-host-entrypoint')
            ->and($testingHost['healthcheck']['test'] ?? null)
            ->toBe([
                'CMD-SHELL',
                'test -s /run/coolify-testing-host/private/testing-host && test -s /run/coolify-testing-host/public/authorized_keys && /usr/sbin/sshd -T >/dev/null',
            ])
            ->and($coolifyVolumes)
            ->toContain('coolify-testing-host-private:/run/coolify-testing-host/private:ro')
            ->and($coolify['environment'] ?? [])
            ->toContain('COOLIFY_TESTING_HOST_PRIVATE_KEY_PATH=/run/coolify-testing-host/private/testing-host')
            ->and($coolify['depends_on']['coolify-testing-host']['condition'] ?? null)
            ->toBe('service_healthy')
            ->and($compose['volumes']['coolify-testing-host-private']['name'] ?? null)
            ->toBe('coolify-testing-host-private')
            ->and($compose['volumes']['coolify-testing-host-public']['name'] ?? null)
            ->toBe('coolify-testing-host-public')
            ->and($legacyFixtureVolumes)
            ->toBe([]);
    }
});
