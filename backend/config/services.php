<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Socialite sign-in *and* the Drive/YouTube data key, in one array on purpose: PHP keeps
    // the last of two literals with the same key, so declaring `google` twice silently threw
    // the OAuth credentials away and broke "sign in with Google" with a null client_id.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        // Only ever buys *metadata* for a Drive video link (its name, length and thumbnail).
        // Playback needs no key at all: a file shared as "anyone with the link" plays from
        // Drive's own preview iframe either way. Defaults to the YouTube key, which is the
        // same kind of key — it works once the Drive API is enabled on that project.
        'key' => env('GOOGLE_API_KEY', env('YOUTUBE_API_KEY')),
    ],

    // YouTube Data API key — powers the music widget's playlist expansion and search
    // (`m!p <playlist>` / `m!p <spotify track>` / `m!p <words>`). Optional: without it,
    // plain YouTube *video* links still queue fine; anything needing a lookup replies
    // with a nudge to paste a video link. See App\Services\Widgets\YouTubeResolver.
    'youtube' => [
        'key' => env('YOUTUBE_API_KEY'),
    ],

    // GIF picker providers. The picker fans out to *every* provider with a key and merges the
    // results (see App\Services\GifService); set one or both. Keys are proxied server-side so
    // they never reach the browser. With none set, the picker shows a "not configured" note.
    //
    // (We moved off Tenor because Google shut down its public API on 2026-06-30 — new keys
    // stopped issuing that January. Giphy + Klipy are the successors platforms migrated to.)
    'giphy' => [
        'key' => env('GIPHY_API_KEY'),
    ],
    'klipy' => [
        'key' => env('KLIPY_API_KEY'),
    ],

    // Spotify no longer needs credentials here: the music widget reads tracks, playlists
    // and albums from their public embed pages (the Web API stopped serving playlist tracks
    // to ordinary apps in Nov 2024). These are kept only in case a future path wants the
    // official API with extended access. See App\Services\Widgets\SpotifyClient.
    'spotify' => [
        'client_id' => env('SPOTIFY_CLIENT_ID'),
        'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
        // OAuth redirect for *user* account linking (real Premium playback). Must match a
        // Redirect URI registered in the Spotify app dashboard. See App\Services\SpotifyOAuth.
        'redirect' => env('SPOTIFY_REDIRECT_URI', 'http://localhost:8000/api/spotify/callback'),
    ],

    /*
     * Firebase Cloud Messaging — the only way to reach a phone whose app is closed.
     *
     * One variable holds the whole service-account JSON (the "Generate new private key"
     * download from Firebase → Project settings → Service accounts), rather than a path to
     * a file: local dev and a hosted deploy then configure identically, and neither needs
     * a secret checked into the image. Base64 is accepted for dashboards that mangle
     * multi-line or quote-heavy values.
     *
     * The project id is read out of the credentials, since it's a field of that same JSON
     * and two sources for one fact is one source too many. Unset means push is simply off:
     * see App\Services\Notifications\FcmSender::configured().
     */
    'fcm' => [
        'credentials' => env('FCM_CREDENTIALS'),
    ],

];
