<?php

namespace App\Search;

use Illuminate\Database\Eloquent\Builder;

/**
 * Search without a search engine — substring matching, newest first.
 *
 * What a SQLite development database gets. It is slower and it cannot rank, so a query
 * that means something in tsquery syntax (`"a phrase"`, `-excluded`) is matched here as
 * the literal characters typed. Everything still *works*: the same plain-word search
 * returns the same set of rows on both drivers, which is the guarantee the feature tests
 * pin down, and it's why an environment without the search migration is merely slow rather
 * than broken.
 */
final class LikeSearchDriver implements SearchDriver
{
    public function matchProse(Builder $query, string $column, string $term): void
    {
        $this->like($query, $column, $term);

        // No relevance to sort by, so the useful answer is the recent one.
        $query->orderByDesc($query->getModel()->getQualifiedKeyName());
    }

    public function matchName(Builder $query, string $column, string $term): void
    {
        $this->like($query, $column, $term);
    }

    private function like(Builder $query, string $column, string $term): void
    {
        $qualified = str_contains($column, '.')
            ? $column
            : $query->getModel()->getTable().'.'.$column;

        // Explicit ESCAPE because SQLite has no default one — without it the backslashes
        // Term::forLike puts in front of `%` and `_` are matched as literal backslashes.
        $query->whereRaw(
            "lower({$qualified}) LIKE ? ESCAPE '\\'",
            ['%'.Term::forLike($term).'%'],
        );
    }
}
