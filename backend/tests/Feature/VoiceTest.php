<?php

use App\Events\VoiceStateUpdated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\VoiceParticipant;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;

it('joins a voice channel and returns the roster and ice servers', function () {
    [$user, , $channel] = ownerWithVoiceChannel();

    Passport::actingAs($user);

    $response = $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.user.id'))->toBe($user->id)
        ->and($response->json('data.0.muted'))->toBeFalse()
        ->and($response->json('ice_servers'))->not->toBeEmpty()
        ->and($response->json('max_participants'))->toBe(config('webrtc.max_participants'));

    expect(VoiceParticipant::where('channel_id', $channel->id)->count())->toBe(1);
});

it('is idempotent — rejoining refreshes the seat rather than duplicating it', function () {
    [$user, , $channel] = ownerWithVoiceChannel();

    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();
    $response = $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect(VoiceParticipant::count())->toBe(1);
});

it('resets stale mute/screen state when you rejoin', function () {
    [$user, , $channel] = ownerWithVoiceChannel();

    VoiceParticipant::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'muted' => true,
        'screen_sharing' => true,
    ]);

    Passport::actingAs($user);

    $response = $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    expect($response->json('data.0.muted'))->toBeFalse()
        ->and($response->json('data.0.screen_sharing'))->toBeFalse();
});

it('moves you out of the voice channel you were already in', function () {
    [$user, $server, $first] = ownerWithVoiceChannel();
    $second = Channel::factory()->create(['server_id' => $server->id, 'type' => 'voice']);

    Passport::actingAs($user);

    $this->postJson("/api/channels/{$first->id}/voice/join")->assertOk();
    $this->postJson("/api/channels/{$second->id}/voice/join")->assertOk();

    // One seat, in the new room: you cannot be talking in two places at once.
    expect(VoiceParticipant::count())->toBe(1)
        ->and(VoiceParticipant::first()->channel_id)->toBe($second->id);
});

it('holds more than two people at once', function () {
    [$owner, $server, $channel] = ownerWithVoiceChannel();
    $others = User::factory()->count(3)->create();
    $server->members()->attach($others->pluck('id'), ['role' => 'member']);

    foreach ($others->push($owner) as $user) {
        Passport::actingAs($user);
        $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();
    }

    $response = $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    expect($response->json('data'))->toHaveCount(4);
});

it('refuses the person who would overflow the mesh', function () {
    [$owner, $server, $channel] = ownerWithVoiceChannel();
    config()->set('webrtc.max_participants', 2);

    $second = User::factory()->create();
    $third = User::factory()->create();
    $server->members()->attach([$second->id, $third->id], ['role' => 'member']);

    Passport::actingAs($owner);
    $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    Passport::actingAs($second);
    $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    Passport::actingAs($third);
    $this->postJson("/api/channels/{$channel->id}/voice/join")
        ->assertJsonValidationErrors('channel');

    expect(VoiceParticipant::count())->toBe(2);
});

it('lets someone already inside a full channel reconnect', function () {
    [$owner, , $channel] = ownerWithVoiceChannel();
    config()->set('webrtc.max_participants', 1);

    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();
    // The room is "full" — of you. Dropping your websocket must not cost you your seat.
    $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();
});

it('leaves a voice channel', function () {
    [$user, , $channel] = ownerWithVoiceChannel();

    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();
    $this->postJson("/api/channels/{$channel->id}/voice/leave")->assertNoContent();

    expect(VoiceParticipant::count())->toBe(0);
});

it('updates mute and screen-sharing state, leaving unmentioned fields alone', function () {
    [$user, , $channel] = ownerWithVoiceChannel();

    Passport::actingAs($user);
    $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    $this->patchJson("/api/channels/{$channel->id}/voice/state", ['muted' => true])
        ->assertOk()
        ->assertJsonPath('data.muted', true);

    // Sharing your screen says nothing about your mic — it must stay muted.
    $this->patchJson("/api/channels/{$channel->id}/voice/state", ['screen_sharing' => true])
        ->assertOk()
        ->assertJsonPath('data.screen_sharing', true)
        ->assertJsonPath('data.muted', true);
});

it('broadcasts only when state actually changes', function () {
    [$user, , $channel] = ownerWithVoiceChannel();

    Passport::actingAs($user);
    $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    Event::fake([VoiceStateUpdated::class]);

    $this->patchJson("/api/channels/{$channel->id}/voice/state", ['muted' => true])->assertOk();
    Event::assertDispatchedTimes(VoiceStateUpdated::class, 1);

    // Muting an already-muted mic is not news.
    $this->patchJson("/api/channels/{$channel->id}/voice/state", ['muted' => true])->assertOk();
    Event::assertDispatchedTimes(VoiceStateUpdated::class, 1);

    // Nor is a heartbeat.
    $this->postJson("/api/channels/{$channel->id}/voice/heartbeat")->assertNoContent();
    Event::assertDispatchedTimes(VoiceStateUpdated::class, 1);
});

it('keeps a seat alive with a heartbeat', function () {
    [$user, , $channel] = ownerWithVoiceChannel();

    $participant = VoiceParticipant::factory()->stale()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
    ]);

    Passport::actingAs($user);
    $this->postJson("/api/channels/{$channel->id}/voice/heartbeat")->assertNoContent();

    expect($participant->fresh()->last_seen_at->timestamp)
        ->toBeGreaterThan(VoiceParticipant::staleBefore()->timestamp);
});

it('sweeps the ghost left by a browser that died without leaving', function () {
    [$owner, $server, $channel] = ownerWithVoiceChannel();
    $ghost = User::factory()->create();
    $server->members()->attach($ghost->id, ['role' => 'member']);

    VoiceParticipant::factory()->stale()->create([
        'channel_id' => $channel->id,
        'user_id' => $ghost->id,
    ]);

    Passport::actingAs($owner);

    $response = $this->getJson("/api/servers/{$server->id}/voice")->assertOk();

    expect($response->json('data'))->toBe([]);
    expect(VoiceParticipant::count())->toBe(0);
});

it('lists who is in each voice channel of a server', function () {
    [$owner, $server, $channel] = ownerWithVoiceChannel();
    $quiet = Channel::factory()->create(['server_id' => $server->id, 'type' => 'voice']);

    Passport::actingAs($owner);
    $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    $response = $this->getJson("/api/servers/{$server->id}/voice")->assertOk();

    expect($response->json("data.{$channel->id}"))->toHaveCount(1)
        ->and($response->json("data.{$channel->id}.0.user.id"))->toBe($owner->id)
        // An empty room isn't in the roster at all, rather than present-but-empty.
        ->and($response->json("data.{$quiet->id}"))->toBeNull();
});

/*
 * Text-in-voice. A voice channel is a channel that happens to have a call attached, not a
 * different kind of thing — so the timeline, threads, reactions and unread badges all work
 * in it without a line of special-casing. These tests exist to keep it that way: the
 * frontend now relies on it, and a stray `isText()` guard added to the message pipeline
 * would break the chat half of every voice channel silently.
 */
it('lets people chat in a voice channel', function () {
    [$owner, $server, $channel] = ownerWithVoiceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    Passport::actingAs($member);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'anyone hear me?'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'anyone hear me?');

    Passport::actingAs($owner);

    $response = $this->getJson("/api/channels/{$channel->id}/messages")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.body'))->toBe('anyone hear me?');
});

it('badges unread messages in a voice channel', function () {
    [$owner, $server, $channel] = ownerWithVoiceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    Message::factory()->count(2)->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    Passport::actingAs($member);

    $response = $this->getJson("/api/servers/{$server->id}/channels")->assertOk();

    expect($response->json('data.0.type'))->toBe('voice')
        ->and($response->json('data.0.unread_count'))->toBe(2);
});

it('refuses to open a call in a text channel', function () {
    [$user, , $text] = ownerWithChannel();

    Passport::actingAs($user);

    $this->postJson("/api/channels/{$text->id}/voice/join")
        ->assertJsonValidationErrors('channel');

    expect(VoiceParticipant::count())->toBe(0);
});

it('forbids non-members from joining or reading a call', function () {
    [, $server, $channel] = ownerWithVoiceChannel();
    $stranger = User::factory()->create();

    Passport::actingAs($stranger);

    $this->postJson("/api/channels/{$channel->id}/voice/join")->assertForbidden();
    $this->postJson("/api/channels/{$channel->id}/voice/leave")->assertForbidden();
    $this->patchJson("/api/channels/{$channel->id}/voice/state", ['muted' => true])->assertForbidden();
    $this->getJson("/api/servers/{$server->id}/voice")->assertForbidden();
});
