<?php

use App\Models\Automation;
use App\Models\Badge;
use App\Models\Server;
use App\Models\User;
use App\Services\Automation\TriggerRegistry;
use Laravel\Passport\Passport;

/*
 * The dashboard API: who may configure a bot, and what they may configure.
 *
 * The interesting cases are all authorisation. Rules are staff-writable because running the
 * place is what an admin is for — with one exception, `set_role`, which would otherwise be
 * a way for an admin to appoint admins. That pair is what most of this file is about.
 */

/** An admin on somebody else's server. */
function adminOn(Server $server): User
{
    $admin = User::factory()->create();
    $server->members()->attach($admin->id, ['role' => Server::ROLE_ADMIN]);

    return $admin;
}

it('lets staff create a rule', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    Passport::actingAs(adminOn($server));

    $this->postJson("/api/servers/{$server->id}/automations", [
        'name' => 'Greet',
        'trigger' => TriggerRegistry::MEMBER_JOINED,
        'actions' => [['type' => 'post_message', 'config' => ['channel_id' => $channel->id, 'body' => 'hi {user}']]],
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Greet')
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.actions.0.type', 'post_message');

    expect($server->automations()->count())->toBe(1);
});

it('refuses a rule from somebody who is only a member', function () {
    [, $server] = ownerWithServer();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => Server::ROLE_MEMBER]);
    Passport::actingAs($member);

    $this->postJson("/api/servers/{$server->id}/automations", [
        'name' => 'Greet',
        'trigger' => TriggerRegistry::MEMBER_JOINED,
        'actions' => [['type' => 'post_message', 'config' => ['body' => 'hi']]],
    ])->assertForbidden();
});

it('will not let an admin write a rule that hands out roles', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs(adminOn($server));

    $this->postJson("/api/servers/{$server->id}/automations", [
        'name' => 'Promote',
        'trigger' => TriggerRegistry::REACTION_ADDED,
        'actions' => [['type' => 'set_role', 'config' => ['role' => 'admin']]],
    ])->assertStatus(422)->assertJsonValidationErrors('actions.0.type');

    // The owner may. Role changes are theirs everywhere else in the app, so a rule that
    // makes them is theirs too.
    Passport::actingAs($owner);
    $this->postJson("/api/servers/{$server->id}/automations", [
        'name' => 'Promote',
        'trigger' => TriggerRegistry::REACTION_ADDED,
        'actions' => [['type' => 'set_role', 'config' => ['role' => 'admin']]],
    ])->assertCreated();
});

it('refuses a trigger or action that does not exist', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/automations", [
        'name' => 'Nope',
        'trigger' => 'the.moon.rises',
        'actions' => [['type' => 'summon_demon']],
    ])->assertStatus(422)->assertJsonValidationErrors(['trigger', 'actions.0.type']);
});

it('replaces a rule’s actions wholesale on update', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $id = $this->postJson("/api/servers/{$server->id}/automations", [
        'name' => 'Greet',
        'trigger' => TriggerRegistry::MEMBER_JOINED,
        'actions' => [
            ['type' => 'post_message', 'config' => ['channel_id' => $channel->id, 'body' => 'one']],
            ['type' => 'dm_user', 'config' => ['body' => 'two']],
        ],
    ])->json('data.id');

    $this->putJson("/api/servers/{$server->id}/automations/{$id}", [
        'name' => 'Greet',
        'trigger' => TriggerRegistry::MEMBER_JOINED,
        'actions' => [['type' => 'dm_user', 'config' => ['body' => 'only this']]],
    ])->assertOk()->assertJsonCount(1, 'data.actions');

    expect(Automation::find($id)->actions()->count())->toBe(1);
});

it('will not touch another server’s rule', function () {
    [$owner, $server] = ownerWithServer();
    [, $other] = ownerWithServer();
    $automation = Automation::create([
        'server_id' => $other->id,
        'name' => 'Theirs',
        'trigger' => TriggerRegistry::MEMBER_JOINED,
    ]);
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/automations/{$automation->id}/toggle")->assertNotFound();
    $this->deleteJson("/api/servers/{$server->id}/automations/{$automation->id}")->assertNotFound();

    expect(Automation::find($automation->id))->not->toBeNull();
});

it('toggles a rule off and on', function () {
    [$owner, $server] = ownerWithServer();
    $automation = Automation::create([
        'server_id' => $server->id,
        'name' => 'Greet',
        'trigger' => TriggerRegistry::MEMBER_JOINED,
    ]);
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/automations/{$automation->id}/toggle")
        ->assertOk()->assertJsonPath('data.enabled', false);
    $this->postJson("/api/servers/{$server->id}/automations/{$automation->id}/toggle")
        ->assertOk()->assertJsonPath('data.enabled', true);
});

it('serves a catalogue the builder can render forms from', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $data = $this->getJson("/api/servers/{$server->id}/bot/catalogue")->assertOk()->json('data');

    expect(collect($data['triggers'])->pluck('name'))->toContain(TriggerRegistry::MEMBER_JOINED);
    expect(collect($data['actions'])->pluck('name'))->toContain('post_message');
    // Each action carries its own field list, which is the point — the frontend keeps no
    // copy of these.
    expect(collect($data['actions'])->firstWhere('name', 'post_message')['schema'])->not->toBeEmpty();
    expect(collect($data['operators'])->pluck('name'))->toContain('contains');
});

/*
 * The welcome message: a settings form on the outside, an automation on the inside.
 */

it('stores the welcome message as a real member.joined rule', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/servers/{$server->id}/bot/welcome", [
        'channel_id' => $channel->id,
        'body' => 'Welcome {user}!',
    ])->assertOk()->assertJsonPath('data.body', 'Welcome {user}!');

    $automation = $server->automations()->where('builtin', Automation::BUILTIN_WELCOME)->with('actions')->first();

    expect($automation->trigger)->toBe(TriggerRegistry::MEMBER_JOINED);
    expect($automation->actions->first()->type)->toBe('post_message');
    expect($automation->actions->first()->option('channel_id'))->toBe($channel->id);
});

it('deletes the welcome rule rather than leaving a disabled one behind', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/servers/{$server->id}/bot/welcome", ['channel_id' => $channel->id, 'body' => 'hi'])->assertOk();
    $this->putJson("/api/servers/{$server->id}/bot/welcome", ['channel_id' => null])
        ->assertOk()->assertJsonPath('data.channel_id', null);

    expect($server->automations()->where('builtin', Automation::BUILTIN_WELCOME)->exists())->toBeFalse();
});

/*
 * Configuration, badges, and the bot that speaks.
 */

it('refuses a channel from another server in the configuration', function () {
    [$owner, $server] = ownerWithServer();
    [, , $elsewhere] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/servers/{$server->id}/bot/settings", ['mod_log_channel_id' => $elsewhere->id])
        ->assertStatus(422)->assertJsonValidationErrors('mod_log_channel_id');
});

it('reports the default prefix before anything has been configured', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    // The column's default applies on write, not to the model firstOrCreate hands back —
    // so this used to come out null and render an empty box for a value that is really `!`.
    $this->getJson("/api/servers/{$server->id}/bot/settings")
        ->assertOk()
        ->assertJsonPath('data.command_prefix', '!')
        ->assertJsonPath('data.mod_roles', []);
});

it('saves the command prefix and mod roles', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->putJson("/api/servers/{$server->id}/bot/settings", [
        'command_prefix' => '?',
        'mod_roles' => [Server::ROLE_ADMIN],
    ])->assertOk()
        ->assertJsonPath('data.command_prefix', '?')
        ->assertJsonPath('data.mod_roles', [Server::ROLE_ADMIN]);

    // A prefix has to be one non-space character, or every message becomes a command.
    $this->putJson("/api/servers/{$server->id}/bot/settings", ['command_prefix' => ' '])
        ->assertStatus(422);
});

it('refuses two badges with the same name in one server', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/badges", ['name' => 'Veteran'])->assertCreated();
    $this->postJson("/api/servers/{$server->id}/badges", ['name' => 'Veteran'])
        ->assertStatus(422)->assertJsonValidationErrors('name');

    // Another server may of course have one.
    [$otherOwner, $other] = ownerWithServer();
    Passport::actingAs($otherOwner);
    $this->postJson("/api/servers/{$other->id}/badges", ['name' => 'Veteran'])->assertCreated();
});

it('hands a badge out and takes it back', function () {
    [$owner, $server] = ownerWithServer();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => Server::ROLE_MEMBER]);
    $badge = Badge::create(['server_id' => $server->id, 'name' => 'Crew']);
    Passport::actingAs($owner);

    $this->putJson("/api/servers/{$server->id}/badges/{$badge->id}/members/{$member->id}")->assertOk();
    expect($badge->holders()->count())->toBe(1);

    $this->deleteJson("/api/servers/{$server->id}/badges/{$badge->id}/members/{$member->id}")->assertNoContent();
    expect($badge->holders()->count())->toBe(0);
});

it('moves the automation bot rather than letting a server have two', function () {
    [$owner, $server] = ownerWithServer();
    [$first] = botOn($server);
    [$second] = botOn($server);
    Passport::actingAs($owner);

    $this->putJson("/api/servers/{$server->id}/bots/{$first->id}/automations")->assertOk();
    $this->putJson("/api/servers/{$server->id}/bots/{$second->id}/automations")->assertOk();

    expect($server->bots()->where('runs_automations', true)->pluck('id')->all())->toBe([$second->id]);
    expect($server->automationBot()->id)->toBe($second->id);
});

it('tells the Bots screen which bot runs the automations', function () {
    [$owner, $server] = ownerWithServer();
    [$first] = botOn($server);
    [$second] = botOn($server);
    Passport::actingAs($owner);

    // Without this on the resource the radio button can't read its own state, and the
    // screen forgets which bot was chosen on every reload.
    $before = collect($this->getJson("/api/servers/{$server->id}/bots")->json('data'));
    expect($before->pluck('runs_automations')->all())->toBe([false, false]);

    $this->putJson("/api/servers/{$server->id}/bots/{$second->id}/automations")->assertOk();

    $after = collect($this->getJson("/api/servers/{$server->id}/bots")->json('data'))->keyBy('id');
    expect($after[$second->id]['runs_automations'])->toBeTrue();
    expect($after[$first->id]['runs_automations'])->toBeFalse();
});

it('says whether the server has any bots at all', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    // "No bot chosen" and "no bots exist" are different dead ends with different fixes.
    expect($this->getJson("/api/servers/{$server->id}/bot/overview")->json('data.has_bots'))->toBeFalse();

    botOn($server);

    expect($this->getJson("/api/servers/{$server->id}/bot/overview")->json('data.has_bots'))->toBeTrue();
});

it('shows a member’s badges on the channel roster', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $badge = Badge::create(['server_id' => $server->id, 'name' => 'Griefer', 'emoji' => '💀', 'color' => '#a855f7']);
    $badge->grantTo($owner);
    Passport::actingAs($owner);

    // The roster is where a badge is actually *seen*. Without this the whole feature is a
    // table nobody reads.
    $me = collect($this->getJson("/api/channels/{$channel->id}/members")->assertOk()->json('data'))
        ->firstWhere('id', $owner->id);

    expect($me['badges'])->toHaveCount(1);
    expect($me['badges'][0]['name'])->toBe('Griefer');
    expect($me['badges'][0]['emoji'])->toBe('💀');
});

it('does not leak badges from another server onto a roster', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    [, $other] = ownerWithServer();
    $other->members()->attach($owner->id, ['role' => Server::ROLE_MEMBER]);

    // A badge belongs to one server, so holding one elsewhere is none of this roster's
    // business — and scoping it in the query is what keeps that true.
    Badge::create(['server_id' => $other->id, 'name' => 'Elsewhere'])->grantTo($owner);
    Badge::create(['server_id' => $server->id, 'name' => 'Here'])->grantTo($owner);

    Passport::actingAs($owner);
    $me = collect($this->getJson("/api/channels/{$channel->id}/members")->json('data'))
        ->firstWhere('id', $owner->id);

    expect(collect($me['badges'])->pluck('name')->all())->toBe(['Here']);
});

it('keeps the automation bot owner-only', function () {
    [, $server] = ownerWithServer();
    [$bot] = botOn($server);
    Passport::actingAs(adminOn($server));

    $this->putJson("/api/servers/{$server->id}/bots/{$bot->id}/automations")->assertForbidden();
});
