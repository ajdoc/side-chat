<?php

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Server;
use App\Models\SideChat;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Your own stream. The one channel a logged-in client subscribes to before it knows
 * anything else about the world.
 *
 * Everything else here is scoped to a place you're already in, which leaves a gap: what
 * about the things that happen *to* you, in places you have no subscription to yet?
 * Someone opens a DM with you for the first time — you aren't in it, so there is no
 * stream on which to tell you it now exists. Someone calls you — the ring has to arrive
 * whatever you happen to be looking at, and it has to arrive even if you have never
 * opened that conversation in your life.
 *
 * So: ConversationCreated, CallStarted, CallEnded and CallDeclined are fanned out to one
 * of these per member. It costs a broadcast per recipient instead of one per room, which
 * for the handful of people in a chat is a bargain for never having to ask "were they
 * already listening?".
 */
Broadcast::channel('user.{userId}', fn (User $user, int $userId) => $user->id === $userId);

/**
 * Global online presence. A *presence* channel every signed-in client joins once (via
 * `echo.join('online')`), so Reverb keeps one roster of who's connected and pushes here/
 * joining/leaving to everyone — the source of the little green/amber dots on avatars.
 *
 * Any authenticated user may join; the returned payload is what each member carries in the
 * roster. Whether someone is *idle* (as opposed to merely online) isn't tracked here — that's
 * a client-side judgement whispered over this same channel, since only the browser knows the
 * user stopped touching the keyboard. See usePresence.
 */
Broadcast::channel('online', fn (User $user) => ['id' => $user->id, 'name' => $user->name]);

// Only members of the channel's container — its server, or its DM/group chat — may
// subscribe to its message stream.
Broadcast::channel('channel.{channelId}', function (User $user, int $channelId) {
    $channel = Channel::with('server', 'conversation')->find($channelId);

    return $channel !== null && $channel->hasMember($user);
});

// Join-request activity (created/approved/declined) and the pending-count badge.
Broadcast::channel('server.{serverId}', function (User $user, int $serverId) {
    $server = Server::find($serverId);

    return $server !== null && $server->hasMember($user);
});

/**
 * A conversation's always-open stream — the DM/group counterpart of `server.{id}`.
 *
 * Carries the things that have to reach you while you're looking at something *else*:
 * the unread badge on a chat you don't have open, and the roster of who is currently in
 * its call. Same reasoning as the server stream, and the events don't know the
 * difference — they ask the container where to go (see MessageContainer).
 */
Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
    $conversation = Conversation::find($conversationId);

    return $conversation !== null && $conversation->hasMember($user);
});

// Thread streams are gated by membership of the thread's channel's container.
Broadcast::channel('thread.{threadId}', function (User $user, int $threadId) {
    $thread = Thread::with('channel.server', 'channel.conversation')->find($threadId);

    return $thread !== null && $thread->channel->hasMember($user);
});

/**
 * Side-chat streams.
 *
 * Gated by membership of the *channel's* container, not the side chat's own roster —
 * anyone in the channel may watch a side chat live (read it, see its card update). The
 * roster is the gate on *posting*, enforced in the request layer, not on subscribing;
 * a non-participant subscribed here only ever receives, which is exactly reading.
 */
Broadcast::channel('sidechat.{sideChatId}', function (User $user, int $sideChatId) {
    $sideChat = SideChat::with('channel.server', 'channel.conversation')->find($sideChatId);

    return $sideChat !== null && $sideChat->channel->hasMember($user);
});

/**
 * A call. A *presence* channel, not a private one — the difference is the whole design.
 *
 * Returning an array instead of `true` makes Reverb keep a roster and push here/joining/
 * leaving to every subscriber, which is exactly the peer lifecycle a WebRTC mesh needs:
 * "who do I dial when I arrive", "who just arrived that I should expect a call from",
 * "whose peer connection do I tear down". Crucially, `leaving` fires when the socket
 * drops, so a closed laptop lid cleans up its own peer connections without anybody
 * having to notice a silence.
 *
 * Offers, answers and ICE candidates then ride over this channel as *client events*
 * (whispers), never touching Laravel — a fully-connected call is a burst of signalling
 * over a second or two, and putting it through HTTP + queue + broadcast would add a
 * round trip to each leg of a handshake that is already several legs deep. Reverb only
 * accepts client events from members of a channel, so this authorisation callback is
 * also what stops a stranger whispering an offer into a call they can't see.
 *
 * The payload becomes each member's `info` in the roster, so it must carry enough to
 * render a tile before a single packet of audio has arrived.
 *
 * Note `allowsCalls()` rather than `isVoice()`: a chat's one channel is a text channel
 * that can also hold a call, so this is the gate that lets a DM ring at all.
 */
Broadcast::channel('voice.{channelId}', function (User $user, int $channelId) {
    $channel = Channel::with('server', 'conversation')->find($channelId);

    if ($channel === null || ! $channel->allowsCalls() || ! $channel->hasMember($user)) {
        return null;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'avatar' => $user->avatar,
    ];
});
