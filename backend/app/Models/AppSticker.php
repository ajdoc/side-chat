<?php

namespace App\Models;

use App\Models\Concerns\HasAppActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sticker on a channel's wall — a small drawing, placed. See the migration for why it isn't
 * a group of whiteboard strokes.
 */
class AppSticker extends Model
{
    /** Comments, tags, reactions and a history — see the trait. */
    use HasAppActivity;

    protected $fillable = ['channel_id', 'user_id', 'name', 'content', 'x', 'y', 'z', 'w', 'h', 'rotation'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'x' => 'integer', 'y' => 'integer', 'z' => 'integer',
            'w' => 'integer', 'h' => 'integer', 'rotation' => 'integer',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** Who drew it. Null once that account is gone — the sticker stays on the wall. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The surface's own stream, the same one its messages and board already travel over. */
    public function streamName(): string
    {
        return 'channel.'.$this->channel_id;
    }
}
