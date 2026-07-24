<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Side Desk note as the client renders it: the whole markdown body, plus who last saved
 * it and when, for the "edited by" line. There's no id — a note is addressed by its surface,
 * never on its own.
 *
 * `version` is the revision the body belongs to; an editor echoes it back as `base_version`
 * on its next save so a concurrent edit is merged instead of overwritten
 * ({@see \App\Models\SpaceNote::applyEdit()}).
 */
class SpaceNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'content' => $this->content,
            'version' => $this->version,
            'updated_by' => new UserResource($this->whenLoaded('editor')),
            'updated_at' => $this->updated_at,
        ];
    }
}
