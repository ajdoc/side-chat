<?php

namespace App\Search;

use Illuminate\Database\Eloquent\Builder;

/**
 * How a text match is expressed to the database.
 *
 * Two implementations, because search is the one feature in this app that cannot be
 * written once against Eloquent and left alone: Postgres runs it as full-text with
 * ranking, and everything else (a SQLite dev box) runs it as `LIKE`. Both return the same
 * *rows* for a plain word — only the ordering and the tolerance for word endings differ —
 * which is the contract the feature tests hold both drivers to.
 *
 * The split is here, at "apply a match to a query", rather than at "search messages",
 * deliberately: the visibility rules, the filters and the eager loads are the parts most
 * likely to grow a bug, and there must be exactly one copy of them. See SearchService.
 */
interface SearchDriver
{
    /**
     * Narrow to rows whose prose column matches, ordered best-first.
     *
     * "Prose" means a message body — something written in sentences, where "deployed"
     * should be found by "deploying". Applies its own ordering, since only the driver
     * knows whether it has a relevance score to order by.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public function matchProse(Builder $query, string $column, string $term): void;

    /**
     * Narrow to rows whose *name* matches — a channel, a server, a person.
     *
     * A different problem from prose and not a smaller one: names are short labels nobody
     * finishes typing, so this matches on substring (and therefore prefix) rather than on
     * whole words. Adds no ordering; the caller decides what a "best" name match is,
     * because that depends on what's being listed.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public function matchName(Builder $query, string $column, string $term): void;
}
