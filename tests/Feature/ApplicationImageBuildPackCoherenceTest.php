<?php

use App\Enums\BuildPackTypes;

it('exposes dockerimage as a build pack the API can validate and converge to', function (): void {
    expect(BuildPackTypes::tryFrom('dockerimage'))->toBe(BuildPackTypes::DOCKERIMAGE);
});

it('requires an image repository only for the image build pack', function (BuildPackTypes $buildPack, bool $requiresImage): void {
    expect($buildPack->requiresImageRepository())->toBe($requiresImage)
        ->and($buildPack->buildsFromSource())->toBe(! $requiresImage);
})->with([
    'dockerimage' => [BuildPackTypes::DOCKERIMAGE, true],
    'dockerfile' => [BuildPackTypes::DOCKERFILE, false],
    'nixpacks' => [BuildPackTypes::NIXPACKS, false],
    'static' => [BuildPackTypes::STATIC, false],
    'dockercompose' => [BuildPackTypes::DOCKERCOMPOSE, false],
    'railpack' => [BuildPackTypes::RAILPACK, false],
]);

it('keeps every build pack exactly one side of the image/source split', function (): void {
    foreach (BuildPackTypes::cases() as $buildPack) {
        expect($buildPack->requiresImageRepository())->not->toBe($buildPack->buildsFromSource());
    }
});
