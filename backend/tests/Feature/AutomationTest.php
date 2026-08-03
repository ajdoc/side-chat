<?php

use App\Jobs\RunAutomation;
use App\Models\Automation;
use App\Models\Badge;
use App\Models\Bot;
use App\Models\BotAuditLog;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerJoinRequest;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\TriggerRegistry;
use App\Support\Automation\AutomationContext;
use App\Support\Automation\ConditionEvaluator;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

/*
 * Automations: which rules fire, and what running one actually does.
 *
 * Split the way the engine is split. Fan-out (`fire`) happens on the request and its whole
 * job is deciding *whether* — so those tests fake the queue and assert on what was pushed.
 * Running (`run`) happens on a worker and its job is doing the thing, so those call it
 * directly and assert on the world afterwards. Testing them together would mostly be
 * testing Laravel's queue.
 */

/** A server whose automations have a bot to speak as. */
function serverWithAutomationBot(Server $server): Bot
{
    [$bot] = botOn($server);
    $bot->update(['runs_automations' => true]);

    return $bot->fresh()->load('user');
}

/**
 * A rule, with its actions.
 *
 * @param  array<int, array{0: string, 1: array<string, mixed>}>  $actions
 */
function automationOn(Server $server, string $trigger, array $actions, array $attributes = []): Automation
{
    $automation = Automation::create(array_merge([
        'server_id' => $server->id,
        'name' => 'Test rule',
        'trigger' => $trigger,
    ], $attributes));

    foreach ($actions as $position => [$type, $config]) {
        $automation->actions()->create(['type' => $type, 'config' => $config, 'position' => $position]);
    }

    return $automation->load('actions');
}

function fireTrigger(Server $server, string $trigger, array $data, int $depth = 0): int
{
    return app(AutomationEngine::class)->fire(new AutomationContext($server->id, $trigger, $data, $depth));
}

it('queues a rule whose trigger fires', function () {
    Queue::fake();
    [, $server] = ownerWithServer();
    $automation = automationOn($server, TriggerRegistry::MEMBER_JOINED, [['post_message', ['body' => 'hi']]]);

    expect(fireTrigger($server, TriggerRegistry::MEMBER_JOINED, ['user_id' => 1]))->toBe(1);

    Queue::assertPushed(RunAutomation::class, fn (RunAutomation $job) => $job->automationId === $automation->id);
});

it('leaves a disabled rule alone', function () {
    Queue::fake();
    [, $server] = ownerWithServer();
    automationOn($server, TriggerRegistry::MEMBER_JOINED, [['post_message', ['body' => 'hi']]], ['enabled' => false]);

    expect(fireTrigger($server, TriggerRegistry::MEMBER_JOINED, ['user_id' => 1]))->toBe(0);

    Queue::assertNothingPushed();
});

it('never runs one server’s rule for another server’s event', function () {
    Queue::fake();
    [, $server] = ownerWithServer();
    [, $other] = ownerWithServer();
    automationOn($other, TriggerRegistry::MEMBER_JOINED, [['post_message', ['body' => 'hi']]]);

    expect(fireTrigger($server, TriggerRegistry::MEMBER_JOINED, ['user_id' => 1]))->toBe(0);
});

it('filters on conditions', function () {
    Queue::fake();
    [, $server] = ownerWithServer();
    automationOn($server, TriggerRegistry::MESSAGE_CREATED, [['add_reaction', ['emoji' => '👀']]], [
        'conditions' => [['field' => 'body', 'operator' => 'contains', 'value' => 'deploy']],
    ]);

    expect(fireTrigger($server, TriggerRegistry::MESSAGE_CREATED, ['body' => 'time to deploy']))->toBe(1);
    expect(fireTrigger($server, TriggerRegistry::MESSAGE_CREATED, ['body' => 'good morning']))->toBe(0);
});

it('stops fanning out once rules have caused each other too many times', function () {
    Queue::fake();
    [, $server] = ownerWithServer();
    automationOn($server, TriggerRegistry::BADGE_GRANTED, [['post_message', ['body' => 'nice']]]);

    expect(fireTrigger($server, TriggerRegistry::BADGE_GRANTED, ['user_id' => 1], AutomationEngine::MAX_DEPTH))->toBe(0);
});

it('runs actions in order and records a line for each', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    $badge = Badge::create(['server_id' => $server->id, 'name' => 'Veteran']);

    $automation = automationOn($server, TriggerRegistry::MEMBER_JOINED, [
        ['grant_badge', ['badge_id' => $badge->id]],
        ['post_message', ['channel_id' => $channel->id, 'body' => 'Welcome {user} to {server}!']],
    ]);

    app(AutomationEngine::class)->run($automation, new AutomationContext(
        $server->id,
        TriggerRegistry::MEMBER_JOINED,
        ['user_id' => $owner->id, 'user_name' => $owner->name],
    ));

    expect($badge->holders()->whereKey($owner->id)->exists())->toBeTrue();
    expect($channel->messages()->latest('id')->first()->body)
        ->toBe("Welcome {$owner->name} to {$server->name}!");

    $log = BotAuditLog::where('automation_id', $automation->id)->orderBy('id')->get();
    expect($log->pluck('action')->all())->toBe(['grant_badge', 'post_message']);
    expect($log->pluck('outcome')->unique()->all())->toBe([BotAuditLog::OK]);
    expect($automation->fresh()->run_count)->toBe(1);
});

it('carries on after an action fails, and says which one', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);

    $automation = automationOn($server, TriggerRegistry::MEMBER_JOINED, [
        // A badge that doesn't exist — the failure case an owner is most likely to create,
        // by deleting a badge a rule still names.
        ['grant_badge', ['badge_id' => 999999]],
        ['post_message', ['channel_id' => $channel->id, 'body' => 'still posted']],
    ]);

    app(AutomationEngine::class)->run($automation, new AutomationContext(
        $server->id,
        TriggerRegistry::MEMBER_JOINED,
        ['user_id' => $owner->id, 'user_name' => $owner->name],
    ));

    expect($channel->messages()->latest('id')->first()->body)->toBe('still posted');
    expect(BotAuditLog::where('action', 'grant_badge')->first()->outcome)->toBe(BotAuditLog::FAILED);
});

it('skips rather than fails when the server has no bot to speak as', function () {
    [$owner, $server, $channel] = ownerWithChannel();

    $automation = automationOn($server, TriggerRegistry::MEMBER_JOINED, [
        ['post_message', ['channel_id' => $channel->id, 'body' => 'hello']],
    ]);

    app(AutomationEngine::class)->run($automation, new AutomationContext(
        $server->id,
        TriggerRegistry::MEMBER_JOINED,
        ['user_id' => $owner->id],
    ));

    expect($channel->messages()->count())->toBe(0);
    expect(BotAuditLog::first()->outcome)->toBe(BotAuditLog::SKIPPED);
});

it('will not let a rule demote the owner', function () {
    [$owner, $server] = ownerWithServer();
    $automation = automationOn($server, TriggerRegistry::MEMBER_JOINED, [['set_role', ['role' => 'member']]]);

    app(AutomationEngine::class)->run($automation, new AutomationContext(
        $server->id,
        TriggerRegistry::MEMBER_JOINED,
        ['user_id' => $owner->id],
    ));

    expect($server->fresh()->owner_id)->toBe($owner->id);
    expect(BotAuditLog::first()->outcome)->toBe(BotAuditLog::SKIPPED);
});

it('will not post into a private channel the bot was kept out of', function () {
    [$owner, $server] = ownerWithServer();
    $bot = serverWithAutomationBot($server);
    $private = Channel::factory()->create(['server_id' => $server->id, 'is_private' => true]);
    $private->allowedMembers()->attach($owner->id);

    $automation = automationOn($server, TriggerRegistry::MEMBER_JOINED, [
        ['post_message', ['channel_id' => $private->id, 'body' => 'secret']],
    ]);

    app(AutomationEngine::class)->run($automation, new AutomationContext(
        $server->id,
        TriggerRegistry::MEMBER_JOINED,
        ['user_id' => $owner->id],
    ));

    expect($private->messages()->count())->toBe(0);
    expect(BotAuditLog::first()->outcome)->toBe(BotAuditLog::SKIPPED);
});

it('grants a badge once, and fires badge.granted only that time', function () {
    Queue::fake();
    [$owner, $server] = ownerWithServer();
    $badge = Badge::create(['server_id' => $server->id, 'name' => 'Crew']);
    automationOn($server, TriggerRegistry::BADGE_GRANTED, [['post_message', ['body' => 'welcome aboard']]]);

    $grant = automationOn($server, TriggerRegistry::MEMBER_JOINED, [['grant_badge', ['badge_id' => $badge->id]]]);
    $context = new AutomationContext($server->id, TriggerRegistry::MEMBER_JOINED, ['user_id' => $owner->id]);

    app(AutomationEngine::class)->run($grant, $context);
    app(AutomationEngine::class)->run($grant, $context);

    expect($badge->holders()->count())->toBe(1);
    Queue::assertPushed(RunAutomation::class, 1);
    expect(BotAuditLog::where('action', 'grant_badge')->pluck('outcome')->all())
        ->toBe([BotAuditLog::OK, BotAuditLog::SKIPPED]);
});

/*
 * The seams. Each of these asserts that the *real* path — an HTTP request, an action —
 * reaches the engine, because a trigger that only fires when a test calls fire() is a
 * trigger that doesn't work.
 */

it('fires member.joined when a join request is approved', function () {
    Queue::fake();
    [$owner, $server] = ownerWithServer();
    $joiner = User::factory()->create();
    $request = ServerJoinRequest::create(['server_id' => $server->id, 'user_id' => $joiner->id]);
    automationOn($server, TriggerRegistry::MEMBER_JOINED, [['post_message', ['body' => 'hi {user}']]]);
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/join-requests/approve", ['request_ids' => [$request->id]])
        ->assertOk();

    Queue::assertPushed(RunAutomation::class, function (RunAutomation $job) use ($joiner) {
        return $job->context['data']['user_id'] === $joiner->id
            && $job->context['trigger'] === TriggerRegistry::MEMBER_JOINED;
    });
});

it('fires message.created for a person, and never for a bot', function () {
    Queue::fake();
    [$owner, $server, $channel] = ownerWithChannel();
    [$bot, $token] = botOn($server);
    automationOn($server, TriggerRegistry::MESSAGE_CREATED, [['add_reaction', ['emoji' => '👀']]]);

    Passport::actingAs($owner);
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'hello'])->assertCreated();
    Queue::assertPushed(RunAutomation::class, 1);

    // The loop guard. A bot's own message must never come back round as a trigger — see
    // RunMessageAutomations.
    $this->postJson("/api/bot/channels/{$channel->id}/messages", ['body' => 'beep'], botAuth($token))
        ->assertCreated();
    Queue::assertPushed(RunAutomation::class, 1);
});

it('fires reaction.added on the way on, but not on the way off', function () {
    Queue::fake();
    [$owner, $server, $channel] = ownerWithChannel();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    automationOn($server, TriggerRegistry::REACTION_ADDED, [['post_message', ['body' => 'noted']]]);
    Passport::actingAs($owner);

    $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '🎮'])->assertOk();
    Queue::assertPushed(RunAutomation::class, 1);

    $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '🎮'])->assertOk();
    Queue::assertPushed(RunAutomation::class, 1);
});

it('fires member.role_assigned only when the role actually changed', function () {
    Queue::fake();
    [$owner, $server] = ownerWithServer();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => Server::ROLE_MEMBER]);
    automationOn($server, TriggerRegistry::ROLE_ASSIGNED, [['post_message', ['body' => 'congrats']]]);
    Passport::actingAs($owner);

    $this->patchJson("/api/servers/{$server->id}/members/{$member->id}/role", ['role' => 'admin'])->assertOk();
    Queue::assertPushed(RunAutomation::class, 1);

    // Same role again — nobody was promoted.
    $this->patchJson("/api/servers/{$server->id}/members/{$member->id}/role", ['role' => 'admin'])->assertOk();
    Queue::assertPushed(RunAutomation::class, 1);
});

/*
 * The condition language. Small enough to test exhaustively, and worth it — these run
 * against rules people wrote months ago.
 */

it('evaluates the condition operators', function (string $operator, mixed $expected, mixed $actual, bool $passes) {
    $context = new AutomationContext(1, 'x', ['field' => $actual]);

    expect(app(ConditionEvaluator::class)->passes(
        [['field' => 'field', 'operator' => $operator, 'value' => $expected]],
        $context,
    ))->toBe($passes);
})->with([
    ['equals', 'yes', 'yes', true],
    ['equals', 'yes', 'no', false],
    ['not_equals', 'yes', 'no', true],
    ['contains', 'DEPLOY', 'time to deploy', true],
    ['not_contains', 'deploy', 'good morning', true],
    ['matches', 'ship*', 'shipping now', true],
    ['matches', 'ship*', 'not shipping', false],
    ['in', 'a,b,c', 'b', true],
    ['in', 'a,b,c', 'd', false],
    ['gt', 5, 7, true],
    ['gt', 5, 'seven', false],
    ['lt', 5, 3, true],
    ['is_empty', null, '', true],
    ['is_not_empty', null, 'x', true],
    // An operator from a future version, or a typo in a hand-edited row. Not a pass.
    ['sideways', 'x', 'x', false],
]);

it('passes an unfiltered rule and fails an unreadable one', function () {
    $context = new AutomationContext(1, 'x', ['a' => 'b']);
    $evaluator = app(ConditionEvaluator::class);

    expect($evaluator->passes(null, $context))->toBeTrue();
    expect($evaluator->passes([], $context))->toBeTrue();
    // A field the trigger didn't supply hasn't matched — it hasn't errored either.
    expect($evaluator->passes([['field' => 'nope', 'operator' => 'equals', 'value' => 'x']], $context))->toBeFalse();
    expect($evaluator->passes([['field' => 'a']], $context))->toBeFalse();
});
