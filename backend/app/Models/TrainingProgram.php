<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A multi-week training program a channel follows. See the create_training_tables migration.
 */
class TrainingProgram extends Model
{
    protected $fillable = ['channel_id', 'title', 'template', 'starts_on', 'content', 'created_by'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'content' => 'array',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function ticks(): HasMany
    {
        return $this->hasMany(TrainingTick::class, 'program_id');
    }

    /** How many weeks the program runs — what a tick's week is checked against. */
    public function weekCount(): int
    {
        return (int) ($this->content['weeks'] ?? 0);
    }

    /** @return array<int, string> */
    public function dayKeys(): array
    {
        return array_column($this->content['days'] ?? [], 'key');
    }

    /** The list-row shape: everything but the (large) content. */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'template' => $this->template,
            'starts_on' => $this->starts_on->toDateString(),
            'weeks' => $this->weekCount(),
        ];
    }
}
