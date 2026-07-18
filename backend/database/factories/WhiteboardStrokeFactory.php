<?php

namespace Database\Factories;

use App\Models\SideChat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\WhiteboardStroke> */
class WhiteboardStrokeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'side_chat_id' => SideChat::factory(),
            'user_id' => User::factory(),
            'kind' => 'pen',
            'payload' => [
                'color' => '#e11d48',
                'width' => 3,
                'points' => [['x' => 0, 'y' => 0], ['x' => 10, 'y' => 12], ['x' => 22, 'y' => 30]],
            ],
            'client_id' => fake()->uuid(),
        ];
    }
}
