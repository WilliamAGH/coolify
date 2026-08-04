<?php

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function magicVariableModifierApplication(string $rawCompose): Application
{
    $team = Team::query()->create([
        'name' => 'Magic variable modifier team',
        'description' => 'Fixture owner for Compose magic variable modifiers.',
        'personal_team' => false,
        'show_boarding' => false,
    ]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);

    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '5',
        'docker_compose_raw' => $rawCompose,
        'fqdn' => null,
    ]);

    parseDockerComposeFile($application, isNew: true);

    return $application->refresh();
}

it('strips compose interpolation modifiers from generated magic variable keys', function () {
    // `${SERVICE_PASSWORD_ADMIN:?}` previously produced the key
    // `SERVICE_PASSWORD_ADMIN:?`, which is not a valid environment variable key
    // and aborted parsing for the whole application.
    $application = magicVariableModifierApplication(<<<'YAML'
services:
  app:
    image: 'nginx:alpine'
    environment:
      - 'ADMIN_PASSWORD=${SERVICE_PASSWORD_ADMIN:?}'
      - 'FALLBACK_PASSWORD=${SERVICE_PASSWORD_FALLBACK:-}'
      - 'REQUIRED_KEY=${SERVICE_BASE64_ENCRYPTION:?}'
YAML);

    $keys = $application->environment_variables()->pluck('key')->all();

    expect($keys)->toContain('SERVICE_PASSWORD_ADMIN')
        ->and($keys)->toContain('SERVICE_PASSWORD_FALLBACK')
        ->and($keys)->toContain('SERVICE_BASE64_ENCRYPTION');

    foreach ($keys as $key) {
        expect($key)->not->toContain(':')
            ->and($key)->not->toContain('?')
            ->and($key)->not->toContain('-');
    }
});

it('keeps the default value of an ordinary variable with a modifier', function () {
    $application = magicVariableModifierApplication(<<<'YAML'
services:
  app:
    image: 'nginx:alpine'
    environment:
      - 'WITH_DEFAULT=${MY_DEFAULTED_VAR:-hello-world}'
      - 'REQUIRED_VAR=${MY_REQUIRED_VAR:?must be set}'
      - 'PLAIN_VAR=${MY_PLAIN_VAR}'
YAML);

    $variables = $application->environment_variables()->pluck('value', 'key');

    expect($variables)->toHaveKey('MY_DEFAULTED_VAR')
        ->and($variables['MY_DEFAULTED_VAR'])->toBe('hello-world')
        ->and($variables)->toHaveKey('MY_REQUIRED_VAR')
        ->and($variables)->toHaveKey('MY_PLAIN_VAR');
});

it('parses a required-modifier default that contains a hyphen', function () {
    // Shape taken from templates/compose/palworld.yaml. The previous derivation
    // split on the first `-` inside the default text and produced the key
    // `SERVER_NAME:?palworld`, which fails key validation and aborted parsing of
    // the whole resource. Coolify templates already treat `:?` text as a default
    // (`${PLAYERS:?16}`), so this makes SERVER_NAME behave like PLAYERS.
    $application = magicVariableModifierApplication(<<<'YAML'
services:
  app:
    image: 'nginx:alpine'
    environment:
      - 'SERVER_NAME=${SERVER_NAME:?palworld-server-docker by Thijs van Loef via Coolify}'
      - 'MAX_PLAYERS=${PLAYERS:?16}'
YAML);

    $variables = $application->environment_variables()->pluck('value', 'key');

    expect($variables)->toHaveKey('SERVER_NAME')
        ->and($variables['SERVER_NAME'])->toBe('palworld-server-docker by Thijs van Loef via Coolify')
        ->and($variables['PLAYERS'])->toBe('16');
});

it('handles the alternate-value modifiers', function () {
    // `${LOG:+debug}` previously yielded the key `LOG:+debug`, which fails key
    // validation and aborted the parse for the whole resource.
    $application = magicVariableModifierApplication(<<<'YAML'
services:
  app:
    image: 'nginx:alpine'
    environment:
      - 'LOG=${LOG:+debug}'
      - 'TRACE=${TRACE+on}'
YAML);

    $keys = $application->environment_variables()->pluck('key')->all();

    expect($keys)->toContain('LOG')
        ->and($keys)->toContain('TRACE');

    foreach ($keys as $key) {
        expect($key)->not->toContain('+')->and($key)->not->toContain(':');
    }
});

it('splits on the leftmost modifier outside nested braces', function () {
    // splitOnOperatorOutsideNested must not treat the inner ${FALLBACK}'s braces
    // as part of the outer variable name.
    $interpolation = composeVariableInterpolation('SERVICE_PASSWORD_ADMIN:-${FALLBACK}');

    expect((string) $interpolation['name'])->toBe('SERVICE_PASSWORD_ADMIN')
        ->and($interpolation['default'])->toBe('${FALLBACK}')
        ->and($interpolation['isRequired'])->toBeFalse();

    $required = composeVariableInterpolation('SERVICE_PASSWORD_ADMIN:?');

    expect((string) $required['name'])->toBe('SERVICE_PASSWORD_ADMIN')
        ->and($required['isRequired'])->toBeTrue();

    $bare = composeVariableInterpolation('SERVICE_PASSWORD_ADMIN');

    expect((string) $bare['name'])->toBe('SERVICE_PASSWORD_ADMIN')
        ->and($bare['default'])->toBeNull();
});

it('generates a usable value for a required magic variable', function () {
    $application = magicVariableModifierApplication(<<<'YAML'
services:
  app:
    image: 'nginx:alpine'
    environment:
      - 'ADMIN_PASSWORD=${SERVICE_PASSWORD_ADMIN:?}'
YAML);

    $generated = $application->environment_variables()
        ->where('key', 'SERVICE_PASSWORD_ADMIN')
        ->firstOrFail();

    expect($generated->value)->toBeString()->not->toBeEmpty();
});

it('parses a compose application that declares no per-service domains without deprecations', function () {
    // `docker_compose_domains` is null for every application that never set a
    // per-service domain, and PHP 8.5 deprecates passing null to json_decode.
    // The warning fired on every parse of the most common shape there is.
    $raised = [];
    set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
        $raised[] = $message;

        return true;
    }, E_DEPRECATED);

    try {
        $application = magicVariableModifierApplication(<<<'YAML'
services:
  web:
    image: 'nginx:alpine'
    environment:
      - 'LOG_LEVEL=${LOG:+debug}'
YAML);
    } finally {
        restore_error_handler();
    }

    expect($application->docker_compose_domains)->toBeNull()
        ->and($raised)->toBe([]);
});
