<?php

namespace App\Http\Middleware;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A guest may take part in the meeting they walked into, and reach nothing else.
 *
 * ## Default deny, and why it has to be
 *
 * A guest is a `users` row, so every membership check in the app answers "yes" for them wherever
 * they've been added — which is exactly one conversation. That is *most* of the protection, and
 * it is not enough: a signed-in account can also list its conversations, search, open profiles,
 * create servers, mint invites, upload files and start meetings of its own, none of which is a
 * thing a stranger admitted to one call should be able to do.
 *
 * So this refuses everything for a guest and then allows a short list, rather than the other way
 * round. An allow-list can only be *too small* — somebody hits a wall and we widen it — where a
 * deny-list is wrong the moment anybody adds a route, silently, in whatever direction the new
 * route points.
 *
 * ## Hiding buttons is not this
 *
 * The client has no guest-shaped UI at all: a guest sees the ordinary app with one chat in it.
 * That is deliberate — the boundary is here, in one file, checked on every request, rather than
 * spread across components where it would be one `v-if` away from being wrong.
 */
class ConfineGuests
{
    /**
     * Paths a guest may reach that aren't scoped to a channel or a conversation.
     *
     * Kept to what an account needs to *function* while it exists: know who it is, put itself
     * away, and keep its call alive. Nothing that reaches other people, and nothing that creates.
     */
    private const ALLOWED = [
        'api/auth/me',
        'api/auth/logout',
        'api/conversations',
    ];

    /**
     * What a guest may do with a *meeting link*, by the segment after the token.
     *
     * A guest account is confined to the meetings it has been let into — not to the first one.
     * Somebody sent a second link is the same person, and making them sign out and mint a fresh
     * throwaway account to follow it loses the chat they were already in, the name the room
     * knows them by, and any chance of the audit saying the two arrivals were one visitor.
     *
     * `''` is the link preview, `join` is following it — and the join is still checked by
     * {@see \App\Actions\Meeting\JoinMeetingAction}, which lets a guest in only where a
     * stranger with no account could have walked in anyway. This widens reach, not rights.
     */
    private const ON_MEETING = ['', 'join'];

    /**
     * What a guest may do *inside* their own room, by the first segment after the channel.
     *
     * Being in the meeting is not the same as being able to work in the room. Membership of the
     * conversation would otherwise hand a stranger the whole Side Desk — the calendar, the board,
     * the tracker, the shelf — of a chat they were let into for half an hour. So the room is a
     * whitelist too: talk, be in the call, and (for a Side Space meeting) walk around.
     *
     * `''` is the channel itself, which the client reads to render the room at all.
     */
    private const IN_ROOM = ['', 'messages', 'voice', 'space', 'read', 'reads', 'members', 'typing'];

    /** And on a message: react to it. Everything else about one belongs to the room's people. */
    private const ON_MESSAGE = ['reactions'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_guest) {
            return $next($request);
        }

        // Expired accounts are refused before anything else: a guest whose meeting finished is
        // an account nobody meant to leave standing, and it stops working the moment it lapses
        // rather than whenever the sweeper next runs.
        if ($user->guest_expires_at !== null && $user->guest_expires_at->isPast()) {
            abort(401, 'This guest session has ended.');
        }

        abort_unless($this->permitted($request, $user), 403, 'Guests can only take part in the meeting they joined.');

        return $next($request);
    }

    private function permitted(Request $request, User $user): bool
    {
        $path = trim($request->path(), '/');

        if (in_array($path, self::ALLOWED, true)) {
            return true;
        }

        // A meeting link names no channel — the token is the whole address, and until the link
        // has been followed the guest is by definition not in the room it leads to.
        $segments = $request->segments();

        if (($segments[1] ?? '') === 'meetings') {
            // A *token*, specifically. `api/meetings` is creating one and `api/meetings/rooms`
            // is the list of rooms to hold one in — neither is a door, and matching the whole
            // prefix would have opened both.
            return Str::isUuid($segments[2] ?? '')
                && in_array($segments[3] ?? '', self::ON_MEETING, true);
        }

        /*
         * Everything else must name the guest's own room.
         *
         * Resolved from the route's own bindings rather than from the path, so this can't be
         * fooled by a shape it didn't anticipate — and so a route that names a message or a
         * conversation instead of a channel is checked by the same rule.
         */
        $channel = $this->channelFor($request);

        return $channel !== null
            && $this->isTheirs($channel, $user)
            && $this->permittedInRoom($request);
    }

    /**
     * The part of the path *after* the thing it names, checked against the whitelists above.
     *
     * Segment-wise rather than by prefix matching, so `channels/9/calendar` can't slip through on
     * the strength of `channels/9` being allowed.
     */
    private function permittedInRoom(Request $request): bool
    {
        $segments = $request->segments(); // ['api', 'channels', '9', 'voice', 'join']

        $kind = $segments[1] ?? '';
        $after = $segments[3] ?? '';

        return match ($kind) {
            'channels' => in_array($after, self::IN_ROOM, true),
            'messages' => in_array($after, self::ON_MESSAGE, true),
            // A conversation route — the chat list's own entry for their meeting.
            'conversations' => $after === '',
            default => false,
        };
    }

    /** The channel a request is about, whichever way it names one. */
    private function channelFor(Request $request): ?Channel
    {
        $route = $request->route();

        if ($route === null) {
            return null;
        }

        $channel = $route->parameter('channel');

        if ($channel instanceof Channel) {
            return $channel;
        }

        $conversation = $route->parameter('conversation');

        if ($conversation instanceof Conversation) {
            return $conversation->channel;
        }

        // A message names its channel, which is how reactions, threads and the rest are covered
        // without listing them.
        $message = $route->parameter('message');

        if ($message instanceof Message) {
            return $message->channel;
        }

        return null;
    }

    /**
     * Is this the conversation the guest was admitted to?
     *
     * Membership is asked of the *conversation*, not of the channel, and a server channel is
     * refused outright however the guest came to be on its roster — a guest has no business in
     * a server under any circumstances, and a rule that leaned on membership alone would depend
     * on nothing ever adding one.
     */
    private function isTheirs(Channel $channel, User $user): bool
    {
        if ($channel->conversation_id === null) {
            return false;
        }

        return $user->conversations()->whereKey($channel->conversation_id)->exists();
    }
}
