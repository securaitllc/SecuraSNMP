<?php

namespace Database\Factories;

use App\Models\Tunnel;
use Illuminate\Database\Eloquent\Factories\Factory;

class TunnelAlertFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tunnel_id' => Tunnel::factory(),
            'started_at' => now()->subHour(),
            'ended_at' => null,
        ];
    }
}
