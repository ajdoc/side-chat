<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ThreadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'side_chat_id' => $this->side_chat_id,
            'message_id' => $this->message_id,
            'name' => $this->name,
            'replies_count' => $this->whenCounted('messages'),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'parent_message' => new MessageResource($this->whenLoaded('parentMessage')),
            'created_at' => $this->created_at,
        ];
    }
}
