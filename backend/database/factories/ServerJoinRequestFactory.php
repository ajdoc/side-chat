<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\ServerJoinRequest> */
class ServerJoinRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'user_id' => User::factory(),
        ];
    }
}
