<?php

namespace App\Models;

use App\Models\Concerns\HasAppActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One card on a kanban board.
 *
 * A row rather than an entry in the widget's JSON, which is what lets it carry comments, tags,
 * reactions and a history through {@see HasAppActivity} — the thing APP_CHANNELS.md listed as
 * impossible for the widget-backed apps, and the reason for the promotion.
 *
 * The id is the card number people see and type: `k!done 12` names the row minted as 12,
 * whatever column it has drifted to since. Row ids are never reused, so the guarantee the old
 * `seq` counter maintained by hand now comes from the database for free — the same trade the
 * poll's options made.
 */
class KanbanCard extends Model
{
    use HasAppActivity;

    public const MAX_TEXT = 280;

    protected $fillable = [
        'board_id', 'channel_id', 'column', 'position', 'text', 'assignee_id', 'added_by', 'added_by_name',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(KanbanBoard::class, 'board_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /** Who to render as the card's author — the live account if it's still there, else the name kept at the time. */
    public function authorName(): ?string
    {
        return $this->author?->name ?? $this->added_by_name;
    }
}
