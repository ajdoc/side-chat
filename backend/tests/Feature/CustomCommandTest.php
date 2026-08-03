<?php

use App\Models\Badge;
use App\Models\BotSchedule;
use App\Models\BotSettings;
use App\Models\CustomCommand;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\TriggerRegistry;
use App\Support\Automation\AutomationContext;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;

/*
 * Custom commands and schedules — the two features a server configures rather than programs.
 *
 * The command tests go through the real send path, because that's where the interesting part
 * is: which of the four command shapes wins, and what a message that only *looks* like a
 * command does.
 */

function customCommand(Server $server, array $attributes = []): CustomCommand
{
    return CustomCommand::create(array_merge([
        'server_id' => $server->id,
        'name' => 'rules',
        'response' => 'Be nice.',
    ], $attributes));
}

it('answers a custom slash command', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    customCommand($server);
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/rules'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Be nice.');
});

it('answers a prefix command on the server’s own prefix', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    customCommand($server);
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '!rules'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Be nice.');

    // Moved to `?`, because another bot in the server already owns `!`.
    $this->putJson("/api/servers/{$server->id}/bot/settings", ['command_prefix' => '?'])->assertOk();

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '?rules'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Be nice.');

    // The old prefix is now just punctuation — and posts as written rather than being
    // answered with "no such command".
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '!rules'])
        ->assertCreated()
        ->assertJsonPath('data.body', '!rules');
});

it('leaves ordinary chat that starts with punctuation alone', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    customCommand($server);
    Passport::actingAs($owner);

    foreach (['!!! it worked', 'wait, !rules is wrong', '!', ':) hello'] as $body) {
        $this->postJson("/api/channels/{$channel->id}/messages", ['body' => $body])
            ->assertCreated()
            ->assertJsonPath('data.body', $body);
    }
});

it('respects which shape a command answers to', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    customCommand($server, ['kind' => CustomCommand::PREFIX]);
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '!rules'])
        ->assertJsonPath('data.body', 'Be nice.');

    // Prefix-only, so the slash falls through to "no such command" rather than answering.
    // 200, not 201: an ephemeral note creates nothing.
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/rules'])
        ->assertOk()
        ->assertJsonPath('data.type', 'system');
});

it('never lets a custom command shadow a built-in', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    // Refused at the point somebody can still be told, rather than silently never firing.
    $this->postJson("/api/servers/{$server->id}/commands", ['name' => 'help', 'response' => 'nope'])
        ->assertStatus(422)->assertJsonValidationErrors('name');
    $this->postJson("/api/servers/{$server->id}/commands", ['name' => 'roll', 'response' => 'nope'])
        ->assertStatus(422)->assertJsonValidationErrors('name');
});

it('fills placeholders in a command’s response', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    customCommand($server, ['response' => 'Hi {user}, welcome to {server}.']);
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/rules'])
        ->assertJsonPath('data.body', "Hi {$owner->name}, welcome to {$server->name}.");
});

it('keeps a command behind its badge, privately', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $badge = Badge::create(['server_id' => $server->id, 'name' => 'Crew']);
    customCommand($server, ['required_badge_id' => $badge->id]);
    Passport::actingAs($owner);

    // Ephemeral: a refusal is between the person and the bot, not an announcement.
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/rules'])
        ->assertOk()
        ->assertJsonPath('data.type', 'system');
    expect(Message::where('body', 'Be nice.')->exists())->toBeFalse();

    $badge->grantTo($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/rules'])
        ->assertJsonPath('data.body', 'Be nice.');
});

it('holds a command on cooldown for the person who ran it', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $other = User::factory()->create();
    $server->members()->attach($other->id, ['role' => Server::ROLE_MEMBER]);
    $channel->allowedMembers()->attach($other->id);
    customCommand($server, ['cooldown_seconds' => 60]);
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/rules'])
        ->assertJsonPath('data.body', 'Be nice.');
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/rules'])
        ->assertOk()
        ->assertJsonPath('data.type', 'system');

    // Per person, not per channel — one member spamming shouldn't lock everyone out.
    Passport::actingAs($other);
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/rules'])
        ->assertJsonPath('data.body', 'Be nice.');
});

it('lists custom commands in /help', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    customCommand($server, ['description' => 'The house rules']);
    customCommand($server, ['name' => 'ip', 'kind' => CustomCommand::PREFIX, 'response' => '1.2.3.4']);
    Passport::actingAs($owner);

    $names = collect($this->getJson("/api/channels/{$channel->id}/commands")->json('data'))->pluck('name');

    expect($names)->toContain('rules');
    // Prefix-only isn't callable with a slash, so listing it would be an invitation the
    // resolver refuses.
    expect($names)->not->toContain('ip');
});

/*
 * Schedules.
 */

it('computes the next run in the schedule’s own timezone', function () {
    [, $server] = ownerWithServer();

    $schedule = BotSchedule::make([
        'server_id' => $server->id,
        'name' => 'Headcount',
        'body' => 'Who is in?',
        'cron' => '0 9 * * 1',
        'timezone' => 'Asia/Manila',
    ]);

    // 09:00 in Manila is 01:00 UTC — the whole reason the timezone is stored.
    expect($schedule->computeNextRun()->format('H:i'))->toBe('01:00');
});

it('refuses a cron expression the runner could not read', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/schedules", [
        'name' => 'Broken', 'body' => 'hi', 'cron' => 'every so often',
    ])->assertStatus(422)->assertJsonValidationErrors('cron');
});

it('recomputes the next run when the expression changes', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $id = $this->postJson("/api/servers/{$server->id}/schedules", [
        'name' => 'Headcount', 'body' => 'Who is in?', 'cron' => '0 9 * * 1',
    ])->assertCreated()->json('data.id');

    $before = BotSchedule::find($id)->next_run_at;

    $this->patchJson("/api/servers/{$server->id}/schedules/{$id}", ['cron' => '0 9 * * 5'])->assertOk();

    // Otherwise a schedule moved from Monday to Friday would still fire on Monday, once.
    expect(BotSchedule::find($id)->next_run_at->ne($before))->toBeTrue();
});

it('posts a due schedule and moves its window forward', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);

    $schedule = BotSchedule::create([
        'server_id' => $server->id,
        'name' => 'Headcount',
        'channel_id' => $channel->id,
        'body' => 'Who is in this week?',
        'cron' => '0 9 * * 1',
    ]);
    $schedule->forceFill(['next_run_at' => now()->subMinute()])->save();

    $this->artisan('bot:run-schedules')->assertSuccessful();

    expect($channel->messages()->latest('id')->first()->body)->toBe('Who is in this week?');
    expect($schedule->fresh()->next_run_at->isFuture())->toBeTrue();
    expect($schedule->fresh()->last_run_at)->not->toBeNull();
});

it('leaves a schedule that is not due alone', function () {
    [, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);

    BotSchedule::create([
        'server_id' => $server->id, 'name' => 'Later', 'channel_id' => $channel->id,
        'body' => 'not yet', 'cron' => '0 9 * * 1',
    ])->forceFill(['next_run_at' => now()->addHour()])->save();

    $this->artisan('bot:run-schedules')->assertSuccessful();

    expect($channel->messages()->count())->toBe(0);
});

it('falls back to the reminder channel when a schedule names none', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    BotSettings::forServer($server)->update(['reminder_channel_id' => $channel->id]);

    BotSchedule::create([
        'server_id' => $server->id, 'name' => 'Anywhere', 'body' => 'fallback',
        'cron' => '0 9 * * 1',
    ])->forceFill(['next_run_at' => now()->subMinute()])->save();

    $this->artisan('bot:run-schedules')->assertSuccessful();

    expect($channel->messages()->latest('id')->first()->body)->toBe('fallback');
});

it('moves the window forward even when the post fails, so a broken channel is not a flood', function () {
    [, $server] = ownerWithServer();
    // No bot, so PostMessageAction skips — the most common way a post doesn't happen.
    $schedule = BotSchedule::create([
        'server_id' => $server->id, 'name' => 'Doomed', 'body' => 'nowhere',
        'cron' => '*/5 * * * *',
    ]);
    $schedule->forceFill(['next_run_at' => now()->subMinute()])->save();

    $this->artisan('bot:run-schedules')->assertSuccessful();

    expect($schedule->fresh()->next_run_at->isFuture())->toBeTrue();
});

/*
 * The chaining actions — the point of the whole phase.
 */

it('posts a custom command’s response from a rule', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);
    $command = customCommand($server, ['response' => 'Welcome {user}, read the rules.']);

    $automation = automationOn($server, TriggerRegistry::MEMBER_JOINED, [
        ['run_command', ['command_id' => $command->id, 'channel_id' => $channel->id]],
    ]);

    app(AutomationEngine::class)->run($automation, new AutomationContext(
        $server->id,
        TriggerRegistry::MEMBER_JOINED,
        ['user_id' => $owner->id, 'user_name' => $owner->name],
    ));

    // Rendered against the rule's subject, not "whoever typed it".
    expect($channel->messages()->latest('id')->first()->body)
        ->toBe("Welcome {$owner->name}, read the rules.");
});

it('sends a schedule from a rule without moving its clock', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);

    $schedule = BotSchedule::create([
        'server_id' => $server->id, 'name' => 'Headcount', 'channel_id' => $channel->id,
        'body' => 'Sound off', 'cron' => '0 9 * * 1',
    ]);
    $schedule->forceFill(['next_run_at' => Carbon::parse('2030-01-07 09:00:00')])->save();

    $automation = automationOn($server, TriggerRegistry::MEMBER_JOINED, [
        ['run_schedule', ['schedule_id' => $schedule->id]],
    ]);

    app(AutomationEngine::class)->run($automation, new AutomationContext(
        $server->id, TriggerRegistry::MEMBER_JOINED, ['user_id' => $owner->id],
    ));

    expect($channel->messages()->latest('id')->first()->body)->toBe('Sound off');
    // The Monday post is still due on Monday.
    expect($schedule->fresh()->next_run_at->toDateTimeString())->toBe('2030-01-07 09:00:00');
});

it('will not let a rule reach round a switched-off schedule', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    serverWithAutomationBot($server);

    $schedule = BotSchedule::create([
        'server_id' => $server->id, 'name' => 'Off', 'channel_id' => $channel->id,
        'body' => 'should not appear', 'cron' => '0 9 * * 1', 'enabled' => false,
    ]);

    $automation = automationOn($server, TriggerRegistry::MEMBER_JOINED, [
        ['run_schedule', ['schedule_id' => $schedule->id]],
    ]);

    app(AutomationEngine::class)->run($automation, new AutomationContext(
        $server->id, TriggerRegistry::MEMBER_JOINED, ['user_id' => $owner->id],
    ));

    expect($channel->messages()->count())->toBe(0);
});
