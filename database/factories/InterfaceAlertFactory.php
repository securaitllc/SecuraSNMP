<?php

namespace Database\Factories;

use App\Models\DeviceInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

class InterfaceAlertFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_interface_id' => DeviceInterface::factory(),
            'started_at' => now()->subHour(),
            'ended_at' => null,
        ];
    }
}
