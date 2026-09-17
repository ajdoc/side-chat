<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sign-up sheet. The list omits `entries` (the wall shows counts); the sheet view loads them.
 *
 * @mixin \App\Models\SignupSheet
 */
class SignupSheetResource extends JsonResource
{
    /** Include the names — true for the sheet view, false for the wall. Needs `entries.person` loaded. */
    public bool $full = false;

    public function full(): static
    {
        $this->full = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'group_id' => $this->group_id,
            'series_id' => $this->series_id,
            'year' => $this->year,
            'event_date' => $this->event_date?->toDateString(),
            'header' => $this->header,
            'columns' => $this->columns,
            'fees' => $this->fees,
            'slots' => $this->slots,
            'locked' => $this->locked_at !== null,
            'archived' => $this->archived_at !== null,
            'counts' => $this->whenLoaded('entries', fn () => $this->entries->countBy('column_key')),
            'entries' => $this->when($this->full, fn () => $this->entries->map(fn ($e) => [
                'id' => $e->id,
                'column_key' => $e->column_key,
                'position' => $e->position,
                'person_id' => $e->person_id,
                'name' => $e->person->name,
                'note' => $e->note,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
