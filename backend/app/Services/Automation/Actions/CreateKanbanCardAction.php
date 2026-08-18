<?php

namespace App\Services\Automation\Actions;

use App\Models\Channel;
use App\Models\KanbanCard;
use App\Services\Automation\AutomationActionHandler;
use App\Support\Apps\KanbanBoards;
use App\Support\Automation\ActionResult;
use App\Support\Automation\AutomationContext;
use App\Support\Automation\Template;

/**
 * Put a card on a channel's board.
 *
 * The first action that writes to a *productivity app* rather than to chat, and the reason the
 * app triggers were worth adding: "when somebody reacts 📌, put it on the board" is a rule that
 * needs both halves. Until now a rule could only ever talk about work; now it can file it.
 *
 * The text is a template, so a card can carry what happened into itself — `{{ body }}` for the
 * message that caused it, `{{ user }}` for who did it. That's the same rendering every other
 * action's text uses, so nothing new has to be learned to use it.
 */
final class CreateKanbanCardAction implements AutomationActionHandler
{
    public function name(): string
    {
        return 'create_kanban_card';
    }

    public function label(): string
    {
        return 'Add a kanban card';
    }

    public function schema(): array
    {
        return [
            [
                'key' => 'channel_id',
                'type' => 'channel',
                'label' => 'Board',
                'required' => false,
                // Same rule as posting: blank means where it happened. Every channel has a
                // board, so "the board in this channel" is always an answer.
                'help' => 'Leave blank for the board in the channel where the trigger happened.',
            ],
            [
                'key' => 'column',
                'type' => 'text',
                'label' => 'Column',
                'required' => false,
                'help' => 'By name or key — “In Review”. Blank puts it in the first column.',
            ],
            [
                'key' => 'text',
                'type' => 'textarea',
                'label' => 'Card text',
                'required' => true,
                'placeholders' => ['user', 'server', 'channel'],
            ],
        ];
    }

    public function handle(array $config, AutomationContext $context): ActionResult
    {
        $server = $context->server();
        $channelId = (int) ($config['channel_id'] ?? 0) ?: (int) $context->get('channel_id');

        $channel = $server === null || $channelId === 0
            ? null
            : Channel::where('server_id', $server->getKey())->find($channelId);

        if ($channel === null) {
            return ActionResult::skipped('The board this rule adds to no longer exists.');
        }

        $text = trim(Template::render((string) ($config['text'] ?? ''), $context->with([
            'server_name' => $server->name,
        ])));

        if ($text === '') {
            // Every placeholder came back empty. A blank card is a card nobody can act on.
            return ActionResult::skipped('The card text came out empty.');
        }

        $board = KanbanBoards::for($channel, $context->subject());
        $column = $board->resolveColumn((string) ($config['column'] ?? '')) ?? $board->firstColumn();

        if ($column === null) {
            return ActionResult::skipped('That board has no columns.');
        }

        $actor = $context->subject();

        $card = $board->cards()->create([
            'channel_id' => $channel->getKey(),
            'column' => $column,
            'position' => KanbanBoards::nextPosition($board, $column),
            'text' => mb_substr($text, 0, KanbanCard::MAX_TEXT),
            'added_by' => $actor?->getKey(),
            // Named for whoever the trigger was about, not for the bot: the card is about their
            // message or their reaction, and "added by Automation" tells nobody anything.
            'added_by_name' => $actor?->name,
        ]);

        $card->recordActivity('created', $actor, ['column' => $column, 'automation' => true]);
        KanbanBoards::cardSaved($card);

        /*
         * The card's own `kanban.card_created` trigger is deliberately *not* fired here.
         *
         * The engine's depth counter would stop an infinite loop, but the honest reason is
         * simpler: a rule that files cards and a rule that reacts to filed cards are a pair
         * somebody wrote by accident far more often than on purpose. Chat's own loop guard makes
         * the same call by never letting a bot's message trigger a rule.
         */

        return ActionResult::ok("Added card #{$card->getKey()} to {$channel->name}.", ['card_id' => $card->getKey()]);
    }
}
