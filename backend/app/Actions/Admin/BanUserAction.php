<?php

namespace App\Actions\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Blocks an account from the site, with a sentence explaining why.
 *
 * The reason is not decoration. It is the entire message the person sees at the login
 * screen (LoginUserAction) and on any request their existing token still makes
 * (EnsureNotBanned), so it's written by the admin doing the banning rather than picked from
 * a list of codes nobody outside the team can read.
 */
final class BanUserAction
{
    public function handle(User $user, User $admin, ?string $reason): User
    {
        DB::transaction(function () use ($user, $admin, $reason) {
            $user->forceFill([
                'banned_at' => now(),
                'ban_reason' => $reason,
                'banned_by' => $admin->getKey(),
            ])->save();

            /*
             * Revoke what they're holding. Without this the ban only starts at their next
             * sign-in, which for somebody sitting in a channel right now is never — the
             * middleware would still stop them, but their websocket subscriptions wouldn't
             * notice, and a revoked token is what actually ends those.
             */
            $user->tokens()->update(['revoked' => true]);
        });

        return $user->fresh();
    }

    /** Lift it. The reason goes with it — a lifted ban isn't history we show anyone. */
    public function lift(User $user): User
    {
        $user->forceFill([
            'banned_at' => null,
            'ban_reason' => null,
            'banned_by' => null,
        ])->save();

        return $user->fresh();
    }
}
