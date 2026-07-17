<?php

use App\Models\Message;
use App\Models\User;
use App\Models\Widget;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;

beforeEach(function () {
    // The resolver reads a YouTube video's title from oEmbed — stub it so tests never
    // touch the network. A direct video link needs nothing else.
    Http::fake([
        'www.youtube.com/oembed*' => Http::response(['title' => 'Never Gonna Give You Up', 'thumbnail_url' => 'https://img/thumb.jpg']),
    ]);
});

/** Wrap an `entity` the way Spotify's embed page does, so SpotifyClient can parse it. */
function spotifyEmbed(array $entity): string
{
    $json = json_encode(['props' => ['pageProps' => ['state' => ['data' => ['entity' => $entity]]]]]);

    return '<html><body><script id="__NEXT_DATA__" type="application/json">'.$json.'</script></body></html>';
}

it('turns `m!p <link>` into a music widget and a card, not a chat message', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $res = $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'm!p https://youtu.be/dQw4w9WgXcQ',
    ])->assertCreated();

    // The response is a widget card, and the raw command text is nowhere in the timeline.
    expect($res->json('data.type'))->toBe('widget')
        ->and($res->json('data.widget.type'))->toBe('music')
        ->and(Message::where('body', 'like', 'm!p%')->exists())->toBeFalse();

    $widget = Widget::where('channel_id', $channel->id)->where('type', 'music')->sole();
    expect($widget->state['queue'])->toHaveCount(1)
        ->and($widget->state['queue'][0]['videoId'])->toBe('dQw4w9WgXcQ')
        ->and($widget->state['status'])->toBe('playing')
        ->and($widget->state['currentIndex'])->toBe(0);
});

it('adds more tracks to the one channel player rather than making a second widget', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'm!p https://youtu.be/aaaaaaaaaaa'])->assertCreated();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'm!p https://youtu.be/bbbbbbbbbbb'])->assertCreated();

    expect(Widget::where('type', 'music')->count())->toBe(1);
    $widget = Widget::where('type', 'music')->sole();
    expect($widget->state['queue'])->toHaveCount(2);
});

it('opens a search picker for plain words and enqueues the chosen result', function () {
    config(['services.youtube.key' => 'test-key']);
    Http::fake([
        'www.googleapis.com/youtube/v3/search*' => Http::response(['items' => [
            ['id' => ['videoId' => 'vid0000000a']],
            ['id' => ['videoId' => 'vid0000000b']],
        ]]),
        'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [
            ['id' => 'vid0000000a', 'snippet' => ['title' => 'Lofi One', 'channelTitle' => 'Chillhop - Topic'], 'contentDetails' => ['duration' => 'PT3M20S']],
            ['id' => 'vid0000000b', 'snippet' => ['title' => 'Lofi Two', 'channelTitle' => 'Beats'], 'contentDetails' => ['duration' => 'PT2M']],
        ]]),
    ]);

    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    // Plain words → a picker, not an immediate enqueue.
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'm!p lofi beats'])->assertCreated();

    $widget = Widget::where('type', 'music')->sole();
    expect($widget->state['queue'])->toBeEmpty()
        ->and($widget->state['pendingSearch']['results'])->toHaveCount(2)
        ->and($widget->state['pendingSearch']['results'][0]['duration'])->toBe(200)
        ->and($widget->state['pendingSearch']['results'][0]['artist'])->toBe('Chillhop'); // "- Topic" stripped

    // Picking one enqueues it and clears the picker.
    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'pick', 'payload' => ['index' => 1]])->assertNoContent();

    $widget->refresh();
    expect($widget->state['pendingSearch'])->toBeNull()
        ->and($widget->state['queue'])->toHaveCount(1)
        ->and($widget->state['queue'][0]['videoId'])->toBe('vid0000000b')
        ->and($widget->state['status'])->toBe('playing');
});

it('resolves a Spotify track from its embed page, keeping its name and art', function () {
    config(['services.youtube.key' => 'test-key']);
    Http::fake([
        // Spotify's embed page carries the metadata in a __NEXT_DATA__ blob — no API/creds.
        'open.spotify.com/embed/track/*' => Http::response(spotifyEmbed([
            'title' => 'Never Gonna Give You Up',
            'artists' => [['name' => 'Rick Astley']],
            'duration' => 213000,
            'coverArt' => ['image' => [['url' => 'https://i.scdn.co/image/cover.jpg', 'maxHeight' => 300]]],
        ])),
        'www.googleapis.com/youtube/v3/search*' => Http::response(['items' => [['id' => ['videoId' => 'dQw4w9WgXcQ']]]]),
        'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [
            ['id' => 'dQw4w9WgXcQ', 'snippet' => ['title' => 'Rick Astley - NGGYU', 'channelTitle' => 'RickAstleyVEVO'], 'contentDetails' => ['duration' => 'PT3M33S']],
        ]]),
    ]);

    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'm!p https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT?si=abc',
    ])->assertCreated();

    // Searched by artist + title, shown with Spotify's clean name/artist/art, honest badge.
    Http::assertSent(fn ($req) => str_contains($req->url(), 'youtube/v3/search')
        && str_contains(urldecode($req->url()), 'Rick Astley Never Gonna Give You Up'));

    $track = Widget::where('type', 'music')->sole()->state['queue'][0];
    expect($track['videoId'])->toBe('dQw4w9WgXcQ')
        ->and($track['source'])->toBe('spotify')
        ->and($track['title'])->toBe('Never Gonna Give You Up')
        ->and($track['artist'])->toBe('Rick Astley')
        ->and($track['thumbnail'])->toBe('https://i.scdn.co/image/cover.jpg')
        ->and($track['duration'])->toBe(213);
});

it('expands a Spotify playlist from its embed page, resolving only the first track', function () {
    config(['services.youtube.key' => 'test-key']);
    Http::fake([
        'open.spotify.com/embed/playlist/*' => Http::response(spotifyEmbed([
            'name' => 'sadboi',
            'coverArt' => ['sources' => [['url' => 'https://img/cover.jpg']]],
            'trackList' => [
                ['title' => 'Song A', 'subtitle' => 'Artist A', 'duration' => 200000, 'uri' => 'spotify:track:a'],
                ['title' => 'Song B', 'subtitle' => "Artist B,\u{00a0}Guest", 'duration' => 190000, 'uri' => 'spotify:track:b'],
                ['title' => 'Song C', 'subtitle' => 'Artist C', 'duration' => 180000, 'uri' => 'spotify:track:c'],
            ],
        ])),
        'www.googleapis.com/youtube/v3/search*' => Http::response(['items' => [['id' => ['videoId' => 'ytFirst0000']]]]),
        'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [
            ['id' => 'ytFirst0000', 'snippet' => ['title' => 'yt', 'channelTitle' => 'ch'], 'contentDetails' => ['duration' => 'PT3M20S']],
        ]]),
    ]);

    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'm!p https://open.spotify.com/playlist/2I4pAXSUydpt0fitWENUk8',
    ])->assertCreated();

    $state = Widget::where('type', 'music')->sole()->state;

    // All three queued as Spotify shells with real metadata (nbsp between artists cleaned)…
    expect($state['queue'])->toHaveCount(3)
        ->and($state['queue'][0]['title'])->toBe('Song A')
        ->and($state['queue'][0]['artist'])->toBe('Artist A')
        ->and($state['queue'][0]['duration'])->toBe(200)
        ->and($state['queue'][0]['thumbnail'])->toBe('https://img/cover.jpg')
        ->and($state['queue'][0]['source'])->toBe('spotify')
        ->and($state['queue'][1]['artist'])->toBe('Artist B, Guest')
        ->and($state['status'])->toBe('playing');

    // …but only the *current* one is resolved to a YouTube id (quota-friendly laziness).
    expect($state['queue'][0]['videoId'])->toBe('ytFirst0000')
        ->and($state['queue'][1]['videoId'])->toBeNull()
        ->and($state['queue'][2]['videoId'])->toBeNull();

    $searches = 0;
    Http::assertSent(function ($req) use (&$searches) {
        if (str_contains($req->url(), 'youtube/v3/search')) {
            $searches++;
        }

        return true;
    });
    expect($searches)->toBe(1);
});

it('reports a friendly error for an unreadable Spotify playlist', function () {
    Http::fake(['open.spotify.com/embed/*' => Http::response('nope', 404)]);

    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $res = $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'm!p https://open.spotify.com/playlist/2I4pAXSUydpt0fitWENUk8',
    ])->assertCreated();

    expect($res->json('data.type'))->toBe('system')
        ->and($res->json('data.body'))->toContain('public')
        ->and(Widget::where('type', 'music')->exists())->toBeFalse();
});

it('cycles loop off → track → queue and toggles autoplay', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'm!p https://youtu.be/dQw4w9WgXcQ'])->assertCreated();
    $widget = Widget::where('type', 'music')->sole();

    foreach (['track', 'queue', 'off'] as $expected) {
        $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'm!loop'])->assertCreated();
        expect($widget->fresh()->state['loop'])->toBe($expected);
    }

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'm!autoplay'])->assertCreated();
    expect($widget->fresh()->state['autoplay'])->toBeTrue();
});

it('sets a clamped playback speed', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'm!p https://youtu.be/dQw4w9WgXcQ'])->assertCreated();

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'm!speed 5'])->assertCreated(); // over the max
    expect(Widget::where('type', 'music')->sole()->state['speed'])->toBe(2.0);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'm!nightcore'])->assertCreated();
    expect(Widget::where('type', 'music')->sole()->state['speed'])->toBe(1.3);
});

it('keeps ordinary messages flowing past the command parser', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $res = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'hello team'])->assertCreated();

    expect($res->json('data.type'))->toBe('user')
        ->and($res->json('data.body'))->toBe('hello team')
        ->and(Widget::count())->toBe(0);
});

it('builds a kanban board with `k!add` and moves a card with `k!done`', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'k!add buy milk'])->assertCreated();

    $widget = Widget::where('type', 'kanban')->sole();
    $cardId = $widget->state['cards'][0]['id'];
    expect($widget->state['cards'][0]['text'])->toBe('buy milk')
        ->and($widget->state['cards'][0]['column'])->toBe('todo');

    // A control command mutates state but must NOT drop a second card in the timeline.
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => "k!done {$cardId}"])->assertCreated();

    expect(Message::where('type', 'widget')->count())->toBe(1)
        ->and(Widget::where('type', 'kanban')->sole()->state['cards'][0]['column'])->toBe('done');
});

it('answers `k!help` with an ephemeral note that is never persisted', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $res = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'k!help'])->assertCreated();

    expect($res->json('data.type'))->toBe('system')
        ->and($res->json('data.id'))->toBeLessThan(0)
        ->and(Message::count())->toBe(0); // nothing hit the database
});

it('applies a card action through the widget endpoint', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'k!add ship it'])->assertCreated();
    $widget = Widget::where('type', 'kanban')->sole();
    $cardId = $widget->state['cards'][0]['id'];

    $this->postJson("/api/widgets/{$widget->id}/action", [
        'action' => 'move',
        'payload' => ['id' => $cardId, 'column' => 'doing'],
    ])->assertNoContent();

    expect($widget->fresh()->state['cards'][0]['column'])->toBe('doing');
});

it('forbids a non-member from driving a channel\'s widget', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'k!add secret'])->assertCreated();
    $widget = Widget::where('type', 'kanban')->sole();

    $stranger = User::factory()->create();
    Passport::actingAs($stranger);

    $this->postJson("/api/widgets/{$widget->id}/action", [
        'action' => 'move',
        'payload' => ['id' => 1, 'column' => 'done'],
    ])->assertForbidden();
});
