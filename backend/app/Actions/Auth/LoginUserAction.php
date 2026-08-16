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

        /*
         * A blocked account gets told why, in the admin's own words.
         *
         * Under `email` so it lands on the form the same way a wrong password does, and only
         * *after* the password check — otherwise the ban notice becomes a way to find out
         * which addresses have accounts.
         */
        if ($user->isBanned()) {
            throw ValidationException::withMessages([
                'email' => [$user->ban_reason ?: 'Your account has been blocked.'],
            ]);
        }

        return $user;
    }
}
