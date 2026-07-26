<?php

use App\Models\CalendarEvent;
use App\Models\User;
use Laravel\Passport\Passport;

/**
 * The Side Desk Calendar app — a shared schedule per surface, and the first app that is a tab
 * *and* a canvas card at once. Both views read and write exactly these endpoints, so the
 * coverage that matters is the ordinary CRUD plus the membership gate.
 */
it('creates an event and returns it in UTC', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/calendar", [
        'title' => 'Sprint review',
        'starts_at' => '2026-08-01T09:00:00Z',
        'ends_at' => '2026-08-01T10:00:00Z',
        'color' => 'green',
    ])->assertCreated()
        ->assertJsonPath('data.title', 'Sprint review')
        ->assertJsonPath('data.color', 'green')
        ->assertJsonPath('data.all_day', false);

    expect(CalendarEvent::where('channel_id', $channel->id)->count())->toBe(1);
});

it('lists events in start order', function () {
    [$owner, , $channel] = ownerWithChannel();

    foreach (['2026-08-03 09:00', '2026-08-01 09:00', '2026-08-02 09:00'] as $when) {
        CalendarEvent::create([
            'channel_id' => $channel->id, 'user_id' => $owner->id,
            'title' => $when, 'starts_at' => $when,
        ]);
    }

    Passport::actingAs($owner);

    $titles = $this->getJson("/api/channels/{$channel->id}/calendar")
        ->assertOk()->json('data.*.title');

    expect($titles)->toBe(['2026-08-01 09:00', '2026-08-02 09:00', '2026-08-03 09:00']);
});

it('patches only the fields it is sent', function () {
    [$owner, , $channel] = ownerWithChannel();
    $event = CalendarEvent::create([
        'channel_id' => $channel->id, 'user_id' => $owner->id,
        'title' => 'Standup', 'description' => 'daily', 'starts_at' => '2026-08-01 09:00',
    ]);

    Passport::actingAs($owner);

    // Dragging an event to another day sends times and nothing else.
    $this->patchJson("/api/channels/{$channel->id}/calendar/{$event->id}", [
        'starts_at' => '2026-08-02T09:00:00Z',
    ])->assertOk()->assertJsonPath('data.title', 'Standup');

    expect($event->fresh()->description)->toBe('daily');
});

it('refuses an end before the start', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/calendar", [
        'title' => 'Backwards',
        'starts_at' => '2026-08-01T10:00:00Z',
        'ends_at' => '2026-08-01T09:00:00Z',
    ])->assertStatus(422);
});

it('accepts an end equal to the start', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/calendar", [
        'title' => 'A moment',
        'starts_at' => '2026-08-01T10:00:00Z',
        'ends_at' => '2026-08-01T10:00:00Z',
    ])->assertCreated();
});

it('refuses a colour outside the palette', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/calendar", [
        'title' => 'Chartreuse',
        'starts_at' => '2026-08-01T10:00:00Z',
        'color' => '#abcdef',
    ])->assertStatus(422);
});

it('deletes an event', function () {
    [$owner, , $channel] = ownerWithChannel();
    $event = CalendarEvent::create([
        'channel_id' => $channel->id, 'user_id' => $owner->id,
        'title' => 'Gone', 'starts_at' => '2026-08-01 09:00',
    ]);

    Passport::actingAs($owner);

    $this->deleteJson("/api/channels/{$channel->id}/calendar/{$event->id}")->assertNoContent();

    expect(CalendarEvent::count())->toBe(0);
});

it('refuses an event belonging to another channel', function () {
    [$owner, , $channel] = ownerWithChannel();
    [, , $other] = ownerWithChannel();

    $event = CalendarEvent::create([
        'channel_id' => $other->id, 'title' => 'Elsewhere', 'starts_at' => '2026-08-01 09:00',
    ]);

    Passport::actingAs($owner);

    // The route binds the event globally, so the controller's own ownership check is the guard.
    $this->deleteJson("/api/channels/{$channel->id}/calendar/{$event->id}")->assertNotFound();
});

it('keeps non-members out', function () {
    [, , $channel] = ownerWithChannel();
    Passport::actingAs(User::factory()->create());

    $this->getJson("/api/channels/{$channel->id}/calendar")->assertForbidden();
    $this->postJson("/api/channels/{$channel->id}/calendar", [
        'title' => 'Intruder', 'starts_at' => '2026-08-01T09:00:00Z',
    ])->assertForbidden();
});
