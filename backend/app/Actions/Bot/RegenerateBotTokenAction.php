<?php

namespace App\Actions\Bot;

use App\Models\Bot;

final class RegenerateBotTokenAction
{
    /**
     * Issues a new token and invalidates the old one in the same write — there is one
     * `token_hash` column, so a leaked credential stops working the instant its replacement
     * is shown. That makes this the revoke button as well as the rotate button.
     */
    public function handle(Bot $bot): string
    {
        $token = Bot::generateToken();

        $bot->update(['token_hash' => Bot::hashToken($token)]);

        return $token;
    }
}
