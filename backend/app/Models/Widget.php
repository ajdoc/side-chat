<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shared interactive object living in a channel — a music player, a kanban board.
 *
 * Its whole world is `state`, a JSON blob whose shape belongs to the handler for its
 * `type` (see App\Services\Widgets). The model deliberately knows nothing about queues or
 * columns; that keeps a new widget kind to a handler plus a Vue card, no schema change.
 */
class Widget extends Model
{
    protected $fillable = ['channel_id', 'type', 'user_id', 'state'];

    protected function casts(): array
    {
        return ['state' => 'array'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** Whoever first ran a command for it — the "started by". */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Its cards in the timeline. There can be more than one — every `m!queue` / `k!list`
     * drops a fresh card at the bottom, and they all render this one live state.
     *
     * @return HasMany<Message>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
