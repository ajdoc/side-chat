<?php

namespace App\Http\Resources;

use App\Models\TrackerProject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One project, as its card on the tracker's home and its header on the board.
 *
 * The two counts are what the card's progress bar is made of, and they're aggregates rather
 * than a loaded collection: the home screen draws a dozen of these and none of them needs the
 * tasks themselves.
 *
 * @mixin TrackerProject
 */
class TrackerProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'archived' => (bool) $this->archived,
            'position' => $this->position,
            'creator' => new UserResource($this->whenLoaded('creator')),
            // Absent, not zero, when the listing didn't count them — a project drawn as "0 / 0"
            // because nobody asked for the numbers is a lie the progress bar tells confidently.
            'task_count' => $this->whenNotNull($this->tasks_count),
            'done_count' => $this->whenNotNull($this->done_tasks_count),
            'tasks' => TrackerTaskResource::collection($this->whenLoaded('tasks')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
