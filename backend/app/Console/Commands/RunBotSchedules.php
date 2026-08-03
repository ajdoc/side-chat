<?php

namespace App\Console\Commands;

use App\Models\BotSchedule;
use App\Models\BotSettings;
use App\Services\Automation\Actions\PostMessageAction;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\TriggerRegistry;
use App\Support\Automation\AutomationContext;
use Illuminate\Console\Command;

/**
 * Posts whatever is due. Run every minute — see routes/console.php.
 *
 * The query is an index range scan over `(enabled, next_run_at)` rather than a walk over
 * every schedule parsing cron expressions, which is what keeps this a constant cost as the
 * number of servers grows. `next_run_at` is the stored answer to "when next", recomputed
 * only after a row actually fires.
 *
 * Two things happen per due schedule, and the order matters: the window moves forward
 * *first*, then the message goes out. A post that throws would otherwise leave `next_run_at`
 * in the past and the schedule would fire again the next minute, and the minute after — a
 * broken channel turning into a flood. Missing one post is the better failure.
 */
class RunBotSchedules extends Command
{
    protected $signature = 'bot:run-schedules';

    protected $description = 'Post any bot schedules that are due.';

    public function handle(PostMessageAction $post, AutomationEngine $engine): int
    {
        $due = BotSchedule::with('server')
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->get();

        foreach ($due as $schedule) {
            $schedule->markRun();
            $this->post($schedule, $post, $engine);
        }

        $this->info("Ran {$due->count()} schedule(s).");

        return self::SUCCESS;
    }

    private function post(BotSchedule $schedule, PostMessageAction $post, AutomationEngine $engine): void
    {
        $server = $schedule->server;

        if ($server === null) {
            return;
        }

        // A schedule with no channel of its own falls back to the server's reminder channel,
        // so moving every unassigned schedule is one setting rather than an edit each.
        $channelIds = $schedule->channelIds(BotSettings::forServer($server)->reminder_channel_id);

        $context = new AutomationContext($server->getKey(), TriggerRegistry::SCHEDULE_DUE, [
            'schedule_id' => $schedule->getKey(),
            'schedule_name' => $schedule->name,
            'channel_id' => $channelIds[0] ?? null,
            'server_name' => $server->name,
        ]);

        /*
         * Straight through the automation action, not a private copy of "post a message":
         * the bot lookup, the private-channel check and the placeholder rendering are all
         * things a schedule needs and all things that action already gets right.
         *
         * One call per channel, and a channel that refuses doesn't stop the others — a
         * schedule posting to three rooms shouldn't go silent because the bot was removed
         * from one of them.
         */
        foreach ($channelIds as $channelId) {
            $post->handle(['channel_id' => $channelId, 'body' => $schedule->body], $context);
        }

        // Fired either way. "The weekly headcount just went out" is true even when the
        // schedule had nowhere to post it, and a rule may want to do something else about it.
        $engine->fire($context);
    }
}
