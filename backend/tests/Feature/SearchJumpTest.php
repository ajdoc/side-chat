<?php

use App\Models\Message;
use App\Search\LikeSearchDriver;
use App\Search\SearchDriver;
use App\Services\MessageService;
use Laravel\Passport\Passport;

/*
 * The other half of search: getting *to* the result.
 *
 * A hit from March is only useful if clicking it lands you there, and no amount of paging
 * backwards from today reaches March. Hence `?around=` on the channel timeline — and hence
 * these tests, because a window centred on a row is the one kind of pagination that can
 * silently return the wrong half.
 *
 * The last test is the driver contract: the same plain-word search must return the same
 * rows whether Postgres full-text or the `LIKE` fallback answered it. Only the order and
 * the tolerance for word endings may differ.
 */

it('centres the timeline on a message, with conversation either side', function () {
    [$owner, , $channel] = ownerWithChannel();

    $ids = collect(range(1, 300))
        ->map(fn ($n) => Message::factory()->create([
            'channel_id' => $channel->id,
            'user_id' => $owner->id,
            'body' => "message {$n}",
        ])->id);

    $target = $ids[149];

    Passport::actingAs($owner);
    $response = $this->getJson("/api/channels/{$channel->id}/messages?around={$target}")->assertOk();

    $returned = collect($response->json('data'))->pluck('id');

    expect($returned)->toContain($target)
        ->and($returned->count())->toBe(MessageService::PER_PAGE)
        // Half either side, so the target sits in the middle rather than at an edge — an
        // off-by-one in the split shows up here as the target landing first or last.
        ->and($returned->search($target))->toBe((int) (MessageService::PER_PAGE / 2))
        // Ascending, like every other page of the timeline.
        ->and($returned->all())->toBe($returned->sort()->values()->all());

    // There is more in both directions, and the client has to know it isn't at the bottom —
    // otherwise the jump lands and then refuses to scroll forwards.
    expect($response->json('has_more'))->toBeTrue()
        ->and($response->json('has_newer'))->toBeTrue();
});

it('says there is nothing newer when the jump lands near the end', function () {
    [$owner, , $channel] = ownerWithChannel();
    $last = collect(range(1, 5))
        ->map(fn ($n) => Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'body' => "m{$n}"]))
        ->last();

    Passport::actingAs($owner);
    $response = $this->getJson("/api/channels/{$channel->id}/messages?around={$last->id}")->assertOk();

    expect($response->json('has_more'))->toBeFalse()
        ->and($response->json('has_newer'))->toBeFalse()
        ->and($response->json('data'))->toHaveCount(5);
});

it('still lands somewhere sensible when the message has since been deleted', function () {
    [$owner, , $channel] = ownerWithChannel();
    $ids = collect(range(1, 10))
        ->map(fn ($n) => Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'body' => "m{$n}"])->id);

    $gone = $ids[5];
    Message::whereKey($gone)->delete();

    Passport::actingAs($owner);
    $data = $this->getJson("/api/channels/{$channel->id}/messages?around={$gone}")->assertOk()->json('data');

    // Nine left, and the window is the conversation around where it was — not an empty page.
    expect($data)->toHaveCount(9);
});

it('leaves the ordinary timeline page untouched', function () {
    [$owner, , $channel] = ownerWithChannel();
    Message::factory()->count(3)->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    Passport::actingAs($owner);
    $response = $this->getJson("/api/channels/{$channel->id}/messages")->assertOk();

    // The plain page always ends at the newest message, so there is never anything below it.
    expect($response->json('has_newer'))->toBeFalse()
        ->and($response->json('data'))->toHaveCount(3);
});

it('returns the same rows on the LIKE fallback driver as on Postgres full-text', function () {
    [$owner, , $channel] = ownerWithChannel();
    Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'body' => 'the pangolin has landed']);
    Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'body' => 'no sign of it']);

    Passport::actingAs($owner);
    $postgres = collect($this->getJson('/api/search?q=pangolin&type=messages')->json('data'))->pluck('id')->sort()->values();

    // What a SQLite development database gets. Same rows for a plain word — that parity is
    // the whole reason the fallback is allowed to exist untested in the wild.
    $this->app->instance(SearchDriver::class, new LikeSearchDriver);
    $fallback = collect($this->getJson('/api/search?q=pangolin&type=messages')->json('data'))->pluck('id')->sort()->values();

    expect($fallback->all())->toBe($postgres->all())->and($fallback)->toHaveCount(1);
});

it('does not let a wildcard in the search term match everything', function () {
    [$owner, , $channel] = ownerWithChannel();
    $channel->update(['name' => 'general']);

    Passport::actingAs($owner);

    // `%` is a LIKE wildcard, and the name matcher is built on LIKE on both drivers. Left
    // unescaped it would match every channel in the database.
    expect($this->getJson('/api/search?q=%25&type=channels')->assertOk()->json('data'))->toBeEmpty();
});
