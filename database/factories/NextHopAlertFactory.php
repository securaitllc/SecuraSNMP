<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

class NextHopAlertFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'started_at' => now()->subHour(),
            'ended_at' => null,
        ];
    }
}
