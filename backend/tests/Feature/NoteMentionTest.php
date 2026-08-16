<?php

use App\Models\Message;
use App\Models\User;
use Laravel\Passport\Passport;

/**
 * @mentions in the shared note.
 *
 * The interesting case is the one a naive implementation gets wrong: a note saves every ~700ms
 * while somebody types, so "the body names Bob" must not be the trigger. Only *newly added*
 * names announce, and only once.
 */
function noteChannel(): array
{
    [$owner, $server, $channel] = ownerWithChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id);

    return [$owner, $member, $channel];
}

it('posts one mention message when a name is added, and none on later saves', function () {
    [$owner, $member, $channel] = noteChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/notes", [
        'content' => "Plan\n\n@{$member->name} can you look?",
    ])->assertOk();

    $announcement = Message::where('type', 'system')->sole();
    expect($announcement->body)->toContain("@{$member->name}")
        ->and($announcement->body)->toContain('notes');

    // Typing on around the mention is the common case, and it must stay silent.
    $this->putJson("/api/channels/{$channel->id}/notes", [
        'content' => "Plan\n\n@{$member->name} can you look? Thanks.",
    ])->assertOk();

    expect(Message::where('type', 'system')->count())->toBe(1);
});

it('says nothing when a note names nobody, or names only its own author', function () {
    [$owner, , $channel] = noteChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/notes", ['content' => 'ship it by friday'])->assertOk();
    // Naming yourself is not a notification anybody needs.
    $this->putJson("/api/channels/{$channel->id}/notes", ['content' => "@{$owner->name} owns this"])->assertOk();

    expect(Message::count())->toBe(0);
});

it('announces a second person added later, and only that person', function () {
    [$owner, $member, $channel] = noteChannel();
    $third = User::factory()->create();
    $channel->server->members()->attach($third->id);

    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/notes", ['content' => "@{$member->name} first"])->assertOk();
    $this->putJson("/api/channels/{$channel->id}/notes", [
        'content' => "@{$member->name} first\n@{$third->name} second",
    ])->assertOk();

    $latest = Message::where('type', 'system')->latest('id')->first();

    expect(Message::where('type', 'system')->count())->toBe(2)
        ->and($latest->body)->toContain("@{$third->name}")
        ->and($latest->body)->not->toContain("@{$member->name}");
});

it('does not announce a save the server refused as stale', function () {
    [$owner, $member, $channel] = noteChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/notes", ['content' => 'first'])->assertOk();

    // Version 0 is long gone — this is the losing half of two people typing at once, and the
    // client will merge and retry. Announcing a body that was never stored would name somebody
    // over an edit nobody can read.
    $this->putJson("/api/channels/{$channel->id}/notes", [
        'content' => "@{$member->name} hello", 'base_version' => 0,
    ])->assertStatus(409);

    expect(Message::where('type', 'system')->count())->toBe(0);
});
