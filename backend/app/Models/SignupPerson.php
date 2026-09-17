<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One name on a channel's sign-up roster. Not a user: most people on a team sheet have no
 * account, and a sheet is filled in by whoever is holding the phone.
 */
class SignupPerson extends Model
{
    protected $table = 'signup_people';

    protected $fillable = ['channel_id', 'name', 'name_key', 'user_id'];

    public static function keyFor(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    /** The roster row for this name, made on first use. */
    public static function resolve(Channel $channel, string $name): self
    {
        $name = Str::squish($name);

        return self::firstOrCreate(
            ['channel_id' => $channel->id, 'name_key' => self::keyFor($name)],
            ['name' => $name],
        );
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(SignupEntry::class, 'person_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(SignupCharge::class, 'person_id');
    }
}
