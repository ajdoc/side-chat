<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'owner_id' => $this->owner_id,
            'is_owner' => $request->user()?->id === $this->owner_id,
            'invite_code' => $this->invite_code,
            'invite_url' => rtrim((string) config('app.frontend_url'), '/').'/invite/'.$this->invite_code,
            'pending_requests_count' => $this->whenCounted('joinRequests'),
            'channels' => ChannelResource::collection($this->whenLoaded('channels')),
            'created_at' => $this->created_at,
        ];
    }
}
