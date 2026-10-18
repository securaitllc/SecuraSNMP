<?php

namespace Database\Factories;

use App\Models\MaintenanceWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceWindow>
 */
class MaintenanceWindowFactory extends Factory
{
    protected $model = MaintenanceWindow::class;

    public function definition(): array
    {
        return [
            'name' => 'Maintenance '.$this->faker->unique()->word(),
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'reason' => 'Planned work',
        ];
    }
}
