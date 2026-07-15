<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\ChannelRead> */
class ChannelReadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'user_id' => User::factory(),
            'last_read_message_id' => null,
            'read_at' => now(),
        ];
    }
}
