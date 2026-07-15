<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Server> */
class ServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'owner_id' => User::factory(),
            'invite_code' => fake()->unique()->bothify('??????????'),
        ];
    }
}
