<?php

use App\Jobs\DeliverBotEvent;
use App\Jobs\PostReminder;
use App\Models\BotCommand;
use App\Models\Message;
use App\Support\Commands\CommandParser;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

/*
 * Slash commands.
 *
 * The parser gets its own unit-ish coverage because the cost of getting it wrong is
 * asymmetric: a command that doesn't fire is a mild annoyance, while an ordinary message
 * mistaken for a command is a message that never lands. Everything else here is about which
 * of the three outcomes a `/word` gets — built-in, bot, or "no such command".
 */

it('recognises a slash command', function (string $body, string $verb, string $args) {
    $command = (new CommandParser)->parse($body);

    expect($command)->not->toBeNull()
        ->and($command->namespace)->toBe(CommandParser::SLASH_NAMESPACE)
        ->and($command->verb)->toBe($verb)
        ->and($command->args)->toBe($args);
})->with([
    ['/roll', 'roll', ''],
    ['/roll 2d6', 'roll', '2d6'],
    ['/ROLL 2d6', 'roll', '2d6'],
    ['/8ball will it deploy?', '8ball', 'will it deploy?'],
    ['/deploy-v2 staging', 'deploy-v2', 'staging'],
    ['  /help  ', 'help', ''],
]);

it('leaves ordinary messages alone', function (string $body) {
    expect((new CommandParser)->parse($body))->toBeNull();
})->with([
    'a path' => ['/usr/local/bin'],
    'a bare slash' => ['/'],
    'a number' => ['/2024'],
    'a date-ish thing' => ['/12-25'],
    'mid-sentence' => ['see /roll for details'],
    'a closing tag' => ['</div>'],
]);

it('rolls dice in the channel, where everyone can see', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $response = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/roll 2d6'])
        ->assertSuccessful();

    // A real message, not a private note: it's in the database and it's the sender's.
    expect(Message::count())->toBe(1)
        ->and($response->json('data.id'))->toBeGreaterThan(0)
        ->and($response->json('data.user.id'))->toBe($user->id)
        ->and($response->json('data.body'))->toMatch('/^🎲 \*\*\d+\*\* \(2d6\) — \d+ \+ \d+$/');
});

it('answers a malformed roll privately, without posting anything', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $response = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/roll banana'])
        ->assertSuccessful();

    expect($response->json('data.type'))->toBe('system')
        ->and($response->json('data.id'))->toBeLessThan(0)
        ->and(Message::count())->toBe(0);
});

it('turns /me into an emote without baking a name into it', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    // The name is deliberately absent: this app lets somebody go by a different name in
    // each place, and a name in the body could never be one of those. See MeCommand.
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/me rebuilds the container'])
        ->assertSuccessful()
        ->assertJsonPath('data.body', '_rebuilds the container_');
});

it('appends a shrug to whatever came with it', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/shrug it works on mine'])
        ->assertSuccessful()
        ->assertJsonPath('data.body', 'it works on mine ¯\\\\_(ツ)_/¯');
});

it('schedules a reminder and says so privately', function () {
    Queue::fake();
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $response = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/remind 20m check the migration'])
        ->assertSuccessful();

    expect($response->json('data.id'))->toBeLessThan(0)
        ->and(Message::count())->toBe(0);

    Queue::assertPushed(PostReminder::class, fn (PostReminder $job) => $job->channelId === $channel->id
        && $job->userId === $user->id
        && $job->text === 'check the migration');
});

it('refuses a reminder it cannot hold on to, or cannot understand', function (string $body) {
    Queue::fake();
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => $body])->assertSuccessful();

    Queue::assertNotPushed(PostReminder::class);
})->with([
    'no unit' => ['/remind 20 check the migration'],
    'nothing to say' => ['/remind 20m'],
    'further off than a month' => ['/remind 90d check the migration'],
]);

it('posts the reminder into the channel when its time comes', function () {
    [$user, , $channel] = ownerWithChannel();

    (new PostReminder($channel->id, $user->id, 'check the migration'))->handle();

    $message = Message::sole();
    expect($message->type)->toBe('system')
        ->and($message->body)->toContain('check the migration');
});

it('says nothing when the person who asked has since left', function () {
    [, $server, $channel] = ownerWithChannel();
    $stranger = App\Models\User::factory()->create();

    (new PostReminder($channel->id, $stranger->id, 'check the migration'))->handle();

    expect(Message::count())->toBe(0);
});

it('lists what you can call here, built-ins and bots together', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    [$bot] = botOn($server);
    BotCommand::create(['bot_id' => $bot->id, 'server_id' => $server->id, 'name' => 'deploy', 'description' => 'Ship it.', 'usage' => '/deploy staging']);
    Passport::actingAs($owner);

    $response = $this->getJson("/api/channels/{$channel->id}/commands")->assertOk();

    $names = collect($response->json('data'))->pluck('name');
    expect($names)->toContain('help', 'roll', '8ball', 'me', 'shrug', 'remind', 'deploy');

    // A bot's command says whose it is; a built-in has nobody behind it.
    $deploy = collect($response->json('data'))->firstWhere('name', 'deploy');
    expect($deploy['bot'])->toBe($bot->user->name);
});

it('hands a bot its command, with who asked and what they said', function () {
    Queue::fake();
    [$owner, $server, $channel] = ownerWithChannel();
    [$bot] = botOn($server);
    $bot->update(['webhook_url' => 'https://example.com/hook', 'webhook_secret' => 'whsec_test']);
    BotCommand::create(['bot_id' => $bot->id, 'server_id' => $server->id, 'name' => 'deploy']);
    Passport::actingAs($owner);

    $response = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/deploy staging'])
        ->assertSuccessful();

    // The person who typed it gets a private acknowledgement, and nothing is posted.
    expect($response->json('data.id'))->toBeLessThan(0)
        ->and(Message::count())->toBe(0);

    Queue::assertPushed(DeliverBotEvent::class, fn (DeliverBotEvent $job) => $job->event === 'command.invoked'
        && $job->data['command'] === 'deploy'
        && $job->data['args'] === 'staging'
        && $job->data['user']['id'] === $owner->id);
});

it('says so plainly when a bot command belongs to a bot that is not listening', function () {
    Queue::fake();
    [$owner, $server, $channel] = ownerWithChannel();
    [$bot] = botOn($server); // registered a command, but never registered a webhook
    BotCommand::create(['bot_id' => $bot->id, 'server_id' => $server->id, 'name' => 'deploy']);
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/deploy staging'])
        ->assertSuccessful()
        ->assertJsonPath('data.type', 'system');

    Queue::assertNotPushed(DeliverBotEvent::class);
});

it('answers an unknown command instead of posting it', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $response = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/rol 2d6'])
        ->assertSuccessful();

    expect($response->json('data.body'))->toContain('/rol')
        ->and(Message::count())->toBe(0);
});

it('lets a bot register its commands, replacing whatever it registered before', function () {
    [, $server] = ownerWithServer();
    [$bot, $token] = botOn($server);

    $this->withHeaders(botAuth($token))
        ->putJson('/api/bot/commands', ['commands' => [
            ['name' => 'deploy', 'description' => 'Ship it.', 'usage' => '/deploy staging'],
            ['name' => 'rollback'],
        ]])
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // Re-registering is the whole set, so the command this version dropped is gone.
    $this->withHeaders(botAuth($token))
        ->putJson('/api/bot/commands', ['commands' => [['name' => 'deploy']]])
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect(BotCommand::pluck('name')->all())->toBe(['deploy']);
});

it('refuses to let a bot shadow a built-in command', function () {
    [, $server] = ownerWithServer();
    [, $token] = botOn($server);

    $this->withHeaders(botAuth($token))
        ->putJson('/api/bot/commands', ['commands' => [['name' => 'help']]])
        ->assertJsonValidationErrors('commands.0.name');
});

it('refuses a command another bot in the server already claimed', function () {
    [, $server] = ownerWithServer();
    [$first] = botOn($server);
    BotCommand::create(['bot_id' => $first->id, 'server_id' => $server->id, 'name' => 'deploy']);

    [, $token] = botOn($server);

    $this->withHeaders(botAuth($token))
        ->putJson('/api/bot/commands', ['commands' => [['name' => 'deploy']]])
        ->assertJsonValidationErrors('commands');
});

it('keeps a bot command out of a private channel the bot was never added to', function () {
    Queue::fake();
    [$owner, $server] = ownerWithServer();
    $private = App\Models\Channel::factory()->create(['server_id' => $server->id, 'is_private' => true]);
    [$bot] = botOn($server);
    $bot->update(['webhook_url' => 'https://example.com/hook', 'webhook_secret' => 'whsec_test']);
    BotCommand::create(['bot_id' => $bot->id, 'server_id' => $server->id, 'name' => 'deploy']);
    Passport::actingAs($owner);

    // Not merely unreachable — absent from the list, because the commands a bot answers to
    // are themselves a hint about what it's for.
    $names = collect($this->getJson("/api/channels/{$private->id}/commands")->json('data'))->pluck('name');
    expect($names)->not->toContain('deploy');

    $this->postJson("/api/channels/{$private->id}/messages", ['body' => '/deploy staging'])->assertSuccessful();
    Queue::assertNotPushed(DeliverBotEvent::class);
});

it('still runs the old widget commands', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    // The slash namespace must not have swallowed the `<letter>!<verb>` family.
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'k!add ship it'])
        ->assertSuccessful()
        ->assertJsonPath('data.type', 'widget');
});
