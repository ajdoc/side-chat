<?php

namespace App\Actions\Bot;

use App\Models\Bot;

final class RegenerateWebhookSecretAction
{
    /**
     * Issues a new signing secret, invalidating the old one.
     *
     * Separate from the API token's rotation on purpose: the two secrets protect opposite
     * directions — the token proves a caller is the bot, the secret proves a delivery is
     * us — and they leak through different doors. Rotating one because the other was
     * exposed would mean an unnecessary outage on the side that was fine.
     *
     * There is a moment mid-rotation where the receiver is checking against the old secret
     * and we're signing with the new one, and those deliveries will be rejected. Genuine
     * zero-downtime rotation needs two live secrets, which needs a table; it isn't worth it
     * until somebody is running a bot they can't briefly restart.
     */
    public function handle(Bot $bot): string
    {
        $secret = Bot::generateWebhookSecret();

        $bot->update(['webhook_secret' => $secret]);

        return $secret;
    }
}
