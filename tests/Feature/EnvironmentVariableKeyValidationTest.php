<?php

use App\Livewire\Project\Shared\EnvironmentVariable\Add;
use Livewire\Livewire;

it('rejects environment variable keys Docker cannot represent in the add form', function (string $key) {
    Livewire::test(Add::class)
        ->set('key', $key)
        ->set('value', 'value')
        ->call('submit')
        ->assertHasErrors(['key' => 'regex']);
})->with([
    'equals' => 'BAD=KEY',
    'starts with digit' => '1BAD',
    'hyphen' => 'BAD-KEY',
    'semicolon' => 'BAD;KEY',
    'space' => 'BAD KEY',
    'command substitution' => 'BAD$(id)',
    'backticks' => 'BAD`id`',
    'pipe' => 'BAD|id',
]);

it('allows Docker-compatible environment variable keys in the add form', function (string $key) {
    Livewire::test(Add::class)
        ->set('key', $key)
        ->set('value', 'value')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('saveKey', function ($event, array $data) use ($key) {
            return data_get($data, 'key') === $key || data_get($data, '0.key') === $key;
        });
})->with([
    'letters' => 'APP_ENV',
    'leading underscore' => '_TOKEN',
    'digits after first character' => 'NODE_VERSION_20',
    'lowercase' => 'node_version',
    'dot' => 'node.name',
    'uppercase dots' => 'XPACK.SECURITY.ENABLED',
]);

it('trims surrounding whitespace in environment variable keys in the add form', function () {
    Livewire::test(Add::class)
        ->set('key', ' node.name ')
        ->set('value', 'value')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('saveKey', function ($event, array $data) {
            return data_get($data, 'key') === 'node.name' || data_get($data, '0.key') === 'node.name';
        });
});
