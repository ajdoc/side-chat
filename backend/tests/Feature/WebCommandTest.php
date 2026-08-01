<?php

use App\Jobs\PostWebLookup;
use App\Models\Message;
use App\Services\Web\WebLookup;
use App\Services\Web\WebLookupFormatter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

/*
 * `/web` — the built-in lookup command.
 *
 * Three things are worth testing and one isn't. The command must not do its two upstream
 * calls on the request path; the two sources must be merged rather than printed twice; and
 * a miss must say so rather than looking like a failure. What isn't worth testing is
 * whether DuckDuckGo and Wikipedia return good answers — that's their business, and a test
 * that asserted on real content would fail the week either one reworded an article.
 */

/**
 * Stub both upstreams.
 *
 * Registration order matters: Http::fake matches the *first* stub whose pattern fits, so
 * a catch-all registered ahead of these would swallow them and every test would see an
 * empty result. Both hosts are named explicitly for that reason.
 *
 * @param  array<string, mixed>  $duck
 * @param  array<string, mixed>  $wiki
 */
function fakeSources(array $duck = [], array $wiki = []): void
{
    Http::fake([
        'api.duckduckgo.com/*' => Http::response($duck, 200),
        'en.wikipedia.org/*' => Http::response($wiki, 200),
    ]);
}

/** A Wikipedia action-API response carrying one article. */
function wikiPage(string $title, string $extract): array
{
    return ['query' => ['pages' => [[
        'title' => $title,
        'extract' => $extract,
        'fullurl' => 'https://en.wikipedia.org/wiki/'.str_replace(' ', '_', $title),
    ]]]];
}

it('answers privately and does the lookup off the request path', function () {
    Queue::fake();
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $response = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => '/web merkle tree'])
        ->assertSuccessful();

    // The acknowledgement is ephemeral, and nothing is written yet — the command must not
    // hold the composer open while two third-party servers think about it.
    expect($response->json('data.id'))->toBeLessThan(0)
        ->and($response->json('data.body'))->toContain('merkle tree')
        ->and(Message::count())->toBe(0);

    Queue::assertPushed(PostWebLookup::class, fn (PostWebLookup $job) => $job->channelId === $channel->id
        && $job->userId === $user->id
        && $job->query === 'merkle tree');
});

it('refuses a query it cannot use, without queueing anything', function (string $body) {
    Queue::fake();
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => $body])->assertSuccessful();

    Queue::assertNotPushed(PostWebLookup::class);
})->with([
    'nothing to look up' => ['/web'],
    'an essay' => ['/web '.str_repeat('a', 201)],
]);

it('posts the answer into the channel when the lookup lands', function () {
    [$user, , $channel] = ownerWithChannel();
    fakeSources(wiki: wikiPage('Merkle tree', 'A hash tree in which every leaf is labelled with a hash.'));

    (new PostWebLookup($channel->id, $user->id, 'merkle tree'))
        ->handle(app(WebLookup::class), app(WebLookupFormatter::class));

    $message = Message::sole();
    expect($message->type)->toBe('system')
        ->and($message->body)->toContain('merkle tree')
        ->and($message->body)->toContain('Merkle tree')
        ->and($message->body)->toContain('https://en.wikipedia.org/wiki/Merkle_tree');
});

it('prints one answer when both sources say the same thing', function () {
    [$user, , $channel] = ownerWithChannel();

    // The real collision: DuckDuckGo quotes Wikipedia, and Wikipedia's copy carries a
    // pronunciation gloss the other doesn't. Without the parenthetical strip in
    // WebLookup::sameSubstance these read as two different texts and both get printed.
    fakeSources(
        duck: [
            'AbstractText' => 'Idempotence is the property of certain operations whereby they can be applied many times without changing the result.',
            'AbstractURL' => 'https://en.wikipedia.org/wiki/Idempotence',
            'AbstractSource' => 'Wikipedia',
        ],
        wiki: wikiPage('Idempotence', 'Idempotence (UK: , US: ) is the property of certain operations whereby they can be applied many times without changing the result.'),
    );

    (new PostWebLookup($channel->id, $user->id, 'idempotent'))
        ->handle(app(WebLookup::class), app(WebLookupFormatter::class));

    $body = Message::sole()->body;

    // One source card, not two — and the shared sentence appears once rather than once
    // per source. `**[` opens a card, so counting them counts the sources shown.
    expect(substr_count($body, '**['))->toBe(1)
        ->and(substr_count($body, 'the property of certain operations'))->toBe(1);
});

it('keeps both when the two sources genuinely differ', function () {
    [$user, , $channel] = ownerWithChannel();
    fakeSources(
        duck: [
            'AbstractText' => 'A distributed version control system created by Linus Torvalds.',
            'AbstractURL' => 'https://example.com/git',
            'AbstractSource' => 'Example',
        ],
        wiki: wikiPage('Git', 'Git is a distributed version control system that tracks versions of files.'),
    );

    (new PostWebLookup($channel->id, $user->id, 'git'))
        ->handle(app(WebLookup::class), app(WebLookupFormatter::class));

    $body = Message::sole()->body;

    expect(substr_count($body, '**['))->toBe(2)
        ->and($body)->toContain('Git — Wikipedia')
        ->and($body)->toContain('Example')
        ->and($body)->toContain('A distributed version control system created by Linus');
});

it('says plainly when there is nothing to find', function () {
    [$user, , $channel] = ownerWithChannel();
    fakeSources(); // both answer, neither has anything

    (new PostWebLookup($channel->id, $user->id, 'zzzqqq'))
        ->handle(app(WebLookup::class), app(WebLookupFormatter::class));

    // Not an error, and not silence — a miss is the documented behaviour of a command
    // with no ranked-web-results source behind it, so it explains itself.
    expect(Message::sole()->body)->toContain('Nothing found');
});

it('survives a source being down', function () {
    [$user, , $channel] = ownerWithChannel();

    // DuckDuckGo 500s; Wikipedia answers. The command should still produce the half it got.
    Http::fake([
        'api.duckduckgo.com/*' => Http::response('nope', 500),
        'en.wikipedia.org/*' => Http::response(wikiPage('Git', 'Git is a version control system.'), 200),
    ]);

    (new PostWebLookup($channel->id, $user->id, 'git'))
        ->handle(app(WebLookup::class), app(WebLookupFormatter::class));

    expect(Message::sole()->body)->toContain('Git — Wikipedia');
});

it('says nothing when the asker has left the channel', function () {
    [, , $channel] = ownerWithChannel();
    $stranger = App\Models\User::factory()->create();
    fakeSources(wiki: wikiPage('Git', 'Git is a version control system.'));

    (new PostWebLookup($channel->id, $stranger->id, 'git'))
        ->handle(app(WebLookup::class), app(WebLookupFormatter::class));

    expect(Message::count())->toBe(0);
});

it('splits an answer too long for one message', function () {
    [$user, , $channel] = ownerWithChannel();

    // Extracts and answers are capped, so the only way past the message limit is a
    // pathological *title* — which is uncapped because it's an identifier, and comes
    // from a third party like everything else here.
    fakeSources(
        duck: [
            'AbstractText' => 'Short abstract.',
            'AbstractURL' => 'https://example.com/long',
            'AbstractSource' => str_repeat('Loud Source Name ', 70),
        ],
        wiki: wikiPage(str_repeat('Very Long Title ', 70), 'Short extract.'),
    );

    (new PostWebLookup($channel->id, $user->id, 'long'))
        ->handle(app(WebLookup::class), app(WebLookupFormatter::class));

    $bodies = Message::orderBy('id')->pluck('body');

    // The property, not a count: it split, and every piece is postable. Asserting an
    // exact number would just pin down how long the fixture happens to be.
    expect($bodies->count())->toBeGreaterThan(1)
        ->and($bodies->every(fn (string $b) => mb_strlen($b) <= 2000))->toBeTrue();
});

it('offers /web in the channel command list', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $web = collect($this->getJson("/api/channels/{$channel->id}/commands")->json('data'))
        ->firstWhere('name', 'web');

    expect($web)->not->toBeNull()
        // A built-in, so nobody's bot is behind it.
        ->and($web['bot'])->toBeNull();
});
