<?php

use App\Models\Channel;
use App\Models\ChannelRead;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Notifications\NotificationPolicy;
use App\Support\Notifications\NotifyLevel;
use Laravel\Passport\Passport;

it('starts with no override and the account default in effect', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->getJson("/api/channels/{$channel->id}/notifications")
        ->assertOk()
        ->assertJson([
            'notify_level' => null,      // nothing chosen here
            'effective_level' => 'mentions', // …so the account default applies
        ]);
});

it('sets and clears a channel override', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->putJson("/api/channels/{$channel->id}/notifications", ['notify_level' => 'all'])
        ->assertOk()
        ->assertJson(['notify_level' => 'all', 'effective_level' => 'all']);

    // Null is an instruction, not an omission: it goes back to following the default.
    $this->putJson("/api/channels/{$channel->id}/notifications", ['notify_level' => null])
        ->assertOk()
        ->assertJson(['notify_level' => null, 'effective_level' => 'mentions']);
});

it('follows the account default as it changes, but not a pinned channel', function () {
    [$user, , $channel] = ownerWithChannel();
    $pinned = Channel::factory()->create(['server_id' => $channel->server_id]);
    Passport::actingAs($user);

    $this->putJson("/api/channels/{$pinned->id}/notifications", ['notify_level' => 'mentions'])->assertOk();
    $this->patchJson('/api/preferences', ['notify_channel_default' => 'all'])->assertOk();

    // The untouched channel moves with the default; the explicitly-set one does not.
    $this->getJson("/api/channels/{$channel->id}/notifications")
        ->assertJsonPath('effective_level', 'all');
    $this->getJson("/api/channels/{$pinned->id}/notifications")
        ->assertJsonPath('effective_level', 'mentions');
});

it('mutes for a while without destroying the level underneath', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->putJson("/api/channels/{$channel->id}/notifications", ['notify_level' => 'all'])->assertOk();
    $this->putJson("/api/channels/{$channel->id}/notifications", ['mute_minutes' => 60])
        ->assertOk()
        ->assertJson(['notify_level' => 'all', 'effective_level' => 'none']);

    // Once it lapses, the level that was there all along comes back.
    $this->travel(61)->minutes();

    $this->getJson("/api/channels/{$channel->id}/notifications")
        ->assertJsonPath('effective_level', 'all');
});

it('leaves the mute alone when only the level is sent', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->putJson("/api/channels/{$channel->id}/notifications", ['mute_minutes' => 60])->assertOk();
    $this->putJson("/api/channels/{$channel->id}/notifications", ['notify_level' => 'all'])
        ->assertOk()
        ->assertJsonPath('effective_level', 'none'); // still muted

    expect(ChannelRead::where('channel_id', $channel->id)->value('muted_until'))->not->toBeNull();
});

it('inherits a muted channel into its discussions', function () {
    [$user, $server] = ownerWithServer();
    $container = Channel::factory()->create(['server_id' => $server->id]);
    $discussion = Channel::factory()->create(['server_id' => $server->id, 'parent_id' => $container->id]);

    Passport::actingAs($user);
    $this->putJson("/api/channels/{$container->id}/notifications", ['mute_minutes' => 60])->assertOk();

    // Nothing was written against the discussion, but it goes quiet with its parent.
    expect(app(NotificationPolicy::class)->levelFor($discussion->fresh(), $user))
        ->toBe(NotifyLevel::None);
});

it('lets a discussion override the channel it lives in', function () {
    [$user, $server] = ownerWithServer();
    $container = Channel::factory()->create(['server_id' => $server->id]);
    $discussion = Channel::factory()->create(['server_id' => $server->id, 'parent_id' => $container->id]);

    Passport::actingAs($user);
    $this->putJson("/api/channels/{$container->id}/notifications", ['notify_level' => 'none'])->assertOk();
    $this->putJson("/api/channels/{$discussion->id}/notifications", ['notify_level' => 'all'])->assertOk();

    expect(app(NotificationPolicy::class)->levelFor($discussion->fresh(), $user))
        ->toBe(NotifyLevel::All);
});

it('uses the DM default rather than the channel default in a chat', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $conversation = Conversation::factory()->create(['type' => 'dm']);
    $conversation->members()->attach([$user->id, $other->id]);
    $channel = Channel::factory()->create(['server_id' => null, 'conversation_id' => $conversation->id]);

    // A DM was addressed to you by definition, so it starts at 'all' where a channel starts
    // at 'mentions'.
    expect(app(NotificationPolicy::class)->levelFor($channel, $user))->toBe(NotifyLevel::All);
});

it('addresses a chat by its conversation id', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->create(['type' => 'dm']);
    $conversation->members()->attach([$user->id, User::factory()->create()->id]);
    Channel::factory()->create(['server_id' => null, 'conversation_id' => $conversation->id]);

    Passport::actingAs($user);

    $this->putJson("/api/conversations/{$conversation->id}/notifications", ['notify_level' => 'none'])
        ->assertOk()
        ->assertJson(['notify_level' => 'none', 'effective_level' => 'none']);
});

it('refuses a channel the caller is not in', function () {
    [, , $channel] = ownerWithChannel();
    Passport::actingAs(User::factory()->create());

    $this->putJson("/api/channels/{$channel->id}/notifications", ['notify_level' => 'none'])
        ->assertForbidden();
});

it('rejects an unknown level', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->putJson("/api/channels/{$channel->id}/notifications", ['notify_level' => 'sometimes'])
        ->assertStatus(422);
});

it('keeps one person\'s settings out of another\'s', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/notifications", ['notify_level' => 'none'])->assertOk();

    Passport::actingAs($member);
    $this->getJson("/api/channels/{$channel->id}/notifications")
        ->assertJson(['notify_level' => null, 'effective_level' => 'mentions']);
});
