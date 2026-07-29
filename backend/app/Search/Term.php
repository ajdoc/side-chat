<?php

namespace App\Search;

use Illuminate\Support\Str;

/** Turning what somebody typed into something safe to hand a database. */
final class Term
{
    /** Longer than this and it isn't a search term, it's a paste. */
    public const MAX_LENGTH = 128;

    /**
     * A term ready to sit inside a `LIKE '%…%'` pattern.
     *
     * Lowercased (every name match in this app is case-insensitive) and with the two
     * wildcards escaped — otherwise searching for `_` matches every single-character name
     * in the database, and searching for `%` matches all of them.
     */
    public static function forLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], Str::lower(trim($term)));
    }

    /** Trimmed and capped, as stored on the request. Empty means "no search". */
    public static function normalize(?string $term): string
    {
        return Str::limit(trim((string) $term), self::MAX_LENGTH, '');
    }
}
