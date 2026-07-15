<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected $fillable = ['channel_id', 'thread_id', 'user_id', 'body', 'type', 'reply_to_id', 'edited_at', 'pinned_at', 'pinned_by'];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime', 'pinned_at' => 'datetime'];
    }

    public function isSystem(): bool
    {
        return $this->type === 'system';
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    /** Whoever pinned this message — null if it isn't pinned, or if they've since gone. */
    public function pinner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by');
    }

    /** @param  Builder<Message>  $query */
    public function scopePinned(Builder $query): void
    {
        $query->whereNotNull('pinned_at');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** The message this one is an inline reply to (if any). */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The thread this message belongs to (null for main-timeline messages). */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /** A thread that was started from this message, if any. */
    public function startedThread(): HasOne
    {
        return $this->hasOne(Thread::class, 'message_id');
    }

    /** @return HasMany<Attachment> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class)->orderBy('id');
    }

    /** @return HasMany<Reaction> */
    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class)->orderBy('id');
    }

    /** Unfurled links from this message's body, in the order they appear in it. */
    public function linkPreviews(): BelongsToMany
    {
        return $this->belongsToMany(LinkPreview::class)
            ->withPivot('position')
            ->orderByPivot('position'); // the order is on the pivot, not the (shared) preview row
    }
}
