<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * The user-facing Spotify OAuth (Authorization Code) flow — linking a person's own Spotify
 * account so the music widget can play the real track through the Web Playback SDK.
 *
 * This is separate from {@see \App\Services\Widgets\SpotifyClient}, which reads *public*
 * catalog metadata with no user. Here we act on behalf of one user, which needs their
 * consent and a Premium subscription (only Premium accounts can stream through the SDK).
 *
 * The app authenticates with Passport tokens, not a session, so the OAuth round-trip can't
 * lean on session state to remember who started it. Instead we carry the user through in an
 * encrypted, short-lived `state` parameter — tamper-proof (app key) and self-identifying,
 * so the callback knows exactly whose tokens it just received.
 */
class SpotifyOAuth
{
    private const AUTHORIZE = 'https://accounts.spotify.com/authorize';

    private const TOKEN = 'https://accounts.spotify.com/api/token';

    private const ME = 'https://api.spotify.com/v1/me';

    /** Everything the Web Playback SDK + playback control needs; nothing more. */
    private const SCOPES = 'streaming user-read-email user-read-private user-read-playback-state user-modify-playback-state';

    /**
     * The URL to send a user to so they can authorise the link.
     *
     * `forceConsent` shows Spotify's approval screen even for a returning user. Reconnect uses
     * it: with `show_dialog=false` an already-approved user can be bounced straight back with
     * the *same* grant, so a token that's missing a scope (or wedged) never actually gets
     * replaced — which looks exactly like "reconnect does nothing". Forcing the dialog mints a
     * fresh token carrying today's full scope set.
     */
    public function authorizeUrl(User $user, bool $forceConsent = false): string
    {
        return self::AUTHORIZE.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'redirect_uri' => $this->redirect(),
            'scope' => self::SCOPES,
            // Encrypted so the callback can trust which user it belongs to, with a timestamp
            // so a stale/leaked link can't be replayed days later.
            'state' => Crypt::encryptString($user->id.'|'.now()->timestamp),
            'show_dialog' => $forceConsent ? 'true' : 'false',
        ]);
    }

    /**
     * Handle the redirect back from Spotify: verify state, swap the code for tokens, record
     * the account (and whether it's Premium). Returns the linked user, or null on failure.
     */
    public function handleCallback(string $code, string $state): ?User
    {
        $user = $this->userFromState($state);
        if ($user === null) {
            return null;
        }

        try {
            $res = Http::asForm()->timeout(8)->post(self::TOKEN, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirect(),
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $res->successful()) {
            return null;
        }

        $this->store($user, $res->json());
        $this->syncProfile($user);

        return $user;
    }

    /**
     * A currently-valid access token for the SDK, refreshed if it's expired (or about to be).
     * Null when the user hasn't linked, or the refresh was rejected (they revoked access).
     */
    public function freshToken(User $user): ?string
    {
        if ($user->spotify_refresh_token === null) {
            return null;
        }

        if ($user->spotify_access_token !== null
            && $user->spotify_token_expires_at !== null
            && $user->spotify_token_expires_at->isAfter(now()->addSeconds(30))) {
            return $user->spotify_access_token;
        }

        return $this->refresh($user);
    }

    public function disconnect(User $user): void
    {
        $user->forceFill([
            'spotify_id' => null,
            'spotify_access_token' => null,
            'spotify_refresh_token' => null,
            'spotify_token_expires_at' => null,
            'spotify_product' => null,
        ])->save();
    }

    private function refresh(User $user): ?string
    {
        try {
            $res = Http::asForm()->timeout(8)->post(self::TOKEN, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $user->spotify_refresh_token,
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $res->successful()) {
            // Refresh rejected — the user revoked access. Clear the dead link.
            $this->disconnect($user);

            return null;
        }

        $this->store($user, $res->json());

        // Re-read the profile so `spotify_product` self-heals: it's set once at link time, and
        // if that first /me call failed (a prod timeout, a transient error) the user is left
        // wrongly flagged non-Premium forever. A refresh happens ~hourly, so this converges.
        if ($user->spotify_product !== 'premium') {
            $this->syncProfile($user);
        }

        return $user->spotify_access_token;
    }

    /** @param  array<string, mixed>  $token */
    private function store(User $user, array $token): void
    {
        $user->forceFill([
            'spotify_access_token' => $token['access_token'] ?? $user->spotify_access_token,
            // A refresh response often omits the refresh token — keep the one we have.
            'spotify_refresh_token' => $token['refresh_token'] ?? $user->spotify_refresh_token,
            'spotify_token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
        ])->save();
    }

    private function syncProfile(User $user): void
    {
        try {
            $res = Http::withToken($user->spotify_access_token)->timeout(8)->get(self::ME);
        } catch (\Throwable) {
            return;
        }
        if (! $res->successful()) {
            return;
        }

        $user->forceFill([
            'spotify_id' => $res->json('id'),
            'spotify_product' => $res->json('product'), // 'premium' | 'free' | 'open'
        ])->save();
    }

    private function userFromState(string $state): ?User
    {
        try {
            [$id, $ts] = explode('|', Crypt::decryptString($state)) + [null, null];
        } catch (DecryptException) {
            return null;
        }

        // A link older than 10 minutes has gone stale.
        if ($ts === null || (int) $ts < now()->subMinutes(10)->timestamp) {
            return null;
        }

        return User::find((int) $id);
    }

    private function clientId(): string
    {
        return (string) config('services.spotify.client_id');
    }

    private function clientSecret(): string
    {
        return (string) config('services.spotify.client_secret');
    }

    private function redirect(): string
    {
        return (string) config('services.spotify.redirect');
    }
}
