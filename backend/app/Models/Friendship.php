<?php

namespace App\Models;

use Database\Factories\FriendshipFactory;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The tie between two people: asked for, accepted, or blocked.
 *
 * One row per pair — see the migration for why. Everything here that takes a `$viewer` is
 * the consequence of that: the same row is "you asked Ben" to you and "Ana asked you" to
 * Ben, so the direction has to be resolved per reader rather than stored twice.
 */
class Friendship extends Model
{
    /** @use HasFactory<FriendshipFactory> */
    use HasFactory;

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const BLOCKED = 'blocked';

    protected $fillable = ['user_id', 'friend_id', 'status', 'pair_key'];

    /** Whoever asked, or — when blocked — whoever blocked. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whoever was asked, or blocked. */
    public function friend(): BelongsTo
    {
        return $this->belongsTo(User::class, 'friend_id');
    }

    /** The stable identity of the pair, order-independent. Mirrors Conversation::dmKey. */
    public static function pairKey(int $userId, int $otherId): string
    {
        $ids = [$userId, $otherId];
        sort($ids);

        return implode(':', $ids);
    }

    /** The row between these two, whichever way round it was created. */
    public static function between(int $userId, int $otherId): ?self
    {
        return static::where('pair_key', static::pairKey($userId, $otherId))->first();
    }

    /** Every tie this user is on either side of. */
    public function scopeInvolving(Builder $query, int $userId): Builder
    {
        return $query->where(fn ($q) => $q->where('user_id', $userId)->orWhere('friend_id', $userId));
    }

    public function isAccepted(): bool
    {
        return $this->status === self::ACCEPTED;
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::BLOCKED;
    }

    /** The other person, from `$viewerId`'s side of the row. */
    public function otherId(int $viewerId): int
    {
        return $this->user_id === $viewerId ? $this->friend_id : $this->user_id;
    }

    public function otherUser(int $viewerId): ?User
    {
        return $this->user_id === $viewerId ? $this->friend : $this->user;
    }

    /** True when `$viewerId` is the one being waited on, i.e. the one who may accept. */
    public function isIncomingFor(int $viewerId): bool
    {
        return $this->isPending() && $this->friend_id === $viewerId;
    }

    /**
     * Both people's personal streams.
     *
     * Same reasoning as a conversation's: neither of you is subscribed to the other, and a
     * request has to land on whatever screen the recipient happens to be looking at.
     *
     * @return array<int, PrivateChannel>
     */
    public function notificationChannels(): array
    {
        return [
            new PrivateChannel('user.'.$this->user_id),
            new PrivateChannel('user.'.$this->friend_id),
        ];
    }
}
