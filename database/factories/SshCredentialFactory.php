<?php

namespace Database\Factories;

use App\Models\SshCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SshCredential>
 */
class SshCredentialFactory extends Factory
{
    protected $model = SshCredential::class;

    public function definition(): array
    {
        return [
            'name' => 'SSH '.$this->faker->unique()->word(),
            'username' => $this->faker->userName(),
            'password' => $this->faker->password(10, 20),
            'notes' => null,
        ];
    }
}
