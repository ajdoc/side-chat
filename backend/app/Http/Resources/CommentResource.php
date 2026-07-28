<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One comment, as it appears in the full comment list (not the aggregated chips).
 *
 * Serves both kinds: a comment on a message and a comment on a side chat *post* (see
 * SideChatComment). They differ only in what they hang off, so exactly one of the two id
 * fields is present and the client keys off whichever it got.
 */
class CommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'message_id' => $this->message_id,
            'side_chat_id' => $this->side_chat_id,
            'body' => $this->body,
            'emoji' => $this->emoji,
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at,
        ];
    }
}
