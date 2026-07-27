<?php

use App\Models\Message;
use App\Models\Widget;
use Laravel\Passport\Passport;

/**
 * `a!<app>` — the one chat command that reaches the whole Side Desk catalogue.
 *
 * The two families answer differently on purpose: a widget app posts its card to the channel,
 * a surface app (board, notes, …) answers the sender alone with an instruction to float it.
 * See App\Services\Widgets\WidgetService::handleAppCommand.
 */
it('posts a widget card for `a!poll`, creating the channel widget on first use', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $res = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'a!poll'])->assertCreated();

    expect($res->json('data.type'))->toBe('widget')
        ->and($res->json('data.widget.type'))->toBe('poll')
        ->and($res->json('data.open_app'))->toBeNull()
        ->and(Widget::where('channel_id', $channel->id)->where('type', 'poll')->count())->toBe(1)
        ->and(Message::where('body', 'like', 'a!%')->exists())->toBeFalse();
});

it('re-surfaces the same widget rather than making a second one', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'a!kanban'])->assertCreated();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'a!kanban'])->assertCreated();

    expect(Widget::where('channel_id', $channel->id)->where('type', 'kanban')->count())->toBe(1);
});

it('answers `a!board` with a sender-only note telling the client to float the board', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    // An ephemeral answer creates nothing, so it comes back 200 rather than 201.
    $res = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'a!board'])->assertOk();

    expect($res->json('data.open_app'))->toBe('board')
        ->and($res->json('data.type'))->toBe('system')
        // Ephemeral: it exists in this response and nowhere else.
        ->and($res->json('data.id'))->toBeLessThan(0)
        ->and(Message::where('channel_id', $channel->id)->count())->toBe(0);
});

it('floats notes too', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    expect($this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'a!notes'])
        ->assertOk()->json('data.open_app'))->toBe('notes');
});

it('lists the apps for `a!list`, and says so for an app that does not exist', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    expect($this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'a!list'])
        ->assertOk()->json('data.body'))->toContain('a!board')->toContain('a!poll');

    $res = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'a!nonsense'])->assertOk();
    expect($res->json('data.type'))->toBe('system')
        ->and($res->json('data.body'))->toContain('nonsense')
        ->and($res->json('data.open_app'))->toBeNull();
});

it('leaves ordinary chat that merely starts with a letter and a bang alone', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $res = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'a! board please'])->assertCreated();

    expect($res->json('data.body'))->toBe('a! board please')
        ->and($res->json('data.open_app'))->toBeNull();
});
