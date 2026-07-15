<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How far one user has read in one channel. */
class ChannelRead extends Model
{
    /** @use HasFactory<\Database\Factories\ChannelReadFactory> */
    use HasFactory;

    protected $fillable = ['channel_id', 'user_id', 'last_read_message_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
