<?php

namespace App\Support\Moba;

use Illuminate\Support\Facades\Config;

/**
 * The signed pass that gets a player into a match.
 *
 * ## Why a ticket rather than a callback
 *
 * The game server is a separate process, possibly on a separate machine, and it must decide in
 * microseconds whether an arriving socket is allowed to sit down. Asking PHP would put an HTTP
 * round trip on the connection path and make the API a hard dependency of every match already
 * in progress — an API deploy would drop reconnects.
 *
 * So PHP mints a short-lived HMAC over the facts the server needs, and the server verifies it
 * offline against a shared secret. Neither side calls the other to seat a player.
 *
 * ## What is in it, and what is not
 *
 * Only what the server cannot otherwise know and cannot be allowed to take on trust from the
 * client: which match, which seat, which team, which hero, and when it stops being valid. No
 * display name and no rating — those are the API's business, and putting them here would mean
 * a stale ticket could render a stale name.
 *
 * The payload is signed, **not encrypted**. A player can read their own ticket and learn their
 * own seat, which they were about to be told anyway. What they cannot do is change it.
 */
final class MatchTicket
{
    /**
     * How long a freshly minted ticket is good for.
     *
     * Long enough to cover a slow page load and a wasm bundle download, short enough that one
     * captured out of a browser's network tab is useless by the time anyone finds it. Reconnects
     * are handled by *issuing a new ticket*, not by making this generous.
     */
    public const TTL_SECONDS = 120;

    /**
     * The shared secret.
     *
     * Deliberately read at call time rather than cached: a rotated secret should take effect on
     * the next request, not on the next deploy. Falls back to the app key so a development
     * stack works with no extra configuration — a *fallback*, never a production posture, which
     * is why {@see isConfigured} exists to say so out loud.
     */
    private static function secret(): string
    {
        return (string) (Config::get('services.moba.secret') ?: Config::get('app.key'));
    }

    public static function isConfigured(): bool
    {
        return (bool) Config::get('services.moba.secret');
    }

    /**
     * Mint a ticket.
     *
     * @return string `base64url(payload).base64url(signature)` — one opaque field to the client,
     *                which passes it through to the game server untouched.
     */
    public static function mint(int $matchId, int $userId, int $team, int $slot, string $hero): string
    {
        $payload = [
            'm' => $matchId,
            'u' => $userId,
            't' => $team,
            's' => $slot,
            'h' => $hero,
            // `now()` rather than `time()`: the rest of the app dates everything through
            // Carbon, and the builtin is unaffected by test time-travel — which would leave
            // the expiry rule, the one part of this class with teeth, untestable.
            'exp' => now()->timestamp + self::TTL_SECONDS,
        ];

        $encoded = self::encode(json_encode($payload, JSON_THROW_ON_ERROR));

        return $encoded.'.'.self::encode(hash_hmac('sha256', $encoded, self::secret(), true));
    }

    /**
     * Verify and decode a ticket.
     *
     * Exists on the PHP side as well as the Rust side because the API's own result endpoint
     * needs to check one, and because a verifier only the other language can run is a verifier
     * that cannot be tested here.
     *
     * @return array<string, mixed>|null null for anything malformed, mis-signed or expired,
     *                                   with no distinction between them: telling a caller
     *                                   *which* is telling an attacker whether they got the
     *                                   payload right and only missed the signature.
     */
    public static function verify(string $ticket): ?array
    {
        $parts = explode('.', $ticket);
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;
        $expected = self::encode(hash_hmac('sha256', $encoded, self::secret(), true));

        // Constant-time, so the comparison cannot be turned into an oracle that leaks the
        // signature one byte at a time.
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode(self::decode($encoded), true);
        if (! is_array($payload) || ! isset($payload['exp'], $payload['m'], $payload['u'])) {
            return null;
        }

        if ((int) $payload['exp'] < now()->timestamp) {
            return null;
        }

        return $payload;
    }

    /** URL-safe base64 with the padding stripped, so a ticket survives a query string. */
    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
