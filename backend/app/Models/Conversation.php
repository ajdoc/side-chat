<?php

namespace App\Models;

use App\Contracts\MessageContainer;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A DM or a group chat.
 *
 * Structurally it is a server with the server parts taken out: no invite code, no join
 * requests, no owner who can delete everyone's history, and exactly one channel instead
 * of a list of them. That single channel is where the messages actually live — see the
 * migration that added `channels.conversation_id` for why it's worth the indirection.
 *
 * The call fields are the one thing a server genuinely doesn't have. A server's voice
 * channel is a *room*: it sits there, empty, and you walk into it. A call in a chat is an
 * *event*: it has to ring somebody, it can be missed, and it ends. Hence `call_started_at`
 * and `call_answered_at`, and hence CallStarted/CallEnded going out on people's personal
 * `user.{id}` streams rather than to a room nobody is watching.
 */
class Conversation extends Model implements MessageContainer
{
    /** @use HasFactory<\Database\Factories\ConversationFactory> */
    use HasFactory;

    public const TYPES = ['dm', 'group'];

    /** Past this, a mesh call stops being a call and starts being a dropped one. */
    public const MAX_GROUP_MEMBERS = 25;

    protected $fillable = [
        'type', 'name', 'owner_id', 'dm_key',
        'call_started_at', 'call_answered_at', 'call_started_by',
    ];

    protected function casts(): array
    {
        return [
            'call_started_at' => 'datetime',
            'call_answered_at' => 'datetime',
        ];
    }

    /** Groups have one; a DM's owner is nobody, because neither person owns the other. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** Where the messages live. One per conversation, created with it. */
    public function channel(): HasOne
    {
        return $this->hasOne(Channel::class);
    }

    public function isDm(): bool
    {
        return $this->type === 'dm';
    }

    public function isGroup(): bool
    {
        return $this->type === 'group';
    }

    /** A call is live from the first person joining to the last one leaving. */
    public function hasActiveCall(): bool
    {
        return $this->call_started_at !== null;
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->getKey())->exists();
    }

    public function broadcastChannel(): PrivateChannel
    {
        return new PrivateChannel('conversation.'.$this->id);
    }

    /**
     * Straight to each member, personally.
     *
     * Unlike a server, a chat has no stream its members hold open all day: you subscribe to
     * `conversation.{id}` for the chat you're *reading*, which is precisely the one chat
     * whose unread badge doesn't need moving. Anything that has to reach someone who is
     * looking somewhere else — a badge, a ring — has to go to `user.{id}`.
     *
     * @return array<int, PrivateChannel>
     */
    public function notificationChannels(): array
    {
        return array_map(
            fn (int $id) => new PrivateChannel('user.'.$id),
            $this->memberIds(),
        );
    }

    /** @return array<int, int> */
    public function memberIds(): array
    {
        return $this->members()->pluck('users.id')->all();
    }

    /**
     * The stable identity of the pair in a DM, so the unique index can stop the same two
     * people ending up with two separate DM histories. Order-independent by construction.
     */
    public static function dmKey(int $userId, int $otherId): string
    {
        $ids = [$userId, $otherId];
        sort($ids);

        return implode(':', $ids);
    }

    /**
     * What to call this conversation when showing it to `$viewer`.
     *
     * A group has a name. A DM doesn't and shouldn't: it's "Ana" to you and "Ben" to Ana,
     * which is the one place in the app where the same row genuinely reads differently
     * depending on who's looking — so it's resolved per viewer here rather than stored.
     */
    public function titleFor(User $viewer): string
    {
        if ($this->isGroup()) {
            return (string) $this->name;
        }

        $other = $this->members->firstWhere('id', '!=', $viewer->id);

        // A DM with yourself (your own notes) is a legitimate thing to have.
        return $other?->name ?? $viewer->name;
    }
}
