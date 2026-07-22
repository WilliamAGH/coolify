<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use Closure;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class PrepareControlPlaneProxyEnrollmentFromHost
{
    use AsAction;

    private const ABSENT_ARTIFACT = '__COOLIFY_CONTROL_PLANE_ARTIFACT_ABSENT__';

    private const PRESENT_ARTIFACT = '__COOLIFY_CONTROL_PLANE_ARTIFACT_PRESENT__';

    public string $commandSignature = 'control-plane:proxy-enrollment:prepare
        {server_id : Local Coolify server ID}
        {operation_id : New durable enrollment operation ID}
        {host : Existing dashboard DNS name or IPv4 address}
        {expected_revision : Candidate revision identity}
        {expected_member : Candidate color/member identity}
        {--backend=* : Exact candidate container/DNS name; defaults to coolify}
        {--app-port=8000 : Existing dashboard APP_PORT}
        {--public-scheme=https : Existing dashboard scheme}
        {--loopback : Harden APP_PORT to loopback after alternate-route proof}
        {--alternate-route-proven : Confirm the alternate route was proved before loopback hardening}
        {--prepare-only : Reserve artifacts without submitting activation}';

    public string $commandDescription = 'Prepare and optionally submit one fenced native-Traefik control-plane enrollment.';

    public function __construct(
        private readonly PrepareControlPlaneProxyEnrollment $preparer,
        private readonly ResumeControlPlaneProxyEnrollment $resumer,
        private readonly StoreControlPlaneProxyEnrollmentState $stateStore,
        private readonly ReconcileRolledBackControlPlaneProxyEnrollment $rolledBackReconciler,
        private readonly string $sourceProductionComposePath = '/data/coolify/source/docker-compose.prod.yml',
    ) {}

    /**
     * @param  list<string>  $activeBackendDnsNames
     * @param  null|Closure(string): ?string  $remoteExecutor
     */
    public function handle(
        Server $server,
        string $operationId,
        string $token,
        int $appPort,
        ControlPlaneProxyExposure $exposure,
        array $activeBackendDnsNames,
        string $host,
        string $expectedRevision,
        string $expectedMember,
        string $publicScheme = 'https',
        bool $hasProvenAlternateRoute = false,
        bool $submitActivation = false,
        ?Closure $remoteExecutor = null,
    ): ControlPlaneProxyEnrollmentState {
        return $this->stateStore->serializeOperation(
            $server,
            function (Server $lockedServer) use (
                $operationId,
                $token,
                $appPort,
                $exposure,
                $activeBackendDnsNames,
                $host,
                $expectedRevision,
                $expectedMember,
                $publicScheme,
                $hasProvenAlternateRoute,
                $submitActivation,
                $remoteExecutor,
            ): ControlPlaneProxyEnrollmentState {
                $server = $lockedServer;
                $execute = $remoteExecutor ?? static fn (string $command): ?string => instant_remote_process(
                    [$command],
                    $server,
                    timeout: 30,
                    disableMultiplexing: true,
                    retry: false,
                );
                $existingState = $this->stateStore->read($server);
                if ($existingState?->phase === ControlPlaneProxyEnrollmentPhase::RolledBack) {
                    $this->rolledBackReconciler->handle($server, $existingState, $remoteExecutor);
                    $this->stateStore->clearRolledBackIfUnchanged($server, $existingState);
                }
                $sourceComposeYaml = $execute('cat -- '.escapeshellarg($this->sourceProductionComposePath));
                if (! is_string($sourceComposeYaml) || $sourceComposeYaml === '') {
                    throw new RuntimeException('The canonical Coolify production Compose could not be read.');
                }

                $dynamicPath = rtrim((string) $server->proxyPath(), '/')
                    .'/dynamic/'.ControlPlaneDynamicConfiguration::MANAGED_FILENAME;
                $existingDynamicYaml = $this->decodeOptionalArtifact($execute($this->optionalArtifactCommand($dynamicPath)));
                $configurationAcknowledgement = 'ack:'.hash('sha256', json_encode([
                    'operation_id' => $operationId,
                    'token_sha256' => hash('sha256', $token),
                    'server_id' => (int) $server->getKey(),
                    'host' => $host,
                    'revision' => $expectedRevision,
                    'member' => $expectedMember,
                    'backends' => $activeBackendDnsNames,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

                $state = $this->preparer->handle(
                    server: $server,
                    operationId: $operationId,
                    token: $token,
                    appPort: $appPort,
                    exposure: $exposure,
                    sourceComposeYaml: $sourceComposeYaml,
                    existingDynamicYaml: $existingDynamicYaml,
                    activeBackendDnsNames: $activeBackendDnsNames,
                    host: $host,
                    expectedRevision: $expectedRevision,
                    expectedMember: $expectedMember,
                    configurationAcknowledgement: $configurationAcknowledgement,
                    publicScheme: $publicScheme,
                    hasProvenAlternateRoute: $hasProvenAlternateRoute,
                );

                return $submitActivation
                    ? $this->resumer->handle($server, $operationId, $token, $remoteExecutor)
                    : $state;
            },
        );
    }

    public function asCommand(Command $command): int
    {
        $serverId = filter_var($command->argument('server_id'), FILTER_VALIDATE_INT);
        $appPort = filter_var($command->option('app-port'), FILTER_VALIDATE_INT);
        if ($serverId === false || $serverId < 0 || $appPort === false || $appPort < 1 || $appPort > 65535) {
            throw new InvalidArgumentException('The control-plane enrollment server ID or APP_PORT is invalid.');
        }
        $token = $command->secret('Enrollment token');
        if (! is_string($token) || $token === '') {
            throw new InvalidArgumentException('The control-plane enrollment token must not be empty.');
        }
        $backends = $command->option('backend');
        if (! is_array($backends) || array_filter($backends, 'is_string') !== $backends) {
            throw new InvalidArgumentException('The control-plane candidate backend list is invalid.');
        }
        $backends = $backends === [] ? ['coolify'] : array_values($backends);
        $server = Server::query()->find($serverId)
            ?? throw new RuntimeException('The control-plane enrollment server does not exist.');
        $state = $this->handle(
            server: $server,
            operationId: (string) $command->argument('operation_id'),
            token: $token,
            appPort: $appPort,
            exposure: $command->option('loopback')
                ? ControlPlaneProxyExposure::Loopback
                : ControlPlaneProxyExposure::Public,
            activeBackendDnsNames: $backends,
            host: (string) $command->argument('host'),
            expectedRevision: (string) $command->argument('expected_revision'),
            expectedMember: (string) $command->argument('expected_member'),
            publicScheme: (string) $command->option('public-scheme'),
            hasProvenAlternateRoute: (bool) $command->option('alternate-route-proven'),
            submitActivation: ! $command->option('prepare-only'),
        );
        $command->info("Control-plane enrollment phase: {$state->phase->value}");

        return Command::SUCCESS;
    }

    private function optionalArtifactCommand(string $path): string
    {
        $path = escapeshellarg($path);

        return 'if [ -e '.$path.' ] || [ -L '.$path.' ]; then '
            .'test -f '.$path.' && test ! -L '.$path.' || exit 1; '
            .'printf '.escapeshellarg(self::PRESENT_ARTIFACT."\n").'; cat -- '.$path.'; '
            .'else printf '.escapeshellarg(self::ABSENT_ARTIFACT."\n").'; fi';
    }

    private function decodeOptionalArtifact(?string $output): ?string
    {
        if (! is_string($output)) {
            throw new RuntimeException('The existing managed Traefik document could not be inspected.');
        }
        if ($output === self::ABSENT_ARTIFACT."\n" || $output === self::ABSENT_ARTIFACT) {
            return null;
        }
        $prefix = self::PRESENT_ARTIFACT."\n";
        if (! str_starts_with($output, $prefix)) {
            throw new RuntimeException('The existing managed Traefik document inspection was malformed.');
        }
        $artifact = substr($output, strlen($prefix));
        if ($artifact === '') {
            throw new RuntimeException('The existing managed Traefik document is empty.');
        }

        return $artifact;
    }
}
