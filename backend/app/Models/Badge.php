<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A label a server hands out. Cosmetic and addressable, never a permission — see the
 * badges migration for the full argument, and {@see Server::ROLE_MEMBER} for the thing it
 * is deliberately not.
 */
class Badge extends Model
{
    protected $fillable = ['server_id', 'name', 'emoji', 'color', 'description'];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsToMany<User> */
    public function holders(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('granted_by')->withTimestamps();
    }

    /**
     * Give this badge to someone, idempotently.
     *
     * Returns whether it was actually new, because the callers care: an automation that
     * announces "welcome to the crew" should say it once, not on every reaction toggle.
     */
    public function grantTo(User $user, ?User $by = null): bool
    {
        if ($this->holders()->whereKey($user->getKey())->exists()) {
            return false;
        }

        $this->holders()->attach($user->getKey(), ['granted_by' => $by?->getKey()]);

        return true;
    }

    /** Take it back. Returns whether they had it. */
    public function revokeFrom(User $user): bool
    {
        return $this->holders()->detach($user->getKey()) > 0;
    }
}
