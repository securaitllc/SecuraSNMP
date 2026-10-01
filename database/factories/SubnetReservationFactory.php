<?php

namespace Database\Factories;

use App\Models\SubnetReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubnetReservation>
 */
class SubnetReservationFactory extends Factory
{
    protected $model = SubnetReservation::class;

    public function definition(): array
    {
        return [
            'cidr' => '10.200.'.fake()->unique()->numberBetween(180, 254).'.0/24',
            'site_label' => 'SC'.fake()->unique()->numberBetween(200, 399).' '.fake()->city(),
            'label' => 'Planned service centre',
            'planned_for' => now()->addMonths(3)->toDateString(),
        ];
    }

    public function released(): static
    {
        return $this->state(fn () => [
            'released_at' => now()->subDay(),
            'released_by' => 'Planner',
        ]);
    }
}
