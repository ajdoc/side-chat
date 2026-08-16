<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The kanban board, promoted out of a widget's JSON blob and into tables.
 *
 * ## Why
 *
 * A kanban card used to be an entry in `widgets.state` with an id from a counter. That was
 * enough while a board was three fixed columns of one-line cards, and it stopped being enough
 * the moment the board needed two things a blob is bad at:
 *
 *   - **Columns you can add, rename and remove.** Fine in a blob on its own — but a column is
 *     now a thing cards point at, and a rename that has to rewrite every card in the same
 *     document is exactly the edit two people lose each other's work in.
 *   - **Comments, tags and reactions on a card.** Those tables are polymorphic and key on a
 *     row id. There is nothing in a blob for `commentable_id` to point at. This is the same
 *     promotion the Poll widget already made (see PollWidget) and for the same reason.
 *
 * The widget row stays: it is what puts a card in the timeline and on the Open Canvas. Its
 * state becomes a pointer, `{"board_id": 7}`, exactly as the poll's did.
 *
 * ## One board per channel
 *
 * `channel_id` is unique. A channel has one kanban widget (`Widget::firstOrCreate` on
 * channel+type), one Kanban desk tab and one kanban app channel, and all three were already
 * the same board — that's the whole premise of the widget family in `useDeskApps`. Making the
 * table agree with it means a second `k!` card in the timeline can't mint a second board.
 *
 * ## Columns stay JSON, cards do not
 *
 * `kanban_boards.columns` is a small ordered list of `{key, label}` owned entirely by the
 * board. It is read and written as a whole, never referenced by anything but its own cards'
 * `column` string, and a table for it would be a join to fetch three rows nobody queries
 * independently. Cards are the opposite: they are addressed one at a time, by other tables.
 */
return new class extends Migration
{
    /** What a board starts with, and what every pre-existing board is backfilled to. */
    private const DEFAULT_COLUMNS = [
        ['key' => 'todo', 'label' => 'To Do'],
        ['key' => 'doing', 'label' => 'Doing'],
        ['key' => 'done', 'label' => 'Done'],
    ];

    public function up(): void
    {
        Schema::create('kanban_boards', function (Blueprint $table) {
            $table->id();
            // One per channel — see the class comment. Unique rather than merely indexed so
            // the invariant is the database's rather than every caller's.
            $table->foreignId('channel_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('columns');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('kanban_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('kanban_boards')->cascadeOnDelete();
            /*
             * Denormalised from the board.
             *
             * `HasAppActivity` needs a `channel_id` on the model it's used on — comments are
             * scoped to a channel for authorisation and tags for vocabulary — and every read
             * of a card in those paths would otherwise join back through the board to learn
             * one number that can never change. A board doesn't move between channels.
             */
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            // The `key` of one of the board's columns. Not a foreign key, because the columns
            // aren't rows; the controller is what keeps it pointing at a live one.
            $table->string('column', 40);
            $table->unsignedInteger('position')->default(0);
            $table->text('text');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            // Who added it. The id for linking, the name for rendering a card whose author has
            // since left — the same pair the blob carried, minus the blob.
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('added_by_name')->nullable();
            $table->timestamps();

            $table->index(['board_id', 'column', 'position']);
        });

        $this->backfill();
    }

    /**
     * Move every existing board out of its widget state and leave the widget pointing at it.
     *
     * Runs here rather than in a separate migration because the two halves are one change:
     * between them the client would read a widget whose state has cards nobody can comment on
     * *and* a board table that doesn't have them.
     */
    private function backfill(): void
    {
        $widgets = DB::table('widgets')->where('type', 'kanban')->get(['id', 'channel_id', 'user_id', 'state']);

        foreach ($widgets as $widget) {
            $state = json_decode((string) $widget->state, true) ?: [];
            $now = now();

            $boardId = DB::table('kanban_boards')->insertGetId([
                'channel_id' => $widget->channel_id,
                'columns' => json_encode(self::DEFAULT_COLUMNS),
                'created_by' => $widget->user_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $position = [];

            foreach ($state['cards'] ?? [] as $card) {
                $column = in_array($card['column'] ?? '', ['todo', 'doing', 'done'], true) ? $card['column'] : 'todo';
                $text = trim((string) ($card['text'] ?? ''));

                if ($text === '') {
                    continue;
                }

                DB::table('kanban_cards')->insert([
                    'board_id' => $boardId,
                    'channel_id' => $widget->channel_id,
                    'column' => $column,
                    // Cards in a blob were ordered by their place in the array, which is the
                    // order they render in. Keeping it is the difference between a migration
                    // nobody notices and one that shuffles everybody's board.
                    'position' => $position[$column] = ($position[$column] ?? -1) + 1,
                    'text' => mb_substr($text, 0, 280),
                    'assignee_id' => $card['assignee']['id'] ?? null,
                    'added_by' => null,
                    'added_by_name' => $card['addedBy'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('widgets')->where('id', $widget->id)->update([
                'state' => json_encode(['board_id' => $boardId]),
            ]);
        }
    }

    public function down(): void
    {
        // The cards go with the tables, so the pointers must go too — a widget left pointing at
        // a board that no longer exists renders as a permanently empty board with no way back.
        DB::table('widgets')->where('type', 'kanban')->update(['state' => json_encode(['seq' => 0, 'cards' => []])]);

        Schema::dropIfExists('kanban_cards');
        Schema::dropIfExists('kanban_boards');
    }
};
