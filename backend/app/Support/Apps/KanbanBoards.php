<?php

namespace App\Support\Apps;

use App\Events\TrackerChanged;
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
     * The board's shape changed — a column added, renamed, moved, emptied or removed, or a pile
     * of cards arriving at once from an import.
     *
     * ## Why the cards are *not* in here
     *
     * They were, and it broke in production. A websocket message has a size ceiling — Reverb's,
     * Pusher's, and any managed broker's — and a board is unbounded: importing eighty-four cards
     * built one payload of all of them and the broker refused it, so the change reached nobody
     * and there was no way to raise the limit from inside the app.
     *
     * So this is a **reference, not a state**: the columns (a dozen short strings, bounded by
     * MAX_COLUMNS) plus the fact that the cards moved. Clients re-read the board over HTTP,
     * where a big response is only a big response. That is exactly what `WidgetUpdated` already
     * does and for the same reason — a broadcast that can outgrow the wire is a broadcast that
     * fails on precisely the busiest board in the server.
     *
     * The columns ride along rather than being fetched too, because they're what the *layout*
     * needs: a client can redraw the columns immediately and fill the cards in when the read
     * lands, instead of blanking the board for a round trip.
     */
    public static function boardSaved(KanbanBoard $board): void
    {
        self::emit($board->channel_id, 'kanban_board', 'saved', [
            'id' => $board->id,
            'channel_id' => $board->channel_id,
            'columns' => array_values($board->columns ?? []),
            // "Re-read the cards." Named rather than implied, so a later payload that *can*
            // carry them wouldn't have to change what this event means.
            'cards_stale' => true,
        ]);
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
