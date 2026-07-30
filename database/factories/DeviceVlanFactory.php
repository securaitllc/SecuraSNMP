<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeviceVlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'vlan_id' => fake()->unique()->numberBetween(1, 4094),
            'name' => fake()->randomElement(['DATA', 'VOICE', 'MGMT', 'GUEST']),
            'status' => 'active',
            'last_seen_at' => now(),
        ];
    }
}
