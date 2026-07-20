<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Side Space note as the client renders it: the whole markdown body, plus who last saved
 * it and when, for the "edited by" line. There's no id — a note is addressed by its surface,
 * never on its own.
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
            'updated_by' => new UserResource($this->whenLoaded('editor')),
            'updated_at' => $this->updated_at,
        ];
    }
}
