<?php

namespace App\Services\Sfu;

use App\Models\Channel;
use App\Models\User;

/**
 * LiveKit, either the hosted Cloud or a server you run yourself — the same software and the
 * same credential format, which is why one driver covers both and why moving between them is
 * a change of URL rather than a rewrite.
 *
 * A LiveKit join credential is an HS256 JWT carrying "video grants": the room, and what the
 * bearer may do in it. That is signed here rather than fetched, so unlike CloudflareTurn this
 * costs no round-trip and cannot fail because a third party is having a bad day — the only
 * way it returns null is if it was never configured.
 *
 * The JWT is assembled by hand. It is three base64url segments and one HMAC, the format is
 * fixed by the spec, and doing it here keeps a community SDK off the path that authorises
 * entry to a call. There is no official LiveKit SDK for PHP to prefer instead.
 */
final class LiveKitProvider implements SfuProvider
{
    /**
     * @param  array<string, mixed>  $config  One entry from config('sfu.providers').
     */
    public function __construct(
        private readonly string $name,
        private readonly array $config,
    ) {}

    public function driver(): string
    {
        return 'livekit';
    }

    public function isConfigured(): bool
    {
        return $this->url() !== '' && $this->key() !== '' && $this->secret() !== '';
    }

    public function credentialsFor(Channel $channel, User $user): ?SfuCredentials
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $room = $this->roomFor($channel);

        return new SfuCredentials(
            driver: $this->driver(),
            provider: $this->name,
            url: $this->url(),
            room: $room,
            token: $this->mint($room, $user),
        );
    }

    /**
     * The room name for a channel.
     *
     * Derived rather than stored, so there is no second piece of state to keep in step with
     * the channel and no way for two people in one channel to end up in two rooms. Prefixed
     * because a room name is a flat global namespace on the SFU, shared with anything else
     * pointed at the same project.
     */
    private function roomFor(Channel $channel): string
    {
        return 'channel-'.$channel->getKey();
    }

    /**
     * Sign the join token.
     *
     * `identity` is what the SFU keys a participant on, and the client matches published
     * tracks back to a person with it — so it has to be the user id and nothing prettier.
     * The grants are the app's existing rules restated for the SFU: everybody in a call may
     * speak, listen, and send data, because the decision about whether they may be here at
     * all was already made by the request that got us this far.
     */
    private function mint(string $room, User $user): string
    {
        $now = time();
        $ttl = max(60, (int) config('sfu.token_ttl', 3600));

        $claims = [
            'iss' => $this->key(),
            'sub' => (string) $user->getKey(),
            'nbf' => $now,
            'exp' => $now + $ttl,
            // Unique per mint: LiveKit uses it to reject a replayed token.
            'jti' => $user->getKey().'-'.$now,
            'name' => (string) $user->name,
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => true,
                'canSubscribe' => true,
                // The remote-control channel and anything else that used a data channel in the
                // mesh needs this; without it the call works and remote control silently doesn't.
                'canPublishData' => true,
            ],
        ];

        $header = $this->segment(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $this->segment($claims);
        $signature = hash_hmac('sha256', $header.'.'.$payload, $this->secret(), true);

        return $header.'.'.$payload.'.'.$this->base64Url($signature);
    }

    /** @param array<string, mixed> $data */
    private function segment(array $data): string
    {
        return $this->base64Url((string) json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    /** Base64 as JWT wants it: URL alphabet, no padding. */
    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function url(): string
    {
        return trim((string) ($this->config['url'] ?? ''));
    }

    private function key(): string
    {
        return trim((string) ($this->config['key'] ?? ''));
    }

    private function secret(): string
    {
        return trim((string) ($this->config['secret'] ?? ''));
    }
}
