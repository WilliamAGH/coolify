<?php

use App\Models\Application;
use OpenApi\Attributes\Property;
use OpenApi\Attributes\Schema;

it('documents application settings under the settings property', function () {
    $schema = (new ReflectionClass(Application::class))
        ->getAttributes(Schema::class)[0]
        ->newInstance();

    $openApi = json_decode(
        file_get_contents(__DIR__.'/../../openapi.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $settingsProperty = collect($schema->properties)
        ->first(fn (mixed $property): bool => $property instanceof Property
            && $property->property === 'settings');

    expect($settingsProperty)
        ->not->toBeNull()
        ->and($openApi['components']['schemas']['Application']['properties'])
        ->toHaveKey('settings')
        ->not->toHaveKey('');
});

it('documents active container destination provenance fields', function () {
    $openApi = json_decode(
        file_get_contents(__DIR__.'/../../openapi.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $items = data_get(
        $openApi,
        'paths./applications/{uuid}/active-container.get.responses.200.content.application/json.schema.properties.provenance.properties.destination.items',
    );

    expect($items['required'])->toBe([
        'destination_id',
        'deployment_uuid',
        'color',
        'routing_revision',
        'container_ids',
    ])->and($items['properties'])->toHaveKeys([
        'destination_id',
        'deployment_uuid',
        'color',
        'routing_revision',
        'container_ids',
    ])->and($items['properties']['container_ids']['items']['type'])->toBe('string');
});

it('documents the active container conflict response body', function () {
    $openApi = json_decode(
        file_get_contents(__DIR__.'/../../openapi.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $schema = data_get(
        $openApi,
        'paths./applications/{uuid}/active-container.get.responses.409.content.application/json.schema',
    );

    expect($schema)
        ->toMatchArray([
            'type' => 'object',
            'required' => ['message'],
            'properties' => [
                'message' => ['type' => 'string'],
            ],
        ]);
});
