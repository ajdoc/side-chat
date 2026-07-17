<?php

namespace App\Contracts;

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A place channels live in: a Server, or a Conversation (a DM or a group chat).
 *
 * Everything downstream of a channel — messages, threads, reactions, reads, pins,
 * attachments, calls — asks exactly two questions of whatever the channel belongs to:
 * "may this person be in here", and "where do I broadcast the things that happen
 * *outside* a channel you have open" (an unread badge, a roster of who's in a call).
 * That's this interface, and it's why a DM needed no new message-handling code at all.
 *
 * The second one deserves a word. You are only subscribed to `channel.{id}` for the one
 * channel you're looking at, so anything meant to move a badge on a *different* row of
 * the sidebar has to travel on a stream you always have open. For a server that's
 * `server.{id}`; for a conversation it's `conversation.{id}`. Same idea, two names — so
 * the events just ask the container.
 */
interface MessageContainer
{
    public function hasMember(User $user): bool;

    /**
     * Everyone who belongs here — the people a message can be addressed to.
     *
     * Shared by the @mention autocomplete and by mention resolution on send, so a name in a
     * message body can be turned back into the member it names, in a server or a chat alike.
     *
     * @return BelongsToMany<User, \Illuminate\Database\Eloquent\Model>
     */
    public function members(): BelongsToMany;

    /**
     * The room's own stream, for people who currently have it open.
     *
     * Who's in the call, and anything else that only matters while you're looking at the
     * place. Clients subscribe to this for the server / conversation they're *in*, and
     * drop it when they leave — so nothing sent here is guaranteed to reach anybody.
     */
    public function broadcastChannel(): PrivateChannel;

    /**
     * Where to shout so that it lands, wherever the members happen to be looking.
     *
     * An unread badge is the case that makes this its own method: the one row of the
     * sidebar that will *not* light up is the channel you have open, which means the event
     * has to travel on something you're subscribed to *regardless* of what you're looking
     * at. The two containers answer that very differently.
     *
     * A server has a single stream every member holds open all the time (`server.{id}`),
     * so it's one broadcast for the lot of them. A conversation has no such thing — you
     * are not subscribed to `conversation.{id}` for a chat you haven't opened, and a chat
     * you haven't opened is exactly the one that needs the badge. So it fans out to each
     * member's personal `user.{id}` instead: N broadcasts rather than one, which for the
     * handful of people in a chat is a bargain for never having to wonder whether they
     * were listening.
     *
     * @return array<int, PrivateChannel>
     */
    public function notificationChannels(): array;

    /** @return array<int, int> Every member's id. */
    public function memberIds(): array;
}
