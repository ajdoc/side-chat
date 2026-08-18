<?php

use App\Models\CalendarEvent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Laravel\Passport\Passport;

/**
 * Calendar reminders, and the room an entry happens in.
 *
 * The cases worth guarding are the ones that misfire quietly: a reminder posted twice, a batch of
 * stale ones after a worker outage, and a rescheduled entry that never announces its new time.
 */
function eventIn(Channel $channel, int $userId, array $attributes = []): CalendarEvent
{
    return $channel->calendarEvents()->create([
        'user_id' => $userId,
        'title' => 'Standup',
        'starts_at' => now()->addMinutes(10),
        ...$attributes,
    ]);
}

it('posts a reminder once, and never again', function () {
    [$owner, , $channel] = ownerWithChannel();
    eventIn($channel, $owner->id, ['remind_minutes' => 15]);

    $this->artisan('calendar:post-reminders')->assertSuccessful();
    $this->artisan('calendar:post-reminders')->assertSuccessful();

    $notices = Message::where('type', 'system')->get();

    // Twice would be noise everybody has to read twice — the failure this stamps against.
    expect($notices)->toHaveCount(1)
        ->and($notices->first()->body)->toContain('Standup')
        ->and($notices->first()->body)->toContain('starts in');
});

it('waits until the reminder is actually due', function () {
    [$owner, , $channel] = ownerWithChannel();
    eventIn($channel, $owner->id, ['starts_at' => now()->addHours(3), 'remind_minutes' => 10]);

    $this->artisan('calendar:post-reminders')->assertSuccessful();

    expect(Message::count())->toBe(0);
});

it('says nothing at all for an entry with no reminder set', function () {
    [$owner, , $channel] = ownerWithChannel();
    eventIn($channel, $owner->id);

    $this->artisan('calendar:post-reminders')->assertSuccessful();

    // Most calendar rows are records, not appointments. A channel that announced all of them is
    // a channel people mute.
    expect(Message::count())->toBe(0);
});

it('never announces an entry whose time has long passed', function () {
    [$owner, , $channel] = ownerWithChannel();
    $event = eventIn($channel, $owner->id, ['starts_at' => now()->subHours(2), 'remind_minutes' => 10]);

    $this->artisan('calendar:post-reminders')->assertSuccessful();

    /*
     * A worker that was down for an hour must not wake up and announce a dozen meetings that
     * already happened. The row is left *unstamped* on purpose: the query's lower bound excludes
     * it by index, and writing to it to record that we chose not to post would be work done to
     * store a non-event.
     */
    expect(Message::count())->toBe(0)
        ->and($event->fresh()->reminded_at)->toBeNull();
});

it('arms the reminder again when the entry is rescheduled', function () {
    [$owner, , $channel] = ownerWithChannel();
    $event = eventIn($channel, $owner->id, ['remind_minutes' => 15]);
    Passport::actingAs($owner);

    $this->artisan('calendar:post-reminders');
    expect(Message::count())->toBe(1);

    // Dragged to tomorrow: the notice that went out was about a time this no longer happens at.
    $this->patchJson("/api/channels/{$channel->id}/calendar/{$event->id}", [
        'starts_at' => now()->addDay()->toIso8601String(),
    ])->assertOk();

    expect($event->fresh()->reminded_at)->toBeNull();
});

it('names the room, and refuses one that isn’t a room', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $room = Channel::factory()->create(['server_id' => $server->id, 'type' => 'voice', 'name' => 'Standup Room']);
    $text = Channel::factory()->create(['server_id' => $server->id, 'type' => 'text']);
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/calendar", [
        'title' => 'Standup', 'starts_at' => now()->addMinutes(5)->toIso8601String(),
        'remind_minutes' => 10, 'room_channel_id' => $room->id,
    ])->assertCreated()->assertJsonPath('data.room.name', 'Standup Room');

    $this->artisan('calendar:post-reminders')->assertSuccessful();

    // The room is what turns a notice into a way in.
    expect(Message::where('type', 'system')->sole()->body)->toContain('Standup Room');

    // A text channel is not somewhere you gather.
    $this->postJson("/api/channels/{$channel->id}/calendar", [
        'title' => 'Nope', 'starts_at' => now()->addMinutes(5)->toIso8601String(),
        'room_channel_id' => $text->id,
    ])->assertStatus(422);
});

it('refuses a room in a server the author cannot see', function () {
    [, , $elsewhere] = ownerWithChannel();
    $hidden = Channel::factory()->create(['server_id' => $elsewhere->server_id, 'type' => 'voice']);
    [$outsider, , $mine] = ownerWithChannel();
    Passport::actingAs($outsider);

    // An id in a request body could otherwise be used to confirm a private room exists.
    $this->postJson("/api/channels/{$mine->id}/calendar", [
        'title' => 'Snoop', 'starts_at' => now()->addMinutes(5)->toIso8601String(),
        'room_channel_id' => $hidden->id,
    ])->assertStatus(422);
});

/*
 * The room's own view of what's scheduled in it. A meeting is a calendar entry with a room, so
 * this reads across the server's calendars rather than the room's — a room has none.
 */

it('lists the meetings scheduled in a room, now and next', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $room = Channel::factory()->create(['server_id' => $server->id, 'type' => 'voice']);
    Passport::actingAs($owner);

    // On now, still to come, already over, and one in a different room.
    eventIn($channel, $owner->id, ['title' => 'Happening', 'starts_at' => now()->subMinutes(5), 'ends_at' => now()->addMinutes(25), 'room_channel_id' => $room->id]);
    eventIn($channel, $owner->id, ['title' => 'Later', 'starts_at' => now()->addHours(2), 'room_channel_id' => $room->id]);
    eventIn($channel, $owner->id, ['title' => 'Yesterday', 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour(), 'room_channel_id' => $room->id]);
    eventIn($channel, $owner->id, ['title' => 'Elsewhere', 'starts_at' => now()->addHour()]);

    $titles = collect($this->getJson("/api/channels/{$room->id}/meetings")->assertOk()->json('data'))
        ->pluck('title')->all();

    expect($titles)->toBe(['Happening', 'Later']);
});

it('does not leak a private channel’s plans into a public room', function () {
    [$owner, $server, $private] = ownerWithChannel();
    $room = Channel::factory()->create(['server_id' => $server->id, 'type' => 'voice']);
    $private->update(['is_private' => true]);
    eventIn($private, $owner->id, ['title' => 'Board meeting', 'starts_at' => now()->addMinutes(30), 'room_channel_id' => $room->id]);

    $member = User::factory()->create();
    $server->members()->attach($member->id);
    Passport::actingAs($member);

    // The entry lives in a channel this person can't see. The *room* is public, so without
    // scoping on the host channel the room would publish the titles of private plans.
    expect($this->getJson("/api/channels/{$room->id}/meetings")->assertOk()->json('data'))->toBe([]);

    // Its owner still sees it.
    Passport::actingAs($owner);
    expect($this->getJson("/api/channels/{$room->id}/meetings")->json('data.0.title'))->toBe('Board meeting');
});
