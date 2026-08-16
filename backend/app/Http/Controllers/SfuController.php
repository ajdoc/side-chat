<?php

namespace App\Http\Controllers;

use App\Http\Requests\Voice\VoiceChannelRequest;
use App\Models\Channel;
use App\Services\Sfu\CloudflareSfuProvider;
use App\Services\Sfu\SfuManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The SDP relay for providers whose API a browser cannot call directly.
 *
 * Only Cloudflare Realtime needs this. Its API is authenticated with an app secret, and an app
 * secret in a browser is an app secret published — so the browser negotiates with us instead:
 * it sends the offer it just made, we forward it to Cloudflare with the credentials, and hand
 * back the answer. LiveKit needs none of this, which is why nothing here is generic: a relay
 * that pretended to serve every provider would be an abstraction over one case.
 *
 * Every endpoint runs VoiceChannelRequest first. That is the load-bearing part of this file:
 * these forward to a metered third-party API on our account, so "is this person actually in
 * this call?" has to be answered before anything is relayed, not after.
 */
class SfuController extends Controller
{
    public function __construct(private readonly SfuManager $sfu) {}

    /**
     * Open a Cloudflare Session — one browser's peer connection to the SFU.
     *
     * The session id comes straight back to the client, which quotes it on everything after,
     * and hands it to its peers so they can pull what it publishes.
     */
    public function session(VoiceChannelRequest $request, Channel $channel): JsonResponse
    {
        $api = $this->provider()?->api();

        if (! $api) {
            return response()->json(['message' => 'No relaying SFU is configured.'], 503);
        }

        // Required, not optional: Cloudflare rejects a bodyless session with a decoding error,
        // and there would be nothing for the connection to be established with anyway.
        $validated = $request->validate([
            'session_description' => ['required', 'array'],
            'session_description.type' => ['required', 'string', 'in:offer'],
            'session_description.sdp' => ['required', 'string'],
        ]);

        $session = $api->createSession($validated['session_description']);

        // Cloudflare being unreachable is not an error worth failing loudly for: the client's
        // answer to it is to keep sharing peer-to-peer, which is where it started.
        if ($session === null) {
            return response()->json(['message' => 'Could not open a media session.'], 502);
        }

        return response()->json([
            'session_id' => $session['sessionId'],
            'session_description' => $session['sessionDescription'] ?? null,
        ]);
    }

    /**
     * Publish or subscribe. The client has already decided which; we only relay.
     *
     * `tracks` is Cloudflare's own shape — `location`, `mid`, `trackName`, and for a remote pull
     * the publisher's `sessionId`. Passed through rather than re-modelled: this is a relay, and
     * a schema of our own in the middle would be one more thing to keep in step with theirs.
     */
    public function tracks(VoiceChannelRequest $request, Channel $channel): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string', 'max:255'],
            'tracks' => ['required', 'array', 'min:1', 'max:16'],
            'tracks.*.location' => ['required', 'string', 'in:local,remote'],
            'tracks.*.mid' => ['nullable', 'string', 'max:64'],
            'tracks.*.trackName' => ['required', 'string', 'max:255'],
            'tracks.*.sessionId' => ['nullable', 'string', 'max:255'],
            'session_description' => ['nullable', 'array'],
            'session_description.type' => ['required_with:session_description', 'string', 'in:offer,answer'],
            'session_description.sdp' => ['required_with:session_description', 'string'],
        ]);

        return $this->relay(fn ($api) => $api->addTracks(
            $validated['session_id'],
            $validated['tracks'],
            $validated['session_description'] ?? null,
        ));
    }

    /** Answer the offer Cloudflare makes when pulling a track changes our connection's shape. */
    public function renegotiate(VoiceChannelRequest $request, Channel $channel): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string', 'max:255'],
            'session_description' => ['required', 'array'],
            'session_description.type' => ['required', 'string', 'in:offer,answer'],
            'session_description.sdp' => ['required', 'string'],
        ]);

        return $this->relay(fn ($api) => $api->renegotiate(
            $validated['session_id'],
            $validated['session_description'],
        ));
    }

    /**
     * Stop tracks, promptly.
     *
     * Cloudflare collects an idle track after 30 seconds anyway, but until then it is a track it
     * still believes in — and anything still flowing is egress somebody is paying for.
     */
    public function close(VoiceChannelRequest $request, Channel $channel): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string', 'max:255'],
            'tracks' => ['required', 'array', 'min:1', 'max:16'],
            'tracks.*.mid' => ['nullable', 'string', 'max:64'],
            'tracks.*.trackName' => ['nullable', 'string', 'max:255'],
            'session_description' => ['nullable', 'array'],
            'session_description.type' => ['required_with:session_description', 'string', 'in:offer,answer'],
            'session_description.sdp' => ['required_with:session_description', 'string'],
        ]);

        return $this->relay(fn ($api) => $api->closeTracks(
            $validated['session_id'],
            $validated['tracks'],
            $validated['session_description'] ?? null,
        ));
    }

    /**
     * Run one relayed call, with the same failure shape for all of them.
     *
     * @param  callable(\App\Services\Sfu\CloudflareRealtime): (array<string, mixed>|null)  $call
     */
    private function relay(callable $call): JsonResponse
    {
        $api = $this->provider()?->api();

        if (! $api) {
            return response()->json(['message' => 'No relaying SFU is configured.'], 503);
        }

        $result = $call($api);

        if ($result === null) {
            return response()->json(['message' => 'The media server refused that.'], 502);
        }

        return response()->json($result);
    }

    private function provider(): ?CloudflareSfuProvider
    {
        $provider = $this->sfu->driver('cloudflare');

        return $provider instanceof CloudflareSfuProvider ? $provider : null;
    }
}
