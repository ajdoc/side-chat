<?php

namespace App\Models;

use Database\Factories\DeviceTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One install we can reach when the app isn't open. See the creating migration. */
class DeviceToken extends Model
{
    /** @use HasFactory<DeviceTokenFactory> */
    use HasFactory;

    public const PLATFORMS = ['android', 'ios', 'web'];

    protected $fillable = ['user_id', 'token', 'platform', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Claim this token for this user.
     *
     * An upsert rather than a create because the token identifies the *install*: the same
     * phone signing in as somebody else must move the row, or the previous owner keeps
     * receiving that phone's notifications.
     */
    public static function register(User $user, string $token, string $platform): self
    {
        return static::updateOrCreate(
            ['token' => $token],
            ['user_id' => $user->id, 'platform' => $platform, 'last_used_at' => now()],
        );
    }
}
