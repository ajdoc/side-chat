<?php

namespace App\Services\Widgets;

use App\Models\KanbanBoard;
use App\Models\KanbanCard;
use App\Models\User;
use App\Models\Widget;
use App\Support\Apps\KanbanBoards;
use App\Support\Commands\ParsedCommand;

/**
 * A shared kanban board, driven by `k!` commands and the board UI alike.
 *
 * ## What changed, and why
 *
 * This used to hold the entire board in the widget's JSON state — a `seq` counter and an array
 * of cards, in three columns fixed in a constant. It is now a **pointer**: the whole state is
 * `{"board_id": 7}`, and every command reads and writes the {@see KanbanBoard} that id names.
 * The same move the Poll widget made, for two reasons a blob couldn't meet:
 *
 *   - **Columns are editable.** Add, rename, reorder, remove — and cards point at them, so the
 *     rename has to *not* rewrite every card. Two facts in one document is where concurrent
 *     edits lose each other.
 *   - **A card carries comments, tags and reactions.** Those tables key on a row id. An entry
 *     in a blob has nothing for `commentable_id` to point at.
 *
 * The commands are unchanged — that was the constraint. `k!done 12` still means "the card
 * minted as 12", except the number is now the row's id rather than a counter kept by hand,
 * which gives the same guarantee (never reused, never shifted by an edit above it) for free.
 * What's new is `k!col`, the column family, because columns are a thing you can change now.
 */
final class KanbanWidget implements WidgetHandler
{
    public function type(): string
    {
        return 'kanban';
    }

    /** No board yet — the first command or card action creates one and writes its id here. */
    public function initialState(): array
    {
        return ['board_id' => null];
    }

    public function command(Widget $widget, User $user, ParsedCommand $command): WidgetOutcome
    {
        if (in_array($command->verb, ['help', 'h'], true)) {
            return WidgetOutcome::reply($this->help());
        }

        $board = $this->board($widget);

        return match ($command->verb) {
            'add', 'a', 'new' => $this->add($board, $user, $command->args),
            'start', 'doing' => $this->moveTo($board, $command->firstArg(), 'doing'),
            'done', 'check', 'finish' => $this->moveTo($board, $command->firstArg(), 'done'),
            'reopen', 'undone', 'todo' => $this->moveTo($board, $command->firstArg(), 'todo'),
            'move', 'mv' => $this->move($board, $command),
            'edit', 'rename' => $this->edit($board, $command),
            'assign' => $this->assign($board, $widget, $command),
            'unassign' => $this->assignCard($board, (int) $command->firstArg(), null),
            'rm', 'del', 'delete', 'remove' => $this->remove($board, (int) $command->firstArg()),
            'clear' => $this->clear($board, $command->firstArg()),
            'col', 'column', 'cols', 'columns' => $this->column($board, $command),
            'list', 'board', 'ls' => WidgetOutcome::show(),
            default => WidgetOutcome::reply("Unknown board command `k!{$command->verb}`. Try `k!help`."),
        };
    }

    /**
     * The card's own controls.
     *
     * Kept for the timeline and canvas cards, which still act through the widget. The full board
     * UI talks to {@see \App\Http\Controllers\KanbanController} directly — it needs columns,
     * ordering and per-card threads, none of which fit an action payload.
     */
    public function action(Widget $widget, User $user, string $action, array $payload): WidgetOutcome
    {
        $board = $this->board($widget);
        $id = (int) ($payload['id'] ?? 0);

        return match ($action) {
            'add' => $this->add($board, $user, (string) ($payload['text'] ?? '')),
            'move' => $this->moveCardTo($board, $id, (string) ($payload['column'] ?? '')),
            'edit' => $this->editCard($board, $id, (string) ($payload['text'] ?? '')),
            'assign' => $this->assignCard($board, $id, isset($payload['user_id']) ? (int) $payload['user_id'] : null),
            'remove' => $this->remove($board, $id),
            'clear' => $this->clear($board, (string) ($payload['column'] ?? 'done')),
            default => WidgetOutcome::noop(),
        };
    }

    /**
     * The board this widget points at, created on first use.
     *
     * Resolved from the widget's *channel* rather than trusted from its state: there is exactly
     * one board per channel, so the id in the blob is a cache of a lookup and not an authority.
     * That also heals a widget whose state was hand-written to point somewhere else, which for
     * a poll needed an explicit channel scope on the query.
     */
    private function board(Widget $widget): KanbanBoard
    {
        $board = KanbanBoards::for($widget->channel, $widget->user);

        if (($widget->state['board_id'] ?? null) !== $board->id) {
            $widget->state = ['board_id' => $board->id];
        }

        return $board;
    }

    private function add(KanbanBoard $board, User $user, string $text): WidgetOutcome
    {
        $text = trim($text);

        if ($text === '') {
            return WidgetOutcome::reply('What should the card say? `k!add <text>`.');
        }

        $column = $board->firstColumn();

        $card = $board->cards()->create([
            'channel_id' => $board->channel_id,
            'column' => $column,
            'position' => KanbanBoards::nextPosition($board, $column),
            'text' => mb_substr($text, 0, KanbanCard::MAX_TEXT),
            'added_by' => $user->id,
            'added_by_name' => $user->name,
        ]);

        $card->recordActivity('created', $user, ['column' => $column]);
        KanbanBoards::cardSaved($card);

        return WidgetOutcome::card();
    }

    /**
     * `k!done 3` and friends.
     *
     * These name a column by the key the board shipped with. A board whose owner renamed or
     * removed that column no longer has one, and the command says so rather than guessing at
     * which of the new columns was meant — `k!move 3 <column>` is how you reach the others.
     */
    private function moveTo(KanbanBoard $board, string $ref, string $column): WidgetOutcome
    {
        if (! $board->hasColumn($column)) {
            return WidgetOutcome::reply("This board has no \"{$column}\" column. Try `k!move <n> <column>`, or `k!col` to see them.");
        }

        return $this->moveCardTo($board, (int) $ref, $column, viaCommand: true);
    }

    private function move(KanbanBoard $board, ParsedCommand $command): WidgetOutcome
    {
        $id = (int) $command->firstArg();
        $column = $board->resolveColumn($command->restAfterFirst());

        if ($column === null) {
            return WidgetOutcome::reply('Move where? `k!move <n> '.$this->columnList($board).'`.');
        }

        return $this->moveCardTo($board, $id, $column, viaCommand: true);
    }

    private function moveCardTo(KanbanBoard $board, int $id, string $column, bool $viaCommand = false): WidgetOutcome
    {
        $column = $board->resolveColumn($column);
        $card = $column === null ? null : $this->card($board, $id);

        if ($card === null) {
            return $viaCommand ? WidgetOutcome::reply("There's no card #{$id}.") : WidgetOutcome::noop();
        }

        $card->update(['column' => $column, 'position' => KanbanBoards::nextPosition($board, $column)]);
        KanbanBoards::cardSaved($card);

        return WidgetOutcome::updated();
    }

    private function edit(KanbanBoard $board, ParsedCommand $command): WidgetOutcome
    {
        $text = trim($command->restAfterFirst());

        if ($text === '') {
            return WidgetOutcome::reply('New text? `k!edit <n> <text>`.');
        }

        return $this->editCard($board, (int) $command->firstArg(), $text, viaCommand: true);
    }

    private function editCard(KanbanBoard $board, int $id, string $text, bool $viaCommand = false): WidgetOutcome
    {
        $text = trim($text);
        $card = $text === '' ? null : $this->card($board, $id);

        if ($card === null) {
            return $viaCommand ? WidgetOutcome::reply("There's no card #{$id}.") : WidgetOutcome::noop();
        }

        $card->update(['text' => mb_substr($text, 0, KanbanCard::MAX_TEXT)]);
        KanbanBoards::cardSaved($card);

        return WidgetOutcome::updated();
    }

    private function assign(KanbanBoard $board, Widget $widget, ParsedCommand $command): WidgetOutcome
    {
        $id = (int) $command->firstArg();

        if ($this->card($board, $id) === null) {
            return WidgetOutcome::reply("There's no card #{$id}.");
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

        return $this->assignCard($board, $id, $member->id);
    }

    private function assignCard(KanbanBoard $board, int $id, ?int $userId): WidgetOutcome
    {
        $card = $this->card($board, $id);

        if ($card === null) {
            return WidgetOutcome::reply("There's no card #{$id}.");
        }

        $card->update(['assignee_id' => $userId]);
        KanbanBoards::cardSaved($card);

        return WidgetOutcome::updated();
    }

    private function remove(KanbanBoard $board, int $id): WidgetOutcome
    {
        $card = $this->card($board, $id);

        if ($card === null) {
            return WidgetOutcome::reply("There's no card #{$id}.");
        }

        // Deleted one at a time so the model event takes its comments and reactions with it.
        $card->delete();
        KanbanBoards::cardRemoved($card);

        return WidgetOutcome::updated();
    }

    private function clear(KanbanBoard $board, string $column): WidgetOutcome
    {
        $key = $board->resolveColumn($column === '' ? 'done' : $column);

        if ($key === null) {
            return WidgetOutcome::reply('Clear which column? `k!clear '.$this->columnList($board).'`.');
        }

        $cards = $board->cards()->where('column', $key)->get();

        if ($cards->isEmpty()) {
            return WidgetOutcome::reply($this->label($board, $key).' is already empty.');
        }

        foreach ($cards as $card) {
            $card->delete();
        }

        KanbanBoards::boardSaved($board);

        return WidgetOutcome::updated();
    }

    /**
     * `k!col` — the column family, new with editable columns.
     *
     * One verb with sub-verbs rather than four top-level ones (`k!addcol`, `k!rmcol`, …): the
     * columns are a single thing you occasionally reshape, and `k!col` on its own listing them
     * is the answer to "what can I move a card to", which is the question people actually have.
     */
    private function column(KanbanBoard $board, ParsedCommand $command): WidgetOutcome
    {
        $sub = mb_strtolower($command->firstArg());
        $rest = trim($command->restAfterFirst());

        return match ($sub) {
            '', 'list', 'ls' => WidgetOutcome::reply('Columns: '.$this->columnList($board)),
            'add', 'new' => $this->addColumn($board, $rest),
            'rename' => $this->renameColumn($board, $rest),
            'rm', 'del', 'delete', 'remove' => $this->removeColumn($board, $rest),
            default => WidgetOutcome::reply('`k!col` · `k!col add <name>` · `k!col rename <column> <name>` · `k!col rm <column>`'),
        };
    }

    private function addColumn(KanbanBoard $board, string $label): WidgetOutcome
    {
        $label = mb_substr(trim($label), 0, 40);

        if ($label === '') {
            return WidgetOutcome::reply('What should the column be called? `k!col add <name>`.');
        }

        if (count($board->columns) >= KanbanBoard::MAX_COLUMNS) {
            return WidgetOutcome::reply('This board already has the maximum of '.KanbanBoard::MAX_COLUMNS.' columns.');
        }

        $board->columns = [...$board->columns, ['key' => $board->mintColumnKey($label), 'label' => $label]];
        $board->save();
        KanbanBoards::boardSaved($board);

        return WidgetOutcome::updated();
    }

    /** `k!col rename <column> <new name>` — the first word names the column, the rest is the label. */
    private function renameColumn(KanbanBoard $board, string $args): WidgetOutcome
    {
        [$which, $label] = array_pad(explode(' ', trim($args), 2), 2, '');
        $key = $board->resolveColumn($which);
        $label = mb_substr(trim($label), 0, 40);

        if ($key === null || $label === '') {
            return WidgetOutcome::reply('Rename which column? `k!col rename <column> <name>`.');
        }

        $columns = $board->columns;
        $columns[array_search($key, array_column($columns, 'key'), true)]['label'] = $label;
        // The key stays as it was — the cards in this column point at it, and a key that
        // followed the label would empty the column on every rename.
        $board->columns = $columns;
        $board->save();
        KanbanBoards::boardSaved($board);

        return WidgetOutcome::updated();
    }

    private function removeColumn(KanbanBoard $board, string $which): WidgetOutcome
    {
        $key = $board->resolveColumn($which);

        if ($key === null) {
            return WidgetOutcome::reply('Remove which column? `k!col rm '.$this->columnList($board).'`.');
        }

        if (count($board->columns) <= 1) {
            return WidgetOutcome::reply('A board needs at least one column.');
        }

        $columns = $board->columns;
        $index = array_search($key, array_column($columns, 'key'), true);
        $fallback = $columns[$index - 1]['key'] ?? $columns[$index + 1]['key'];

        array_splice($columns, $index, 1);
        $board->columns = array_values($columns);
        $board->save();

        // The cards move rather than going with the column — see KanbanController::destroyColumn.
        $board->cards()->where('column', $key)->update([
            'column' => $fallback,
            'position' => KanbanBoards::nextPosition($board, $fallback),
        ]);

        KanbanBoards::boardSaved($board);

        return WidgetOutcome::reply('Removed. Its cards moved to '.$this->label($board, $fallback).'.');
    }

    /** A card of *this* board, by the number people type. Null if it isn't one. */
    private function card(KanbanBoard $board, int $id): ?KanbanCard
    {
        return $board->cards()->whereKey($id)->first();
    }

    private function label(KanbanBoard $board, string $key): string
    {
        foreach ($board->columns ?? [] as $column) {
            if ($column['key'] === $key) {
                return (string) $column['label'];
            }
        }

        return $key;
    }

    private function columnList(KanbanBoard $board): string
    {
        return implode('|', $board->columnKeys());
    }

    private function help(): string
    {
        return implode("\n", [
            '📋 **Board commands**',
            '`k!add <text>` — add a card to the first column',
            '`k!start <n>` · `k!done <n>` · `k!reopen <n>` — move card #n',
            '`k!move <n> <column>` · `k!edit <n> <text>`',
            '`k!assign <n> @name` · `k!rm <n>` · `k!clear <column>`',
            '`k!col` — list columns   `k!col add <name>` · `k!col rename <column> <name>` · `k!col rm <column>`',
            '`k!list` — bring the board back to the bottom',
        ]);
    }
}
