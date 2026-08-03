<?php

use App\Models\Automation;
use App\Models\Badge;
use App\Models\Channel;
use App\Models\BotAuditLog;
use App\Models\Giveaway;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use App\Services\Automation\TriggerRegistry;
use App\Services\Giveaways\GiveawayDrawer;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

/*
 * Reaction roles and giveaways — the two features that are entirely built out of the engine.
 *
 * Neither has a mechanism of its own: a reaction role is a pair of automations on a message,
 * and a giveaway's entries arrive through one. So most of what's worth testing is that the
 * assembly is right, and that the round trip (react → badge → un-react → no badge) closes.
 */

/** A member who can actually react in the channel. */
function memberOf(Server $server): User
{
    $user = User::factory()->create();
    $server->members()->attach($user->id, ['role' => Server::ROLE_MEMBER]);

    return $user;
}

it('posts the announcement, seeds the emoji and writes both rules', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $bot = serverWithAutomationBot($server);
    $badge = Badge::create(['server_id' => $server->id, 'name' => 'Griefer', 'emoji' => '💀']);
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/reaction-roles", [
        'channel_id' => $channel->id,
        'body' => 'React to pick your role!',
        'pairs' => [['emoji' => '🎮', 'badge_id' => $badge->id]],
    ])->assertCreated();

    $message = $channel->messages()->latest('id')->first();
    expect($message->body)->toBe('React to pick your role!');
    // Seeded, so the emoji is already there to click.
    expect($message->reactions()->where('emoji', '🎮')->where('user_id', $bot->user->id)->exists())->toBeTrue();

    // Two rules: one to take the badge, one to give it up.
    $rules = $server->automations()->where('builtin', Automation::BUILTIN_REACTION_ROLE)->get();
    expect($rules)->toHaveCount(2);
    expect($rules->pluck('trigger')->sort()->values()->all())
        ->toBe([TriggerRegistry::REACTION_ADDED, TriggerRegistry::REACTION_REMOVED]);
});

it('grants the badge on reacting and takes it back on un-reacting', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    $badge = Badge::create(['server_id' => $server->id, 'name' => 'Griefer']);
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/reaction-roles", [
        'channel_id' => $channel->id,
        'body' => 'Pick a role',
        'pairs' => [['emoji' => '🎮', 'badge_id' => $badge->id]],
    ])->assertCreated();

    $message = $channel->messages()->latest('id')->first();
    $member = memberOf($server);
    Passport::actingAs($member);

    // The queue runs inline here on purpose: the round trip is the thing being tested, and
    // faking it would only prove a job was pushed.
    $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '🎮'])->assertOk();
    expect($badge->holders()->whereKey($member->id)->exists())->toBeTrue();

    $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '🎮'])->assertOk();
    expect($badge->holders()->whereKey($member->id)->exists())->toBeFalse();
});

it('ignores a different emoji on the same message', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    $badge = Badge::create(['server_id' => $server->id, 'name' => 'Griefer']);
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/reaction-roles", [
        'channel_id' => $channel->id,
        'body' => 'Pick', 'pairs' => [['emoji' => '🎮', 'badge_id' => $badge->id]],
    ])->assertCreated();

    $message = $channel->messages()->latest('id')->first();
    Passport::actingAs($member = memberOf($server));

    $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '🎨'])->assertOk();

    expect($badge->holders()->whereKey($member->id)->exists())->toBeFalse();
});

it('refuses two badges on one emoji', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    $a = Badge::create(['server_id' => $server->id, 'name' => 'A']);
    $b = Badge::create(['server_id' => $server->id, 'name' => 'B']);
    Passport::actingAs($owner);

    // Both rules would fire and the second badge would look like a bug rather than a choice.
    $this->postJson("/api/servers/{$server->id}/reaction-roles", [
        'channel_id' => $channel->id,
        'body' => 'Pick',
        'pairs' => [['emoji' => '🎮', 'badge_id' => $a->id], ['emoji' => '🎮', 'badge_id' => $b->id]],
    ])->assertStatus(422)->assertJsonValidationErrors('pairs');
});

it('refuses reaction roles when no bot has been chosen to speak', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $badge = Badge::create(['server_id' => $server->id, 'name' => 'Griefer']);
    Passport::actingAs($owner);

    // Refused up front rather than posting an announcement nobody can act on.
    $this->postJson("/api/servers/{$server->id}/reaction-roles", [
        'channel_id' => $channel->id, 'body' => 'Pick',
        'pairs' => [['emoji' => '🎮', 'badge_id' => $badge->id]],
    ])->assertStatus(422);

    expect($channel->messages()->count())->toBe(0);
});

it('removes both halves of a pair when the reaction role is deleted', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    $badge = Badge::create(['server_id' => $server->id, 'name' => 'Griefer']);
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/reaction-roles", [
        'channel_id' => $channel->id, 'body' => 'Pick',
        'pairs' => [['emoji' => '🎮', 'badge_id' => $badge->id]],
    ])->assertCreated();

    $message = $channel->messages()->latest('id')->first();

    $this->deleteJson("/api/servers/{$server->id}/reaction-roles/{$message->id}")->assertNoContent();

    // Leaving the revoke half would give a badge nobody could give up.
    expect($server->automations()->where('builtin', Automation::BUILTIN_REACTION_ROLE)->count())->toBe(0);
});

it('survives the badge a reaction role names being deleted', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    $badge = Badge::create(['server_id' => $server->id, 'name' => 'Griefer']);
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/reaction-roles", [
        'channel_id' => $channel->id, 'body' => 'Pick',
        'pairs' => [['emoji' => '🎮', 'badge_id' => $badge->id]],
    ])->assertCreated();

    // Deleting a badge is allowed and merely makes the rule start failing — it must not
    // take the page that lists the rule down with it. This 500'd, which broke the whole
    // dashboard for anybody who deleted a badge a reaction role was using.
    $this->deleteJson("/api/servers/{$server->id}/badges/{$badge->id}")->assertNoContent();

    $this->getJson("/api/servers/{$server->id}/reaction-roles")
        ->assertOk()
        ->assertJsonPath('data.0.pairs.0.badge_name', null);
});

it('posts a reaction role to every channel asked for', function () {
    [$owner, $server, $first] = ownerWithChannel();
    $second = Channel::factory()->create(['server_id' => $server->id]);
    serverWithAutomationBot($server);
    $badge = Badge::create(['server_id' => $server->id, 'name' => 'Griefer']);
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/reaction-roles", [
        'channel_id' => $first->id,
        'extra_channel_ids' => [$second->id],
        'body' => 'Pick a role',
        'pairs' => [['emoji' => '🎮', 'badge_id' => $badge->id]],
    ])->assertCreated();

    expect($first->messages()->count())->toBe(1);
    expect($second->messages()->count())->toBe(1);

    // Reacting in *either* room grants the same badge — that's the point of the feature.
    Passport::actingAs($member = memberOf($server));
    $this->postJson("/api/messages/{$second->messages()->first()->id}/reactions", ['emoji' => '🎮'])->assertOk();

    expect($badge->holders()->whereKey($member->id)->exists())->toBeTrue();
});

it('posts a giveaway to several channels but counts one entry per person', function () {
    [$owner, $server, $first] = ownerWithChannel();
    $second = Channel::factory()->create(['server_id' => $server->id]);
    serverWithAutomationBot($server);

    Passport::actingAs($owner);
    $id = $this->postJson("/api/servers/{$server->id}/giveaways", [
        'channel_id' => $first->id,
        'extra_channel_ids' => [$second->id],
        'prize' => 'A Steam key',
        'ends_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated()->json('data.id');

    $giveaway = Giveaway::find($id);
    expect($first->messages()->count())->toBe(1);
    expect($second->messages()->count())->toBe(1);

    // Finding it twice is not two chances.
    Passport::actingAs(memberOf($server));
    $this->postJson("/api/messages/{$first->messages()->first()->id}/reactions", ['emoji' => '🎉'])->assertOk();
    $this->postJson("/api/messages/{$second->messages()->first()->id}/reactions", ['emoji' => '🎉'])->assertOk();

    expect($giveaway->entries()->count())->toBe(1);
});

it('refuses the whole set rather than posting to only some channels', function () {
    [$owner, $server, $first] = ownerWithChannel();
    $private = Channel::factory()->create(['server_id' => $server->id, 'is_private' => true]);
    serverWithAutomationBot($server);
    $badge = Badge::create(['server_id' => $server->id, 'name' => 'Griefer']);
    Passport::actingAs($owner);

    // Half a set would leave the badge reachable from some rooms and not others, with
    // nothing on screen to say which.
    $this->postJson("/api/servers/{$server->id}/reaction-roles", [
        'channel_id' => $first->id,
        'extra_channel_ids' => [$private->id],
        'body' => 'Pick', 'pairs' => [['emoji' => '🎮', 'badge_id' => $badge->id]],
    ])->assertStatus(422);

    expect($first->messages()->count())->toBe(0);
});

it('reposts a reaction role and moves the rules onto the new message', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $bot = serverWithAutomationBot($server);
    $badge = Badge::create(['server_id' => $server->id, 'name' => 'Griefer']);
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/reaction-roles", [
        'channel_id' => $channel->id, 'body' => 'Pick a role',
        'pairs' => [['emoji' => '🎮', 'badge_id' => $badge->id]],
    ])->assertCreated();

    $original = $channel->messages()->latest('id')->first();

    $this->postJson("/api/servers/{$server->id}/reaction-roles/{$original->id}/resend")->assertOk();

    $reposted = $channel->messages()->latest('id')->first();
    expect($reposted->id)->not->toBe($original->id);
    expect($reposted->body)->toBe('Pick a role');
    expect($reposted->reactions()->where('emoji', '🎮')->exists())->toBeTrue();

    // Reacting to the *new* post grants the badge; the old one no longer means anything.
    Passport::actingAs($member = memberOf($server));
    $this->postJson("/api/messages/{$reposted->id}/reactions", ['emoji' => '🎮'])->assertOk();
    expect($badge->holders()->whereKey($member->id)->exists())->toBeTrue();

    $badge->revokeFrom($member);
    $this->postJson("/api/messages/{$original->id}/reactions", ['emoji' => '🎮'])->assertOk();
    expect($badge->holders()->whereKey($member->id)->exists())->toBeFalse();
});

/*
 * Giveaways.
 */

function giveawayOn(Server $server, User $owner, int $channelId, array $overrides = []): array
{
    Passport::actingAs($owner);

    $response = test()->postJson("/api/servers/{$server->id}/giveaways", array_merge([
        'channel_id' => $channelId,
        'prize' => 'A Steam key',
        'ends_at' => now()->addDay()->toIso8601String(),
    ], $overrides))->assertCreated();

    return [Giveaway::find($response->json('data.id')), $response];
}

it('announces a giveaway and wires reacting to it as an entry', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);

    [$giveaway] = giveawayOn($server, $owner, $channel->id);

    expect($channel->messages()->latest('id')->first()->body)->toContain('A Steam key');
    expect($giveaway->message_id)->not->toBeNull();

    // The entry mechanism is a rule, not a listener of its own.
    $rule = $server->automations()->where('builtin', Automation::BUILTIN_GIVEAWAY)->with('actions')->first();
    expect($rule->trigger)->toBe(TriggerRegistry::REACTION_ADDED);
    expect($rule->actions->first()->type)->toBe('enter_giveaway');
});

it('enters somebody who reacts, once', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    [$giveaway] = giveawayOn($server, $owner, $channel->id);

    Passport::actingAs($member = memberOf($server));
    $this->postJson("/api/messages/{$giveaway->message_id}/reactions", ['emoji' => '🎉'])->assertOk();

    expect($giveaway->entries()->count())->toBe(1);

    // Un-react then react again — still one entry, not two chances.
    $this->postJson("/api/messages/{$giveaway->message_id}/reactions", ['emoji' => '🎉'])->assertOk();
    $this->postJson("/api/messages/{$giveaway->message_id}/reactions", ['emoji' => '🎉'])->assertOk();

    expect($giveaway->entries()->count())->toBe(1);
    expect($giveaway->entries()->first()->user_id)->toBe($member->id);
});

it('keeps a badge-gated giveaway to badge holders', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    $badge = Badge::create(['server_id' => $server->id, 'name' => 'Crew']);
    [$giveaway] = giveawayOn($server, $owner, $channel->id, ['required_badge_id' => $badge->id]);

    Passport::actingAs($member = memberOf($server));
    $this->postJson("/api/messages/{$giveaway->message_id}/reactions", ['emoji' => '🎉'])->assertOk();
    expect($giveaway->entries()->count())->toBe(0);

    // Recorded as a skip rather than a failure — the rule did its job.
    expect(BotAuditLog::where('action', 'enter_giveaway')->first()->outcome)->toBe(BotAuditLog::SKIPPED);

    $badge->grantTo($member);
    $this->postJson("/api/messages/{$giveaway->message_id}/reactions", ['emoji' => '🎉'])->assertOk();
    $this->postJson("/api/messages/{$giveaway->message_id}/reactions", ['emoji' => '🎉'])->assertOk();

    expect($giveaway->entries()->count())->toBe(1);
});

it('draws distinct winners and announces them', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    [$giveaway] = giveawayOn($server, $owner, $channel->id, ['winner_count' => 2]);

    foreach (range(1, 4) as $i) {
        $giveaway->entries()->create(['user_id' => memberOf($server)->id]);
    }

    app(GiveawayDrawer::class)->draw($giveaway);

    $winners = $giveaway->entries()->where('won', true)->get();
    expect($winners)->toHaveCount(2);
    // Drawn in one pass, so a two-winner giveaway can't name the same person twice.
    expect($winners->pluck('user_id')->unique())->toHaveCount(2);

    expect($channel->messages()->latest('id')->first()->body)->toContain('A Steam key');
    expect($giveaway->fresh()->drawn_at)->not->toBeNull();
});

it('says plainly when nobody entered', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    [$giveaway] = giveawayOn($server, $owner, $channel->id);

    app(GiveawayDrawer::class)->draw($giveaway);

    expect($channel->messages()->latest('id')->first()->body)->toContain('nobody entered');
});

it('draws every giveaway whose time is up, and no others', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);

    [$due] = giveawayOn($server, $owner, $channel->id, ['prize' => 'Due one']);
    [$later] = giveawayOn($server, $owner, $channel->id, ['prize' => 'Later one']);
    $due->forceFill(['ends_at' => now()->subMinute()])->save();

    $this->artisan('bot:draw-giveaways')->assertSuccessful();

    expect($due->fresh()->drawn_at)->not->toBeNull();
    expect($later->fresh()->drawn_at)->toBeNull();
});

it('stops entries once a giveaway has closed', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    [$giveaway] = giveawayOn($server, $owner, $channel->id);

    // Past its end but not yet drawn — the minute the runner hasn't reached.
    $giveaway->forceFill(['ends_at' => now()->subMinute()])->save();

    Passport::actingAs(memberOf($server));
    $this->postJson("/api/messages/{$giveaway->message_id}/reactions", ['emoji' => '🎉'])->assertOk();

    expect($giveaway->entries()->count())->toBe(0);
});

it('cancels rather than deletes, and stops reacting from meaning anything', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    [$giveaway] = giveawayOn($server, $owner, $channel->id);

    Passport::actingAs($owner);
    $this->deleteJson("/api/servers/{$server->id}/giveaways/{$giveaway->id}")->assertNoContent();

    expect($giveaway->fresh()->cancelled_at)->not->toBeNull();
    expect($giveaway->fresh()->status())->toBe('cancelled');
    expect($server->automations()->where('builtin', Automation::BUILTIN_GIVEAWAY)->count())->toBe(0);
});

it('reposts a giveaway and keeps the entries it already has', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    [$giveaway] = giveawayOn($server, $owner, $channel->id);

    Passport::actingAs($early = memberOf($server));
    $this->postJson("/api/messages/{$giveaway->message_id}/reactions", ['emoji' => '🎉'])->assertOk();
    $original = $giveaway->fresh()->message_id;

    Passport::actingAs($owner);
    $this->postJson("/api/servers/{$server->id}/giveaways/{$giveaway->id}/resend")->assertOk();

    $reposted = $giveaway->fresh()->message_id;
    expect($reposted)->not->toBe($original);
    // A repost moves where new entries come from; it doesn't restart the draw.
    expect($giveaway->entries()->count())->toBe(1);

    Passport::actingAs($late = memberOf($server));
    $this->postJson("/api/messages/{$reposted}/reactions", ['emoji' => '🎉'])->assertOk();
    expect($giveaway->entries()->count())->toBe(2);
});

it('will not repost a giveaway that has closed', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    [$giveaway] = giveawayOn($server, $owner, $channel->id);
    $giveaway->forceFill(['ends_at' => now()->subMinute()])->save();

    Passport::actingAs($owner);
    $this->postJson("/api/servers/{$server->id}/giveaways/{$giveaway->id}/resend")->assertStatus(422);
});

it('refuses to draw the same giveaway twice', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    [$giveaway] = giveawayOn($server, $owner, $channel->id);

    Passport::actingAs($owner);
    $this->postJson("/api/servers/{$server->id}/giveaways/{$giveaway->id}/draw")->assertOk();
    $this->postJson("/api/servers/{$server->id}/giveaways/{$giveaway->id}/draw")->assertStatus(422);
});

/*
 * Logging.
 */

it('pages and filters the audit log', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    BotAuditLog::create(['server_id' => $server->id, 'action' => 'post_message', 'outcome' => BotAuditLog::OK]);
    BotAuditLog::create(['server_id' => $server->id, 'action' => 'grant_badge', 'outcome' => BotAuditLog::FAILED, 'message' => 'gone']);

    $all = $this->getJson("/api/servers/{$server->id}/bot/log")->assertOk();
    expect($all->json('meta.total'))->toBe(2);

    // The filter that earns its place: finding the handful of failures among thousands of
    // successes is not something scrolling does.
    $failed = $this->getJson("/api/servers/{$server->id}/bot/log?outcome=failed")->assertOk();
    expect($failed->json('meta.total'))->toBe(1);
    expect($failed->json('data.0.message'))->toBe('gone');
});

it('prunes audit lines past the retention window', function () {
    [, $server] = ownerWithServer();

    BotAuditLog::create(['server_id' => $server->id, 'action' => 'old', 'outcome' => BotAuditLog::OK])
        ->forceFill(['created_at' => now()->subDays(40)])->save();
    BotAuditLog::create(['server_id' => $server->id, 'action' => 'recent', 'outcome' => BotAuditLog::OK]);

    $this->artisan('bot:prune-audit-log')->assertSuccessful();

    expect(BotAuditLog::pluck('action')->all())->toBe(['recent']);
});

it('keeps the log to the server it belongs to', function () {
    [$owner, $server] = ownerWithServer();
    [, $other] = ownerWithServer();
    BotAuditLog::create(['server_id' => $other->id, 'action' => 'theirs', 'outcome' => BotAuditLog::OK]);
    Passport::actingAs($owner);

    expect($this->getJson("/api/servers/{$server->id}/bot/log")->json('meta.total'))->toBe(0);
});
