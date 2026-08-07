<?php

use App\Http\Controllers\Api\DeployController;
use App\Models\Application;
use OpenApi\Attributes\MediaType;
use OpenApi\Attributes\Post;
use OpenApi\Attributes\Property;
use OpenApi\Attributes\Response;
use OpenApi\Attributes\Schema;
use Symfony\Component\Yaml\Yaml;

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

it('keeps emergency deployment recovery contracts synchronized', function () {
    $recoveryFields = [
        'deployment_uuid',
        'status',
        'cancelled',
        'outcome',
        'message',
        'reason_code',
        'correlation_id',
        'claimable',
        'recovery_owner_active',
    ];
    $recoveryFieldTypes = [
        'deployment_uuid' => 'string',
        'status' => 'string',
        'cancelled' => 'boolean',
        'outcome' => 'string',
        'message' => 'string',
        'reason_code' => 'string',
        'correlation_id' => 'string',
        'claimable' => 'boolean',
        'recovery_owner_active' => 'boolean',
    ];
    // The generated 3.1 documents render the nullable failure fields as
    // union types, while the PHP attributes keep type + nullable separate.
    $recoveryDocumentFieldTypes = array_replace($recoveryFieldTypes, [
        'reason_code' => ['string', 'null'],
        'correlation_id' => ['string', 'null'],
    ]);
    $forbiddenMessage = 'You do not have permission to recover this deployment.';

    $recoverOperation = (new ReflectionMethod(DeployController::class, 'recover_deployment'))
        ->getAttributes(Post::class)[0]
        ->newInstance();
    $responses = collect($recoverOperation->responses);
    $successResponse = $responses->first(
        static fn (Response $response): bool => $response->response === 200,
    );
    $forbiddenResponse = $responses->first(
        static fn (Response $response): bool => $response->response === 403,
    );
    $successContent = $successResponse->content[0] ?? null;
    $forbiddenContent = $forbiddenResponse instanceof Response
        ? $forbiddenResponse->content[0] ?? null
        : null;

    expect($successResponse)
        ->toBeInstanceOf(Response::class)
        ->and($successContent)->toBeInstanceOf(MediaType::class)
        ->and($successContent->schema->required)->toBe($recoveryFields)
        ->and(collect($successContent->schema->properties)
            ->mapWithKeys(static fn (Property $property): array => [$property->property => $property->type])
            ->all())->toBe($recoveryFieldTypes)
        ->and($forbiddenResponse)->toBeInstanceOf(Response::class)
        ->and($forbiddenContent)->toBeInstanceOf(MediaType::class)
        ->and($forbiddenContent->schema->required)->toBe(['message'])
        ->and($forbiddenContent->schema->properties[0]->property)->toBe('message')
        ->and($forbiddenContent->schema->properties[0]->type)->toBe('string')
        ->and($forbiddenContent->schema->properties[0]->example)->toBe($forbiddenMessage);

    $documents = [
        json_decode(
            file_get_contents(__DIR__.'/../../openapi.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        ),
        Yaml::parse(file_get_contents(__DIR__.'/../../openapi.yaml')),
    ];

    foreach ($documents as $document) {
        $successSchema = data_get(
            $document,
            'paths./deployments/{uuid}/recover.post.responses.200.content.application/json.schema',
        );
        $forbiddenSchema = data_get(
            $document,
            'paths./deployments/{uuid}/recover.post.responses.403.content.application/json.schema',
        );

        expect($successSchema)
            ->toBeArray()
            ->and($successSchema['required'])->toBe($recoveryFields)
            ->and(collect($successSchema['properties'])
                ->mapWithKeys(static fn (array $property, string $name): array => [$name => $property['type']])
                ->all())->toBe($recoveryDocumentFieldTypes)
            ->and($forbiddenSchema)->toBeArray()
            ->and($forbiddenSchema['required'])->toBe(['message'])
            ->and($forbiddenSchema['properties']['message'])->toMatchArray([
                'type' => 'string',
                'example' => $forbiddenMessage,
            ]);
    }
});
