<?php

namespace App\Services;

use App\Contracts\GifProvider;
use App\Services\Gif\GiphyProvider;
use App\Services\Gif\KlipyProvider;

/**
 * Coordinates the composer's GIF picker across every configured provider (Giphy, Klipy).
 *
 * Each provider is queried and their results are interleaved round-robin into one grid, so a
 * search shows a mix rather than all of one source then all of the other. Duplicates (same
 * media URL) are dropped. A provider with no key sits out silently; if none is configured the
 * controller answers 422 and the picker shows a "not configured" note.
 *
 * To add a source: implement {@see GifProvider} and add it to {@see self::providers()}.
 */
final class GifService
{
    private const LIMIT = 24;

    public function __construct(
        private readonly GiphyProvider $giphy,
        private readonly KlipyProvider $klipy,
    ) {}

    /** @return array<int, GifProvider> */
    private function providers(): array
    {
        return [$this->giphy, $this->klipy];
    }

    /** @return array<int, GifProvider> */
    private function active(): array
    {
        return array_values(array_filter($this->providers(), fn (GifProvider $p) => $p->configured()));
    }

    public function configured(): bool
    {
        return $this->active() !== [];
    }

    /** Attribution labels for the picker footer — one per configured provider. */
    public function providerLabels(): array
    {
        return array_map(fn (GifProvider $p) => $p->label(), $this->active());
    }

    /**
     * Host suffixes a stored GIF's URL may use — the union across configured providers. A GIF
     * couldn't have come from our picker unless one of these served it, so this is the
     * allowlist {@see AttachmentService::storeGif()} checks a user-supplied URL against.
     *
     * @return array<int, string>
     */
    public function allowedHosts(): array
    {
        $hosts = [];
        foreach ($this->active() as $provider) {
            $hosts = array_merge($hosts, $provider->hosts());
        }

        return array_values(array_unique($hosts));
    }

    /** Trending GIFs, merged across providers. */
    public function featured(): array
    {
        return $this->merge(array_map(fn (GifProvider $p) => $p->featured(self::LIMIT), $this->active()));
    }

    /** Search GIFs, merged across providers. */
    public function search(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return $this->featured();
        }

        return $this->merge(array_map(fn (GifProvider $p) => $p->search($query, self::LIMIT), $this->active()));
    }

    /**
     * Interleave several providers' result lists round-robin (first of each, then second of
     * each, …), dropping any GIF whose media URL we've already taken.
     *
     * @param  array<int, array<int, array{url: string}>>  $lists
     * @return array<int, array<string, mixed>>
     */
    private function merge(array $lists): array
    {
        $lists = array_values(array_filter($lists));
        $out = [];
        $seen = [];

        for ($i = 0; ; $i++) {
            $anyAtThisIndex = false;

            foreach ($lists as $list) {
                if (! array_key_exists($i, $list)) {
                    continue;
                }

                $anyAtThisIndex = true;
                $item = $list[$i];

                if (! isset($seen[$item['url']])) {
                    $seen[$item['url']] = true;
                    $out[] = $item;
                }
            }

            if (! $anyAtThisIndex) {
                break;
            }
        }

        return $out;
    }
}
