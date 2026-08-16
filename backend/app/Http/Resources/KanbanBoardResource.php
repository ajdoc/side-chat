<?php

namespace App\Http\Resources;

use App\Models\KanbanBoard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A board and everything on it, in one response.
 *
 * Unpaginated, like the Sticker Wall and for the same reason: a board is read as a whole — the
 * counts at the top of each column are only true if every card is in hand — and half a board
 * is not a useful thing to render.
 *
 * @mixin KanbanBoard
 */
class KanbanBoardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'columns' => array_values($this->columns ?? []),
            'cards' => KanbanCardResource::collection($this->whenLoaded('cards')),
        ];
    }
}
