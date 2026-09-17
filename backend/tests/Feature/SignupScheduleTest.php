<?php

use App\Models\SignupSheet;
use App\Support\Signups\SignupSchedule;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;

/**
 * Sign-ups: custom groups, recurring schedules, and copying a month forward.
 *
 * September 2026 starts on a Tuesday; October 2026 on a Thursday. The dates below lean on that.
 */
it('works out every Tuesday and Thursday of a month', function () {
    expect(SignupSchedule::dates([2, 4], null, Carbon::parse('2026-09-01')))->toBe([
        '2026-09-01', '2026-09-03', '2026-09-08', '2026-09-10', '2026-09-15', '2026-09-17',
        '2026-09-22', '2026-09-24', '2026-09-29',
    ]);
});

it('works out the 2nd and 4th Saturday, and the last one', function () {
    expect(SignupSchedule::dates([6], [2, 4], Carbon::parse('2026-10-01')))->toBe(['2026-10-10', '2026-10-24'])
        ->and(SignupSchedule::dates([6], [SignupSchedule::LAST], Carbon::parse('2026-10-01')))->toBe(['2026-10-31']);
});

it('keeps a slot when moving a month on', function () {
    // 2nd Tuesday of September → 2nd Tuesday of October; a 5th Tuesday → October's last.
    expect(SignupSchedule::sameSlotNextMonth(Carbon::parse('2026-09-08'))->toDateString())->toBe('2026-10-13')
        ->and(SignupSchedule::sameSlotNextMonth(Carbon::parse('2026-09-29'))->toDateString())->toBe('2026-10-27');
});

it('seeds default groups and lets staff manage them', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $groups = $this->getJson("/api/channels/{$channel->id}/signups")->json('groups');
    expect(collect($groups)->pluck('name')->all())->toBe(['Training', 'Bootcamp', 'Leagues & Tournaments', 'Other events']);

    $u12 = $this->postJson("/api/channels/{$channel->id}/signups/groups", ['name' => 'U12', 'color' => 'green'])
        ->assertCreated()->json('data.id');

    $sheet = $this->postJson("/api/channels/{$channel->id}/signups/sheets", ['title' => 'U12 practice', 'group_id' => $u12])
        ->assertCreated()->json('data');
    expect($sheet['group_id'])->toBe($u12);

    // Deleting the group ungroups the sheet, never deletes it.
    $this->deleteJson("/api/channels/{$channel->id}/signups/groups/{$u12}")->assertNoContent();
    expect(SignupSheet::find($sheet['id'])->group_id)->toBeNull();

    // And an emptied channel with sheets isn't re-seeded behind the user's back.
    foreach ($groups as $g) {
        $this->deleteJson("/api/channels/{$channel->id}/signups/groups/{$g['id']}");
    }
    expect($this->getJson("/api/channels/{$channel->id}/signups")->json('groups'))->toBe([]);
});

it('refuses a group from another channel', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $other = App\Models\Channel::factory()->create(['server_id' => $server->id]);
    $foreign = $this->getJson("/api/channels/{$other->id}/signups")->json('groups.0.id');

    $this->postJson("/api/channels/{$channel->id}/signups/sheets", ['title' => 'x', 'group_id' => $foreign])
        ->assertStatus(422);
});

it('creates a schedule with its first month, and generating again adds nothing', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $training = $this->getJson("/api/channels/{$channel->id}/signups")->json('groups.0.id');

    $res = $this->postJson("/api/channels/{$channel->id}/signups/schedules", [
        'title' => 'Weekday training',
        'group_id' => $training,
        'weekdays' => [4, 2],
        'month' => '2026-09',
        'fees' => [['label' => 'Field fee', 'amount' => 50]],
    ])->assertCreated();

    expect($res->json('created'))->toBe(9)
        ->and($res->json('data.weekdays'))->toBe([2, 4]);

    $id = $res->json('data.id');
    $this->postJson("/api/channels/{$channel->id}/signups/schedules/{$id}/generate", ['month' => '2026-09'])
        ->assertOk()->assertJson(['created' => 0]);

    $sheet = SignupSheet::where('series_id', $id)->orderBy('event_date')->first();
    expect($sheet->group_id)->toBe($training)
        ->and($sheet->fees[0]['key'])->toBe('field_fee');
});

it('previews a rule without saving anything', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/signups/schedules/preview", [
        'weekdays' => [6], 'weeks' => [2, 4], 'month' => '2026-10',
    ])->assertOk()->assertJson(['dates' => ['2026-10-10', '2026-10-24']]);

    expect(SignupSheet::count())->toBe(0);
});

it('copies a month forward: schedules by their rule, one-offs by their slot', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/signups/schedules", [
        'title' => 'Bootcamp', 'weekdays' => [6], 'weeks' => [2, 4], 'month' => '2026-09',
    ])->assertCreated();

    // A one-off on the 3rd Thursday of September.
    $this->postJson("/api/channels/{$channel->id}/signups/sheets", [
        'title' => 'Friendly vs MUFC', 'event_date' => '2026-09-17',
    ])->assertCreated();

    $res = $this->postJson("/api/channels/{$channel->id}/signups/copy-month", ['month' => '2026-09'])->assertOk();
    expect($res->json('created'))->toBe(3)->and($res->json('month'))->toBe('2026-10');

    $october = SignupSheet::whereBetween('event_date', ['2026-10-01', '2026-10-31'])
        ->orderBy('event_date')->get()
        ->map(fn ($s) => [$s->title, $s->event_date->toDateString()])->all();

    expect($october)->toBe([
        ['Bootcamp', '2026-10-10'],
        ['Friendly vs MUFC', '2026-10-15'],
        ['Bootcamp', '2026-10-24'],
    ]);

    // Twice is harmless.
    $this->postJson("/api/channels/{$channel->id}/signups/copy-month", ['month' => '2026-09'])
        ->assertJson(['created' => 0]);
});

/** A Tue/Thu schedule with September made, and its sheets in date order. */
function tueThuSchedule($test, $channel): array
{
    $id = $test->postJson("/api/channels/{$channel->id}/signups/schedules", [
        'title' => 'Training', 'weekdays' => [2, 4], 'month' => '2026-09',
        'fees' => [['label' => 'Field fee', 'amount' => 50]],
    ])->assertCreated()->json('data.id');

    return [$id, SignupSheet::where('series_id', $id)->orderBy('event_date')->get()];
}

it('edits only one sheet of a schedule by default', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    [, $sheets] = tueThuSchedule($this, $channel);

    $this->patchJson("/api/channels/{$channel->id}/signups/sheets/{$sheets[0]->id}", ['title' => 'Opening day'])
        ->assertOk()->assertJson(['applied' => 0]);

    expect(SignupSheet::where('title', 'Training')->count())->toBe(8);
});

it('applies a sheet edit to the following sheets and the schedule', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    [$seriesId, $sheets] = tueThuSchedule($this, $channel);

    // Someone signs up on a later sheet; the edit must not lose them.
    $this->putJson("/api/channels/{$channel->id}/signups/sheets/{$sheets[6]->id}/slot", [
        'column_key' => 'boys', 'position' => 1, 'name' => 'Ken',
    ])->assertOk();

    // Edit the 5th sheet (Sep 15): raise the fee, add coaching.
    $this->patchJson("/api/channels/{$channel->id}/signups/sheets/{$sheets[4]->id}", [
        'apply_to' => 'following',
        'fees' => [
            ['key' => 'field_fee', 'label' => 'Field fee', 'amount' => 60],
            ['label' => 'Coaching fee', 'amount' => 100],
        ],
    ])->assertOk()->assertJson(['applied' => 4]);

    $fees = SignupSheet::where('series_id', $seriesId)->orderBy('event_date')->get()
        ->map(fn ($s) => collect($s->fees)->sum('amount'))->all();

    expect($fees)->toEqual([50, 50, 50, 50, 160, 160, 160, 160, 160])
        ->and(App\Models\SignupSeries::find($seriesId)->fees)->toHaveCount(2)
        // Ken's charges followed the new fees.
        ->and((float) App\Models\SignupCharge::sum('amount'))->toEqual(160.0)
        ->and($sheets[6]->entries()->count())->toBe(1);
});

it('applies a schedule edit to all its sheets, but never archived ones', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    [$seriesId, $sheets] = tueThuSchedule($this, $channel);
    $sheets[0]->update(['archived_at' => now()]);

    $this->patchJson("/api/channels/{$channel->id}/signups/schedules/{$seriesId}", [
        'title' => 'Weekday training', 'apply_to' => 'all',
    ])->assertOk()->assertJson(['applied' => 8]);

    expect($sheets[0]->fresh()->title)->toBe('Training')
        ->and(SignupSheet::where('title', 'Weekday training')->count())->toBe(8);
});

it('applies a schedule edit to upcoming sheets only', function () {
    Carbon::setTestNow('2026-09-16 09:00');
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    [$seriesId] = tueThuSchedule($this, $channel);

    $this->patchJson("/api/channels/{$channel->id}/signups/schedules/{$seriesId}", [
        'slots' => 20, 'apply_to' => 'upcoming',
    ])->assertOk()->assertJson(['applied' => 4]); // 17, 22, 24, 29

    // And a plain schedule edit touches no sheet.
    $this->patchJson("/api/channels/{$channel->id}/signups/schedules/{$seriesId}", ['slots' => 25])
        ->assertJson(['applied' => 0]);
    expect(SignupSheet::where('slots', 25)->count())->toBe(0);

    Carbon::setTestNow();
});
