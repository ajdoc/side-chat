<?php

namespace App\Services\Widgets;

use Illuminate\Support\Facades\Http;

/**
 * Turns whatever someone typed after `m!p` into playable, richly-described YouTube tracks.
 *
 * Because listeners drive their *own* YouTube player kept in sync (nobody re-streams
 * anyone's audio), everything has to end up as a YouTube video id. What we accept:
 *
 *   - a YouTube video / playlist link
 *   - a Spotify / SoundCloud / Deezer *track* link  → its title via the site's oEmbed,
 *                                                      then matched on YouTube
 *   - plain text                                     → a YouTube search (top results, for
 *                                                      the picker)
 *
 * Whenever a Data API key is configured, resolved ids are "hydrated" in one `videos` call
 * so the card can show real durations and artists — the thing that makes the player feel
 * like a proper music bot rather than a link embed. Without a key, plain YouTube video
 * links still queue (title-only via oEmbed); anything needing a lookup returns a friendly
 * error. Every network call is best-effort with a short timeout and never throws into the
 * send path.
 *
 * @phpstan-type Track array{videoId: string, title: string, artist: string|null, duration: int|null, thumbnail: string|null, source: string}
 * @phpstan-type Result array{tracks: array<int, Track>, error: string|null}
 */
final class YouTubeResolver
{
    private const OEMBED = [
        'youtube' => 'https://www.youtube.com/oembed',
        'spotify' => 'https://open.spotify.com/oembed',
        'soundcloud' => 'https://soundcloud.com/oembed',
        'deezer' => 'https://api.deezer.com/oembed',
    ];

    private const API = 'https://www.googleapis.com/youtube/v3';

    public function __construct(private readonly SpotifyClient $spotify) {}

    /** Does this look like a link we should resolve, rather than words to search for? */
    public function looksLikeLink(string $input): bool
    {
        return (bool) preg_match('#https?://#i', $input)
            || (bool) preg_match('#(youtube\.com|youtu\.be|open\.spotify\.com|soundcloud\.com|deezer\.com)#i', $input);
    }

    /** Resolve a link into one or more tracks ready to enqueue. @return Result */
    public function resolveLink(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return $this->error('Give me something to play — `m!p <link or search>`.');
        }

        if (($playlistId = $this->youtubePlaylistId($input)) !== null) {
            return $this->fromYouTubePlaylist($playlistId);
        }

        if (($videoId = $this->youtubeVideoId($input)) !== null) {
            return $this->fromYouTubeVideo($videoId);
        }

        // Any Spotify link (track, playlist, album) is read from its public embed page —
        // the Web API no longer serves playlist tracks. Tracks come back as shells that
        // resolve to YouTube lazily on play.
        if (preg_match('#open\.spotify\.com/(track|playlist|album)/([A-Za-z0-9]+)#i', $input, $m)) {
            return $this->spotify->resolve(strtolower($m[1]), $m[2]);
        }

        foreach (['soundcloud' => 'soundcloud.com', 'deezer' => 'deezer.com'] as $source => $host) {
            if (stripos($input, $host) !== false) {
                return $this->fromExternal($source, $input);
            }
        }

        // A bare link we don't recognise — try searching for it as a last resort.
        return $this->searchMany($input, 1);
    }

    /**
     * Search YouTube and return the top matches (for the picker). @return Result
     */
    public function searchMany(string $query, int $max = 5): array
    {
        $key = $this->apiKey();
        if ($key === null) {
            return $this->error('Searching needs a YouTube API key. Paste a YouTube video link instead and I\'ll queue it.');
        }

        try {
            $res = Http::timeout(6)->get(self::API.'/search', [
                'part' => 'snippet',
                'type' => 'video',
                'maxResults' => max(1, min(10, $max)),
                'q' => $query,
                'key' => $key,
            ]);
        } catch (\Throwable) {
            return $this->error('Couldn\'t reach YouTube. Try again in a moment.');
        }

        $ids = [];
        foreach ($res->successful() ? $res->json('items', []) : [] as $item) {
            $id = data_get($item, 'id.videoId');
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return $this->error('Found nothing on YouTube for that.');
        }

        return $this->ok($this->hydrate($ids, 'youtube'));
    }

    /**
     * One track "like" the given one, for autoplay/radio, excluding anything already heard.
     * Best-effort: YouTube removed the related-videos API, so this is a seeded search on the
     * track's artist/title. Null when nothing fresh turns up (or there's no key).
     *
     * @param  array{title?: string, artist?: string, videoId?: string}  $track
     * @param  array<int, string>  $excludeVideoIds
     * @return Track|null
     */
    public function relatedTo(array $track, array $excludeVideoIds = []): ?array
    {
        $seed = trim(($track['artist'] ?? '').' '.($track['title'] ?? ''));
        if ($seed === '') {
            return null;
        }

        $result = $this->searchMany($seed, 8);
        foreach ($result['tracks'] as $candidate) {
            if (! in_array($candidate['videoId'], $excludeVideoIds, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return Result */
    private function fromYouTubeVideo(string $videoId): array
    {
        if ($this->apiKey() !== null) {
            $tracks = $this->hydrate([$videoId], 'youtube');
            if ($tracks !== []) {
                return $this->ok($tracks);
            }
        }

        // Keyless (or hydration failed): title-only via oEmbed. Duration fills in on the
        // client once the player knows it (see the 'meta' action).
        $meta = $this->oembed('youtube', 'https://www.youtube.com/watch?v='.$videoId);

        return $this->ok([[
            'videoId' => $videoId,
            'title' => $meta['title'] ?? 'YouTube video',
            'artist' => $this->cleanArtist($meta['author_name'] ?? null),
            'duration' => null,
            'thumbnail' => $meta['thumbnail_url'] ?? $this->thumb($videoId),
            'source' => 'youtube',
        ]]);
    }

    /** @return Result */
    private function fromYouTubePlaylist(string $playlistId): array
    {
        $key = $this->apiKey();
        if ($key === null) {
            return $this->error('Playlists need a YouTube API key set up. Paste a single video link and I\'ll queue it.');
        }

        try {
            $res = Http::timeout(6)->get(self::API.'/playlistItems', [
                'part' => 'contentDetails',
                'maxResults' => 50,
                'playlistId' => $playlistId,
                'key' => $key,
            ]);
        } catch (\Throwable) {
            return $this->error('Couldn\'t reach YouTube to read that playlist. Try again in a moment.');
        }

        if (! $res->successful()) {
            return $this->error('Couldn\'t read that playlist — is it public?');
        }

        $ids = [];
        foreach ($res->json('items', []) as $item) {
            $id = data_get($item, 'contentDetails.videoId');
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        $tracks = $ids === [] ? [] : $this->hydrate($ids, 'youtube');

        return $tracks === []
            ? $this->error('That playlist had nothing playable in it.')
            : $this->ok($tracks);
    }

    /** A SoundCloud / Deezer *track* → matched on YouTube by "artist title". @return Result */
    private function fromExternal(string $source, string $url): array
    {
        $meta = $this->externalMeta($source, $url);
        $title = $meta['title'];
        if ($title === null || $title === '') {
            return $this->error('Couldn\'t read that '.ucfirst($source).' link.');
        }

        // Search with the artist *and* title — a bare song name lands on covers and lyric
        // re-uploads. This is the whole reason a Spotify link resolves to the right track.
        $query = trim(($meta['artist'] ?? '').' '.$title);
        $result = $this->searchMany($query, 1);
        if ($result['error'] !== null || $result['tracks'] === []) {
            return $result['error'] !== null ? $result : $this->error("Couldn't find \"{$query}\" on YouTube.");
        }

        // Play via YouTube, but show the original's clean name/artist/art and honest badge.
        $track = $result['tracks'][0];
        $track['source'] = $source;
        $track['title'] = $title;
        $track['artist'] = $meta['artist'] ?? $track['artist'];
        $track['thumbnail'] = $meta['thumbnail'] ?? $track['thumbnail'];

        return $this->ok([$track]);
    }

    /**
     * Title + artist + cover for a SoundCloud / Deezer track link, from its oEmbed. Both
     * hand back the artist as `author_name`. (Spotify is read from its embed page instead —
     * see SpotifyClient.)
     *
     * @return array{title: string|null, artist: string|null, thumbnail: string|null}
     */
    private function externalMeta(string $source, string $url): array
    {
        $o = $this->oembed($source, $url);

        return [
            'title' => is_string($o['title'] ?? null) ? $o['title'] : null,
            'artist' => is_string($o['author_name'] ?? null) ? $o['author_name'] : null,
            'thumbnail' => is_string($o['thumbnail_url'] ?? null) ? $o['thumbnail_url'] : null,
        ];
    }

    /**
     * Fill in title/artist/duration/thumbnail for a batch of video ids in one API call.
     *
     * @param  array<int, string>  $ids
     * @return array<int, array{videoId: string, title: string, artist: string|null, duration: int|null, thumbnail: string|null, source: string}>
     */
    private function hydrate(array $ids, string $source): array
    {
        $key = $this->apiKey();
        if ($key === null || $ids === []) {
            return [];
        }

        try {
            $res = Http::timeout(6)->get(self::API.'/videos', [
                'part' => 'snippet,contentDetails',
                'id' => implode(',', array_slice($ids, 0, 50)),
                'key' => $key,
            ]);
        } catch (\Throwable) {
            return [];
        }

        if (! $res->successful()) {
            return [];
        }

        // Index by id so we can return them in the order asked for.
        $byId = [];
        foreach ($res->json('items', []) as $item) {
            $id = data_get($item, 'id');
            if (! is_string($id)) {
                continue;
            }
            $byId[$id] = [
                'videoId' => $id,
                'title' => (string) data_get($item, 'snippet.title', 'YouTube video'),
                'artist' => $this->cleanArtist(data_get($item, 'snippet.channelTitle')),
                'duration' => $this->parseDuration((string) data_get($item, 'contentDetails.duration', '')),
                'thumbnail' => data_get($item, 'snippet.thumbnails.medium.url') ?? $this->thumb($id),
                'source' => $source,
            ];
        }

        $tracks = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $tracks[] = $byId[$id];
            }
        }

        return $tracks;
    }

    /** @return array<string, mixed> */
    private function oembed(string $source, string $url): array
    {
        try {
            $res = Http::timeout(5)->get(self::OEMBED[$source], ['url' => $url, 'format' => 'json']);

            return $res->successful() ? (array) $res->json() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function youtubeVideoId(string $url): ?string
    {
        if (preg_match('#(?:youtube\.com/watch\?[^ ]*\bv=|youtu\.be/|youtube\.com/embed/|youtube\.com/shorts/)([A-Za-z0-9_-]{11})#i', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    private function youtubePlaylistId(string $url): ?string
    {
        // `watch?v=…&list=…` is "play this video", not "play the list".
        if ($this->youtubeVideoId($url) !== null) {
            return null;
        }
        if (preg_match('#[?&]list=([A-Za-z0-9_-]+)#i', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    /** "Rick Astley - Topic" / "RickAstleyVEVO" → "Rick Astley". Best-effort tidy-up. */
    private function cleanArtist(?string $channel): ?string
    {
        if (! is_string($channel) || $channel === '') {
            return null;
        }

        return trim(preg_replace('/\s*-\s*Topic$/i', '', $channel)) ?: $channel;
    }

    /** ISO-8601 duration ("PT3M33S") → whole seconds. */
    private function parseDuration(string $iso): ?int
    {
        if ($iso === '' || ! preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m)) {
            return null;
        }

        return ((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + ((int) ($m[3] ?? 0));
    }

    private function thumb(string $videoId): string
    {
        return "https://i.ytimg.com/vi/{$videoId}/mqdefault.jpg";
    }

    private function apiKey(): ?string
    {
        $key = config('services.youtube.key');

        return is_string($key) && $key !== '' ? $key : null;
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
