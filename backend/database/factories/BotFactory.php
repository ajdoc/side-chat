<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bot>
 */
class BotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->bot(),
            'server_id' => Server::factory(),
            'created_by' => User::factory(),
            'description' => fake()->sentence(),
            'token_hash' => Bot::hashToken(Bot::generateToken()),
        ];
    }

    /** A bot whose token the test knows — the only way to authenticate one after creation. */
    public function withToken(string $token): static
    {
        return $this->state(fn () => ['token_hash' => Bot::hashToken($token)]);
    }
}
