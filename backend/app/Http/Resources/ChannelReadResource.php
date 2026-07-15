<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelReadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'channel_id' => $this->channel_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'last_read_message_id' => $this->last_read_message_id,
            'read_at' => $this->read_at,
        ];
    }
}
