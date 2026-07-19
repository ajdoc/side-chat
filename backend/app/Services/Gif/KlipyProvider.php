<?php

namespace App\Services\Gif;

use App\Contracts\GifProvider;
use Illuminate\Support\Facades\Http;

/**
 * Klipy source for the GIF picker — an alternative that rose after Google shut down Tenor.
 *
 * Klipy puts the API key in the URL *path* (not a query param or header):
 *   https://api.klipy.com/api/v1/{KEY}/gifs/{trending|search}
 * and wraps results as `{ result: true, data: { data: [...], has_next, ... } }`.
 *
 * ⚠️ VERIFY WITH A LIVE KEY: Klipy's full docs are gated (docs.klipy.com blocks automated
 * fetching), so the per-item field names below — the `file`/size/`gif.url` shape in
 * {@see self::pickGif()} and the CDN host in {@see self::hosts()} — are a best-effort read
 * and may need a small tweak once you can inspect a real response. The mapper is written
 * defensively (unknown items are skipped, never fatal), and Giphy is unaffected either way.
 */
final class KlipyProvider implements GifProvider
{
    private const API = 'https://api.klipy.com/api/v1';

    public function key(): string
    {
        return 'klipy';
    }

    public function label(): string
    {
        return 'KLIPY';
    }

    public function configured(): bool
    {
        return is_string(config('services.klipy.key')) && config('services.klipy.key') !== '';
    }

    public function hosts(): array
    {
        // Best guess — confirm the real media CDN host from a live response and adjust.
        return ['klipy.com'];
    }

    public function featured(int $limit): array
    {
        return $this->fetch('trending', ['per_page' => $limit]);
    }

    public function search(string $query, int $limit): array
    {
        return $this->fetch('search', ['q' => $query, 'per_page' => $limit]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, array{id: string, url: string, preview_url: string, width: int, height: int, title: string, provider: string}>
     */
    private function fetch(string $endpoint, array $params): array
    {
        if (! $this->configured()) {
            return [];
        }

        $url = self::API.'/'.config('services.klipy.key').'/gifs/'.$endpoint;

        $res = Http::timeout(6)->get($url, array_merge($params, [
            'page' => 1,
            'content_filter' => 'medium',
        ]));

        if (! $res->successful()) {
            return [];
        }

        // Results are nested under data.data (the outer `data` also carries pagination).
        return collect($res->json('data.data', []))
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
        $file = $r['file'] ?? $r['files'] ?? [];

        // Largest available for the message, smallest available for the grid thumbnail.
        $full = $this->pickGif($file, ['hd', 'lg', 'md', 'sm', 'xs']);
        $preview = $this->pickGif($file, ['sm', 'xs', 'md', 'lg', 'hd']);

        if ($full === null) {
            return null;
        }

        return [
            'id' => (string) ($r['id'] ?? $r['slug'] ?? ''),
            'url' => (string) $full['url'],
            'preview_url' => (string) ($preview['url'] ?? $full['url']),
            'width' => (int) ($full['width'] ?? 0),
            'height' => (int) ($full['height'] ?? 0),
            'title' => (string) ($r['title'] ?? 'gif'),
            'provider' => $this->key(),
        ];
    }

    /**
     * Find the gif rendition at the first available size. Klipy nests renditions by size
     * (`hd`/`md`/`sm`/…), each with per-format entries (`gif`/`webp`/`mp4`); we want the gif.
     *
     * @param  array<string, mixed>  $file
     * @param  array<int, string>  $sizes
     * @return array<string, mixed>|null
     */
    private function pickGif(array $file, array $sizes): ?array
    {
        foreach ($sizes as $size) {
            $gif = $file[$size]['gif'] ?? null;
            if (is_array($gif) && isset($gif['url'])) {
                return $gif;
            }
        }

        return null;
    }
}
