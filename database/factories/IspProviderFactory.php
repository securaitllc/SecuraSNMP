<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class IspProviderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'support_phone' => fake()->numerify('1-800-###-####'),
            'account_rep_name' => fake()->name(),
            'account_rep_mobile' => fake()->numerify('###-###-####'),
            'account_rep_phone' => fake()->numerify('###-###-####'),
            'account_rep_email' => fake()->safeEmail(),
            'notes' => null,
        ];
    }
}
