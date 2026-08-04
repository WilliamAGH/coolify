<?php

use App\Models\Application;
use App\Models\Server;
use App\Support\ValidationPatterns;
use Illuminate\Support\Facades\Validator;

/**
 * A factory must produce a model the product's own validation accepts.
 *
 * Faker person names carry apostrophes often enough to matter ("Golden
 * O'Keefe"), and NAME_PATTERN excludes them on purpose because a name reaches
 * Docker labels and generated Compose. A factory emitting one made unrelated
 * Livewire saves abort silently, which surfaced as roughly one flaky failure in
 * twelve full-suite runs rather than as an obvious defect.
 */
it('generates factory names the product accepts', function (string $factory): void {
    $rules = ['name' => ValidationPatterns::nameRules()];

    for ($attempt = 0; $attempt < 300; $attempt++) {
        $name = $factory::factory()->make()->name;

        expect(Validator::make(['name' => $name], $rules)->fails())
            ->toBeFalse("The {$factory} factory produced a name the product refuses: ".var_export($name, true));
    }
})->with([
    'application' => [Application::class],
    'server' => [Server::class],
]);

it('keeps a sanitized name inside the accepted pattern', function (string $raw, string $expected): void {
    expect(ValidationPatterns::toName($raw))->toBe($expected)
        ->and(preg_match(ValidationPatterns::NAME_PATTERN, ValidationPatterns::toName($raw)))->toBe(1);
})->with([
    'apostrophe is removed' => ["Golden O'Keefe", 'Golden OKeefe'],
    'quotes and backticks are removed' => ['a"b`c$d', 'abcd'],
    'collapses whitespace left behind' => ["Bob   '  Smith", 'Bob Smith'],
    'unicode letters survive' => ['Ünïcode Nàme', 'Ünïcode Nàme'],
    'allowed punctuation survives' => ['api-gateway_v2.1 (eu)', 'api-gateway_v2.1 (eu)'],
    'pads a name that sanitizes away' => ['\'"`', 'xxx'],
]);
