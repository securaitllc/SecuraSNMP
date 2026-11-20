<?php

namespace Database\Factories;

use App\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    protected $model = NotificationChannel::class;

    public function definition(): array
    {
        return [
            'name' => 'Channel '.$this->faker->unique()->word(),
            'type' => 'webhook',
            'config' => ['url' => 'https://hooks.example.test/'.$this->faker->uuid()],
            'min_severity' => 'warning',
            'enabled' => true,
        ];
    }
}
