<?php

use App\Jobs\DeliverBotEvent;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

/*
 * Bot webhooks: who gets told what, and what happens when their server doesn't answer.
 *
 * Two halves, tested apart. The fan-out (which bots get a delivery queued for a given
 * message) is where the visibility and loop rules live. The delivery itself (signing,
 * SSRF, retries, giving up) is a job, and its interesting states are all failure states.
 */

/** A bot on a server with a webhook already registered. */
function botWithWebhook(App\Models\Server $server, array $attributes = []): array
{
    [$bot, $token] = botOn($server);

    $bot->update(array_merge([
        'webhook_url' => 'https://example.com/side-chat',
        'webhook_secret' => 'whsec_test',
    ], $attributes));

    return [$bot->fresh(), $token];
}

it('queues a delivery when a person posts in a channel the bot can see', function () {
    Queue::fake();
    [$owner, $server, $channel] = ownerWithChannel();
    [$bot] = botWithWebhook($server);
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'hello bot'])->assertCreated();

    Queue::assertPushed(DeliverBotEvent::class, function (DeliverBotEvent $job) use ($bot) {
        return $job->botId === $bot->id
            && $job->event === 'message.created'
            && $job->data['body'] === 'hello bot';
    });
});

it('never tells a bot about a message a bot wrote', function () {
    Queue::fake();
    [, $server, $channel] = ownerWithChannel();
    // Two of them, so the case under test isn't only "a bot hearing its own echo": bot A
    // posting must not wake bot B either, or the two of them answer each other forever.
    [, $token] = botWithWebhook($server);
    botWithWebhook($server);

    $this->withHeaders(botAuth($token))
        ->postJson("/api/bot/channels/{$channel->id}/messages", ['body' => 'build passed'])
        ->assertCreated();

    Queue::assertNothingPushed();
});

it('skips a bot with no webhook, a switched-off one, or one that did not ask for the event', function (array $attributes) {
    Queue::fake();
    [$owner, $server, $channel] = ownerWithChannel();
    botWithWebhook($server, $attributes);
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'hello'])->assertCreated();

    Queue::assertNothingPushed();
})->with([
    'no endpoint' => [['webhook_url' => null]],
    'switched off after failing' => [['webhook_disabled_at' => '2026-01-01 00:00:00']],
    'not subscribed' => [['events' => []]],
]);

it('does not leak a private channel to a bot that was never added to it', function () {
    Queue::fake();
    [$owner, $server] = ownerWithServer();
    $private = Channel::factory()->create(['server_id' => $server->id, 'is_private' => true]);
    botWithWebhook($server);
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$private->id}/messages", ['body' => 'secret'])->assertCreated();

    Queue::assertNothingPushed();
});

it('stays quiet for system notices, and for threads a bot cannot answer', function (array|Closure $attributes) {
    Queue::fake();
    [$owner, $server, $channel] = ownerWithChannel();
    botWithWebhook($server);

    // Built and broadcast by hand: both kinds fire the same event as an ordinary send, and
    // the point of the test is that the *listener* tells them apart.
    $message = $channel->messages()->create(array_merge(
        ['user_id' => $owner->id, 'body' => 'not for bots'],
        $attributes instanceof Closure ? $attributes($channel) : $attributes,
    ));

    broadcast(new App\Events\MessageSent($message));

    Queue::assertNothingPushed();
})->with([
    'a system notice' => [['type' => 'system']],
    'a thread reply' => [fn () => fn (Channel $channel) => [
        'thread_id' => App\Models\Thread::factory()->create(['channel_id' => $channel->id])->id,
    ]],
]);

it('signs the delivery over the timestamp and body', function () {
    Http::fake(['example.com/*' => Http::response('', 200)]);
    [, $server] = ownerWithServer();
    [$bot] = botWithWebhook($server);

    (new DeliverBotEvent($bot->id, 'message.created', ['id' => 7, 'body' => 'hi'], 'delivery-1'))
        ->handle(app(App\Services\SafeUrlFetcher::class));

    Http::assertSent(function (Request $request) {
        $timestamp = $request->header(DeliverBotEvent::TIMESTAMP_HEADER)[0];
        $expected = DeliverBotEvent::sign($timestamp, $request->body(), 'whsec_test');

        return $request->url() === 'https://example.com/side-chat'
            && $request->header(DeliverBotEvent::SIGNATURE_HEADER)[0] === $expected
            && $request->header('X-SideChat-Event')[0] === 'message.created'
            && json_decode($request->body(), true)['data']['body'] === 'hi';
    });
});

it('clears the failure count once a delivery gets through', function () {
    Http::fake(['example.com/*' => Http::response('', 200)]);
    [, $server] = ownerWithServer();
    [$bot] = botWithWebhook($server, ['webhook_failures' => 7]);

    (new DeliverBotEvent($bot->id, 'message.created', [], 'delivery-1'))
        ->handle(app(App\Services\SafeUrlFetcher::class));

    expect($bot->fresh()->webhook_failures)->toBe(0);
});

it('retries a receiver that answers with an error rather than giving up on it', function () {
    Http::fake(['example.com/*' => Http::response('nope', 500)]);
    [, $server] = ownerWithServer();
    [$bot] = botWithWebhook($server);

    $job = new DeliverBotEvent($bot->id, 'message.created', [], 'delivery-1');

    // Throwing is what hands the job back to the queue for another attempt; the strike is
    // only recorded once the attempts run out.
    expect(fn () => $job->handle(app(App\Services\SafeUrlFetcher::class)))
        ->toThrow(RuntimeException::class);

    expect($bot->fresh()->webhook_failures)->toBe(0);
});

it('counts a strike when every attempt is spent, and switches the webhook off at the ceiling', function () {
    config(['bots.webhooks.max_failures' => 3]);
    [, $server] = ownerWithServer();
    [$bot] = botWithWebhook($server, ['webhook_failures' => 2]);

    (new DeliverBotEvent($bot->id, 'message.created', [], 'delivery-1'))->failed();

    $bot = $bot->fresh();
    expect($bot->webhook_failures)->toBe(3)
        ->and($bot->webhook_disabled_at)->not->toBeNull()
        ->and($bot->webhookActive())->toBeFalse();
});

it('refuses to deliver to an address inside our own network', function () {
    Http::fake();
    [, $server] = ownerWithServer();
    [$bot] = botWithWebhook($server, ['webhook_url' => 'http://127.0.0.1:80/hook']);

    (new DeliverBotEvent($bot->id, 'message.created', [], 'delivery-1'))
        ->handle(app(App\Services\SafeUrlFetcher::class));

    // Nothing left the building, and the bot is a strike closer to being switched off.
    Http::assertNothingSent();
    expect($bot->fresh()->webhook_failures)->toBe(1);
});

it('stops delivering to a bot that was retired mid-flight', function () {
    Http::fake();
    [, $server] = ownerWithServer();
    [$bot] = botWithWebhook($server);
    $id = $bot->id;
    $bot->delete();

    (new DeliverBotEvent($id, 'message.created', [], 'delivery-1'))
        ->handle(app(App\Services\SafeUrlFetcher::class));

    Http::assertNothingSent();
});

it('hands the owner a signing secret when they register an endpoint, once', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $secret = $this->postJson("/api/servers/{$server->id}/bots", [
        'name' => 'Deploy Bot',
        'webhook_url' => 'https://example.com/side-chat',
    ])->assertCreated()->json('webhook_secret');

    expect($secret)->toStartWith('whsec_');

    // And never again — not in the listing, and not as a masked field.
    $this->getJson("/api/servers/{$server->id}/bots")
        ->assertOk()
        ->assertJsonPath('data.0.webhook_url', 'https://example.com/side-chat')
        ->assertJsonPath('data.0.webhook_enabled', true)
        ->assertJsonMissingPath('data.0.webhook_secret');
});

it('rotates the signing secret without touching the API token', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    [$bot, $token] = botWithWebhook($server);
    Passport::actingAs($owner);

    $secret = $this->postJson("/api/servers/{$server->id}/bots/{$bot->id}/webhook-secret")
        ->assertOk()
        ->json('data.webhook_secret');

    expect($secret)->not->toBe('whsec_test')
        ->and($bot->fresh()->webhook_secret)->toBe($secret);

    // The token it authenticates with is a different secret and is untouched.
    $this->withHeaders(botAuth($token))
        ->postJson("/api/bot/channels/{$channel->id}/messages", ['body' => 'still working'])
        ->assertCreated();
});

it('brings a switched-off webhook back when the owner points it somewhere new', function () {
    [$owner, $server] = ownerWithServer();
    [$bot] = botWithWebhook($server, ['webhook_failures' => 50, 'webhook_disabled_at' => now()]);
    Passport::actingAs($owner);

    $this->patchJson("/api/servers/{$server->id}/bots/{$bot->id}", [
        'webhook_url' => 'https://example.com/moved',
    ])
        ->assertOk()
        ->assertJsonPath('data.webhook_enabled', true)
        ->assertJsonPath('data.webhook_failures', 0);
});

it('refuses an event name it has never heard of', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/bots", [
        'name' => 'Deploy Bot',
        'webhook_url' => 'https://example.com/side-chat',
        'events' => ['message.created', 'server.exploded'],
    ])->assertJsonValidationErrors('events.1');
});
