<?php

namespace App\Http\Controllers;

use App\Services\SpotifyOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Linking a user's own Spotify account for real Premium playback in the music widget.
 *
 * `connect`/`status`/`token`/`disconnect` are called by the authenticated app; `callback`
 * is the public redirect target Spotify sends the browser back to — it identifies the user
 * from the encrypted `state`, not from a login session. See {@see SpotifyOAuth}.
 */
class SpotifyController extends Controller
{
    public function __construct(private readonly SpotifyOAuth $spotify) {}

    /** Hand the client the URL to open so the user can authorise the link. */
    public function connect(Request $request): JsonResponse
    {
        if (config('services.spotify.client_id') === null) {
            return response()->json(['message' => 'Spotify is not configured.'], 422);
        }

        // Reconnect forces the approval screen so a wedged/under-scoped grant is actually replaced.
        return response()->json(['url' => $this->spotify->authorizeUrl($request->user(), $request->boolean('reconnect'))]);
    }

    /** Where Spotify sends the browser back to. Public — the encrypted state is the identity. */
    public function callback(Request $request): RedirectResponse
    {
        $frontend = rtrim(config('app.frontend_url'), '/');

        $code = $request->query('code');
        $state = $request->query('state');
        $ok = is_string($code) && is_string($state) && $this->spotify->handleCallback($code, $state) !== null;

        // The client opens this in a popup; the landing page relays the result and closes.
        return redirect()->away($frontend.'/?spotifyLinked='.($ok ? '1' : '0'));
    }

    /** Whether the caller has a usable (Premium) Spotify link — drives the player's engine choice. */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'linked' => $user->spotify_refresh_token !== null,
            'premium' => $user->spotifyPremium(),
            'product' => $user->spotify_product,
        ]);
    }

    /** A fresh access token for the Web Playback SDK (refreshed as needed). */
    public function token(Request $request): JsonResponse
    {
        $token = $this->spotify->freshToken($request->user());
        if ($token === null) {
            return response()->json(['message' => 'Spotify not linked.'], 409);
        }

        return response()->json([
            'access_token' => $token,
            'product' => $request->user()->spotify_product,
        ]);
    }

    public function disconnect(Request $request): Response
    {
        $this->spotify->disconnect($request->user());

        return response()->noContent();
    }
}
