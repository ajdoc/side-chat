<?php

use App\Models\AppPoll;
use App\Models\CalendarEvent;
use App\Models\Channel;
use App\Models\KanbanCard;
use App\Models\Message;
use App\Models\TrackerProject;
use App\Models\User;
use Laravel\Passport\Passport;

/**
 * "Add this message to an app."
 *
 * The interesting cases are the reading — a poll's options out of a markdown list, a title out
 * of a decorated first line — and the two-channel authorisation, which is the half that would
 * be a hole rather than a bug.
 */
function messageIn(Channel $channel, User $author, string $body): Message
{
    return $channel->messages()->create(['user_id' => $author->id, 'body' => $body]);
}

it('offers this chat, app channels, and every other kind of channel', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $board = Channel::factory()->create(['server_id' => $server->id, 'type' => 'app', 'name' => 'Roadmap']);
    $board->app()->create(['app_id' => 'kanban', 'installed_by' => $owner->id]);
    $voice = Channel::factory()->create(['server_id' => $server->id, 'type' => 'voice', 'name' => 'Standup']);
    $space = Channel::factory()->create(['server_id' => $server->id, 'type' => 'space', 'name' => 'The Office']);

    Passport::actingAs($owner);
    $message = messageIn($channel, $owner, 'redo the onboarding');

    $res = $this->getJson("/api/messages/{$message->id}/app-targets")->assertOk()->json();

    // Every channel carries a Side Desk, so every channel is a target — a voice room and a
    // Side Space have a board in exactly the sense an app channel does.
    expect($res['here']['id'])->toBe($channel->id)
        ->and($res['app_channels']['kanban'][0]['name'])->toBe('Roadmap')
        ->and(collect($res['channels'])->pluck('name')->all())->toContain('Standup', 'The Office')
        // The app channel is in its own group, not in the general list.
        ->and(collect($res['channels'])->pluck('name')->all())->not->toContain('Roadmap')
        ->and(collect($res['channels'])->firstWhere('name', 'The Office')['type'])->toBe('space')
        ->and($res['apps'])->toContain('kanban', 'tracker', 'polls', 'notes');
});

it('surfaces the board in a conversation channel it was filed into, once', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $other = Channel::factory()->create(['server_id' => $server->id, 'type' => 'text']);
    Passport::actingAs($owner);

    $first = messageIn($channel, $owner, 'one for the other board');
    $second = messageIn($channel, $owner, 'and another');

    foreach ([$first, $second] as $message) {
        $this->postJson("/api/messages/{$message->id}/app-items", [
            'app' => 'kanban', 'target_channel_id' => $other->id,
        ])->assertOk();
    }

    // One card, not two: a widget card renders the live board wherever it sits, so a second
    // would be noise — and this can be called once per message somebody files.
    expect($other->messages()->where('type', 'widget')->count())->toBe(1)
        ->and(KanbanCard::where('channel_id', $other->id)->count())->toBe(2);
});

it('does not put a widget card in an app channel', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $board = Channel::factory()->create(['server_id' => $server->id, 'type' => 'app']);
    $board->app()->create(['app_id' => 'kanban', 'installed_by' => $owner->id]);

    Passport::actingAs($owner);
    $message = messageIn($channel, $owner, 'for the roadmap');

    $this->postJson("/api/messages/{$message->id}/app-items", [
        'app' => 'kanban', 'target_channel_id' => $board->id,
    ])->assertOk();

    // The channel already *is* the board, full window. A card in the timeline underneath would
    // be a picture of the room you're standing in.
    expect($board->messages()->where('type', 'widget')->count())->toBe(0);
});

it('adds a message to this channel’s own board', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $message = messageIn($channel, $owner, 'redo the onboarding');

    $this->postJson("/api/messages/{$message->id}/app-items", [
        'app' => 'kanban', 'target_channel_id' => $channel->id, 'options' => ['column' => 'doing'],
    ])->assertOk();

    $card = KanbanCard::sole();

    expect($card->text)->toBe('redo the onboarding')
        ->and($card->column)->toBe('doing')
        ->and($card->channel_id)->toBe($channel->id)
        // The message is untouched — this is a copy of its text, not a move.
        ->and(Message::find($message->id))->not->toBeNull();
});

it('reads a markdown list as a poll’s question and options', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $message = messageIn($channel, $owner, "Where are we eating?\n- Thai\n- Pizza\n- Somewhere with chairs");

    $preview = $this->getJson("/api/messages/{$message->id}/app-targets")->json('preview.poll');
    expect($preview['question'])->toBe('Where are we eating?')
        ->and($preview['options'])->toBe(['Thai', 'Pizza', 'Somewhere with chairs']);

    $this->postJson("/api/messages/{$message->id}/app-items", [
        'app' => 'polls', 'target_channel_id' => $channel->id,
    ])->assertOk();

    $poll = AppPoll::with('options')->sole();

    expect($poll->question)->toBe('Where are we eating?')
        ->and($poll->type)->toBe('single')
        ->and($poll->options->pluck('label')->all())->toBe(['Thai', 'Pizza', 'Somewhere with chairs']);
});

it('makes a bare question a yes/no poll', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $message = messageIn($channel, $owner, 'Ship on Friday?');

    $this->postJson("/api/messages/{$message->id}/app-items", [
        'app' => 'polls', 'target_channel_id' => $channel->id,
    ])->assertOk();

    $poll = AppPoll::with('options')->sole();

    // A question with nothing listed under it *is* a yes/no, and making somebody pick a type
    // they didn't know they were picking is the alternative.
    expect($poll->type)->toBe('yes_no')
        ->and($poll->options->pluck('label')->all())->toBe(['Yes', 'No']);
});

it('makes a task under a project, and a project from a message', function () {
    [$owner, , $channel] = ownerWithChannel();
    $project = $channel->trackerProjects()->create(['key' => 'ONB', 'name' => 'Onboarding', 'created_by' => $owner->id]);
    Passport::actingAs($owner);

    $message = messageIn($channel, $owner, "## Rewrite the welcome email\nIt still says beta.");

    $this->postJson("/api/messages/{$message->id}/app-items", [
        'app' => 'tracker', 'target_channel_id' => $channel->id,
        'options' => ['as' => 'task', 'project_id' => $project->id],
    ])->assertOk()->assertJsonPath('message', 'Created ONB-1.');

    $task = $project->tasks()->sole();
    // The heading markers are stripped: somebody who typed `##` meant the words.
    expect($task->title)->toBe('Rewrite the welcome email')
        ->and($task->description)->toBe('It still says beta.');

    $this->postJson("/api/messages/{$message->id}/app-items", [
        'app' => 'tracker', 'target_channel_id' => $channel->id, 'options' => ['as' => 'project'],
    ])->assertOk();

    expect(TrackerProject::where('key', 'RTWE')->exists())->toBeTrue();
});

it('refuses a task with no project in that channel', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $message = messageIn($channel, $owner, 'something');

    $this->postJson("/api/messages/{$message->id}/app-items", [
        'app' => 'tracker', 'target_channel_id' => $channel->id, 'options' => ['as' => 'task'],
    ])->assertStatus(422);
});

it('appends to the notes rather than replacing them', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $channel->spaceNote()->create(['content' => 'existing plan']);

    $message = messageIn($channel, $owner, 'and another thing');

    $this->postJson("/api/messages/{$message->id}/app-items", [
        'app' => 'notes', 'target_channel_id' => $channel->id,
    ])->assertOk();

    expect($channel->spaceNote()->sole()->content)
        ->toStartWith('existing plan')
        ->toContain('and another thing');
});

it('puts an entry on the calendar at the time given', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $message = messageIn($channel, $owner, "Retro\nwith the whole team");

    $this->postJson("/api/messages/{$message->id}/app-items", [
        'app' => 'calendar', 'target_channel_id' => $channel->id,
        'options' => ['starts_at' => '2026-09-01T10:00:00Z'],
    ])->assertOk();

    $event = CalendarEvent::sole();

    expect($event->title)->toBe('Retro')
        ->and($event->starts_at->toIso8601String())->toStartWith('2026-09-01T10:00');
});

it('files a message into another channel’s app', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $board = Channel::factory()->create(['server_id' => $server->id, 'type' => 'app']);
    $board->app()->create(['app_id' => 'kanban', 'installed_by' => $owner->id]);

    Passport::actingAs($owner);
    $message = messageIn($channel, $owner, 'goes on the roadmap');

    $this->postJson("/api/messages/{$message->id}/app-items", [
        'app' => 'kanban', 'target_channel_id' => $board->id,
    ])->assertOk();

    expect(KanbanCard::sole()->channel_id)->toBe($board->id);
});

it('refuses a target the caller cannot see', function () {
    [, , $hidden] = ownerWithChannel();
    [$outsider, , $mine] = ownerWithChannel();
    Passport::actingAs($outsider);

    $message = messageIn($mine, $outsider, 'mine');

    // 404, not 403 — the same rule the import follows, so this can't be used to probe ids.
    $this->postJson("/api/messages/{$message->id}/app-items", [
        'app' => 'kanban', 'target_channel_id' => $hidden->id,
    ])->assertNotFound();
});

it('refuses to file a message the caller cannot read', function () {
    [$owner, , $channel] = ownerWithChannel();
    $message = messageIn($channel, $owner, 'private');

    Passport::actingAs(User::factory()->create());

    $this->postJson("/api/messages/{$message->id}/app-items", [
        'app' => 'kanban', 'target_channel_id' => $channel->id,
    ])->assertForbidden();
});
