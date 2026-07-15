<?php

namespace App\Actions\Auth;

use App\DTOs\Auth\RegisterUserData;
use App\Models\User;

final class RegisterUserAction
{
    public function handle(RegisterUserData $data): User
    {
        return User::create([
            'name' => $data->name,
            'email' => $data->email,
            'password' => $data->password, // hashed via the model cast
        ])->refresh(); // pick up DB defaults (theme prefs)
    }
}
