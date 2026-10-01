<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => fake()->word().'-sw01',
            'ip_address' => fake()->unique()->localIpv4(),
            'vendor' => fake()->randomElement(['juniper', 'silverpeak']),
            'model' => fake()->randomElement(['EX2300', 'EX3400', 'EX4000', 'EX4650', 'EC10104']),
            'role' => fake()->randomElement(['switch', 'edgeconnect']),
            'snmp_version' => 'v2c',
            'snmp_community' => 'public',
            'status' => 'active',
        ];
    }
}
