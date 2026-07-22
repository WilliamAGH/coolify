<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Actions\Proxy\GetProxyConfiguration;
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

        $proxyComposeYaml = GetProxyConfiguration::run($server);

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

        $source = $this->parseCompose($sourceComposeYaml, 'source');
        if (! is_array(data_get($source, 'services.coolify'))) {
            throw new InvalidArgumentException('The source Compose has no canonical Coolify service.');
        }

        $sourcePorts = data_get($source, 'services.coolify.ports');
        $legacySourcePorts = ['${APP_PORT:-8000}:8080'];
        $bundledSourcePorts = [
            '${APP_PORT:-8000}:8080',
            '${PUSHER_PORT:-${SOKETI_PORT:-6001}}:6001',
            '${TERMINAL_PORT:-6002}:6002',
        ];
        if ($sourcePorts !== $legacySourcePorts && $sourcePorts !== $bundledSourcePorts) {
            throw new InvalidArgumentException('Enrollment requires exactly one canonical Coolify APP_PORT publication.');
        }

        $replacementProxyYaml = $this->compileProxyConfiguration($proxyComposeYaml, $exposure);
        $sourceOverrideYaml = $this->dumpCompose([
            'services' => [
                'coolify' => [
                    'ports' => new TaggedValue('reset', []),
                ],
            ],
        ]);

        return new ControlPlaneStaticProxyConfiguration(
            predecessorProxyYaml: $proxyComposeYaml,
            replacementProxyYaml: $replacementProxyYaml,
            sourceOverrideYaml: $sourceOverrideYaml,
            appPort: $appPort,
            exposure: $exposure,
        );
    }

    public function compileProxyConfiguration(
        string $proxyComposeYaml,
        ControlPlaneProxyExposure $exposure,
    ): string {
        $proxy = $this->parseCompose($proxyComposeYaml, 'proxy');
        if (! is_array(data_get($proxy, 'services.traefik'))) {
            throw new InvalidArgumentException('The canonical proxy Compose has no Traefik service.');
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

        $proxy['services']['traefik']['image'] = get_exact_traefik_image();
        $proxy['services']['traefik']['ports'][] = $exposure->publishedPort();
        $proxy['services']['traefik']['command'][] = '--entrypoints.coolify.address=:8000';
        $replacementProxyYaml = $this->dumpCompose($proxy);

        $replacement = $this->parseCompose($replacementProxyYaml, 'replacement proxy');
        if ($replacement !== $proxy) {
            throw new InvalidArgumentException('The enrolled Traefik Compose did not round-trip exactly.');
        }

        return $replacementProxyYaml;
    }

    public function withHealthProofIdentity(
        ControlPlaneStaticProxyConfiguration $configuration,
        string $healthProofTokenSha256,
        string $authenticationProxyProofSha256,
        string $dynamicSha256,
        string $configurationAcknowledgement,
        string $expectedMember,
        string $expectedRevision,
        int $serverId,
        string $canonicalHost,
    ): ControlPlaneStaticProxyConfiguration {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $healthProofTokenSha256) !== 1) {
            throw new InvalidArgumentException('The control-plane health proof token hash must be a SHA-256 value.');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/D', $authenticationProxyProofSha256) !== 1) {
            throw new InvalidArgumentException('The control-plane authentication-proxy proof hash must be a SHA-256 value.');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/D', $dynamicSha256) !== 1) {
            throw new InvalidArgumentException('The control-plane dynamic document hash must be a SHA-256 value.');
        }
        if (preg_match('/\A[A-Za-z0-9._~+\/=:-]{16,512}\z/D', $configurationAcknowledgement) !== 1) {
            throw new InvalidArgumentException('The control-plane configuration acknowledgement must be opaque and single-line.');
        }
        foreach (['member' => $expectedMember, 'revision' => $expectedRevision] as $role => $value) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
                throw new InvalidArgumentException("The control-plane {$role} is invalid.");
            }
        }
        if ($serverId < 0) {
            throw new InvalidArgumentException('The control-plane attestor server is invalid.');
        }
        $isIpv4Address = filter_var($canonicalHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isDnsName = preg_match('/\A(?=.{1,253}\z)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\z/D', $canonicalHost) === 1;
        if (! $isIpv4Address && ! $isDnsName) {
            throw new InvalidArgumentException('The control-plane attestor host is invalid.');
        }

        $override = $this->parseCompose($configuration->sourceOverrideYaml, 'source override');
        $override['services']['coolify']['environment'] = [
            'COOLIFY_CONTROL_PLANE_HEALTH_ACK' => $configurationAcknowledgement,
            'COOLIFY_CONTROL_PLANE_HEALTH_PROOF_TOKEN_SHA256' => $healthProofTokenSha256,
            'COOLIFY_CONTROL_PLANE_AUTHENTICATION_PROXY_PROOF_SHA256' => $authenticationProxyProofSha256,
            'COOLIFY_CONTROL_PLANE_DYNAMIC_SHA256' => $dynamicSha256,
            'COOLIFY_CONTROL_PLANE_MEMBER' => $expectedMember,
            'COOLIFY_CONTROL_PLANE_REVISION' => $expectedRevision,
            'COOLIFY_TRAEFIK_ATTESTOR_PROBE_HOST' => $canonicalHost,
            'COOLIFY_TRAEFIK_ATTESTOR_PROBE_URL' => 'http://host.docker.internal:'.$configuration->appPort.'/api/health',
            'COOLIFY_TRAEFIK_ATTESTOR_SERVER_ID' => (string) $serverId,
        ];
        $override['services']['coolify']['volumes'] = [
            [
                'type' => 'bind',
                'source' => '/data/coolify/proxy',
                'target' => '/var/www/html/storage/app/control-plane-proxy',
                'read_only' => true,
            ],
            [
                'type' => 'bind',
                'source' => '/data/coolify/control-plane-attestor',
                'target' => '/var/www/html/storage/app/control-plane-attestor',
            ],
        ];

        return new ControlPlaneStaticProxyConfiguration(
            predecessorProxyYaml: $configuration->predecessorProxyYaml,
            replacementProxyYaml: $configuration->replacementProxyYaml,
            sourceOverrideYaml: $this->dumpCompose($override),
            appPort: $configuration->appPort,
            exposure: $configuration->exposure,
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
        $yaml = Yaml::dump($compose, 12, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);

        return str_replace("    ports: !reset\n      []", '    ports: !reset []', $yaml);
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
