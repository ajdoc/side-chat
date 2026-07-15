<?php

use App\Events\MessagePreviewsUpdated;
use App\Jobs\FetchLinkPreview;
use App\Models\LinkPreview;
use App\Models\Message;
use App\Services\LinkPreviewService;
use App\Services\SafeUrlFetcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

/** Stand in for the network: hands back a canned page without leaving the process. */
function fakeFetcher(?array $result): void
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class)->makePartial();
    $fetcher->shouldReceive('get')->andReturn($result);

    test()->instance(SafeUrlFetcher::class, $fetcher);
}

function html(string $head): array
{
    return [
        'url' => 'https://example.com/post',
        'content_type' => 'text/html',
        'body' => "<html><head>{$head}</head><body>hi</body></html>",
    ];
}

it('extracts the linkable urls from a body', function (string $body, array $expected) {
    expect(app(LinkPreviewService::class)->extractUrls($body))->toBe($expected);
})->with([
    'a bare url' => ['see https://example.com/a', ['https://example.com/a']],
    'trailing sentence punctuation' => ['go to https://example.com/a.', ['https://example.com/a']],
    'a markdown link' => ['[docs](https://example.com/a)', ['https://example.com/a']],
    'a url that owns its parens' => ['https://en.wikipedia.org/wiki/Foo_(bar)', ['https://en.wikipedia.org/wiki/Foo_(bar)']],
    'no links' => ['just talking', []],
    'not http' => ['ftp://example.com/a', []],
    'duplicates collapse' => ['https://a.com https://a.com', ['https://a.com']],
]);

it('unfurls at most three links per message', function () {
    $body = collect(range(1, 5))->map(fn ($i) => "https://example.com/{$i}")->implode(' ');

    expect(app(LinkPreviewService::class)->extractUrls($body))->toHaveCount(LinkPreviewService::MAX_LINKS);
});

it('queues a fetch for a new url and holds the preview back until it lands', function () {
    Queue::fake();

    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $response = $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'look at https://example.com/post',
    ])->assertCreated();

    // Pending previews aren't renderable — the message arrives without one, and the
    // card drops in over the websocket a moment later.
    expect($response->json('data.link_previews'))->toBe([])
        ->and(LinkPreview::where('url', 'https://example.com/post')->value('status'))->toBe('pending');

    Queue::assertPushed(FetchLinkPreview::class);
});

it('does not touch the queue for a message with no links', function () {
    Queue::fake();

    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'no links here'])->assertCreated();

    Queue::assertNothingPushed();
    expect(LinkPreview::count())->toBe(0);
});

it('fills the card in from open graph tags and broadcasts it to the channel', function () {
    Event::fake([MessagePreviewsUpdated::class]);

    fakeFetcher(html(<<<'HTML'
        <meta property="og:title" content="The Post">
        <meta property="og:description" content="All about it.">
        <meta property="og:site_name" content="Example">
        <meta property="og:image" content="/og.png">
    HTML));

    [$user, , $channel] = ownerWithChannel();
    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'look at https://example.com/post',
    ]);

    app(LinkPreviewService::class)->syncFor($message);
    $preview = $message->linkPreviews()->first();

    (new FetchLinkPreview($preview, $message))->handle(app(LinkPreviewService::class));

    expect($preview->fresh())
        ->status->toBe('ok')
        ->kind->toBe('link')
        ->title->toBe('The Post')
        ->description->toBe('All about it.')
        ->site_name->toBe('Example')
        // og:image was relative — resolved against the page it came from.
        ->image_url->toBe('https://example.com/og.png');

    Event::assertDispatched(MessagePreviewsUpdated::class, function (MessagePreviewsUpdated $event) use ($message, $channel) {
        return $event->message->id === $message->id
            && $event->broadcastOn()[0]->name === 'private-channel.'.$channel->id;
    });
});

it('falls back to <title> and the meta description when there are no og tags', function () {
    fakeFetcher(html('<title>Plain Page</title><meta name="description" content="Nothing fancy.">'));

    $preview = LinkPreview::factory()->create(['status' => 'pending', 'fetched_at' => null]);
    app(LinkPreviewService::class)->unfurl($preview);

    expect($preview->fresh())
        ->status->toBe('ok')
        ->title->toBe('Plain Page')
        ->description->toBe('Nothing fancy.');
});

it('treats a direct image url as the preview itself', function () {
    fakeFetcher([
        'url' => 'https://example.com/cat.png',
        'content_type' => 'image/png',
        'body' => '',
    ]);

    $preview = LinkPreview::factory()->create(['status' => 'pending', 'fetched_at' => null]);
    app(LinkPreviewService::class)->unfurl($preview);

    expect($preview->fresh())
        ->status->toBe('ok')
        ->kind->toBe('image')
        ->image_url->toBe('https://example.com/cat.png')
        ->title->toBeNull();
});

it('marks a url failed when it cannot be fetched, or has nothing worth showing', function (?array $result) {
    fakeFetcher($result);

    $preview = LinkPreview::factory()->create(['status' => 'pending', 'fetched_at' => null]);
    app(LinkPreviewService::class)->unfurl($preview);

    expect($preview->fresh()->status)->toBe('failed');
})->with([
    'unreachable or blocked by the guard' => null,
    'a page with no title' => fn () => html('<meta name="keywords" content="x">'),
    'not a web page' => fn () => ['url' => 'https://example.com/a.zip', 'content_type' => 'application/zip', 'body' => ''],
]);

it('reuses the cached preview when the same link is posted again', function () {
    Queue::fake();

    [$user, , $channel] = ownerWithChannel();

    LinkPreview::factory()->create([
        'url' => 'https://example.com/post',
        'url_hash' => LinkPreview::hashFor('https://example.com/post'),
        'status' => 'ok',
        'title' => 'Cached',
        'fetched_at' => now(),
    ]);

    Passport::actingAs($user);

    $response = $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'again: https://example.com/post',
    ])->assertCreated();

    // Served straight from the cached row: no second row, no second fetch, and the
    // card is on the message in the POST response rather than arriving later.
    expect(LinkPreview::count())->toBe(1)
        ->and($response->json('data.link_previews.0.title'))->toBe('Cached');

    Queue::assertNothingPushed();
});

it('re-syncs the previews when the body is edited', function () {
    Queue::fake();

    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $message = $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'https://example.com/first',
    ])->assertCreated()->json('data.id');

    expect(Message::find($message)->linkPreviews)->toHaveCount(1);

    $this->patchJson("/api/messages/{$message}", ['body' => 'never mind'])->assertOk();

    expect(Message::find($message)->linkPreviews)->toHaveCount(0);
});
