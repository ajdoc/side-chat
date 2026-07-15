<?php

namespace App\Actions\Auth;

use App\DTOs\Auth\LoginUserData;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class LoginUserAction
{
    /** @throws ValidationException */
    public function handle(LoginUserData $data): User
    {
        $user = User::where('email', $data->email)->first();

        if (! $user || ! $user->password || ! Hash::check($data->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return $user;
    }
}
