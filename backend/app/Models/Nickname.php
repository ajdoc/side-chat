<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One naming: "in this place, this person is called this — to everyone, or to just me."
 *
 * See the migration for why public and private namings share a table, and NicknameService
 * for the order they resolve in.
 */
class Nickname extends Model
{
    protected $fillable = ['place_type', 'place_id', 'user_id', 'viewer_id', 'nickname'];

    /** The Server or Conversation this naming is scoped to. */
    public function place(): MorphTo
    {
        return $this->morphTo();
    }

    /** Whose name this is. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Who sees it — null when everybody does. */
    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewer_id');
    }

    /** Public namings are the place's own; private ones belong to the one viewer. */
    public function isPublic(): bool
    {
        return $this->viewer_id === null;
    }
}
