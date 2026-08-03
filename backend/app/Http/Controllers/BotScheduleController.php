<?php

namespace App\Http\Controllers;

use App\Console\Commands\RunBotSchedules;
use App\Http\Requests\Automation\ManageAutomationsRequest;
use App\Http\Requests\Automation\StoreBotScheduleRequest;
use App\Models\BotSchedule;
use App\Models\BotSettings;
use App\Models\Server;
use App\Services\Automation\Actions\PostMessageAction;
use App\Services\Automation\TriggerRegistry;
use App\Support\Automation\AutomationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The Schedules page: recurring posts.
 *
 * `next_run_at` is computed on every write rather than by the runner. It's derived from the
 * cron expression and the timezone, so the moment either changes the stored answer is stale
 * — and a schedule whose next run is wrong is a schedule that fires at the wrong time, which
 * is the one bug this feature can't afford.
 */
class BotScheduleController extends Controller
{
    public function index(ManageAutomationsRequest $request, Server $server): JsonResponse
    {
        return response()->json([
            'data' => $server->schedules()->orderBy('name')->get()->map($this->present(...)),
            // The presets the UI offers instead of asking anybody to write cron.
            'presets' => BotSchedule::PRESETS,
        ]);
    }

    public function store(StoreBotScheduleRequest $request, Server $server): JsonResponse
    {
        $schedule = $server->schedules()->make($request->validated());
        $schedule->next_run_at = $schedule->computeNextRun();
        $schedule->save();

        return response()->json(['data' => $this->present($schedule)], 201);
    }

    public function update(StoreBotScheduleRequest $request, Server $server, BotSchedule $schedule): JsonResponse
    {
        $this->belongsTo($server, $schedule);

        $schedule->fill($request->validated());
        // Recomputed from the *new* expression, before the save — otherwise a schedule moved
        // from Monday to Friday would still fire on Monday, once.
        $schedule->next_run_at = $schedule->computeNextRun();
        $schedule->save();

        return response()->json(['data' => $this->present($schedule)]);
    }

    public function toggle(ManageAutomationsRequest $request, Server $server, BotSchedule $schedule): JsonResponse
    {
        $this->belongsTo($server, $schedule);

        $schedule->enabled = ! $schedule->enabled;
        // Switching one back on after a month shouldn't fire it immediately for every run it
        // slept through — the window restarts from now.
        $schedule->next_run_at = $schedule->enabled ? $schedule->computeNextRun() : null;
        $schedule->save();

        return response()->json(['data' => $this->present($schedule)]);
    }

    /**
     * Send it now, without waiting for its clock.
     *
     * Doesn't move `next_run_at`: the Monday post is still due on Monday. Same reasoning as
     * {@see \App\Services\Automation\Actions\RunScheduleAction}, and the same path.
     */
    public function run(
        ManageAutomationsRequest $request,
        Server $server,
        BotSchedule $schedule,
        PostMessageAction $post,
    ): JsonResponse {
        $this->belongsTo($server, $schedule);

        $channelIds = $schedule->channelIds(BotSettings::forServer($server)->reminder_channel_id);

        if ($channelIds === []) {
            return response()->json(['data' => ['sent' => false, 'reason' => 'This schedule has nowhere to post.']]);
        }

        $context = new AutomationContext($server->getKey(), TriggerRegistry::SCHEDULE_DUE, [
            'schedule_id' => $schedule->getKey(),
            'schedule_name' => $schedule->name,
            'channel_id' => $channelIds[0],
            'server_name' => $server->name,
        ]);

        // Reported as sent if *any* channel took it — one room refusing shouldn't read as a
        // total failure when the other two got it.
        $results = array_map(
            fn (int $id) => $post->handle(['channel_id' => $id, 'body' => $schedule->body], $context),
            $channelIds,
        );

        $result = collect($results)->firstWhere(fn ($r) => $r->succeeded()) ?? $results[0];

        // The action's own words for why nothing happened — "the bot isn't in #private" is
        // more use than a generic failure.
        return response()->json(['data' => [
            'sent' => $result->succeeded(),
            'reason' => $result->message,
        ]]);
    }

    public function destroy(ManageAutomationsRequest $request, Server $server, BotSchedule $schedule): Response
    {
        $this->belongsTo($server, $schedule);

        $schedule->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(BotSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'server_id' => $schedule->server_id,
            'name' => $schedule->name,
            'channel_id' => $schedule->channel_id,
            'extra_channel_ids' => $schedule->extra_channel_ids ?? [],
            'body' => $schedule->body,
            'cron' => $schedule->cron,
            'timezone' => $schedule->timezone,
            'enabled' => (bool) $schedule->enabled,
            'last_run_at' => $schedule->last_run_at,
            'next_run_at' => $schedule->next_run_at,
        ];
    }

    private function belongsTo(Server $server, BotSchedule $schedule): void
    {
        abort_if($schedule->server_id !== $server->getKey(), 404);
    }
}
