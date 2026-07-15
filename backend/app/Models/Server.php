<?php

namespace App\Models;

use App\Contracts\MessageContainer;
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
    use HasFactory;

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
        return $this->channels()->where('type', 'text')->first();
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
