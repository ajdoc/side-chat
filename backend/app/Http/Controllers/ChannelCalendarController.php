<?php

namespace App\Http\Controllers;

use App\Events\CalendarEventRemoved;
use App\Events\CalendarEventSaved;
use App\Http\Requests\Calendar\ChannelCalendarEventRequest;
use App\Http\Requests\Space\ChannelSpaceRequest;
use App\Http\Resources\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Models\Channel;
use Illuminate\Http\JsonResponse;
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
            $channel->calendarEvents()->with(['user', 'roomChannel:id,name,type,server_id'])->orderBy('starts_at')->get()
        );
    }

    /**
     * The rooms an entry here may point at — this server's voice channels and Side Spaces.
     *
     * A dedicated endpoint rather than making the editor fetch the server's whole channel list
     * and filter it: the answer is exactly what the store path *validates* against, so one query
     * defines both and the picker can't offer something the save then refuses.
     */
    public function rooms(ChannelSpaceRequest $request, Channel $channel): JsonResponse
    {
        $rooms = Channel::query()
            ->visibleTo($request->user())
            ->whereIn('type', ['voice', 'space'])
            // Same server only. A room in another server is a room these people may be able to
            // see and cannot treat as "where our standup is".
            ->when($channel->server_id !== null, fn ($q) => $q->where('server_id', $channel->server_id))
            ->when($channel->server_id === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('name')
            // `server_id` rides along so the client can build a link to the room — the path is
            // the frontend's to compose (see SearchPanel), and it needs the server in it.
            ->get(['id', 'name', 'type', 'server_id']);

        return response()->json(['data' => $rooms]);
    }

    public function store(ChannelCalendarEventRequest $request, Channel $channel): CalendarEventResource
    {
        $event = $channel->calendarEvents()->create([
            'user_id' => $request->user()->id,
            ...$this->fields($request),
        ]);

        broadcast(new CalendarEventSaved($event))->toOthers();

        return new CalendarEventResource($event->load('user', 'roomChannel:id,name,type,server_id'));
    }

    /**
     * The writable fields, with the room checked against where it claims to be.
     *
     * A `room_channel_id` is a channel id in a request body, so it gets the same treatment every
     * other id crossing this boundary does: it must be a channel the author can see, in this
     * channel's own server, and one people can actually gather in. Without that, an entry could
     * point at a private room as a way of confirming it exists.
     *
     * @return array<string, mixed>
     */
    private function fields(ChannelCalendarEventRequest $request): array
    {
        $fields = $request->safe()->only([
            'title', 'description', 'starts_at', 'ends_at', 'all_day', 'color', 'remind_minutes', 'room_channel_id',
        ]);

        if (($fields['room_channel_id'] ?? null) !== null) {
            $room = Channel::query()
                ->visibleTo($request->user())
                ->whereIn('type', ['voice', 'space'])
                ->whereKey($fields['room_channel_id'])
                ->first();

            abort_if($room === null, 422, 'That room isn’t a voice channel or Side Space you can see.');
        }

        return $fields;
    }

    /** Edit an entry. Only the present fields change — a drag to another day saves just times. */
    public function update(ChannelCalendarEventRequest $request, Channel $channel, CalendarEvent $event): CalendarEventResource
    {
        abort_unless($event->channel_id === $channel->id, 404);

        $fields = $this->fields($request);

        /*
         * Moving the start, or changing the reminder, arms it again.
         *
         * An entry dragged to tomorrow has not "already been reminded about" — the notice that
         * went out was about a time that is no longer when this happens. Clearing the stamp is
         * what makes rescheduling work at all, and it is the one case where a second reminder
         * for one entry is correct.
         */
        if (array_key_exists('starts_at', $fields) || array_key_exists('remind_minutes', $fields)) {
            $fields['reminded_at'] = null;
        }

        $event->update($fields);
        broadcast(new CalendarEventSaved($event))->toOthers();

        return new CalendarEventResource($event->load('user', 'roomChannel:id,name,type,server_id'));
    }

    public function destroy(ChannelCalendarEventRequest $request, Channel $channel, CalendarEvent $event): Response
    {
        abort_unless($event->channel_id === $channel->id, 404);

        $event->delete();
        broadcast(new CalendarEventRemoved('channel.'.$channel->id, $event->id))->toOthers();

        return response()->noContent();
    }
}
