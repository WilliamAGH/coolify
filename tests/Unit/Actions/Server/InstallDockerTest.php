<?php

namespace App\Actions\Server {
    use Illuminate\Support\Collection;
    use Tests\Support\InstallDockerRemoteProcessCapture;

    /**
     * Namespace-level override of the global remote_process() helper. Once this
     * file is loaded the override shadows the global helper for the whole
     * App\Actions\Server namespace for the rest of the process, so it must
     * delegate to the global helper unless capture mode is explicitly enabled —
     * otherwise it silently swallows remote_process() calls from every other
     * action in this namespace (e.g. UpdateCoolify) in later tests.
     */
    function remote_process(Collection|array $commands, ...$args)
    {
        if (InstallDockerRemoteProcessCapture::$enabled) {
            return $commands instanceof Collection ? $commands : collect($commands);
        }

        return \remote_process($commands, ...$args);
    }
}

namespace Tests\Support {
    class InstallDockerRemoteProcessCapture
    {
        public static bool $enabled = false;
    }
}

namespace {
    use App\Actions\Server\InstallDocker;
    use App\Models\Server;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Str;
    use Tests\Support\InstallDockerRemoteProcessCapture;
    use Tests\TestCase;

    uses(TestCase::class, RefreshDatabase::class);

    beforeEach(function (): void {
        InstallDockerRemoteProcessCapture::$enabled = true;
    });

    afterEach(function (): void {
        InstallDockerRemoteProcessCapture::$enabled = false;
    });

    it('delegates managed-server daemon mutation to the canonical installer entrypoint', function (): void {
        $certificates = Mockery::mock();
        $certificates->shouldReceive('where')->andReturnSelf();
        $certificates->shouldReceive('exists')->andReturnTrue();

        $server = Mockery::mock(Server::class)->makePartial();
        $server->id = 42;
        $server->shouldReceive('validateOS')->andReturn(Str::of('debian'));
        $server->shouldReceive('sslCertificates')->andReturn($certificates);
        $server->shouldReceive('isSwarm')->andReturnFalse();

        $commands = InstallDocker::run($server);
        $daemonMutation = $commands->first(
            fn (string $command): bool => str_contains($command, '--configure-docker-daemon'),
        );

        expect($daemonMutation)->toBeString()
            ->toContain(base64_encode(file_get_contents(base_path('scripts/install.sh'))))
            ->toContain('--configure-docker-daemon /etc/docker/daemon.json')
            ->toContain('10.0.0.0/8 24 false false')
            ->toContain('if [ "$DAEMON_CONFIG_RESULT" = changed ]')
            ->toContain('for COOLIFY_CONTAINER in coolify-proxy coolify-sentinel')
            ->toContain("docker inspect --format='{{.State.Running}}'")
            ->toContain('systemctl restart docker && for COOLIFY_CONTAINER in $COOLIFY_SOCKET_MOUNTERS')
            ->toContain('docker restart "$COOLIFY_CONTAINER"')
            ->not->toContain('10.42.0.0/16')
            ->not->toContain('daemon.json.appended')
            ->not->toContain('jq -s')
            ->not->toContain('| bash')
            ->not->toContain('cdn.coollabs.io');

        $nonRootCommand = parseCommandsByLineForSudo(collect([$daemonMutation]), $server)[0];
        expect($nonRootCommand)
            ->toStartWith("sudo bash -c '")
            ->toContain('--configure-docker-daemon /etc/docker/daemon.json')
            ->toEndWith("'");
    });
}
