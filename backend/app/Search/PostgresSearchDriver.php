<?php

namespace App\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Full-text search, the way Postgres does it.
 *
 * `websearch_to_tsquery` is what turns the box into a search box people already know how
 * to use: bare words are ANDed, `"a phrase"` in quotes stays a phrase, `-word` excludes,
 * `or` alternates — and, crucially, it never throws on malformed input the way
 * `to_tsquery` does. A user typing `((` into a search field is not an error condition.
 *
 * The trade is that it matches whole (stemmed) words only, so `depl` finds nothing while
 * `deploying` finds "deployed". That's the right way round for message bodies, which are
 * searched deliberately, and the wrong way round for names, which are searched while you
 * type — hence {@see matchName} going through `ILIKE` instead.
 */
final class PostgresSearchDriver implements SearchDriver
{
    private const CONFIG = 'english';

    /** Whether a table carries the stored `search_vector` column, memoised per request. */
    private array $hasVector = [];

    public function matchProse(Builder $query, string $column, string $term): void
    {
        $table = $query->getModel()->getTable();
        $vector = $this->vectorExpression($table, $column);

        $query
            ->whereRaw("{$vector} @@ websearch_to_tsquery(?, ?)", [self::CONFIG, $term])
            // Ranked, then broken by recency. `ts_rank_cd` weighs how close the matched
            // words are to each other, which is what makes a message that says the whole
            // phrase beat one that happens to contain both words a paragraph apart.
            ->orderByRaw("ts_rank_cd({$vector}, websearch_to_tsquery(?, ?)) DESC", [self::CONFIG, $term])
            ->orderByDesc($query->getModel()->getQualifiedKeyName());
    }

    public function matchName(Builder $query, string $column, string $term): void
    {
        $query->whereRaw('lower('.$this->qualify($query, $column).') LIKE ?', ['%'.Term::forLike($term).'%']);
    }

    /**
     * The indexed `search_vector` column where the migration managed to add one, and the
     * same expression computed inline where it didn't.
     *
     * The fallback is not decoration: the search-index migration is written to be skippable
     * (see its header), and a query that assumed the column would be a 500 rather than a
     * slow page.
     */
    private function vectorExpression(string $table, string $column): string
    {
        $this->hasVector[$table] ??= Schema::hasColumn($table, 'search_vector');

        return $this->hasVector[$table]
            ? '"'.$table.'"."search_vector"'
            : "to_tsvector('".self::CONFIG."', coalesce(\"{$table}\".\"{$column}\", ''))";
    }

    private function qualify(Builder $query, string $column): string
    {
        return str_contains($column, '.')
            ? $column
            : $query->getModel()->getTable().'.'.$column;
    }
}
