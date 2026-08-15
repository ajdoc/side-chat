<?php

namespace App\Services\Sfu;

/**
 * Everything a browser needs to join a room on one particular SFU.
 *
 * Deliberately provider-shaped rather than provider-specific: `provider` names the driver
 * so the client can pick the right SDK adapter, and everything else is the small set of
 * facts every SFU needs — where to connect, which room, and proof you're allowed in.
 */
final class SfuCredentials
{
    public function __construct(
        /** The driver that minted this — 'livekit' today. The client keys its adapter off it. */
        public readonly string $driver,
        /** Which entry in config('sfu.providers') this came from, for logs and debugging. */
        public readonly string $provider,
        /** Where the browser connects. A wss:// URL for LiveKit. */
        public readonly string $url,
        /** The room name, derived from the channel — see SfuProvider::roomFor. */
        public readonly string $room,
        /** Short-lived proof of admission, scoped to one person and one room. */
        public readonly string $token,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'provider' => $this->provider,
            'url' => $this->url,
            'room' => $this->room,
            'token' => $this->token,
        ];
    }
}
