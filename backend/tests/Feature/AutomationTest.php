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

it('requires every filter under “all”, and any one under “any”', function () {
    Queue::fake();
    [, $server] = ownerWithServer();

    $conditions = [
        ['field' => 'user_name', 'operator' => 'equals', 'value' => 'Ada'],
        ['field' => 'user_email', 'operator' => 'contains', 'value' => 'example.com'],
    ];

    $all = automationOn($server, TriggerRegistry::MEMBER_JOINED, [['post_message', ['body' => 'hi']]], [
        'conditions' => $conditions,
        'condition_match' => 'all',
    ]);
    $any = automationOn($server, TriggerRegistry::MEMBER_JOINED, [['post_message', ['body' => 'hi']]], [
        'conditions' => $conditions,
        'condition_match' => 'any',
    ]);

    // Only the second holds: "all" rejects, "any" accepts. Both rules see the same event, so
    // exactly one of them should be queued.
    expect(fireTrigger($server, TriggerRegistry::MEMBER_JOINED, [
        'user_name' => 'Grace',
        'user_email' => 'grace@example.com',
    ]))->toBe(1);

    Queue::assertPushed(RunAutomation::class, fn (RunAutomation $job) => $job->automationId === $any->id);
    Queue::assertNotPushed(RunAutomation::class, fn (RunAutomation $job) => $job->automationId === $all->id);

    // Neither holds — "any" refuses too, so it isn't just "always true".
    expect(fireTrigger($server, TriggerRegistry::MEMBER_JOINED, [
        'user_name' => 'Grace',
        'user_email' => 'grace@elsewhere.test',
    ]))->toBe(0);
});

it('runs an unfiltered rule whichever way it is set to match', function () {
    Queue::fake();
    [, $server] = ownerWithServer();

    // "Any of nothing" is false under a strict reading, and that reading would mean deleting
    // your last filter silently switched the rule off.
    automationOn($server, TriggerRegistry::MEMBER_JOINED, [['post_message', ['body' => 'hi']]], [
        'condition_match' => 'any',
    ]);

    expect(fireTrigger($server, TriggerRegistry::MEMBER_JOINED, ['user_id' => 1]))->toBe(1);
});

it('defaults a rule to “all”, and round-trips the choice', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $payload = [
        'name' => 'Either name',
        'trigger' => TriggerRegistry::MEMBER_JOINED,
        'conditions' => [
            ['field' => 'user_name', 'operator' => 'equals', 'value' => 'Ada'],
            ['field' => 'user_name', 'operator' => 'equals', 'value' => 'Grace'],
        ],
        'actions' => [['type' => 'post_message', 'config' => ['channel_id' => $channel->id, 'body' => 'hi']]],
    ];

    // Existing rules never sent this field and have always meant "all" — so that's the default.
    $this->postJson("/api/servers/{$server->id}/automations", $payload)
        ->assertCreated()->assertJsonPath('data.condition_match', 'all');

    $this->postJson("/api/servers/{$server->id}/automations", [...$payload, 'condition_match' => 'any'])
        ->assertCreated()->assertJsonPath('data.condition_match', 'any');

    $this->postJson("/api/servers/{$server->id}/automations", [...$payload, 'condition_match' => 'maybe'])
        ->assertStatus(422)->assertJsonValidationErrors('condition_match');
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

it('records what the event contained, so a filter that never matches can be debugged', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);

    $automation = automationOn($server, TriggerRegistry::MEMBER_JOINED, [
        ['post_message', ['channel_id' => $channel->id, 'body' => 'hi']],
    ]);

    app(AutomationEngine::class)->run($automation, new AutomationContext(
        $server->id,
        TriggerRegistry::MEMBER_JOINED,
        ['user_id' => $owner->id, 'user_name' => $owner->name],
    ));

    // A rule rejected by its filter writes nothing at all — it's skipped before it runs — so
    // the only way to find out what a filter was compared against is to see the values from
    // a run that did happen.
    $line = BotAuditLog::where('automation_id', $automation->id)->first();

    expect($line->context['event']['user_name'])->toBe($owner->name);
    // Keyed by channel: one step can post in several, so the result is a map rather than
    // a single id.
    expect($line->context['result']['message_ids'])->toHaveKey((string) $channel->id);
});

it('supplies name, nickname and email on every trigger about a person', function () {
    Queue::fake();
    [$owner, $server] = ownerWithServer();
    $joiner = User::factory()->create(['name' => 'Robert Smith', 'email' => 'rob@example.com']);
    $request = ServerJoinRequest::create(['server_id' => $server->id, 'user_id' => $joiner->id]);
    automationOn($server, TriggerRegistry::MEMBER_JOINED, [['post_message', ['body' => 'hi']]]);
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/join-requests/approve", ['request_ids' => [$request->id]])
        ->assertOk();

    Queue::assertPushed(RunAutomation::class, function (RunAutomation $job) use ($joiner) {
        $data = $job->context['data'];

        // The whole set, every time. An id that arrives while a name doesn't is what made
        // filtering on user_id work and user_name silently match nothing.
        return $data['user_id'] === $joiner->id
            && $data['user_name'] === 'Robert Smith'
            && $data['user_email'] === 'rob@example.com'
            // No server nickname set, so it falls back to the account name rather than empty.
            && $data['user_nickname'] === 'Robert Smith';
    });
});

it('offers exactly the fields the context supplies', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $triggers = collect($this->getJson("/api/servers/{$server->id}/bot/catalogue")->json('data.triggers'));

    // A field the builder offers but nothing supplies is a filter that can never match —
    // so the advertised list and the real one have to be the same list.
    expect($triggers->firstWhere('name', TriggerRegistry::MEMBER_JOINED)['fields'])
        ->toBe(['user_id', 'user_name', 'user_nickname', 'user_email']);
});

it('posts one step to several channels at once', function () {
    [$owner, $server, $first] = ownerWithChannel();
    $second = Channel::factory()->create(['server_id' => $server->id]);
    serverWithAutomationBot($server);

    // One step, three rooms — not three steps. Steps are ordered because some depend on the
    // one before; posting the same words in parallel has no such ordering.
    $automation = automationOn($server, TriggerRegistry::MEMBER_JOINED, [
        ['post_message', [
            'channel_id' => $first->id,
            'extra_channel_ids' => [$second->id],
            'body' => 'Welcome {user}!',
        ]],
    ]);

    app(AutomationEngine::class)->run($automation, new AutomationContext(
        $server->id,
        TriggerRegistry::MEMBER_JOINED,
        ['user_id' => $owner->id, 'user_name' => $owner->name],
    ));

    expect($first->messages()->latest('id')->first()->body)->toBe("Welcome {$owner->name}!");
    expect($second->messages()->latest('id')->first()->body)->toBe("Welcome {$owner->name}!");

    // One step, so one audit line — carrying both messages rather than pretending to be two.
    $log = BotAuditLog::where('automation_id', $automation->id)->get();
    expect($log)->toHaveCount(1);
    expect($log->first()->context['result']['message_ids'])->toHaveCount(2);
});

it('keeps posting to the rest when the bot is shut out of one channel', function () {
    [$owner, $server, $open] = ownerWithChannel();
    $private = Channel::factory()->create(['server_id' => $server->id, 'is_private' => true]);
    serverWithAutomationBot($server);

    $automation = automationOn($server, TriggerRegistry::MEMBER_JOINED, [
        ['post_message', ['channel_id' => $open->id, 'extra_channel_ids' => [$private->id], 'body' => 'hi']],
    ]);

    app(AutomationEngine::class)->run($automation, new AutomationContext(
        $server->id, TriggerRegistry::MEMBER_JOINED, ['user_id' => $owner->id],
    ));

    expect($open->messages()->count())->toBe(1);
    expect($private->messages()->count())->toBe(0);

    // Recorded as done, but saying which room missed out — silence about it would be worse
    // than either a plain success or a plain failure.
    $line = BotAuditLog::where('automation_id', $automation->id)->first();
    expect($line->outcome)->toBe(BotAuditLog::OK);
    expect($line->message)->toContain($private->name);
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

/*
 * The productivity apps: the triggers a board and a tracker fire, and the two actions that
 * write back into them. Until these, a rule could only ever see and say things in chat.
 */

it('fires a trigger when a card is added, and again when it moves', function () {
    Queue::fake();
    [$owner, $server, $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $card = $this->postJson("/api/channels/{$channel->id}/kanban/cards", ['text' => 'ship the thing'])
        ->assertCreated()->json('data');

    Queue::assertNothingPushed(); // no rule listens yet

    automationOn($server, TriggerRegistry::KANBAN_CARD_CREATED, [['post_message', ['body' => 'x']]]);
    automationOn($server, TriggerRegistry::KANBAN_CARD_MOVED, [['post_message', ['body' => 'x']]]);

    $this->postJson("/api/channels/{$channel->id}/kanban/cards", ['text' => 'and another'])->assertCreated();
    $this->patchJson("/api/channels/{$channel->id}/kanban/cards/{$card['id']}", ['column' => 'done'])->assertOk();

    Queue::assertPushed(RunAutomation::class, 2);
    Queue::assertPushed(RunAutomation::class, function (RunAutomation $job) {
        $data = $job->context['data'] ?? [];

        // Both ends of the move, because "announce it when something reaches Done" is the rule
        // this exists for and `to = done` is how somebody writes it.
        return ($job->context['trigger'] ?? '') !== TriggerRegistry::KANBAN_CARD_MOVED
            || ($data['from'] === 'todo' && $data['to'] === 'done' && $data['to_label'] === 'Done');
    });
});

it('says nothing when a card only changes its text', function () {
    Queue::fake();
    [$owner, $server, $channel] = ownerWithChannel();
    automationOn($server, TriggerRegistry::KANBAN_CARD_MOVED, [['post_message', ['body' => 'x']]]);
    Passport::actingAs($owner);

    $card = $this->postJson("/api/channels/{$channel->id}/kanban/cards", ['text' => 'one'])->json('data');
    $this->patchJson("/api/channels/{$channel->id}/kanban/cards/{$card['id']}", ['text' => 'one, edited'])->assertOk();

    // A rename is not a move, and a rule that announced one would cry wolf all day.
    Queue::assertNothingPushed();
});

it('stays silent through an import, however much arrives', function () {
    Queue::fake();
    [$owner, $server, $source] = ownerWithChannel();
    $target = Channel::factory()->create(['server_id' => $server->id]);
    automationOn($server, TriggerRegistry::KANBAN_CARD_CREATED, [['post_message', ['body' => 'x']]]);
    Passport::actingAs($owner);

    $board = \App\Support\Apps\KanbanBoards::for($source, $owner);
    foreach (range(1, 5) as $n) {
        $board->cards()->create(['channel_id' => $source->id, 'column' => 'todo', 'text' => "card {$n}"]);
    }

    $this->postJson("/api/channels/{$target->id}/apps/import", [
        'app' => 'kanban', 'source_channel_id' => $source->id,
    ])->assertOk();

    // One rule × eighty-four cards is a channel nobody can read. A bulk arrival is not a
    // person adding a card, and the import announces itself once in its own way.
    Queue::assertNotPushed(RunAutomation::class);
});

it('fires when a task is opened and when its status changes', function () {
    Queue::fake();
    [$owner, $server, $channel] = ownerWithChannel();
    $project = $channel->trackerProjects()->create(['key' => 'ONB', 'name' => 'Onboarding', 'created_by' => $owner->id]);
    automationOn($server, TriggerRegistry::TRACKER_TASK_STATUS_CHANGED, [['post_message', ['body' => 'x']]]);
    Passport::actingAs($owner);

    $task = $this->postJson("/api/channels/{$channel->id}/tracker/tasks", [
        'project_id' => $project->id, 'title' => 'Rewrite the welcome email',
    ])->assertCreated()->json('data');

    $this->patchJson("/api/channels/{$channel->id}/tracker/tasks/{$task['id']}", ['status' => 'done'])->assertOk();

    Queue::assertPushed(RunAutomation::class, 1);
    Queue::assertPushed(RunAutomation::class, function (RunAutomation $job) {
        $data = $job->context['data'] ?? [];

        // The key, because ONB-1 is how the task is referred to in whatever message the rule
        // is about to post.
        return $data['task_key'] === 'ONB-1' && $data['to'] === 'done';
    });
});

it('adds a kanban card as an action, in the column the rule names', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);

    $automation = automationOn($server, TriggerRegistry::REACTION_ADDED, [
        ['create_kanban_card', ['channel_id' => $channel->id, 'column' => 'Doing', 'text' => 'Follow up with {user}']],
    ]);

    app(AutomationEngine::class)->run($automation, new AutomationContext(
        $server->id,
        TriggerRegistry::REACTION_ADDED,
        ['user_id' => $owner->id, 'user_name' => $owner->name, 'channel_id' => $channel->id],
    ));

    $card = \App\Models\KanbanCard::sole();

    expect($card->text)->toBe("Follow up with {$owner->name}")
        // Named by *label*, because that's what somebody types in the dashboard.
        ->and($card->column)->toBe('doing')
        ->and($card->channel_id)->toBe($channel->id);
});

it('opens a tracker task as an action, and skips a project key that isn’t there', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    $project = $channel->trackerProjects()->create(['key' => 'ONB', 'name' => 'Onboarding', 'created_by' => $owner->id]);

    $context = fn () => new AutomationContext(
        $server->id,
        TriggerRegistry::COMMAND_INVOKED,
        ['user_id' => $owner->id, 'user_name' => $owner->name, 'channel_id' => $channel->id],
    );

    app(AutomationEngine::class)->run(automationOn($server, TriggerRegistry::COMMAND_INVOKED, [
        ['create_tracker_task', ['channel_id' => $channel->id, 'project_key' => 'onb', 'title' => 'Triage from {user}']],
    ]), $context());

    // Keyed case-insensitively: `ONB` is a name people type, not an identifier they copy.
    expect($project->tasks()->sole()->title)->toBe("Triage from {$owner->name}");

    $missing = automationOn($server, TriggerRegistry::COMMAND_INVOKED, [
        ['create_tracker_task', ['channel_id' => $channel->id, 'project_key' => 'NOPE', 'title' => 'x']],
    ]);
    app(AutomationEngine::class)->run($missing, $context());

    // A skip, not a failure: a mistyped key is a configuration problem, and the dashboard line
    // is the only place anybody will find out.
    $line = BotAuditLog::where('automation_id', $missing->id)->latest('id')->first();
    expect($line->outcome)->toBe(BotAuditLog::SKIPPED)
        ->and($line->message)->toContain('NOPE');
});
