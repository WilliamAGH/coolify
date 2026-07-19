<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Enums\ProxyTypes;
use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

class CompileControlPlaneStaticProxyConfiguration
{
    use AsAction;

    public function handle(
        Server $server,
        string $sourceComposeYaml,
        int $appPort,
        ControlPlaneProxyExposure $exposure = ControlPlaneProxyExposure::Public,
    ): ControlPlaneStaticProxyConfiguration {
        if ($server->isSwarm()) {
            throw new InvalidArgumentException('Control-plane Traefik enrollment does not alter Swarm installations.');
        }
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new InvalidArgumentException('Control-plane proxy enrollment requires the existing Traefik proxy.');
        }

        $proxyComposeYaml = generateDefaultProxyConfiguration($server, save: false);
        if ($proxyComposeYaml === null) {
            throw new InvalidArgumentException('The canonical Traefik proxy configuration could not be generated.');
        }

        return $this->compile($proxyComposeYaml, $sourceComposeYaml, $appPort, $exposure);
    }

    public function compile(
        string $proxyComposeYaml,
        string $sourceComposeYaml,
        int $appPort,
        ControlPlaneProxyExposure $exposure = ControlPlaneProxyExposure::Public,
    ): ControlPlaneStaticProxyConfiguration {
        if ($appPort < 1 || $appPort > 65535) {
            throw new InvalidArgumentException('APP_PORT must be a valid TCP port.');
        }

        $proxy = $this->parseCompose($proxyComposeYaml, 'proxy');
        $source = $this->parseCompose($sourceComposeYaml, 'source');
        $traefik = data_get($proxy, 'services.traefik');
        if (! is_array($traefik)) {
            throw new InvalidArgumentException('The canonical proxy Compose has no Traefik service.');
        }
        if (! is_array(data_get($source, 'services.coolify'))) {
            throw new InvalidArgumentException('The source Compose has no canonical Coolify service.');
        }

        $sourcePorts = data_get($source, 'services.coolify.ports');
        if (! is_array($sourcePorts) || count($sourcePorts) !== 1 || ! $this->publishesContainerPort($sourcePorts[0], 8080)) {
            throw new InvalidArgumentException('Enrollment requires exactly one canonical Coolify APP_PORT publication.');
        }

        $proxyPorts = data_get($proxy, 'services.traefik.ports');
        $proxyCommands = data_get($proxy, 'services.traefik.command');
        if (! is_array($proxyPorts) || ! is_array($proxyCommands)) {
            throw new InvalidArgumentException('The canonical Traefik service must define ports and commands.');
        }
        foreach ($proxyPorts as $port) {
            if ($this->publishesContainerPort($port, 8000)) {
                throw new InvalidArgumentException('The Traefik APP_PORT entrypoint is already owned by another static configuration.');
            }
        }
        foreach ($proxyCommands as $command) {
            if (is_string($command) && str_starts_with(strtolower($command), '--entrypoints.coolify.address=')) {
                throw new InvalidArgumentException('The Traefik APP_PORT entrypoint is already defined.');
            }
        }

        $proxy['services']['traefik']['ports'][] = $exposure->publishedPort();
        $proxy['services']['traefik']['command'][] = '--entrypoints.coolify.address=:8000';
        $replacementProxyYaml = $this->dumpCompose($proxy);
        $sourceOverrideYaml = Yaml::dump([
            'services' => [
                'coolify' => [
                    'ports' => new TaggedValue('reset', []),
                ],
            ],
        ], 6, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE);

        $replacement = $this->parseCompose($replacementProxyYaml, 'replacement proxy');
        if ($replacement !== $proxy) {
            throw new InvalidArgumentException('The enrolled Traefik Compose did not round-trip exactly.');
        }

        return new ControlPlaneStaticProxyConfiguration(
            predecessorProxyYaml: $proxyComposeYaml,
            replacementProxyYaml: $replacementProxyYaml,
            sourceOverrideYaml: $sourceOverrideYaml,
            appPort: $appPort,
            exposure: $exposure,
        );
    }

    /** @return array<string, mixed> */
    private function parseCompose(string $yaml, string $owner): array
    {
        $parsed = Yaml::parse(
            $yaml,
            Yaml::PARSE_CUSTOM_TAGS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_EXCEPTION_ON_ALIAS,
        );
        if (! is_array($parsed)) {
            throw new InvalidArgumentException("The {$owner} Compose must be a YAML mapping.");
        }

        return $parsed;
    }

    /** @param array<string, mixed> $compose */
    private function dumpCompose(array $compose): string
    {
        return Yaml::dump($compose, 12, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE);
    }

    private function publishesContainerPort(mixed $publication, int $containerPort): bool
    {
        if (is_string($publication) || is_int($publication)) {
            $value = preg_replace('/\/(tcp|udp)$/i', '', (string) $publication);

            return preg_match('/(?:^|:)'.preg_quote((string) $containerPort, '/').'$/', $value ?? '') === 1;
        }
        if (is_array($publication)) {
            return (int) ($publication['target'] ?? 0) === $containerPort;
        }

        return false;
    }
}
