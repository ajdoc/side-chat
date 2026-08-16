<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One message, for the audit log.
 *
 * The body is withheld when the message is end-to-end encrypted — not censored, genuinely
 * unavailable: the server holds ciphertext and no key (see e2ee, EncryptionController), so
 * there is nothing to show and pretending otherwise would be the wrong answer to give a
 * moderator. `encrypted` is always present so the screen can say which of the two it is.
 *
 * Everything else about the message — who, where, when, whether it was edited or deleted —
 * is readable regardless, because that's metadata the server has either way.
 */
class AdminMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $encrypted = $this->resource->isEncrypted();

        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'thread_id' => $this->thread_id,
            'type' => $this->type,
            'encrypted' => $encrypted,
            'body' => $encrypted ? null : $this->body,
            'author' => $this->whenLoaded('user', fn () => $this->user?->only(['id', 'name', 'email', 'avatar', 'is_bot'])),
            // Where it was said, resolved to something a human can read: a server channel
            // says "#general in Foo", a DM says which chat. The controller loads what's
            // needed; a message whose channel is gone reports null rather than exploding.
            'channel' => $this->whenLoaded('channel', fn () => $this->channel ? [
                'id' => $this->channel->id,
                'name' => $this->channel->name,
                'type' => $this->channel->type,
                'server_id' => $this->channel->server_id,
                'server_name' => $this->channel->relationLoaded('server') ? $this->channel->server?->name : null,
                'conversation_id' => $this->channel->conversation_id,
            ] : null),
            'attachments_count' => $this->whenCounted('attachments'),
            'edited_at' => $this->edited_at,
            'pinned_at' => $this->pinned_at,
            'created_at' => $this->created_at,
        ];
    }
}
