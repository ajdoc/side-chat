<?php

use App\Jobs\SendPushNotifications;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\User;
use App\Services\Notifications\FcmSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

/**
 * A service-account JSON shaped like the real thing, with a key that can actually sign —
 * the sender refuses to send at all if the assertion won't sign, so a fake string would
 * make every test here pass for the wrong reason.
 */
function fakeFcmCredentials(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    return json_encode([
        'type' => 'service_account',
        'project_id' => 'side-chat-test',
        'client_email' => 'push@side-chat-test.iam.gserviceaccount.com',
        'private_key' => $pem,
    ]);
}

/**
 * What the send endpoint should answer next. Read it with no argument, set it with one.
 *
 * A mutable holder rather than a second `Http::fake()` call, because faking twice *appends*
 * a stub and the first registered match wins — a test that re-faked the send endpoint as a
 * 404 would go on being handed the 200 that `beforeEach` had already registered, and would
 * pass or fail for reasons that had nothing to do with the code under test.
 */
function fcmStatus(?int $set = null): int
{
    static $status = 200;

    if ($set !== null) {
        $status = $set;
    }

    return $status;
}

beforeEach(function () {
    config()->set('services.fcm.credentials', fakeFcmCredentials());
    fcmStatus(200);

    // One closure covering both hosts, registered exactly once per test.
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'oauth2.googleapis.com')) {
            return Http::response(['access_token' => 'test-token', 'expires_in' => 3600]);
        }

        return fcmStatus() === 200
            ? Http::response(['name' => 'projects/side-chat-test/messages/1'])
            : Http::response(['error' => ['status' => 'UNREGISTERED']], fcmStatus());
    });
});

/** A server, its owner, a channel, and a second member with a phone. */
function pushFixture(): array
{
    [$owner, $server, $channel] = ownerWithChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);
    DeviceToken::factory()->create(['user_id' => $member->id]);

    return [$owner, $server, $channel, $member];
}

function runPushFor(Message $message, array $mentioned = [], bool $mentionsAll = false): void
{
    app()->call([new SendPushNotifications($message->id, $mentioned, $mentionsAll), 'handle']);
}

it('pushes a mention to a member whose default is mentions-only', function () {
    [$owner, , $channel, $member] = pushFixture();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    runPushFor($message, [$member->id]);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com'));
});

it('stays quiet for an ordinary message at the default level', function () {
    [$owner, , $channel] = pushFixture();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    runPushFor($message);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com'));
});

it('pushes every message once the channel is set to all', function () {
    [$owner, , $channel, $member] = pushFixture();
    Passport::actingAs($member);
    $this->putJson("/api/channels/{$channel->id}/notifications", ['notify_level' => 'all'])->assertOk();

    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    runPushFor($message);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com'));
});

it('does not push a mention into a muted channel', function () {
    [$owner, , $channel, $member] = pushFixture();
    Passport::actingAs($member);
    $this->putJson("/api/channels/{$channel->id}/notifications", ['mute_minutes' => 60])->assertOk();

    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    runPushFor($message, [$member->id]);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com'));
});

it('never pushes a message back to its own author', function () {
    [$owner, , $channel] = pushFixture();
    DeviceToken::factory()->create(['user_id' => $owner->id]);

    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    // @all names everybody — the author included, if nothing excluded them.
    runPushFor($message, [], true);

    Http::assertSentCount(2); // the token exchange, and the one push to the *other* member
});

it('respects the account-wide push switch', function () {
    [$owner, , $channel, $member] = pushFixture();
    $member->update(['push_enabled' => false]);

    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    runPushFor($message, [$member->id]);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com'));
});

it('keeps a private channel private', function () {
    [$owner, $server, , $member] = pushFixture();
    $private = Channel::factory()->create(['server_id' => $server->id, 'is_private' => true]);
    $private->allowedMembers()->attach($owner->id);

    $message = Message::factory()->create(['channel_id' => $private->id, 'user_id' => $owner->id]);
    runPushFor($message, [$member->id]);

    // Named in a channel they can't see. Membership of the server is not enough.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com'));
});

it('sends no preview for an encrypted message', function () {
    [$owner, , $channel, $member] = pushFixture();
    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
        'body' => 'ciphertext-that-must-never-be-shown',
        'encrypted' => true,
    ]);

    runPushFor($message, [], true);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'fcm.googleapis.com')) {
            return true;
        }

        $body = $request->data()['message']['data']['body'];

        return $body === 'Sent you an encrypted message'
            && ! str_contains(json_encode($request->data()), 'ciphertext-that-must-never');
    });
});

it('prunes a token FCM says is dead', function () {
    [$owner, , $channel, $member] = pushFixture();

    fcmStatus(404);

    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    runPushFor($message, [$member->id]);

    expect(DeviceToken::where('user_id', $member->id)->exists())->toBeFalse();
});

it('keeps a token through a server-side wobble', function () {
    [$owner, , $channel, $member] = pushFixture();

    fcmStatus(503);

    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    runPushFor($message, [$member->id]);

    expect(DeviceToken::where('user_id', $member->id)->exists())->toBeTrue();
});

it('accepts credentials however the host mangled them', function () {
    $json = fakeFcmCredentials();

    // Raw, base64 (for dashboards that eat newlines), and with the quotes an env field
    // keeps around a value you typed quoted — the last one being the failure that looks
    // exactly like a bad key.
    foreach ([$json, base64_encode($json), "'{$json}'", "\"{$json}\""] as $form) {
        config()->set('services.fcm.credentials', $form);

        expect(app(FcmSender::class)->configured())->toBeTrue();
    }
});

it('does nothing at all when push is not configured', function () {
    config()->set('services.fcm.credentials', null);

    [$owner, , $channel, $member] = pushFixture();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    runPushFor($message, [$member->id]);

    Http::assertNothingSent();
});

it('pushes both sides of a DM at the DM default', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    DeviceToken::factory()->create(['user_id' => $recipient->id]);

    $conversation = Conversation::factory()->create(['type' => 'dm']);
    $conversation->members()->attach([$sender->id, $recipient->id]);
    $channel = Channel::factory()->create(['server_id' => null, 'conversation_id' => $conversation->id]);

    // No mention, and it still goes: a DM defaults to 'all' where a channel defaults to
    // 'mentions'.
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $sender->id]);
    runPushFor($message);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com'));
});

it('registers and revokes a device token', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson('/api/device-tokens', ['token' => 'abc123', 'platform' => 'android'])->assertOk();
    expect(DeviceToken::where('token', 'abc123')->value('user_id'))->toBe($user->id);

    // Re-registering the same token is an upsert, not a second row.
    $this->postJson('/api/device-tokens', ['token' => 'abc123', 'platform' => 'android'])->assertOk();
    expect(DeviceToken::where('token', 'abc123')->count())->toBe(1);

    $this->deleteJson('/api/device-tokens', ['token' => 'abc123'])->assertOk();
    expect(DeviceToken::where('token', 'abc123')->exists())->toBeFalse();
});

it('moves a token to whoever signed in last', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    Passport::actingAs($first);
    $this->postJson('/api/device-tokens', ['token' => 'shared-phone', 'platform' => 'android'])->assertOk();

    Passport::actingAs($second);
    $this->postJson('/api/device-tokens', ['token' => 'shared-phone', 'platform' => 'android'])->assertOk();

    // One row, and the previous owner no longer gets this phone's notifications.
    expect(DeviceToken::where('token', 'shared-phone')->count())->toBe(1)
        ->and(DeviceToken::where('token', 'shared-phone')->value('user_id'))->toBe($second->id);
});

it('will not let one user revoke another\'s token', function () {
    $owner = User::factory()->create();
    DeviceToken::factory()->create(['user_id' => $owner->id, 'token' => 'not-yours']);

    Passport::actingAs(User::factory()->create());
    $this->deleteJson('/api/device-tokens', ['token' => 'not-yours'])->assertOk();

    expect(DeviceToken::where('token', 'not-yours')->exists())->toBeTrue();
});

it('queues a push when a message is sent', function () {
    Queue::fake();

    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'hello'])->assertCreated();

    Queue::assertPushed(SendPushNotifications::class);
});
