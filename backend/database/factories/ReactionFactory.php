<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Reaction> */
class ReactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'user_id' => User::factory(),
            'emoji' => fake()->randomElement(['👍', '🎉', '😂', '❤️', '🚀']),
        ];
    }
}
