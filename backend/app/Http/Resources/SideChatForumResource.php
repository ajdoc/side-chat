<?php

namespace App\Http\Resources;

use App\Models\SideChatForum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A forum heading, as the side chat panel draws it.
 *
 * No `can_manage` here, deliberately. Whether you may rename or delete a group is
 * {@link \App\Models\SideChatForum::canManageIn}, which asks only about the *channel* — the answer is
 * identical for every heading in the list, so shipping it per row would be the same boolean
 * repeated N times, and one the broadcast (which has no viewer to compute it for) couldn't
 * keep current. It rides on the index response's `meta` instead.
 *
 * @mixin SideChatForum
 */
class SideChatForumResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'name' => $this->name,
            'position' => $this->position,
            'created_at' => $this->created_at,
        ];
    }
}
