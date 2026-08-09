<?php

use App\Events\ChannelEncryptionToggled;
use App\Jobs\DeliverBotEvent;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

/*
 * End-to-end encryption, phases one and two: the flag and its consequences.
 *
 * Nothing here encrypts anything — there is no crypto yet, and that is the point of testing
 * it in this order. Everything that reads a message body has to degrade correctly when told
 * it may not, and recover when told it may again, and all of that is provable against a
 * boolean. If these pass, the crypto that lands on top has one job instead of five.
 *
 * The two properties worth stating outright, because most of the file is one or the other:
 *
 *  - **Default off.** Every conversation and channel that exists behaves exactly as it did.
 *  - **Forward only, both ways.** Turning it on doesn't reach back and encrypt history;
 *    turning it off doesn't reach back and decrypt it. What a message *is* was decided when
 *    it was sent, and the toggle can't revise it.
 */

/**
 * A timeline with encryption already on, and the staff member who turned it on.
 *
 * The *discussion*, not the channel it hangs off. Encryption is a property of a timeline
 * and a container channel hasn't got one — its messages live in its discussions, each of
 * which is a channel in its own right and gets its own switch. See ToggleEncryptionRequest.
 */
function encryptedChannel(): array
{
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);
    $discussion = $channel->discussions()->first();

    Passport::actingAs($owner);
    test()->putJson("/api/channels/{$discussion->id}/encryption", ['encrypted' => true])->assertOk();

    return [$owner, $member, $discussion->fresh()];
}

it('leaves every channel and chat unencrypted by default', function () {
    [$owner, , $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);
    [$a, , $conversation] = dmBetween();

    // `fresh()`, not the in-memory model: the default lives on the column.
    expect($channel->fresh()->isEncrypted())->toBeFalse()
        ->and($channel->fresh()->encryption_epoch)->toBe(0)
        ->and($conversation->channel->fresh()->isEncrypted())->toBeFalse();

    Passport::actingAs($owner);
    $this->getJson("/api/servers/{$server->id}/channels")
        ->assertOk()
        ->assertJsonPath('data.0.encrypted', false);

    Passport::actingAs($a);
    $this->getJson('/api/conversations')->assertOk()->assertJsonPath('data.0.encrypted', false);
});

it('lets staff turn encryption on, and starts a fresh key era', function () {
    [$owner, , $server] = twoMembers();
    $discussion = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$discussion->id}/encryption", ['encrypted' => true])
        ->assertOk()
        ->assertJsonPath('data.encrypted', true)
        ->assertJsonPath('data.encryption_epoch', 1);

    expect($discussion->fresh()->encryption_toggled_by)->toBe($owner->id);
});

it('refuses to lock a channel that holds discussions rather than messages', function () {
    // Its timeline is not where anybody is talking, so a padlock here would be a padlock on
    // nothing — drawn above every discussion it contains, each of which is still plaintext.
    [$owner, , $server] = twoMembers();
    $container = Channel::factory()->create(['server_id' => $server->id]);
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$container->id}/encryption", ['encrypted' => true])->assertForbidden();
});

it('never reuses an epoch, so a key era can only ever move forward', function () {
    [$owner, , $channel] = encryptedChannel();
    Passport::actingAs($owner);

    // Off, then on again: the second encrypted era is *not* the first one resumed. Anyone who
    // held the old sender key and was removed during the gap must stay locked out of it.
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => false])->assertOk();
    expect($channel->fresh()->encryption_epoch)->toBe(1);

    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])
        ->assertOk()
        ->assertJsonPath('data.encryption_epoch', 2);
});

it('does nothing at all when the toggle is already where it is being set', function () {
    [$owner, , $channel] = encryptedChannel();
    Passport::actingAs($owner);
    $before = Message::where('channel_id', $channel->id)->count();

    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertOk();

    // No burnt epoch, and no second "turned on encryption" notice on the timeline.
    expect($channel->fresh()->encryption_epoch)->toBe(1)
        ->and(Message::where('channel_id', $channel->id)->count())->toBe($before);
});

it('records the change on the timeline and tells the room live', function () {
    Event::fake([ChannelEncryptionToggled::class]);
    [$owner, , $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertOk();

    $notice = Message::where('channel_id', $channel->id)->latest('id')->first();

    expect($notice->isSystem())->toBeTrue()
        ->and($notice->body)->toContain('turned on end-to-end encryption')
        // The notice is *about* the channel and has to stay readable — including by people
        // who can't decrypt the era it announces.
        ->and($notice->isEncrypted())->toBeFalse();

    Event::assertDispatched(ChannelEncryptionToggled::class);
});

it('refuses the toggle to a plain server member', function () {
    [, $member, $server] = twoMembers();
    $discussion = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();
    Passport::actingAs($member);

    $this->putJson("/api/channels/{$discussion->id}/encryption", ['encrypted' => true])->assertForbidden();
    expect($discussion->fresh()->isEncrypted())->toBeFalse();
});

it('refuses the toggle to someone outside the channel entirely', function () {
    [, , $server] = twoMembers();
    $discussion = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();
    Passport::actingAs(User::factory()->create());

    $this->putJson("/api/channels/{$discussion->id}/encryption", ['encrypted' => true])->assertForbidden();
});

it('lets either person in a DM encrypt it', function () {
    // Not the sender-goes-first rule an owner check would amount to: both are equally
    // exposed by the setting, so both may change it.
    [, $b, $conversation] = dmBetween();
    Passport::actingAs($b);

    $this->putJson("/api/channels/{$conversation->channel->id}/encryption", ['encrypted' => true])
        ->assertOk()
        ->assertJsonPath('data.encrypted', true);
});

it('lets only the owner encrypt a group chat', function () {
    [$owner, $member] = twoMembers();
    $conversation = Conversation::factory()
        ->withMembers([$owner, $member])
        ->create(['type' => 'group', 'owner_id' => $owner->id]);
    $channel = $conversation->load('channel')->channel;

    Passport::actingAs($member);
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertForbidden();

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertOk();
});

/*
 * Phase two: what the flag does to everything that reads a body.
 */

it('tells the timeline what a message sent now would be', function () {
    // The composer's only source of truth. A client that had to be *told* the channel was
    // encrypted — rather than being told by the page it was already fetching — would post in
    // the clear whenever nobody remembered to tell it, which is the one failure that matters.
    [$owner, , $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();
    Passport::actingAs($owner);

    $this->getJson("/api/channels/{$channel->id}/messages")
        ->assertOk()
        ->assertJsonPath('encryption.encrypted', false)
        ->assertJsonPath('encryption.epoch', 0);

    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertOk();

    $this->getJson("/api/channels/{$channel->id}/messages")
        ->assertOk()
        ->assertJsonPath('encryption.encrypted', true)
        ->assertJsonPath('encryption.epoch', 1);
});

it('stamps a message with the era it was sent in, and never revises it', function () {
    [$owner, , $channel] = encryptedChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'ciphertext-stand-in'])
        ->assertCreated()
        ->assertJsonPath('data.encrypted', true)
        ->assertJsonPath('data.epoch', 1);

    $sent = Message::where('body', 'ciphertext-stand-in')->first();

    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => false])->assertOk();

    // The channel is plaintext again; the message it already holds is not, and can't be.
    expect($sent->fresh()->isEncrypted())->toBeTrue()
        ->and($sent->fresh()->epoch)->toBe(1);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'readable again'])
        ->assertCreated()
        ->assertJsonPath('data.encrypted', false)
        ->assertJsonPath('data.epoch', null);
});

it('leaves history alone when encryption is turned on', function () {
    [$owner, , $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'said in the open'])->assertCreated();
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => true])->assertOk();

    // Still plaintext, and still searchable. Turning encryption on protects the future; the
    // server has had this row, its backups and its index all along, and pretending otherwise
    // would be the one dishonest thing this feature could do.
    expect(Message::where('body', 'said in the open')->first()->isEncrypted())->toBeFalse();

    $this->getJson('/api/search?q=said&type=messages')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('keeps encrypted messages out of search and says how many it skipped', function () {
    [$owner, , $channel] = encryptedChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'needle in ciphertext'])->assertCreated();

    // The term appears verbatim in the stored body and still must not match: the row is
    // flagged encrypted, and search doesn't look inside those regardless of what they hold.
    $this->getJson('/api/search?q=needle&type=messages')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('encrypted_skipped', 1);
});

it('restores search for everything sent after encryption is turned off', function () {
    [$owner, , $channel] = encryptedChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'sealed needle'])->assertCreated();
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => false])->assertOk();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'open needle'])->assertCreated();

    // One era back in the index, one era gone for good — exactly one hit, and the count says
    // what's still invisible rather than leaving the gap unexplained.
    $this->getJson('/api/search?q=needle&type=messages')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', 'open needle')
        ->assertJsonPath('encrypted_skipped', 1);
});

it('stops delivering messages to bots while a channel is encrypted', function () {
    Queue::fake();
    [$owner, , $channel] = encryptedChannel();
    [$bot] = botOn($channel->server);
    $bot->update(['webhook_url' => 'https://example.test/hook', 'events' => ['message.created']]);
    $channel->server->members()->syncWithoutDetaching([$bot->user_id => ['role' => 'member']]);

    Passport::actingAs($owner);
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'sealed'])->assertCreated();

    Queue::assertNotPushed(DeliverBotEvent::class);

    // ...and hears everything again the moment it's turned off.
    $this->putJson("/api/channels/{$channel->id}/encryption", ['encrypted' => false])->assertOk();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'in the open'])->assertCreated();

    Queue::assertPushed(DeliverBotEvent::class);
});

it('does not unfurl links in an encrypted channel', function () {
    [$owner, , $channel] = encryptedChannel();
    Passport::actingAs($owner);

    // A URL sitting in plain sight in the body still doesn't get fetched: the flag decides,
    // not the contents. Otherwise a client that encrypted properly would be the only one
    // protected, and the server would be phoning a third party about the rest.
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'https://example.test/page'])
        ->assertCreated()
        ->assertJsonPath('data.link_previews', []);

    expect(Message::where('channel_id', $channel->id)->latest('id')->first()->linkPreviews()->count())->toBe(0);
});

it('forgets an encrypted attachment’s name and type', function () {
    // A filename is often the most revealing thing about a document, so storing the real one
    // beside an encrypted file would give away most of what the encryption was for. The real
    // values travel sealed in the message envelope; the columns get neutral placeholders.
    [$owner, , $channel] = encryptedChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'envelope-stand-in',
        'attachments' => [UploadedFile::fake()->create('Q3 redundancies.xlsx', 12, 'application/vnd.ms-excel')],
    ])->assertCreated();

    $attachment = Attachment::latest('id')->first();

    expect($attachment->isEncrypted())->toBeTrue()
        ->and($attachment->name)->toBe('Encrypted file')
        ->and($attachment->name)->not->toContain('redundancies')
        ->and($attachment->mime_type)->toBe('application/octet-stream')
        ->and($attachment->extension)->toBeNull()
        // Size is the one thing that can't be hidden — the bytes are on disk — and pretending
        // otherwise would be worse than admitting it.
        ->and($attachment->size)->toBeGreaterThan(0);
});

it('refuses to guess what an encrypted attachment is', function () {
    // Every content question answers no, because the stored MIME type is a placeholder.
    // A true `is_image` here would have the client draw a broken picture and a thumbnailer
    // chew on ciphertext.
    [$owner, , $channel] = encryptedChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'envelope-stand-in',
        'attachments' => [UploadedFile::fake()->create('holiday.png', 20, 'image/png')],
    ])->assertCreated()
        ->assertJsonPath('data.attachments.0.encrypted', true)
        ->assertJsonPath('data.attachments.0.is_image', false);
});

it('leaves attachments alone in an unencrypted channel', function () {
    [$owner, , $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id])->discussions()->first();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'here you go',
        'attachments' => [UploadedFile::fake()->create('holiday.png', 20, 'image/png')],
    ])->assertCreated()
        ->assertJsonPath('data.attachments.0.encrypted', false)
        ->assertJsonPath('data.attachments.0.is_image', true)
        ->assertJsonPath('data.attachments.0.name', 'holiday.png');
});

it('leaves attachments in the clear when the deployment turns file encryption off', function () {
    /*
     * The escape hatch for an object store. Encrypted files cannot be streamed or
     * range-requested and have to be fetched by JavaScript, which is the wrong trade on some
     * deployments — see config/uploads.php.
     *
     * What must *not* change is the message itself: the body stays encrypted. Only the file
     * travels readable, and the attachment says so on its own row rather than the app
     * inferring it from a setting that may have changed since.
     */
    config()->set('uploads.encrypt_attachments', false);

    [$owner, , $channel] = encryptedChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'envelope-stand-in',
        'attachments' => [UploadedFile::fake()->create('holiday.png', 20, 'image/png')],
    ])->assertCreated()
        // The message is still ciphertext…
        ->assertJsonPath('data.encrypted', true)
        // …and the file is not, with its real name and type intact.
        ->assertJsonPath('data.attachments.0.encrypted', false)
        ->assertJsonPath('data.attachments.0.name', 'holiday.png')
        ->assertJsonPath('data.attachments.0.is_image', true);
});

it('tells the client whether files are encrypted', function () {
    // The composer seals big files at pick time, long before the send, so it has to know
    // this up front rather than discovering it on the way out.
    [$owner, , $channel] = encryptedChannel();
    Passport::actingAs($owner);

    $this->getJson("/api/channels/{$channel->id}/messages")
        ->assertOk()
        ->assertJsonPath('encryption.files', true);

    config()->set('uploads.encrypt_attachments', false);

    $this->getJson("/api/channels/{$channel->id}/messages")
        ->assertOk()
        ->assertJsonPath('encryption.files', false);
});

it('does not treat an encrypted body as a command', function () {
    [$owner, , $channel] = encryptedChannel();
    Passport::actingAs($owner);

    // Ciphertext that happens to open with a command prefix is not a command. Before the
    // guard this would have been parsed, answered, and never stored as a message at all.
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/roll 2d6'])
        ->assertCreated()
        ->assertJsonPath('data.encrypted', true)
        ->assertJsonPath('data.body', '/roll 2d6');
});
