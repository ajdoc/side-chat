<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

final class SocialLoginAction
{
    /** Links to an existing account by email where possible, otherwise creates one. */
    public function handle(string $provider, SocialiteUser $socialUser): User
    {
        $user = $socialUser->getEmail()
            ? User::where('email', $socialUser->getEmail())->first()
            : User::where('provider', $provider)->where('provider_id', $socialUser->getId())->first();

        if ($user) {
            $user->update([
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'avatar' => $user->avatar ?: $socialUser->getAvatar(),
            ]);

            return $user;
        }

        return User::create([
            'name' => $socialUser->getName() ?: $socialUser->getNickname() ?: 'User',
            'email' => $socialUser->getEmail(),
            'avatar' => $socialUser->getAvatar(),
            'provider' => $provider,
            'provider_id' => $socialUser->getId(),
            'password' => bcrypt(Str::random(40)),
        ]);
    }
}
