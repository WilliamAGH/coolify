<?php

namespace App\Actions\Server {
    use Illuminate\Support\Collection;

    function remote_process(Collection|array $commands): Collection
    {
        return $commands instanceof Collection ? $commands : collect($commands);
    }
}

namespace {
    use App\Actions\Server\InstallDocker;
    use App\Models\Server;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Str;
    use Tests\TestCase;

    uses(TestCase::class, RefreshDatabase::class);

    it('delegates managed-server daemon mutation to the canonical installer entrypoint', function (): void {
        $certificates = Mockery::mock();
        $certificates->shouldReceive('where')->andReturnSelf();
        $certificates->shouldReceive('exists')->andReturnTrue();

        $server = Mockery::mock(Server::class)->makePartial();
        $server->id = 0;
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
            ->toContain('24 false false')
            ->toContain('if [ "$DAEMON_CONFIG_RESULT" = changed ]')
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
