<?php

namespace App\Services;

use App\Jobs\FetchLinkPreview;
use App\Models\Channel;
use App\Models\LinkPreview;
use App\Models\Message;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class LinkPreviewService
{
    /** Unfurl at most this many links per message — a wall of cards is worse than none. */
    public const MAX_LINKS = 3;

    /** http(s) URLs, stopping at whitespace and the characters that usually *contain* a link. */
    private const URL_PATTERN = '~https?://[^\s<>"\'`]+~i';

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * Pull the linkable URLs out of a message body.
     *
     * @return array<int, string>
     */
    public function extractUrls(?string $body): array
    {
        if (blank($body)) {
            return [];
        }

        preg_match_all(self::URL_PATTERN, $body, $matches);

        return collect($matches[0])
            ->map(fn (string $url) => $this->trimTrailingPunctuation($url))
            ->filter()
            ->unique()
            ->take(self::MAX_LINKS)
            ->values()
            ->all();
    }

    /**
     * Point a message at a preview row for each URL in its body (creating the rows on
     * first sight), drop the ones it no longer links to, and queue a fetch for anything
     * that's never been fetched or has gone stale.
     */
    public function syncFor(Message $message): void
    {
        $urls = $this->extractUrls($message->body);

        if ($urls === []) {
            $message->linkPreviews()->detach();

            return;
        }

        $pivot = [];
        $toFetch = [];

        foreach ($urls as $position => $url) {
            $preview = LinkPreview::firstOrCreate(
                ['url_hash' => LinkPreview::hashFor($url)],
                ['url' => $url, 'status' => 'pending'],
            );

            $pivot[$preview->id] = ['position' => $position];

            if ($preview->status === 'pending' || $preview->isStale()) {
                $toFetch[] = $preview;
            }
        }

        $message->linkPreviews()->sync($pivot);

        foreach ($toFetch as $preview) {
            FetchLinkPreview::dispatch($preview, $message);
        }
    }

    /**
     * Every link shared in a channel (main timeline *and* threads) — the Info > Links tab.
     *
     * One row per *sharing*, not per URL: the same link posted twice is two entries, each
     * pointing back at its own message. Failed and pending unfurls are included — a link
     * whose site blocks bots is still a link somebody shared, and the tab would be lying
     * by omission if it hid them. The UI falls back to the host when there's no title.
     */
    public function forChannel(Channel $channel): LengthAwarePaginator
    {
        return LinkPreview::query()
            ->join('link_preview_message as pivot', 'pivot.link_preview_id', '=', 'link_previews.id')
            ->join('messages', 'messages.id', '=', 'pivot.message_id')
            ->join('users', 'users.id', '=', 'messages.user_id')
            ->where('messages.channel_id', $channel->id)
            ->orderByDesc('messages.id')
            ->orderBy('pivot.position') // a message's own links stay in the order it listed them
            ->select([
                'link_previews.*',
                'pivot.message_id as shared_in_message_id',
                'messages.thread_id as shared_in_thread_id', // the timeline can't jump into a thread
                'users.name as shared_by',
                'messages.created_at as shared_at',
            ])
            ->paginate(50);
    }

    /**
     * Fetch a URL and fill the row in. Always resolves to `ok` or `failed` — never
     * leaves it `pending`, or the message would wait on a preview that never lands.
     */
    public function unfurl(LinkPreview $preview): void
    {
        $result = $this->fetcher->get($preview->url);

        if ($result === null) {
            $preview->update(['status' => 'failed', 'fetched_at' => now()]);

            return;
        }

        // A link straight to an image is its own preview — show the image, skip the card.
        if (Str::startsWith($result['content_type'], 'image/')) {
            $preview->update([
                'status' => 'ok',
                'kind' => 'image',
                'image_url' => $result['url'],
                'title' => null,
                'description' => null,
                'site_name' => $this->hostOf($result['url']),
                'fetched_at' => now(),
            ]);

            return;
        }

        if (! Str::contains($result['content_type'], 'html')) {
            $preview->update(['status' => 'failed', 'fetched_at' => now()]);

            return;
        }

        $meta = $this->parseHtml($result['body'], $result['url']);

        // A card with no title is just a link with extra steps.
        if (blank($meta['title'])) {
            $preview->update(['status' => 'failed', 'fetched_at' => now()]);

            return;
        }

        $preview->update([
            'status' => 'ok',
            'kind' => 'link',
            'title' => $meta['title'],
            'description' => $meta['description'],
            'site_name' => $meta['site_name'] ?? $this->hostOf($result['url']),
            'image_url' => $meta['image_url'],
            'fetched_at' => now(),
        ]);
    }

    /**
     * Open Graph first, Twitter cards second, plain <title>/<meta description> last.
     *
     * @return array{title: ?string, description: ?string, site_name: ?string, image_url: ?string}
     */
    private function parseHtml(string $html, string $url): array
    {
        $previous = libxml_use_internal_errors(true); // real-world HTML is not valid XML
        $doc = new DOMDocument();
        $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);

        $title = $this->meta($xpath, ['og:title', 'twitter:title'])
            ?? $this->text($xpath, '//title');

        $description = $this->meta($xpath, ['og:description', 'twitter:description', 'description']);

        $image = $this->meta($xpath, ['og:image', 'og:image:url', 'og:image:secure_url', 'twitter:image', 'twitter:image:src']);

        // og:image is routinely a relative path, and http(s) only — the browser will load it.
        if (filled($image)) {
            $image = $this->fetcher->resolveAgainst($image, $url);

            if (! Str::startsWith(Str::lower($image), ['http://', 'https://'])) {
                $image = null;
            }
        }

        return [
            'title' => $this->clean($title, 255),
            'description' => $this->clean($description, 300),
            'site_name' => $this->clean($this->meta($xpath, ['og:site_name']), 100),
            'image_url' => $image ?: null,
        ];
    }

    /** First non-empty <meta> content for any of these keys (og:* uses property, twitter:* uses name). */
    private function meta(DOMXPath $xpath, array $keys): ?string
    {
        foreach ($keys as $key) {
            $nodes = $xpath->query("//meta[@property='{$key}' or @name='{$key}']/@content");

            $value = $nodes?->item(0)?->nodeValue;

            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    private function text(DOMXPath $xpath, string $query): ?string
    {
        return $xpath->query($query)?->item(0)?->textContent ?: null;
    }

    private function clean(?string $value, int $limit): ?string
    {
        if (blank($value)) {
            return null;
        }

        $collapsed = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $collapsed === '' ? null : Str::limit($collapsed, $limit);
    }

    private function hostOf(string $url): ?string
    {
        return parse_url($url, PHP_URL_HOST) ?: null;
    }

    /**
     * A URL at the end of a sentence swallows the punctuation ("see https://x.com.").
     * Also drop a trailing ")" unless the URL opened one itself — markdown link syntax
     * `[x](https://y.com)` hands us the closing paren.
     */
    private function trimTrailingPunctuation(string $url): string
    {
        $url = rtrim($url, '.,;:!?\'"');

        while (Str::endsWith($url, ')') && substr_count($url, ')') > substr_count($url, '(')) {
            $url = substr($url, 0, -1);
        }

        return $url;
    }
}
