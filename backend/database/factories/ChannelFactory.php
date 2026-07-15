<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Channel> */
class ChannelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'name' => fake()->unique()->word(),
            'type' => 'text',
            'position' => 0,
        ];
    }

    public function voice(): static
    {
        return $this->state(fn () => ['type' => 'voice']);
    }
}
