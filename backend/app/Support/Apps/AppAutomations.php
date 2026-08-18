<?php

namespace App\Support\Apps;

use App\Models\Channel;
use App\Models\KanbanBoard;
use App\Models\KanbanCard;
use App\Models\TrackerTask;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\TriggerRegistry;
use App\Support\Automation\AutomationContext;
use App\Support\Automation\Subject;

/**
 * Firing the productivity apps' automation triggers.
 *
 * ## Why a helper rather than a listener
 *
 * `message.created` hangs off an event, because a message can be created from five places and a
 * trigger that fired for some of them would be worse than one that fired for none. The apps are
 * the opposite: their rows are created from several paths *on purpose* and only some of them
 * are things a person just did.
 *
 * The line this draws is **one item, by a person** fires; **a bulk arrival** does not. So the
 * board UI, `k!add` and filing a message all fire, and an import of eighty-four cards fires
 * nothing. That isn't a limitation dressed up — a rule that posts "card added" is a rule
 * somebody wants to read, and eighty-four of those is a channel nobody can read. The import
 * announces itself once, in its own way (see AppImports).
 *
 * ## Why it can't throw
 *
 * Automations are a side effect of doing the work, never a condition of it. A board must not
 * fail to accept a card because a rule engine is unhappy, so every call here is best-effort and
 * the engine's own queue is where the real work happens.
 */
final class AppAutomations
{
    public static function cardCreated(KanbanCard $card, ?User $actor): void
    {
        $board = $card->board;

        self::fire($card->channel, TriggerRegistry::KANBAN_CARD_CREATED, $actor, [
            'card_id' => $card->getKey(),
            'column' => $card->column,
            'column_label' => self::columnLabel($board, $card->column),
            'text' => (string) $card->text,
        ]);
    }

    /** Both ends of the move: "announce it when something reaches Done" is the rule people build. */
    public static function cardMoved(KanbanCard $card, string $from, ?User $actor): void
    {
        if ($from === $card->column) {
            return;
        }

        self::fire($card->channel, TriggerRegistry::KANBAN_CARD_MOVED, $actor, [
            'card_id' => $card->getKey(),
            'from' => $from,
            'to' => $card->column,
            'to_label' => self::columnLabel($card->board, $card->column),
            'text' => (string) $card->text,
        ]);
    }

    public static function taskCreated(TrackerTask $task, ?User $actor): void
    {
        $task->loadMissing('project.channel');

        self::fire($task->project?->channel, TriggerRegistry::TRACKER_TASK_CREATED, $actor, [
            ...self::taskFields($task),
            'status' => (string) $task->status,
        ]);
    }

    public static function taskStatusChanged(TrackerTask $task, string $from, ?User $actor): void
    {
        if ($from === $task->status) {
            return;
        }

        $task->loadMissing('project.channel');

        self::fire($task->project?->channel, TriggerRegistry::TRACKER_TASK_STATUS_CHANGED, $actor, [
            ...self::taskFields($task),
            'from' => $from,
            'to' => (string) $task->status,
        ]);
    }

    /** @return array<string, mixed> */
    private static function taskFields(TrackerTask $task): array
    {
        return [
            'task_id' => $task->getKey(),
            // The key, because `ONB-4` is how a task is referred to everywhere else — in chat,
            // in commits, and in whatever message the rule is about to post.
            'task_key' => $task->project?->key ? $task->project->key.'-'.$task->number : (string) $task->number,
            'project_key' => (string) $task->project?->key,
            'project_name' => (string) $task->project?->name,
            'title' => (string) $task->title,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function fire(?Channel $channel, string $trigger, ?User $actor, array $data): void
    {
        $channel?->loadMissing('server');
        $server = $channel?->server;

        // Automations belong to a server. A DM's board is a real board and simply has nobody to
        // own a rule about it, so there is nothing to fire rather than something to refuse.
        if ($channel === null || $server === null) {
            return;
        }

        app(AutomationEngine::class)->fire(new AutomationContext(
            $server->getKey(),
            $trigger,
            [
                ...Subject::fields($actor, $server),
                'channel_id' => $channel->getKey(),
                'channel_name' => (string) $channel->name,
                ...$data,
            ],
        ));
    }

    private static function columnLabel(?KanbanBoard $board, string $key): string
    {
        foreach ($board?->columns ?? [] as $column) {
            if (($column['key'] ?? null) === $key) {
                return (string) $column['label'];
            }
        }

        return $key;
    }
}
