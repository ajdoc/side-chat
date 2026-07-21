<?php

namespace App\Services\Widgets;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Time-synced lyrics for the karaoke view, from LRCLIB.
 *
 * LRCLIB (https://lrclib.net) is a free, key-less, community lyrics database that serves
 * LRC — plain text lines each stamped `[mm:ss.xx]`. That stamping is the whole point here:
 * the music widget already broadcasts a shared clock (position as of `updated_at`), so a
 * stamped line is all the karaoke pane needs to know what the room is singing *right now*.
 *
 * Two lookups, cheapest first:
 *   - `/api/get` — an exact match on track + artist + duration. When the queue has a real
 *     duration this is precise, and it's the only call that can return the *right* version
 *     of a song that has a dozen re-recordings.
 *   - `/api/search` — the fallback when there's no duration or the exact match missed.
 *     Results are ranked by LRCLIB; we take the first that actually carries synced lyrics.
 *
 * Everything is best-effort: a miss, a timeout, or a 404 all mean "no lyrics", never an
 * error into the player. Results (hits *and* misses) are cached — the same track gets asked
 * for by every listener in the room at once, and a miss is worth remembering so a song
 * LRCLIB simply doesn't have doesn't re-query on every sync.
 *
 * @phpstan-type Lyrics array{synced: string|null, plain: string|null, title: string, artist: string|null, instrumental: bool}
 */
final class LyricsResolver
{
    private const BASE = 'https://lrclib.net/api';

    /** LRCLIB asks API consumers to identify themselves. */
    private const AGENT = 'SideChat (https://github.com/ajdoc/side-chat)';

    private const TIMEOUT = 6;

    /** Hits are stable — lyrics don't change. Misses expire sooner in case one gets added. */
    private const TTL_HIT = 60 * 60 * 24 * 30;

    private const TTL_MISS = 60 * 60 * 6;

    /**
     * Look up lyrics for one track. Returns null when nothing usable was found.
     *
     * @return Lyrics|null
     */
    public function find(string $title, ?string $artist, ?int $duration): ?array
    {
        $title = trim($title);
        if ($title === '') {
            return null;
        }

        $key = 'lyrics:'.sha1(mb_strtolower($title.'|'.($artist ?? '').'|'.($duration ?? '')));
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === 'miss' ? null : $cached;
        }

        $found = $this->lookup($title, $artist, $duration);
        Cache::put($key, $found ?? 'miss', $found ? self::TTL_HIT : self::TTL_MISS);

        return $found;
    }

    /** @return Lyrics|null */
    private function lookup(string $title, ?string $artist, ?int $duration): ?array
    {
        // A YouTube title is rarely a clean track name — strip the noise before asking.
        $title = $this->cleanTitle($title);
        $artist = $artist !== null ? $this->cleanArtist($artist) : null;

        // Plenty of queue entries carry the artist inside the title ("Queen - Bohemian
        // Rhapsody") and nothing in the artist field. Split it out so /api/get can match.
        if (($artist === null || $artist === '') && preg_match('/^(.{2,60}?)\s+[-–—]\s+(.+)$/u', $title, $m)) {
            $artist = trim($m[1]);
            $title = trim($m[2]);
        }

        if ($artist !== null && $artist !== '' && $duration !== null && $duration > 0) {
            $hit = $this->get($title, $artist, $duration);
            if ($hit !== null) {
                return $hit;
            }
        }

        return $this->search($title, $artist);
    }

    /** The exact-match endpoint. LRCLIB allows a couple of seconds of duration slack. */
    private function get(string $title, string $artist, int $duration): ?array
    {
        $body = $this->call('/get', [
            'track_name' => $title,
            'artist_name' => $artist,
            'duration' => $duration,
        ]);

        return is_array($body) ? $this->normalise($body) : null;
    }

    /** The ranked-search fallback: first result that actually has synced lyrics wins. */
    private function search(string $title, ?string $artist): ?array
    {
        $params = ['track_name' => $title];
        if ($artist !== null && $artist !== '') {
            $params['artist_name'] = $artist;
        }

        $body = $this->call('/search', $params);
        if (! is_array($body)) {
            return null;
        }

        $best = null;
        foreach (array_slice($body, 0, 10) as $row) {
            $row = $this->normalise(is_array($row) ? $row : []);
            if ($row === null) {
                continue;
            }
            if ($row['synced'] !== null) {
                return $row;
            }
            // Remember the first plain-text-only match, but keep looking for a synced one —
            // unsynced lyrics still beat an empty pane, they just can't highlight a line.
            $best ??= $row;
        }

        return $best;
    }

    /** @return array<string, mixed>|null */
    private function call(string $path, array $query): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders(['User-Agent' => self::AGENT])
                ->get(self::BASE.$path, $query);
        } catch (\Throwable) {
            // Network trouble — karaoke is a nicety, never let it surface as a failure.
            return null;
        }

        // 404 is LRCLIB's ordinary "no such track", not an incident.
        return $response->successful() ? $response->json() : null;
    }

    /**
     * Keep only what the pane renders, and drop rows with nothing to show.
     *
     * @param  array<string, mixed>  $row
     * @return Lyrics|null
     */
    private function normalise(array $row): ?array
    {
        $synced = is_string($row['syncedLyrics'] ?? null) ? trim($row['syncedLyrics']) : '';
        $plain = is_string($row['plainLyrics'] ?? null) ? trim($row['plainLyrics']) : '';
        $instrumental = (bool) ($row['instrumental'] ?? false);

        if ($synced === '' && $plain === '' && ! $instrumental) {
            return null;
        }

        return [
            'synced' => $synced !== '' ? $synced : null,
            'plain' => $plain !== '' ? $plain : null,
            'title' => (string) ($row['trackName'] ?? ''),
            'artist' => is_string($row['artistName'] ?? null) ? $row['artistName'] : null,
            'instrumental' => $instrumental,
        ];
    }

    /**
     * Strip the decoration YouTube uploaders bolt onto titles — "(Official Video)",
     * "[HD Remaster]", "| Lyrics", a trailing "4K" — which otherwise sink every match.
     */
    private function cleanTitle(string $title): string
    {
        // The leading `\d{4}` catches the year-first spellings — "(2004 Remaster)",
        // "(1991 Live)" — that the noise words alone would miss.
        $noise = '(?:\d{4}\s*)?(?:official\s*)?(?:music\s*|lyric[s]?\s*|audio\s*|hd\s*|4k\s*|full\s*)?'
            .'(?:video|audio|visualizer|mv|m\/v|lyrics?|hd|4k|hq|remaster(?:ed)?(?:\s*\d{4})?|'
            .'live|explicit|clean|extended|full\s*version|colou?r\s*coded)';

        $title = preg_replace('/[\(\[]\s*'.$noise.'[^\)\]]*[\)\]]/iu', ' ', $title) ?? $title;
        $title = preg_replace('/\s*\|\s*'.$noise.'.*$/iu', '', $title) ?? $title;
        $title = preg_replace('/\s*[-–—]\s*'.$noise.'\s*$/iu', '', $title) ?? $title;
        $title = preg_replace('/\s*[\(\[]\s*[\)\]]\s*/u', ' ', $title) ?? $title;

        return trim(preg_replace('/\s{2,}/u', ' ', $title) ?? $title);
    }

    /** YouTube's channel-as-artist often carries a "- Topic" / "VEVO" suffix. */
    private function cleanArtist(string $artist): string
    {
        $artist = preg_replace('/\s*[-–—]\s*topic\s*$/iu', '', $artist) ?? $artist;
        $artist = preg_replace('/vevo\s*$/iu', '', $artist) ?? $artist;

        return trim($artist);
    }
}
