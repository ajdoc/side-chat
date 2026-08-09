<?php

namespace App\Services\Notifications;

use App\Models\DeviceToken;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends to Firebase Cloud Messaging over the HTTP v1 API.
 *
 * v1 and not the legacy `fcm/send`: the old server key was switched off in 2024, so the
 * only path left authenticates as a service account. That means an OAuth access token,
 * which means signing a JWT with the account's private key — done here with `openssl_sign`
 * rather than by pulling in google/apiclient, because the whole of what we need from that
 * library is thirty lines and one HTTP round trip.
 *
 * Messages go out **data-only** — no `notification` block. With a `notification` block
 * Android hands the payload straight to the system tray whenever the app is backgrounded
 * and our code never runs, which costs us the two things that matter: collapsing repeat
 * messages from one channel into one alert, and staying silent when the user already has
 * that channel open. Data-only wakes the app's own handler every time and lets it decide.
 */
final class FcmSender
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Google issues these for an hour; we re-mint a little early to avoid the edge. */
    private const TOKEN_TTL = 3300;

    /**
     * Is a credential present at all?
     *
     * Static and deliberately shallow — it looks at the config string and nothing else, so
     * the send path can ask it per message without parsing JSON each time. Callers that are
     * about to actually send want {@see configured()}, which checks the contents too.
     */
    public static function enabled(): bool
    {
        $raw = config('services.fcm.credentials');

        return is_string($raw) && trim($raw) !== '';
    }

    /** Is push configured *and* usable? Everything upstream no-ops quietly when it isn't. */
    public function configured(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * Deliver one payload to every one of these devices.
     *
     * FCM v1 has no batch endpoint (the old `registration_ids` fan-out went away with the
     * legacy API), so this is one request per token. That is fine at the scale a chat
     * message implies — a handful of devices per recipient — and the whole thing runs on
     * the queue anyway.
     *
     * @param  iterable<int, DeviceToken>  $devices
     * @param  array<string, string>  $data  Values must be strings; FCM rejects anything else.
     * @return int How many were accepted.
     */
    public function send(iterable $devices, array $data): int
    {
        if (($credentials = $this->credentials()) === null) {
            return 0;
        }

        $accessToken = $this->accessToken($credentials);

        if ($accessToken === null) {
            return 0;
        }

        $url = sprintf(
            'https://fcm.googleapis.com/v1/projects/%s/messages:send',
            $credentials['project_id'],
        );

        $sent = 0;

        foreach ($devices as $device) {
            if ($this->deliver($url, $accessToken, $device, $data)) {
                $sent++;
            }
        }

        return $sent;
    }

    /** @param  array<string, string>  $data */
    private function deliver(string $url, string $accessToken, DeviceToken $device, array $data): bool
    {
        $response = Http::withToken($accessToken)
            ->asJson()
            ->post($url, [
                'message' => [
                    'token' => $device->token,
                    'data' => $data,
                    'android' => [
                        // A chat message is worthless five minutes late, and `high` is also
                        // what wakes a dozing device out of Doze to run our handler at all.
                        'priority' => 'high',
                        // Anything undelivered after a day is history, not news.
                        'ttl' => '86400s',
                    ],
                ],
            ]);

        if ($response->successful()) {
            $device->forceFill(['last_used_at' => now()])->save();

            return true;
        }

        $this->handleFailure($device, $response->status(), (string) $response->body());

        return false;
    }

    /**
     * Prune what FCM tells us is dead, keep what might just be having a bad day.
     *
     * An uninstalled app answers 404 UNREGISTERED and a malformed token 400 — both mean
     * this row will never work again and deleting it is the only thing that stops us
     * retrying it forever. A 429 or a 5xx is Google's problem, not the token's.
     */
    private function handleFailure(DeviceToken $device, int $status, string $body): void
    {
        if ($status === 404 || $status === 400) {
            $device->delete();

            return;
        }

        Log::warning('FCM send failed', [
            'device_token_id' => $device->id,
            'status' => $status,
            'body' => mb_substr($body, 0, 500),
        ]);
    }

    /**
     * An OAuth access token for the service account, cached for its lifetime.
     *
     * Cached because it is good for an hour and minting one costs a signature plus a round
     * trip to Google — per message sent, that would dwarf the actual send.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function accessToken(array $credentials): ?string
    {
        $key = 'fcm:access-token:'.md5((string) $credentials['client_email']);

        $token = Cache::get($key);

        if (is_string($token)) {
            return $token;
        }

        $assertion = $this->assertion($credentials);

        if ($assertion === null) {
            return null;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);

        if (! $response->successful() || ! is_string($token = $response->json('access_token'))) {
            Log::warning('FCM token exchange failed', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);

            return null;
        }

        Cache::put($key, $token, self::TOKEN_TTL);

        return $token;
    }

    /** The signed JWT Google swaps for an access token. @param array<string, mixed> $credentials */
    private function assertion(array $credentials): ?string
    {
        $now = time();

        try {
            return JWT::encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ], $credentials['private_key'], 'RS256');
        } catch (\Throwable $e) {
            // Almost always a private key that lost its newlines on the way through an
            // env var. Worth saying out loud, because the symptom is otherwise "no pushes".
            Log::error('FCM assertion could not be signed — check FCM_CREDENTIALS', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The service-account JSON, however it was supplied.
     *
     * Raw JSON or base64-encoded JSON — the latter because more than one hosting dashboard
     * will happily eat the newlines in a PEM private key, and base64 survives that.
     *
     * @return array<string, mixed>|null
     */
    private function credentials(): ?array
    {
        $raw = config('services.fcm.credentials');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);

        // A hosting dashboard's env field is not a .env file: quotes you typed around the
        // value are kept as part of it, and the result is a "valid" variable holding JSON
        // that starts with an apostrophe. Silent, and indistinguishable from a bad key.
        if (strlen($raw) >= 2 && ($raw[0] === '"' || $raw[0] === "'") && $raw[-1] === $raw[0]) {
            $raw = substr($raw, 1, -1);
        }

        if (! str_starts_with($raw, '{')) {
            $decoded = base64_decode($raw, true);
            $raw = $decoded === false ? $raw : $decoded;
        }

        $parsed = json_decode($raw, true);

        if (! is_array($parsed) || ! isset($parsed['client_email'], $parsed['private_key'], $parsed['project_id'])) {
            Log::error('FCM_CREDENTIALS is not a usable service-account JSON.');

            return null;
        }

        return $parsed;
    }
}
