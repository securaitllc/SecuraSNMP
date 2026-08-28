<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeviceAlarmFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'alarm_id' => 'ALM-'.fake()->unique()->numberBetween(1000, 9999),
            'description' => fake()->sentence(),
            'first_seen_at' => now(),
            'cleared_at' => null,
        ];
    }
}
