<?php

namespace Database\Factories;

use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrivateKey>
 */
class PrivateKeyFactory extends Factory
{
    protected $model = PrivateKey::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            // Generate per model; Ed25519 keeps large factory suites fast.
            'private_key' => generateSSHKey('ed25519')['private'],
            'team_id' => Team::factory(),
            'is_git_related' => false,
        ];
    }
}
