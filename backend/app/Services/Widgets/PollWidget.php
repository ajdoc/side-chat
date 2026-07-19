<?php

namespace App\Services\Widgets;

use App\Models\User;
use App\Models\Widget;
use App\Support\Commands\ParsedCommand;

/**
 * A shared poll — one question, a list of options, and a live tally everyone sees, driven
 * by `p!` commands and the card's buttons alike.
 *
 * Like the board, an option is referenced by a stable number, not a position: `p!vote 2`
 * always means the option minted as #2, whatever's since been added or removed above it.
 * That number is `seq`, handed out once and never reused, so a vote a user casts after
 * glancing at the card can't land on the wrong option because the list shifted.
 *
 * A vote records *who* cast it (id + name), so the tally is a live count, a voter can
 * toggle their own vote off, and the card can show each person what they picked. In
 * single-choice mode (the default) casting a vote clears that voter's other picks; `p!multi`
 * flips it to let-me-pick-several.
 *
 * State shape:
 *   seq:      int                                   — the last option number handed out
 *   question: string
 *   multi:    bool                                  — allow more than one pick per voter
 *   closed:   bool                                  — voting locked; the result stands
 *   options:  [ { id, text, voters: [{id,name}] } ]
 */
final class PollWidget implements WidgetHandler
{
    private const MAX_OPTIONS = 20;

    private const MAX_QUESTION = 280;

    private const MAX_OPTION = 200;

    public function type(): string
    {
        return 'poll';
    }

    public function initialState(): array
    {
        return ['seq' => 0, 'question' => '', 'multi' => false, 'closed' => false, 'options' => []];
    }

    public function command(Widget $widget, User $user, ParsedCommand $command): WidgetOutcome
    {
        return match ($command->verb) {
            'new', 'ask', 'poll', 'create', 'q' => $this->newPoll($widget, $command->args),
            'add', 'a', 'opt', 'option', 'o' => $this->addOption($widget, $command->args),
            'edit', 'rename' => $this->edit($widget, $command),
            'rm', 'del', 'delete', 'remove' => $this->remove($widget, $command->firstArg()),
            'vote', 'v', 'pick' => $this->voteByCommand($widget, $user, $command->firstArg()),
            'unvote', 'retract', 'clearvote' => $this->clearVotesFor($widget, $user),
            'multi' => $this->toggleMulti($widget),
            'close', 'end', 'lock' => $this->setClosed($widget, true),
            'open', 'reopen', 'unlock' => $this->setClosed($widget, false),
            'clear', 'resetvotes' => $this->clearAllVotes($widget),
            'reset' => $this->reset($widget),
            'show', 'results', 'result', 'list', 'ls' => WidgetOutcome::show(),
            'help', 'h' => WidgetOutcome::reply($this->help()),
            default => WidgetOutcome::reply("Unknown poll command `p!{$command->verb}`. Try `p!help`."),
        };
    }

    public function action(Widget $widget, User $user, string $action, array $payload): WidgetOutcome
    {
        $id = (int) ($payload['id'] ?? 0);

        return match ($action) {
            'vote' => $this->toggleVote($widget, $user, $id),
            'add' => $this->addOption($widget, (string) ($payload['text'] ?? '')),
            'edit' => $this->editOption($widget, $id, (string) ($payload['text'] ?? '')),
            'remove' => $this->removeOption($widget, $id),
            'multi' => $this->toggleMulti($widget),
            'close' => $this->setClosed($widget, true),
            'open' => $this->setClosed($widget, false),
            'clear' => $this->clearAllVotes($widget),
            default => WidgetOutcome::updated(),
        };
    }

    /**
     * Start a fresh poll, resetting question, options and votes. Options can ride along
     * pipe-separated: `p!new Lunch? | Pizza | Sushi | Tacos`.
     */
    private function newPoll(Widget $widget, string $args): WidgetOutcome
    {
        $parts = array_map('trim', explode('|', $args));
        $question = mb_substr((string) array_shift($parts), 0, self::MAX_QUESTION);
        if ($question === '') {
            return WidgetOutcome::reply('What\'s the question? `p!new <question> | option | option`.');
        }

        $state = $this->initialState();
        $state['question'] = $question;
        foreach ($parts as $text) {
            if ($text !== '' && count($state['options']) < self::MAX_OPTIONS) {
                $state['seq']++;
                $state['options'][] = ['id' => $state['seq'], 'text' => mb_substr($text, 0, self::MAX_OPTION), 'voters' => []];
            }
        }
        $widget->state = $state;

        return WidgetOutcome::card();
    }

    private function addOption(Widget $widget, string $text): WidgetOutcome
    {
        $text = trim($text);
        if ($text === '') {
            return WidgetOutcome::reply('What\'s the option? `p!add <text>`.');
        }

        $state = $widget->state;
        if (count($state['options']) >= self::MAX_OPTIONS) {
            return WidgetOutcome::reply('This poll already has the maximum of '.self::MAX_OPTIONS.' options.');
        }

        $state['seq']++;
        $state['options'][] = ['id' => $state['seq'], 'text' => mb_substr($text, 0, self::MAX_OPTION), 'voters' => []];
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function edit(Widget $widget, ParsedCommand $command): WidgetOutcome
    {
        $id = (int) $command->firstArg();
        $text = trim($command->restAfterFirst());
        if ($text === '') {
            return WidgetOutcome::reply('New text? `p!edit <n> <text>`.');
        }

        return $this->editOption($widget, $id, $text, viaCommand: true);
    }

    private function editOption(Widget $widget, int $id, string $text, bool $viaCommand = false): WidgetOutcome
    {
        $text = trim($text);
        $state = $widget->state;
        $index = $this->indexOf($state, $id);
        if ($index === null || $text === '') {
            return $viaCommand ? WidgetOutcome::reply("There's no option #{$id}.") : WidgetOutcome::updated();
        }
        $state['options'][$index]['text'] = mb_substr($text, 0, self::MAX_OPTION);
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function remove(Widget $widget, string $ref): WidgetOutcome
    {
        $id = (int) $ref;
        if ($this->indexOf($widget->state, $id) === null) {
            return WidgetOutcome::reply("There's no option #{$id}.");
        }

        return $this->removeOption($widget, $id);
    }

    private function removeOption(Widget $widget, int $id): WidgetOutcome
    {
        $state = $widget->state;
        $index = $this->indexOf($state, $id);
        if ($index === null) {
            return WidgetOutcome::updated();
        }
        array_splice($state['options'], $index, 1);
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function voteByCommand(Widget $widget, User $user, string $ref): WidgetOutcome
    {
        $id = (int) $ref;
        if ($this->indexOf($widget->state, $id) === null) {
            return WidgetOutcome::reply("There's no option #{$id}. Try `p!show` to see the poll.");
        }
        if ($widget->state['closed']) {
            return WidgetOutcome::reply('This poll is closed.');
        }

        return $this->toggleVote($widget, $user, $id);
    }

    /** Toggle this voter's pick on option #id, honouring single- vs multi-choice. */
    private function toggleVote(Widget $widget, User $user, int $id): WidgetOutcome
    {
        $state = $widget->state;
        if ($state['closed']) {
            return WidgetOutcome::updated();
        }
        $index = $this->indexOf($state, $id);
        if ($index === null) {
            return WidgetOutcome::updated();
        }

        $already = $this->hasVoted($state['options'][$index], $user->id);

        // Single-choice: clear this voter everywhere first, so a new pick replaces the old.
        // (Also runs when toggling off, which is a harmless no-op on the other options.)
        if (! $state['multi'] || $already) {
            foreach ($state['options'] as $i => $option) {
                $state['options'][$i]['voters'] = $this->withoutVoter($option['voters'], $user->id);
            }
        }

        if (! $already) {
            $state['options'][$index]['voters'][] = ['id' => $user->id, 'name' => $user->name];
        }
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function clearVotesFor(Widget $widget, User $user): WidgetOutcome
    {
        $state = $widget->state;
        foreach ($state['options'] as $i => $option) {
            $state['options'][$i]['voters'] = $this->withoutVoter($option['voters'], $user->id);
        }
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function toggleMulti(Widget $widget): WidgetOutcome
    {
        $state = $widget->state;
        $state['multi'] = ! $state['multi'];
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function setClosed(Widget $widget, bool $closed): WidgetOutcome
    {
        $state = $widget->state;
        if ($state['closed'] === $closed) {
            return WidgetOutcome::updated();
        }
        $state['closed'] = $closed;
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    /** Wipe every vote but keep the question and options — a clean re-run. */
    private function clearAllVotes(Widget $widget): WidgetOutcome
    {
        $state = $widget->state;
        foreach ($state['options'] as $i => $option) {
            $state['options'][$i]['voters'] = [];
        }
        $state['closed'] = false;
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function reset(Widget $widget): WidgetOutcome
    {
        $widget->state = $this->initialState();

        return WidgetOutcome::updated();
    }

    private function hasVoted(array $option, int $userId): bool
    {
        foreach ($option['voters'] as $voter) {
            if ((int) $voter['id'] === $userId) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{id:int,name:string}> the voters minus the given user */
    private function withoutVoter(array $voters, int $userId): array
    {
        return array_values(array_filter($voters, fn ($v) => (int) $v['id'] !== $userId));
    }

    /** @return int|null index into the options array, by stable option number */
    private function indexOf(array $state, int $id): ?int
    {
        foreach ($state['options'] as $i => $option) {
            if ((int) $option['id'] === $id) {
                return $i;
            }
        }

        return null;
    }

    private function help(): string
    {
        return implode("\n", [
            '📊 **Poll commands**',
            '`p!new <question> | opt | opt` — start a poll (options optional)',
            '`p!add <text>` — add an option · `p!edit <n> <text>` · `p!rm <n>`',
            '`p!vote <n>` — cast/toggle your vote · `p!unvote` — take it back',
            '`p!multi` — allow picking several · `p!close` / `p!open` — lock/unlock',
            '`p!clear` — wipe votes · `p!show` — bring the poll back to the bottom',
        ]);
    }
}
