<?php

namespace App\Http\Controllers;

use App\Events\CalendarEventRemoved;
use App\Events\CalendarEventSaved;
use App\Http\Requests\Calendar\CalendarEventRequest;
use App\Http\Requests\SideChat\ViewSideChatRequest;
use App\Http\Resources\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Models\SideChat;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * A side chat's shared Calendar. Reading it is a channel-membership power
 * ({@see ViewSideChatRequest}); authoring is a roster power ({@see CalendarEventRequest}) —
 * the same line the canvas and the board draw. The channel's own calendar is the near-identical
 * {@see ChannelCalendarController}.
 */
class CalendarController extends Controller
{
    public function index(ViewSideChatRequest $request, SideChat $sideChat): AnonymousResourceCollection
    {
        return CalendarEventResource::collection(
            $sideChat->calendarEvents()->with('user')->orderBy('starts_at')->get()
        );
    }

    public function store(CalendarEventRequest $request, SideChat $sideChat): CalendarEventResource
    {
        $event = $sideChat->calendarEvents()->create([
            'user_id' => $request->user()->id,
            ...$request->safe()->only(['title', 'description', 'starts_at', 'ends_at', 'all_day', 'color']),
        ]);

        broadcast(new CalendarEventSaved($event))->toOthers();

        return new CalendarEventResource($event->load('user'));
    }

    public function update(CalendarEventRequest $request, SideChat $sideChat, CalendarEvent $event): CalendarEventResource
    {
        abort_unless($event->side_chat_id === $sideChat->id, 404);

        $event->update($request->safe()->only(['title', 'description', 'starts_at', 'ends_at', 'all_day', 'color']));
        broadcast(new CalendarEventSaved($event))->toOthers();

        return new CalendarEventResource($event->load('user'));
    }

    public function destroy(CalendarEventRequest $request, SideChat $sideChat, CalendarEvent $event): Response
    {
        abort_unless($event->side_chat_id === $sideChat->id, 404);

        $event->delete();
        broadcast(new CalendarEventRemoved('sidechat.'.$sideChat->id, $event->id))->toOthers();

        return response()->noContent();
    }
}
