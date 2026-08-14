<?php

namespace App\Http\Resources;

use App\Models\SpaceNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Side Desk note as the client renders it: the whole markdown body, plus who last saved
 * it and when, for the "edited by" line.
 *
 * `id` is here for exactly one reason: a note can carry comments, tags and reactions like any
 * other app item, and those hang off a polymorphic id. It is *not* how a note is addressed —
 * every read and write still goes through the surface, because a surface has exactly one note.
 *
 * `version` is the revision the body belongs to; an editor echoes it back as `base_version`
 * on its next save so a concurrent edit is merged instead of overwritten
 * ({@see SpaceNote::applyEdit()}).
 */
class SpaceNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'version' => $this->version,
            'updated_by' => new UserResource($this->whenLoaded('editor')),
            'updated_at' => $this->updated_at,
        ];
    }
}
