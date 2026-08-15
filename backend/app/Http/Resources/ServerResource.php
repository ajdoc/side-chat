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
            // Who may add a discussion to a channel here. Sent to everyone, not just staff: the
            // client has to know whether to offer the button at all.
            'discussion_creation' => $this->discussion_creation,
            // The owner's SFU policy. Sent to everyone for the same reason as the line above:
            // the settings screen has to render the switch in the position it's actually in.
            'sfu_enabled' => (bool) $this->sfu_enabled,
            'owner_id' => $this->owner_id,
            'is_owner' => $request->user()?->id === $this->owner_id,
            // What the *asker* is here, and whether that's enough to run the place. The two
            // are separate because the UI needs both answers: `role` labels them in the
            // member list, `is_staff` gates the settings the owner and admins share.
            'role' => $this->when(
                $request->user() !== null,
                fn () => $this->resource->roleFor($request->user()),
            ),
            'is_staff' => $this->when(
                $request->user() !== null,
                fn () => $this->resource->isStaff($request->user()),
            ),
            'invite_code' => $this->invite_code,
            'invite_url' => rtrim((string) config('app.frontend_url'), '/').'/invite/'.$this->invite_code,
            'pending_requests_count' => $this->whenCounted('joinRequests'),
            'channels' => ChannelResource::collection($this->whenLoaded('channels')),
            'created_at' => $this->created_at,
        ];
    }
}
