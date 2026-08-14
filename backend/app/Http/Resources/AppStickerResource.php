<?php

namespace App\Http\Resources;

use App\Models\AppSticker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One sticker, as the wall draws it and as the editor reloads it.
 *
 * @mixin AppSticker
 */
class AppStickerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'content' => $this->content,
            'x' => $this->x,
            'y' => $this->y,
            'z' => $this->z,
            'w' => $this->w,
            'h' => $this->h,
            'rotation' => $this->rotation,
            // Who drew it — the wall names its contributors, which is half of why it reads as
            // a group effort rather than a canvas.
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at,
        ];
    }
}
