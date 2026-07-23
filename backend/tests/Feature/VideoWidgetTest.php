<?php

use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\SideChat;
use App\Models\Thread;
use App\Models\User;
use App\Models\Widget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

/** The channel's video widget, or null before one's been started. */
function videoWidget(int $channelId): ?Widget
{
    return Widget::where('channel_id', $channelId)->where('type', 'video')->first();
}

/**
 * Every metadata lookup the resolver makes, answered locally. The widget must work without a
 * YouTube API key (that's the keyless path real deployments run on), so no key is configured
 * and only oEmbed is stubbed.
 */
beforeEach(function () {
    config(['services.youtube.key' => null]);

    Http::fake([
        'www.youtube.com/oembed*' => Http::response([
            'title' => 'Big Buck Bunny',
            'author_name' => 'Blender Foundation',
            'thumbnail_url' => 'https://i.ytimg.com/vi/aqz-KE-bpKQ/mqdefault.jpg',
        ]),
        'vimeo.com/api/oembed.json*' => Http::response([
            'title' => 'A Vimeo film',
            'author_name' => 'Someone',
            'duration' => 212,
            'thumbnail_url' => 'https://i.vimeocdn.com/x.jpg',
        ]),
        '*' => Http::response([], 404),
    ]);
});

it('adds a YouTube link with `v!play`, drops a card and starts it playing', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $res = $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'v!play https://www.youtube.com/watch?v=aqz-KE-bpKQ',
    ])->assertCreated();

    expect($res->json('data.type'))->toBe('widget')
        ->and($res->json('data.widget.type'))->toBe('video');

    $state = videoWidget($channel->id)->state;
    expect($state['playlist'])->toHaveCount(1)
        ->and($state['playlist'][0]['kind'])->toBe('youtube')
        ->and($state['playlist'][0]['key'])->toBe('aqz-KE-bpKQ')
        ->and($state['playlist'][0]['title'])->toBe('Big Buck Bunny')
        ->and($state['playlist'][0]['addedBy'])->toBe($user->name)
        // Adding to an empty playlist seats it — the room starts watching immediately.
        ->and($state['currentIndex'])->toBe(0)
        ->and($state['status'])->toBe('playing');
});

it('classifies each kind of link by the player that can drive it', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'v!play https://vimeo.com/76979871'])->assertCreated();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'v!play https://example.com/clips/holiday.mp4'])->assertCreated();

    $playlist = videoWidget($channel->id)->state['playlist'];

    // Vimeo is an iframe we can start but not steer…
    expect($playlist[0]['kind'])->toBe('embed')
        ->and($playlist[0]['provider'])->toBe('vimeo')
        ->and($playlist[0]['embedUrl'])->toBe('https://player.vimeo.com/video/76979871')
        ->and($playlist[0]['duration'])->toBe(212)
        // …a direct file is a plain <video>, which stays in lockstep.
        ->and($playlist[1]['kind'])->toBe('file')
        ->and($playlist[1]['provider'])->toBe('direct')
        ->and($playlist[1]['url'])->toBe('https://example.com/clips/holiday.mp4')
        ->and($playlist[1]['title'])->toBe('holiday.mp4');
});

it('refuses a link it has no player for, without touching the playlist', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $res = $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'v!play https://example.com/an/article',
    ])->assertOk();

    // An ephemeral note to the actor — never a real message, never a widget card. (The widget
    // row itself is minted by firstOrCreate before the handler ever sees the command, as it is
    // for every widget type; what matters is that a bad link leaves it empty and idle.)
    expect($res->json('data.type'))->toBe('system')
        ->and($res->json('data.body'))->toContain("don't know how to play that link");

    $state = videoWidget($channel->id)->state;
    expect($state['playlist'])->toBeEmpty()
        ->and($state['currentIndex'])->toBeNull()
        ->and($state['status'])->toBe('idle');
});

it('drives the shared transport through card actions', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'v!play https://youtu.be/aqz-KE-bpKQ'])->assertCreated();
    $widget = videoWidget($channel->id);

    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'pause'])->assertNoContent();
    expect($widget->fresh()->state['status'])->toBe('paused');

    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'seek', 'payload' => ['position' => 95.5]])->assertNoContent();
    expect($widget->fresh()->state['position'])->toBe(95.5);

    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'resume'])->assertNoContent();
    expect($widget->fresh()->state['status'])->toBe('playing');
});

it('only lets the first `ended` report advance the playlist', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'v!play https://youtu.be/aqz-KE-bpKQ'])->assertCreated();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'v!play https://example.com/second.mp4'])->assertCreated();

    $widget = videoWidget($channel->id);
    $firstId = $widget->state['playlist'][0]['id'];

    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'ended', 'payload' => ['id' => $firstId]])->assertNoContent();
    expect($widget->fresh()->state['currentIndex'])->toBe(1);

    // A second viewer's player fires the same event a beat later, still naming the old video.
    // It must not skip the one that just started.
    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'ended', 'payload' => ['id' => $firstId]])->assertNoContent();
    expect($widget->fresh()->state['currentIndex'])->toBe(1);
});

it('keeps the playlist on `v!stop` but empties it on `v!clear`', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'v!play https://youtu.be/aqz-KE-bpKQ'])->assertCreated();
    $widget = videoWidget($channel->id);

    // Stop is "the room stops watching", not "throw the evening's viewing away" — the
    // divergence from the music widget, whose m!stop empties the queue.
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'v!stop'])->assertOk();
    expect($widget->fresh()->state['status'])->toBe('idle')
        ->and($widget->fresh()->state['playlist'])->toHaveCount(1);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'v!clear'])->assertOk();
    expect($widget->fresh()->state['playlist'])->toBeEmpty()
        ->and($widget->fresh()->state['currentIndex'])->toBeNull();
});

it('plays an uploaded file, serving it over a signed URL and never leaking its path', function () {
    Storage::fake('local');
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    // Stage the file the ordinary way — the same two-step any large attachment takes.
    $bytes = 'fake-mp4-bytes';
    $started = $this->postJson('/api/uploads', [
        'name' => 'holiday.mp4',
        'size' => strlen($bytes),
        'mime_type' => 'video/mp4',
        'total_chunks' => 1,
    ])->assertCreated();

    $uploadId = $started->json('data.id');
    $this->post("/api/uploads/{$uploadId}/chunks", [
        'index' => 0,
        'chunk' => UploadedFile::fake()->createWithContent('chunk', $bytes),
    ])->assertOk();

    // Open the widget, then hand it the staged file.
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'v!play https://youtu.be/aqz-KE-bpKQ'])->assertCreated();
    $widget = videoWidget($channel->id);
    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'upload', 'payload' => ['upload' => $uploadId]])
        ->assertNoContent();

    $stored = $widget->fresh()->state['playlist'][1];
    expect($stored['provider'])->toBe('upload')
        ->and($stored['kind'])->toBe('file')
        ->and($stored['title'])->toBe('holiday.mp4')
        // Stored in the channel's own folder, so deleting the channel sweeps it with the rest.
        ->and($stored['path'])->toStartWith("attachments/{$channel->id}/");
    Storage::disk('local')->assertExists($stored['path']);

    // What the *API* hands back is a signed URL and no disk location at all.
    $shown = $this->getJson("/api/widgets/{$widget->id}")->assertOk()->json('data.state.playlist.1');
    expect($shown)->not->toHaveKey('path')
        ->and($shown)->not->toHaveKey('disk')
        ->and($shown['url'])->toContain("/widgets/{$widget->id}/video/{$stored['id']}")
        ->and($shown['url'])->toContain('signature=');

    // And that URL actually serves the bytes.
    $this->get($shown['url'])->assertOk();
});

it('deletes an uploaded clip\'s bytes when it leaves the playlist', function () {
    Storage::fake('local');
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $bytes = 'fake-mp4-bytes';
    $uploadId = $this->postJson('/api/uploads', [
        'name' => 'holiday.mp4', 'size' => strlen($bytes), 'mime_type' => 'video/mp4', 'total_chunks' => 1,
    ])->json('data.id');
    $this->post("/api/uploads/{$uploadId}/chunks", [
        'index' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk', $bytes),
    ])->assertOk();

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'v!play https://youtu.be/aqz-KE-bpKQ'])->assertCreated();
    $widget = videoWidget($channel->id);
    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'upload', 'payload' => ['upload' => $uploadId]])->assertNoContent();

    $stored = $widget->fresh()->state['playlist'][1];
    Storage::disk('local')->assertExists($stored['path']);

    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'remove', 'payload' => ['id' => $stored['id']]])->assertNoContent();

    expect($widget->fresh()->state['playlist'])->toHaveCount(1);
    Storage::disk('local')->assertMissing($stored['path']);
});

/**
 * Post a video file into a channel, optionally inside a thread or a side chat, and hand back
 * the attachment. Every message carries its channel whichever branch it's in, which is what
 * lets one channel-scoped query find all three.
 */
function postVideoTo(Channel $channel, User $user, string $name, array $extra = []): Attachment
{
    $message = Message::factory()->create(array_merge([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
    ], $extra));

    return $message->attachments()->create([
        'disk' => 'local',
        'path' => "attachments/{$channel->id}/".Str::random(20).'.mp4',
        'name' => $name,
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'size' => 1024,
    ]);
}

it('lists videos posted anywhere in the chat — timeline, threads and side chats', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $thread = Thread::factory()->create(['channel_id' => $channel->id]);
    $sideChat = SideChat::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    postVideoTo($channel, $user, 'timeline-clip.mp4');
    postVideoTo($channel, $user, 'thread-clip.mp4', ['thread_id' => $thread->id]);
    postVideoTo($channel, $user, 'sidechat-clip.mp4', ['side_chat_id' => $sideChat->id]);

    // A non-video attachment in the same channel must not show up.
    Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id])
        ->attachments()->create([
            'disk' => 'local', 'path' => "attachments/{$channel->id}/notes.pdf", 'name' => 'notes.pdf',
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size' => 10,
        ]);

    $names = collect($this->getJson("/api/channels/{$channel->id}/videos")->assertOk()->json('data'))
        ->pluck('name');

    expect($names)->toHaveCount(3)
        ->and($names)->toContain('timeline-clip.mp4', 'thread-clip.mp4', 'sidechat-clip.mp4')
        ->and($names)->not->toContain('notes.pdf');
});

it('filters the video picker by filename', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    postVideoTo($channel, $user, 'holiday-2026.mp4');
    postVideoTo($channel, $user, 'standup-recording.mp4');

    $names = collect($this->getJson("/api/channels/{$channel->id}/videos?q=holi")->assertOk()->json('data'))
        ->pluck('name');

    expect($names)->toHaveCount(1)->and($names[0])->toBe('holiday-2026.mp4');
});

it('keeps the video picker to members of the chat', function () {
    [, , $channel] = ownerWithChannel();
    $outsider = User::factory()->create();
    Passport::actingAs($outsider);

    $this->getJson("/api/channels/{$channel->id}/videos")->assertForbidden();
});

it('adds a video from the chat by reference, without copying or claiming its bytes', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $sideChat = SideChat::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);
    $attachment = postVideoTo($channel, $user, 'holiday.mp4', ['side_chat_id' => $sideChat->id]);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'v!play https://youtu.be/aqz-KE-bpKQ'])->assertCreated();
    $widget = videoWidget($channel->id);

    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'addAttachment', 'payload' => ['attachment' => $attachment->id]])
        ->assertNoContent();

    $source = $widget->fresh()->state['playlist'][1];
    expect($source['kind'])->toBe('file')
        ->and($source['provider'])->toBe('attachment')
        ->and($source['title'])->toBe('holiday.mp4')
        // Whoever posted it originally, not whoever queued it.
        ->and($source['author'])->toBe($user->name)
        // A reference, not a copy: no disk/path of its own.
        ->and($source)->toHaveKey('attachmentId')
        ->and($source)->not->toHaveKey('path');

    // The viewer gets the attachment's own signed URL, and never the id behind it.
    $shown = $this->getJson("/api/widgets/{$widget->id}")->assertOk()->json('data.state.playlist.1');
    expect($shown)->not->toHaveKey('attachmentId')
        ->and($shown['url'])->toContain("/attachments/{$attachment->id}")
        ->and($shown['url'])->toContain('signature=');
});

it('refuses a file from another chat, or one that isn\'t a video', function () {
    [$user, , $channel] = ownerWithChannel();
    [$stranger, , $otherChannel] = ownerWithChannel();
    Passport::actingAs($user);

    $elsewhere = postVideoTo($otherChannel, $stranger, 'someone-elses.mp4');

    $pdf = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id])
        ->attachments()->create([
            'disk' => 'local', 'path' => "attachments/{$channel->id}/notes.pdf", 'name' => 'notes.pdf',
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size' => 10,
        ]);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'v!play https://youtu.be/aqz-KE-bpKQ'])->assertCreated();
    $widget = videoWidget($channel->id);

    foreach ([$elsewhere->id, $pdf->id] as $id) {
        $res = $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'addAttachment', 'payload' => ['attachment' => $id]])->assertOk();
        expect($res->json('reply'))->toContain('has to be a video posted in this chat');
    }

    expect($widget->fresh()->state['playlist'])->toHaveCount(1);
});

it('flags a borrowed video whose message was deleted, and never deletes the file itself', function () {
    Storage::fake('local');
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $attachment = postVideoTo($channel, $user, 'holiday.mp4');
    Storage::disk('local')->put($attachment->path, 'bytes');

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'v!play https://youtu.be/aqz-KE-bpKQ'])->assertCreated();
    $widget = videoWidget($channel->id);
    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'addAttachment', 'payload' => ['attachment' => $attachment->id]])->assertNoContent();

    $sourceId = $widget->fresh()->state['playlist'][1]['id'];

    // Taking it off the playlist must leave the file in the conversation it was posted in.
    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'remove', 'payload' => ['id' => $sourceId]])->assertNoContent();
    Storage::disk('local')->assertExists($attachment->path);
    expect(Attachment::find($attachment->id))->not->toBeNull();

    // Re-add it, then delete the attachment out from under the playlist.
    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'addAttachment', 'payload' => ['attachment' => $attachment->id]])->assertNoContent();
    $attachment->delete();

    $shown = $this->getJson("/api/widgets/{$widget->id}")->assertOk()->json('data.state.playlist.1');
    expect($shown['url'])->toBeNull()
        ->and($shown['missing'])->toBeTrue();
});

it('refuses to serve a playlist entry that isn\'t a file we host', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'v!play https://youtu.be/aqz-KE-bpKQ'])->assertCreated();
    $widget = videoWidget($channel->id);
    $youtubeId = $widget->state['playlist'][0]['id'];

    // Even correctly signed, the file route only ever serves `provider: upload` entries.
    $url = URL::temporarySignedRoute('widget-videos.show', now()->addHour(), [
        'widget' => $widget->id,
        'source' => $youtubeId,
    ]);

    $this->get($url)->assertNotFound();
});
