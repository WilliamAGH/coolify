<?php

use App\Actions\Application\BlueGreen\VerifyBlueGreenPublicRecovery;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

it('transports route proof secrets only through unlogged curl config stdin', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $application->forceFill([
        'is_http_basic_auth_enabled' => true,
        'http_basic_auth_username' => 'proof-user',
        'http_basic_auth_password' => 'basic-auth-secret',
    ])->save();
    $deployment = BlueGreenDeactivationScenario::queuedDeployment(
        $application,
        $destination,
        'secret-transport-deployment',
    );
    $acknowledgement = str_repeat('a', 64);
    $probeToken = 'opaque-probe-secret';
    $transports = [];
    config()->set('constants.ssh.mux_enabled', false);
    Process::fake(function (PendingProcess $process) use (&$transports, $acknowledgement) {
        if ($process->input !== null) {
            $transports[] = [
                'command' => is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
                'input' => (string) $process->input,
            ];
        }

        return Process::result(output: "HTTP/1.1 200 OK\r\n".
            BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$acknowledgement}\r\n\r\n");
    });

    $lifecycle = new BlueGreenDeploymentLifecycle(
        $application,
        $deployment,
        $destination,
        $server,
        30,
        static function (): void {},
    );
    (new ReflectionMethod($lifecycle, 'assertHttpRoute'))->invoke(
        $lifecycle,
        ['router' => 'candidate-proof', 'url' => 'https://proof.example.test/'],
        'X-Coolify-Blue-Green-Probe',
        $probeToken,
        $acknowledgement,
    );
    (new VerifyBlueGreenPublicRecovery)->handle(
        $server,
        $application,
        [['router' => 'public-recovery', 'url' => 'https://recovery.example.test/']],
        $acknowledgement,
    );

    expect($transports)->toHaveCount(2);
    foreach ($transports as $transport) {
        expect($transport['command'])
            ->toContain('curl --config -')
            ->not->toContain('proof-user', 'basic-auth-secret', $probeToken)
            ->and($transport['input'])
            ->toStartWith("silent\nshow-error\nhttp1.1\n")
            ->toContain(
                'user = "proof-user:basic-auth-secret"',
                'url = "https://',
            )
            ->not->toContain('curl --config -');
    }
    expect($transports[0]['input'])->toContain(
        'header = "X-Coolify-Blue-Green-Probe: '.$probeToken.'"',
    )->and($transports[1]['input'])->not->toContain($probeToken);
});
