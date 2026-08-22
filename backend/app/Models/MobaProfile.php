<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's standing: one rating, and the record behind it.
 */
class MobaProfile extends Model
{
    public const STARTING_MMR = 1200;

    protected $fillable = ['user_id', 'mmr', 'games', 'wins'];

    protected $attributes = ['mmr' => self::STARTING_MMR, 'games' => 0, 'wins' => 0];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * This user's profile, creating it on first sight.
     *
     * Lazily rather than on registration: most people who sign up for a chat app will never
     * open the MOBA, and a rating row for every one of them is a table full of 1200s that mean
     * nothing.
     */
    public static function forUser(User|int $user): MobaProfile
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return static::firstOrCreate(['user_id' => $id]);
    }
}
