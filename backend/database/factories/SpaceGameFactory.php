<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\SpaceGame;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpaceGame>
 */
class SpaceGameFactory extends Factory
{
    protected $model = SpaceGame::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'type' => 'amongus',
            'status' => SpaceGame::VOTING,
            'state' => [],
            'votes' => [],
            'created_by' => null,
        ];
    }
}
