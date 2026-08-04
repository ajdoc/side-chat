<?php

namespace App\Http\Resources;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A friendship as one particular person sees it.
 *
 * `user` is always *the other one* and `direction` is always relative to whoever asked for
 * it, because a single row is "you asked Ben" on one screen and "Ana asked you" on the
 * other. Resolving that here rather than in the client is what keeps the pending list from
 * having to know which column it landed in.
 *
 * @mixin Friendship
 */
class FriendshipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $viewerId = $viewer instanceof User ? $viewer->id : 0;

        return [
            'id' => $this->id,
            'status' => $this->status,
            // 'incoming' — they asked you, you may accept. 'outgoing' — you asked, you may
            // only cancel. Meaningless once accepted, and the client ignores it there.
            'direction' => $this->user_id === $viewerId ? 'outgoing' : 'incoming',
            'user' => new UserResource($this->otherUser($viewerId)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
