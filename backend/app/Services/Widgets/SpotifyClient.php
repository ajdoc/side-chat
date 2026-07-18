<?php

namespace App\Services\Widgets;

use Illuminate\Support\Facades\Http;

/**
 * Reads public Spotify tracks, playlists and albums so the music widget can expand them.
 *
 * It does *not* use the Spotify Web API. Since November 2024 that API returns 403 on
 * playlist/album track reads for ordinary apps (and strips the tracks embedded in the
 * playlist object too), so credentials don't help. Instead we read the same public
 * `open.spotify.com/embed/<type>/<id>` page a browser loads — its `__NEXT_DATA__` blob
 * carries the whole track list with names, artists, durations and cover art, needs no auth,
 * and works for editorial playlists as well. This is what music bots like Jockie rely on.
 *
 * Everything comes back as *shells*: a `query` ("artist title") and no `videoId`. Turning a
 * shell into something playable is a YouTube search, and doing dozens up-front would torch
 * the YouTube quota — so the widget resolves each one lazily, only when it's about to play
 * (see MusicWidget::resolveAt). That laziness is the whole reason for the shape.
 *
 * The obvious fragility: it depends on Spotify's page markup, so a redesign there could
 * break it (same risk as any scraper). Nothing here throws — a miss returns a friendly error.
 *
 * @phpstan-type Shell array{videoId: null, spotifyUri: string|null, title: string, artist: string|null, duration: int|null, thumbnail: string|null, source: string, query: string}
 * @phpstan-type Result array{tracks: array<int, Shell>, error: string|null}
 */
final class SpotifyClient
{
    private const EMBED = 'https://open.spotify.com/embed';

    /** Don't expand an unbounded playlist — cap what we pull into a queue. */
    private const MAX_TRACKS = 100;

    /**
     * Expand a Spotify track / playlist / album link into playable shells.
     *
     * @return Result
     */
    public function resolve(string $type, string $id): array
    {
        $entity = $this->embedEntity($type, $id);
        if ($entity === null) {
            return $this->error('Couldn\'t read that Spotify link — is it public?');
        }

        $cover = $this->coverUrl($entity['coverArt'] ?? []);

        // A single track has no trackList — it *is* the track.
        if ($type === 'track') {
            $shell = $this->trackShell($entity, $cover);

            return $shell === null ? $this->error('Couldn\'t read that Spotify track.') : $this->ok([$shell]);
        }

        $tracks = [];
        foreach ($entity['trackList'] ?? [] as $item) {
            $shell = $this->listItemShell($item, $cover);
            if ($shell !== null) {
                $tracks[] = $shell;
            }
            if (count($tracks) >= self::MAX_TRACKS) {
                break;
            }
        }

        return $tracks === []
            ? $this->error('That Spotify '.$type.' had no playable tracks.')
            : $this->ok($tracks);
    }

    /**
     * The `entity` object out of an embed page's `__NEXT_DATA__`.
     *
     * @return array<string, mixed>|null
     */
    private function embedEntity(string $type, string $id): ?array
    {
        try {
            $res = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; SideChatBot/1.0)'])
                ->get(self::EMBED."/{$type}/{$id}");
            if (! $res->successful()) {
                return null;
            }
            $html = $res->body();
        } catch (\Throwable) {
            return null;
        }

        if (! preg_match('#<script id="__NEXT_DATA__" type="application/json">(.*?)</script>#s', $html, $m)) {
            return null;
        }

        $data = json_decode($m[1], true);

        return $data['props']['pageProps']['state']['data']['entity'] ?? null;
    }

    /**
     * A trackList row (playlist/album) → shell. `subtitle` is the comma-separated artists.
     *
     * @param  array<string, mixed>  $item
     * @return Shell|null
     */
    private function listItemShell(array $item, ?string $cover): ?array
    {
        $title = $item['title'] ?? null;
        if (! is_string($title) || $title === '') {
            return null;
        }
        $artist = $this->clean($item['subtitle'] ?? null);

        return $this->shell($title, $artist, $item['duration'] ?? null, $cover, $item['uri'] ?? null);
    }

    /**
     * A track entity → shell. Its artists live in `artists[].name`, not `subtitle`.
     *
     * @param  array<string, mixed>  $entity
     * @return Shell|null
     */
    private function trackShell(array $entity, ?string $cover): ?array
    {
        $title = $entity['title'] ?? $entity['name'] ?? null;
        if (! is_string($title) || $title === '') {
            return null;
        }
        $names = array_filter(array_map(fn ($a) => $a['name'] ?? null, $entity['artists'] ?? []));
        $artist = $this->clean($names === [] ? null : implode(', ', $names));

        return $this->shell($title, $artist, $entity['duration'] ?? null, $cover, $entity['uri'] ?? null);
    }

    /**
     * @return Shell
     */
    private function shell(string $title, ?string $artist, mixed $durationMs, ?string $cover, mixed $uri): array
    {
        return [
            'videoId' => null,
            // The real Spotify track URI — Premium listeners play this directly; everyone
            // else gets `videoId` resolved lazily from YouTube. See MusicWidget/MusicPlayer.
            'spotifyUri' => is_string($uri) && str_starts_with($uri, 'spotify:track:') ? $uri : null,
            'title' => $title,
            'artist' => $artist,
            'duration' => is_numeric($durationMs) ? (int) round($durationMs / 1000) : null,
            'thumbnail' => $cover,
            'source' => 'spotify',
            'query' => trim(($artist ?? '').' '.$title),
        ];
    }

    /**
     * Pull a usable cover URL out of either embed cover shape (`sources` for playlists,
     * `image` for albums/tracks), preferring a ~300px rendition.
     *
     * @param  array<string, mixed>  $coverArt
     */
    private function coverUrl(array $coverArt): ?string
    {
        $candidates = $coverArt['image'] ?? $coverArt['sources'] ?? [];
        if ($candidates === []) {
            return null;
        }

        // Prefer the one closest to 300px tall; fall back to the first url present.
        usort($candidates, fn ($a, $b) => abs(($a['maxHeight'] ?? 300) - 300) <=> abs(($b['maxHeight'] ?? 300) - 300));
        $url = $candidates[0]['url'] ?? null;

        return is_string($url) ? $url : null;
    }

    /** Spotify's subtitles use non-breaking spaces between artists — normalise them. */
    private function clean(?string $s): ?string
    {
        if (! is_string($s) || $s === '') {
            return null;
        }
        $s = preg_replace('/\x{00a0}/u', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s)) ?: null;
    }

    /** @param  array<int, array>  $tracks @return Result */
    private function ok(array $tracks): array
    {
        return ['tracks' => $tracks, 'error' => null];
    }

    /** @return Result */
    private function error(string $message): array
    {
        return ['tracks' => [], 'error' => $message];
    }
}
