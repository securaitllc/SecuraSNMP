<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

class SnmpTrapFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'source_ip' => fake()->ipv4(),
            'trap_oid' => '.1.3.6.1.6.3.1.1.5.3',
            'message' => 'linkDown: ifIndex 2',
            'received_at' => now(),
        ];
    }
}
