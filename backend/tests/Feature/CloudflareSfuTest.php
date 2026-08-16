<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;

/**
 * The Cloudflare relay.
 *
 * Two things are worth testing here and they pull in opposite directions. One is that we relay
 * faithfully — Cloudflare's schema passes through untouched, because a shape of our own in the
 * middle is a shape to keep in step with theirs. The other is that we relay *only for people
 * entitled to it*: these endpoints spend a metered third-party account, so the membership check
 * is the part that must not regress.
 */
function cloudflareConfigured(): void
{
    config([
        'sfu.providers' => [
            'cloudflare' => [
                'driver' => 'cloudflare',
                'app_id' => 'test-app',
                'app_secret' => 'test-secret',
            ],
        ],
        'sfu.order' => ['cloudflare'],
        'sfu.threshold' => 1,
    ]);
}

it('hands the browser a session id and keeps the app secret to itself', function () {
    cloudflareConfigured();

    Http::fake([
        'rtc.live.cloudflare.com/*' => Http::response(['sessionId' => 'sess-123']),
    ]);

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/voice/sfu/session", [
        'session_description' => ['type' => 'offer', 'sdp' => 'v=0'],
    ])->assertOk()->assertJson(['session_id' => 'sess-123']);

    Http::assertSent(function ($request) {
        // The secret authorises the call and never leaves this server; the app id scopes it.
        return $request->hasHeader('Authorization', 'Bearer test-secret')
            && str_contains($request->url(), '/apps/test-app/sessions/new');
    });
});

it('relays a publish with cloudflare\'s own track shape', function () {
    cloudflareConfigured();

    Http::fake([
        'rtc.live.cloudflare.com/*' => Http::response([
            'sessionDescription' => ['type' => 'answer', 'sdp' => 'v=0'],
            'tracks' => [['trackName' => 'screen', 'mid' => '0']],
        ]),
    ]);

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    $response = $this->postJson("/api/channels/{$channel->id}/voice/sfu/tracks", [
        'session_id' => 'sess-123',
        'tracks' => [['location' => 'local', 'mid' => '0', 'trackName' => 'screen']],
        'session_description' => ['type' => 'offer', 'sdp' => 'v=0'],
    ])->assertOk();

    expect($response->json('sessionDescription.type'))->toBe('answer');

    Http::assertSent(function ($request) {
        $body = $request->data();

        // Passed through, not re-modelled — `location` and `mid` are Cloudflare's vocabulary
        // and the client speaks it directly.
        return str_contains($request->url(), '/sessions/sess-123/tracks/new')
            && $body['tracks'][0]['location'] === 'local'
            && $body['tracks'][0]['mid'] === '0'
            && $body['sessionDescription']['type'] === 'offer';
    });
});

it('relays a pull, which names somebody else\'s session rather than a mid', function () {
    cloudflareConfigured();

    Http::fake([
        'rtc.live.cloudflare.com/*' => Http::response([
            'sessionDescription' => ['type' => 'offer', 'sdp' => 'v=0'],
            'requiresImmediateRenegotiation' => true,
        ]),
    ]);

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    $response = $this->postJson("/api/channels/{$channel->id}/voice/sfu/tracks", [
        'session_id' => 'mine',
        'tracks' => [['location' => 'remote', 'sessionId' => 'theirs', 'trackName' => 'screen']],
    ])->assertOk();

    // Pulling changes the shape of our connection, so Cloudflare offers and we must answer.
    expect($response->json('requiresImmediateRenegotiation'))->toBeTrue();
});

it('answers cloudflare\'s offer through the renegotiate endpoint', function () {
    cloudflareConfigured();

    Http::fake(['rtc.live.cloudflare.com/*' => Http::response(['ok' => true])]);

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    $this->putJson("/api/channels/{$channel->id}/voice/sfu/renegotiate", [
        'session_id' => 'mine',
        'session_description' => ['type' => 'answer', 'sdp' => 'v=0'],
    ])->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/sessions/mine/renegotiate'));
});

it('closes tracks rather than waiting for cloudflare to collect them', function () {
    cloudflareConfigured();

    Http::fake(['rtc.live.cloudflare.com/*' => Http::response(['tracks' => []])]);

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    $this->putJson("/api/channels/{$channel->id}/voice/sfu/tracks/close", [
        'session_id' => 'mine',
        'tracks' => [['mid' => '0']],
    ])->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/sessions/mine/tracks/close'));
});

it('refuses to relay for somebody who is not in this channel', function () {
    cloudflareConfigured();

    Http::fake();

    [, , $channel] = ownerWithVoiceChannel();

    // A stranger with a valid token. This is the check that matters: every endpoint here
    // spends a metered account, so entitlement is decided before anything is forwarded.
    Passport::actingAs(User::factory()->create());

    $this->postJson("/api/channels/{$channel->id}/voice/sfu/session", [
        'session_description' => ['type' => 'offer', 'sdp' => 'v=0'],
    ])->assertForbidden();

    Http::assertNothingSent();
});

it('refuses to relay for a guest', function () {
    cloudflareConfigured();
    Http::fake();

    [, , $channel] = ownerWithVoiceChannel();

    $this->postJson("/api/channels/{$channel->id}/voice/sfu/session", [
        'session_description' => ['type' => 'offer', 'sdp' => 'v=0'],
    ])->assertUnauthorized();

    Http::assertNothingSent();
});

it('treats an error in the body as a failure, not a success', function () {
    cloudflareConfigured();

    // Cloudflare reports application-level trouble with a 200 and an errorCode, so a status
    // check alone would hand the client a broken negotiation dressed up as a good one.
    Http::fake([
        'rtc.live.cloudflare.com/*' => Http::response([
            'errorCode' => 'InvalidSessionDescription',
            'errorDescription' => 'nope',
        ]),
    ]);

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/voice/sfu/tracks", [
        'session_id' => 'mine',
        'tracks' => [['location' => 'local', 'mid' => '0', 'trackName' => 'screen']],
        'session_description' => ['type' => 'offer', 'sdp' => 'v=0'],
    ])->assertStatus(502);
});

it('says so plainly when no relaying provider is configured', function () {
    config(['sfu.providers' => [], 'sfu.order' => []]);
    Http::fake();

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/voice/sfu/session", [
        'session_description' => ['type' => 'offer', 'sdp' => 'v=0'],
    ])->assertStatus(503);

    Http::assertNothingSent();
});

it('validates the track shape before spending a request on it', function () {
    cloudflareConfigured();
    Http::fake();

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/voice/sfu/tracks", [
        'session_id' => 'mine',
        'tracks' => [['location' => 'sideways', 'trackName' => 'screen']],
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('offers cloudflare as a provider to the join endpoint, with no token to leak', function () {
    cloudflareConfigured();
    Http::fake();

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    $response = $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    expect($response->json('sfu.driver'))->toBe('cloudflare')
        ->and($response->json('sfu.room'))->toBe('channel-'.$channel->id)
        // Nothing for the browser to hold: it negotiates through us instead.
        ->and($response->json('sfu.token'))->toBeNull()
        ->and($response->json('sfu.url'))->toBeNull();

    // Offering the provider costs no call to the *SFU* — a session is opened when a share
    // actually wants one. (Joining does mint a TURN credential, which is a different service
    // on the same host, so this checks the SFU path specifically rather than silence.)
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/apps/'));
});

it('refuses to open a session without an offer, which cloudflare would reject anyway', function () {
    cloudflareConfigured();
    Http::fake();

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    // Verified against the live API: a bodyless session comes back
    // `decoding_error: Body JSON validation error: sessionDescription`.
    $this->postJson("/api/channels/{$channel->id}/voice/sfu/session")->assertStatus(422);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/apps/'));
});

it('sends the SDP through byte for byte, trailing CRLF and all', function () {
    cloudflareConfigured();

    Http::fake([
        'rtc.live.cloudflare.com/*' => Http::response(['sessionId' => 'sess-123']),
    ]);

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    // Every line of an SDP is CRLF-terminated, including the last one. Laravel's global
    // TrimStrings middleware would otherwise eat that final terminator, and Cloudflare rejects
    // the result with `invalid_session_description: ... missing termination at the end` — an
    // error that points at the browser and the relay, neither of which did anything wrong.
    $sdp = "v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\n";

    $this->postJson("/api/channels/{$channel->id}/voice/sfu/session", [
        'session_description' => ['type' => 'offer', 'sdp' => $sdp],
    ])->assertOk();

    Http::assertSent(fn ($request) => $request->data()['sessionDescription']['sdp'] === $sdp);
});

it('sends a track offer through untrimmed too', function () {
    cloudflareConfigured();

    Http::fake([
        'rtc.live.cloudflare.com/*' => Http::response(['sessionDescription' => ['type' => 'answer', 'sdp' => 'v=0']]),
    ]);

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    // The publish path carries an SDP as well, and would fail the same way.
    $sdp = "v=0\r\nm=video 9 UDP/TLS/RTP/SAVPF 96\r\n";

    $this->postJson("/api/channels/{$channel->id}/voice/sfu/tracks", [
        'session_id' => 'sess-123',
        'tracks' => [['location' => 'local', 'mid' => '0', 'trackName' => 'screen']],
        'session_description' => ['type' => 'offer', 'sdp' => $sdp],
    ])->assertOk();

    Http::assertSent(fn ($request) => $request->data()['sessionDescription']['sdp'] === $sdp);
});
