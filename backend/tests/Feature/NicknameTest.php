<?php

use App\Models\Conversation;
use App\Models\Nickname;
use App\Models\User;
use App\Services\NicknameService;
use App\Support\MentionParser;
use Laravel\Passport\Passport;

it('lets a member set their own public nickname in a server', function () {
    [$owner, $other, $server] = twoMembers();
    Passport::actingAs($other);

    $this->putJson("/api/servers/{$server->id}/nicknames/{$other->id}", [
        'nickname' => 'ada-ops',
        'scope' => 'public',
    ])->assertOk()->assertJsonPath('data.nickname', 'ada-ops');

    // Everyone in the place sees it, not just the person who set it.
    Passport::actingAs($owner);
    $this->getJson("/api/servers/{$server->id}/nicknames")
        ->assertOk()
        ->assertJsonPath("data.public.{$other->id}", 'ada-ops');
});

it('lets the server owner rename another member publicly', function () {
    [$owner, $other, $server] = twoMembers();
    Passport::actingAs($owner);

    $this->putJson("/api/servers/{$server->id}/nicknames/{$other->id}", [
        'nickname' => 'The Intern',
        'scope' => 'public',
    ])->assertOk();

    expect(Nickname::where('user_id', $other->id)->whereNull('viewer_id')->first()?->nickname)
        ->toBe('The Intern');
});

it('refuses to let a plain member rename someone else publicly', function () {
    [$owner, $other, $server] = twoMembers();
    Passport::actingAs($other);

    $this->putJson("/api/servers/{$server->id}/nicknames/{$owner->id}", [
        'nickname' => 'Boss',
        'scope' => 'public',
    ])->assertForbidden();
});

it('keeps a private alias to the person who set it', function () {
    [$owner, $other, $server] = twoMembers();
    Passport::actingAs($owner);

    $this->putJson("/api/servers/{$server->id}/nicknames/{$other->id}", [
        'nickname' => 'Sam from work',
        'scope' => 'private',
    ])->assertOk();

    $this->getJson("/api/servers/{$server->id}/nicknames")
        ->assertJsonPath("data.private.{$other->id}", 'Sam from work');

    // The person it's about learns nothing of it — neither map mentions them.
    Passport::actingAs($other);
    $this->getJson("/api/servers/{$server->id}/nicknames")
        ->assertOk()
        ->assertJsonMissingPath("data.private.{$other->id}")
        ->assertJsonMissingPath("data.public.{$other->id}");
});

it('lets any member set a private alias without owning anything', function () {
    [$owner, $other, $server] = twoMembers();
    Passport::actingAs($other);

    $this->putJson("/api/servers/{$server->id}/nicknames/{$owner->id}", [
        'nickname' => 'The Boss',
        'scope' => 'private',
    ])->assertOk();
});

it('clears a naming when given a blank nickname', function () {
    [, $other, $server] = twoMembers();
    Passport::actingAs($other);

    $path = "/api/servers/{$server->id}/nicknames/{$other->id}";

    $this->putJson($path, ['nickname' => 'ada-ops', 'scope' => 'public'])->assertOk();
    $this->putJson($path, ['nickname' => null, 'scope' => 'public'])
        ->assertOk()
        ->assertJsonPath('data.nickname', null);

    expect(Nickname::count())->toBe(0);
});

it('refuses to name somebody who is not in the place', function () {
    [, $other, $server] = twoMembers();
    $stranger = User::factory()->create();
    Passport::actingAs($other);

    $this->putJson("/api/servers/{$server->id}/nicknames/{$stranger->id}", [
        'nickname' => 'nobody',
        'scope' => 'private',
    ])->assertForbidden();
});

it('refuses a non-member entirely', function () {
    [, , $server] = twoMembers();
    Passport::actingAs(User::factory()->create());

    $this->getJson("/api/servers/{$server->id}/nicknames")->assertForbidden();
});

it('scopes nicknames to one place', function () {
    [$owner, $other, $server] = twoMembers();
    // The same two people, in a chat as well as the server — otherwise "the same person
    // elsewhere" isn't the same person at all.
    $conversation = Conversation::factory()->withMembers([$owner, $other])->create();

    Passport::actingAs($other);
    $this->putJson("/api/servers/{$server->id}/nicknames/{$other->id}", [
        'nickname' => 'ada-ops',
        'scope' => 'public',
    ])->assertOk();

    // The same person, in a chat they're also in, is still themselves.
    Passport::actingAs($owner);
    $this->getJson("/api/conversations/{$conversation->id}/nicknames")
        ->assertOk()
        ->assertJsonMissingPath("data.public.{$other->id}");
});

it('works in a DM, where nobody may rename anybody else publicly', function () {
    [$a, $b, $conversation] = dmBetween();
    Passport::actingAs($a);

    $this->putJson("/api/conversations/{$conversation->id}/nicknames/{$a->id}", [
        'nickname' => 'just me',
        'scope' => 'public',
    ])->assertOk();

    // A chat has an owner field, but it buys nobody authority over a name.
    $this->putJson("/api/conversations/{$conversation->id}/nicknames/{$b->id}", [
        'nickname' => 'not yours to set',
        'scope' => 'public',
    ])->assertForbidden();
});

it('rejects an unknown scope', function () {
    [, $other, $server] = twoMembers();
    Passport::actingAs($other);

    $this->putJson("/api/servers/{$server->id}/nicknames/{$other->id}", [
        'nickname' => 'x',
        'scope' => 'sideways',
    ])->assertStatus(422)->assertJsonValidationErrors('scope');
});

it('mentions a member by their nickname as well as their name', function () {
    [, $other, $server] = twoMembers();

    app(NicknameService::class)->set($server, $other, null, 'ada-ops');
    $names = app(NicknameService::class)->mentionNamesFor($server);

    expect(MentionParser::parse('hey @ada-ops', $names)['user_ids'])->toContain($other->id)
        ->and(MentionParser::parse("hey @{$other->name}", $names)['user_ids'])->toContain($other->id);
});

it('never offers a private alias as a mention name', function () {
    [$owner, $other, $server] = twoMembers();

    app(NicknameService::class)->set($server, $other, $owner, 'Sam from work');
    $names = app(NicknameService::class)->mentionNamesFor($server);

    expect(MentionParser::parse('hey @Sam from work', $names)['user_ids'])->toBeEmpty();
});

it('drops a place\'s nicknames when the place is deleted', function () {
    [, $other, $server] = twoMembers();

    app(NicknameService::class)->set($server, $other, null, 'ada-ops');
    expect(Nickname::count())->toBe(1);

    $server->delete();

    // Nothing holds the polymorphic link, so this is the trait's doing — see HasNicknames.
    expect(Nickname::count())->toBe(0);
});
