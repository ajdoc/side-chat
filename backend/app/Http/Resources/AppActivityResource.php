<?php

namespace App\Http\Resources;

use App\Models\AppActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of an item's history.
 *
 * `kind` plus `data` rather than a rendered sentence: the client owns the wording, so a
 * history written today still reads in whatever language and phrasing a later release uses,
 * and a change of copy isn't a data migration.
 *
 * @mixin AppActivity
 */
class AppActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'data' => $this->data ?? [],
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at,
        ];
    }
}
