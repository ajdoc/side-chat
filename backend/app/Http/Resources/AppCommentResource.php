<?php

namespace App\Http\Resources;

use App\Models\AppComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One comment under a work item.
 *
 * Carries its target so a client holding several open items can route an arriving broadcast
 * without having to guess which one it belongs to.
 *
 * @mixin AppComment
 */
class AppCommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // The short name ('tracker_task'), not the FQCN — the wire shouldn't be quoting
            // PHP namespaces at a TypeScript client. See AppMorphMap.
            'commentable_type' => $this->commentable_type,
            'commentable_id' => $this->commentable_id,
            'body' => $this->body,
            'user' => new UserResource($this->whenLoaded('user')),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'created_at' => $this->created_at,
        ];
    }
}
