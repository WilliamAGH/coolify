<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Providers\TelescopeServiceProvider;
use Laravel\Telescope\Telescope;
use Tests\TestCase;

uses(TestCase::class);

it('redacts both control-plane proof headers from request telemetry', function (): void {
    $originalHiddenHeaders = Telescope::$hiddenRequestHeaders;
    $provider = new class(app()) extends TelescopeServiceProvider
    {
        public function applySensitiveRequestDetails(): void
        {
            $this->hideSensitiveRequestDetails();
        }
    };

    try {
        $provider->applySensitiveRequestDetails();
        $hiddenHeaders = array_map('strtolower', Telescope::$hiddenRequestHeaders);

        expect($hiddenHeaders)
            ->toContain(strtolower(ControlPlaneDynamicConfiguration::HEALTH_PROOF_HEADER))
            ->toContain(strtolower(ControlPlaneDynamicConfiguration::AUTHENTICATION_PROXY_PROOF_HEADER));
    } finally {
        Telescope::$hiddenRequestHeaders = $originalHiddenHeaders;
    }
});
