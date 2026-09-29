<?php

namespace App\Http\Controllers;

use App\Events\TrackerChanged;
use App\Http\Requests\Tracker\TrackerRequest;
use App\Models\Channel;
use App\Models\TrainingProgram;
use App\Models\TrainingTick;
use App\Support\Training\TrainingAccess;
use App\Support\Training\TrainingContent;
use App\Support\Training\TrainingTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The Training app: multi-week programs a channel follows, and each member's ticks.
 *
 * ## Who may do what
 *
 * Staff (everyone, in a DM or group) start, rename, reschedule and delete programs. Any member
 * ticks off their own blocks, and sees everyone's progress in the team view — a channel's
 * members can already see each other, and training together is the point.
 *
 * ## Broadcasts
 *
 * {@see TrackerChanged} under three subjects: `training_program` (an id — re-read the list),
 * `training_tick` (one tick, small and bounded) and `training_ticks` (someone cleared theirs —
 * re-read). Never a list of ticks: a program's ticks grow with the team.
 */
class TrainingController extends Controller
{
    public function index(TrackerRequest $request, Channel $channel): JsonResponse
    {
        return response()->json([
            'programs' => $channel->trainingPrograms()->latest('id')->get()->map->summary(),
            'templates' => TrainingTemplates::all(),
            'can_manage' => TrainingAccess::canManage($channel, $request->user()),
        ]);
    }

    /**
     * Start a program — from a built-in template, or from an imported document (the client reads
     * it out of an HTML or JSON file; see lib/trainingImport.ts). Either way it's validated here.
     */
    public function store(TrackerRequest $request, Channel $channel): JsonResponse
    {
        TrainingAccess::authorize($channel, $request->user());

        $data = $request->validate([
            'template' => ['required_without:content', 'nullable', 'string', 'max:60'],
            // Free-form, so read with input(): validated() would drop the nested document.
            'content' => ['required_without:template', 'nullable', 'array'],
            'title' => ['nullable', 'string', 'max:120'],
            'starts_on' => ['nullable', 'date'],
        ]);

        if (! empty($data['template'])) {
            abort_unless(TrainingTemplates::exists($data['template']), 422, 'No such template.');
            $content = TrainingContent::normalise(TrainingTemplates::load($data['template']));
        } else {
            $content = TrainingContent::normalise($request->input('content'));
        }

        $program = $channel->trainingPrograms()->create([
            'title' => trim($data['title'] ?? '') ?: ($content['meta']['title'] ?? 'Training'),
            'template' => $data['template'] ?? null,
            'starts_on' => $this->monday($data['starts_on'] ?? $content['meta']['default_start'] ?? null),
            'content' => $content,
            'created_by' => $request->user()->id,
        ]);

        $this->broadcast($channel, 'training_program', 'saved', ['id' => $program->id]);

        return response()->json(['data' => $this->full($program, $request->user()->id)], 201);
    }

    /** One program with its content, plus the caller's own ticks. */
    public function show(TrackerRequest $request, Channel $channel, TrainingProgram $program): JsonResponse
    {
        $this->belongs($channel, $program);

        return response()->json(['data' => $this->full($program, $request->user()->id)]);
    }

    public function update(TrackerRequest $request, Channel $channel, TrainingProgram $program): JsonResponse
    {
        $this->belongs($channel, $program);
        TrainingAccess::authorize($channel, $request->user());

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'starts_on' => ['sometimes', 'date'],
        ]);

        if (isset($data['starts_on'])) $data['starts_on'] = $this->monday($data['starts_on']);
        $program->update($data);

        $this->broadcast($channel, 'training_program', 'saved', ['id' => $program->id]);

        return response()->json(['data' => $this->full($program, $request->user()->id)]);
    }

    /**
     * Replace a program's whole document — the editor's save, or importing over it.
     *
     * Ticks point at `week:day:blockId`. Block ids survive edits, so exercises can be moved and
     * deleted freely. Weeks are positions, though: when the editor inserts or deletes weeks it
     * sends `week_map` (old week → new week, or null for deleted), and ticks follow it here.
     * Ticks on a day or week that no longer exists are dropped.
     */
    public function replaceContent(TrackerRequest $request, Channel $channel, TrainingProgram $program): JsonResponse
    {
        $this->belongs($channel, $program);
        TrainingAccess::authorize($channel, $request->user());

        $request->validate([
            'content' => ['required', 'array'],
            'week_map' => ['nullable', 'array'],
            'week_map.*' => ['nullable', 'integer', 'min:1', 'max:52'],
        ]);
        $content = TrainingContent::normalise($request->input('content'));

        DB::transaction(function () use ($program, $content, $request) {
            $program->update(['content' => $content]);
            $this->remapTicks($program, $request->input('week_map'), $content);
        });

        $this->broadcast($channel, 'training_program', 'saved', ['id' => $program->id]);

        return response()->json(['data' => $this->full($program->refresh(), $request->user()->id)]);
    }

    public function destroy(TrackerRequest $request, Channel $channel, TrainingProgram $program): Response
    {
        $this->belongs($channel, $program);
        TrainingAccess::authorize($channel, $request->user());

        $program->delete();
        $this->broadcast($channel, 'training_program', 'removed', ['id' => $program->id]);

        return response()->noContent();
    }

    /** Tick or untick one of your own blocks. Idempotent both ways. */
    public function tick(TrackerRequest $request, Channel $channel, TrainingProgram $program): JsonResponse
    {
        $this->belongs($channel, $program);

        $data = $request->validate([
            'week' => ['required', 'integer', 'min:1', 'max:'.max(1, $program->weekCount())],
            'day' => ['required', 'string', Rule::in($program->dayKeys())],
            // A block id. Loose on purpose: which session a day resolves to is the client's
            // reading of the content, and a stray id only ever marks the caller's own sheet.
            'block' => ['required', 'regex:/^[a-z0-9][a-z0-9_-]{0,39}$/'],
            'done' => ['required', 'boolean'],
        ]);

        $where = [
            'program_id' => $program->id,
            'user_id' => $request->user()->id,
            'week' => $data['week'],
            'day' => $data['day'],
            'block' => (string) $data['block'],
        ];

        if ($data['done']) {
            TrainingTick::firstOrCreate($where);
        } else {
            TrainingTick::where($where)->delete();
        }

        $key = "{$data['week']}:{$data['day']}:{$data['block']}";
        $this->broadcast($channel, 'training_tick', 'saved', [
            'program_id' => $program->id,
            'user_id' => $request->user()->id,
            'key' => $key,
            'done' => $data['done'],
        ]);

        return response()->json(['key' => $key, 'done' => $data['done']]);
    }

    /** Clear every one of your own ticks on this program. */
    public function clear(TrackerRequest $request, Channel $channel, TrainingProgram $program): Response
    {
        $this->belongs($channel, $program);

        $program->ticks()->where('user_id', $request->user()->id)->delete();
        $this->broadcast($channel, 'training_ticks', 'saved', [
            'program_id' => $program->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->noContent();
    }

    /** Everyone who has ticked anything, with their ticks — the team view counts them. */
    public function team(TrackerRequest $request, Channel $channel, TrainingProgram $program): JsonResponse
    {
        $this->belongs($channel, $program);

        $ticks = $program->ticks()->with('user:id,name,avatar')->get();

        $members = $ticks->groupBy('user_id')->map(fn ($rows) => [
            'user' => $rows->first()->user->only(['id', 'name', 'avatar']),
            'ticks' => $rows->map->key()->values(),
        ])->sortBy(fn ($m) => mb_strtolower($m['user']['name']))->values();

        return response()->json(['members' => $members]);
    }

    /**
     * Move ticks to their new weeks and drop the ones with nowhere to go.
     *
     * Rewritten as delete-and-reinsert rather than row updates: shifting week 6 to 5 while
     * week 5 still exists would trip the unique index mid-update.
     *
     * @param  array<int|string, int|null>|null  $weekMap
     */
    private function remapTicks(TrainingProgram $program, ?array $weekMap, array $content): void
    {
        $days = array_column($content['days'], 'key');
        $weeks = (int) $content['weeks'];

        $query = $program->ticks();
        if ($weekMap === null) {
            $query->where(fn ($q) => $q->where('week', '>', $weeks)->orWhereNotIn('day', $days))->delete();

            return;
        }

        $rows = $query->get(['user_id', 'week', 'day', 'block', 'created_at']);
        $program->ticks()->delete();

        $keep = [];
        foreach ($rows as $t) {
            $week = array_key_exists($t->week, $weekMap) ? $weekMap[$t->week] : $t->week;
            if ($week === null || $week < 1 || $week > $weeks || ! in_array($t->day, $days, true)) continue;
            $keep["{$t->user_id}:{$week}:{$t->day}:{$t->block}"] = [
                'program_id' => $program->id,
                'user_id' => $t->user_id,
                'week' => (int) $week,
                'day' => $t->day,
                'block' => $t->block,
                'created_at' => $t->created_at,
            ];
        }
        foreach (array_chunk(array_values($keep), 500) as $chunk) TrainingTick::insert($chunk);
    }

    private function full(TrainingProgram $program, int $userId): array
    {
        return [
            ...$program->summary(),
            'content' => $program->content,
            'mine' => $program->ticks()->where('user_id', $userId)->get()->map->key()->values(),
        ];
    }

    private function belongs(Channel $channel, TrainingProgram $program): void
    {
        abort_unless($program->channel_id === $channel->id, 404);
    }

    /** Week 1 always starts on a Monday — the day offsets count from it. */
    private function monday(?string $date): string
    {
        $d = $date ? Carbon::parse($date) : now();

        return $d->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    private function broadcast(Channel $channel, string $subject, string $action, array $payload): void
    {
        broadcast(new TrackerChanged('channel.'.$channel->id, $subject, $action, $payload))->toOthers();
    }
}
