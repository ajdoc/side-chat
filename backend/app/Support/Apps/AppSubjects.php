<?php

namespace App\Support\Apps;

use App\Models\CalendarEvent;
use App\Models\CanvasItem;
use App\Models\Channel;
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

    /** An entry on the shared Calendar. Channel-owned only, same as the canvas card above. */
    private static function calendarEvent(Channel $channel, int $id): ?CalendarEvent
    {
        return CalendarEvent::query()->where('channel_id', $channel->getKey())->find($id);
    }
}
