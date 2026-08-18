<?php

namespace App\Services\Automation\Actions;

use App\Models\Channel;
use App\Models\TrackerProject;
use App\Services\Automation\AutomationActionHandler;
use App\Support\Automation\ActionResult;
use App\Support\Automation\AutomationContext;
use App\Support\Automation\Template;
use Illuminate\Support\Facades\DB;

/**
 * Open a task in a tracker project.
 *
 * The heavier half of "a rule can file work": a card is a line on a board, a task has a key,
 * a status and a history. Used for the rules where something must not be forgotten — a report
 * command that opens a triage task, a schedule that opens the weekly checklist.
 *
 * The project is named by its **key** (`ONB`), not its id: a key is what people say out loud
 * and what appears in every task reference, where an id is a number nobody using the dashboard
 * has ever seen.
 */
final class CreateTrackerTaskAction implements AutomationActionHandler
{
    public function name(): string
    {
        return 'create_tracker_task';
    }

    public function label(): string
    {
        return 'Open a tracker task';
    }

    public function schema(): array
    {
        return [
            [
                'key' => 'channel_id',
                'type' => 'channel',
                'label' => 'Tracker channel',
                'required' => true,
                // Required, unlike the board's: a tracker lives in a channel somebody set up on
                // purpose, and "wherever this happened" is usually a chat with no projects in it.
                'help' => 'The channel whose tracker holds the project.',
            ],
            [
                'key' => 'project_key',
                'type' => 'text',
                'label' => 'Project key',
                'required' => true,
                'help' => 'The short key, e.g. ONB.',
            ],
            [
                'key' => 'title',
                'type' => 'text',
                'label' => 'Task title',
                'required' => true,
                'placeholders' => ['user', 'server', 'channel'],
            ],
            [
                'key' => 'description',
                'type' => 'textarea',
                'label' => 'Description',
                'required' => false,
                'placeholders' => ['user', 'server', 'channel'],
            ],
        ];
    }

    public function handle(array $config, AutomationContext $context): ActionResult
    {
        $server = $context->server();
        $channel = $server === null
            ? null
            : Channel::where('server_id', $server->getKey())->find((int) ($config['channel_id'] ?? 0));

        if ($channel === null) {
            return ActionResult::skipped('The tracker channel this rule uses no longer exists.');
        }

        $project = TrackerProject::where('channel_id', $channel->getKey())
            ->whereRaw('UPPER(key) = ?', [mb_strtoupper(trim((string) ($config['project_key'] ?? '')))])
            ->first();

        if ($project === null) {
            // Named rather than vague: a mistyped key is the likeliest cause, and the dashboard
            // line is the only place anybody will find out.
            return ActionResult::skipped('No project “'.($config['project_key'] ?? '').'” in '.$channel->name.'.');
        }

        $rendered = fn (string $key) => trim(Template::render((string) ($config[$key] ?? ''), $context->with([
            'server_name' => $server->name,
        ])));

        $title = $rendered('title');

        if ($title === '') {
            return ActionResult::skipped('The task title came out empty.');
        }

        $actor = $context->subject();

        $task = DB::transaction(function () use ($project, $title, $rendered, $actor) {
            // Under the same row lock the Tracker's own create uses — a task number handed out
            // twice would be a reference that silently changes meaning.
            $number = $project->takeNextNumber();

            return $project->tasks()->create([
                'number' => $number,
                'title' => mb_substr($title, 0, 200),
                'description' => $rendered('description') ?: null,
                'created_by' => $actor?->getKey(),
                'position' => ((int) $project->tasks()->max('position')) + 1,
            ]);
        });

        $task->recordActivity('created', $actor, ['automation' => true]);

        $key = $project->key.'-'.$task->number;

        return ActionResult::ok("Opened {$key}.", ['task_id' => $task->getKey(), 'task_key' => $key]);
    }
}
