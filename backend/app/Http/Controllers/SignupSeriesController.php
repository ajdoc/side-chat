<?php

namespace App\Http\Controllers;

use App\Events\TrackerChanged;
use App\Http\Requests\Tracker\TrackerRequest;
use App\Models\Channel;
use App\Models\SignupSeries;
use App\Models\SignupSheet;
use App\Support\Signups\SignupAccess;
use App\Support\Signups\SignupKeys;
use App\Support\Signups\SignupLayout;
use App\Support\Signups\SignupSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Recurring sign-up schedules: "Training, every Tuesday and Thursday", "Bootcamp, 2nd and 4th
 * Saturday". Staff only. See the add_signup_groups_and_schedules migration.
 */
class SignupSeriesController extends Controller
{
    /** The shape the client gets — used by the wall's index too. */
    public static function present(SignupSeries $s): array
    {
        return [
            'id' => $s->id,
            'group_id' => $s->group_id,
            'title' => $s->title,
            'header' => $s->header,
            'columns' => $s->columns,
            'fees' => $s->fees,
            'slots' => $s->slots,
            'weekdays' => $s->weekdays,
            'weeks' => $s->weeks,
            'sheets_count' => $s->sheets_count ?? null,
        ];
    }

    /**
     * The dates a rule would give in a month, without saving anything — so the form's preview
     * is the server's own answer, never a second implementation of the rule.
     */
    public function preview(TrackerRequest $request, Channel $channel): JsonResponse
    {
        $data = $request->validate([...$this->ruleRules(), 'month' => ['required', 'date_format:Y-m']]);

        return response()->json([
            'dates' => SignupSchedule::dates($data['weekdays'], $data['weeks'] ?? null, $this->month($data['month'])),
        ]);
    }

    /** Save a schedule, and — when `month` is sent — make that month's sheets straight away. */
    public function store(TrackerRequest $request, Channel $channel): JsonResponse
    {
        SignupAccess::authorize($channel, $request->user());

        $data = $request->validate($this->rules($channel, creating: true));
        $defaults = SignupSheet::defaults();

        [$series, $created] = DB::transaction(function () use ($request, $channel, $data, $defaults) {
            $series = SignupSeries::create([
                'channel_id' => $channel->id,
                'group_id' => $data['group_id'] ?? null,
                'title' => $data['title'],
                'header' => $data['header'] ?? $defaults['header'],
                'columns' => SignupKeys::normalise($data['columns'] ?? $defaults['columns'], 'column'),
                'fees' => SignupKeys::normalise($data['fees'] ?? [], 'fee'),
                'slots' => $data['slots'] ?? 15,
                'weekdays' => $this->cleanWeekdays($data['weekdays']),
                'weeks' => $this->cleanWeeks($data['weeks'] ?? null),
                'created_by' => $request->user()->id,
            ]);

            $created = empty($data['month']) ? collect() : $series->generate($this->month($data['month']), $request->user());

            return [$series, $created];
        });

        $this->changed($channel);

        return response()->json([
            'data' => self::present($series->loadCount('sheets')),
            'created' => $created->count(),
            'first_sheet_id' => $created->first()?->id,
        ], 201);
    }

    /**
     * Change a schedule. By default only months generated from now on follow; `apply_to` also
     * pushes the layout onto sheets it already made — `upcoming` (today on) or `all`.
     */
    public function update(TrackerRequest $request, Channel $channel, SignupSeries $series): JsonResponse
    {
        $this->own($channel, $series);
        SignupAccess::authorize($channel, $request->user());

        $data = $request->validate([
            ...$this->rules($channel, creating: false),
            'apply_to' => ['sometimes', Rule::in(SignupLayout::SERIES_SCOPES)],
        ]);
        $scope = $data['apply_to'] ?? 'none';

        $applied = DB::transaction(function () use ($series, $data, $scope) {
            $layout = SignupLayout::from($data);

            $series->fill($layout);
            if (array_key_exists('weekdays', $data)) {
                $series->weekdays = $this->cleanWeekdays($data['weekdays']);
            }
            if (array_key_exists('weeks', $data)) {
                $series->weeks = $this->cleanWeeks($data['weeks']);
            }
            $series->save();

            // Dates aren't part of a layout: a changed rule never moves or deletes sheets.
            return $scope === 'none'
                ? 0
                : SignupLayout::fanOut($series, $layout, $scope === 'upcoming' ? now()->toDateString() : null);
        });

        $this->changed($channel);

        return response()->json(['data' => self::present($series->loadCount('sheets')), 'applied' => $applied]);
    }

    /** Make a month's sheets. Dates that already have one are skipped. */
    public function generate(TrackerRequest $request, Channel $channel, SignupSeries $series): JsonResponse
    {
        $this->own($channel, $series);
        SignupAccess::authorize($channel, $request->user());

        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        $created = DB::transaction(fn () => $series->generate($this->month($data['month']), $request->user()));

        $this->changed($channel);

        return response()->json([
            'created' => $created->count(),
            'year' => (int) substr($data['month'], 0, 4),
            'first_sheet_id' => $created->first()?->id,
        ]);
    }

    /** The sheets it made stay — they have names and charges on them. */
    public function destroy(TrackerRequest $request, Channel $channel, SignupSeries $series): Response
    {
        $this->own($channel, $series);
        SignupAccess::authorize($channel, $request->user());

        $series->delete();
        $this->changed($channel);

        return response()->noContent();
    }

    // --- helpers ------------------------------------------------------------------------------

    private function ruleRules(bool $required = true): array
    {
        return [
            'weekdays' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['integer', 'between:0,6'],
            'weeks' => ['nullable', 'array', 'max:5'],
            'weeks.*' => ['integer', Rule::in([1, 2, 3, 4, 5, SignupSchedule::LAST])],
        ];
    }

    private function rules(Channel $channel, bool $creating): array
    {
        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'group_id' => ['sometimes', 'nullable', 'integer', SignupController::groupRule($channel)],
            'month' => ['sometimes', 'nullable', 'date_format:Y-m'],
            ...$this->ruleRules($creating),
            ...SignupController::structureRules(),
        ];
    }

    /** @return list<int> */
    private function cleanWeekdays(array $days): array
    {
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);

        return $days;
    }

    /** An empty list means "every week", the same as null. */
    private function cleanWeeks(?array $weeks): ?array
    {
        if (! $weeks) {
            return null;
        }
        $weeks = array_values(array_unique(array_map('intval', $weeks)));
        sort($weeks);

        return $weeks;
    }

    private function month(string $ym): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $ym.'-01')->startOfDay();
    }

    private function own(Channel $channel, SignupSeries $series): void
    {
        abort_unless($series->channel_id === $channel->id, 404);
    }

    private function changed(Channel $channel): void
    {
        broadcast(new TrackerChanged('channel.'.$channel->id, 'signup', 'saved', ['id' => null]))->toOthers();
    }
}
