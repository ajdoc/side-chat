<?php

namespace App\Services\Widgets;

use Illuminate\Support\Facades\Http;

/**
 * Turns whatever someone pasted into `v!play` into something the watch-along card can put
 * on screen — a *source*, not a track.
 *
 * The video widget's whole premise is that not every video can be driven the same way, so
 * a source declares which engine plays it and the card picks a player to match:
 *
 *   - `youtube`  → the IFrame Player API. Seekable, so the room stays in lockstep.
 *   - `file`     → a plain <video>: an uploaded clip, or a direct link to .mp4/.webm/…
 *                  Seekable, so the room stays in lockstep.
 *   - `embed`    → the provider's own iframe (Vimeo, Dailymotion, Twitch, Streamable).
 *                  Everyone gets the same start offset, but nothing after that: a third-party
 *                  iframe won't take a seek from us. The card says so rather than pretending.
 *
 * That tiering is the honest version of "universal": anything with a public embed *plays*,
 * and the two engines we can actually drive also *sync*. A link we don't recognise is tried
 * as a direct media file, then — for plain words — as a YouTube search.
 *
 * Metadata (title, author, thumbnail, duration) is best-effort via each site's oEmbed, with
 * YouTube durations coming from the Data API when a key is configured. Every network call has
 * a short timeout and never throws into the send path; a source with no metadata still plays.
 *
 * @phpstan-type Source array{kind: string, key: string|null, url: string|null, embedUrl: string|null, provider: string, title: string, author: string|null, duration: int|null, thumbnail: string|null}
 * @phpstan-type Result array{sources: array<int, Source>, error: string|null}
 */
final class VideoResolver
{
    /** Extensions we'll hand straight to a <video> element. */
    private const FILE_EXTENSIONS = ['mp4', 'm4v', 'webm', 'ogv', 'ogg', 'mov', 'mkv', 'm3u8'];

    private const OEMBED = [
        'vimeo' => 'https://vimeo.com/api/oembed.json',
        'dailymotion' => 'https://www.dailymotion.com/services/oembed',
        'streamable' => 'https://api.streamable.com/oembed.json',
    ];

    public function __construct(private readonly YouTubeResolver $youtube) {}

    /** Does this look like a link to resolve, rather than words to search YouTube for? */
    public function looksLikeLink(string $input): bool
    {
        return (bool) preg_match('#https?://#i', $input)
            || (bool) preg_match('#(youtube\.com|youtu\.be|vimeo\.com|dailymotion\.com|dai\.ly|twitch\.tv|streamable\.com)#i', $input);
    }

    /**
     * Resolve a pasted link into one or more playable sources.
     *
     * @return Result
     */
    public function resolveLink(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return $this->error('Give me something to play — `v!play <link>`, or upload a file.');
        }

        // A YouTube *playlist* link expands into the whole list; a watch link is one video.
        if (($playlistId = $this->youtubePlaylistId($input)) !== null) {
            return $this->fromYouTubePlaylist($playlistId);
        }

        if (($videoId = $this->youtubeVideoId($input)) !== null) {
            return $this->fromYouTube($videoId);
        }

        if (preg_match('#(?:vimeo\.com/(?:video/)?|player\.vimeo\.com/video/)(\d+)#i', $input, $m)) {
            return $this->fromVimeo($m[1], $input);
        }

        if (preg_match('#(?:dailymotion\.com/video/|dai\.ly/)([A-Za-z0-9]+)#i', $input, $m)) {
            return $this->fromDailymotion($m[1], $input);
        }

        if (preg_match('#streamable\.com/(?:e/)?([A-Za-z0-9]+)#i', $input, $m)) {
            return $this->fromStreamable($m[1], $input);
        }

        if (($twitch = $this->twitchEmbed($input)) !== null) {
            return $this->ok([$twitch]);
        }

        // Not a site we know. If it points at something a <video> can open, play it directly.
        if ($this->looksLikeMediaFile($input)) {
            return $this->ok([$this->directFile($input)]);
        }

        return $this->error("I don't know how to play that link. YouTube, Vimeo, Dailymotion, Twitch, Streamable and direct video files (.mp4, .webm, …) all work — or upload the file.");
    }

    /**
     * Search YouTube for plain words, for the card's picker. Delegated whole to the music
     * widget's resolver — it already owns the API key, the quota messaging and the durations.
     *
     * @return Result
     */
    public function search(string $query, int $max = 5): array
    {
        $result = $this->youtube->searchMany($query, $max);
        if ($result['error'] !== null) {
            return $this->error($result['error']);
        }

        $sources = [];
        foreach ($result['tracks'] as $track) {
            $sources[] = $this->youtubeSource(
                (string) $track['videoId'],
                (string) ($track['title'] ?? 'YouTube video'),
                $track['artist'] ?? null,
                $track['duration'] ?? null,
                $track['thumbnail'] ?? null,
            );
        }

        return $sources === [] ? $this->error('Found nothing on YouTube for that.') : $this->ok($sources);
    }

    // --- per-provider resolution --------------------------------------------

    /** @return Result */
    private function fromYouTube(string $videoId): array
    {
        // oEmbed gives title and channel with no key at all; the Data API adds the real
        // duration when one is configured. Neither is required — the card backfills the
        // duration from its own player once the video loads.
        $meta = $this->oembedRaw('https://www.youtube.com/oembed', 'https://www.youtube.com/watch?v='.$videoId);

        return $this->ok([$this->youtubeSource(
            $videoId,
            is_string($meta['title'] ?? null) ? $meta['title'] : 'YouTube video',
            is_string($meta['author_name'] ?? null) ? $meta['author_name'] : null,
            $this->youtubeDuration($videoId),
            is_string($meta['thumbnail_url'] ?? null) ? $meta['thumbnail_url'] : "https://i.ytimg.com/vi/{$videoId}/mqdefault.jpg",
        )]);
    }

    /** @return Result */
    private function fromYouTubePlaylist(string $playlistId): array
    {
        $key = $this->apiKey();
        if ($key === null) {
            return $this->error('Playlists need a YouTube API key set up. Paste a single video link and I\'ll add it.');
        }

        try {
            $res = Http::timeout(6)->get('https://www.googleapis.com/youtube/v3/playlistItems', [
                'part' => 'snippet,contentDetails',
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

        $sources = [];
        foreach ($res->json('items', []) as $item) {
            $id = data_get($item, 'contentDetails.videoId');
            if (! is_string($id) || $id === '') {
                continue;
            }
            $sources[] = $this->youtubeSource(
                $id,
                (string) data_get($item, 'snippet.title', 'YouTube video'),
                data_get($item, 'snippet.videoOwnerChannelTitle') ?? data_get($item, 'snippet.channelTitle'),
                null,
                data_get($item, 'snippet.thumbnails.medium.url') ?? "https://i.ytimg.com/vi/{$id}/mqdefault.jpg",
            );
        }

        return $sources === []
            ? $this->error('That playlist had nothing playable in it.')
            : $this->ok($sources);
    }

    /** @return Result */
    private function fromVimeo(string $id, string $url): array
    {
        $meta = $this->oembedRaw(self::OEMBED['vimeo'], $url);
        $duration = is_numeric($meta['duration'] ?? null) ? (int) $meta['duration'] : null;

        return $this->ok([$this->embedSource(
            provider: 'vimeo',
            embedUrl: "https://player.vimeo.com/video/{$id}",
            title: is_string($meta['title'] ?? null) ? $meta['title'] : 'Vimeo video',
            author: is_string($meta['author_name'] ?? null) ? $meta['author_name'] : null,
            duration: $duration,
            thumbnail: is_string($meta['thumbnail_url'] ?? null) ? $meta['thumbnail_url'] : null,
            key: $id,
        )]);
    }

    /** @return Result */
    private function fromDailymotion(string $id, string $url): array
    {
        $meta = $this->oembedRaw(self::OEMBED['dailymotion'], $url);

        return $this->ok([$this->embedSource(
            provider: 'dailymotion',
            embedUrl: "https://www.dailymotion.com/embed/video/{$id}",
            title: is_string($meta['title'] ?? null) ? $meta['title'] : 'Dailymotion video',
            author: is_string($meta['author_name'] ?? null) ? $meta['author_name'] : null,
            duration: is_numeric($meta['duration'] ?? null) ? (int) $meta['duration'] : null,
            thumbnail: is_string($meta['thumbnail_url'] ?? null) ? $meta['thumbnail_url'] : null,
            key: $id,
        )]);
    }

    /** @return Result */
    private function fromStreamable(string $id, string $url): array
    {
        $meta = $this->oembedRaw(self::OEMBED['streamable'], $url);

        return $this->ok([$this->embedSource(
            provider: 'streamable',
            embedUrl: "https://streamable.com/e/{$id}",
            title: is_string($meta['title'] ?? null) ? $meta['title'] : 'Streamable video',
            author: null,
            duration: is_numeric($meta['duration'] ?? null) ? (int) $meta['duration'] : null,
            thumbnail: is_string($meta['thumbnail_url'] ?? null) ? $meta['thumbnail_url'] : null,
            key: $id,
        )]);
    }

    /**
     * Twitch: a VOD, a clip or a live channel.
     *
     * Its player refuses to load unless the embedding host is named in `parent`, so this is
     * only playable when `app.frontend_url` is the host people actually browse from — which
     * it has to be anyway for social login to return anywhere useful.
     *
     * @return Source|null
     */
    private function twitchEmbed(string $url): ?array
    {
        $parent = parse_url((string) config('app.frontend_url'), PHP_URL_HOST) ?: 'localhost';

        if (preg_match('#twitch\.tv/videos/(\d+)#i', $url, $m)) {
            return $this->embedSource('twitch', "https://player.twitch.tv/?video={$m[1]}&parent={$parent}", 'Twitch video', null, null, null, $m[1]);
        }

        if (preg_match('#(?:twitch\.tv/\w+/clip/|clips\.twitch\.tv/)([A-Za-z0-9_-]+)#i', $url, $m)) {
            return $this->embedSource('twitch', "https://clips.twitch.tv/embed?clip={$m[1]}&parent={$parent}", 'Twitch clip', null, null, null, $m[1]);
        }

        if (preg_match('#twitch\.tv/([A-Za-z0-9_]{3,25})/?(?:$|\?)#i', $url, $m)) {
            return $this->embedSource('twitch', "https://player.twitch.tv/?channel={$m[1]}&parent={$parent}", $m[1].' on Twitch', $m[1], null, null, $m[1]);
        }

        return null;
    }

    /** A link straight at a video file — the same engine an upload uses. @return Source */
    private function directFile(string $url): array
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'Video';

        return [
            'kind' => 'file',
            'key' => null,
            'url' => $url,
            'embedUrl' => null,
            'provider' => 'direct',
            'title' => urldecode($name),
            'author' => parse_url($url, PHP_URL_HOST) ?: null,
            'duration' => null,
            'thumbnail' => null,
        ];
    }

    // --- shapes -------------------------------------------------------------

    /** @return Source */
    private function youtubeSource(string $videoId, string $title, ?string $author, ?int $duration, ?string $thumbnail): array
    {
        return [
            'kind' => 'youtube',
            'key' => $videoId,
            'url' => null,
            'embedUrl' => null,
            'provider' => 'youtube',
            'title' => $title,
            'author' => $author,
            'duration' => $duration,
            'thumbnail' => $thumbnail,
        ];
    }

    /** @return Source */
    private function embedSource(string $provider, string $embedUrl, string $title, ?string $author, ?int $duration, ?string $thumbnail, ?string $key = null): array
    {
        return [
            'kind' => 'embed',
            'key' => $key,
            'url' => null,
            'embedUrl' => $embedUrl,
            'provider' => $provider,
            'title' => $title,
            'author' => $author,
            'duration' => $duration,
            'thumbnail' => $thumbnail,
        ];
    }

    // --- helpers ------------------------------------------------------------

    private function looksLikeMediaFile(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        foreach (self::FILE_EXTENSIONS as $ext) {
            if (str_ends_with($path, '.'.$ext)) {
                return true;
            }
        }

        return false;
    }

    private function youtubeVideoId(string $url): ?string
    {
        if (preg_match('#(?:youtube\.com/watch\?[^ ]*\bv=|youtu\.be/|youtube\.com/embed/|youtube\.com/shorts/|youtube\.com/live/)([A-Za-z0-9_-]{11})#i', $url, $m)) {
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

        return preg_match('#[?&]list=([A-Za-z0-9_-]+)#i', $url, $m) ? $m[1] : null;
    }

    /** Real length in seconds from the Data API, when a key is configured. */
    private function youtubeDuration(string $videoId): ?int
    {
        $key = $this->apiKey();
        if ($key === null) {
            return null;
        }

        try {
            $res = Http::timeout(6)->get('https://www.googleapis.com/youtube/v3/videos', [
                'part' => 'contentDetails',
                'id' => $videoId,
                'key' => $key,
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $res->successful()) {
            return null;
        }

        $iso = (string) data_get($res->json(), 'items.0.contentDetails.duration', '');
        if ($iso === '' || ! preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m)) {
            return null;
        }

        return ((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + ((int) ($m[3] ?? 0));
    }

    /** @return array<string, mixed> */
    private function oembedRaw(string $endpoint, string $url): array
    {
        try {
            $res = Http::timeout(5)->get($endpoint, ['url' => $url, 'format' => 'json']);

            return $res->successful() ? (array) $res->json() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function apiKey(): ?string
    {
        $key = config('services.youtube.key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** @param  array<int, array>  $sources @return Result */
    private function ok(array $sources): array
    {
        return ['sources' => $sources, 'error' => null];
    }

    /** @return Result */
    private function error(string $message): array
    {
        return ['sources' => [], 'error' => $message];
    }
}
