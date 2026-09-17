<?php

use App\Models\Channel;
use App\Models\SignupCharge;
use App\Models\User;
use Laravel\Passport\Passport;

/**
 * The Sign-ups app.
 *
 * What matters is the money: attending names are charged each fee once, unpaid charges follow
 * the sheet, and paid charges never move.
 */
function signupSheet(Channel $channel, array $extra = []): array
{
    return test()->postJson("/api/channels/{$channel->id}/signups/sheets", [
        'title' => 'Thursday training',
        'event_date' => '2026-09-17',
        'fees' => [
            ['label' => 'Field fee', 'amount' => 50],
            ['label' => 'Coaching fee', 'amount' => 100],
        ],
        ...$extra,
    ])->assertCreated()->json('data');
}

function fillSlot(Channel $channel, array $sheet, string $column, int $position, ?string $name, ?string $note = null)
{
    return test()->putJson("/api/channels/{$channel->id}/signups/sheets/{$sheet['id']}/slot", [
        'column_key' => $column, 'position' => $position, 'name' => $name, 'note' => $note,
    ]);
}

it('creates a sheet with the default columns and keyed fees, filed under its year', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $sheet = signupSheet($channel);

    expect($sheet['year'])->toBe(2026)
        ->and(collect($sheet['columns'])->pluck('key')->all())->toBe(['boys', 'girls', 'nys', 'not_going'])
        ->and(collect($sheet['fees'])->pluck('key')->all())->toBe(['field_fee', 'coaching_fee']);
});

it('charges attending names each fee once, and nobody else', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $sheet = signupSheet($channel);

    fillSlot($channel, $sheet, 'boys', 1, 'PAT')->assertOk();
    fillSlot($channel, $sheet, 'nys', 1, 'pat')->assertOk(); // same person, different case
    fillSlot($channel, $sheet, 'not_going', 1, 'Dayne', 'work')->assertOk();

    expect(SignupCharge::count())->toBe(2)
        ->and(SignupCharge::sum('amount'))->toEqual(150);

    $res = $this->getJson("/api/channels/{$channel->id}/signups/payables?month=2026-09")->assertOk();
    expect($res->json('totals.due'))->toEqual(150)
        ->and($res->json('people.0.name'))->toBe('PAT');
});

it('drops unpaid charges when a name leaves, but keeps paid ones', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $sheet = signupSheet($channel);

    fillSlot($channel, $sheet, 'boys', 1, 'Wel');
    fillSlot($channel, $sheet, 'girls', 1, 'Rory');

    $welField = SignupCharge::whereHas('person', fn ($q) => $q->where('name', 'Wel'))
        ->where('fee_key', 'field_fee')->value('id');
    $this->postJson("/api/channels/{$channel->id}/signups/payables/paid", ['ids' => [$welField], 'paid' => true])
        ->assertOk();

    fillSlot($channel, $sheet, 'boys', 1, null);
    fillSlot($channel, $sheet, 'girls', 1, null);

    expect(SignupCharge::pluck('id')->all())->toBe([$welField]);
});

it('reprices unpaid charges when a fee changes', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $sheet = signupSheet($channel);
    fillSlot($channel, $sheet, 'boys', 1, 'Ken');

    $this->patchJson("/api/channels/{$channel->id}/signups/sheets/{$sheet['id']}", [
        'fees' => [['key' => 'field_fee', 'label' => 'Field fee', 'amount' => 60]],
    ])->assertOk();

    expect(SignupCharge::pluck('amount')->map(fn ($a) => (float) $a)->all())->toBe([60.0]);
});

it('suggests names from the roster', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $sheet = signupSheet($channel);
    fillSlot($channel, $sheet, 'boys', 1, 'Martin');
    fillSlot($channel, $sheet, 'girls', 1, 'Pat M');

    $res = $this->getJson("/api/channels/{$channel->id}/signups/people?q=mar")->assertOk();

    expect(collect($res->json('data'))->pluck('name')->all())->toBe(['Martin']);
});

it('lets members sign up but not reshape the sheet or mark payments', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $sheet = signupSheet($channel);

    $member = User::factory()->create();
    $server->members()->attach($member->id);
    Passport::actingAs($member);

    fillSlot($channel, $sheet, 'boys', 2, 'Anton')->assertOk();
    $this->patchJson("/api/channels/{$channel->id}/signups/sheets/{$sheet['id']}", ['title' => 'x'])->assertForbidden();
    $this->postJson("/api/channels/{$channel->id}/signups/payables/paid", ['ids' => [1], 'paid' => true])->assertForbidden();
});

it('makes an archived year read-only', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $sheet = signupSheet($channel);

    $this->postJson("/api/channels/{$channel->id}/signups/archive", ['year' => 2026, 'archived' => true])->assertOk();

    fillSlot($channel, $sheet, 'boys', 1, 'Roland')->assertStatus(422);
    expect($this->getJson("/api/channels/{$channel->id}/signups")->json('years.0.archived'))->toBeTrue();
});

it('duplicates a sheet without its names', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $sheet = signupSheet($channel);
    fillSlot($channel, $sheet, 'boys', 1, 'Kirvy');

    $copy = $this->postJson("/api/channels/{$channel->id}/signups/sheets/{$sheet['id']}/duplicate", [
        'title' => 'Next Thursday', 'event_date' => '2026-09-24',
    ])->assertCreated()->json('data');

    expect($copy['entries'])->toBe([])
        ->and($copy['fees'])->toEqual($sheet['fees']);
});

it('copies a sheet to several dates and another group, with its names', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $sheet = signupSheet($channel);
    fillSlot($channel, $sheet, 'boys', 1, 'Kirvy');
    fillSlot($channel, $sheet, 'not_going', 1, 'Dayne', 'work');
    $bootcamp = $this->getJson("/api/channels/{$channel->id}/signups")->json('groups.1.id');

    $res = $this->postJson("/api/channels/{$channel->id}/signups/sheets/{$sheet['id']}/duplicate", [
        'dates' => ['2026-10-08', '2026-10-01'],
        'group_id' => $bootcamp,
        'with_names' => true,
    ])->assertCreated();

    expect($res->json('created'))->toBe(2)
        ->and($res->json('data.event_date'))->toBe('2026-10-01')
        ->and($res->json('data.title'))->toBe('Thursday training')
        ->and($res->json('data.group_id'))->toBe($bootcamp)
        ->and(collect($res->json('data.entries'))->pluck('name')->sort()->values()->all())->toBe(['Dayne', 'Kirvy'])
        // Kirvy owes 150 on the original and on each copy; Dayne isn't going.
        ->and((float) SignupCharge::sum('amount'))->toEqual(450.0);
});

it('refuses a copy onto the same date twice in one request', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $sheet = signupSheet($channel);

    $this->postJson("/api/channels/{$channel->id}/signups/sheets/{$sheet['id']}/duplicate", [
        'dates' => ['2026-10-01', '2026-10-01'],
    ])->assertStatus(422);
});
