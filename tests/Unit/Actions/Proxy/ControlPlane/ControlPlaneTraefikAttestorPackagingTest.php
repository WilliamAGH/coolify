<?php

it('packages the bounded Traefik attestor as a non-root long-running service', function (): void {
    $repositoryRoot = dirname(__DIR__, 5);
    $source = $repositoryRoot.'/scripts/control-plane-traefik-attestor';
    $dockerfile = file_get_contents($repositoryRoot.'/docker/production/Dockerfile');
    $serviceRoot = $repositoryRoot.'/docker/production/etc/s6-overlay/s6-rc.d/control-plane-traefik-attestor';
    $run = file_get_contents($serviceRoot.'/run');

    expect($source)->toBeFile()
        ->and(is_executable($source))->toBeTrue()
        ->and($dockerfile)->toContain('COPY --chown=www-data:www-data --chmod=755 scripts/control-plane-traefik-attestor ./scripts/control-plane-traefik-attestor')
        ->toContain('USER www-data')
        ->toContain('util-linux-misc')
        ->and(trim((string) file_get_contents($serviceRoot.'/type')))->toBe('longrun')
        ->and($serviceRoot.'/dependencies.d/init-script')->toBeFile()
        ->and($repositoryRoot.'/docker/production/etc/s6-overlay/s6-rc.d/user/contents.d/control-plane-traefik-attestor')->toBeFile()
        ->and(is_executable($serviceRoot.'/run'))->toBeTrue()
        ->and($run)->toContain('exec scripts/control-plane-traefik-attestor watch')
        ->not->toContain('docker.sock')
        ->not->toContain('systemctl')
        ->not->toContain('nft ');
});
