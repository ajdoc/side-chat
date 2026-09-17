<?php

namespace App\Http\Controllers;

use App\Events\TrackerChanged;
use App\Http\Requests\Tracker\TrackerRequest;
use App\Http\Resources\SignupSheetResource;
use App\Models\Channel;
use App\Models\SignupCharge;
use App\Models\SignupGroup;
use App\Models\SignupSeries;
use App\Models\SignupPerson;
use App\Models\SignupSheet;
use App\Support\Signups\SignupAccess;
use App\Support\Signups\SignupCharges;
use App\Support\Signups\SignupKeys;
use App\Support\Signups\SignupLayout;
use App\Support\Signups\SignupSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Sign-ups app: custom sheets, the roster behind their suggestions, and the payables.
 *
 * ## Who may do what
 *
 * Any member may write a name into a slot — that's what a sign-up sheet is for. Shaping a
 * sheet (header, columns, fees), marking money paid, and archiving a year are for the people
 * who run the place: a server's staff. In a DM or group chat there is nobody above anybody,
 * so everyone manages.
 *
 * ## Broadcasts
 *
 * Rides {@see TrackerChanged} under the `signup` subject, carrying only the sheet id. A sheet
 * holds an unbounded number of names, and the websocket has a payload cap — so clients re-read.
 */
class SignupController extends Controller
{
    /** The wall: sheets for one year (default: the newest), plus which years exist. */
    public function index(TrackerRequest $request, Channel $channel): JsonResponse
    {
        SignupGroup::seedFor($channel);

        $years = $channel->signupSheets()
            ->selectRaw('year, count(*) as sheets, count(archived_at) as archived')
            ->groupBy('year')
            ->orderByDesc('year')
            ->get()
            ->map(fn ($r) => [
                'year' => (int) $r->year,
                'sheets' => (int) $r->sheets,
                'archived' => (int) $r->archived === (int) $r->sheets,
            ]);

        $year = (int) ($request->query('year') ?: ($years->first()['year'] ?? now()->year));

        $sheets = $channel->signupSheets()
            ->where('year', $year)
            ->with('entries:id,sheet_id,column_key')
            ->orderByRaw('event_date IS NULL')
            ->orderByDesc('event_date')
            ->latest('id')
            ->get();

        return response()->json([
            'year' => $year,
            'years' => $years,
            'sheets' => SignupSheetResource::collection($sheets)->resolve(),
            'groups' => SignupGroup::where('channel_id', $channel->id)
                ->orderBy('position')->orderBy('id')
                ->get(['id', 'name', 'color', 'position']),
            'schedules' => SignupSeries::where('channel_id', $channel->id)
                ->withCount('sheets')
                ->orderBy('title')
                ->get()
                ->map(fn ($s) => SignupSeriesController::present($s)),
            'can_manage' => SignupAccess::canManage($channel, $request->user()),
        ]);
    }

    public function show(TrackerRequest $request, Channel $channel, SignupSheet $sheet): SignupSheetResource
    {
        $this->own($channel, $sheet);

        return $this->full($sheet);
    }

    public function store(TrackerRequest $request, Channel $channel): SignupSheetResource
    {
        SignupAccess::authorize($channel, $request->user());

        $data = $this->validateSheet($request, creating: true);
        $defaults = SignupSheet::defaults();

        $sheet = $channel->signupSheets()->create([
            'title' => $data['title'],
            'group_id' => $data['group_id'] ?? null,
            'event_date' => $data['event_date'] ?? null,
            'year' => $data['year'] ?? $this->yearFor($data['event_date'] ?? null),
            'header' => $data['header'] ?? $defaults['header'],
            'columns' => SignupKeys::normalise($data['columns'] ?? $defaults['columns'], 'column'),
            'fees' => SignupKeys::normalise($data['fees'] ?? $defaults['fees'], 'fee'),
            'slots' => $data['slots'] ?? 15,
            'created_by' => $request->user()->id,
        ]);

        $this->broadcast($channel, $sheet);

        return $this->full($sheet);
    }

    /**
     * Copy a sheet — to one date or several at once, optionally into another group, optionally
     * with its names.
     *
     * Copies are one-offs: they aren't among the schedule's dates, so a copy never blocks the
     * schedule from making its own sheet on that day. Names copied bring their charges with them
     * (fresh and unpaid — a payment belongs to the sheet it was made on).
     */
    public function duplicate(TrackerRequest $request, Channel $channel, SignupSheet $sheet): SignupSheetResource
    {
        $this->own($channel, $sheet);
        SignupAccess::authorize($channel, $request->user());

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'event_date' => ['sometimes', 'nullable', 'date'],
            'dates' => ['sometimes', 'array', 'min:1', 'max:31'],
            'dates.*' => ['date', 'distinct'],
            'group_id' => ['sometimes', 'nullable', 'integer', self::groupRule($channel)],
            'with_names' => ['sometimes', 'boolean'],
        ]);

        $dates = $data['dates'] ?? [$data['event_date'] ?? null];
        sort($dates);
        $title = trim((string) ($data['title'] ?? ''))
            ?: ($dates === [null] ? $sheet->title.' (copy)' : $sheet->title);

        $copies = DB::transaction(function () use ($request, $sheet, $data, $dates, $title) {
            $entries = ($data['with_names'] ?? false) ? $sheet->entries()->get() : collect();

            return collect($dates)->map(function ($date) use ($request, $sheet, $data, $title, $entries) {
                $copy = $sheet->replicate(['locked_at', 'archived_at', 'series_id']);
                $copy->title = $title;
                $copy->event_date = $date;
                $copy->year = $this->yearFor($date);
                if (array_key_exists('group_id', $data)) {
                    $copy->group_id = $data['group_id'];
                }
                $copy->created_by = $request->user()->id;
                $copy->save();

                foreach ($entries as $entry) {
                    $copy->entries()->create([
                        ...$entry->only(['column_key', 'position', 'person_id', 'note']),
                        'added_by' => $request->user()->id,
                    ]);
                }

                if ($entries->isNotEmpty()) {
                    SignupCharges::sync($copy);
                }

                return $copy;
            });
        });

        foreach ($copies as $copy) {
            $this->broadcast($channel, $copy);
        }

        return $this->full($copies->first())->additional([
            'created' => $copies->count(),
            'ids' => $copies->pluck('id'),
        ]);
    }

    public function update(TrackerRequest $request, Channel $channel, SignupSheet $sheet): SignupSheetResource
    {
        $this->own($channel, $sheet);
        SignupAccess::authorize($channel, $request->user());

        $data = $this->validateSheet($request, creating: false);

        // An archived sheet only moves by being un-archived, which is the archive endpoint's job.
        abort_if($sheet->archived_at !== null, 422, 'This sheet is archived.');

        $scope = $data['apply_to'] ?? 'this';
        $series = $sheet->series;

        $applied = DB::transaction(function () use ($sheet, $data, $scope, $series) {
            $layout = SignupLayout::from($data);

            if (array_key_exists('event_date', $data)) {
                $sheet->event_date = $data['event_date'];
                // Follow the date into its year unless the caller placed the sheet explicitly.
                if (! array_key_exists('year', $data) && $data['event_date']) {
                    $sheet->year = $this->yearFor($data['event_date']);
                }
            }

            if (array_key_exists('year', $data)) {
                $sheet->year = $data['year'];
            }

            if (array_key_exists('locked', $data)) {
                $sheet->locked_at = $data['locked'] ? now() : null;
            }

            SignupLayout::applyTo($sheet, $layout);

            if ($scope === 'this' || $series === null) {
                return 0;
            }

            // The schedule takes the new layout too, so months made later match.
            $series->fill($layout)->save();

            $from = $scope === 'following' ? $sheet->event_date?->toDateString() : null;

            return SignupLayout::fanOut($series, $layout, $from, except: $sheet->id);
        });

        $this->broadcast($channel, $sheet);

        return $this->full($sheet)->additional(['applied' => $applied]);
    }

    /** Takes its names and unpaid charges with it. Paid charges go too — the sheet is gone. */
    public function destroy(TrackerRequest $request, Channel $channel, SignupSheet $sheet): Response
    {
        $this->own($channel, $sheet);
        SignupAccess::authorize($channel, $request->user());

        $sheet->delete();

        $this->broadcast($channel, $sheet, 'removed');

        return response()->noContent();
    }

    /**
     * Write or clear one slot.
     *
     * The body is the slot's whole new content: a blank name clears it. A name is matched to the
     * roster case-insensitively and added to it on first use — that's what makes the next
     * sheet's suggestions.
     */
    public function slot(TrackerRequest $request, Channel $channel, SignupSheet $sheet): SignupSheetResource
    {
        $this->own($channel, $sheet);

        abort_unless($sheet->isOpen(), 422, $sheet->archived_at ? 'This sheet is archived.' : 'This sheet is locked.');

        $data = $request->validate([
            'column_key' => ['required', 'string', Rule::in(array_keys($sheet->columnsByKey()))],
            'position' => ['required', 'integer', 'min:1', 'max:'.$sheet->slots],
            'name' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        $name = Str::squish($data['name'] ?? '');

        DB::transaction(function () use ($request, $channel, $sheet, $data, $name) {
            $slot = ['column_key' => $data['column_key'], 'position' => $data['position']];

            if ($name === '') {
                $sheet->entries()->where($slot)->delete();
            } else {
                $person = SignupPerson::resolve($channel, $name);

                $sheet->entries()->updateOrCreate($slot, [
                    'person_id' => $person->id,
                    'note' => Str::squish($data['note'] ?? '') ?: null,
                    'added_by' => $request->user()->id,
                ]);
            }

            SignupCharges::sync($sheet);
            $sheet->touch();
        });

        $this->broadcast($channel, $sheet);

        return $this->full($sheet);
    }

    /** Move a whole year in or out of the archive. */
    public function archive(TrackerRequest $request, Channel $channel): JsonResponse
    {
        SignupAccess::authorize($channel, $request->user());

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'archived' => ['required', 'boolean'],
        ]);

        $count = $channel->signupSheets()
            ->where('year', $data['year'])
            ->update(['archived_at' => $data['archived'] ? now() : null]);

        broadcast(new TrackerChanged('channel.'.$channel->id, 'signup', 'saved', ['id' => null]))->toOthers();

        return response()->json(['updated' => $count]);
    }

    /**
     * The roster, with how often each name has signed up. `q` narrows it for suggestions.
     */
    public function people(TrackerRequest $request, Channel $channel): JsonResponse
    {
        $q = SignupPerson::keyFor((string) $request->query('q', ''));

        $people = SignupPerson::query()
            ->where('channel_id', $channel->id)
            ->when($q !== '', fn ($query) => $query->where('name_key', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->withCount('entries')
            ->withMax('entries', 'created_at')
            ->orderByDesc('entries_count')
            ->orderBy('name')
            ->limit($q !== '' ? 20 : 1000)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'signups' => $p->entries_count,
                'last_signed_up_at' => $p->entries_max_created_at
                    ? Carbon::parse($p->entries_max_created_at)->toIso8601String()
                    : null,
            ]);

        return response()->json(['data' => $people]);
    }

    /** Fix a name's spelling everywhere it appears. */
    public function renamePerson(TrackerRequest $request, Channel $channel, SignupPerson $person): JsonResponse
    {
        abort_unless($person->channel_id === $channel->id, 404);
        SignupAccess::authorize($channel, $request->user());

        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $name = Str::squish($data['name']);
        $key = SignupPerson::keyFor($name);

        $clash = SignupPerson::where('channel_id', $channel->id)->where('name_key', $key)
            ->whereKeyNot($person->id)->exists();

        if ($clash) {
            throw ValidationException::withMessages(['name' => 'Somebody on the roster already has that name.']);
        }

        $person->update(['name' => $name, 'name_key' => $key]);

        broadcast(new TrackerChanged('channel.'.$channel->id, 'signup', 'saved', ['id' => null]))->toOthers();

        return response()->json(['data' => ['id' => $person->id, 'name' => $person->name]]);
    }

    /** Only a name that isn't on any sheet — otherwise it would silently vanish from history. */
    public function destroyPerson(TrackerRequest $request, Channel $channel, SignupPerson $person): Response
    {
        abort_unless($person->channel_id === $channel->id, 404);
        SignupAccess::authorize($channel, $request->user());

        abort_if($person->entries()->exists() || $person->charges()->exists(), 422,
            'This name is on a sheet. Remove it from its sheets first.');

        $person->delete();

        return response()->noContent();
    }

    /**
     * Charges for a month (`month=2026-09`) or a whole year (`year=2026`), grouped by person:
     * who trained, how many sessions, what they owe and what they've paid.
     */
    public function payables(TrackerRequest $request, Channel $channel): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        if (! empty($data['year']) && empty($data['month'])) {
            $from = Carbon::create((int) $data['year'])->startOfYear();
            $to = $from->copy()->endOfYear();
        } else {
            $from = Carbon::createFromFormat('Y-m', $data['month'] ?? now()->format('Y-m'))->startOfMonth();
            $to = $from->copy()->endOfMonth();
        }

        $charges = SignupCharge::query()
            ->where('channel_id', $channel->id)
            ->whereBetween('billed_on', [$from->toDateString(), $to->toDateString()])
            ->with(['person:id,name', 'sheet:id,title,event_date,group_id', 'sheet.group:id,name'])
            ->orderBy('billed_on')
            ->orderBy('id')
            ->get();

        $people = $charges->groupBy('person_id')->map(function ($rows) {
            $due = $rows->sum(fn ($c) => (float) $c->amount);
            $paid = $rows->whereNotNull('paid_at')->sum(fn ($c) => (float) $c->amount);

            return [
                'person_id' => $rows->first()->person_id,
                'name' => $rows->first()->person->name,
                'sessions' => $rows->pluck('sheet_id')->unique()->count(),
                'due' => round($due, 2),
                'paid' => round($paid, 2),
                'balance' => round($due - $paid, 2),
                'charges' => $rows->map(fn ($c) => [
                    'id' => $c->id,
                    'sheet_id' => $c->sheet_id,
                    'sheet_title' => $c->sheet->title,
                    'group' => $c->sheet->group?->name,
                    'label' => $c->label,
                    'amount' => (float) $c->amount,
                    'billed_on' => $c->billed_on->toDateString(),
                    'paid' => $c->paid_at !== null,
                    'paid_at' => $c->paid_at?->toIso8601String(),
                ])->values(),
            ];
        })->sortBy(fn ($p) => Str::lower($p['name']))->values();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                'people' => $people->count(),
                'due' => round($people->sum('due'), 2),
                'paid' => round($people->sum('paid'), 2),
                'balance' => round($people->sum('balance'), 2),
            ],
            'people' => $people,
            'can_manage' => SignupAccess::canManage($channel, $request->user()),
        ]);
    }

    /** Mark one charge paid or unpaid. `ids` marks several at once ("paid the month"). */
    public function markPaid(TrackerRequest $request, Channel $channel): JsonResponse
    {
        SignupAccess::authorize($channel, $request->user());

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'paid' => ['required', 'boolean'],
        ]);

        $query = SignupCharge::where('channel_id', $channel->id)->whereIn('id', $data['ids']);

        $count = $data['paid']
            ? $query->whereNull('paid_at')->update(['paid_at' => now(), 'paid_marked_by' => $request->user()->id])
            : $query->whereNotNull('paid_at')->update(['paid_at' => null, 'paid_marked_by' => null]);

        // Unpaying a charge whose name has since left the sheet makes it stale; let sync decide.
        if (! $data['paid']) {
            SignupSheet::whereIn('id', SignupCharge::whereIn('id', $data['ids'])->select('sheet_id'))
                ->get()
                ->each(fn ($s) => SignupCharges::sync($s));
        }

        broadcast(new TrackerChanged('channel.'.$channel->id, 'signup_payables', 'saved', ['id' => null]))->toOthers();

        return response()->json(['updated' => $count]);
    }

    /**
     * Copy a month's sheets into the next month — names not included.
     *
     * Sheets from a schedule are regenerated from its rule (so "every 2nd and 4th Saturday"
     * stays right in a month that starts on a different day). One-off sheets keep their slot:
     * the 2nd Tuesday becomes the 2nd Tuesday. A sheet already there is left alone, so copying
     * twice doesn't double anything.
     */
    public function copyMonth(TrackerRequest $request, Channel $channel): JsonResponse
    {
        SignupAccess::authorize($channel, $request->user());

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'group_id' => ['nullable', 'integer', self::groupRule($channel)],
        ]);

        $from = Carbon::createFromFormat('Y-m-d', $data['month'].'-01')->startOfDay();
        $to = $from->copy()->addMonth();

        $source = $channel->signupSheets()
            ->whereBetween('event_date', [$from->toDateString(), $from->copy()->endOfMonth()->toDateString()])
            ->when(! empty($data['group_id']), fn ($q) => $q->where('group_id', $data['group_id']))
            ->orderBy('event_date')
            ->get();

        $created = DB::transaction(function () use ($source, $to, $request, $channel) {
            $made = collect();

            SignupSeries::whereIn('id', $source->pluck('series_id')->filter()->unique())
                ->get()
                ->each(function ($series) use ($to, $request, $made) {
                    $made->push(...$series->generate($to, $request->user()));
                });

            foreach ($source->whereNull('series_id') as $sheet) {
                $date = SignupSchedule::sameSlotNextMonth($sheet->event_date)->toDateString();

                $exists = $channel->signupSheets()
                    ->where('title', $sheet->title)
                    ->where('group_id', $sheet->group_id)
                    ->whereDate('event_date', $date)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $copy = $sheet->replicate(['locked_at', 'archived_at']);
                $copy->event_date = $date;
                $copy->year = (int) substr($date, 0, 4);
                $copy->created_by = $request->user()->id;
                $copy->save();
                $made->push($copy);
            }

            return $made;
        });

        broadcast(new TrackerChanged('channel.'.$channel->id, 'signup', 'saved', ['id' => null]))->toOthers();

        return response()->json([
            'month' => $to->format('Y-m'),
            'year' => $to->year,
            'created' => $created->count(),
        ]);
    }

    // --- helpers ------------------------------------------------------------------------------

    /** A group id must be one of *this* channel's groups. */
    public static function groupRule(Channel $channel): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('signup_groups', 'id')->where('channel_id', $channel->id);
    }

    /** The structure rules a sheet and a schedule share. */
    public static function structureRules(): array
    {
        return [
            'slots' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'header' => ['sometimes', 'array', 'max:20'],
            'header.*.label' => ['required', 'string', 'max:40'],
            'header.*.value' => ['nullable', 'string', 'max:500'],
            'columns' => ['sometimes', 'array', 'min:1', 'max:20'],
            'columns.*.key' => ['nullable', 'string', 'max:40'],
            'columns.*.label' => ['required', 'string', 'max:40'],
            'columns.*.color' => ['nullable', 'string', 'max:20'],
            'columns.*.kind' => ['required', Rule::in(SignupSheet::COLUMN_KINDS)],
            'fees' => ['sometimes', 'array', 'max:20'],
            'fees.*.key' => ['nullable', 'string', 'max:40'],
            'fees.*.label' => ['required', 'string', 'max:80'],
            'fees.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'fees.*.note' => ['nullable', 'string', 'max:80'],
        ];
    }

    private function validateSheet(TrackerRequest $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$req, 'string', 'max:120'],
            'group_id' => ['sometimes', 'nullable', 'integer', $this->groupRule($request->route('channel'))],
            'event_date' => ['sometimes', 'nullable', 'date'],
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'locked' => ['sometimes', 'boolean'],
            'apply_to' => ['sometimes', Rule::in(SignupLayout::SHEET_SCOPES)],
            ...self::structureRules(),
        ]);
    }

    private function yearFor(?string $date): int
    {
        return $date ? Carbon::parse($date)->year : now()->year;
    }

    private function full(SignupSheet $sheet): SignupSheetResource
    {
        return (new SignupSheetResource($sheet->load('entries.person')))->full();
    }

    /** 404 rather than 403 — a sheet in another channel may as well not exist. */
    private function own(Channel $channel, SignupSheet $sheet): void
    {
        abort_unless($sheet->channel_id === $channel->id, 404);
    }

    private function broadcast(Channel $channel, SignupSheet $sheet, string $action = 'saved'): void
    {
        broadcast(new TrackerChanged('channel.'.$channel->id, 'signup', $action, ['id' => $sheet->id]))->toOthers();
    }
}
