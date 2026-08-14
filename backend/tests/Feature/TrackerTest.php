<?php

use App\Models\Channel;
use App\Models\TrackerProject;
use App\Models\TrackerTask;
use App\Models\User;
use Laravel\Passport\Passport;

/**
 * The Tracker: projects, tasks, and the comments/tags/history any app can borrow.
 *
 * The interesting cases are the ones where being wrong is quiet — a reused task number, a
 * `completed_at` left stamped on a reopened task, a tag from another channel attaching itself.
 * Those get tests; the plain CRUD mostly gets one.
 */
function trackerChannel(): array
{
    [$owner, $server, $channel] = ownerWithChannel();
    $project = $channel->trackerProjects()->create([
        'key' => 'HRIP', 'name' => 'HRIPS Yuck', 'created_by' => $owner->id,
    ]);

    return [$owner, $channel, $project];
}

it('numbers tasks per project and never reuses a number', function () {
    [$owner, $channel, $project] = trackerChannel();
    Passport::actingAs($owner);

    $first = $this->postJson("/api/channels/{$channel->id}/tracker/tasks", [
        'project_id' => $project->id, 'title' => 'Kill Myself',
    ])->assertCreated()->json('data');

    expect($first['key'])->toBe('HRIP-1');

    // Deleting it must not hand its number back: task keys get quoted in chat and in commits,
    // and a reused one silently comes to mean something else.
    $this->deleteJson("/api/channels/{$channel->id}/tracker/tasks/{$first['id']}")->assertNoContent();

    $second = $this->postJson("/api/channels/{$channel->id}/tracker/tasks", [
        'project_id' => $project->id, 'title' => 'Kill JI',
    ])->assertCreated()->json('data');

    expect($second['key'])->toBe('HRIP-2');
});

it('stamps and clears completed_at as a task crosses Done', function () {
    [$owner, $channel, $project] = trackerChannel();
    Passport::actingAs($owner);

    $id = $this->postJson("/api/channels/{$channel->id}/tracker/tasks", [
        'project_id' => $project->id, 'title' => 'Ship it',
    ])->json('data.id');

    $this->patchJson("/api/channels/{$channel->id}/tracker/tasks/{$id}", ['status' => 'done'])
        ->assertOk();
    expect(TrackerTask::find($id)->completed_at)->not->toBeNull();

    // Reopened is not finished. A stale stamp would leave the progress bar counting it forever.
    $this->patchJson("/api/channels/{$channel->id}/tracker/tasks/{$id}", ['status' => 'in_progress'])
        ->assertOk();
    expect(TrackerTask::find($id)->completed_at)->toBeNull();
});

it('writes a history line for every change worth one', function () {
    [$owner, $channel, $project] = trackerChannel();
    Passport::actingAs($owner);

    $id = $this->postJson("/api/channels/{$channel->id}/tracker/tasks", [
        'project_id' => $project->id, 'title' => 'Ship it',
    ])->json('data.id');

    $this->patchJson("/api/channels/{$channel->id}/tracker/tasks/{$id}", [
        'status' => 'in_review', 'priority' => 'high',
    ])->assertOk();

    $kinds = TrackerTask::find($id)->activity()->pluck('kind')->all();

    expect($kinds)->toContain('created')
        ->and($kinds)->toContain('status')
        ->and($kinds)->toContain('priority');

    // The line carries both sides, so the client can word it without re-deriving anything.
    $status = TrackerTask::find($id)->activity()->where('kind', 'status')->first();
    expect($status->data)->toMatchArray(['from' => 'todo', 'to' => 'in_review']);
});

it('refuses a task in a project from another channel', function () {
    [$owner, $channel] = trackerChannel();
    $other = Channel::factory()->create();
    $foreign = $other->trackerProjects()->create(['key' => 'X', 'name' => 'Other']);

    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/tracker/tasks", [
        'project_id' => $foreign->id, 'title' => 'Sneaky',
    ])->assertStatus(422);
});

it('refuses a duplicate project key in one channel but allows it in another', function () {
    [$owner, $channel] = trackerChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/tracker/projects", [
        'name' => 'Another', 'key' => 'HRIP',
    ])->assertStatus(422)->assertJsonValidationErrors('key');

    // A key is only unique within its channel — two teams may both have a PROJ.
    [$owner2, , $channel2] = ownerWithChannel();
    Passport::actingAs($owner2);
    $this->postJson("/api/channels/{$channel2->id}/tracker/projects", [
        'name' => 'Theirs', 'key' => 'HRIP',
    ])->assertCreated();
});

it('rejects a project key that is not a usable prefix', function () {
    [$owner, $channel] = trackerChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/tracker/projects", [
        'name' => 'Bad', 'key' => '1BAD',
    ])->assertStatus(422)->assertJsonValidationErrors('key');
});

it('suggests a key from the first word of the name', function () {
    expect(TrackerProject::suggestKey('HRIPS Yuck'))->toBe('HRIP')
        ->and(TrackerProject::suggestKey('Website Redesign'))->toBe('WEBS')
        ->and(TrackerProject::suggestKey('Yuck'))->toBe('YUCK')
        // Skips a leading word that couldn't start a key.
        ->and(TrackerProject::suggestKey('3D Assets'))->toBe('ASSE')
        ->and(TrackerProject::suggestKey('   '))->toBe('TASK');
});

it('deletes a project with its tasks, comments and history', function () {
    [$owner, $channel, $project] = trackerChannel();
    Passport::actingAs($owner);

    $id = $this->postJson("/api/channels/{$channel->id}/tracker/tasks", [
        'project_id' => $project->id, 'title' => 'Ship it',
    ])->json('data.id');

    $this->postJson("/api/channels/{$channel->id}/apps/tracker_task/{$id}/comments", ['body' => 'hi'])
        ->assertCreated();

    $this->deleteJson("/api/channels/{$channel->id}/tracker/projects/{$project->id}")
        ->assertNoContent();

    expect(TrackerTask::count())->toBe(0)
        ->and(DB::table('app_comments')->count())->toBe(0)
        ->and(DB::table('app_activity')->count())->toBe(0);
});

// --- comments and tags, which every app shares -----------------------------------------

it('comments on a task and refuses one on another channel’s task', function () {
    [$owner, $channel, $project] = trackerChannel();
    Passport::actingAs($owner);

    $id = $this->postJson("/api/channels/{$channel->id}/tracker/tasks", [
        'project_id' => $project->id, 'title' => 'Ship it',
    ])->json('data.id');

    $this->postJson("/api/channels/{$channel->id}/apps/tracker_task/{$id}/comments", ['body' => 'first'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'first')
        // The short morph name, not a PHP namespace — the wire shouldn't quote our classes.
        ->assertJsonPath('data.commentable_type', 'tracker_task');

    // Addressed through a channel that doesn't own it: 404, indistinguishable from "no such
    // task", so ids can't be probed.
    [$other, , $otherChannel] = ownerWithChannel();
    Passport::actingAs($other);
    $this->postJson("/api/channels/{$otherChannel->id}/apps/tracker_task/{$id}/comments", ['body' => 'nope'])
        ->assertNotFound();
});

it('lets only the author edit a comment', function () {
    [$owner, $channel, $project] = trackerChannel();
    Passport::actingAs($owner);

    $id = $this->postJson("/api/channels/{$channel->id}/tracker/tasks", [
        'project_id' => $project->id, 'title' => 'Ship it',
    ])->json('data.id');

    $comment = $this->postJson("/api/channels/{$channel->id}/apps/tracker_task/{$id}/comments", ['body' => 'mine'])
        ->json('data.id');

    $this->patchJson("/api/channels/{$channel->id}/app-comments/{$comment}", ['body' => 'edited'])
        ->assertOk()
        ->assertJsonPath('data.body', 'edited');

    // Rewriting words under somebody else's name is a power nothing here grants — not even
    // to staff, who may delete instead.
    $intruder = User::factory()->create();
    $channel->server->members()->attach($intruder->id, ['role' => 'member']);
    Passport::actingAs($intruder);
    $this->patchJson("/api/channels/{$channel->id}/app-comments/{$comment}", ['body' => 'hijacked'])
        ->assertForbidden();
});

it('reuses an existing tag instead of failing on the duplicate', function () {
    [$owner, $channel] = trackerChannel();
    Passport::actingAs($owner);

    $first = $this->postJson("/api/channels/{$channel->id}/app-tags", ['label' => 'Bug'])
        ->assertCreated()->json('data.id');

    // Typing a tag that exists is how you reuse one — including in a different case. 200
    // rather than 201 says so: nothing was created.
    $second = $this->postJson("/api/channels/{$channel->id}/app-tags", ['label' => 'bug'])
        ->assertOk()->json('data.id');

    expect($second)->toBe($first)
        ->and(DB::table('app_tags')->count())->toBe(1);
});

it('ignores a tag from another channel when syncing a task', function () {
    [$owner, $channel, $project] = trackerChannel();
    $otherChannel = Channel::factory()->create();
    $foreignTag = $otherChannel->appTags()->create(['name' => 'x', 'label' => 'X']);

    Passport::actingAs($owner);

    $id = $this->postJson("/api/channels/{$channel->id}/tracker/tasks", [
        'project_id' => $project->id, 'title' => 'Ship it',
    ])->json('data.id');

    $mine = $this->postJson("/api/channels/{$channel->id}/app-tags", ['label' => 'Bug'])->json('data.id');

    // The stale id is dropped rather than failing the whole edit, which would lose the title
    // and status that came with it.
    $this->patchJson("/api/channels/{$channel->id}/tracker/tasks/{$id}", [
        'tag_ids' => [$mine, $foreignTag->id],
    ])->assertOk()->assertJsonCount(1, 'data.tags');
});

it('attaching the same tag twice leaves one chip', function () {
    [$owner, $channel, $project] = trackerChannel();
    Passport::actingAs($owner);

    $id = $this->postJson("/api/channels/{$channel->id}/tracker/tasks", [
        'project_id' => $project->id, 'title' => 'Ship it',
    ])->json('data.id');
    $tag = $this->postJson("/api/channels/{$channel->id}/app-tags", ['label' => 'Bug'])->json('data.id');

    $this->putJson("/api/channels/{$channel->id}/apps/tracker_task/{$id}/tags/{$tag}")->assertOk();
    $this->putJson("/api/channels/{$channel->id}/apps/tracker_task/{$id}/tags/{$tag}")->assertOk();

    expect(DB::table('app_taggables')->count())->toBe(1);
});

it('serves comments for the other apps that borrowed them', function () {
    [$owner, $channel] = trackerChannel();
    Passport::actingAs($owner);

    // The polymorphic tables exist so the second app to want a thread needs no new plumbing.
    $event = $channel->calendarEvents()->create([
        'user_id' => $owner->id, 'title' => 'Standup', 'starts_at' => now(),
    ]);

    $this->postJson("/api/channels/{$channel->id}/apps/calendar_event/{$event->id}/comments", ['body' => 'late'])
        ->assertCreated()
        ->assertJsonPath('data.commentable_type', 'calendar_event');

    $this->getJson("/api/channels/{$channel->id}/apps/calendar_event/{$event->id}/comments")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('refuses an unknown subject type', function () {
    [$owner, $channel] = trackerChannel();
    Passport::actingAs($owner);

    $this->getJson("/api/channels/{$channel->id}/apps/not_a_thing/1/comments")->assertNotFound();
});
