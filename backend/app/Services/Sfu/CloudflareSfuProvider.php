<?php

namespace App\Services\Sfu;

use App\Models\Channel;
use App\Models\User;

/**
 * Cloudflare Realtime as an SFU provider.
 *
 * The odd one out, and the interface survives it — which is the point of having an interface.
 * Where LiveKit answers "here is a URL and a token, go and join", this answers "you are allowed;
 * negotiate through us". There is nothing to mint: the app secret stays on this server, the
 * browser never sees it, and every SDP exchange is relayed by SfuController.
 *
 * So `credentialsFor` is really a *permission* check plus a room name. That is enough, because
 * the client adapter keys off `driver` and knows Cloudflare means "talk to the backend".
 */
final class CloudflareSfuProvider implements SfuProvider
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
        return 'cloudflare';
    }

    public function isConfigured(): bool
    {
        return $this->api()->isConfigured();
    }

    public function credentialsFor(Channel $channel, User $user): ?SfuCredentials
    {
        if (! $this->isConfigured()) {
            return null;
        }

        // No URL and no token, deliberately — see SfuCredentials. The room name is still real
        // and still derived from the channel, because it is what scopes the session bookkeeping
        // this provider's sessions are recorded against.
        return new SfuCredentials(
            driver: $this->driver(),
            provider: $this->name,
            room: 'channel-'.$channel->getKey(),
        );
    }

    /** The API client, built from this entry's own credentials. */
    public function api(): CloudflareRealtime
    {
        return new CloudflareRealtime(
            trim((string) ($this->config['app_id'] ?? '')),
            trim((string) ($this->config['app_secret'] ?? '')),
        );
    }
}
