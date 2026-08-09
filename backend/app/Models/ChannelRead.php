<?php

namespace App\Models;

use Database\Factories\ChannelReadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How far one user has read in one channel. */
class ChannelRead extends Model
{
    /** @use HasFactory<ChannelReadFactory> */
    use HasFactory;

    /**
     * `default_child_id` is the odd one out: it isn't about reading at all, it's "open this
     * channel on this discussion, for me". It lives here because this table is already exactly
     * the user-by-channel row that preference needs, and a table of its own would be the same
     * unique key written twice. Set only on a *container's* row.
     */
    protected $fillable = [
        'channel_id', 'user_id', 'last_read_message_id', 'read_at', 'default_child_id',
        // Nor is this one about reading: how loud this channel is allowed to be, for this
        // person. Same justification as `default_child_id` — it's the user-by-channel row
        // the preference needs, already keyed exactly right. See NotificationPolicy.
        'notify_level', 'muted_until',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'muted_until' => 'datetime'];
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
