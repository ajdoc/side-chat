<?php

namespace App\Http\Controllers;

use App\Events\CalendarEventRemoved;
use App\Events\CalendarEventSaved;
use App\Http\Requests\Calendar\ChannelCalendarEventRequest;
use App\Http\Requests\Space\ChannelSpaceRequest;
use App\Http\Resources\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Models\Channel;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * A channel's (or DM's) shared Calendar — the Side Desk app, and the canvas card, and (via the
 * same broadcast stream) both at once. A channel has no roster, so membership gates reading and
 * authoring alike; everything else is shared with the side chat calendar
 * ({@see CalendarController}).
 */
class ChannelCalendarController extends Controller
{
    /**
     * Every entry, in start order.
     *
     * Deliberately unpaginated and unwindowed: a surface's calendar is a handful of rows, the
     * client already holds them all to render a month grid *and* an agenda list, and windowing
     * would mean refetching on every arrow press. If a busy channel ever makes that untrue,
     * `?from=`/`?to=` is the change — not pagination.
     */
    public function index(ChannelSpaceRequest $request, Channel $channel): AnonymousResourceCollection
    {
        return CalendarEventResource::collection(
            $channel->calendarEvents()->with('user')->orderBy('starts_at')->get()
        );
    }

    public function store(ChannelCalendarEventRequest $request, Channel $channel): CalendarEventResource
    {
        $event = $channel->calendarEvents()->create([
            'user_id' => $request->user()->id,
            ...$request->safe()->only(['title', 'description', 'starts_at', 'ends_at', 'all_day', 'color']),
        ]);

        broadcast(new CalendarEventSaved($event))->toOthers();

        return new CalendarEventResource($event->load('user'));
    }

    /** Edit an entry. Only the present fields change — a drag to another day saves just times. */
    public function update(ChannelCalendarEventRequest $request, Channel $channel, CalendarEvent $event): CalendarEventResource
    {
        abort_unless($event->channel_id === $channel->id, 404);

        $event->update($request->safe()->only(['title', 'description', 'starts_at', 'ends_at', 'all_day', 'color']));
        broadcast(new CalendarEventSaved($event))->toOthers();

        return new CalendarEventResource($event->load('user'));
    }

    public function destroy(ChannelCalendarEventRequest $request, Channel $channel, CalendarEvent $event): Response
    {
        abort_unless($event->channel_id === $channel->id, 404);

        $event->delete();
        broadcast(new CalendarEventRemoved('channel.'.$channel->id, $event->id))->toOthers();

        return response()->noContent();
    }
}
