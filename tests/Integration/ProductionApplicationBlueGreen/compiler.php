<?php

declare(strict_types=1);

use App\Actions\Proxy\BlueGreenProxyRollbackArtifactCommitter;
use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneDocker;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

require '/var/www/html/vendor/autoload.php';
$applicationContainer = require '/var/www/html/bootstrap/app.php';
$applicationContainer->make(Kernel::class)->bootstrap();

[$script, $action, $activeColorValue, $routingRevisionValue, $operationId, $modeValue] = array_pad($argv, 6, null);
if (! is_string($action)
    || ! is_string($activeColorValue)
    || ! is_string($routingRevisionValue)
    || ! is_string($operationId)
    || ! is_string($modeValue)) {
    fwrite(STDERR, "Usage: php compiler.php <apply|commit|crash-before-write|crash-after-write|crash-after-promotion> <blue|green> <revision> <operation-id> <steady|probe>\n");
    exit(64);
}

$activeColor = BlueGreenDeploymentColor::tryFrom($activeColorValue);
$routingRevision = filter_var($routingRevisionValue, FILTER_VALIDATE_INT);
if ($activeColor === null || ! is_int($routingRevision) || $routingRevision < 0) {
    fwrite(STDERR, "The color or routing revision is invalid.\n");
    exit(64);
}
if (! in_array($modeValue, ['steady', 'probe'], true)) {
    fwrite(STDERR, "The routing mode must be steady or probe.\n");
    exit(64);
}

$server = new Server;
$server->proxy = ['type' => ProxyTypes::TRAEFIK->value];
$serverSettings = new ServerSetting;
$serverSettings->setRawAttributes(['generate_exact_labels' => true]);
$server->setRelation('settings', $serverSettings);

$destination = new StandaloneDocker(['network' => 'production-saga']);
$destination->id = 1;
$destination->setRelation('server', $server);

$application = new Application;
$application->uuid = 'production-saga';
$application->fqdn = 'http://traefik';
$application->ports_exposes = '8080';
$application->redirect = 'both';
$application->is_http_basic_auth_enabled = false;
$applicationSettings = new ApplicationSetting;
$applicationSettings->setRawAttributes([
    'is_force_https_enabled' => false,
    'is_gzip_enabled' => false,
    'is_static' => false,
    'is_stripprefix_enabled' => false,
]);
$application->setRelation('settings', $applicationSettings);
$application->setRelation('destination', $destination);

$probeColor = $activeColor === BlueGreenDeploymentColor::BLUE
    ? BlueGreenDeploymentColor::GREEN
    : BlueGreenDeploymentColor::BLUE;
$probeToken = $modeValue === 'probe'
    ? 'probe:'.hash('sha256', $operationId."\0".$routingRevisionValue)
    : null;
$target = new BlueGreenRoutingTarget(
    destinationId: 1,
    activeColor: $activeColor,
    blueContainerName: 'production-blue',
    greenContainerName: 'production-green',
    port: 8080,
    routingRevision: $routingRevision,
    mode: BlueGreenRoutingMode::Steady,
    probeHeaderName: $modeValue === 'probe' ? 'X-Coolify-Production-Saga' : null,
    probeToken: $probeToken,
    probeColor: $modeValue === 'probe' ? $probeColor : null,
    publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($operationId),
);
$configuration = CompileBlueGreenProxyConfiguration::run($application, $destination, $target);
$rollbackKey = new BlueGreenProxyRollbackKey(
    managedFilename: $configuration->managedFilename,
    operationId: $operationId,
    routingRevision: $routingRevision,
);
$lifecycle = new ReflectionClass(BlueGreenDeploymentLifecycle::class);
$lifecycleMethods = [
    'complete' => $lifecycle->getMethod('complete'),
    'promote' => $lifecycle->getMethod('promote'),
    'rollback' => $lifecycle->getMethod('rollback'),
];
foreach ($lifecycleMethods as $methodName => $method) {
    if (! $method->isPublic()) {
        throw new LogicException("BlueGreenDeploymentLifecycle::{$methodName} must remain a public lifecycle boundary.");
    }
}
$evidence = [
    'action' => $action,
    'activeColor' => $activeColor->value,
    'blueGreenLifecycle' => [
        'classSha256' => hash_file('sha256', $lifecycle->getFileName()),
        'completeMethodIsPublic' => $lifecycleMethods['complete']->isPublic(),
        'promoteMethodIsPublic' => $lifecycleMethods['promote']->isPublic(),
        'rollbackMethodIsPublic' => $lifecycleMethods['rollback']->isPublic(),
    ],
    'compilerClassSha256' => hash_file('sha256', (new ReflectionClass(CompileBlueGreenProxyConfiguration::class))->getFileName()),
    'managedFilename' => $configuration->managedFilename,
    'mode' => $modeValue,
    'probeAcknowledgement' => $target->probeAcknowledgement(),
    'probeHeader' => $target->probeHeaderName,
    'probeToken' => $target->probeToken,
    'publicAcknowledgement' => $target->publicAcknowledgement(),
    'routingRevision' => $routingRevision,
    'writerClassSha256' => hash_file('sha256', (new ReflectionClass(WriteBlueGreenProxyConfiguration::class))->getFileName()),
    'yamlSha256' => $configuration->sha256,
];

$publishEvidence = static function () use ($evidence): void {
    fwrite(STDOUT, json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
};
if ($action === 'crash-before-write') {
    $publishEvidence();
    exit(86);
}

$writer = new WriteBlueGreenProxyConfiguration;
if (in_array($action, ['apply', 'crash-after-write', 'crash-after-promotion'], true)) {
    $process = Process::fromShellCommandline($writer->commandFor('/proxy', $configuration, $rollbackKey));
    $process->setTimeout(30);
    $process->mustRun();
    $publishEvidence();
    if ($action === 'crash-after-write') {
        exit(87);
    }
    if ($action === 'crash-after-promotion') {
        exit(88);
    }
    exit(0);
}

if ($action === 'commit') {
    $process = Process::fromShellCommandline(
        (new BlueGreenProxyRollbackArtifactCommitter)->commandFor('/proxy', $rollbackKey),
    );
    $process->setTimeout(30);
    $process->mustRun();
    $publishEvidence();
    exit(0);
}

fwrite(STDERR, "Unknown compiler action: {$action}\n");
exit(64);
