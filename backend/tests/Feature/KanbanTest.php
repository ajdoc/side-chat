<?php

use App\Models\Channel;
use App\Models\KanbanCard;
use App\Models\User;
use Laravel\Passport\Passport;

/**
 * The kanban board, now that it is rows rather than a widget's JSON blob.
 *
 * The tests here are the ones where being wrong is quiet: a rename that empties a column
 * because the key followed the label, a column removal that takes twenty cards with it, an
 * import that carries an assignee into a channel that person can't see.
 */
it('creates the board on first read, with three columns and nothing on it', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $board = $this->getJson("/api/channels/{$channel->id}/kanban")->assertOk()->json('data');

    expect(array_column($board['columns'], 'key'))->toBe(['todo', 'doing', 'done'])
        ->and($board['cards'])->toBe([]);

    // Read twice, one board — the row is unique per channel.
    $this->getJson("/api/channels/{$channel->id}/kanban")->assertOk();
    expect(\App\Models\KanbanBoard::count())->toBe(1);
});

it('adds, moves and orders cards', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $first = $this->postJson("/api/channels/{$channel->id}/kanban/cards", ['text' => 'one'])->assertCreated()->json('data');
    $second = $this->postJson("/api/channels/{$channel->id}/kanban/cards", ['text' => 'two'])->assertCreated()->json('data');

    expect($first['column'])->toBe('todo')
        ->and($second['position'])->toBeGreaterThan($first['position']);

    // Dropping the second card above the first has to *shift* the first, not tie with it —
    // a tie leaves the order to the id tiebreak, which is not where you dropped it.
    $this->patchJson("/api/channels/{$channel->id}/kanban/cards/{$second['id']}", [
        'column' => 'todo', 'position' => 0,
    ])->assertOk();

    $order = collect($this->getJson("/api/channels/{$channel->id}/kanban")->json('data.cards'))
        ->pluck('text')->all();

    expect($order)->toBe(['two', 'one']);
});

it('renames a column without orphaning the cards in it', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $card = $this->postJson("/api/channels/{$channel->id}/kanban/cards", ['text' => 'stays put'])->json('data');

    $board = $this->patchJson("/api/channels/{$channel->id}/kanban/columns/todo", ['label' => 'Backlog'])
        ->assertOk()->json('data');

    // The label moved, the key did not — that separation is the whole point of minting one.
    expect($board['columns'][0])->toBe(['key' => 'todo', 'label' => 'Backlog'])
        ->and(KanbanCard::find($card['id'])->column)->toBe('todo');
});

it('rehomes a removed column\'s cards rather than deleting them', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $card = $this->postJson("/api/channels/{$channel->id}/kanban/cards", [
        'text' => 'mid-flight', 'column' => 'doing',
    ])->json('data');

    $board = $this->deleteJson("/api/channels/{$channel->id}/kanban/columns/doing")->assertOk()->json('data');

    expect(array_column($board['columns'], 'key'))->toBe(['todo', 'done'])
        ->and(KanbanCard::find($card['id'])->column)->toBe('todo');
});

it('refuses to remove the last column', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->getJson("/api/channels/{$channel->id}/kanban")->assertOk();
    $this->deleteJson("/api/channels/{$channel->id}/kanban/columns/doing")->assertOk();
    $this->deleteJson("/api/channels/{$channel->id}/kanban/columns/done")->assertOk();

    // A board with no columns has nowhere to put the next card.
    $this->deleteJson("/api/channels/{$channel->id}/kanban/columns/todo")->assertStatus(422);
});

it('adds a column with a key minted from its label, and keeps keys unique', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/kanban/columns", ['label' => 'In Review'])->assertOk();
    $board = $this->postJson("/api/channels/{$channel->id}/kanban/columns", ['label' => 'In Review'])
        ->assertOk()->json('data');

    expect(array_column($board['columns'], 'key'))->toBe(['todo', 'doing', 'done', 'in-review', 'in-review-2']);
});

it('carries comments, tags and reactions on a card', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $card = $this->postJson("/api/channels/{$channel->id}/kanban/cards", ['text' => 'talk about me'])->json('data');
    $base = "/api/channels/{$channel->id}/apps/kanban_card/{$card['id']}";

    $this->postJson("{$base}/comments", ['body' => 'looks done to me'])->assertCreated();
    $this->postJson("{$base}/reactions", ['emoji' => '🔥'])->assertOk();

    $tag = $this->postJson("/api/channels/{$channel->id}/app-tags", ['label' => 'blocked'])->assertCreated()->json('data');
    $this->putJson("{$base}/tags/{$tag['id']}")->assertOk();

    $fetched = collect($this->getJson("/api/channels/{$channel->id}/kanban")->json('data.cards'))
        ->firstWhere('id', $card['id']);

    expect($fetched['comment_count'])->toBe(1)
        ->and($fetched['reaction_count'])->toBe(1)
        ->and($fetched['tags'][0]['label'])->toBe('blocked');

    // The discussion goes with the card — there's no foreign key to cascade along, so this is
    // the model event in HasAppActivity doing it.
    $this->deleteJson("/api/channels/{$channel->id}/kanban/cards/{$card['id']}")->assertNoContent();
    $this->getJson("{$base}/comments")->assertNotFound();
});

it('hides a card in a channel you cannot see', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $this->postJson("/api/channels/{$channel->id}/kanban/cards", ['text' => 'private'])->assertCreated();

    Passport::actingAs(User::factory()->create());
    $this->getJson("/api/channels/{$channel->id}/kanban")->assertForbidden();
});

it('imports a board from another channel, columns and all', function () {
    [$owner, $server, $source] = ownerWithChannel();
    $target = Channel::factory()->create(['server_id' => $server->id]);
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$source->id}/kanban/columns", ['label' => 'In Review'])->assertOk();
    $this->postJson("/api/channels/{$source->id}/kanban/cards", ['text' => 'shipped', 'column' => 'done'])->assertCreated();
    $this->postJson("/api/channels/{$source->id}/kanban/cards", ['text' => 'reviewing', 'column' => 'in-review'])->assertCreated();

    $sources = $this->getJson("/api/channels/{$target->id}/apps/import/sources?app=kanban")->assertOk()->json();

    expect($sources['importable'])->toBeTrue()
        ->and(collect($sources['sources'])->firstWhere('id', $source->id)['count'])->toBe(2);

    $this->postJson("/api/channels/{$target->id}/apps/import", [
        'app' => 'kanban', 'source_channel_id' => $source->id,
    ])->assertOk()->assertJson(['imported' => 2]);

    $board = $this->getJson("/api/channels/{$target->id}/kanban")->json('data');

    // The columns the destination already had are matched by label rather than duplicated; the
    // one it lacked came across.
    expect(array_column($board['columns'], 'label'))->toBe(['To Do', 'Doing', 'Done', 'In Review'])
        ->and(collect($board['cards'])->pluck('text')->sort()->values()->all())->toBe(['reviewing', 'shipped']);

    // A copy, not a move.
    expect($this->getJson("/api/channels/{$source->id}/kanban")->json('data.cards'))->toHaveCount(2);
});

it('refuses to import from a channel the caller cannot see', function () {
    [, , $hidden] = ownerWithChannel();
    [$outsider, , $mine] = ownerWithChannel();
    Passport::actingAs($outsider);

    // 404 rather than 403: an import must not become a way to learn which channel ids exist.
    $this->postJson("/api/channels/{$mine->id}/apps/import", [
        'app' => 'kanban', 'source_channel_id' => $hidden->id,
    ])->assertNotFound();
});
