<?php

namespace Database\Factories;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Friendship> */
class FriendshipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'friend_id' => User::factory(),
            'status' => Friendship::PENDING,
        ];
    }

    public function accepted(): static
    {
        return $this->state(['status' => Friendship::ACCEPTED]);
    }

    public function blocked(): static
    {
        return $this->state(['status' => Friendship::BLOCKED]);
    }

    /**
     * The pair key is derived, never passed in — leaving it to the caller is how you end up
     * with a row whose unique index no longer describes the two people in it.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Friendship $friendship) {
            $friendship->pair_key = Friendship::pairKey($friendship->user_id, $friendship->friend_id);
        });
    }
}
