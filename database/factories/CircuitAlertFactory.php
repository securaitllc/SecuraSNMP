<?php

namespace Database\Factories;

use App\Models\Circuit;
use Illuminate\Database\Eloquent\Factories\Factory;

class CircuitAlertFactory extends Factory
{
    public function definition(): array
    {
        return [
            'circuit_id' => Circuit::factory(),
            'started_at' => now()->subHour(),
            'ended_at' => null,
            'ticket_number' => null,
        ];
    }
}
