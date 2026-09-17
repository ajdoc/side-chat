<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A channel's own grouping of sign-up sheets — "Training", "Bootcamp", whatever they call it. */
class SignupGroup extends Model
{
    public const DEFAULTS = [
        ['name' => 'Training', 'color' => 'blue'],
        ['name' => 'Bootcamp', 'color' => 'orange'],
        ['name' => 'Leagues & Tournaments', 'color' => 'purple'],
        ['name' => 'Other events', 'color' => 'slate'],
    ];

    protected $fillable = ['channel_id', 'name', 'color', 'position'];

    /**
     * Give a channel the starter groups the first time it opens the app. "First time" is
     * nothing at all yet — a channel that deleted every group but has sheets meant to.
     */
    public static function seedFor(Channel $channel): void
    {
        $empty = ! self::where('channel_id', $channel->id)->exists()
            && ! $channel->signupSheets()->exists()
            && ! SignupSeries::where('channel_id', $channel->id)->exists();

        if (! $empty) {
            return;
        }

        foreach (self::DEFAULTS as $i => $group) {
            self::create([...$group, 'channel_id' => $channel->id, 'position' => $i]);
        }
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(SignupSheet::class, 'group_id');
    }
}
