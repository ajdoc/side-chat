<?php

namespace App\Support\Apps;

use App\Models\AppPoll;
use App\Models\Channel;
use App\Models\KanbanCard;
use App\Models\User;
use App\Services\Widgets\WidgetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Copying one channel's app content into another's.
 *
 * ## The problem it solves
 *
 * An app channel starts empty, and the thing people already have is somewhere else — a kanban
 * board that grew in a text channel's Side Desk, a calendar in the channel it was planned in.
 * Before this, the only way across was to retype it. Every app storing its content *per
 * surface* is what makes the copy well-defined at all: there is a clean set of rows to take.
 *
 * ## Copy, never move
 *
 * The source keeps everything. An import is additive at the destination too — it appends to
 * what's there rather than replacing it, so importing twice duplicates rather than destroys,
 * and nobody loses work to a mis-click on a channel picker. The two exceptions are the apps
 * whose storage is *one* document per surface, called out on their importers below.
 *
 * ## What doesn't come with it
 *
 * Comments, reactions, tags and history stay behind. They are a conversation that happened in
 * the source channel, by people who may not be members here, and re-attributing it to a copy in
 * a room they can't see is not a thing an import should do quietly. The item comes; the
 * discussion about it doesn't.
 *
 * Assignees and authors are kept only when the person is a member of the destination too —
 * otherwise the card arrives unassigned with its author name preserved as text. A card assigned
 * to somebody who can't see the channel is a task nobody owns.
 */
final class AppImports
{
    /**
     * Which apps can be imported, and how.
     *
     * Not every app in the registry is here: `canvas` cards that point at a widget, and the
     * games, have nothing surface-scoped worth copying. An app absent from this map is reported
     * as un-importable rather than silently doing nothing.
     *
     * @var array<string, array{count: callable(Channel): int, copy: callable(Channel, Channel, User): int}>
     */
    private static function importers(): array
    {
        return [
            'kanban' => ['count' => [self::class, 'countKanban'], 'copy' => [self::class, 'copyKanban']],
            'tracker' => ['count' => [self::class, 'countTracker'], 'copy' => [self::class, 'copyTracker']],
            'calendar' => ['count' => [self::class, 'countCalendar'], 'copy' => [self::class, 'copyCalendar']],
            'polls' => ['count' => [self::class, 'countPolls'], 'copy' => [self::class, 'copyPolls']],
            'stickers' => ['count' => [self::class, 'countStickers'], 'copy' => [self::class, 'copyStickers']],
            'board' => ['count' => [self::class, 'countBoard'], 'copy' => [self::class, 'copyBoard']],
            'canvas' => ['count' => [self::class, 'countCanvas'], 'copy' => [self::class, 'copyCanvas']],
            'notes' => ['count' => [self::class, 'countNotes'], 'copy' => [self::class, 'copyNotes']],
            'docs' => ['count' => [self::class, 'countDocs'], 'copy' => [self::class, 'copyDocs']],
        ];
    }

    /** @return array<int, string> the app ids an import is defined for */
    public static function supported(): array
    {
        return array_keys(self::importers());
    }

    public static function supports(string $app): bool
    {
        return array_key_exists($app, self::importers());
    }

    /**
     * How much of this app a channel holds.
     *
     * Feeds the picker, which lists candidate sources with a count — "Design · 24 cards". A
     * channel showing 0 is still offered rather than hidden: an empty board you were sure had
     * something in it is an answer, and a silently missing row is not.
     */
    public static function count(Channel $channel, string $app): int
    {
        $importer = self::importers()[$app] ?? null;

        return $importer === null ? 0 : ($importer['count'])($channel);
    }

    /**
     * Copy `$app`'s content from one channel to another. Returns how many items arrived.
     *
     * In a transaction: a half-imported board is worse than a failed import, because nobody can
     * tell by looking which half made it.
     */
    public static function run(string $app, Channel $from, Channel $to, User $actor): int
    {
        $importer = self::importers()[$app] ?? null;

        if ($importer === null) {
            return 0;
        }

        return DB::transaction(fn () => ($importer['copy'])($from, $to, $actor));
    }

    // --- kanban ------------------------------------------------------------------------------

    private static function countKanban(Channel $channel): int
    {
        return KanbanCard::where('channel_id', $channel->getKey())->count();
    }

    /**
     * The columns come too, which is the point.
     *
     * A board's columns are half of what it *is*, and cards landing in a destination that has no
     * "In Review" would either pile into the wrong column or need renaming by hand. Columns are
     * matched by label rather than key — two boards that both call it "In Review" merge, and one
     * that doesn't have it gains it — because the label is what a person means by "the same
     * column" and the key is an internal name they've never seen.
     */
    private static function copyKanban(Channel $from, Channel $to, User $actor): int
    {
        $source = KanbanBoards::for($from);
        $target = KanbanBoards::for($to, $actor);

        $byLabel = [];
        foreach ($target->columns as $column) {
            $byLabel[mb_strtolower($column['label'])] = $column['key'];
        }

        $columns = $target->columns;
        $mapped = [];

        foreach ($source->columns as $column) {
            $label = mb_strtolower($column['label']);

            if (isset($byLabel[$label])) {
                $mapped[$column['key']] = $byLabel[$label];

                continue;
            }

            $key = $target->mintColumnKey($column['label']);
            $columns[] = ['key' => $key, 'label' => $column['label']];
            $target->columns = $columns;
            $mapped[$column['key']] = $key;
        }

        $target->columns = $columns;
        $target->save();

        $members = self::memberIds($to);
        $count = 0;
        $next = [];

        foreach ($source->cards as $card) {
            $column = $mapped[$card->column] ?? $target->firstColumn();
            $next[$column] ??= KanbanBoards::nextPosition($target, $column);

            $target->cards()->create([
                'channel_id' => $to->getKey(),
                'column' => $column,
                'position' => $next[$column]++,
                'text' => $card->text,
                'assignee_id' => in_array($card->assignee_id, $members, true) ? $card->assignee_id : null,
                'added_by' => in_array($card->added_by, $members, true) ? $card->added_by : null,
                'added_by_name' => $card->added_by_name,
            ]);
            $count++;
        }

        KanbanBoards::boardSaved($target);

        return $count;
    }

    // --- tracker -----------------------------------------------------------------------------

    private static function countTracker(Channel $channel): int
    {
        return $channel->trackerProjects()->withCount('tasks')->get()->sum('tasks_count');
    }

    /**
     * Projects and their tasks.
     *
     * Task *keys* are not preserved. A key is `PROJECT-N` from the project's own counter, and
     * the counter here has already handed out numbers; reusing the source's would mint
     * duplicates in a namespace whose whole promise is that a number means one task forever. The
     * imported tasks get fresh numbers, and the project key is suffixed if this channel already
     * has one by that name.
     */
    private static function copyTracker(Channel $from, Channel $to, User $actor): int
    {
        $members = self::memberIds($to);
        $taken = $to->trackerProjects()->pluck('key')->map(fn ($k) => mb_strtoupper($k))->all();
        $position = (int) $to->trackerProjects()->max('position');
        $count = 0;

        foreach ($from->trackerProjects()->with('tasks')->get() as $project) {
            $key = mb_strtoupper($project->key);

            for ($n = 2; in_array($key, $taken, true); $n++) {
                $key = mb_substr(mb_strtoupper($project->key), 0, 8).$n;
            }
            $taken[] = $key;

            $copy = $to->trackerProjects()->create([
                'key' => $key,
                'name' => $project->name,
                'description' => $project->description,
                'position' => ++$position,
                'archived' => $project->archived,
                'created_by' => $actor->getKey(),
            ]);

            foreach ($project->tasks as $i => $task) {
                $copy->tasks()->create([
                    'number' => $i + 1,
                    'title' => $task->title,
                    'description' => $task->description,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'assignee_id' => in_array($task->assignee_id, $members, true) ? $task->assignee_id : null,
                    'created_by' => $actor->getKey(),
                    'due_date' => $task->due_date,
                    'position' => $task->position,
                    'completed_at' => $task->completed_at,
                ]);
                $count++;
            }

            // The counter has to clear every number just handed out, or the next task created
            // here collides with an imported one.
            $copy->update(['next_number' => $project->tasks->count() + 1]);
        }

        return $count;
    }

    // --- the rest ----------------------------------------------------------------------------

    private static function countCalendar(Channel $channel): int
    {
        return $channel->calendarEvents()->count();
    }

    private static function copyCalendar(Channel $from, Channel $to, User $actor): int
    {
        $count = 0;

        foreach ($from->calendarEvents as $event) {
            $to->calendarEvents()->create([
                'user_id' => $actor->getKey(),
                'title' => $event->title,
                'description' => $event->description,
                'starts_at' => $event->starts_at,
                'ends_at' => $event->ends_at,
                'all_day' => $event->all_day,
                'color' => $event->color,
            ]);
            $count++;
        }

        return $count;
    }

    private static function countPolls(Channel $channel): int
    {
        return $channel->polls()->count();
    }

    /**
     * Polls, with their options — and never their votes.
     *
     * A vote is a person's answer in the room they answered in. Copying it would put words in
     * the mouths of people who may not even be here, and on an anonymous poll it would move a
     * record whose entire point was that it stays where it was cast. The copies arrive open with
     * zero votes, which is what an imported question is: something still to be answered.
     */
    private static function copyPolls(Channel $from, Channel $to, User $actor): int
    {
        $count = 0;

        foreach ($from->polls()->with('options')->get() as $poll) {
            /** @var AppPoll $copy */
            $copy = $to->polls()->create([
                'type' => $poll->type,
                'question' => $poll->question,
                'description' => $poll->description,
                'anonymous' => $poll->anonymous,
                'created_by' => $actor->getKey(),
            ]);

            foreach ($poll->options as $option) {
                $copy->options()->create(['label' => $option->label, 'position' => $option->position]);
            }
            $count++;
        }

        return $count;
    }

    private static function countStickers(Channel $channel): int
    {
        return $channel->stickers()->count();
    }

    private static function copyStickers(Channel $from, Channel $to, User $actor): int
    {
        $count = 0;

        foreach ($from->stickers as $sticker) {
            $to->stickers()->create([
                // The sticker wall's ownership rule is yours-or-staff, so an imported sticker
                // belongs to whoever imported it — otherwise nobody present could move it.
                'user_id' => $actor->getKey(),
                'name' => $sticker->name,
                'content' => $sticker->content,
                'x' => $sticker->x, 'y' => $sticker->y, 'z' => $sticker->z,
                'w' => $sticker->w, 'h' => $sticker->h, 'rotation' => $sticker->rotation,
            ]);
            $count++;
        }

        return $count;
    }

    private static function countBoard(Channel $channel): int
    {
        return $channel->whiteboardStrokes()->count();
    }

    /**
     * Whiteboard strokes, and the layer names they sit on.
     *
     * Strokes carry a layer *index*, and the names live on the channel — so importing the
     * strokes without the names would put half the drawing on "Layer 3". The destination's own
     * names win where it has one, since they describe drawings already on it.
     */
    private static function copyBoard(Channel $from, Channel $to, User $actor): int
    {
        $layers = $to->board_layers ?? [];

        foreach ($from->board_layers ?? [] as $i => $layer) {
            $layers[$i] ??= $layer;
        }

        if ($layers !== ($to->board_layers ?? [])) {
            $to->update(['board_layers' => $layers]);
        }

        $count = 0;

        foreach ($from->whiteboardStrokes as $stroke) {
            $to->whiteboardStrokes()->create([
                'user_id' => $actor->getKey(),
                'kind' => $stroke->kind,
                'layer' => $stroke->layer,
                'payload' => $stroke->payload,
                // `client_id` dedupes a stroke against the tab that drew it. A copy was not
                // drawn by anyone's tab, so it gets none rather than a borrowed one.
                'client_id' => null,
            ]);
            $count++;
        }

        return $count;
    }

    private static function countCanvas(Channel $channel): int
    {
        return $channel->canvasItems()->count();
    }

    /**
     * Canvas cards, including the ones that place a widget.
     *
     * A widget card points at a `widget_id`, and that widget belongs to the source channel — a
     * copied row would put another channel's music player on this canvas. So a widget card is
     * re-pointed at *this* channel's widget of the same type, minting it if there isn't one,
     * which is exactly what dropping the card here by hand would have done.
     */
    private static function copyCanvas(Channel $from, Channel $to, User $actor): int
    {
        $widgets = app(WidgetService::class);
        $count = 0;

        foreach ($from->canvasItems()->with('widget')->get() as $item) {
            $widgetId = null;

            if ($item->widget !== null) {
                $widgetId = $widgets->ensure($to, $actor, $item->widget->type)?->getKey();

                // A card for a widget type this server no longer has is a card that would
                // render blank. Skipped rather than imported empty.
                if ($widgetId === null) {
                    continue;
                }
            }

            $to->canvasItems()->create([
                'user_id' => $actor->getKey(),
                'widget_id' => $widgetId,
                'kind' => $item->kind,
                'content' => $item->content,
                'x' => $item->x, 'y' => $item->y, 'w' => $item->w, 'h' => $item->h, 'z' => $item->z,
            ]);
            $count++;
        }

        return $count;
    }

    private static function countNotes(Channel $channel): int
    {
        return trim((string) $channel->spaceNote?->content) === '' ? 0 : 1;
    }

    /**
     * The shared note. One of the two apps whose storage is a single document.
     *
     * Appended under a rule rather than replacing what's here — a note is prose somebody is in
     * the middle of, and an import that blanked it would be the one destructive path in this
     * whole file. Appending is reviewable; a replacement is not.
     */
    private static function copyNotes(Channel $from, Channel $to, User $actor): int
    {
        $source = trim((string) $from->spaceNote?->content);

        if ($source === '') {
            return 0;
        }

        $note = $to->spaceNote()->firstOrCreate([], ['content' => '']);
        $existing = trim((string) $note->content);

        $note->update([
            'content' => $existing === '' ? $source : $existing."\n\n---\n\n".$source,
            'updated_by' => $actor->getKey(),
            'version' => $note->version + 1,
        ]);

        return 1;
    }

    private static function countDocs(Channel $channel): int
    {
        return $channel->spaceDocuments()->count();
    }

    /**
     * The doc shelf — and the files themselves, copied on the disk.
     *
     * Two rows sharing one stored path would make deleting either file delete both, which is a
     * data-loss bug wearing an import's clothes. A file we can't copy (gone from the disk
     * already) is skipped rather than left as a shelf entry that 404s.
     */
    private static function copyDocs(Channel $from, Channel $to, User $actor): int
    {
        $count = 0;

        foreach ($from->spaceDocuments as $document) {
            $disk = Storage::disk($document->disk);
            $path = dirname($document->path).'/'.uniqid('import-').'-'.basename($document->path);

            if (! $disk->exists($document->path) || ! $disk->copy($document->path, $path)) {
                continue;
            }

            $to->spaceDocuments()->create([
                'user_id' => $actor->getKey(),
                'disk' => $document->disk,
                'path' => $path,
                'name' => $document->name,
                'mime_type' => $document->mime_type,
                'extension' => $document->extension,
                'size' => $document->size,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Who can see the destination — the gate on carrying an assignee across.
     *
     * @return array<int, int>
     */
    private static function memberIds(Channel $channel): array
    {
        return $channel->container()?->members()->pluck('users.id')->all() ?? [];
    }
}
