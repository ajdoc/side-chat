<?php

namespace App\Services\Widgets;

use App\Models\User;
use App\Models\Widget;
use App\Support\Commands\ParsedCommand;

/**
 * A shared kanban board — three columns (To Do / Doing / Done) of cards, driven by `k!`
 * commands and the card UI alike.
 *
 * Cards are referenced by a stable number, not a position: `k!done 3` always means the
 * card minted as #3, whatever column it's since drifted to. That number is `seq`, handed
 * out once and never reused, so a command a user typed after glancing at the board can't
 * hit the wrong card because something above it moved.
 *
 * State shape:
 *   seq:   int                       — the last card number handed out
 *   cards: [ { id, text, column, assignee: {id,name}|null, addedBy } ]
 */
final class KanbanWidget implements WidgetHandler
{
    private const COLUMNS = ['todo', 'doing', 'done'];

    private const ALIASES = ['todo' => 'todo', 'to-do' => 'todo', 'doing' => 'doing', 'wip' => 'doing', 'done' => 'done'];

    public function type(): string
    {
        return 'kanban';
    }

    public function initialState(): array
    {
        return ['seq' => 0, 'cards' => []];
    }

    public function command(Widget $widget, User $user, ParsedCommand $command): WidgetOutcome
    {
        return match ($command->verb) {
            'add', 'a', 'new' => $this->add($widget, $user, $command->args),
            'start', 'doing' => $this->moveTo($widget, $command->firstArg(), 'doing'),
            'done', 'check', 'finish' => $this->moveTo($widget, $command->firstArg(), 'done'),
            'reopen', 'undone', 'todo' => $this->moveTo($widget, $command->firstArg(), 'todo'),
            'move', 'mv' => $this->move($widget, $command),
            'edit', 'rename' => $this->edit($widget, $command),
            'assign' => $this->assign($widget, $command),
            'unassign' => $this->assign($widget, $command, clear: true),
            'rm', 'del', 'delete', 'remove' => $this->remove($widget, $command->firstArg()),
            'clear' => $this->clear($widget, $command->firstArg()),
            'list', 'board', 'ls' => WidgetOutcome::show(),
            'help', 'h' => WidgetOutcome::reply($this->help()),
            default => WidgetOutcome::reply("Unknown board command `k!{$command->verb}`. Try `k!help`."),
        };
    }

    public function action(Widget $widget, User $user, string $action, array $payload): WidgetOutcome
    {
        $id = (int) ($payload['id'] ?? 0);

        return match ($action) {
            'add' => $this->add($widget, $user, (string) ($payload['text'] ?? '')),
            'move' => $this->moveCardTo($widget, $id, (string) ($payload['column'] ?? '')),
            'edit' => $this->editCard($widget, $id, (string) ($payload['text'] ?? '')),
            'assign' => $this->assignCard($widget, $id, isset($payload['user_id']) ? (int) $payload['user_id'] : null),
            'remove' => $this->removeCard($widget, $id),
            'clear' => $this->clear($widget, (string) ($payload['column'] ?? 'done')),
            default => WidgetOutcome::updated(),
        };
    }

    private function add(Widget $widget, User $user, string $text): WidgetOutcome
    {
        $text = trim($text);
        if ($text === '') {
            return WidgetOutcome::reply('What should the card say? `k!add <text>`.');
        }

        $state = $widget->state;
        $state['seq']++;
        $state['cards'][] = [
            'id' => $state['seq'],
            'text' => mb_substr($text, 0, 280),
            'column' => 'todo',
            'assignee' => null,
            'addedBy' => $user->name,
        ];
        $widget->state = $state;

        return WidgetOutcome::card();
    }

    private function moveTo(Widget $widget, string $ref, string $column): WidgetOutcome
    {
        return $this->moveCardTo($widget, (int) $ref, $column, viaCommand: true);
    }

    private function move(Widget $widget, ParsedCommand $command): WidgetOutcome
    {
        $id = (int) $command->firstArg();
        $column = $this->normaliseColumn($command->restAfterFirst());
        if ($column === null) {
            return WidgetOutcome::reply('Move where? `k!move <n> todo|doing|done`.');
        }

        return $this->moveCardTo($widget, $id, $column, viaCommand: true);
    }

    private function moveCardTo(Widget $widget, int $id, string $column, bool $viaCommand = false): WidgetOutcome
    {
        $column = $this->normaliseColumn($column);
        if ($column === null) {
            return WidgetOutcome::updated();
        }

        $state = $widget->state;
        $index = $this->indexOf($state, $id);
        if ($index === null) {
            return $viaCommand ? WidgetOutcome::reply("There's no card #{$id}.") : WidgetOutcome::updated();
        }

        $state['cards'][$index]['column'] = $column;
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function edit(Widget $widget, ParsedCommand $command): WidgetOutcome
    {
        $id = (int) $command->firstArg();
        $text = trim($command->restAfterFirst());
        if ($text === '') {
            return WidgetOutcome::reply('New text? `k!edit <n> <text>`.');
        }

        return $this->editCard($widget, $id, $text, viaCommand: true);
    }

    private function editCard(Widget $widget, int $id, string $text, bool $viaCommand = false): WidgetOutcome
    {
        $text = trim($text);
        $state = $widget->state;
        $index = $this->indexOf($state, $id);
        if ($index === null || $text === '') {
            return $viaCommand ? WidgetOutcome::reply("There's no card #{$id}.") : WidgetOutcome::updated();
        }
        $state['cards'][$index]['text'] = mb_substr($text, 0, 280);
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function assign(Widget $widget, ParsedCommand $command, bool $clear = false): WidgetOutcome
    {
        $id = (int) $command->firstArg();
        if ($this->indexOf($widget->state, $id) === null) {
            return WidgetOutcome::reply("There's no card #{$id}.");
        }
        if ($clear) {
            return $this->assignCard($widget, $id, null);
        }

        $name = ltrim(trim($command->restAfterFirst()), '@');
        if ($name === '') {
            return WidgetOutcome::reply('Assign to whom? `k!assign <n> @name`.');
        }

        $member = $widget->channel->container()?->members()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($member === null) {
            return WidgetOutcome::reply("No member here called \"{$name}\".");
        }

        return $this->assignCard($widget, $id, $member->id, $member->name);
    }

    private function assignCard(Widget $widget, int $id, ?int $userId, ?string $name = null): WidgetOutcome
    {
        $state = $widget->state;
        $index = $this->indexOf($state, $id);
        if ($index === null) {
            return WidgetOutcome::updated();
        }

        if ($userId === null) {
            $state['cards'][$index]['assignee'] = null;
        } else {
            // Trust a name if we were handed one (command path); otherwise look it up.
            $name ??= $widget->channel->container()?->members()->whereKey($userId)->value('name');
            if ($name === null) {
                return WidgetOutcome::updated();
            }
            $state['cards'][$index]['assignee'] = ['id' => $userId, 'name' => $name];
        }
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function remove(Widget $widget, string $ref): WidgetOutcome
    {
        $id = (int) $ref;
        if ($this->indexOf($widget->state, $id) === null) {
            return WidgetOutcome::reply("There's no card #{$id}.");
        }

        return $this->removeCard($widget, $id);
    }

    private function removeCard(Widget $widget, int $id): WidgetOutcome
    {
        $state = $widget->state;
        $index = $this->indexOf($state, $id);
        if ($index === null) {
            return WidgetOutcome::updated();
        }
        array_splice($state['cards'], $index, 1);
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function clear(Widget $widget, string $column): WidgetOutcome
    {
        $column = $this->normaliseColumn($column) ?? 'done';
        $state = $widget->state;
        $before = count($state['cards']);
        $state['cards'] = array_values(array_filter($state['cards'], fn ($c) => $c['column'] !== $column));
        if (count($state['cards']) === $before) {
            return WidgetOutcome::reply(ucfirst($column).' is already empty.');
        }
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    /** @return int|null index into the cards array, by stable card number */
    private function indexOf(array $state, int $id): ?int
    {
        foreach ($state['cards'] as $i => $card) {
            if ((int) $card['id'] === $id) {
                return $i;
            }
        }

        return null;
    }

    private function normaliseColumn(string $column): ?string
    {
        $key = strtolower(trim($column));

        return self::ALIASES[$key] ?? (in_array($key, self::COLUMNS, true) ? $key : null);
    }

    private function help(): string
    {
        return implode("\n", [
            '📋 **Board commands**',
            '`k!add <text>` — add a card to To Do',
            '`k!start <n>` · `k!done <n>` · `k!reopen <n>` — move card #n',
            '`k!move <n> todo|doing|done` · `k!edit <n> <text>`',
            '`k!assign <n> @name` · `k!rm <n>` · `k!clear done`',
            '`k!list` — bring the board back to the bottom',
        ]);
    }
}
