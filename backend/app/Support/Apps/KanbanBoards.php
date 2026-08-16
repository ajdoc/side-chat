<?php

namespace App\Support\Apps;

use App\Events\TrackerChanged;
use App\Http\Resources\KanbanBoardResource;
use App\Http\Resources\KanbanCardResource;
use App\Models\Channel;
use App\Models\KanbanBoard;
use App\Models\KanbanCard;
use App\Models\User;

/**
 * Getting at a channel's board, and telling everyone it moved.
 *
 * Sits between the two things that write to a board — {@see \App\Http\Controllers\KanbanController}
 * for the UI and {@see \App\Services\Widgets\KanbanWidget} for `k!` commands — so "what does a
 * board look like when it's created" and "what goes out on the wire when a card moves" are each
 * answered once. Before the promotion to tables, the widget handler was the only writer and
 * that question didn't exist.
 */
final class KanbanBoards
{
    /**
     * The channel's board, created on first use.
     *
     * `firstOrCreate` on the unique `channel_id`, which matches how a widget is minted
     * (`Widget::firstOrCreate` on channel+type) — two people opening an empty board at the same
     * moment get one board rather than a duplicate-key error for the loser.
     */
    public static function for(Channel $channel, ?User $creator = null): KanbanBoard
    {
        return KanbanBoard::firstOrCreate(
            ['channel_id' => $channel->getKey()],
            ['columns' => KanbanBoard::DEFAULT_COLUMNS, 'created_by' => $creator?->getKey()],
        );
    }

    /** The board with everything a client needs to draw it. */
    public static function loaded(KanbanBoard $board): KanbanBoard
    {
        return $board->load([
            'cards' => fn ($q) => $q->with(['assignee', 'author', 'tags'])->withCount(['comments', 'reactions']),
        ]);
    }

    /**
     * Where a new card goes in its column: the end of it.
     *
     * Positions are only ever compared within one column, so a gap left by a card that moved
     * away is harmless and never compacted. Reordering rewrites the column it lands in.
     */
    public static function nextPosition(KanbanBoard $board, string $column): int
    {
        return (int) $board->cards()->where('column', $column)->max('position') + 1;
    }

    /** A card has changed (or arrived). */
    public static function cardSaved(KanbanCard $card): void
    {
        self::emit($card->channel_id, 'kanban_card', 'saved', (new KanbanCardResource(
            $card->load(['assignee', 'author', 'tags'])->loadCount(['comments', 'reactions'])
        ))->resolve());
    }

    public static function cardRemoved(KanbanCard $card): void
    {
        self::emit($card->channel_id, 'kanban_card', 'removed', ['id' => $card->id]);
    }

    /**
     * The columns changed.
     *
     * The whole board goes out rather than the one column, because every column edit that isn't
     * a rename moves cards — removing one rehomes its cards, and a client applying "column
     * gone" on its own would draw a board those cards had fallen off. One payload, one state.
     */
    public static function boardSaved(KanbanBoard $board): void
    {
        self::emit($board->channel_id, 'kanban_board', 'saved', (new KanbanBoardResource(self::loaded($board)))->resolve());
    }

    /** @param  array<string, mixed>  $payload */
    private static function emit(int $channelId, string $subject, string $action, array $payload): void
    {
        // The channel's own private stream — the same one the tracker, the calendar and the
        // board's strokes ride, which is what keeps a kanban open in a tab and in a floating
        // window in step.
        broadcast(new TrackerChanged('channel.'.$channelId, $subject, $action, $payload))->toOthers();
    }
}
