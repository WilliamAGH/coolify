<?php

use Symfony\Component\Yaml\Yaml;

it('keeps user-authored labels separate from generated Compose additions', function () {
    $rawCompose = <<<'YAML'
services:
  web:
    image: nginx:latest
    volumes:
      - type: bind
        source: ./config
        target: /etc/nginx/conf.d
        content: |
          server {
            listen 80;
          }
    labels:
      - "my.custom.label=value"
YAML;

    $sanitizedCompose = Yaml::parse(sanitizeDockerComposeRaw($rawCompose));
    $labels = data_get($sanitizedCompose, 'services.web.labels');

    expect($labels)->toBe(['my.custom.label=value'])
        ->and(collect($labels)->contains(fn (string $label): bool => str_contains($label, 'traefik.')))->toBeFalse()
        ->and(collect($labels)->contains(fn (string $label): bool => str_contains($label, 'coolify.managed')))->toBeFalse()
        ->and(data_get($sanitizedCompose, 'services.web.volumes.0'))->not->toHaveKey('content');
});
