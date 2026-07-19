<?php

use App\Livewire\Upgrade;
use App\Models\InstanceSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\ComponentAttributeBag;

it('keeps the upgrade UI on the guarded fork release without upstream discovery', function () {
    config(['constants.coolify.version' => '4.13.1-fork']);
    Cache::shouldReceive('remember')->never();
    $settings = Mockery::mock(InstanceSettings::class);
    $settings->shouldReceive('getAttribute')
        ->once()
        ->with('new_version_available')
        ->andReturn(true);
    $settings->shouldReceive('update')
        ->once()
        ->with(['new_version_available' => false]);
    $component = new class($settings) extends Upgrade
    {
        public function __construct(private readonly InstanceSettings $settings) {}

        protected function instanceSettings(): ?InstanceSettings
        {
            return $this->settings;
        }
    };

    $component->mount();

    expect($component->currentVersion)->toBe('4.13.1-fork')
        ->and($component->latestVersion)->toBe('4.13.1-fork')
        ->and($component->isUpgradeAvailable)->toBeFalse();
});

it('links fork versions to the fork release instead of the upstream tag', function () {
    config([
        'constants.coolify.version' => '4.13.1-fork',
        'constants.coolify.fork_releases_url' => 'https://github.com/WilliamAGH/coolify/releases',
    ]);

    $html = view('components.version', [
        'attributes' => new ComponentAttributeBag,
    ])->render();

    expect($html)->toContain('https://github.com/WilliamAGH/coolify/releases/tag/4.13.1-fork')
        ->not->toContain('coollabsio/coolify/releases/tag');
});

it('keeps stable versions linked to the upstream release', function () {
    config(['constants.coolify.version' => '4.13.1']);

    $html = view('components.version', [
        'attributes' => new ComponentAttributeBag,
    ])->render();

    expect($html)->toContain('https://github.com/coollabsio/coolify/releases/tag/v4.13.1');
});
