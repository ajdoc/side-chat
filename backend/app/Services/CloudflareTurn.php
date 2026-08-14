<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Short-lived TURN credentials from Cloudflare Realtime.
 *
 * Unlike coturn or a plain hosted relay, there is no static username/password to drop in
 * an env var: you hold a key id and an API token, and exchange them for a credential that
 * expires. That exchange is an outbound HTTP call, and it sits on the join path — so the
 * two things this class is really about are not making it per-join, and not letting it
 * take the call down when Cloudflare is unreachable.
 *
 * Caching: one credential is shared by everybody until it nears expiry. It's a relay
 * ticket, not an identity — nothing about it is per-user — so minting one per join would
 * buy no isolation and cost an API round-trip on every single join.
 */
final class CloudflareTurn
{
    private const ENDPOINT = 'https://rtc.live.cloudflare.com/v1/turn/keys/%s/credentials/generate-ice-servers';

    /**
     * Retire the cached credential this long before Cloudflare does. A credential handed
     * out in its last seconds would expire while the call it belongs to is still up, and
     * ICE does not re-fetch — so the tail of the TTL is deliberately never served.
     */
    private const CACHE_MARGIN = 600;

    private const CACHE_KEY = 'webrtc:cloudflare-turn';

    /** Cloudflare can be slow or down; a call must not wait on it. */
    private const TIMEOUT = 5;

    /**
     * The ICE server entry for Cloudflare, or null if it isn't configured — or if minting
     * failed, because a missing relay degrades a call for the minority who need one, while
     * throwing here would deny the call to everybody.
     *
     * @return array<string, mixed>|null
     */
    public function iceServer(): ?array
    {
        $keyId = (string) config('webrtc.cloudflare.key_id');
        $token = (string) config('webrtc.cloudflare.api_token');

        if ($keyId === '' || $token === '') {
            return null;
        }

        $ttl = max(self::CACHE_MARGIN + 60, (int) config('webrtc.cloudflare.ttl', 86400));

        return Cache::remember(
            self::CACHE_KEY.':'.$keyId,
            $ttl - self::CACHE_MARGIN,
            fn (): ?array => $this->mint($keyId, $token, $ttl)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mint(string $keyId, string $token, int $ttl): ?array
    {
        try {
            $response = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->post(sprintf(self::ENDPOINT, $keyId), ['ttl' => $ttl]);

            if ($response->failed()) {
                Log::warning('Cloudflare TURN credential request failed', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $ice = $response->json('iceServers');
        } catch (Throwable $e) {
            Log::warning('Cloudflare TURN credential request threw', ['message' => $e->getMessage()]);

            return null;
        }

        if (! is_array($ice)) {
            return null;
        }

        // `iceServers` comes back as a *list* of entries — Cloudflare returns its STUN servers
        // as one and the relay as another — though the API is also documented returning a bare
        // object with `urls` on it. Both shapes are normalised to a list here, because reading
        // `$ice['urls']` off the list form silently finds nothing: that mismatch is what had
        // this returning null against the live API while the tests, which faked the object
        // form, passed.
        $entries = isset($ice['urls']) ? [$ice] : array_filter($ice, 'is_array');

        // The relay is the entry that carries credentials. The STUN-only entry is deliberately
        // dropped rather than passed through: it needs no minting, config('webrtc.stun_urls')
        // already supplies STUN, and this method's contract is one TURN entry.
        foreach ($entries as $entry) {
            $urls = array_values(array_filter((array) ($entry['urls'] ?? []), 'is_string'));

            if ($urls === [] || ($entry['username'] ?? null) === null) {
                continue;
            }

            return [
                'urls' => $urls,
                'username' => $entry['username'],
                'credential' => $entry['credential'] ?? null,
            ];
        }

        return null;
    }
}
