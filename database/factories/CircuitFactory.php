<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class CircuitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'isp_name' => fake()->randomElement(['AT&T', 'Comcast', 'Spectrum', 'Lumen']),
            'circuit_type' => fake()->randomElement(['fiber', 'cable']),
            'circuit_id' => strtoupper(fake()->bothify('CKT-####??')),
            'account_number' => fake()->numerify('##########'),
            'support_phone' => fake()->numerify('1-800-###-####'),
            'monitored_ip' => fake()->unique()->localIpv4(),
            'subnet' => '255.255.255.252',
            'status' => 'up',
        ];
    }
}
