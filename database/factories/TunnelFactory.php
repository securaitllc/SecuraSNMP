<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

class TunnelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'tunnel_name' => fake()->unique()->word().'-tunnel',
            'status' => 'up',
            'in_discards' => 0,
            'out_discards' => 0,
            'in_discards_delta' => 0,
            'out_discards_delta' => 0,
            'last_checked_at' => now(),
        ];
    }
}
