<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Every thread this person may see, for search.
     *
     * A channel thread is as visible as the channel it hangs off — the Threads panel lists
     * all of them to any member, and a title is exactly what that panel shows. A side chat's
     * own threads are not: they are reached through the side chat, so they inherit its
     * roster, the same gate {@see Message::scopeVisibleTo} applies to its messages.
     *
     * @param  Builder<Thread>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query
            ->whereIn('channel_id', Channel::query()->visibleTo($user)->select('channels.id'))
            ->where(function (Builder $q) use ($user) {
                $q->whereNull('side_chat_id')
                    ->orWhereExists(fn ($exists) => $exists
                        ->from('side_chat_user')
                        ->whereColumn('side_chat_user.side_chat_id', 'threads.side_chat_id')
                        ->where('side_chat_user.user_id', $user->getKey()));
            });
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

    /**
     * May this person rename or delete the thread? Its creator, or the server's staff.
     *
     * The rule itself is explained in ThreadAuthorRequest, which enforces it; this exists
     * so ThreadResource can tell the client the same answer without duplicating it.
     */
    public function canManage(User $user): bool
    {
        if ($this->user_id === $user->getKey()) {
            return true;
        }

        $container = $this->loadMissing('channel')->channel?->container();

        return $container instanceof Server && $container->isStaff($user);
    }
}
