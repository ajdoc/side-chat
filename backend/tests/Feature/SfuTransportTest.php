<?php

use App\Models\Channel;
use App\Models\User;
use App\Models\VoiceParticipant;
use App\Services\Sfu\SfuCredentials;
use App\Services\Sfu\SfuProvider;
use Laravel\Passport\Passport;

/**
 * A provider that is configured and then falls over — the shape of a real one being out of
 * quota or unreachable. Registered by class name in config, which is the same extension point
 * a genuine third provider would use.
 */
final class FailingProvider implements SfuProvider
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly string $name, private readonly array $config) {}

    public function driver(): string
    {
        return 'failing';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function credentialsFor(Channel $channel, User $user): ?SfuCredentials
    {
        throw new RuntimeException('provider is out of quota');
    }
}

/**
 * Point config at a working LiveKit provider, and nothing else.
 *
 * `order` is set alongside `providers` every time: the shipped order names two entries, and a
 * test that only replaced the providers would still be exercising whatever the second name
 * resolved to.
 *
 * @param  array<string, array<string, mixed>>  $providers
 */
function sfuConfig(array $providers, int $threshold = 1): void
{
    config([
        'sfu.providers' => $providers,
        'sfu.order' => array_keys($providers),
        'sfu.threshold' => $threshold,
    ]);
}

/** A configured LiveKit entry. */
function liveKitProvider(string $url = 'wss://test.livekit.cloud', string $secret = 'test-secret'): array
{
    return ['driver' => 'livekit', 'url' => $url, 'key' => 'test-key', 'secret' => $secret];
}

/** Decode a JWT's claims without verifying — the test asserts on shape, not trust. */
function jwtClaims(string $token): array
{
    $payload = explode('.', $token)[1] ?? '';

    return json_decode(base64_decode(strtr($payload, '-_', '+/')), true) ?: [];
}

/** Put extra people in the call, so occupancy crosses a threshold. */
function fillCall(Channel $channel, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        VoiceParticipant::factory()->create([
            'channel_id' => $channel->id,
            'user_id' => User::factory()->create()->id,
        ]);
    }
}

it('hands the browser an sfu credential once a call is big enough', function () {
    sfuConfig(['livekit' => liveKitProvider()], threshold: 3);

    [$user, , $channel] = ownerWithVoiceChannel();
    fillCall($channel, 2); // two already in; the joiner makes three

    Passport::actingAs($user);

    $response = $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    expect($response->json('transport'))->toBe('sfu')
        ->and($response->json('sfu.driver'))->toBe('livekit')
        ->and($response->json('sfu.url'))->toBe('wss://test.livekit.cloud')
        ->and($response->json('sfu.room'))->toBe('channel-'.$channel->id);

    $claims = jwtClaims($response->json('sfu.token'));

    expect($claims['iss'])->toBe('test-key')
        ->and($claims['sub'])->toBe((string) $user->id)
        ->and($claims['video']['room'])->toBe('channel-'.$channel->id)
        ->and($claims['video']['roomJoin'])->toBeTrue()
        // Remote control rides a data channel; without this grant the call works and remote
        // control silently doesn't.
        ->and($claims['video']['canPublishData'])->toBeTrue();
});

it('signs the token with the configured secret, so a forged one would not verify', function () {
    sfuConfig(['livekit' => liveKitProvider(secret: 'the-real-secret')]);

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    $token = $this->postJson("/api/channels/{$channel->id}/voice/join")->json('sfu.token');

    [$header, $payload, $signature] = explode('.', $token);

    $expected = rtrim(strtr(base64_encode(
        hash_hmac('sha256', $header.'.'.$payload, 'the-real-secret', true)
    ), '+/', '-_'), '=');

    expect($signature)->toBe($expected);

    // And the same input under a different key does not produce it — otherwise the assertion
    // above would pass for any secret at all.
    $wrong = rtrim(strtr(base64_encode(
        hash_hmac('sha256', $header.'.'.$payload, 'not-the-secret', true)
    ), '+/', '-_'), '=');

    expect($signature)->not->toBe($wrong);
});

it('starts a small call sharing peer-to-peer, but still hands over the keys', function () {
    sfuConfig(['livekit' => liveKitProvider()], threshold: 4);

    [$user, , $channel] = ownerWithVoiceChannel();

    Passport::actingAs($user);

    $response = $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    // Below the threshold a direct share is the better one, so that is where it starts.
    expect($response->json('transport'))->toBe('mesh')
        // But the credentials come anyway: the sharer may find their upload gives out even in
        // a call of two, and switching should not need another round-trip.
        ->and($response->json('sfu.token'))->not->toBeEmpty()
        // The mesh is always running underneath — it is carrying the voices regardless.
        ->and($response->json('ice_servers'))->not->toBeEmpty();
});

it('falls through to the next provider when the first is not configured', function () {
    sfuConfig([
        'broken' => ['driver' => 'livekit', 'url' => '', 'key' => '', 'secret' => ''],
        'backup' => liveKitProvider(url: 'wss://backup.livekit.cloud'),
    ]);

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    $response = $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    expect($response->json('transport'))->toBe('sfu')
        ->and($response->json('sfu.provider'))->toBe('backup')
        ->and($response->json('sfu.url'))->toBe('wss://backup.livekit.cloud');
});

it('skips a provider whose driver nobody implements', function () {
    sfuConfig([
        'exotic' => ['driver' => 'not-a-real-sfu', 'url' => 'wss://x', 'key' => 'k', 'secret' => 's'],
        'livekit' => liveKitProvider(),
    ]);

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    expect($this->postJson("/api/channels/{$channel->id}/voice/join")->json('sfu.provider'))
        ->toBe('livekit');
});

it('keeps screen sharing peer-to-peer when every provider is down, rather than failing the join', function () {
    // Configured, so the resolver gets as far as asking — and then throws, which is the
    // quota-exhausted / API-down case. The join must survive it: a worse call beats no call.
    sfuConfig(['flaky' => ['driver' => FailingProvider::class, 'url' => 'wss://x']]);

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    $response = $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    expect($response->json('transport'))->toBe('mesh')
        ->and($response->json('ice_servers'))->not->toBeEmpty();
});

it('never reaches for an sfu when none is configured', function () {
    config(['sfu.providers' => [], 'sfu.order' => []]);

    [$user, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($user);

    expect($this->postJson("/api/channels/{$channel->id}/voice/join")->json('transport'))
        ->toBe('mesh');
});

it('lets a server owner turn the sfu off for the whole place', function () {
    sfuConfig(['livekit' => liveKitProvider()]);

    [$user, $server, $channel] = ownerWithVoiceChannel();

    Passport::actingAs($user);

    $this->patchJson("/api/servers/{$server->id}", [
        'name' => $server->name,
        'sfu_enabled' => false,
    ])->assertOk()->assertJsonPath('data.sfu_enabled', false);

    expect($this->postJson("/api/channels/{$channel->id}/voice/join")->json('transport'))
        ->toBe('mesh');
});

it('leaves the sfu setting alone when an update does not mention it', function () {
    [$user, $server] = ownerWithVoiceChannel();

    $server->update(['sfu_enabled' => false]);

    Passport::actingAs($user);

    // A rename is a rename: it must not quietly switch the SFU back on.
    $this->patchJson("/api/servers/{$server->id}", ['name' => 'Renamed'])->assertOk();

    expect($server->fresh()->sfu_enabled)->toBeFalse();
});

it('lets one channel opt out while the rest of the server keeps the sfu', function () {
    sfuConfig(['livekit' => liveKitProvider()]);

    [$user, $server, $channel] = ownerWithVoiceChannel();
    $other = Channel::factory()->create(['server_id' => $server->id, 'type' => 'voice']);

    $channel->update(['sfu_enabled' => false]);

    Passport::actingAs($user);

    expect($this->postJson("/api/channels/{$channel->id}/voice/join")->json('transport'))->toBe('mesh')
        ->and($this->postJson("/api/channels/{$other->id}/voice/join")->json('transport'))->toBe('sfu');
});

it('lets a channel opt in even though its server has said no', function () {
    sfuConfig(['livekit' => liveKitProvider()]);

    [$user, $server, $channel] = ownerWithVoiceChannel();

    $server->update(['sfu_enabled' => false]);
    // Null would inherit the server's "no"; an explicit true is the room's own decision.
    $channel->update(['sfu_enabled' => true]);

    Passport::actingAs($user);

    expect($this->postJson("/api/channels/{$channel->id}/voice/join")->json('transport'))
        ->toBe('sfu');
});
