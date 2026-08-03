<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BadgeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->server_id,
            'name' => $this->name,
            'emoji' => $this->emoji,
            'color' => $this->color,
            'description' => $this->description,
            // Only where it's been counted — the management screen wants it, and the
            // member list rendering fifty badges very much does not.
            'holders_count' => $this->whenCounted('holders'),
        ];
    }
}
