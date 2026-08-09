<?php

use App\Events\SenderKeysDistributed;
use App\Models\Channel;
use App\Models\DeviceKey;
use App\Models\SenderKey;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;

/*
 * The key directory: devices, prekeys, and moving wrapped sender keys about.
 *
 * The interesting assertions are all refusals. A directory that hands the right thing to the
 * right person is easy; the failures that matter are the ones where it hands something to
 * somebody who merely asked nicely — a stranger draining an account's prekeys, a member of a
 * server reaching into a private channel, a client claiming to be a device it isn't.
 *
 * Note what is *not* tested here: that any of this key material is valid. The server never
 * parses it, and shouldn't. The crypto is proven in the frontend's crypto.spec.ts against
 * real WebCrypto; this file proves the post box delivers to the right addresses.
 */

/** A registered device for a user, with the payload a real client would send. */
function registerDeviceFor(User $user, string $deviceId, int $prekeys = 2): array
{
    Passport::actingAs($user);

    return test()->putJson('/api/encryption/devices', [
        'device_id' => $deviceId,
        'identity_public' => base64_encode(random_bytes(65)),
        'signing_public' => base64_encode(random_bytes(65)),
        'signed_prekey' => base64_encode(random_bytes(65)),
        'prekey_signature' => base64_encode(random_bytes(64)),
        // `range(1, 0)` counts *downwards* in PHP and would quietly stock two prekeys for a
        // device the test asked to have none — which is exactly the case worth testing.
        'one_time_prekeys' => collect($prekeys > 0 ? range(1, $prekeys) : [])->map(fn (int $i) => [
            'prekey_id' => "otp-{$deviceId}-{$i}",
            'public_key' => base64_encode(random_bytes(65)),
        ])->all(),
    ])->assertOk()->json('data');
}

/** One wrapped sender key, addressed to a device. */
function wrappedKeyFor(DeviceKey $recipient): array
{
    return [
        'recipient_device_key_id' => $recipient->id,
        'wrapped_key' => base64_encode(random_bytes(48)),
        'wrap_iv' => base64_encode(random_bytes(12)),
        'ephemeral_public' => base64_encode(random_bytes(65)),
    ];
}

it('registers a device and reports its prekey stock', function () {
    [$owner] = twoMembers();

    $data = registerDeviceFor($owner, 'laptop', 3);

    expect($data['device_id'])->toBe('laptop')
        ->and($data['one_time_prekeys'])->toBe(3);

    expect(DeviceKey::where('user_id', $owner->id)->count())->toBe(1);
});

it('treats a re-registration as the same device, not a second one', function () {
    // Every launch calls this — to rotate the signed prekey and to say the device still
    // exists. A client that accumulated a row per launch would fan out sender keys to
    // hundreds of phantom devices.
    [$owner] = twoMembers();

    registerDeviceFor($owner, 'laptop');
    $again = registerDeviceFor($owner, 'laptop');

    expect(DeviceKey::where('user_id', $owner->id)->count())->toBe(1)
        // The prekeys from both calls are additive; the id is stable.
        ->and($again['device_key_id'])->toBe(DeviceKey::where('user_id', $owner->id)->first()->id);
});

it('keeps one person’s two devices genuinely separate', function () {
    [$owner] = twoMembers();

    registerDeviceFor($owner, 'laptop');
    registerDeviceFor($owner, 'phone');

    expect(DeviceKey::where('user_id', $owner->id)->count())->toBe(2);
});

it('tops up prekeys without duplicating a retried one', function () {
    [$owner] = twoMembers();
    registerDeviceFor($owner, 'laptop', 1);

    $prekey = ['prekey_id' => 'otp-retry', 'public_key' => base64_encode(random_bytes(65))];

    $this->postJson('/api/encryption/devices/prekeys', [
        'device_id' => 'laptop',
        'one_time_prekeys' => [$prekey],
    ])->assertOk()->assertJsonPath('data.one_time_prekeys', 2);

    // The same call again — a client retrying a request whose response it never saw.
    $this->postJson('/api/encryption/devices/prekeys', [
        'device_id' => 'laptop',
        'one_time_prekeys' => [$prekey],
    ])->assertOk()->assertJsonPath('data.one_time_prekeys', 2);
});

it('refuses to act as a device belonging to somebody else', function () {
    // The whole of "which device am I" rests on this. If naming another account's device
    // worked, one member could collect sender keys addressed to another.
    [$owner, $member] = twoMembers();
    registerDeviceFor($owner, 'owner-laptop');

    Passport::actingAs($member);
    $this->postJson('/api/encryption/devices/prekeys', [
        'device_id' => 'owner-laptop',
        'one_time_prekeys' => [['prekey_id' => 'x', 'public_key' => base64_encode(random_bytes(65))]],
    ])->assertNotFound();
});

it('hands out the other devices in a channel, and consumes a prekey doing it', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone', 2);
    registerDeviceFor($owner, 'owner-laptop', 2);

    Passport::actingAs($owner);
    $bundles = $this->postJson("/api/channels/{$channel->id}/encryption/bundles", [
        'device_id' => 'owner-laptop',
    ])->assertOk()->json('data');

    // The asking device is not in its own list; the other member's is.
    expect($bundles)->toHaveCount(1)
        ->and($bundles[0]['device_id'])->toBe('member-phone')
        ->and($bundles[0]['one_time_prekey'])->not->toBeNull();

    // A one-time prekey is spent by being fetched — that is what makes it one-time.
    expect(DeviceKey::where('device_id', 'member-phone')->first()->oneTimePrekeys()->count())->toBe(1);
});

it('never hands the same one-time prekey out twice', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone', 2);
    registerDeviceFor($owner, 'owner-laptop', 1);

    Passport::actingAs($owner);
    $seen = [];
    foreach (range(1, 2) as $ignored) {
        $seen[] = $this->postJson("/api/channels/{$channel->id}/encryption/bundles", [
            'device_id' => 'owner-laptop',
        ])->assertOk()->json('data.0.one_time_prekey_id');
    }

    expect($seen[0])->not->toBe($seen[1]);
});

it('still returns a usable bundle once the prekeys have run out', function () {
    // Draining somebody's stock must degrade the session, not break the conversation.
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone', 0);
    registerDeviceFor($owner, 'owner-laptop', 0);

    Passport::actingAs($owner);
    $bundle = $this->postJson("/api/channels/{$channel->id}/encryption/bundles", [
        'device_id' => 'owner-laptop',
    ])->assertOk()->json('data.0');

    expect($bundle['one_time_prekey'])->toBeNull()
        // Everything needed for a session without forward secrecy is still there.
        ->and($bundle['signed_prekey'])->not->toBeNull()
        ->and($bundle['prekey_signature'])->not->toBeNull();
});

it('refuses bundles to somebody who is not in the channel', function () {
    // Otherwise the directory is open, and anybody can drain anybody's prekeys.
    [, , $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    $outsider = User::factory()->create();
    registerDeviceFor($outsider, 'outsider-laptop');

    $this->postJson("/api/channels/{$channel->id}/encryption/bundles", [
        'device_id' => 'outsider-laptop',
    ])->assertForbidden();
});

it('leaves out the devices of people shut out of a private channel', function () {
    // The member is in the server but not on this channel's allow-list. Handing over their
    // device would mean distributing the channel's keys to somebody who can't see it exists.
    [$owner, $member, $server] = twoMembers();
    $container = Channel::factory()->create(['server_id' => $server->id, 'is_private' => true]);
    $channel = $container->discussions()->first();

    registerDeviceFor($member, 'member-phone');
    registerDeviceFor($owner, 'owner-laptop');

    Passport::actingAs($owner);
    $bundles = $this->postJson("/api/channels/{$channel->id}/encryption/bundles", [
        'device_id' => 'owner-laptop',
    ])->assertOk()->json('data');

    expect($bundles)->toHaveCount(0);
});

it('lists identity keys for verification without spending a prekey', function () {
    // The distinction this endpoint exists for. Pointing the safety-number screen at the
    // bundles endpoint would burn a one-time prekey every time somebody glanced at it, and
    // an account whose stock is drained falls back to sessions without forward secrecy.
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone', 2);
    registerDeviceFor($owner, 'owner-laptop', 2);

    Passport::actingAs($owner);
    $identities = $this->getJson("/api/channels/{$channel->id}/encryption/identities")
        ->assertOk()
        ->json('data');

    // Both devices, the caller's own included — comparing your laptop against your phone is
    // a reasonable thing to want.
    expect($identities)->toHaveCount(2)
        ->and(collect($identities)->pluck('device_id')->sort()->values()->all())
        ->toBe(['member-phone', 'owner-laptop']);

    // Nothing consumed.
    expect(DeviceKey::where('device_id', 'member-phone')->first()->oneTimePrekeys()->count())->toBe(2);
});

it('keeps identity keys inside the channel', function () {
    [, , $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();
    registerDeviceFor(User::factory()->create(), 'outsider-laptop');

    $this->getJson("/api/channels/{$channel->id}/encryption/identities")->assertForbidden();
});

it('distributes a sender key and delivers it to the right inbox', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone');
    registerDeviceFor($owner, 'owner-laptop');
    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertOk();

    $recipient = DeviceKey::where('device_id', 'member-phone')->first();

    $this->postJson("/api/channels/{$channel->id}/encryption/sender-keys", [
        'device_id' => 'owner-laptop',
        'epoch' => 1,
        'keys' => [wrappedKeyFor($recipient)],
    ])->assertOk()->assertJsonPath('data.stored', 1);

    Passport::actingAs($member);
    $inbox = $this->postJson("/api/channels/{$channel->id}/encryption/inbox", [
        'device_id' => 'member-phone',
    ])->assertOk()->json('data');

    expect($inbox)->toHaveCount(1)
        ->and($inbox[0]['epoch'])->toBe(1)
        // Stamped with the *sending device*, because that is what message envelopes carry.
        ->and($inbox[0]['sender_device_id'])->toBe('owner-laptop');
});

it('shows a device only what was addressed to it', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone');
    registerDeviceFor($owner, 'owner-laptop');
    registerDeviceFor($owner, 'owner-phone');
    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertOk();

    $this->postJson("/api/channels/{$channel->id}/encryption/sender-keys", [
        'device_id' => 'owner-laptop',
        'epoch' => 1,
        'keys' => [wrappedKeyFor(DeviceKey::where('device_id', 'owner-phone')->first())],
    ])->assertOk();

    // Addressed to the owner's other device, so the member's phone sees nothing at all.
    Passport::actingAs($member);
    $this->postJson("/api/channels/{$channel->id}/encryption/inbox", ['device_id' => 'member-phone'])
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('replaces a redistributed key rather than stacking up duplicates', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone');
    registerDeviceFor($owner, 'owner-laptop');
    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertOk();

    $recipient = DeviceKey::where('device_id', 'member-phone')->first();

    foreach (range(1, 2) as $ignored) {
        $this->postJson("/api/channels/{$channel->id}/encryption/sender-keys", [
            'device_id' => 'owner-laptop',
            'epoch' => 1,
            'keys' => [wrappedKeyFor($recipient)],
        ])->assertOk();
    }

    expect(SenderKey::where('channel_id', $channel->id)->count())->toBe(1);
});

it('drops a key addressed to a device outside the channel', function () {
    // An inbox anybody can write into is a place to hide things. The blob would be useless
    // to that device anyway — it can't reproduce the session — but the row shouldn't exist.
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone');
    registerDeviceFor($owner, 'owner-laptop');
    $stranger = User::factory()->create();
    registerDeviceFor($stranger, 'stranger-laptop');

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertOk();

    $this->postJson("/api/channels/{$channel->id}/encryption/sender-keys", [
        'device_id' => 'owner-laptop',
        'epoch' => 1,
        'keys' => [
            wrappedKeyFor(DeviceKey::where('device_id', 'member-phone')->first()),
            wrappedKeyFor(DeviceKey::where('device_id', 'stranger-laptop')->first()),
        ],
    ])->assertOk()->assertJsonPath('data.stored', 1);

    expect(SenderKey::where('channel_id', $channel->id)->count())->toBe(1);
});

it('narrows bundles to the devices a sender is missing', function () {
    /*
     * The late-joiner case, and the reason this filter exists.
     *
     * Somebody opens the app in a new browser profile halfway through a conversation. Every
     * existing sender already handed out its chain, so nobody ever gives one to the new
     * device and it reads "this device doesn't have the key" on everything. The sender has to
     * top up — but without a filter it would re-fetch every bundle in the channel and burn a
     * one-time prekey on every device to reach the one that is new.
     */
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone', 3);
    registerDeviceFor($member, 'member-laptop', 3);
    registerDeviceFor($owner, 'owner-laptop', 3);

    $newcomer = DeviceKey::where('device_id', 'member-laptop')->first();

    Passport::actingAs($owner);
    $bundles = $this->postJson("/api/channels/{$channel->id}/encryption/bundles", [
        'device_id' => 'owner-laptop',
        'device_key_ids' => [$newcomer->id],
    ])->assertOk()->json('data');

    expect($bundles)->toHaveCount(1)
        ->and($bundles[0]['device_id'])->toBe('member-laptop');

    // Only the named device paid; the one already reached keeps its full stock.
    expect($newcomer->oneTimePrekeys()->count())->toBe(2)
        ->and(DeviceKey::where('device_id', 'member-phone')->first()->oneTimePrekeys()->count())->toBe(3);
});

it('still refuses a device outside the channel, even when named directly', function () {
    // The filter narrows, it must never widen: naming a device explicitly cannot be a way
    // round the membership rule.
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone');
    registerDeviceFor($owner, 'owner-laptop');
    $stranger = User::factory()->create();
    registerDeviceFor($stranger, 'stranger-laptop');

    Passport::actingAs($owner);
    $bundles = $this->postJson("/api/channels/{$channel->id}/encryption/bundles", [
        'device_id' => 'owner-laptop',
        'device_key_ids' => [DeviceKey::where('device_id', 'stranger-laptop')->first()->id],
    ])->assertOk()->json('data');

    expect($bundles)->toHaveCount(0);
});

it('tells the room when sender keys have been left for it', function () {
    // Without the nudge, a client with the channel already open never learns its key has
    // arrived — it sits on "can't read this" until somebody reloads the page, which reads as
    // the encryption being broken rather than as a race.
    Event::fake([SenderKeysDistributed::class]);

    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone');
    registerDeviceFor($owner, 'owner-laptop');
    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertOk();

    $this->postJson("/api/channels/{$channel->id}/encryption/sender-keys", [
        'device_id' => 'owner-laptop',
        'epoch' => 1,
        'keys' => [wrappedKeyFor(DeviceKey::where('device_id', 'member-phone')->first())],
    ])->assertOk();

    Event::assertDispatched(SenderKeysDistributed::class, fn ($event) => $event->epoch === 1
        && $event->channel->id === $channel->id);
});

it('stays quiet when a distribution stored nothing', function () {
    // Every recipient was outside the channel and dropped. Waking every client to refetch an
    // inbox that hasn't changed is noise.
    Event::fake([SenderKeysDistributed::class]);

    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone');
    registerDeviceFor($owner, 'owner-laptop');
    $stranger = User::factory()->create();
    registerDeviceFor($stranger, 'stranger-laptop');

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertOk();

    $this->postJson("/api/channels/{$channel->id}/encryption/sender-keys", [
        'device_id' => 'owner-laptop',
        'epoch' => 1,
        'keys' => [wrappedKeyFor(DeviceKey::where('device_id', 'stranger-laptop')->first())],
    ])->assertOk()->assertJsonPath('data.stored', 0);

    Event::assertNotDispatched(SenderKeysDistributed::class);
});

it('names the devices that still need a sender’s chain', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone');
    registerDeviceFor($member, 'member-laptop');
    registerDeviceFor($owner, 'owner-laptop');
    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertOk();

    $phone = DeviceKey::where('device_id', 'member-phone')->first();
    $laptop = DeviceKey::where('device_id', 'member-laptop')->first();

    // Nothing delivered yet: both of the other devices are pending, and never our own.
    expect($this->postJson("/api/channels/{$channel->id}/encryption/pending", [
        'device_id' => 'owner-laptop', 'epoch' => 1,
    ])->assertOk()->json('data'))->toEqualCanonicalizing([$phone->id, $laptop->id]);

    $this->postJson("/api/channels/{$channel->id}/encryption/sender-keys", [
        'device_id' => 'owner-laptop',
        'epoch' => 1,
        'keys' => [wrappedKeyFor($phone)],
    ])->assertOk();

    expect($this->postJson("/api/channels/{$channel->id}/encryption/pending", [
        'device_id' => 'owner-laptop', 'epoch' => 1,
    ])->assertOk()->json('data'))->toBe([$laptop->id]);
});

it('puts a device back in the queue when it gives up on a key it cannot open', function () {
    /*
     * The recovery path for the worst failure this system had.
     *
     * Unwrapping a sender key consumes the one-time prekey it was wrapped against. If the
     * attempt then fails to store the result — as it did when every chain write threw — the
     * prekey is spent, the key is unopenable forever, and the row still looks delivered. The
     * device is permanently deaf to that sender and no amount of retrying helps, because the
     * sender has no reason to send again.
     */
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone');
    registerDeviceFor($owner, 'owner-laptop');
    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertOk();

    $phone = DeviceKey::where('device_id', 'member-phone')->first();
    $this->postJson("/api/channels/{$channel->id}/encryption/sender-keys", [
        'device_id' => 'owner-laptop', 'epoch' => 1, 'keys' => [wrappedKeyFor($phone)],
    ])->assertOk();

    // The recipient can't open it, and says so.
    Passport::actingAs($member);
    $this->postJson("/api/channels/{$channel->id}/encryption/reject", [
        'device_id' => 'member-phone',
        'epoch' => 1,
        'sender_device_id' => 'owner-laptop',
    ])->assertOk()->assertJsonPath('data.discarded', 1);

    expect(SenderKey::where('channel_id', $channel->id)->count())->toBe(0);

    // …and the sender is told to send again, which is the whole point.
    Passport::actingAs($owner);
    expect($this->postJson("/api/channels/{$channel->id}/encryption/pending", [
        'device_id' => 'owner-laptop', 'epoch' => 1,
    ])->assertOk()->json('data'))->toBe([$phone->id]);
});

it('will not let one member discard a key addressed to somebody else', function () {
    // Deleting another device's inbound key would be a way to cut them out of the
    // conversation quietly — they would simply stop being able to read.
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone');
    registerDeviceFor($owner, 'owner-laptop');
    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertOk();

    $this->postJson("/api/channels/{$channel->id}/encryption/sender-keys", [
        'device_id' => 'owner-laptop',
        'epoch' => 1,
        'keys' => [wrappedKeyFor(DeviceKey::where('device_id', 'member-phone')->first())],
    ])->assertOk();

    // The owner names their *own* device, so the key addressed to the member is untouched.
    $this->postJson("/api/channels/{$channel->id}/encryption/reject", [
        'device_id' => 'owner-laptop',
        'epoch' => 1,
        'sender_device_id' => 'owner-laptop',
    ])->assertOk()->assertJsonPath('data.discarded', 0);

    expect(SenderKey::where('channel_id', $channel->id)->count())->toBe(1);
});

it('refuses to seed an era that has not started', function () {
    // Naming a future epoch would let somebody pre-place a key for an era nobody has begun,
    // and confuse every client about which key is current the moment it does.
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone');
    registerDeviceFor($owner, 'owner-laptop');
    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertOk();

    $this->postJson("/api/channels/{$channel->id}/encryption/sender-keys", [
        'device_id' => 'owner-laptop',
        'epoch' => 9,
        'keys' => [wrappedKeyFor(DeviceKey::where('device_id', 'member-phone')->first())],
    ])->assertStatus(422)->assertJsonValidationErrors('epoch');
});

it('still serves the keys of an era that has since ended', function () {
    // Encryption gets turned off; the messages from while it was on stay encrypted forever,
    // so their keys have to remain fetchable or that history becomes unreadable.
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone');
    registerDeviceFor($owner, 'owner-laptop');
    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertOk();

    $this->postJson("/api/channels/{$channel->id}/encryption/sender-keys", [
        'device_id' => 'owner-laptop',
        'epoch' => 1,
        'keys' => [wrappedKeyFor(DeviceKey::where('device_id', 'member-phone')->first())],
    ])->assertOk();

    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => false])->assertOk();

    Passport::actingAs($member);
    $this->postJson("/api/channels/{$channel->id}/encryption/inbox", ['device_id' => 'member-phone'])
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('refuses an inbox to somebody who has left the channel’s server', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();

    registerDeviceFor($member, 'member-phone');
    registerDeviceFor($owner, 'owner-laptop');

    $server->members()->detach($member->id);

    Passport::actingAs($member);
    $this->postJson("/api/channels/{$channel->id}/encryption/inbox", ['device_id' => 'member-phone'])
        ->assertForbidden();
});

it('works the same in a DM as in a server channel', function () {
    [$a, $b, $conversation] = dmBetween();

    registerDeviceFor($b, 'b-phone');
    registerDeviceFor($a, 'a-laptop');
    Passport::actingAs($a);
    $this->putJson("/api/channels/{$conversation->channel->id}/encryption", ['encrypted' => true])->assertOk();

    $bundles = $this->postJson("/api/channels/{$conversation->channel->id}/encryption/bundles", [
        'device_id' => 'a-laptop',
    ])->assertOk()->json('data');

    expect($bundles)->toHaveCount(1)->and($bundles[0]['device_id'])->toBe('b-phone');
});
