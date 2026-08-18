<?php

use App\Models\Channel;
use App\Models\User;
use App\Models\VoiceParticipant;
use Laravel\Passport\Passport;

/**
 * Turning a channel into a different kind of channel.
 *
 * A conversion moves the lid, not the contents — so what's worth testing is that the contents
 * survive, that a room being closed doesn't strand the people in it, and that an app channel is
 * refused rather than half-converted.
 */
it('converts a text channel to voice and back, keeping its history', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $channel->messages()->create(['user_id' => $owner->id, 'body' => 'said before the change']);

    $this->patchJson("/api/channels/{$channel->id}/type", ['type' => 'voice'])
        ->assertOk()->assertJsonPath('data.type', 'voice');

    $this->patchJson("/api/channels/{$channel->id}/type", ['type' => 'text'])
        ->assertOk()->assertJsonPath('data.type', 'text');

    // The timeline was never the lid. Messages, threads and apps hang off the channel and have
    // never known which of the three they were in.
    expect($channel->messages()->count())->toBe(1);
});

it('seeds a map when a channel becomes a Side Space, and keeps it on the way back', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->patchJson("/api/channels/{$channel->id}/type", ['type' => 'space'])->assertOk();

    // A space with no map is a room nobody can stand in.
    expect($channel->spaceMap()->exists())->toBeTrue();
    $mapId = $channel->spaceMap->id;

    $this->patchJson("/api/channels/{$channel->id}/type", ['type' => 'text'])->assertOk();
    $this->patchJson("/api/channels/{$channel->id}/type", ['type' => 'space'])->assertOk();

    // Converting away and back must not bulldoze the furniture somebody placed.
    expect($channel->fresh()->spaceMap->id)->toBe($mapId);
});

it('turns out the call when a room becomes a text channel', function () {
    [$owner, , $channel] = ownerWithVoiceChannel();
    Passport::actingAs($owner);
    $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    expect(VoiceParticipant::where('channel_id', $channel->id)->count())->toBe(1);

    $this->patchJson("/api/channels/{$channel->id}/type", ['type' => 'text'])->assertOk();

    // A seat in a room the UI no longer draws is a ghost in the sidebar nothing would clear.
    expect(VoiceParticipant::where('channel_id', $channel->id)->count())->toBe(0);
});

it('carries the container’s discussions with it', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    // A discussion carries its container's server_id as well as its parent — see the
    // `channels_one_container` check constraint.
    $general = $channel->discussions()->create(['server_id' => $server->id, 'name' => 'General', 'type' => 'text', 'position' => 0]);
    $odd = $channel->discussions()->create(['server_id' => $server->id, 'name' => 'The Office', 'type' => 'space', 'position' => 1]);

    $this->patchJson("/api/channels/{$channel->id}/type", ['type' => 'voice'])->assertOk();

    // A discussion inherited its container's type when it was made, so it follows the container
    // — but one somebody deliberately made different is left as they made it.
    expect($general->fresh()->type)->toBe('voice')
        ->and($odd->fresh()->type)->toBe('space');
});

it('refuses to convert an app channel', function () {
    [$owner, $server] = ownerWithServer();
    $app = Channel::factory()->create(['server_id' => $server->id, 'type' => 'app']);
    $app->app()->create(['app_id' => 'kanban', 'installed_by' => $owner->id]);
    Passport::actingAs($owner);

    // An app channel's body is an application with a row of its own; uninstalling is the
    // operation that means this.
    $this->patchJson("/api/channels/{$app->id}/type", ['type' => 'voice'])->assertStatus(422);

    expect($app->fresh()->type)->toBe('app');
});

it('refuses a member who is not staff', function () {
    [, $server, $channel] = ownerWithChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id);
    Passport::actingAs($member);

    // A conversion is a change to the *place*, which is the place-owner's call.
    $this->patchJson("/api/channels/{$channel->id}/type", ['type' => 'voice'])->assertForbidden();

    expect($channel->fresh()->type)->toBe('text');
});
