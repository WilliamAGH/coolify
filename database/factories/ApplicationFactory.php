<?php

namespace Database\Factories;

use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Faker person names carry apostrophes ("Golden O'Keefe") often
            // enough to matter, and the application refuses them: a name reaches
            // Docker labels and generated Compose, so NAME_PATTERN excludes
            // them deliberately. A factory that emits one produces a model the
            // product's own validation rejects, which surfaces as an unrelated
            // save silently doing nothing.
            'name' => ValidationPatterns::toName(fake()->unique()->name()),
            'destination_id' => 1,
            'git_repository' => fake()->url(),
            'git_branch' => fake()->word(),
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'environment_id' => 1,
            'destination_id' => 1,
        ];
    }
}
