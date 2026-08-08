<?php

namespace App\Models;

use App\Http\Resources\MessageResource;
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

    protected $fillable = ['channel_id', 'thread_id', 'side_chat_id', 'widget_id', 'user_id', 'body', 'type', 'reply_to_id', 'replies_to_post', 'forwarded_from_id', 'edited_at', 'pinned_at', 'pinned_by', 'decided_at', 'decided_by'];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime', 'pinned_at' => 'datetime', 'decided_at' => 'datetime', 'replies_to_post' => 'boolean'];
    }

    /**
     * A Side Desk app the *sender's* client should open, set only on the ephemeral reply to an
     * `a!<app>` command (see WidgetService::handleAppCommand).
     *
     * Deliberately not a column and never broadcast: launching a floating window is a thing
     * that happens to one person, in the browser tab that typed the command, once. It rides
     * out on that one HTTP response ({@see MessageResource}) and is gone.
     */
    public ?string $openApp = null;

    public function isSystem(): bool
    {
        return $this->type === 'system';
    }

    public function isWidget(): bool
    {
        return $this->type === 'widget';
    }

    /**
     * The private stream a message's real-time events ride on.
     *
     * A message lives in exactly one place — a side chat, a thread, or the main timeline —
     * and its events must reach that place and no other. Centralised here so every event
     * (sent, edited, deleted, reacted, commented, unfurled) routes identically; getting one
     * wrong would leak a side-chat reply into the channel everyone's watching.
     */
    public function streamName(): string
    {
        return self::streamNameFor($this->channel_id, $this->thread_id, $this->side_chat_id);
    }

    public static function streamNameFor(int $channelId, ?int $threadId, ?int $sideChatId): string
    {
        return match (true) {
            $sideChatId !== null => 'sidechat.'.$sideChatId,
            $threadId !== null => 'thread.'.$threadId,
            default => 'channel.'.$channelId,
        };
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

    /**
     * Every message this person may read.
     *
     * Two gates, because a message's home is not always its channel. The channel gate is
     * {@see Channel::scopeVisibleTo} exactly, as a subquery. The second gate is the side
     * chat: a side chat is a *private* room hanging off a channel everyone can see, so
     * "you're in the channel" is not the answer — a message with `side_chat_id` set is
     * yours to read only if you're on that side chat's roster.
     *
     * Thread replies need no gate of their own: a thread is open to whoever holds the
     * surface it hangs off, which the first two clauses have already settled.
     *
     * @param  Builder<Message>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query
            ->whereIn('channel_id', Channel::query()->visibleTo($user)->select('channels.id'))
            ->where(function (Builder $q) use ($user) {
                $q->whereNull('side_chat_id')
                    ->orWhereExists(fn ($exists) => $exists
                        ->from('side_chat_user')
                        ->whereColumn('side_chat_user.side_chat_id', 'messages.side_chat_id')
                        ->where('side_chat_user.user_id', $user->getKey()));
            });
    }

    /** Recorded as a decision — the ✅ count on a side chat's card. Same shape as a pin. */
    public function isDecision(): bool
    {
        return $this->decided_at !== null;
    }

    /** @param  Builder<Message>  $query */
    public function scopeDecided(Builder $query): void
    {
        $query->whereNotNull('decided_at');
    }

    /** Who marked this message a decision — null if it isn't one, or if they've since gone. */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
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

    /** The original this message was forwarded from (if any). */
    public function forwardedFrom(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'forwarded_from_id');
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

    /** The side chat this message belongs to (null for main-timeline / thread messages). */
    public function sideChat(): BelongsTo
    {
        return $this->belongsTo(SideChat::class);
    }

    /** A side chat that was spun off this message, if any — powers the living-object card. */
    public function startedSideChat(): HasOne
    {
        return $this->hasOne(SideChat::class, 'message_id');
    }

    /** The widget this message renders as a card (only set when `type` is 'widget'). */
    public function widget(): BelongsTo
    {
        return $this->belongsTo(Widget::class);
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

    /** Word-reactions: short annotations, grouped into "popular comments" chips. */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderBy('id');
    }

    /** Unfurled links from this message's body, in the order they appear in it. */
    public function linkPreviews(): BelongsToMany
    {
        return $this->belongsToMany(LinkPreview::class)
            ->withPivot('position')
            ->orderByPivot('position'); // the order is on the pivot, not the (shared) preview row
    }
}
