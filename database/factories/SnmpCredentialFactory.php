<?php

namespace Database\Factories;

use App\Models\SnmpCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SnmpCredential>
 */
class SnmpCredentialFactory extends Factory
{
    protected $model = SnmpCredential::class;

    public function definition(): array
    {
        return [
            'name' => 'SNMP '.$this->faker->unique()->word(),
            'snmp_version' => 'v2c',
            'snmp_community' => $this->faker->password(8, 16),
            'notes' => null,
        ];
    }
}
