<?php

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

it('removes one-time volume fields while preserving the raw Compose definition', function () {
    $rawCompose = <<<'YAML'
services:
  web:
    image: nginx:latest
    environment:
      APP_ENV: production
    labels:
      - "my.custom.label=value"
    volumes:
      - type: bind
        source: ./nginx.conf
        target: /etc/nginx/nginx.conf
        content: |
          events {}
        isDirectory: false
      - type: bind
        source: ./data
        target: /var/lib/data
        is_directory: true
  worker:
    image: busybox:latest
    volumes:
      - cache:/cache
networks:
  default:
    name: custom-network
volumes:
  cache:
YAML;

    $sanitizedCompose = Yaml::parse(sanitizeDockerComposeRaw($rawCompose));
    $fileVolume = data_get($sanitizedCompose, 'services.web.volumes.0');
    $directoryVolume = data_get($sanitizedCompose, 'services.web.volumes.1');

    expect($fileVolume)->not->toHaveKey('content')
        ->and($fileVolume)->not->toHaveKey('isDirectory')
        ->and($fileVolume)->not->toHaveKey('is_directory')
        ->and($directoryVolume)->not->toHaveKey('content')
        ->and($directoryVolume)->not->toHaveKey('isDirectory')
        ->and($directoryVolume)->not->toHaveKey('is_directory')
        ->and(data_get($sanitizedCompose, 'services.worker.volumes.0'))->toBe('cache:/cache')
        ->and(data_get($sanitizedCompose, 'services.web.image'))->toBe('nginx:latest')
        ->and(data_get($sanitizedCompose, 'services.web.environment.APP_ENV'))->toBe('production')
        ->and(data_get($sanitizedCompose, 'services.web.labels'))->toBe(['my.custom.label=value'])
        ->and(data_get($sanitizedCompose, 'networks.default.name'))->toBe('custom-network')
        ->and($sanitizedCompose)->toHaveKey('volumes.cache');
});

it('leaves Compose files without long-syntax volume metadata unchanged', function () {
    $rawCompose = <<<'YAML'
services:
  web:
    image: nginx:latest
    volumes:
      - ./public:/usr/share/nginx/html:ro
    restart: unless-stopped
YAML;

    expect(Yaml::parse(sanitizeDockerComposeRaw($rawCompose)))
        ->toBe(Yaml::parse($rawCompose));
});

it('surfaces invalid YAML so parsers can retain the original raw Compose value', function () {
    expect(fn () => sanitizeDockerComposeRaw("services:\n  web: ["))
        ->toThrow(ParseException::class);
});
