<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->city().' Site',
            'address' => fake()->address(),
            'notes' => null,
        ];
    }
}
