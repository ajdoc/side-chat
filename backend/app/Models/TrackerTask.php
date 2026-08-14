<?php

namespace App\Models;

use App\Models\Concerns\HasAppActivity;
use App\Support\Tracker\TrackerFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One task. Carries comments, tags and a history through {@see HasAppActivity}.
 */
class TrackerTask extends Model
{
    use HasAppActivity;

    protected $fillable = [
        'project_id', 'number', 'title', 'description',
        'status', 'priority', 'assignee_id', 'created_by', 'due_date', 'position', 'completed_at',
    ];

    protected $attributes = [
        'status' => TrackerFields::DEFAULT_STATUS,
        'priority' => TrackerFields::DEFAULT_PRIORITY,
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(TrackerProject::class, 'project_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The reference people actually use — HRIP-2.
     *
     * Composed rather than stored: the two halves are already columns, and a stored copy would
     * be one more thing to keep true if a project were ever re-keyed.
     */
    public function key(): string
    {
        return ($this->project?->key ?? '?').'-'.$this->number;
    }

    /**
     * Which channel this task lives in — what both the comments and the tags scope to, and
     * what every permission check ultimately asks about.
     */
    public function channelId(): ?int
    {
        return $this->project?->channel_id;
    }

    public function isDone(): bool
    {
        return $this->status === TrackerFields::DONE;
    }
}
