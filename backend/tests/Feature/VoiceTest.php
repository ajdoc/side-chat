<?php

use App\Events\VoiceEffectsUpdated;
use App\Events\VoiceMuteEnforced;
use App\Events\VoiceParticipantDisconnected;
use App\Events\VoiceStateUpdated;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\VoiceEffectAssignment;
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

/*
 * Moderation. Turning other people out of a call is an owner-only power, and the person on
 * the receiving end has to be *told* — their browser is holding a live mesh open that the
 * presence channel can't ask to hang up on itself.
 */
it('lets the owner disconnect one participant and tells that person', function () {
    [$owner, $server, $channel] = ownerWithVoiceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    foreach ([$owner, $member] as $user) {
        Passport::actingAs($user);
        $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();
    }

    Event::fake([VoiceParticipantDisconnected::class]);

    Passport::actingAs($owner);
    $this->postJson("/api/channels/{$channel->id}/voice/disconnect", ['user_id' => $member->id])
        ->assertOk()
        ->assertJsonPath('disconnected', 1);

    // The member's seat is gone; the owner keeps theirs.
    expect(VoiceParticipant::where('channel_id', $channel->id)->pluck('user_id')->all())
        ->toBe([$owner->id]);

    Event::assertDispatched(
        VoiceParticipantDisconnected::class,
        fn (VoiceParticipantDisconnected $e) => $e->target->id === $member->id
            && $e->channel->id === $channel->id,
    );
});

it('lets the owner clear the room but keeps their own seat', function () {
    [$owner, $server, $channel] = ownerWithVoiceChannel();
    $members = User::factory()->count(3)->create();
    $server->members()->attach($members->pluck('id'), ['role' => 'member']);

    foreach ($members->push($owner) as $user) {
        Passport::actingAs($user);
        $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();
    }

    Passport::actingAs($owner);
    $this->postJson("/api/channels/{$channel->id}/voice/disconnect")
        ->assertOk()
        ->assertJsonPath('disconnected', 3);

    expect(VoiceParticipant::where('channel_id', $channel->id)->pluck('user_id')->all())
        ->toBe([$owner->id]);
});

it('lets a non-owner member disconnect anyone', function () {
    [$owner, $server, $channel] = ownerWithVoiceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    foreach ([$owner, $member] as $user) {
        Passport::actingAs($user);
        $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();
    }

    // Disconnecting is open to any member, so a plain member can turn out the owner.
    Passport::actingAs($member);
    $this->postJson("/api/channels/{$channel->id}/voice/disconnect", ['user_id' => $owner->id])
        ->assertOk()
        ->assertJsonPath('disconnected', 1);

    expect(VoiceParticipant::where('channel_id', $channel->id)->pluck('user_id')->all())
        ->toBe([$member->id]);
});

it('lets either person disconnect the other in a DM', function () {
    [$a, $b, $conversation] = dmBetween();
    $channel = $conversation->channel;

    foreach ([$a, $b] as $user) {
        Passport::actingAs($user);
        $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();
    }

    // A DM has no owner, but disconnecting only asks for membership — so either person may.
    Passport::actingAs($a);
    $this->postJson("/api/channels/{$channel->id}/voice/disconnect", ['user_id' => $b->id])
        ->assertOk()
        ->assertJsonPath('disconnected', 1);

    expect(VoiceParticipant::where('channel_id', $channel->id)->pluck('user_id')->all())
        ->toBe([$a->id]);
});

/*
 * Moving somebody else's microphone. Unlike disconnecting — which any member may do, because
 * it only empties a seat — this reaches a switch on another person's machine, so it stops
 * with whoever owns the place, and the person it happens to has to be told.
 */
it('lets the owner mute a participant and tells that person', function () {
    [$owner, $server, $channel] = ownerWithVoiceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    foreach ([$owner, $member] as $user) {
        Passport::actingAs($user);
        $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();
    }

    Event::fake([VoiceMuteEnforced::class, VoiceStateUpdated::class]);

    Passport::actingAs($owner);
    $this->postJson("/api/channels/{$channel->id}/voice/mute", ['user_id' => $member->id, 'muted' => true])
        ->assertOk()
        ->assertJsonPath('applied', true);

    // Written straight away, so nobody's sidebar waits on the target's browser to answer.
    expect(VoiceParticipant::where('channel_id', $channel->id)->where('user_id', $member->id)->value('muted'))
        ->toBeTrue();

    Event::assertDispatched(
        VoiceMuteEnforced::class,
        fn (VoiceMuteEnforced $e) => $e->target->id === $member->id
            && $e->channel->id === $channel->id
            && $e->muted === true,
    );
    Event::assertDispatched(VoiceStateUpdated::class);
});

it('lets the owner unmute someone who muted themselves', function () {
    [$owner, $server, $channel] = ownerWithVoiceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    foreach ([$owner, $member] as $user) {
        Passport::actingAs($user);
        $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();
    }

    Passport::actingAs($member);
    $this->patchJson("/api/channels/{$channel->id}/voice/state", ['muted' => true])->assertOk();

    Event::fake([VoiceMuteEnforced::class]);

    Passport::actingAs($owner);
    $this->postJson("/api/channels/{$channel->id}/voice/mute", ['user_id' => $member->id, 'muted' => false])
        ->assertOk()
        ->assertJsonPath('applied', true);

    expect(VoiceParticipant::where('channel_id', $channel->id)->where('user_id', $member->id)->value('muted'))
        ->toBeFalse();

    Event::assertDispatched(
        VoiceMuteEnforced::class,
        fn (VoiceMuteEnforced $e) => $e->target->id === $member->id && $e->muted === false,
    );
});

it('tells the target even when the row already said what the owner is asking for', function () {
    // The row is only what the server last *heard*. A client that has drifted out of step is
    // exactly the one that needs the message, so this is not treated as a no-op.
    [$owner, $server, $channel] = ownerWithVoiceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    foreach ([$owner, $member] as $user) {
        Passport::actingAs($user);
        $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();
    }

    Event::fake([VoiceMuteEnforced::class]);

    Passport::actingAs($owner);
    $this->postJson("/api/channels/{$channel->id}/voice/mute", ['user_id' => $member->id, 'muted' => false])
        ->assertOk()
        ->assertJsonPath('applied', true);

    Event::assertDispatched(VoiceMuteEnforced::class);
});

it('refuses to let a plain member mute anyone', function () {
    [$owner, $server, $channel] = ownerWithVoiceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    foreach ([$owner, $member] as $user) {
        Passport::actingAs($user);
        $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();
    }

    Passport::actingAs($member);
    $this->postJson("/api/channels/{$channel->id}/voice/mute", ['user_id' => $owner->id, 'muted' => true])
        ->assertForbidden();

    expect(VoiceParticipant::where('channel_id', $channel->id)->where('user_id', $owner->id)->value('muted'))
        ->toBeFalse();
});

it("lets a group's owner mute someone in its call", function () {
    [$a, $b] = twoMembers();

    $conversation = Conversation::factory()->group()->withMembers([$a, $b])->create(['owner_id' => $a->id]);
    $channel = $conversation->load('channel')->channel;

    foreach ([$a, $b] as $user) {
        Passport::actingAs($user);
        $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();
    }

    Passport::actingAs($a);
    $this->postJson("/api/channels/{$channel->id}/voice/mute", ['user_id' => $b->id, 'muted' => true])
        ->assertOk();

    expect(VoiceParticipant::where('channel_id', $channel->id)->where('user_id', $b->id)->value('muted'))
        ->toBeTrue();
});

it('refuses in a DM, which has nobody to be its owner', function () {
    [$a, $b, $conversation] = dmBetween();
    $channel = $conversation->channel;

    foreach ([$a, $b] as $user) {
        Passport::actingAs($user);
        $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();
    }

    Passport::actingAs($a);
    $this->postJson("/api/channels/{$channel->id}/voice/mute", ['user_id' => $b->id, 'muted' => true])
        ->assertForbidden();
});

it('reports that nothing was applied when the target has already left', function () {
    [$owner, $server, $channel] = ownerWithVoiceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    Passport::actingAs($owner);
    $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    $this->postJson("/api/channels/{$channel->id}/voice/mute", ['user_id' => $member->id, 'muted' => true])
        ->assertOk()
        ->assertJsonPath('applied', false);
});

/*
|--------------------------------------------------------------------------
| Sharing sound without a picture
|--------------------------------------------------------------------------
*/

it('publishes an audio-only share separately from a screen share', function () {
    [$user, , $channel] = ownerWithVoiceChannel();

    Passport::actingAs($user);
    $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    $this->patchJson("/api/channels/{$channel->id}/voice/state", ['audio_sharing' => true])
        ->assertOk()
        ->assertJsonPath('data.audio_sharing', true)
        // The distinction is the point: nobody should be offered a screen to watch.
        ->assertJsonPath('data.screen_sharing', false);
});

it('forgets an audio share you died in the middle of', function () {
    [$user, , $channel] = ownerWithVoiceChannel();

    VoiceParticipant::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'audio_sharing' => true,
    ]);

    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/voice/join")
        ->assertOk()
        ->assertJsonPath('data.0.audio_sharing', false);
});

/*
|--------------------------------------------------------------------------
| Entrance and exit effects
|--------------------------------------------------------------------------
*/

it('hands the effects over on join — the room\'s, and each person\'s', function () {
    [$owner, $server, $channel] = ownerWithVoiceChannel();
    $friend = User::factory()->create();
    $server->members()->attach($friend->id, ['role' => 'member']);

    $channel->update(['join_effect' => 'sparkles', 'leave_effect' => null]);
    VoiceEffectAssignment::create([
        'channel_id' => $channel->id,
        'user_id' => $friend->id,
        'join_effect' => 'fireworks',
        'leave_effect' => 'confetti',
    ]);

    Passport::actingAs($owner);

    // Everything has to be in the browser *before* the door opens, so it all rides the join.
    $this->postJson("/api/channels/{$channel->id}/voice/join")
        ->assertOk()
        ->assertJsonPath('effects.default.join', 'sparkles')
        ->assertJsonPath('effects.default.leave', null)
        ->assertJsonPath('effects.people.0.user_id', $friend->id)
        ->assertJsonPath('effects.people.0.join', 'fireworks')
        ->assertJsonPath('effects.people.0.leave', 'confetti');
});

it('lets the owner attach an effect to one person, and tells everyone', function () {
    Event::fake([VoiceEffectsUpdated::class]);

    [$owner, $server, $channel] = ownerWithVoiceChannel();
    $friend = User::factory()->create();
    $server->members()->attach($friend->id, ['role' => 'member']);

    Passport::actingAs($owner);

    $this->patchJson("/api/channels/{$channel->id}/voice/effects", [
        'user_id' => $friend->id,
        'join_effect' => 'fireworks',
        'leave_effect' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.people.0.user_id', $friend->id)
        ->assertJsonPath('data.people.0.join', 'fireworks')
        // One person being singled out says nothing about the room.
        ->assertJsonPath('data.default.join', null);

    expect(VoiceEffectAssignment::where('channel_id', $channel->id)->where('user_id', $friend->id)->value('join_effect'))
        ->toBe('fireworks');

    Event::assertDispatched(VoiceEffectsUpdated::class);
});

it("sets the room's default when no one is named", function () {
    [$owner, , $channel] = ownerWithVoiceChannel();

    Passport::actingAs($owner);

    $this->patchJson("/api/channels/{$channel->id}/voice/effects", [
        'join_effect' => 'confetti',
        'leave_effect' => 'sparkles',
    ])
        ->assertOk()
        ->assertJsonPath('data.default.join', 'confetti')
        ->assertJsonPath('data.default.leave', 'sparkles')
        ->assertJsonPath('data.people', []);

    expect($channel->fresh()->join_effect)->toBe('confetti');
});

it('forgets a person entirely when both their effects are cleared', function () {
    [$owner, $server, $channel] = ownerWithVoiceChannel();
    $friend = User::factory()->create();
    $server->members()->attach($friend->id, ['role' => 'member']);

    VoiceEffectAssignment::create([
        'channel_id' => $channel->id,
        'user_id' => $friend->id,
        'join_effect' => 'fireworks',
    ]);

    Passport::actingAs($owner);

    // A row saying "nothing" and no row at all behave the same today — they would stop
    // behaving the same the moment the room's default changed underneath it.
    $this->patchJson("/api/channels/{$channel->id}/voice/effects", [
        'user_id' => $friend->id,
        'join_effect' => null,
        'leave_effect' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.people', []);

    expect(VoiceEffectAssignment::where('channel_id', $channel->id)->count())->toBe(0);
});

it('refuses an effect nobody can draw', function () {
    [$owner, , $channel] = ownerWithVoiceChannel();

    Passport::actingAs($owner);

    $this->patchJson("/api/channels/{$channel->id}/voice/effects", [
        'join_effect' => 'airhorn',
        'leave_effect' => null,
    ])->assertJsonValidationErrors('join_effect');
});

it('refuses to decorate somebody who is not a member here', function () {
    [$owner, , $channel] = ownerWithVoiceChannel();
    $stranger = User::factory()->create();

    Passport::actingAs($owner);

    $this->patchJson("/api/channels/{$channel->id}/voice/effects", [
        'user_id' => $stranger->id,
        'join_effect' => 'fireworks',
        'leave_effect' => null,
    ])->assertJsonValidationErrors('user_id');

    expect(VoiceEffectAssignment::count())->toBe(0);
});

it('keeps the effects with the owner — a member in the call may not attach one', function () {
    [, $server, $channel] = ownerWithVoiceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    Passport::actingAs($member);
    $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    $this->patchJson("/api/channels/{$channel->id}/voice/effects", [
        'user_id' => $member->id,
        'join_effect' => 'fireworks',
        'leave_effect' => null,
    ])->assertForbidden();

    expect(VoiceEffectAssignment::count())->toBe(0);

    // Reading them is another matter: everyone in the call has to know what will play.
    $this->getJson("/api/channels/{$channel->id}/voice/effects")->assertOk();
});

it('refuses effects in a chat, which is not a venue with an owner', function () {
    [$a, , $conversation] = dmBetween();

    Passport::actingAs($a);

    $this->patchJson("/api/channels/{$conversation->channel->id}/voice/effects", [
        'join_effect' => 'fireworks',
        'leave_effect' => null,
    ])->assertForbidden();
});
