<?php

namespace App\Http\Controllers;

use App\Actions\Meeting\CreateMeetingAction;
use App\Actions\Meeting\JoinMeetingAction;
use App\Actions\Meeting\JoinMeetingAsGuestAction;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\RateLimiter;
use App\Http\Requests\Space\ChannelSpaceRequest;
use App\Http\Resources\CalendarEventResource;
use App\Http\Resources\MeetingJoinResource;
use App\Http\Resources\MeetingResource;
use App\Models\CalendarEvent;
use App\Models\Channel;
use App\Models\Meeting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What's scheduled *in this room*.
 *
 * ## A meeting is a calendar entry with a room
 *
 * There is deliberately no `meetings` table. A scheduled meeting is a {@see CalendarEvent} whose
 * `room_channel_id` points at a voice channel or a Side Space — which is what the Calendar's own
 * room field already means. A second concept would need its own reminders, its own editor and its
 * own idea of "when", and would then disagree with the calendar about all three.
 *
 * What was missing is the *room's* half of that relationship. The entry lives in a text channel's
 * calendar ("Design → Standup, 10:00, in 🔊 Standup Room"), so the room itself had no way to
 * answer the question people ask while standing in it: **is something happening here, and when?**
 * This is that question, and it reads across every calendar in the server rather than the room's
 * own — a room has no calendar of its own and shouldn't grow one.
 */
class MeetingController extends Controller
{
    /** How far back a meeting still counts as "now" when it has no end time. */
    private const ASSUME_MINUTES = 60;

    /**
     * The meetings for this room: whatever is on now, and what's next.
     *
     * Scoped to entries the caller can see. The channel the entry *lives* in may be one they
     * aren't in — a private channel can schedule a meeting in a public room — so the visible set
     * is checked on the host channel, not on the room. Without that, the room would leak the
     * titles of private channels' plans to everybody who walked in.
     */
    public function index(ChannelSpaceRequest $request, Channel $channel): AnonymousResourceCollection
    {
        $now = now();

        $meetings = CalendarEvent::query()
            ->where('room_channel_id', $channel->getKey())
            ->whereIn('channel_id', Channel::query()->visibleTo($request->user())->select('channels.id'))
            // On now, or still to come. An entry with no end is assumed to run an hour, which is
            // the difference between "the standup is happening" and a room that claims a meeting
            // from three weeks ago is in progress.
            ->where(function ($q) use ($now) {
                $q->where('starts_at', '>=', $now)
                    ->orWhere(fn ($live) => $live
                        ->where('starts_at', '<', $now)
                        ->where(function ($ends) use ($now) {
                            $ends->where('ends_at', '>', $now)
                                ->orWhere(fn ($assumed) => $assumed
                                    ->whereNull('ends_at')
                                    ->where('starts_at', '>=', $now->copy()->subMinutes(self::ASSUME_MINUTES)));
                        }));
            })
            ->with(['user', 'channel:id,name', 'roomChannel:id,name,type,server_id'])
            ->orderBy('starts_at')
            // A room's agenda, not its history: the next few are what anybody standing here can
            // act on, and a longer list belongs in the calendar it came from.
            ->limit(10)
            ->get();

        return CalendarEventResource::collection($meetings);
    }

    /**
     * The meeting links for this room.
     *
     * Distinct from {@see index} above, which lists what is *scheduled* here and answers in
     * calendar entries. This answers in meetings — the links themselves — because "what's the
     * address of this room's meeting" is a different question from "what's on in here", and until
     * this endpoint existed the only way to see a link was to be the person who had just made it.
     *
     * Readable by anyone in the room, since a link is exactly what they're entitled to share. The
     * *audit* of who used one is not, and is its own endpoint.
     */
    public function links(ChannelSpaceRequest $request, Channel $channel): AnonymousResourceCollection
    {
        $meetings = Meeting::query()
            ->where('channel_id', $channel->getKey())
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with(['channel', 'creator', 'scheduledEvent'])
            ->withCount('joins')
            ->latest('id')
            ->limit(20)
            ->get();

        return MeetingResource::collection($meetings);
    }

    /**
     * Make a meeting — a room, a link to it, and optionally a time.
     *
     * Where it lands is decided by what the caller sends, not by a mode: `server_id` makes a room
     * in that server, `channel_id` reuses one that exists, and neither makes a **group
     * conversation** whose channel is the room. See {@see CreateMeetingAction}.
     */
    public function store(Request $request, CreateMeetingAction $action): MeetingResource
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            // Voice unless somebody deliberately chooses otherwise — see the action.
            'type' => ['sometimes', 'string', Rule::in(['voice', 'space'])],
            'server_id' => ['sometimes', 'nullable', 'integer'],
            'channel_id' => ['sometimes', 'nullable', 'integer'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'remind_minutes' => ['sometimes', 'nullable', 'integer', 'in:'.implode(',', CalendarEvent::REMIND_CHOICES)],
            // How far open the door is — see Meeting::ACCESS.
            'access' => ['sometimes', 'string', Rule::in(Meeting::ACCESS)],
            'invite_user_ids' => ['sometimes', 'array', 'max:100'],
            'invite_user_ids.*' => ['integer'],
            'map_preset' => ['sometimes', 'nullable', 'string'],
        ]);

        /*
         * Refused rather than quietly ignored.
         *
         * A link cannot admit anybody to a server, so "let outsiders in" and "hold it in a server
         * room" are a contradiction. Storing the flag anyway would hand somebody a link that
         * looks like it works and turns every stranger away.
         */
        if (($data['access'] ?? 'members') !== 'members' && ! empty($data['server_id'])) {
            throw ValidationException::withMessages([
                'access' => 'A meeting in a server can only be joined by that server’s people. Make it a group meeting to let anybody else in.',
            ]);
        }

        return new MeetingResource($action->handle($request->user(), $data));
    }

    /**
     * What a link leads to, before anybody commits to following it.
     *
     * Deliberately readable by any signed-in user, and deliberately thin: the title, who called
     * it, when it is, and whether this person can get in. Somebody deciding whether to follow a
     * link needs to know what it is; they do not need the room's id or its members, and a page
     * that leaked those would make an unguessable token the only thing protecting them.
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $meeting = Meeting::with('channel', 'creator', 'scheduledEvent')->where('token', $token)->first();

        abort_if($meeting === null, 404);

        // Unauthenticated here as often as not — the whole point of a public link — so every
        // question below has to have an answer for "nobody".
        $user = $request->user();
        $inside = $user !== null && $meeting->channel?->hasMember($user);

        return response()->json(['data' => [
            'title' => $meeting->title,
            'creator' => $meeting->creator?->name,
            'room_type' => $meeting->channel?->type,
            'scheduled_at' => $meeting->scheduledEvent?->starts_at?->toIso8601String(),
            'open' => $meeting->isOpen(),
            // Can *this* person get in, and if not, why. The client says so rather than sending
            // somebody through a join that will refuse them.
            'can_join' => $inside || $meeting->admitsOutsiders(),
            'member' => $inside,
            // Whether somebody with no account at all may walk in. What turns the link page from
            // "sign in to join" into a name field.
            'guests' => $meeting->admitsGuests(),
        ]]);
    }

    /** Follow the link. Admits an outsider only to a group meeting — see {@see JoinMeetingAction}. */
    public function join(Request $request, string $token, JoinMeetingAction $action): MeetingResource
    {
        $meeting = Meeting::with('channel')->where('token', $token)->first();

        abort_if($meeting === null, 404);

        return new MeetingResource($action->handle($meeting, $request->user(), $request));
    }

    /**
     * Walk in with no account: a name, and you're in.
     *
     * Public — it is the only endpoint here that has to be, since the whole point is somebody who
     * cannot sign in. Rate-limited per IP, because an unauthenticated route that creates accounts
     * is exactly the shape of thing that gets found and hammered; the limit is generous enough
     * for a room of people behind one office NAT and mean enough to make a script pointless.
     */
    public function guest(Request $request, string $token, JoinMeetingAsGuestAction $action): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:40']]);

        $meeting = Meeting::with('channel.conversation')->where('token', $token)->first();

        abort_if($meeting === null, 404);

        $key = 'meeting-guest:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            abort(429, 'Too many attempts. Try again in a few minutes.');
        }

        RateLimiter::hit($key, 600);

        $joined = $action->handle($meeting, $data['name'], $request);

        return response()->json([
            // The token is the guest's whole session: the client stores it exactly as it stores a
            // real sign-in, which is what makes every other endpoint work for them unchanged.
            'token' => $joined['token'],
            'user' => (new UserResource($joined['user']))->resolve(),
            'meeting' => (new MeetingResource($joined['meeting']))->resolve(),
        ]);
    }

    /**
     * Who was admitted to this meeting, and how.
     *
     * Restricted to the people who answer for the room — its creator, or staff of the server it
     * is in. A guest list is a record *about* people, and everybody in a call being able to read
     * who arrived from where is a different thing from the room knowing somebody walked in (which
     * the timeline already said out loud).
     */
    public function joins(Request $request, string $token): AnonymousResourceCollection
    {
        $meeting = Meeting::with('channel.server')->where('token', $token)->firstOrFail();

        $user = $request->user();
        $container = $meeting->channel?->container();
        $mayAudit = $meeting->created_by === $user->getKey()
            || ($container instanceof \App\Models\Server && $container->isStaff($user))
            || $container?->owner_id === $user->getKey();

        abort_unless($mayAudit, 403);

        return MeetingJoinResource::collection($meeting->joins()->with('user')->latest('id')->get());
    }
}
