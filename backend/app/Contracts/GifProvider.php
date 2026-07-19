<?php

namespace App\Contracts;

/**
 * One GIF source (Giphy, Klipy, …) behind the composer's picker. GifService fans out to
 * every configured provider and merges the results, so adding a source is: implement this,
 * give it a config key, and register it in GifService.
 *
 * Every provider returns results in one shape:
 *   ['id' => string, 'url' => string, 'preview_url' => string,
 *    'width' => int, 'height' => int, 'title' => string, 'provider' => string]
 * where `url` is the full GIF we store as a remote attachment and `preview_url` is a small
 * thumbnail for the grid.
 */
interface GifProvider
{
    /** Short machine id — 'giphy', 'klipy'. Also stamped on each result. */
    public function key(): string;

    /** Human label for attribution — 'GIPHY', 'KLIPY'. */
    public function label(): string;

    /** Whether this provider has an API key and can be queried. */
    public function configured(): bool;

    /**
     * CDN host suffixes a picked GIF's media URL may live on (e.g. 'giphy.com'). Used to
     * validate the user-supplied URL before it's stored. A leading dot is implied — the
     * check matches the host exactly or as a `*.suffix` subdomain.
     *
     * @return array<int, string>
     */
    public function hosts(): array;

    /**
     * Trending GIFs.
     *
     * @return array<int, array{id: string, url: string, preview_url: string, width: int, height: int, title: string, provider: string}>
     */
    public function featured(int $limit): array;

    /**
     * Search GIFs for a query.
     *
     * @return array<int, array{id: string, url: string, preview_url: string, width: int, height: int, title: string, provider: string}>
     */
    public function search(string $query, int $limit): array;
}
