<?php

use App\Models\Channel;
use App\Models\User;
use Laravel\Passport\Passport;

/**
 * App channels — a channel whose body is an application rather than a timeline.
 *
 * What's worth pinning down is the shape rather than the rendering: that an app channel can't
 * exist without an app, that the app hangs off the *discussion* (as a Side Space's map does),
 * that a second discussion inherits it, and that the timeline underneath is untouched — the
 * last being the whole claim the design rests on.
 */
it('creates an app channel with its app on the General discussion', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $res = $this->postJson("/api/servers/{$server->id}/channels", [
        'name' => 'Design',
        'type' => 'app',
        'app_id' => 'tracker',
    ])->assertCreated();

    $channel = Channel::find($res->json('data.id'));
    expect($channel->type)->toBe('app');

    // On the discussion, not the container — that's what makes a channel of discussions a
    // folder of apps.
    $general = $channel->discussions()->first();
    expect($general->app)->not->toBeNull()
        ->and($general->app->app_id)->toBe('tracker')
        ->and($general->app->installed_by)->toBe($owner->id);
});

it('refuses an app channel with no app', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/channels", [
        'name' => 'Design',
        'type' => 'app',
    ])->assertStatus(422)->assertJsonValidationErrors('app_id');
});

it('refuses an app id that is not in the catalogue', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/channels", [
        'name' => 'Design',
        'type' => 'app',
        'app_id' => 'definitely-not-an-app',
    ])->assertStatus(422)->assertJsonValidationErrors('app_id');
});

it('leaves other channel types without an app row', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $res = $this->postJson("/api/servers/{$server->id}/channels", [
        'name' => 'general',
        'type' => 'text',
    ])->assertCreated();

    $channel = Channel::find($res->json('data.id'));
    expect($channel->discussions()->first()->app)->toBeNull();
});

it('inherits the app when a discussion is added, and honours an explicit one', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $id = $this->postJson("/api/servers/{$server->id}/channels", [
        'name' => 'Design', 'type' => 'app', 'app_id' => 'tracker',
    ])->json('data.id');

    // No app_id: more of what the channel already is.
    $inherited = $this->postJson("/api/channels/{$id}/discussions", ['name' => 'Roadmap'])
        ->assertCreated()->json('data.id');
    expect(Channel::find($inherited)->app->app_id)->toBe('tracker');

    // An explicit one overrides — a channel can hold a tracker and a board side by side.
    $chosen = $this->postJson("/api/channels/{$id}/discussions", [
        'name' => 'Sketches', 'app_id' => 'board',
    ])->assertCreated()->json('data.id');
    expect(Channel::find($chosen)->app->app_id)->toBe('board');
});

it('keeps the timeline working underneath the app', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $id = $this->postJson("/api/servers/{$server->id}/channels", [
        'name' => 'Design', 'type' => 'app', 'app_id' => 'tracker',
    ])->json('data.id');

    $general = Channel::find($id)->discussions()->first();

    // The whole design rests on this: nothing below the app slot knows the app is there.
    $this->postJson("/api/channels/{$general->id}/messages", ['body' => 'hello'])
        ->assertCreated();

    $this->getJson("/api/channels/{$general->id}/messages")
        ->assertOk()
        ->assertJsonPath('data.0.body', 'hello');
});

it('deletes the app row with its channel', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $id = $this->postJson("/api/servers/{$server->id}/channels", [
        'name' => 'Design', 'type' => 'app', 'app_id' => 'tracker',
    ])->json('data.id');

    $this->deleteJson("/api/channels/{$id}")->assertNoContent();

    expect(DB::table('channel_apps')->count())->toBe(0);
});

it('hides an app channel from someone outside the server', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $id = $this->postJson("/api/servers/{$server->id}/channels", [
        'name' => 'Design', 'type' => 'app', 'app_id' => 'tracker',
    ])->json('data.id');

    $general = Channel::find($id)->discussions()->first();

    // Nothing app-specific about this, which is the point — an app channel inherits the same
    // membership gate every other channel has.
    Passport::actingAs(User::factory()->create());
    $this->getJson("/api/channels/{$general->id}/tracker/projects")->assertForbidden();
});

it('reports the app on the container too, so the sidebar can draw its icon', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/channels", [
        'name' => 'Design', 'type' => 'app', 'app_id' => 'tracker',
    ])->assertCreated();

    // The sidebar listing draws *containers*, and a container has no app row of its own —
    // the row hangs off the discussion. Without the fallback in Channel::displayAppId() an
    // app channel comes back appless here and gets drawn as a plain `#` text channel.
    $listing = $this->getJson("/api/servers/{$server->id}/channels")->assertOk()->json('data');
    $container = collect($listing)->firstWhere('type', 'app');

    expect($container['app_id'])->toBe('tracker')
        // And the discussion, which is where it actually lives and what the page opens.
        ->and($container['discussions'][0]['app_id'])->toBe('tracker');
});

it('lets the Tracker be added to a Side Desk strip', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    // The bug this pins down: `tracker` existed as a channel app and in the client registry,
    // but not in the *desk* validation list — so adding the tab 422'd and the app looked
    // broken. One registry now answers both questions.
    $this->putJson("/api/channels/{$channel->id}/desk-apps", [
        'apps' => ['tracker', 'polls', 'stickers'],
    ])->assertOk()->assertJsonPath('apps', ['tracker', 'polls', 'stickers']);
});

it('serves a catalogue of built-in and installed apps', function () {
    [$owner] = ownerWithServer();
    Passport::actingAs($owner);

    \App\Models\InstalledApp::create([
        'slug' => 'acme-crm', 'name' => 'Acme CRM', 'entry_url' => 'https://apps.example.test/crm',
    ]);

    $res = $this->getJson('/api/apps/catalogue')->assertOk();

    expect(collect($res->json('built_in'))->pluck('id'))->toContain('tracker')
        ->and($res->json('installed.0.id'))->toBe('acme-crm');
});

it('lets a channel be created as an installed app, and refuses a disabled one', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $app = \App\Models\InstalledApp::create(['slug' => 'acme-crm', 'name' => 'Acme CRM']);

    // The whole point of the dynamic catalogue: an id that no PHP constant mentions.
    $this->postJson("/api/servers/{$server->id}/channels", [
        'name' => 'CRM', 'type' => 'app', 'app_id' => 'acme-crm',
    ])->assertCreated();

    // Disabling is the kill switch: no *new* channel can pick it, while existing ones keep
    // their timelines.
    $app->update(['enabled' => false]);

    $this->postJson("/api/servers/{$server->id}/channels", [
        'name' => 'CRM 2', 'type' => 'app', 'app_id' => 'acme-crm',
    ])->assertStatus(422)->assertJsonValidationErrors('app_id');
});
