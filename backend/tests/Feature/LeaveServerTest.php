<?php

use App\Models\Channel;
use App\Models\ChannelRead;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use App\Models\VoiceParticipant;
use Laravel\Passport\Passport;

/** A member (not the owner) of a server that has a text channel. */
function memberOfServer(): array
{
    [$owner, $server, $channel] = ownerWithChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    return [$member, $owner, $server, $channel];
}

it('lets a member leave a server', function () {
    [$member, , $server] = memberOfServer();

    Passport::actingAs($member);
    $this->postJson("/api/servers/{$server->id}/leave")->assertNoContent();

    expect($server->fresh()->hasMember($member))->toBeFalse();

    // Gone from their rail, and the door is shut behind them.
    $this->getJson('/api/servers')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/servers/{$server->id}")->assertForbidden();
});

it('keeps the messages of someone who leaves', function () {
    [$member, $owner, $server, $channel] = memberOfServer();

    Passport::actingAs($member);
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'still here'])->assertCreated();

    $this->postJson("/api/servers/{$server->id}/leave")->assertNoContent();

    // Leaving a conversation doesn't unsay what you said — the people still in the
    // channel keep the history they were part of.
    Passport::actingAs($owner);
    $this->getJson("/api/channels/{$channel->id}/messages")
        ->assertOk()
        ->assertJsonPath('data.0.body', 'still here');

    expect(Message::where('user_id', $member->id)->count())->toBe(1);
});

it('takes the leaver out of the voice roster and the seen-by row', function () {
    [$member, , $server, $channel] = memberOfServer();
    $voice = Channel::factory()->create(['server_id' => $server->id, 'type' => 'voice']);

    Passport::actingAs($member);
    $this->postJson("/api/channels/{$voice->id}/voice/join")->assertOk();
    Message::factory()->create(['channel_id' => $channel->id]);
    $this->postJson("/api/channels/{$channel->id}/read")->assertOk();

    expect(VoiceParticipant::where('user_id', $member->id)->count())->toBe(1)
        ->and(ChannelRead::where('user_id', $member->id)->count())->toBe(1);

    $this->postJson("/api/servers/{$server->id}/leave")->assertNoContent();

    // Both are claims about the present, not the past: a seat in a call they've left, and
    // a read marker that would keep drawing their avatar in a channel they can't read.
    expect(VoiceParticipant::where('user_id', $member->id)->count())->toBe(0)
        ->and(ChannelRead::where('user_id', $member->id)->count())->toBe(0);
});

it('refuses to let the owner leave their own server', function () {
    [$owner, $server] = ownerWithServer();

    Passport::actingAs($owner);
    $this->postJson("/api/servers/{$server->id}/leave")
        ->assertStatus(422)
        ->assertJsonValidationErrors('server');

    // Still theirs, and still standing.
    expect($server->fresh()->hasMember($owner))->toBeTrue()
        ->and(Server::find($server->id))->not->toBeNull();
});

it('forbids a non-member from leaving a server they were never in', function () {
    [, $server] = ownerWithServer();

    Passport::actingAs(User::factory()->create());
    $this->postJson("/api/servers/{$server->id}/leave")->assertForbidden();
});

it('lets someone who left ask to join again', function () {
    [$member, , $server] = memberOfServer();

    Passport::actingAs($member);
    $this->postJson("/api/servers/{$server->id}/leave")->assertNoContent();

    $this->postJson("/api/invites/{$server->invite_code}/join")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');
});
