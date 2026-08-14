<?php

namespace App\Services\Widgets;

use App\Models\AppPoll;
use App\Models\User;
use App\Models\Widget;
use App\Support\Commands\ParsedCommand;

/**
 * A shared poll, driven by `p!` commands and the card's buttons alike.
 *
 * ## What changed, and why
 *
 * This used to hold the whole poll in the widget's JSON state — question, options, and a list
 * of voters per option. Then the Polls app arrived with real tables, and there were two poll
 * systems in one product: a `p!` poll in the timeline and a poll on the channel's wall, which
 * couldn't see each other. Two things called "Poll" that don't share an answer is exactly the
 * confusion worth removing.
 *
 * So the widget is now a **pointer**. Its whole state is `{"poll_id": 12}`, and every command
 * reads and writes the {@see AppPoll} that id names. A `p!new` in the timeline puts a poll on
 * the wall; voting on the wall moves the timeline card; closing it in either place closes it in
 * both. There is one poll.
 *
 * The commands are unchanged — that was the constraint. `p!vote 2` still means "the option
 * minted as #2", except the number is now the option's row id rather than a counter in a blob,
 * which gives the same guarantee (never reused, never shifted by an edit above it) without the
 * bookkeeping.
 *
 * A widget whose poll has been deleted from the wall is a card pointing at nothing; every
 * command answers by saying so rather than resurrecting it, since the deletion was deliberate.
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

    /** No poll yet — the first `p!new` creates one and writes its id here. */
    public function initialState(): array
    {
        return ['poll_id' => null];
    }

    public function command(Widget $widget, User $user, ParsedCommand $command): WidgetOutcome
    {
        // The two verbs that don't need an existing poll.
        if (in_array($command->verb, ['new', 'ask', 'poll', 'create', 'q'], true)) {
            return $this->newPoll($widget, $command->args);
        }

        if (in_array($command->verb, ['help', 'h'], true)) {
            return WidgetOutcome::reply($this->help());
        }

        $poll = $this->poll($widget);

        if ($poll === null) {
            return WidgetOutcome::reply('No poll here yet. Start one with `p!new <question> | option | option`.');
        }

        return match ($command->verb) {
            'add', 'a', 'opt', 'option', 'o' => $this->addOption($poll, $command->args),
            'edit', 'rename' => $this->edit($poll, $command),
            'rm', 'del', 'delete', 'remove' => $this->removeOption($poll, (int) $command->firstArg()),
            'vote', 'v', 'pick' => $this->toggleVote($poll, $user, (int) $command->firstArg()),
            'unvote', 'retract', 'clearvote' => $this->clearVotesFor($poll, $user),
            'multi' => $this->toggleMulti($poll),
            'close', 'end', 'lock' => $this->setClosed($poll, true),
            'open', 'reopen', 'unlock' => $this->setClosed($poll, false),
            'clear', 'resetvotes' => $this->clearAllVotes($poll),
            'reset' => $this->reset($widget),
            'show', 'results', 'result', 'list', 'ls' => WidgetOutcome::show(),
            default => WidgetOutcome::reply("Unknown poll command `p!{$command->verb}`. Try `p!help`."),
        };
    }

    /** The card's own buttons. `vote` is the only one it offers. */
    public function action(Widget $widget, User $user, string $action, array $payload): WidgetOutcome
    {
        $poll = $this->poll($widget);

        if ($poll === null) {
            return WidgetOutcome::noop();
        }

        return match ($action) {
            'vote' => $this->toggleVote($poll, $user, (int) ($payload['id'] ?? 0)),
            'close' => $this->setClosed($poll, true),
            'open' => $this->setClosed($poll, false),
            default => WidgetOutcome::noop(),
        };
    }

    /**
     * The poll this widget points at, or null.
     *
     * Scoped to the widget's own channel as well as its id: a state blob is user-writable in
     * principle, and a widget must not become a way to read or drive a poll in a channel you
     * can't see.
     */
    private function poll(Widget $widget): ?AppPoll
    {
        $id = $widget->state['poll_id'] ?? null;

        return $id === null
            ? null
            : AppPoll::where('channel_id', $widget->channel_id)->with('options')->find($id);
    }

    private function newPoll(Widget $widget, string $args): WidgetOutcome
    {
        $parts = array_map('trim', explode('|', $args));
        $question = mb_substr((string) array_shift($parts), 0, self::MAX_QUESTION);

        if ($question === '') {
            return WidgetOutcome::reply('What\'s the question? `p!new <question> | option | option`.');
        }

        $poll = AppPoll::create([
            'channel_id' => $widget->channel_id,
            // `single` rather than `yes_no` even when no options are given: `p!add` is how a
            // timeline poll usually grows, and a yes/no poll can't take extra options.
            'type' => 'single',
            'question' => $question,
            'created_by' => $widget->user_id,
        ]);

        foreach (array_values(array_filter($parts)) as $i => $text) {
            if ($i >= self::MAX_OPTIONS) {
                break;
            }
            $poll->options()->create(['label' => mb_substr($text, 0, self::MAX_OPTION), 'position' => $i]);
        }

        // Points the widget at the new poll. `p!new` on a card that already had one starts a
        // fresh poll and leaves the old one on the wall — the results of a finished poll are
        // usually the reason it was asked.
        $widget->state = ['poll_id' => $poll->id];

        return WidgetOutcome::card();
    }

    private function addOption(AppPoll $poll, string $text): WidgetOutcome
    {
        $text = trim($text);

        if ($text === '') {
            return WidgetOutcome::reply('What\'s the option? `p!add <text>`.');
        }

        if ($poll->options->count() >= self::MAX_OPTIONS) {
            return WidgetOutcome::reply('This poll already has the maximum of '.self::MAX_OPTIONS.' options.');
        }

        $poll->options()->create([
            'label' => mb_substr($text, 0, self::MAX_OPTION),
            'position' => (int) $poll->options()->max('position') + 1,
        ]);

        return WidgetOutcome::updated();
    }

    /** `p!edit <n> <text>` rewords an option; `p!edit <text>` rewords the question. */
    private function edit(AppPoll $poll, ParsedCommand $command): WidgetOutcome
    {
        $first = $command->firstArg();
        $rest = trim(mb_substr($command->args, mb_strlen($first)));

        if (ctype_digit($first) && $rest !== '') {
            $option = $poll->options->firstWhere('id', (int) $first);

            if ($option === null) {
                return WidgetOutcome::reply("There's no option #{$first}.");
            }

            $option->update(['label' => mb_substr($rest, 0, self::MAX_OPTION)]);

            return WidgetOutcome::updated();
        }

        if (trim($command->args) === '') {
            return WidgetOutcome::reply('Reword the question with `p!edit <question>`, or an option with `p!edit <n> <text>`.');
        }

        $poll->update(['question' => mb_substr(trim($command->args), 0, self::MAX_QUESTION)]);

        return WidgetOutcome::updated();
    }

    private function removeOption(AppPoll $poll, int $id): WidgetOutcome
    {
        $option = $poll->options->firstWhere('id', $id);

        if ($option === null) {
            return WidgetOutcome::reply("There's no option #{$id}.");
        }

        // Its votes go with it on the foreign key — a vote for an option that no longer exists
        // is not an opinion anybody still holds.
        $option->delete();

        return WidgetOutcome::updated();
    }

    /**
     * Cast or withdraw one vote.
     *
     * A toggle, so the card's button and `p!vote 2` behave the same way twice in a row. On a
     * single-answer poll a new pick replaces the old one rather than adding to it.
     */
    private function toggleVote(AppPoll $poll, User $user, int $id): WidgetOutcome
    {
        if (! $poll->isOpen()) {
            return WidgetOutcome::reply('This poll is closed.');
        }

        $option = $poll->options->firstWhere('id', $id);

        if ($option === null) {
            return WidgetOutcome::reply($id === 0
                ? 'Which option? `p!vote <n>`.'
                : "There's no option #{$id}.");
        }

        $existing = $poll->votes()->where('user_id', $user->id)->where('option_id', $id)->first();

        if ($existing !== null) {
            $existing->delete();

            return WidgetOutcome::updated();
        }

        if (! $poll->allowsMultiple()) {
            $poll->votes()->where('user_id', $user->id)->delete();
        }

        $poll->votes()->create(['option_id' => $id, 'user_id' => $user->id]);

        return WidgetOutcome::updated();
    }

    private function clearVotesFor(AppPoll $poll, User $user): WidgetOutcome
    {
        $poll->votes()->where('user_id', $user->id)->delete();

        return WidgetOutcome::updated();
    }

    private function toggleMulti(AppPoll $poll): WidgetOutcome
    {
        $poll->update(['type' => $poll->allowsMultiple() ? 'single' : 'multiple']);

        return WidgetOutcome::updated();
    }

    private function setClosed(AppPoll $poll, bool $closed): WidgetOutcome
    {
        if ($poll->isOpen() !== $closed) {
            return WidgetOutcome::reply($closed ? 'This poll is already closed.' : 'This poll is already open.');
        }

        $poll->update(['closed_at' => $closed ? now() : null]);

        return WidgetOutcome::updated();
    }

    private function clearAllVotes(AppPoll $poll): WidgetOutcome
    {
        $poll->votes()->delete();

        return WidgetOutcome::updated();
    }

    /** Forget the poll entirely and leave the card ready for a new one. */
    private function reset(Widget $widget): WidgetOutcome
    {
        $widget->state = $this->initialState();

        return WidgetOutcome::updated();
    }

    private function help(): string
    {
        return implode("\n", [
            '**Poll**  —  the same polls the Polls app shows, from the timeline.',
            '`p!new <question> | option | option` — start one',
            '`p!add <text>` — add an option    `p!edit <n> <text>` — reword one',
            '`p!rm <n>` — remove an option     `p!edit <question>` — reword the question',
            '`p!vote <n>` — vote or take it back    `p!unvote` — clear your picks',
            '`p!multi` — allow several picks   `p!close` / `p!open`',
            '`p!clear` — clear every vote      `p!reset` — start over',
        ]);
    }
}
