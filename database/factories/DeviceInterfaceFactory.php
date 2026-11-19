<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeviceInterfaceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'if_index' => fake()->unique()->numberBetween(1, 9999),
            'if_name' => fake()->randomElement(['ge-0/0/0', 'ge-0/0/1', 'xe-0/1/0']),
            'status' => 'up',
            'in_octets' => fake()->numberBetween(0, 1000000000),
            'out_octets' => fake()->numberBetween(0, 1000000000),
            'in_discards' => 0,
            'out_discards' => 0,
            'in_discards_delta' => 0,
            'out_discards_delta' => 0,
            'last_polled_at' => now(),
        ];
    }
}
