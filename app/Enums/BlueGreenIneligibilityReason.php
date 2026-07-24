<?php

namespace App\Enums;

/**
 * Why an application cannot run blue-green deployments right now.
 *
 * The category is control-flow significant: assembly-incomplete reasons
 * describe configuration that has not been finished yet (a skeleton
 * application may still be saved while opted in), while conflict reasons
 * describe configuration that is actively incompatible with blue-green and
 * always fails closed. Branch on the enum, never on message text.
 */
enum BlueGreenIneligibilityReason
{
    case NoStandaloneDockerPrimaryDestination;

    case NoStandaloneDockerDestination;

    case GeneratedReadonlyLabelsRequired;

    case ComposeParseIncomplete;

    case ComposeHttpHealthcheckRequired;

    case HealthcheckRequired;

    case FqdnRequired;

    case BackendPortsRequired;

    case DestinationUnavailableConflict;

    case SwarmDestinationConflict;

    case NonTraefikProxyConflict;

    case DestinationPerServerConflict;

    case RawComposeConflict;

    case ComposeTopologyConflict;

    case HostPortMappingConflict;

    case ConsistentContainerNameConflict;

    case CustomInternalNameConflict;

    case CustomNetworkAliasConflict;

    case CustomDockerRunOptionsConflict;

    case WritableStorageConflict;

    /**
     * True only while the application is merely not configured yet; every
     * active conflict returns false and must fail closed.
     */
    public function isAssemblyIncomplete(): bool
    {
        return match ($this) {
            self::NoStandaloneDockerPrimaryDestination,
            self::NoStandaloneDockerDestination,
            self::GeneratedReadonlyLabelsRequired,
            self::ComposeParseIncomplete,
            self::ComposeHttpHealthcheckRequired,
            self::HealthcheckRequired,
            self::FqdnRequired,
            self::BackendPortsRequired => true,
            default => false,
        };
    }

    /**
     * The canonical operator-facing text. The Docker Compose cases compose
     * their exact detail inside BlueGreenComposeTopology and carry it beside
     * the enum; they have no static message here.
     */
    public function message(): string
    {
        return match ($this) {
            self::NoStandaloneDockerPrimaryDestination => 'Blue-green deployments require a standalone Docker primary destination.',
            self::NoStandaloneDockerDestination => 'Blue-green deployments require at least one standalone Docker destination.',
            self::GeneratedReadonlyLabelsRequired => 'Blue-green deployments require generated, read-only container labels.',
            self::ComposeHttpHealthcheckRequired => 'Blue-green Docker Compose applications require an enabled HTTP Coolify healthcheck for routed failover.',
            self::HealthcheckRequired => 'Blue-green deployments require either an enabled Coolify healthcheck or a detected image healthcheck.',
            self::FqdnRequired => 'Blue-green deployments require at least one FQDN.',
            self::BackendPortsRequired => 'Blue-green deployments require unique valid exposed backend ports. Multiple ports require every FQDN to declare one exposed :port, and every exposed port must have a matching FQDN; static applications use port 80.',
            self::DestinationUnavailableConflict => 'Blue-green deployments require every configured standalone Docker destination to remain available.',
            self::SwarmDestinationConflict => 'Blue-green deployments are not available for Docker Swarm destinations.',
            self::NonTraefikProxyConflict => 'Blue-green deployments require Traefik as the proxy on every configured destination.',
            self::DestinationPerServerConflict => 'Blue-green deployments require one destination per server.',
            self::RawComposeConflict => 'Blue-green deployments do not support raw Docker Compose applications because raw Compose cannot be safely rewritten.',
            self::HostPortMappingConflict => 'Blue-green deployments do not support ports mapped to the host.',
            self::ConsistentContainerNameConflict => 'Blue-green deployments do not support consistent container names.',
            self::CustomInternalNameConflict => 'Blue-green deployments do not support custom container names.',
            self::CustomNetworkAliasConflict => 'Blue-green deployments do not support custom network aliases.',
            self::CustomDockerRunOptionsConflict => 'Blue-green deployments do not support custom Docker run options.',
            self::WritableStorageConflict => 'Blue-green deployments require stateless applications without writable storage.',
            self::ComposeParseIncomplete,
            self::ComposeTopologyConflict => throw new \LogicException('Docker Compose ineligibility detail is composed by BlueGreenComposeTopology and travels beside the enum.'),
        };
    }
}
