<?php

use App\Events\CallDeclined;
use App\Events\CallEnded;
use App\Events\CallStarted;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\VoiceParticipant;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;

/**
 * A call in a chat, as distinct from a call in a server's voice channel.
 *
 * The voice channel is a *place*: it's always there, you walk in, nobody is interrupted.
 * A call in a DM is an *event*: it rings, it can be missed, and it ends. Everything below
 * is testing that difference — the mesh, the signalling and the roster underneath are the
 * same code that already served voice channels, and are already tested in VoiceTest.
 */

it('rings the other person when you start a call in a dm', function () {
    Event::fake([CallStarted::class]);

    [$me, $them, $dm] = dmBetween();

    Passport::actingAs($me);

    // There is no "start a call" endpoint: walking into an empty room *is* placing the
    // call. Joining is the only truth about whether a call is happening.
    $this->postJson("/api/channels/{$dm->channel->id}/voice/join")->assertOk();

    Event::assertDispatched(CallStarted::class, function (CallStarted $event) use ($me, $them) {
        $names = collect($event->broadcastOn())->map->name->all();

        // To them, wherever they are — and *not* to me. Ringing your own phone is a bug.
        return $event->caller->id === $me->id
            && $names === ['private-user.'.$them->id];
    });

    expect($dm->fresh()->hasActiveCall())->toBeTrue();
    expect($dm->fresh()->call_started_by)->toBe($me->id);
});

it('does not ring a second time when someone joins a call already in progress', function () {
    [$me, $them, $dm] = dmBetween();

    Passport::actingAs($me);
    $this->postJson("/api/channels/{$dm->channel->id}/voice/join")->assertOk();

    Event::fake([CallStarted::class]);

    Passport::actingAs($them);
    $this->postJson("/api/channels/{$dm->channel->id}/voice/join")->assertOk();

    Event::assertNotDispatched(CallStarted::class);

    // Picking up is what turns a ring into a conversation — and it's what a duration is
    // measured from.
    expect($dm->fresh()->call_answered_at)->not->toBeNull();
});

it('writes a call to the transcript when the last person hangs up', function () {
    [$me, $them, $dm] = dmBetween();

    Passport::actingAs($me);
    $this->postJson("/api/channels/{$dm->channel->id}/voice/join")->assertOk();

    Passport::actingAs($them);
    $this->postJson("/api/channels/{$dm->channel->id}/voice/join")->assertOk();

    // One of them leaves: the call is still going, so nothing is written yet.
    $this->postJson("/api/channels/{$dm->channel->id}/voice/leave")->assertNoContent();
    expect(Message::where('type', 'system')->count())->toBe(0);
    expect($dm->fresh()->hasActiveCall())->toBeTrue();

    Passport::actingAs($me);
    $this->postJson("/api/channels/{$dm->channel->id}/voice/leave")->assertNoContent();

    $system = Message::where('type', 'system')->first();

    expect($system->body)->toStartWith('Call ended ·')
        // Attributed to whoever rang, not to whoever happened to hang up last.
        ->and($system->user_id)->toBe($me->id);

    expect($dm->fresh()->hasActiveCall())->toBeFalse();
});

it('records a missed call when nobody ever picks up', function () {
    Event::fake([CallEnded::class]);

    [$me, , $dm] = dmBetween();

    Passport::actingAs($me);
    $this->postJson("/api/channels/{$dm->channel->id}/voice/join")->assertOk();
    $this->postJson("/api/channels/{$dm->channel->id}/voice/leave")->assertNoContent();

    expect(Message::where('type', 'system')->first()->body)->toBe('Missed call');

    Event::assertDispatched(CallEnded::class, fn (CallEnded $e) => $e->answered === false);
});

it('stops the ringing on everyone’s phone when the call ends', function () {
    Event::fake([CallEnded::class]);

    [$me, $them, $dm] = dmBetween();

    Passport::actingAs($me);
    $this->postJson("/api/channels/{$dm->channel->id}/voice/join")->assertOk();
    $this->postJson("/api/channels/{$dm->channel->id}/voice/leave")->assertNoContent();

    // Including the person who never answered — whose phone is the entire reason this
    // event exists. A ring that outlives the call is the worst bug this feature could have.
    Event::assertDispatched(CallEnded::class, function (CallEnded $event) use ($me, $them) {
        $names = collect($event->broadcastOn())->map->name->all();

        return in_array('private-user.'.$them->id, $names, true)
            && in_array('private-user.'.$me->id, $names, true);
    });
});

it('declines a call without joining it', function () {
    Event::fake([CallDeclined::class]);

    [$me, $them, $dm] = dmBetween();

    Passport::actingAs($me);
    $this->postJson("/api/channels/{$dm->channel->id}/voice/join")->assertOk();

    Passport::actingAs($them);
    $this->postJson("/api/conversations/{$dm->id}/call/decline")->assertNoContent();

    Event::assertDispatched(CallDeclined::class, function (CallDeclined $event) use ($me, $them) {
        $names = collect($event->broadcastOn())->map->name->all();

        // Two audiences: the caller learns it was declined, and the decliner's *other tabs*
        // stop ringing — the case you only notice when you don't handle it.
        return $event->user->id === $them->id
            && in_array('private-user.'.$me->id, $names, true)
            && in_array('private-user.'.$them->id, $names, true);
    });

    // Declining is not leaving: they were never in it.
    expect(VoiceParticipant::where('user_id', $them->id)->count())->toBe(0);
    expect($dm->fresh()->hasActiveCall())->toBeTrue();
});

it('holds a call in a group chat', function () {
    [$me, $them, $server] = twoMembers();
    $third = User::factory()->create();
    $server->members()->attach($third->id, ['role' => 'member']);

    $group = Conversation::factory()->group('Crew')->withMembers([$me, $them, $third])->create();

    Event::fake([CallStarted::class]);

    Passport::actingAs($me);
    $this->postJson("/api/channels/{$group->channel->id}/voice/join")->assertOk();

    Event::assertDispatched(CallStarted::class, function (CallStarted $event) use ($me, $them, $third) {
        $names = collect($event->broadcastOn())->map->name->all();

        return count($names) === 2
            && in_array('private-user.'.$them->id, $names, true)
            && in_array('private-user.'.$third->id, $names, true)
            && ! in_array('private-user.'.$me->id, $names, true);
    });
});

it('shows who is in a chat’s call before you join it', function () {
    [$me, $them, $dm] = dmBetween();

    Passport::actingAs($them);
    $this->postJson("/api/channels/{$dm->channel->id}/voice/join")->assertOk();

    Passport::actingAs($me);
    $response = $this->getJson("/api/conversations/{$dm->id}/voice")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.user.id'))->toBe($them->id);

    // …and the chat list says so too, so a chat you aren't looking at can show "in a call".
    expect($this->getJson('/api/conversations')->json('data.0.call_active'))->toBeTrue();
});

it('still refuses to open a call in a server’s text channel', function () {
    [$user, , $channel] = ownerWithChannel();

    Passport::actingAs($user);

    // The gate widened to let a chat's channel host a call — it must not have widened to
    // let #general host one. Nobody in a server asked to be called.
    $this->postJson("/api/channels/{$channel->id}/voice/join")
        ->assertStatus(422)
        ->assertJsonValidationErrors('channel');
});

it('forbids an outsider from joining a chat’s call', function () {
    [, , $dm] = dmBetween();
    $outsider = User::factory()->create();

    Passport::actingAs($outsider);

    $this->postJson("/api/channels/{$dm->channel->id}/voice/join")->assertForbidden();
    $this->postJson("/api/conversations/{$dm->id}/call/decline")->assertForbidden();
});

it('ends the call you were in when you answer a different one', function () {
    [$me, $them, $first] = dmBetween();
    $second = Conversation::factory()->group('Elsewhere')->withMembers([$me, $them])->create();

    Passport::actingAs($me);
    $this->postJson("/api/channels/{$first->channel->id}/voice/join")->assertOk();

    // You can only be in one call at a time — and walking out of the first one still ends
    // it, because you were the only person in it.
    $this->postJson("/api/channels/{$second->channel->id}/voice/join")->assertOk();

    expect($first->fresh()->hasActiveCall())->toBeFalse()
        ->and($second->fresh()->hasActiveCall())->toBeTrue();

    expect(Message::where('channel_id', $first->channel->id)->where('type', 'system')->first()->body)
        ->toBe('Missed call');
});
