<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One committed mark on a shared whiteboard — a pen path, a shape, a text label, or a
 * sticky note. A board is just every stroke pointing at its surface (a side chat or a
 * channel); there is no board row. The live drag and the moving cursor never become
 * strokes: they ride over whispers and expire. See {@see \App\Http\Controllers\
 * WhiteboardController} / {@see \App\Http\Controllers\ChannelWhiteboardController}.
 */
class WhiteboardStroke extends Model
{
    /** @use HasFactory<\Database\Factories\WhiteboardStrokeFactory> */
    use HasFactory;

    protected $fillable = ['side_chat_id', 'channel_id', 'user_id', 'kind', 'payload', 'client_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function sideChat(): BelongsTo
    {
        return $this->belongsTo(SideChat::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The private broadcast stream this stroke belongs on — the side chat's own stream, or
     * the channel's. It's the same stream the surface's messages already travel over, so an
     * open board and the timeline beside it are fed by one subscription.
     */
    public function streamName(): string
    {
        return $this->side_chat_id
            ? 'sidechat.'.$this->side_chat_id
            : 'channel.'.$this->channel_id;
    }
}
