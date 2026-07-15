<?php

use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Reaction;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

beforeEach(function () {
    Storage::fake('local');
});

it('deletes a channel with every message, thread and file under it', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    // A file on the main timeline...
    $this->post("/api/channels/{$channel->id}/messages", [
        'body' => 'on the timeline',
        'attachments' => [UploadedFile::fake()->create('timeline.png', 20)],
    ])->assertCreated();

    // ...and one buried in a thread, which is the case a non-recursive delete forgets.
    $parent = Message::where('channel_id', $channel->id)->first();
    $thread = Thread::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
        'message_id' => $parent->id,
    ]);
    $this->post("/api/threads/{$thread->id}/messages", [
        'body' => 'in a thread',
        'attachments' => [UploadedFile::fake()->create('thread.pdf', 30, 'application/pdf')],
    ])->assertCreated();

    $paths = Attachment::pluck('path');
    expect($paths)->toHaveCount(2);
    $paths->each(fn ($path) => Storage::disk('local')->assertExists($path));

    $this->deleteJson("/api/channels/{$channel->id}")->assertNoContent();

    expect(Channel::find($channel->id))->toBeNull()
        ->and(Message::where('channel_id', $channel->id)->count())->toBe(0)
        ->and(Thread::where('channel_id', $channel->id)->count())->toBe(0)
        ->and(Attachment::count())->toBe(0);

    // The bytes are gone too — not just the rows pointing at them.
    $paths->each(fn ($path) => Storage::disk('local')->assertMissing($path));
    // ...and so is the channel's upload directory itself.
    expect(Storage::disk('local')->exists("attachments/{$channel->id}"))->toBeFalse();
});

it('leaves other channels files untouched when one channel is deleted', function () {
    [$owner, $server, $doomed] = ownerWithChannel();
    $keeper = Channel::factory()->create(['server_id' => $server->id]);
    Passport::actingAs($owner);

    $this->post("/api/channels/{$doomed->id}/messages", [
        'attachments' => [UploadedFile::fake()->create('doomed.png', 10)],
    ])->assertCreated();
    $this->post("/api/channels/{$keeper->id}/messages", [
        'attachments' => [UploadedFile::fake()->create('keeper.png', 10)],
    ])->assertCreated();

    $survivor = Attachment::whereIn(
        'message_id', Message::where('channel_id', $keeper->id)->select('id')
    )->sole();

    $this->deleteJson("/api/channels/{$doomed->id}")->assertNoContent();

    expect(Attachment::count())->toBe(1);
    Storage::disk('local')->assertExists($survivor->path);
});

it('cleans up reactions and read markers with the channel', function () {
    [$owner, , $channel] = ownerWithChannel();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    Reaction::factory()->create(['message_id' => $message->id, 'user_id' => $owner->id]);

    Passport::actingAs($owner);
    $this->postJson("/api/channels/{$channel->id}/read")->assertOk();

    expect(Reaction::count())->toBe(1);

    $this->deleteJson("/api/channels/{$channel->id}")->assertNoContent();

    expect(Reaction::count())->toBe(0)
        ->and(DB::table('channel_reads')->where('channel_id', $channel->id)->count())->toBe(0);
});

it('lets only the owner delete a channel', function () {
    [, $server, $channel] = ownerWithChannel();

    // A plain member may create channels, but not destroy them.
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);
    Passport::actingAs($member);
    $this->deleteJson("/api/channels/{$channel->id}")->assertForbidden();

    // A stranger gets nothing either.
    Passport::actingAs(User::factory()->create());
    $this->deleteJson("/api/channels/{$channel->id}")->assertForbidden();

    expect(Channel::find($channel->id))->not->toBeNull();
});
