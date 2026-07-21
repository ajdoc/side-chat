<?php

use App\Models\Attachment;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

beforeEach(function () {
    Storage::fake('local');
});

it('uploads multiple files with a message and flags images and pdfs', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $response = $this->post("/api/channels/{$channel->id}/messages", [
        'body' => 'here are the files',
        'attachments' => [
            UploadedFile::fake()->create('photo.png', 50),
            UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ],
    ])->assertCreated();

    $attachments = $response->json('data.attachments');

    expect($attachments)->toHaveCount(3)
        ->and($attachments[0]['is_image'])->toBeTrue()
        ->and($attachments[1]['is_pdf'])->toBeTrue()
        ->and($attachments[2]['is_image'])->toBeFalse()
        ->and($attachments[2]['is_pdf'])->toBeFalse()
        ->and($attachments[0]['url'])->toContain('signature=')
        ->and($attachments[0]['download_url'])->toContain('signature=');

    // The files really landed on disk.
    foreach (Attachment::all() as $attachment) {
        Storage::disk('local')->assertExists($attachment->path);
    }
});

it('allows an attachment-only message with no text', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->post("/api/channels/{$channel->id}/messages", [
        'attachments' => [UploadedFile::fake()->create('only.png', 50)],
    ])->assertCreated()->assertJsonPath('data.body', null);
});

it('requires text when there are no attachments', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('body');
});

it('uploads files to a thread message', function () {
    [$user, , $channel] = ownerWithChannel();
    $thread = Thread::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);
    Passport::actingAs($user);

    $this->post("/api/threads/{$thread->id}/messages", [
        'body' => 'thread file',
        'attachments' => [UploadedFile::fake()->create('t.png', 50)],
    ])->assertCreated()->assertJsonCount(1, 'data.attachments');
});

it('serves an attachment only with a valid signature', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $url = $this->post("/api/channels/{$channel->id}/messages", [
        'body' => 'x',
        'attachments' => [UploadedFile::fake()->create('photo.png', 50)],
    ])->json('data.attachments.0.url');

    $this->get($url)->assertOk();

    // Same route without the signature is rejected.
    $id = Attachment::first()->id;
    $this->get("/attachments/{$id}")->assertForbidden();
});

it('serves a byte range so big video seeks instead of downloading whole', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $url = $this->post("/api/channels/{$channel->id}/messages", [
        'body' => 'clip',
        // Real bytes, not `fake()->create()`: that reports a size but writes an empty file,
        // and an empty file has no range to satisfy.
        'attachments' => [UploadedFile::fake()->createWithContent('clip.mov', str_repeat('x', 4096))],
    ])->json('data.attachments.0.url');

    $this->get($url)
        ->assertOk()
        ->assertHeader('Accept-Ranges', 'bytes');

    $this->get($url, ['Range' => 'bytes=0-1023'])
        ->assertStatus(206)
        ->assertHeader('Content-Length', '1024');
});

// ---- editing files ----

it('deletes the old file from disk when an attachment is removed on edit', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $created = $this->post("/api/channels/{$channel->id}/messages", [
        'body' => 'original',
        'attachments' => [UploadedFile::fake()->create('old.png', 50)],
    ])->assertCreated();

    $messageId = $created->json('data.id');
    $old = Attachment::first();
    Storage::disk('local')->assertExists($old->path);

    // Replace it: remove the old, upload a new one (POST + method spoofing for multipart).
    $this->post("/api/messages/{$messageId}", [
        '_method' => 'PATCH',
        'body' => 'replaced',
        'remove_attachment_ids' => [$old->id],
        'attachments' => [UploadedFile::fake()->create('new.png', 50)],
    ])->assertOk()->assertJsonCount(1, 'data.attachments');

    // Old row and old file are gone; the new one exists.
    expect(Attachment::find($old->id))->toBeNull();
    Storage::disk('local')->assertMissing($old->path);

    $new = Attachment::first();
    expect($new->name)->toBe('new.png');
    Storage::disk('local')->assertExists($new->path);
});

it('deletes a single attachment and its file (sender only)', function () {
    [$user, $server, $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->post("/api/channels/{$channel->id}/messages", [
        'body' => 'x',
        'attachments' => [UploadedFile::fake()->create('a.png', 50)],
    ])->assertCreated();

    $attachment = Attachment::first();

    // A different member cannot delete someone else's attachment.
    $other = User::factory()->create();
    $server->members()->attach($other->id, ['role' => 'member']);
    Passport::actingAs($other);
    $this->deleteJson("/api/attachments/{$attachment->id}")->assertForbidden();

    // The sender can.
    Passport::actingAs($user);
    $this->deleteJson("/api/attachments/{$attachment->id}")->assertOk();

    expect(Attachment::find($attachment->id))->toBeNull();
    Storage::disk('local')->assertMissing($attachment->path);
});

// ---- deleting messages purges files ----

it('purges attachment files when the message is deleted', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $id = $this->post("/api/channels/{$channel->id}/messages", [
        'body' => 'x',
        'attachments' => [UploadedFile::fake()->create('a.png', 50), UploadedFile::fake()->create('b.png', 50)],
    ])->json('data.id');

    $paths = Attachment::pluck('path');

    $this->deleteJson("/api/messages/{$id}")->assertNoContent();

    expect(Attachment::count())->toBe(0);
    foreach ($paths as $path) {
        Storage::disk('local')->assertMissing($path);
    }
});

it('purges files of every thread reply when the parent message is deleted (rule 3)', function () {
    [$user, , $channel] = ownerWithChannel();
    $parent = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);
    $thread = Thread::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'message_id' => $parent->id,
    ]);

    Passport::actingAs($user);

    // A thread reply carrying a file.
    $this->post("/api/threads/{$thread->id}/messages", [
        'body' => 'reply with file',
        'attachments' => [UploadedFile::fake()->create('in-thread.png', 50)],
    ])->assertCreated();

    $path = Attachment::first()->path;
    Storage::disk('local')->assertExists($path);

    // Deleting the parent cascades the thread + its replies, and their files.
    $this->deleteJson("/api/messages/{$parent->id}")->assertNoContent();

    expect(Attachment::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

// ---- channel Info > Files ----

it('lists every file posted in a channel', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->post("/api/channels/{$channel->id}/messages", [
        'body' => 'one',
        'attachments' => [UploadedFile::fake()->create('a.png', 50)],
    ])->assertCreated();

    $thread = Thread::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);
    $this->post("/api/threads/{$thread->id}/messages", [
        'body' => 'two',
        'attachments' => [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')],
    ])->assertCreated();

    $this->getJson("/api/channels/{$channel->id}/attachments")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.uploaded_by', $user->name);
});

it('forbids non-members from listing channel files', function () {
    [, , $channel] = ownerWithChannel();
    Passport::actingAs(User::factory()->create());

    $this->getJson("/api/channels/{$channel->id}/attachments")->assertForbidden();
});
