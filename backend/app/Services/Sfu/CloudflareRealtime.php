<?php

namespace App\Services\Sfu;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Cloudflare Realtime SFU's HTTPS API, and the reason it has to live on the server.
 *
 * Cloudflare's SFU is deliberately unopinionated: there is no room, no participant list, no
 * client SDK. What there is, is a *Session* — which is exactly one RTCPeerConnection — and
 * *Tracks* within it, each either `local` (something you are sending up) or `remote` (something
 * somebody else sent up, which you want pulled down). Everything above that, including who is in
 * a call and which track belongs to whom, is the application's problem. Ours is solved by the
 * mesh, which already knows the roster, and by the state whispers that already tell everyone
 * what everyone else is doing.
 *
 * This class exists because the API is authenticated with an app secret. A browser cannot hold
 * one, so the browser negotiates with *us* — handing over an SDP offer and getting an answer
 * back — and we relay to Cloudflare. That is the architecture Cloudflare itself recommends, and
 * it is why the Cloudflare driver's SfuCredentials carry no token: there is nothing the browser
 * could usefully be given.
 *
 * Every method returns null rather than throwing, for the same reason CloudflareTurn does: a
 * provider having a bad day must degrade a screen share to peer-to-peer, never fail a call.
 */
final class CloudflareRealtime
{
    private const BASE = 'https://rtc.live.cloudflare.com/v1/apps';

    /** Cloudflare sits on the path of starting a share; a share must not wait on it forever. */
    private const TIMEOUT = 10;

    public function __construct(
        private readonly string $appId,
        private readonly string $appSecret,
    ) {}

    public function isConfigured(): bool
    {
        return $this->appId !== '' && $this->appSecret !== '';
    }

    /**
     * Open a Session — one browser's single peer connection to Cloudflare.
     *
     * Takes the client's opening offer, and it is *required* — verified against the live API,
     * which answers a bodyless call with `decoding_error: Body JSON validation error:
     * sessionDescription`. That matches how the rest of it behaves: track operations need the
     * peer connection actually up (Cloudflare waits ~5s then gives up), and a session created
     * with no description has nothing to connect *with*, so the first pull on a fresh session
     * would race the DTLS handshake and lose.
     *
     * @param  array<string, mixed>  $sessionDescription
     * @return array<string, mixed>|null  The session id, and Cloudflare's answer to the offer.
     */
    public function createSession(array $sessionDescription): ?array
    {
        $response = $this->post('/sessions/new', ['sessionDescription' => $sessionDescription]);

        return is_string($response['sessionId'] ?? null) ? $response : null;
    }

    /**
     * Publish or subscribe, which are the same call with a different `location`.
     *
     * `local` sends a track up: the browser has already put it on a transceiver and offers the
     * SDP, and each entry names the `mid` that carries it. `remote` pulls somebody else's down,
     * naming their session and track rather than a mid — the answer then describes a transceiver
     * we didn't create, which is why the browser has to renegotiate around it.
     *
     * @param  array<int, array<string, mixed>>  $tracks
     * @param  array<string, mixed>|null  $sessionDescription  Our offer. Absent when pulling, where
     *                                                         Cloudflare offers instead.
     * @return array<string, mixed>|null
     */
    public function addTracks(string $sessionId, array $tracks, ?array $sessionDescription = null): ?array
    {
        $body = ['tracks' => $tracks];

        if ($sessionDescription !== null) {
            $body['sessionDescription'] = $sessionDescription;
        }

        return $this->post("/sessions/{$sessionId}/tracks/new", $body);
    }

    /**
     * Answer an offer Cloudflare made us.
     *
     * Pulling a track can change the shape of our connection — Cloudflare adds transceivers for
     * the media it is about to send — so it answers with an offer of its own, and this is where
     * our answer to *that* goes. Skipping it leaves the tracks negotiated but silent.
     *
     * @param  array<string, mixed>  $sessionDescription
     * @return array<string, mixed>|null
     */
    public function renegotiate(string $sessionId, array $sessionDescription): ?array
    {
        return $this->put("/sessions/{$sessionId}/renegotiate", [
            'sessionDescription' => $sessionDescription,
        ]);
    }

    /**
     * Stop sending or receiving some tracks.
     *
     * Worth doing promptly rather than leaving to the timeout: an abandoned track is garbage
     * collected after 30 seconds of silence, and until then it is still a track Cloudflare
     * believes in — and, for anything still flowing, still egress somebody is paying for.
     *
     * @param  array<int, array<string, mixed>>  $tracks
     * @param  array<string, mixed>|null  $sessionDescription
     * @return array<string, mixed>|null
     */
    public function closeTracks(string $sessionId, array $tracks, ?array $sessionDescription = null): ?array
    {
        $body = ['tracks' => $tracks, 'force' => false];

        if ($sessionDescription !== null) {
            $body['sessionDescription'] = $sessionDescription;
        }

        return $this->put("/sessions/{$sessionId}/tracks/close", $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function post(string $path, array $body = []): ?array
    {
        return $this->send('post', $path, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function put(string $path, array $body): ?array
    {
        return $this->send('put', $path, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function send(string $method, string $path, array $body): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withToken($this->appSecret)
                ->timeout(self::TIMEOUT)
                ->{$method}(self::BASE.'/'.$this->appId.$path, $body);

            if ($response->failed()) {
                // The body, not just the status. Cloudflare says *why* in it — a rejected SDP
                // and a malformed body are both 400, and they are not the same problem. A log
                // line that omits the reason is a log line that costs someone a debugging
                // session, which is exactly what happened before this was added.
                Log::warning('Cloudflare Realtime request failed', [
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            $json = $response->json();

            // Cloudflare reports application-level trouble in the body with a 200, so a status
            // check alone would let a failed negotiation through as a success.
            if (isset($json['errorCode'])) {
                Log::warning('Cloudflare Realtime returned an error', [
                    'path' => $path,
                    'error' => $json['errorCode'],
                    'description' => $json['errorDescription'] ?? null,
                ]);

                return null;
            }

            return is_array($json) ? $json : null;
        } catch (Throwable $e) {
            Log::warning('Cloudflare Realtime request threw', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
