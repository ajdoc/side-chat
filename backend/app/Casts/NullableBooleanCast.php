<?php

namespace App\Casts;

use WendellAdriel\ValidatedDTO\Casting\Castable;

/**
 * BooleanCast, except that it leaves null alone.
 *
 * The stock cast funnels everything through `(bool)`, which turns an *absent* field into
 * `false` — indistinguishable from someone deliberately sending false. In a partial
 * update that difference is the whole point: "I started sharing my screen" would quietly
 * become "…and unmute me, and un-deafen me while you're at it".
 */
final class NullableBooleanCast implements Castable
{
    public function cast(string $property, mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
