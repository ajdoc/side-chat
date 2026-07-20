<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Open Canvas card as its Vue renderer draws it. `content` is passed through verbatim —
 * its shape depends on `kind` and is the renderer's contract, not the API's (the same stance
 * {@see WhiteboardStrokeResource} takes on a stroke's payload).
 *
 * @mixin \App\Models\CanvasItem
 */
class CanvasItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'content' => $this->content,
            'x' => $this->x,
            'y' => $this->y,
            'w' => $this->w,
            'h' => $this->h,
            'z' => $this->z,
            'widget' => new WidgetResource($this->whenLoaded('widget')),
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at,
        ];
    }
}
