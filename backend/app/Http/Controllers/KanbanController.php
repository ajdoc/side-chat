<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tracker\TrackerRequest;
use App\Http\Resources\KanbanBoardResource;
use App\Http\Resources\KanbanCardResource;
use App\Models\Channel;
use App\Models\KanbanBoard;
use App\Models\KanbanCard;
use App\Support\Apps\KanbanBoards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The kanban board's own storage — columns and cards.
 *
 * New with the promotion out of the widget's JSON state. The `k!` commands still work and still
 * go through {@see \App\Services\Widgets\KanbanWidget}; that handler now reads and writes these
 * same rows, so a card added from chat and one added from the board are the same card. The two
 * paths share {@see KanbanBoards} for board creation and broadcasting so they can't disagree
 * about either.
 *
 * Membership is the whole permission story, as with every other Side Desk app: whoever can see
 * the channel can move its cards. Deleting a *column* is the one thing that could destroy other
 * people's work in bulk, and it doesn't — its cards move to the neighbouring column rather than
 * going with it.
 */
class KanbanController extends Controller
{
    /** The board, created on first read. Every view of a kanban starts here. */
    public function show(TrackerRequest $request, Channel $channel): JsonResponse
    {
        $board = KanbanBoards::for($channel, $request->user());

        return $this->board($board);
    }

    // --- columns ---------------------------------------------------------------------------

    public function storeColumn(TrackerRequest $request, Channel $channel): JsonResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:40']]);

        $board = KanbanBoards::for($channel, $request->user());

        abort_if(count($board->columns ?? []) >= KanbanBoard::MAX_COLUMNS, 422, 'This board already has the maximum number of columns.');

        $board->columns = [...$board->columns, [
            'key' => $board->mintColumnKey($data['label']),
            'label' => $data['label'],
        ]];
        $board->save();

        return $this->savedBoard($board);
    }

    /**
     * Rename a column, or move it left or right.
     *
     * The key never changes — it's what every card in the column stores and what `k!move`
     * matches. Renaming is a label edit and nothing more, which is exactly why the key was
     * minted separately in the first place.
     */
    public function updateColumn(TrackerRequest $request, Channel $channel, string $key): JsonResponse
    {
        $board = KanbanBoards::for($channel, $request->user());
        abort_unless($board->hasColumn($key), 404);

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:40'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:'.(count($board->columns) - 1)],
        ]);

        $columns = $board->columns;
        $index = array_search($key, array_column($columns, 'key'), true);

        if (isset($data['label'])) {
            $columns[$index]['label'] = $data['label'];
        }

        if (isset($data['position'])) {
            [$moved] = array_splice($columns, $index, 1);
            array_splice($columns, $data['position'], 0, [$moved]);
        }

        $board->columns = array_values($columns);
        $board->save();

        return $this->savedBoard($board);
    }

    /**
     * Remove a column. Its cards are rehomed, not deleted.
     *
     * Deleting somebody's twenty cards because you tidied a column is the one destructive thing
     * this app could do by accident, and the undo for it doesn't exist. They move to the
     * neighbour on the left (or the right, for the first column) — which is where you'd drag
     * them by hand anyway.
     *
     * The last column can't go: a board with no columns has nowhere to put a card, and the next
     * `k!add` would have to invent one.
     */
    public function destroyColumn(TrackerRequest $request, Channel $channel, string $key): JsonResponse
    {
        $board = KanbanBoards::for($channel, $request->user());
        abort_unless($board->hasColumn($key), 404);
        abort_if(count($board->columns) <= 1, 422, 'A board needs at least one column.');

        $columns = $board->columns;
        $index = array_search($key, array_column($columns, 'key'), true);
        $fallback = $columns[$index - 1]['key'] ?? $columns[$index + 1]['key'];

        array_splice($columns, $index, 1);
        $board->columns = array_values($columns);
        $board->save();

        $board->cards()->where('column', $key)->update([
            'column' => $fallback,
            // Appended to the end of the fallback column, in whatever order they were in. Their
            // old positions are meaningless there — position is only ever compared within a
            // column — and reusing them would interleave two columns' worth of cards at random.
            'position' => KanbanBoards::nextPosition($board, $fallback),
        ]);

        return $this->savedBoard($board);
    }

    // --- cards -----------------------------------------------------------------------------

    public function storeCard(TrackerRequest $request, Channel $channel): KanbanCardResource
    {
        $board = KanbanBoards::for($channel, $request->user());

        $data = $request->validate([
            'text' => ['required', 'string', 'max:'.KanbanCard::MAX_TEXT],
            'column' => ['sometimes', 'string', Rule::in($board->columnKeys())],
        ]);

        $user = $request->user();
        $column = $data['column'] ?? $board->firstColumn();

        $card = $board->cards()->create([
            'channel_id' => $channel->getKey(),
            'column' => $column,
            'position' => KanbanBoards::nextPosition($board, $column),
            'text' => $data['text'],
            'added_by' => $user->id,
            'added_by_name' => $user->name,
        ]);

        $card->recordActivity('created', $user, ['column' => $column]);
        KanbanBoards::cardSaved($card);

        return new KanbanCardResource($card->load(['assignee', 'author', 'tags'])->loadCount(['comments', 'reactions']));
    }

    /**
     * Edit a card: its text, its column, its place in that column, its assignee.
     *
     * One endpoint for all four because a drag is two of them at once (column and position) and
     * splitting them would make the commonest gesture on the board two requests that can land
     * out of order.
     */
    public function updateCard(TrackerRequest $request, Channel $channel, KanbanCard $card): KanbanCardResource
    {
        abort_unless($card->channel_id === $channel->id, 404);

        $board = $card->board;

        $data = $request->validate([
            'text' => ['sometimes', 'string', 'max:'.KanbanCard::MAX_TEXT],
            'column' => ['sometimes', 'string', Rule::in($board->columnKeys())],
            'position' => ['sometimes', 'integer', 'min:0'],
            // Explicit null clears it. `sometimes` is what separates "unassign" from "don't
            // touch the assignee", which a plain nullable rule can't tell apart.
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $user = $request->user();
        $from = $card->column;

        if (array_key_exists('assignee_id', $data) && $data['assignee_id'] !== null) {
            // Only somebody who can already see this channel may be handed a card in it.
            $member = $channel->container()?->members()->whereKey($data['assignee_id'])->first();
            abort_if($member === null, 422, 'That person is not a member here.');
        }

        $card->fill(array_intersect_key($data, array_flip(['text', 'column', 'assignee_id'])));

        if (isset($data['column']) || isset($data['position'])) {
            $card->position = $this->slotFor($card, $data['position'] ?? null);
        }

        $card->save();

        if (isset($data['column']) && $data['column'] !== $from) {
            $card->recordActivity('moved', $user, ['from' => $from, 'to' => $data['column']]);
        }

        if (array_key_exists('assignee_id', $data)) {
            $card->recordActivity('assigned', $user, ['user_id' => $data['assignee_id']]);
        }

        KanbanBoards::cardSaved($card);

        return new KanbanCardResource($card->load(['assignee', 'author', 'tags'])->loadCount(['comments', 'reactions']));
    }

    public function destroyCard(TrackerRequest $request, Channel $channel, KanbanCard $card): Response
    {
        abort_unless($card->channel_id === $channel->id, 404);

        // Its comments, tags, reactions and history go with it — the model event in
        // HasAppActivity, since there's no foreign key for the database to cascade along.
        $card->delete();
        KanbanBoards::cardRemoved($card);

        return response()->noContent();
    }

    /** Empty a column in one gesture — what `k!clear done` does, from the UI. */
    public function clearColumn(TrackerRequest $request, Channel $channel, string $key): JsonResponse
    {
        $board = KanbanBoards::for($channel, $request->user());
        abort_unless($board->hasColumn($key), 404);

        $cards = $board->cards()->where('column', $key)->get();

        // Loaded and deleted one at a time rather than in a bulk delete: each one has to fire
        // its own deleting event to take its comments and reactions with it.
        foreach ($cards as $card) {
            $card->delete();
        }

        return $this->savedBoard($board);
    }

    /**
     * Where a card lands when it's dropped.
     *
     * Everything at or after the target slot in the destination column shifts down by one, so
     * the dropped card takes the gap rather than tying with whatever was already there. Without
     * the shift two cards share a position and their order becomes whatever the id tiebreak
     * says, which is not where you dropped it.
     */
    private function slotFor(KanbanCard $card, ?int $position): int
    {
        if ($position === null) {
            return KanbanBoards::nextPosition($card->board, $card->column);
        }

        $card->board->cards()
            ->where('column', $card->column)
            ->whereKeyNot($card->getKey())
            ->where('position', '>=', $position)
            ->increment('position');

        return $position;
    }

    private function savedBoard(KanbanBoard $board): JsonResponse
    {
        KanbanBoards::boardSaved($board);

        return $this->board($board->refresh());
    }

    /**
     * The board, always as a 200.
     *
     * Explicit, because a JsonResource wrapping a model that was created during *this* request
     * answers 201 — and the board is created on first read, so a plain GET would be a 201 once
     * per channel and a 200 forever after. A status that depends on whether you're the first
     * person ever to open the board is a status no client can branch on.
     */
    private function board(KanbanBoard $board): JsonResponse
    {
        return (new KanbanBoardResource(KanbanBoards::loaded($board)))->response()->setStatusCode(200);
    }
}
