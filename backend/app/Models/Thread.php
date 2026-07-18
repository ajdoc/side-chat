<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Thread extends Model
{
    /** @use HasFactory<\Database\Factories\ThreadFactory> */
    use HasFactory;

    protected $fillable = ['channel_id', 'side_chat_id', 'user_id', 'message_id', 'name'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** The side chat this thread belongs to, if it's a side-chat thread rather than a channel one. */
    public function sideChat(): BelongsTo
    {
        return $this->belongsTo(SideChat::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The message this thread branched off (may be null). */
    public function parentMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
