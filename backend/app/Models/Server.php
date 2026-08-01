<?php

namespace App\Models;

use App\Contracts\MessageContainer;
use App\Models\Concerns\HasNicknames;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Server extends Model implements MessageContainer
{
    /** @use HasFactory<\Database\Factories\ServerFactory> */
    use HasFactory, HasNicknames;

    /**
     * What a member may be, on the `server_user.role` pivot.
     *
     * Deliberately two rungs rather than a permission matrix: an admin is "somebody the
     * owner trusts to run the place", and the things that need trusting are all the same
     * kind of thing — approving who gets in, shaping the channels, handing out rooms and
     * call effects. Splitting those into toggles would be a lot of UI for a distinction
     * nobody has asked to make. The owner is not a role: it's a column on the server, and
     * it is the one thing an admin can't become or grant.
     */
    public const ROLE_MEMBER = 'member';

    public const ROLE_ADMIN = 'admin';

    public const ROLES = [self::ROLE_MEMBER, self::ROLE_ADMIN];

    protected $fillable = ['name', 'owner_id', 'invite_code'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class)->orderBy('position')->orderBy('id');
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->getKey())->exists();
    }

    /** The one person the server belongs to. Not a role — see the ROLE_* constants. */
    public function isOwner(User $user): bool
    {
        return $this->owner_id === $user->getKey();
    }

    /**
     * May this person run the place? The owner, or anyone the owner has made an admin.
     *
     * This is the check behind every "owner only" endpoint bar two — deleting the server
     * and changing who is an admin, which stay with the owner alone (see ServerOwnerRequest).
     */
    public function isStaff(User $user): bool
    {
        return $this->isOwner($user) || $this->members()
            ->whereKey($user->getKey())
            ->wherePivot('role', self::ROLE_ADMIN)
            ->exists();
    }

    /** This member's role string — 'owner' for the owner, else the pivot value. */
    public function roleFor(User $user): string
    {
        if ($this->isOwner($user)) {
            return 'owner';
        }

        return (string) ($this->members()->whereKey($user->getKey())->first()?->pivot->role ?? self::ROLE_MEMBER);
    }

    public function broadcastChannel(): PrivateChannel
    {
        return new PrivateChannel('server.'.$this->id);
    }

    /**
     * One broadcast reaches everybody: every member holds `server.{id}` open for as long
     * as the server is the one they're looking at, and that's where the unread badges,
     * join requests and voice rosters have always gone.
     *
     * @return array<int, PrivateChannel>
     */
    public function notificationChannels(): array
    {
        return [$this->broadcastChannel()];
    }

    /** @return array<int, int> */
    public function memberIds(): array
    {
        return $this->members()->pluck('users.id')->all();
    }

    /**
     * Bots registered here. One server owns a bot outright — see the bots migration.
     *
     * @return HasMany<Bot>
     */
    public function bots(): HasMany
    {
        return $this->hasMany(Bot::class);
    }

    /** @return HasMany<ServerJoinRequest> */
    public function joinRequests(): HasMany
    {
        return $this->hasMany(ServerJoinRequest::class);
    }

    /**
     * The channel a system message (e.g. "X joined the server") should be posted to.
     * Null when the server has no text channel yet - in which case we post nothing.
     */
    public function firstTextChannel(): ?Channel
    {
        // Never a private one: "X joined the server" is for everybody, and posting it
        // somewhere most of the server can't see would quietly lose it.
        return $this->channels()->where('type', 'text')->where('is_private', false)->first();
    }

    /** A short, unique, URL-safe invite code. */
    public static function generateInviteCode(): string
    {
        do {
            $code = Str::lower(Str::random(10));
        } while (self::where('invite_code', $code)->exists());

        return $code;
    }
}
