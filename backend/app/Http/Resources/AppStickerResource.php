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
    /**
     * Serialise without the drawing.
     *
     * A sticker's `content` is every stroke of it and is unbounded in practice — comfortably
     * past the 10KB a Pusher/Reverb event may carry, which is a hard limit on Laravel Cloud
     * and not one the app can raise. So a broadcast carries the *placement* only and the client
     * fetches the drawing over HTTP, exactly as a Widget does (see the note on `Widget.state`).
     *
     * Placement is the half that changes constantly — every drag is a save — and the half that
     * has to arrive live. The drawing changes when somebody deliberately edits it, which is
     * rare and can afford a request.
     */
    public static function reference(AppSticker $sticker): self
    {
        return (new self($sticker))->withoutContent();
    }

    private bool $withContent = true;

    public function withoutContent(): self
    {
        $this->withContent = false;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Absent — not null — on a broadcast, so the client can tell "no drawing sent" from
            // "an empty drawing" and know to go and fetch it.
            $this->mergeWhen($this->withContent, fn () => ['content' => $this->content]),
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
