<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Selective Forwarding Units
    |--------------------------------------------------------------------------
    |
    | A call is peer-to-peer by default (see config/webrtc.php), which means the person
    | sharing their screen encodes and uploads it once *per other person in the room*. An
    | SFU takes one copy and fans it out, so the sharer's upload stops growing with the
    | audience — which is the whole reason this file exists.
    |
    | Nothing here is required. With no provider configured every call is a mesh call and
    | the app behaves exactly as it did before this file was added: the resolver simply has
    | nothing to offer and says 'mesh' (see VoiceTransportResolver).
    |
    | The list below is ordered, and it is a *failover* list rather than a load balancer —
    | the same shape, and for the same reason, as the TURN list in config/webrtc.php. The
    | first provider that can mint a credential wins; a provider that is down, out of quota,
    | or misconfigured is skipped and the next one is tried. If none can, the call falls
    | back to a mesh rather than failing, because a worse call beats no call.
    |
    | Each entry is self-contained: providers don't share credentials, and a second entry
    | is expected to be a *different* provider (or the same software, self-hosted, as an
    | escape hatch from a metered one) rather than a second key for the same account.
    |
    */

    'providers' => [

        'livekit' => [
            'driver' => 'livekit',
            // wss://<project>.livekit.cloud for LiveKit Cloud, or your own server's URL.
            // The browser connects here directly; the key and secret never leave the server.
            'url' => env('SFU_LIVEKIT_URL'),
            'key' => env('SFU_LIVEKIT_API_KEY'),
            'secret' => env('SFU_LIVEKIT_API_SECRET'),
        ],

        /*
         * Cloudflare Realtime. Priced per GB of egress with a large monthly allowance, and the
         * odd one out in shape: it has no client SDK and no join token, so the browser
         * negotiates through this server rather than connecting to Cloudflare itself. Create a
         * Realtime *SFU app* in the dashboard — that is a different thing from the TURN key in
         * config/webrtc.php, though both live on the same account and share one free tier.
         */
        'cloudflare' => [
            'driver' => 'cloudflare',
            'app_id' => env('SFU_CLOUDFLARE_APP_ID'),
            'app_secret' => env('SFU_CLOUDFLARE_APP_SECRET'),
        ],

        'livekit_backup' => [
            'driver' => 'livekit',
            'url' => env('SFU_LIVEKIT2_URL'),
            'key' => env('SFU_LIVEKIT2_API_KEY'),
            'secret' => env('SFU_LIVEKIT2_API_SECRET'),
        ],

    ],

    /*
    | The order providers are tried in. Names refer to keys above; unknown names and
    | unconfigured providers are skipped, so trimming this list is how you take a provider
    | out of rotation without deleting its credentials.
    */
    'order' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SFU_ORDER', 'cloudflare,livekit,livekit_backup'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | When to use one
    |--------------------------------------------------------------------------
    |
    | Below this many people a mesh is the better call, not merely an acceptable one: two
    | browsers talking directly have lower latency than two browsers talking through a
    | server in another city, and it costs nothing to run. The SFU earns its place when the
    | N-1 upload starts to hurt, which is a handful of people in — not at two.
    |
    | Counted as "people already in the call, plus you".
    |
    */
    'threshold' => (int) env('SFU_THRESHOLD', 4),

    /*
    | How long a minted join credential stays valid. It only has to survive the connect, not
    | the call: once a browser is in the room the session outlives the token that opened it.
    | Kept generous anyway, because the cost of it expiring is a join that fails for no
    | reason the user can act on.
    */
    'token_ttl' => (int) env('SFU_TOKEN_TTL', 3600),

];
