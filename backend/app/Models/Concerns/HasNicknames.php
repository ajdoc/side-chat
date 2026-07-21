<?php

namespace App\Models\Concerns;

use App\Models\Nickname;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A place people can be called something other than their name — see MessageContainer.
 *
 * The delete hook is the reason this is a trait rather than one line in each model. The
 * link from a naming to its place is polymorphic, so no foreign key holds it and no
 * `cascadeOnDelete` fires: delete a server and its nicknames would sit there forever,
 * pointing at nothing, waiting for a new server to be handed the same id.
 */
trait HasNicknames
{
    public static function bootHasNicknames(): void
    {
        static::deleting(function (self $place) {
            $place->nicknames()->delete();
        });
    }

    /** @return MorphMany<Nickname, self> */
    public function nicknames(): MorphMany
    {
        return $this->morphMany(Nickname::class, 'place');
    }
}
