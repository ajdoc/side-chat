<?php

use App\Models\Channel;
use App\Models\TrainingTick;
use Laravel\Passport\Passport;

/**
 * The Training app: staff start programs from a template, everyone ticks their own blocks.
 */
function trainingProgram(Channel $channel, array $extra = []): array
{
    return test()->postJson("/api/channels/{$channel->id}/training/programs", [
        'template' => 'ultimate-winter-arc',
        ...$extra,
    ])->assertCreated()->json('data');
}

it('starts a program from the built-in template, snapped to a Monday', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $index = $this->getJson("/api/channels/{$channel->id}/training")->assertOk();
    expect($index->json('templates.0.id'))->toBe('ultimate-winter-arc')
        ->and($index->json('can_manage'))->toBeTrue();

    $program = trainingProgram($channel, ['starts_on' => '2026-10-01']); // a Thursday

    expect($program['title'])->toBe('Ultimate winter arc')
        ->and($program['starts_on'])->toBe('2026-09-28')
        ->and($program['weeks'])->toBe(13)
        ->and($program['content']['sessions']['test']['blocks'])->toHaveCount(5)
        ->and($program['mine'])->toBe([]);
});

it('refuses an unknown template', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/training/programs", ['template' => '../etc'])
        ->assertStatus(422);
});

it('lets members tick their own blocks but not manage programs', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);
    Passport::actingAs($owner);
    $program = trainingProgram($channel);

    Passport::actingAs($member);
    $url = "/api/channels/{$channel->id}/training/programs/{$program['id']}";

    $this->putJson("$url/tick", ['week' => 2, 'day' => 'tue', 'block' => 3, 'done' => true])->assertOk();
    $this->putJson("$url/tick", ['week' => 2, 'day' => 'tue', 'block' => 3, 'done' => true])->assertOk(); // idempotent
    $this->putJson("$url/tick", ['week' => 14, 'day' => 'tue', 'block' => 0, 'done' => true])->assertStatus(422);
    $this->putJson("$url/tick", ['week' => 1, 'day' => 'sun', 'block' => 0, 'done' => true])->assertStatus(422);

    expect($this->getJson($url)->json('data.mine'))->toBe(['2:tue:3']);

    $this->patchJson($url, ['title' => 'Mine now'])->assertForbidden();
    $this->deleteJson($url)->assertForbidden();

    $team = $this->getJson("$url/team")->assertOk();
    expect($team->json('members'))->toHaveCount(1)
        ->and($team->json('members.0.user.id'))->toBe($member->id)
        ->and($team->json('members.0.ticks'))->toBe(['2:tue:3']);

    // Owner's ticks are separate from the member's.
    Passport::actingAs($owner);
    expect($this->getJson($url)->json('data.mine'))->toBe([]);
});

it('clears only your own ticks, and deleting a program takes its ticks', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);
    Passport::actingAs($owner);
    $program = trainingProgram($channel);
    $url = "/api/channels/{$channel->id}/training/programs/{$program['id']}";

    $this->putJson("$url/tick", ['week' => 1, 'day' => 'mon', 'block' => 0, 'done' => true]);
    Passport::actingAs($member);
    $this->putJson("$url/tick", ['week' => 1, 'day' => 'mon', 'block' => 0, 'done' => true]);
    $this->deleteJson("$url/ticks")->assertNoContent();

    expect(TrainingTick::pluck('user_id')->all())->toBe([$owner->id]);

    Passport::actingAs($owner);
    $this->patchJson($url, ['starts_on' => '2026-10-07'])->assertOk()->assertJsonPath('data.starts_on', '2026-10-05');
    $this->deleteJson($url)->assertNoContent();
    expect(TrainingTick::count())->toBe(0);
});

it('keeps programs to their own channel', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $other = Channel::factory()->create(['server_id' => $server->id]);
    Passport::actingAs($owner);
    $program = trainingProgram($channel);

    $this->getJson("/api/channels/{$other->id}/training/programs/{$program['id']}")->assertNotFound();
});

it('ships video links on blocks and warm-up steps', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $content = trainingProgram($channel)['content'];

    expect($content['sessions']['cut-foundation']['blocks'][4]['videos'][0]['label'])->toBe('Pogo hops')
        ->and($content['sessions']['cut-build']['blocks'][4]['videos'][0]['label'])->toBe('Broad jump')
        ->and($content['warmups']['speed']['items'][0])->toBe('2 easy laps of the field')
        ->and($content['warmups']['speed']['items'][6]['videos'][0]['search'])->toBe('sprint build ups drill');
});

function tinyProgram(array $overrides = []): array
{
    return array_replace_recursive([
        'meta' => ['title' => 'Couch to 5k'],
        'weeks' => 2,
        'days' => [['key' => 'mon', 'label' => 'Mon', 'tag' => 'Run', 'offset' => 0]],
        'plan' => ['mon' => 'run'],
        'sessions' => ['run' => [
            'title' => 'Easy run', 'hard' => false, 'warmup' => null,
            'blocks' => [['name' => 'Jog', 'dose' => '20 minutes', 'cue' => 'Easy', 'sneaky' => '<script>']],
        ]],
    ], $overrides);
}

it('imports a program document, keeping only fields it knows', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $program = $this->postJson("/api/channels/{$channel->id}/training/programs", ['content' => tinyProgram()])
        ->assertCreated()->json('data');

    expect($program['title'])->toBe('Couch to 5k')
        ->and($program['template'])->toBeNull()
        ->and($program['weeks'])->toBe(2)
        ->and($program['content']['phases'][0]['name'])->toBe('Program')
        ->and($program['content']['sessions']['run']['blocks'][0])->not->toHaveKey('sneaky');
});

it('refuses a program whose plan names a missing session, or with unsafe links', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $url = "/api/channels/{$channel->id}/training/programs";

    $this->postJson($url, ['content' => tinyProgram(['plan' => ['mon' => 'nope']])])
        ->assertStatus(422)->assertJsonValidationErrors('content.plan.mon');

    $bad = tinyProgram();
    $bad['sessions']['run']['blocks'][0]['videos'] = [['label' => 'x', 'url' => 'javascript:alert(1)']];
    $this->postJson($url, ['content' => $bad])
        ->assertStatus(422)->assertJsonValidationErrors('content.sessions.run.blocks.0.videos.0.url');

    // Garbage shapes are refused, not crashed on.
    $this->postJson($url, ['content' => ['weeks' => 'x', 'days' => ['a'], 'sessions' => [['b']], 'plan' => ['mon' => [['x']]]]])
        ->assertStatus(422);
});

it('lets staff replace a program document, keeping ticks', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);
    Passport::actingAs($owner);
    $program = $this->postJson("/api/channels/{$channel->id}/training/programs", ['content' => tinyProgram()])->json('data');
    $url = "/api/channels/{$channel->id}/training/programs/{$program['id']}";
    $this->putJson("$url/tick", ['week' => 1, 'day' => 'mon', 'block' => 0, 'done' => true]);

    $edited = $program['content'];
    $edited['sessions']['run']['blocks'][0]['dose'] = '25 minutes';

    $res = $this->putJson("$url/content", ['content' => $edited])->assertOk();
    expect($res->json('data.content.sessions.run.blocks.0.dose'))->toBe('25 minutes')
        ->and($res->json('data.mine'))->toBe(['1:mon:0']);

    Passport::actingAs($member);
    $this->putJson("$url/content", ['content' => $edited])->assertForbidden();
});

it('allows a rest phase in a per-phase plan', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $doc = tinyProgram([
        'phases' => [['name' => 'A', 'from' => 1, 'to' => 1], ['name' => 'B', 'from' => 2, 'to' => 2]],
        'plan' => ['mon' => ['run', null]],
    ]);

    $this->postJson("/api/channels/{$channel->id}/training/programs", ['content' => $doc])
        ->assertCreated()
        ->assertJsonPath('data.content.plan.mon', ['run', null]);
});

it('gives blocks ids, their position when missing, and never twice', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $doc = tinyProgram();
    $doc['sessions']['run']['blocks'] = [
        ['name' => 'A'], ['id' => 'x1', 'name' => 'B'], ['id' => 'x1', 'name' => 'C'],
    ];
    $blocks = $this->postJson("/api/channels/{$channel->id}/training/programs", ['content' => $doc])
        ->assertCreated()->json('data.content.sessions.run.blocks');

    expect($blocks[0]['id'])->toBe('0')
        ->and($blocks[1]['id'])->toBe('x1')
        ->and($blocks[2]['id'])->not->toBe('x1');
});

it('keeps ticks on an exercise when others are deleted, and moves them with their week', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $doc = tinyProgram(['weeks' => 3]);
    $doc['sessions']['run']['blocks'] = [['id' => 'a', 'name' => 'A'], ['id' => 'b', 'name' => 'B']];
    $program = $this->postJson("/api/channels/{$channel->id}/training/programs", ['content' => $doc])->json('data');
    $url = "/api/channels/{$channel->id}/training/programs/{$program['id']}";

    foreach ([1, 2, 3] as $w) $this->putJson("$url/tick", ['week' => $w, 'day' => 'mon', 'block' => 'b', 'done' => true])->assertOk();

    // Delete exercise A and week 2: week 3 becomes week 2, week 2's tick goes.
    $edited = $program['content'];
    $edited['weeks'] = 2;
    $edited['phases'][0]['to'] = 2;
    $edited['sessions']['run']['blocks'] = [['id' => 'b', 'name' => 'B']];

    $res = $this->putJson("$url/content", ['content' => $edited, 'week_map' => ['2' => null, '3' => 2]])->assertOk();

    expect(collect($res->json('data.mine'))->sort()->values()->all())->toBe(['1:mon:b', '2:mon:b']);
});

it('drops ticks on a deleted day', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $doc = tinyProgram();
    $doc['days'][] = ['key' => 'tue', 'label' => 'Tue', 'offset' => 1];
    $doc['plan']['tue'] = 'run';
    $program = $this->postJson("/api/channels/{$channel->id}/training/programs", ['content' => $doc])->json('data');
    $url = "/api/channels/{$channel->id}/training/programs/{$program['id']}";
    $this->putJson("$url/tick", ['week' => 1, 'day' => 'mon', 'block' => '0', 'done' => true]);
    $this->putJson("$url/tick", ['week' => 1, 'day' => 'tue', 'block' => '0', 'done' => true]);

    $edited = $program['content'];
    array_pop($edited['days']);
    unset($edited['plan']['tue']);

    expect($this->putJson("$url/content", ['content' => $edited])->json('data.mine'))->toBe(['1:mon:0']);
});
