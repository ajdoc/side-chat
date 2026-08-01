<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A `/name` a bot has claimed in its server. See the migration for why the server is on it.
 */
class BotCommand extends Model
{
    protected $fillable = ['bot_id', 'server_id', 'name', 'description', 'usage'];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
