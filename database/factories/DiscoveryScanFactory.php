<?php

namespace Database\Factories;

use App\Models\DiscoveryScan;
use App\Models\SnmpCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscoveryScan>
 */
class DiscoveryScanFactory extends Factory
{
    protected $model = DiscoveryScan::class;

    public function definition(): array
    {
        return [
            'name' => 'Scan '.$this->faker->unique()->numberBetween(1, 9999),
            'subnets' => ['10.20.5.0/24'],
            'snmp_credential_id' => SnmpCredential::factory(),
            'status' => 'pending',
        ];
    }
}
