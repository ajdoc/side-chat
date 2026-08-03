<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'is_bot', 'avatar', 'provider', 'provider_id', 'theme_mode', 'theme_color', 'space_avatar', 'space_pet', 'space_shout', 'spotify_id', 'spotify_access_token', 'spotify_refresh_token', 'spotify_token_expires_at', 'spotify_product'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Default appearance preferences (also enforced by DB column defaults).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'theme_mode' => 'system',
        'theme_color' => 'blue',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_bot' => 'boolean',
            // How you're drawn in a Side Space — see App\Support\SideSpace\Avatars. One setting
            // with five parts, never queried by, so it rides as JSON rather than five columns.
            'space_avatar' => 'array',
            // Third-party OAuth credentials — encrypted at rest.
            'spotify_access_token' => 'encrypted',
            'spotify_refresh_token' => 'encrypted',
            'spotify_token_expires_at' => 'datetime',
        ];
    }

    /** Whether this user has linked Spotify and can drive the Web Playback SDK. */
    public function spotifyPremium(): bool
    {
        return $this->spotify_refresh_token !== null && $this->spotify_product === 'premium';
    }

    /**
     * Servers this user owns.
     */
    public function ownedServers(): HasMany
    {
        return $this->hasMany(Server::class, 'owner_id');
    }

    /** Outstanding requests this user has made to join servers. */
    public function joinRequests(): HasMany
    {
        return $this->hasMany(ServerJoinRequest::class);
    }

    /**
     * Servers this user is a member of.
     */
    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * The bot registration behind this account, or null for a person.
     *
     * `is_bot` answers "is this a bot" on its own, without a join — which is what the
     * timeline and every resource ask. This relation is for the handful of places that
     * need the registration itself: the management screens and the token guard.
     */
    public function bot(): HasOne
    {
        return $this->hasOne(Bot::class);
    }

    /**
     * Badges this user holds, across every server they're in.
     *
     * Scoped by the caller where it matters — a badge belongs to one server, so rendering a
     * member list filters to that server's. Kept unscoped here because the relation has no
     * server to scope by, and a second relation per server would be worse.
     *
     * @return BelongsToMany<Badge>
     */
    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class)->withTimestamps();
    }

    /** DMs and group chats this user is in. */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class)->withTimestamps();
    }
}
