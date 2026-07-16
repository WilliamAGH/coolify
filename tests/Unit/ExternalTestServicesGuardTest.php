<?php

use Illuminate\Config\Repository;
use Tests\Support\ExternalTestServicesGuard;

function externalTestServicesConfig(
    string $databaseConnection = 'testing',
    string $databaseHost = '',
    string $databaseName = ':memory:',
    string $cacheStore = 'array',
    string $redisHost = '',
    ?string $databaseUrl = null,
    ?string $redisUrl = null,
): Repository {
    return new Repository([
        'database' => [
            'default' => $databaseConnection,
            'connections' => [
                $databaseConnection => [
                    'driver' => $databaseConnection === 'testing' ? 'sqlite' : $databaseConnection,
                    'url' => $databaseUrl,
                    'host' => $databaseHost,
                    'database' => $databaseName,
                ],
            ],
            'redis' => [
                'default' => [
                    'url' => $redisUrl,
                    'host' => $redisHost,
                ],
                'cache' => [
                    'url' => $redisUrl,
                    'host' => $redisHost,
                ],
            ],
        ],
        'cache' => [
            'default' => $cacheStore,
            'stores' => [
                'array' => ['driver' => 'array'],
                'redis' => [
                    'driver' => 'redis',
                    'connection' => 'cache',
                    'lock_connection' => 'default',
                ],
            ],
        ],
    ]);
}

it('accepts the isolated in-memory test services', function () {
    ExternalTestServicesGuard::assertSafe(externalTestServicesConfig(), false);
})->throwsNoExceptions();

it('rejects inherited external services without explicit confirmation', function () {
    ExternalTestServicesGuard::assertSafe(
        externalTestServicesConfig('pgsql', '127.0.0.1', 'coolify_testing', 'redis', '127.0.0.1'),
        false,
    );
})->throws(RuntimeException::class, 'COOLIFY_EXTERNAL_TEST_SERVICES=true');

it('rejects a confirmed database that is not an isolated test database', function () {
    ExternalTestServicesGuard::assertSafe(
        externalTestServicesConfig('pgsql', '127.0.0.1', 'coolify'),
        true,
    );
})->throws(RuntimeException::class, 'database name ending in _testing');

it('rejects confirmed external services on a non-loopback host', function () {
    ExternalTestServicesGuard::assertSafe(
        externalTestServicesConfig('pgsql', 'database.internal', 'coolify_testing'),
        true,
    );
})->throws(RuntimeException::class, 'loopback host');

it('accepts explicitly confirmed loopback PostgreSQL and Redis test services', function () {
    ExternalTestServicesGuard::assertSafe(
        externalTestServicesConfig('pgsql', '127.0.0.1', 'coolify_testing', 'redis', 'localhost'),
        true,
    );
})->throwsNoExceptions();

it('accepts explicitly confirmed loopback service URLs with encoded components', function () {
    ExternalTestServicesGuard::assertSafe(
        externalTestServicesConfig(
            databaseConnection: 'pgsql',
            databaseHost: 'database.internal',
            databaseName: 'coolify',
            cacheStore: 'redis',
            redisHost: 'redis.internal',
            databaseUrl: 'postgres://postgres@%31%32%37.0.0.1/%63oolify%5Ftesting',
            redisUrl: 'redis://%31%32%37.0.0.1/1',
        ),
        true,
    );
})->throwsNoExceptions();

it('rejects confirmed Redis on a non-loopback host', function () {
    ExternalTestServicesGuard::assertSafe(
        externalTestServicesConfig('testing', '', ':memory:', 'redis', 'redis.internal'),
        true,
    );
})->throws(RuntimeException::class, 'External Redis tests require a loopback host');

it('rejects unsafe database URL overrides', function (string $databaseUrl) {
    ExternalTestServicesGuard::assertSafe(
        externalTestServicesConfig(
            databaseConnection: 'pgsql',
            databaseHost: '127.0.0.1',
            databaseName: 'coolify_testing',
            databaseUrl: $databaseUrl,
        ),
        true,
    );
})->with([
    'remote host' => 'postgres://postgres@database.internal/coolify_testing',
    'encoded remote host' => 'postgres://postgres@database%2Einternal/coolify_testing',
    'non-test database' => 'postgres://postgres@127.0.0.1/coolify',
    'encoded non-test database' => 'postgres://postgres@127.0.0.1/%63oolify',
    'encoded query host override' => 'postgres://postgres@127.0.0.1/coolify_testing?host=database%2Einternal',
    'encoded query database override' => 'postgres://postgres@127.0.0.1/coolify_testing?database=%63oolify',
    'read host override' => 'postgres://postgres@127.0.0.1/coolify_testing?read%5Bhost%5D=database.internal',
    'write host override' => 'postgres://postgres@127.0.0.1/coolify_testing?write%5Bhost%5D=database.internal',
])->throws(RuntimeException::class, 'External database tests require a loopback host and a database name ending in _testing');

it('rejects a URL that turns the named in-memory connection into a remote database', function () {
    ExternalTestServicesGuard::assertSafe(
        externalTestServicesConfig(
            databaseUrl: 'postgres://postgres@database.internal/coolify_testing',
        ),
        true,
    );
})->throws(RuntimeException::class, 'External database tests require a loopback host');

it('rejects malformed database URLs', function (string $databaseUrl) {
    ExternalTestServicesGuard::assertSafe(
        externalTestServicesConfig(
            databaseConnection: 'pgsql',
            databaseHost: '127.0.0.1',
            databaseName: 'coolify_testing',
            databaseUrl: $databaseUrl,
        ),
        true,
    );
})->with([
    'missing scheme' => '127.0.0.1/coolify_testing',
    'invalid port' => 'postgres://127.0.0.1:invalid/coolify_testing',
    'invalid percent encoding' => 'postgres://127.0.0.1/coolify%ZZ_testing',
    'encoded control character' => 'postgres://127.0.0.1/%00coolify_testing',
])->throws(RuntimeException::class, 'External database configuration URL is malformed');

it('rejects unsafe Redis URL overrides', function (string $redisUrl) {
    ExternalTestServicesGuard::assertSafe(
        externalTestServicesConfig(
            cacheStore: 'redis',
            redisHost: '127.0.0.1',
            redisUrl: $redisUrl,
        ),
        true,
    );
})->with([
    'remote host' => 'redis://redis.internal/1',
    'encoded remote host' => 'redis://redis%2Einternal/1',
    'encoded query host override' => 'redis://127.0.0.1/1?host=redis%2Einternal',
])->throws(RuntimeException::class, 'External Redis tests require a loopback host');

it('rejects malformed Redis URLs', function (string $redisUrl) {
    ExternalTestServicesGuard::assertSafe(
        externalTestServicesConfig(
            cacheStore: 'redis',
            redisHost: '127.0.0.1',
            redisUrl: $redisUrl,
        ),
        true,
    );
})->with([
    'missing scheme' => '127.0.0.1/1',
    'invalid port' => 'redis://127.0.0.1:invalid/1',
    'invalid percent encoding' => 'redis://127.0.0.1/%ZZ',
    'encoded control character' => 'redis://127.0.0.1/%00',
])->throws(RuntimeException::class, 'External Redis configuration URL is malformed');
