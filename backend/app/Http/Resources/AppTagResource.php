<?php

namespace App\Http\Resources;

use App\Models\AppTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One tag. `label` is what's drawn; `name` is the normalized form the API matches on.
 *
 * @mixin AppTag
 */
class AppTagResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->label,
            'color' => $this->color,
            // How many things wear it, when the listing counted them — the tag manager shows it
            // so you can see what you're about to delete.
            'usage_count' => $this->whenNotNull($this->taggables_count),
        ];
    }
}
