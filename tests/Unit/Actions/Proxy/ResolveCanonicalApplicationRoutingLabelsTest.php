<?php

use App\Actions\Proxy\ResolveCanonicalApplicationRoutingLabels;
use App\Models\Application;
use App\Models\StandaloneDocker;

/**
 * custom_labels is written in two encodings by different owners: the Livewire
 * editors base64-encode it, while the API's readonly-label regeneration in
 * ApplicationsController stores newline-joined plain text. Resolving the labels
 * must accept both, or a blue-green rollout fails on an application whose row
 * was last written through the API.
 */
$plainLabels = implode("\n", [
    'traefik.enable=true',
    'traefik.http.routers.https-0-abc.rule=Host(`dev.example.com`) && PathPrefix(`/`)',
    'traefik.http.services.https-0-abc.loadbalancer.server.port=3000',
]);

it('resolves labels persisted as newline-joined plain text by the API writer', function () use ($plainLabels) {
    $application = new Application(['custom_labels' => $plainLabels]);

    $labels = ResolveCanonicalApplicationRoutingLabels::run($application, new StandaloneDocker);

    expect($labels)->toBe(explode("\n", $plainLabels));
});

it('resolves labels persisted as base64 by the Livewire editors', function () use ($plainLabels) {
    $application = new Application(['custom_labels' => base64_encode($plainLabels)]);

    $labels = ResolveCanonicalApplicationRoutingLabels::run($application, new StandaloneDocker);

    expect($labels)->toBe(explode("\n", $plainLabels));
});

it('keeps plain text intact when it happens to decode under strict base64', function () {
    // "traefik" is pure base64 alphabet, so a bare strict decode succeeds and
    // yields bytes that are not the label the operator persisted. The
    // round-trip check is what keeps the literal text authoritative.
    $application = new Application(['custom_labels' => 'traefik']);

    $labels = ResolveCanonicalApplicationRoutingLabels::run($application, new StandaloneDocker);

    expect($labels)->toBe(['traefik']);
});

it('splits on carriage returns as well as newlines', function () {
    $application = new Application([
        'custom_labels' => "traefik.enable=true\r\ntraefik.http.routers.r.rule=Host(`a.example.com`)",
    ]);

    $labels = ResolveCanonicalApplicationRoutingLabels::run($application, new StandaloneDocker);

    expect($labels)->toBe([
        'traefik.enable=true',
        'traefik.http.routers.r.rule=Host(`a.example.com`)',
    ]);
});
