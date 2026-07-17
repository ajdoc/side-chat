<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A widget as its card renders it. `state` is passed through verbatim — its shape is the
 * handler's contract with the matching Vue card, and the API layer stays out of it.
 *
 * @mixin \App\Models\Widget
 */
class WidgetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'type' => $this->type,
            'state' => $this->state,
            'created_at' => $this->created_at,
        ];
    }
}
