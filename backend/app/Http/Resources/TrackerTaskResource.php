<?php

namespace App\Http\Resources;

use App\Models\TrackerTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One task, as the board row and the detail pane both draw it.
 *
 * The board needs the whole row it renders — key, title, status, priority, assignee, due date,
 * tags — so all of that goes out on every task rather than being fetched again when one is
 * opened. What's left for the detail request is the two lists that would be wasteful in a
 * board of fifty: the comments and the history.
 *
 * @mixin TrackerTask
 */
class TrackerTaskResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'number' => $this->number,
            // Composed server-side so every surface prints the same reference, rather than each
            // client re-joining the two halves and one of them getting the separator wrong.
            'key' => $this->key(),
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'creator' => new UserResource($this->whenLoaded('creator')),
            // A plain Y-m-d: a due date is a day, not an instant. See the migration.
            'due_date' => $this->due_date?->toDateString(),
            'position' => $this->position,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'tags' => AppTagResource::collection($this->whenLoaded('tags')),
            // Only on the detail view. Absent rather than empty on a board listing, so the
            // client can tell "no comments" from "didn't ask".
            'comments' => AppCommentResource::collection($this->whenLoaded('comments')),
            'activity' => AppActivityResource::collection($this->whenLoaded('activity')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
