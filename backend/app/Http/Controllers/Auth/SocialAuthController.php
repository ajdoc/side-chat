<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\SocialLoginAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialAuthController extends Controller
{
    public function redirect(string $provider): RedirectResponse
    {
        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function callback(string $provider, SocialLoginAction $action): RedirectResponse
    {
        $frontend = rtrim(config('app.frontend_url'), '/');

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (Throwable) {
            return redirect()->away($frontend.'/auth/callback?error=oauth_failed');
        }

        $user = $action->handle($provider, $socialUser);
        $token = $user->createToken('auth')->accessToken;

        return redirect()->away($frontend.'/auth/callback?token='.$token);
    }
}
