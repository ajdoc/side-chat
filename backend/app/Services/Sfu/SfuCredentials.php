<?php

namespace App\Services\Sfu;

/**
 * Everything a browser needs to reach one particular SFU.
 *
 * Deliberately provider-shaped rather than provider-specific: `driver` names the client-side
 * adapter, and the rest is the small set of facts an SFU needs — where to connect, which room,
 * and proof you're allowed in.
 *
 * Two of those are nullable, because the providers genuinely differ on them. LiveKit hands the
 * browser a URL and a signed token and gets out of the way. Cloudflare Realtime has neither: its
 * API is authenticated with an app secret that must never reach a browser, so the client talks
 * to *us* and we talk to Cloudflare (see SfuController). Forcing a token into that shape would
 * mean inventing one nobody checks.
 */
final class SfuCredentials
{
    public function __construct(
        /** The driver that minted this — 'livekit' or 'cloudflare'. The client keys its adapter off it. */
        public readonly string $driver,
        /** Which entry in config('sfu.providers') this came from, for logs and debugging. */
        public readonly string $provider,
        /** The room name, derived from the channel. Meaningful to every provider. */
        public readonly string $room,
        /** Where the browser connects directly, for providers it connects to directly. */
        public readonly ?string $url = null,
        /** Short-lived proof of admission, for providers that use one. */
        public readonly ?string $token = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'provider' => $this->provider,
            'room' => $this->room,
            'url' => $this->url,
            'token' => $this->token,
        ];
    }
}
