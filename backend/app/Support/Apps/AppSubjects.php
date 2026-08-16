<?php

namespace App\Support\Apps;

use App\Models\AppPoll;
use App\Models\AppSticker;
use App\Models\CalendarEvent;
use App\Models\CanvasItem;
use App\Models\Channel;
use App\Models\KanbanCard;
use App\Models\SpaceDocument;
use App\Models\SpaceNote;
use App\Models\TrackerTask;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolving `{type}/{id}` in a URL to the thing it names — the small piece of plumbing that
 * lets one comments controller and one tags controller serve every app.
 *
 * The type is the short morph-map name ('tracker_task'), so the routes read
 * `channels/12/apps/tracker_task/34/comments` and adding the next commentable app is a line in
 * this map plus a line in the morph map.
 *
 * Every resolver re-checks that the row really belongs to the channel in the URL. That check
 * is the whole authorisation story for these endpoints: the request class has already
 * established that the caller is in *this* channel, so all that's left to refuse is a
 * well-formed id pointing at somebody else's task.
 */
final class AppSubjects
{
    /**
     * How to load each commentable/taggable kind, scoped to its channel.
     *
     * @var array<string, callable(Channel, int): ?Model>
     */
    private const RESOLVERS = [
        'tracker_task' => [self::class, 'trackerTask'],
        'canvas_item' => [self::class, 'canvasItem'],
        'calendar_event' => [self::class, 'calendarEvent'],
        'app_poll' => [self::class, 'poll'],
        'app_sticker' => [self::class, 'sticker'],
        'space_document' => [self::class, 'document'],
        'space_note' => [self::class, 'note'],
        'kanban_card' => [self::class, 'kanbanCard'],
    ];

    public static function types(): array
    {
        return array_keys(self::RESOLVERS);
    }

    /**
     * The model named by a type/id pair, or null if it isn't one or doesn't live here.
     *
     * Callers turn null into a 404 rather than a 403: "this task isn't in this channel" and
     * "there is no such task" should be indistinguishable to somebody probing ids.
     */
    public static function resolve(Channel $channel, string $type, int $id): ?Model
    {
        $resolver = self::RESOLVERS[$type] ?? null;

        return $resolver === null ? null : $resolver($channel, $id);
    }

    private static function trackerTask(Channel $channel, int $id): ?TrackerTask
    {
        return TrackerTask::query()
            ->with('project')
            // Through the project, which is what carries the channel — a task has no
            // `channel_id` of its own.
            ->whereHas('project', fn ($p) => $p->where('channel_id', $channel->getKey()))
            ->find($id);
    }

    /**
     * A card on the Open Canvas — a note or a checklist.
     *
     * Channel-owned cards only. A canvas card can belong to a side chat instead, and those have
     * a null `channel_id`; the comparison below excludes them rather than matching null against
     * null. Commenting on a side chat's card is a coherent feature and simply isn't this one —
     * it would need the side chat's own membership gate, not the channel's.
     */
    private static function canvasItem(Channel $channel, int $id): ?CanvasItem
    {
        return CanvasItem::query()->where('channel_id', $channel->getKey())->find($id);
    }

    /** A poll on the channel's wall — comments, tags and reactions all hang off it. */
    private static function poll(Channel $channel, int $id): ?AppPoll
    {
        return AppPoll::query()->where('channel_id', $channel->getKey())->find($id);
    }

    /** A sticker on the channel's wall. */
    private static function sticker(Channel $channel, int $id): ?AppSticker
    {
        return AppSticker::query()->where('channel_id', $channel->getKey())->find($id);
    }

    /** A file on the channel's Docs shelf. Channel-owned only, as with the canvas card. */
    private static function document(Channel $channel, int $id): ?SpaceDocument
    {
        return SpaceDocument::query()->where('channel_id', $channel->getKey())->find($id);
    }

    /**
     * The channel's one shared note.
     *
     * A surface has exactly one, so the id in the URL is checked against it rather than used to
     * look it up — otherwise any note id would resolve here and the channel scope would be the
     * only thing standing between them.
     */
    private static function note(Channel $channel, int $id): ?SpaceNote
    {
        return SpaceNote::query()->where('channel_id', $channel->getKey())->whereKey($id)->first();
    }

    /**
     * A card on the channel's kanban board.
     *
     * Scoped by the card's own `channel_id` rather than through its board: the column is
     * denormalised onto the card precisely so these paths don't have to join, and a board never
     * moves between channels.
     */
    private static function kanbanCard(Channel $channel, int $id): ?KanbanCard
    {
        return KanbanCard::query()->where('channel_id', $channel->getKey())->find($id);
    }

    /** An entry on the shared Calendar. Channel-owned only, same as the canvas card above. */
    private static function calendarEvent(Channel $channel, int $id): ?CalendarEvent
    {
        return CalendarEvent::query()->where('channel_id', $channel->getKey())->find($id);
    }
}
