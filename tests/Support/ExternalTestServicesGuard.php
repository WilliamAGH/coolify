<?php

namespace Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;
use RuntimeException;

final class ExternalTestServicesGuard
{
    public static function assertSafe(Repository $config, bool $externalServicesConfirmed): void
    {
        $databaseConnection = (string) $config->get('database.default');
        $cacheStore = (string) $config->get('cache.default');
        $databaseConfiguration = self::resolveConfiguration(
            $config->get("database.connections.{$databaseConnection}"),
            'database',
        );
        $cacheConfiguration = $config->get("cache.stores.{$cacheStore}");

        $usesInMemoryDatabase = $databaseConnection === 'testing'
            && ($databaseConfiguration['driver'] ?? null) === 'sqlite'
            && ($databaseConfiguration['database'] ?? null) === ':memory:';
        $usesArrayCache = $cacheStore === 'array'
            && is_array($cacheConfiguration)
            && ($cacheConfiguration['driver'] ?? null) === 'array';

        if ($usesInMemoryDatabase && $usesArrayCache) {
            return;
        }

        if (! $externalServicesConfirmed) {
            throw new RuntimeException('External test services require COOLIFY_EXTERNAL_TEST_SERVICES=true. Refusing to use inherited database or cache configuration.');
        }

        if (! $usesInMemoryDatabase) {
            self::assertDatabaseConfigurationIsSafe($databaseConfiguration);
        }

        if (! $usesArrayCache) {
            if (! is_array($cacheConfiguration) || ($cacheConfiguration['driver'] ?? null) !== 'redis') {
                throw new RuntimeException('External cache tests only support an explicitly confirmed loopback Redis service.');
            }

            $redisConnection = $cacheConfiguration['connection'] ?? 'default';
            $redisLockConnection = $cacheConfiguration['lock_connection'] ?? $redisConnection;

            if (! is_string($redisConnection) || $redisConnection === ''
                || ! is_string($redisLockConnection) || $redisLockConnection === '') {
                throw new RuntimeException('External Redis tests require a loopback host.');
            }

            foreach (array_unique([$redisConnection, $redisLockConnection]) as $connection) {
                $redisConfiguration = self::resolveConfiguration(
                    $config->get("database.redis.{$connection}"),
                    'Redis',
                );

                if (! self::isLoopbackHost($redisConfiguration['host'] ?? null)) {
                    throw new RuntimeException('External Redis tests require a loopback host.');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private static function assertDatabaseConfigurationIsSafe(array $configuration): void
    {
        foreach (self::databaseEndpointConfigurations($configuration) as $endpointConfiguration) {
            $databaseHosts = $endpointConfiguration['host'] ?? null;
            $databaseHosts = is_array($databaseHosts) ? $databaseHosts : [$databaseHosts];
            $databaseName = $endpointConfiguration['database'] ?? null;

            if ($databaseHosts === []
                || ! is_string($databaseName)
                || ! str_ends_with($databaseName, '_testing')
                || array_any($databaseHosts, fn (mixed $host): bool => ! self::isLoopbackHost($host))) {
                throw new RuntimeException('External database tests require a loopback host and a database name ending in _testing.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<array<string, mixed>>
     */
    private static function databaseEndpointConfigurations(array $configuration): array
    {
        $endpointConfigurations = [$configuration];

        foreach (['read', 'write'] as $connectionType) {
            if (! array_key_exists($connectionType, $configuration)) {
                continue;
            }

            $overrides = $configuration[$connectionType];

            if (! is_array($overrides) || $overrides === []) {
                throw new RuntimeException('External database tests require a loopback host and a database name ending in _testing.');
            }

            $overrides = array_is_list($overrides) ? $overrides : [$overrides];

            foreach ($overrides as $override) {
                if (! is_array($override)) {
                    throw new RuntimeException('External database tests require a loopback host and a database name ending in _testing.');
                }

                $endpointConfiguration = array_merge($configuration, $override);
                unset($endpointConfiguration['read'], $endpointConfiguration['write']);
                $endpointConfigurations[] = $endpointConfiguration;
            }
        }

        return $endpointConfigurations;
    }

    /**
     * @return array<string, mixed>
     */
    private static function resolveConfiguration(mixed $configuration, string $service): array
    {
        if (is_string($configuration)) {
            self::assertUrlIsWellFormed($configuration, $service);
        } elseif (is_array($configuration)) {
            if (array_key_exists('url', $configuration) && $configuration['url'] !== null && $configuration['url'] !== '') {
                if (! is_string($configuration['url'])) {
                    throw new RuntimeException("External {$service} configuration URL is malformed.");
                }

                self::assertUrlIsWellFormed($configuration['url'], $service);
            }
        } else {
            throw new RuntimeException("External {$service} configuration is missing or invalid.");
        }

        try {
            return (new ConfigurationUrlParser)->parseConfiguration($configuration);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException("External {$service} configuration URL is malformed.", previous: $exception);
        }
    }

    private static function assertUrlIsWellFormed(string $url, string $service): void
    {
        $components = parse_url($url);

        if ($url === ''
            || trim($url) !== $url
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || $components === false
            || ! isset($components['scheme'], $components['host'])
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $url) === 1
            || preg_match('/[\x00-\x1F\x7F]/', rawurldecode($url)) === 1) {
            throw new RuntimeException("External {$service} configuration URL is malformed.");
        }
    }

    private static function isLoopbackHost(mixed $host): bool
    {
        if (! is_string($host)) {
            return false;
        }

        if ($host === '[::1]') {
            $host = '::1';
        }

        return in_array(strtolower($host), ['127.0.0.1', '::1', 'localhost'], true);
    }
}
