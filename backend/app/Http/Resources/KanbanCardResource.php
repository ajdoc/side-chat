<?php

namespace App\Http\Resources;

use App\Models\KanbanCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One kanban card, as every view of the board draws it — the timeline card, the desk tab, the
 * canvas card and the app channel.
 *
 * The assignee ships as a full user (avatar, name) and the author as a bare name: you point at
 * an assignee, you only read an author. `comments` and `reactions` are counts rather than the
 * things themselves — a board of forty cards wants to show that three of them have a thread,
 * not to load forty threads.
 *
 * @mixin KanbanCard
 */
class KanbanCardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'column' => $this->column,
            'position' => $this->position,
            'text' => $this->text,
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'added_by' => $this->authorName(),
            'comment_count' => $this->whenCounted('comments'),
            'reaction_count' => $this->whenCounted('reactions'),
            'tags' => AppTagResource::collection($this->whenLoaded('tags')),
            'created_at' => $this->created_at,
        ];
    }
}
