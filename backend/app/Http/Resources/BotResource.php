<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bot as its server's owner sees it on the management screen.
 *
 * The token is never here — not even a masked version of it. It exists in a response
 * exactly twice: the create, and a rotate. See BotController.
 */
class BotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->server_id,
            'description' => $this->description,
            // The webhook, minus its secret: the owner needs to see where events go, which
            // ones, and whether delivery is actually working.
            'webhook_url' => $this->webhook_url,
            'events' => $this->resource->subscribedEvents(),
            // False once delivery has been switched off after too many failures — the one
            // state that looks like "the bot is broken" and has no other visible symptom.
            'webhook_enabled' => $this->webhook_url !== null && $this->webhook_disabled_at === null,
            'webhook_failures' => (int) $this->webhook_failures,
            'webhook_disabled_at' => $this->webhook_disabled_at,
            // Null once the token has never been used — i.e. nobody has wired it up yet.
            'last_used_at' => $this->last_used_at,
            'created_at' => $this->created_at,
            // The account it posts as: name and avatar are edited through this bot, so the
            // screen that renames it reads them from here.
            'user' => new UserResource($this->whenLoaded('user')),
            // Who to ask about it. A name, not an account — the creator may have left.
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
        ];
    }
}
