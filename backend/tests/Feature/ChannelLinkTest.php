<?php

use App\Models\LinkPreview;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use App\Services\LinkPreviewService;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

/** Attach a body's links to a message without going near the network. */
function shareLink(Message $message): void
{
    Queue::fake();
    app(LinkPreviewService::class)->syncFor($message);
}

it('lists every link shared in the channel, newest first', function () {
    [$user, , $channel] = ownerWithChannel();

    $first = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'one https://example.com/one',
    ]);
    $second = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'two https://example.com/two',
    ]);

    shareLink($first);
    shareLink($second);

    Passport::actingAs($user);

    $response = $this->getJson("/api/channels/{$channel->id}/links")->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.url'))->toBe('https://example.com/two') // newest first
        ->and($response->json('data.0.message_id'))->toBe($second->id)
        ->and($response->json('data.0.shared_by'))->toBe($user->name)
        ->and($response->json('data.0.shared_at'))->not->toBeNull()
        ->and($response->json('data.1.url'))->toBe('https://example.com/one');
});

it('keeps a message’s own links in the order it listed them', function () {
    [$user, , $channel] = ownerWithChannel();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'https://example.com/a then https://example.com/b',
    ]);
    shareLink($message);

    Passport::actingAs($user);

    $urls = collect($this->getJson("/api/channels/{$channel->id}/links")->assertOk()->json('data'))
        ->pluck('url')
        ->all();

    expect($urls)->toBe(['https://example.com/a', 'https://example.com/b']);
});

it('shows a link twice when it is shared twice, each pointing at its own message', function () {
    [$user, , $channel] = ownerWithChannel();

    foreach (range(1, 2) as $i) {
        $message = Message::factory()->create([
            'channel_id' => $channel->id,
            'user_id' => $user->id,
            'body' => 'again https://example.com/same',
        ]);
        shareLink($message);
    }

    Passport::actingAs($user);

    $data = $this->getJson("/api/channels/{$channel->id}/links")->assertOk()->json('data');

    // One cached preview row, but two sharings — and two distinct messages to jump to.
    expect(LinkPreview::count())->toBe(1)
        ->and($data)->toHaveCount(2)
        ->and($data[0]['message_id'])->not->toBe($data[1]['message_id']);
});

it('includes links shared inside threads', function () {
    [$user, , $channel] = ownerWithChannel();
    $thread = Thread::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    $reply = Message::factory()->create([
        'channel_id' => $channel->id,
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'body' => 'in a thread https://example.com/threaded',
    ]);
    shareLink($reply);

    Passport::actingAs($user);

    $response = $this->getJson("/api/channels/{$channel->id}/links")->assertOk();

    expect($response->json('data.0.url'))->toBe('https://example.com/threaded');
});

it('still lists a link whose unfurl failed, with no title', function () {
    [$user, , $channel] = ownerWithChannel();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'https://example.com/blocked',
    ]);
    shareLink($message);

    LinkPreview::query()->update(['status' => 'failed', 'title' => null, 'fetched_at' => now()]);

    Passport::actingAs($user);

    $response = $this->getJson("/api/channels/{$channel->id}/links")->assertOk();

    // A site that blocks bots still had its link shared — hiding it would be lying by omission.
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.url'))->toBe('https://example.com/blocked')
        ->and($response->json('data.0.title'))->toBeNull();
});

it('forbids non-members from listing a channel’s links', function () {
    [, , $channel] = ownerWithChannel();

    Passport::actingAs(User::factory()->create());

    $this->getJson("/api/channels/{$channel->id}/links")->assertForbidden();
});
