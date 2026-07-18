<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One committed stroke on a side chat's whiteboard, as the client draws it. `payload` is
 * passed straight through — its shape depends on `kind` and is the whiteboard engine's
 * contract, not the API's, so the server stays agnostic to how a mark is drawn.
 */
class WhiteboardStrokeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'payload' => $this->payload,
            'client_id' => $this->client_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at,
        ];
    }
}
