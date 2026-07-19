<?php

namespace App\Services\Gif;

use App\Contracts\GifProvider;
use Illuminate\Support\Facades\Http;

/**
 * Giphy source for the GIF picker. Key lives in config/services.php and is proxied
 * server-side. Best-effort: a missing key or a Giphy hiccup returns an empty list.
 */
final class GiphyProvider implements GifProvider
{
    private const API = 'https://api.giphy.com/v1/gifs';

    private const RATING = 'pg-13';

    public function key(): string
    {
        return 'giphy';
    }

    public function label(): string
    {
        return 'GIPHY';
    }

    public function configured(): bool
    {
        return is_string(config('services.giphy.key')) && config('services.giphy.key') !== '';
    }

    public function hosts(): array
    {
        return ['giphy.com'];
    }

    public function featured(int $limit): array
    {
        return $this->fetch('/trending', ['limit' => $limit]);
    }

    public function search(string $query, int $limit): array
    {
        return $this->fetch('/search', ['q' => $query, 'limit' => $limit]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, array{id: string, url: string, preview_url: string, width: int, height: int, title: string, provider: string}>
     */
    private function fetch(string $path, array $params): array
    {
        if (! $this->configured()) {
            return [];
        }

        $res = Http::timeout(6)->get(self::API.$path, array_merge($params, [
            'api_key' => config('services.giphy.key'),
            'rating' => self::RATING,
            // A curated rendition set tuned for chat — keeps the payload small.
            'bundle' => 'messaging_non_clips',
        ]));

        if (! $res->successful()) {
            return [];
        }

        return collect($res->json('data', []))
            ->map(fn (array $r) => $this->mapResult($r))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{id: string, url: string, preview_url: string, width: int, height: int, title: string, provider: string}|null
     */
    private function mapResult(array $r): ?array
    {
        $images = $r['images'] ?? [];
        $original = $images['original'] ?? null;

        if (! is_array($original) || ! isset($original['url'])) {
            return null;
        }

        // Prefer a fixed-width thumbnail for the grid; fall back to the full GIF.
        $preview = $images['fixed_width']['url']
            ?? $images['fixed_width_downsampled']['url']
            ?? $original['url'];

        return [
            'id' => (string) ($r['id'] ?? ''),
            'url' => $this->stripQuery($original['url']),
            'preview_url' => $this->stripQuery($preview),
            'width' => (int) ($original['width'] ?? 0),
            'height' => (int) ($original['height'] ?? 0),
            'title' => (string) ($r['title'] ?? 'gif'),
            'provider' => $this->key(),
        ];
    }

    /**
     * Giphy media URLs arrive with tracking query params (`?cid=…&ct=g`). We store `url` as a
     * remote attachment and render it forever, so drop the query and keep the stable asset URL.
     */
    private function stripQuery(string $url): string
    {
        return strtok($url, '?') ?: $url;
    }
}
