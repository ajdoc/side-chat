<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Side Space's map, whole. There is no partial form of this: the browser has to draw every
 * tile and answer "is this solid" for every step, so it gets the entire grid or nothing.
 *
 * @mixin \App\Models\SideSpaceMap
 */
class SideSpaceMapResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'name' => $this->name,
            'width' => $this->width,
            'height' => $this->height,
            'tiles' => $this->tiles,
            'zones' => $this->zones,
            'spawn' => $this->spawn,
            'updated_by' => $this->whenLoaded('editor', fn () => $this->editor?->name),
            'updated_at' => $this->updated_at,
        ];
    }
}
