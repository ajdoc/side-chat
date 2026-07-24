<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ICE servers
    |--------------------------------------------------------------------------
    |
    | Handed to the browser's RTCPeerConnection. STUN alone is enough whenever the
    | two peers can eventually see each other (most home networks, and always on
    | localhost). TURN relays the media through a server and is what rescues the
    | ~15% of connections behind symmetric NAT or a strict corporate firewall —
    | without it those users join the call and simply never hear anyone.
    |
    | There is no free TURN: point these at your own coturn, or a hosted provider.
    |
    | More than one TURN entry is a redundancy play, not a load-balancer: a browser is
    | handed every one and ICE fails over to whichever answers, so the ~15% who need a
    | relay still connect when a server (or a whole provider) is down. Because they're
    | separate providers each carries its own credentials — coturn's HMAC secret and a
    | hosted provider's key don't cross-authenticate — which is exactly why each entry
    | below is self-contained rather than sharing one username/credential.
    |
    */

    'stun_urls' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WEBRTC_STUN_URLS', 'stun:stun.l.google.com:19302,stun:stun1.l.google.com:19302'))
    ))),

    /*
     * A list of TURN servers, each with its own credentials. Entries with no URLs are
     * dropped (see VoiceService::iceServers), so a deployment with only the first set
     * configured behaves exactly as a single-TURN one did — add WEBRTC_TURN2_* to light
     * up the second provider, and nothing else has to change.
     */
    'turn' => array_values(array_filter([
        [
            'urls' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('WEBRTC_TURN_URLS', ''))
            ))),
            'username' => env('WEBRTC_TURN_USERNAME'),
            'credential' => env('WEBRTC_TURN_CREDENTIAL'),
        ],
        [
            'urls' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('WEBRTC_TURN2_URLS', ''))
            ))),
            'username' => env('WEBRTC_TURN2_USERNAME'),
            'credential' => env('WEBRTC_TURN2_CREDENTIAL'),
        ],
    ], static fn (array $turn): bool => $turn['urls'] !== [])),

    /*
    |--------------------------------------------------------------------------
    | Mesh size
    |--------------------------------------------------------------------------
    |
    | Calls are peer-to-peer: every participant sends their own audio (and screen)
    | separately to every other one, so upload cost grows with N-1 and CPU with N.
    | That is fine for a handful of people and falls apart for a room. The cap is
    | enforced on join so a call degrades by refusing the 9th person rather than by
    | slowly turning to mud for the other eight.
    |
    | Going meaningfully past this means putting an SFU (LiveKit, mediasoup) in the
    | middle, at which point the browser sends one stream instead of N-1.
    |
    */

    'max_participants' => (int) env('WEBRTC_MAX_PARTICIPANTS', 8),

    /*
    |--------------------------------------------------------------------------
    | Stale participants
    |--------------------------------------------------------------------------
    |
    | Clients in a call heartbeat every ~25s. A row that hasn't been touched in this
    | long belongs to a browser that was closed, crashed, or lost the network without
    | getting a chance to leave, and is filtered out of every roster we serve.
    |
    */

    'stale_after_seconds' => (int) env('WEBRTC_STALE_AFTER_SECONDS', 75),

];
