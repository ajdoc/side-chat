<?php

namespace App\Services\Automation\Actions;

use App\Models\BotSchedule;
use App\Models\BotSettings;
use App\Services\Automation\AutomationActionHandler;
use App\Support\Automation\ActionResult;
use App\Support\Automation\AutomationContext;

/**
 * Send one of this server's scheduled posts now, off its usual clock.
 *
 * The other half of making the built-ins compose: "when the stream goes live, post the
 * weekly headcount" shouldn't mean copying the headcount's text into a rule where it will
 * quietly drift out of date. One message, one place to edit it, two ways to reach it.
 *
 * Firing early does **not** move the schedule's own window. `next_run_at` is what the
 * Monday-morning post is for, and a rule that fired on Thursday shouldn't push it to next
 * Thursday — the two are separate reasons for the same message to go out.
 */
final class RunScheduleAction implements AutomationActionHandler
{
    public function __construct(private readonly PostMessageAction $post) {}

    public function name(): string
    {
        return 'run_schedule';
    }

    public function label(): string
    {
        return 'Send a schedule now';
    }

    public function schema(): array
    {
        return [[
            'key' => 'schedule_id',
            'type' => 'schedule',
            'label' => 'Schedule',
            'required' => true,
        ]];
    }

    public function handle(array $config, AutomationContext $context): ActionResult
    {
        $server = $context->server();

        if ($server === null) {
            return ActionResult::skipped('This server no longer exists.');
        }

        $schedule = BotSchedule::where('server_id', $server->getKey())->find($config['schedule_id'] ?? null);

        if ($schedule === null) {
            return ActionResult::failed('That schedule has been deleted.');
        }

        // A disabled schedule is one somebody switched off. Reaching round that from a rule
        // would make the off switch a lie.
        if (! $schedule->enabled) {
            return ActionResult::skipped("“{$schedule->name}” is switched off.");
        }

        $channelId = $schedule->channel_id ?? BotSettings::forServer($server)->reminder_channel_id;

        if ($channelId === null) {
            return ActionResult::skipped("“{$schedule->name}” has nowhere to post.");
        }

        // Through the same action the runner uses, so an early send behaves in every way
        // like an on-time one.
        return $this->post->handle(
            ['channel_id' => $channelId, 'body' => $schedule->body],
            $context,
        );
    }
}
