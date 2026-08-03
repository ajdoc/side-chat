<?php

namespace Database\Factories;

use App\Models\ArpgCharacter;
use App\Models\User;
use App\Services\Games\ArpgGame;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArpgCharacter>
 */
class ArpgCharacterFactory extends Factory
{
    protected $model = ArpgCharacter::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->unique()->firstName(),
            'class' => $this->faker->randomElement(array_keys(ArpgGame::CLASSES)),
            'level' => 1,
            'xp' => 0,
            'gold' => 0,
            'stats' => ['strength' => 10, 'dexterity' => 10, 'magic' => 10, 'vitality' => 10, 'unspent' => 0],
            'skills' => [],
            'skill_points' => 0,
            'inventory' => [],
            'equipment' => [],
            'depth' => 1,
            'last_played_at' => null,
        ];
    }
}
