<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\SideChatForum;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SideChatForum> */
class SideChatForumFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            // Unique per channel in the schema, so the name has to be unique here too —
            // two forums made by the same test would otherwise collide on the index.
            'name' => fake()->unique()->words(2, true),
            'position' => 0,
        ];
    }
}
