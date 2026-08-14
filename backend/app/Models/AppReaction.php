<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An emoji reaction on anything an app owns. See the migration for why it isn't {@see Reaction}.
 */
class AppReaction extends Model
{
    protected $fillable = ['reactable_type', 'reactable_id', 'channel_id', 'user_id', 'emoji'];

    public function reactable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
