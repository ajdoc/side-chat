<?php

namespace App\Actions\Bot;

use App\Models\Bot;

final class UpdateBotAction
{
    /**
     * Renames a bot, or changes its blurb or avatar.
     *
     * The name and avatar live on the account (they're what the timeline renders), the
     * description on the registration (it's only ever shown on the management screen) — so
     * a single edit touches both rows.
     *
     * Registering a webhook URL where there wasn't one mints the signing secret, which is
     * returned — the only other moment it exists in readable form besides the create.
     *
     * @param  array<string, mixed>  $changes  Only the keys the caller actually sent: a
     *                                         missing key leaves the field alone, whereas an
     *                                         explicit null clears it.
     * @return array{bot: Bot, webhook_secret: string|null}
     */
    public function handle(Bot $bot, array $changes): array
    {
        $user = array_intersect_key($changes, array_flip(['name', 'avatar']));

        if ($user !== []) {
            $bot->user->update($user);
        }

        $fields = array_intersect_key($changes, array_flip(['description', 'events']));
        $secret = null;

        if (array_key_exists('webhook_url', $changes)) {
            $fields['webhook_url'] = $changes['webhook_url'];

            // A URL is a fresh start: whatever was wrong with the old endpoint is not this
            // one's problem, so the failure count and the switched-off flag both clear.
            // Without this, replacing a dead URL would leave delivery silently off.
            $fields['webhook_failures'] = 0;
            $fields['webhook_disabled_at'] = null;

            if ($changes['webhook_url'] !== null && $bot->webhook_secret === null) {
                $fields['webhook_secret'] = $secret = Bot::generateWebhookSecret();
            }
        }

        // The re-enable button, for an endpoint that fixed itself. Ignored when it agrees
        // with where things already stand.
        if (array_key_exists('webhook_enabled', $changes)) {
            $fields['webhook_disabled_at'] = $changes['webhook_enabled'] ? null : now();
            $fields['webhook_failures'] = 0;
        }

        if ($fields !== []) {
            $bot->update($fields);
        }

        return ['bot' => $bot->load('user', 'creator'), 'webhook_secret' => $secret];
    }
}
