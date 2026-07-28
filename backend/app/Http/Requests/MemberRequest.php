<?php

namespace App\Http\Requests;

use App\Contracts\MessageContainer;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Server;
use App\Models\SideChat;
use App\Models\Thread;
use App\Models\Widget;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for anything scoped to a place messages live: authorizes that the caller
 * is a member of it. Keeps membership checks out of the controllers.
 *
 * "Place" is a Server or a Conversation (a DM or a group chat) — see MessageContainer.
 * Routes bind whichever model is convenient (a server, a conversation, a channel, a
 * thread, a message) and resolveContainer() walks up from there, so every endpoint in the
 * app gets the right membership rule for free, whether it's being called about #general
 * or about a DM.
 */
abstract class MemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        // When the route leads to a channel, ask the *channel*: it applies the container's
        // membership rule and then its own access list on top (see Channel::hasMember).
        // Falling through to the container would let anyone in the server into a private
        // channel by addressing one of the things inside it — a thread, a message, a widget.
        $channel = $this->resolveChannel();

        if ($channel !== null) {
            return $channel->hasMember($user);
        }

        $container = $this->resolveContainer();

        return $container !== null && $container->hasMember($user);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    /**
     * The channel this route is about, if any — walking up from whatever it bound.
     *
     * The mirror of {@link resolveContainer}, one rung lower. Everything a channel holds
     * (threads, side chats, widgets, messages) leads back to exactly one channel, and that
     * channel may be private, so the access check has to be reachable from all of them.
     * Routes that bind only a server or a conversation return null and fall back to the
     * container rule, which is the whole of the answer for a DM or a group chat.
     */
    protected function resolveChannel(): ?Channel
    {
        $channel = $this->route('channel');
        if ($channel instanceof Channel) {
            return $channel;
        }

        $thread = $this->route('thread');
        if ($thread instanceof Thread) {
            return $thread->loadMissing('channel')->channel;
        }

        $sideChat = $this->route('sideChat');
        if ($sideChat instanceof SideChat) {
            return $sideChat->loadMissing('channel')->channel;
        }

        $widget = $this->route('widget');
        if ($widget instanceof Widget) {
            return $widget->loadMissing('channel')->channel;
        }

        $message = $this->route('message');
        if ($message instanceof Message) {
            return $message->loadMissing('channel')->channel;
        }

        return null;
    }

    /** Walk up from whatever the route bound to the thing that owns it. */
    protected function resolveContainer(): ?MessageContainer
    {
        $server = $this->route('server');
        if ($server instanceof Server) {
            return $server;
        }

        $conversation = $this->route('conversation');
        if ($conversation instanceof Conversation) {
            return $conversation;
        }

        $channel = $this->route('channel');
        if ($channel instanceof Channel) {
            return $channel->container();
        }

        $thread = $this->route('thread');
        if ($thread instanceof Thread) {
            return $thread->loadMissing('channel')->channel->container();
        }

        $sideChat = $this->route('sideChat');
        if ($sideChat instanceof SideChat) {
            return $sideChat->loadMissing('channel')->channel->container();
        }

        $widget = $this->route('widget');
        if ($widget instanceof Widget) {
            return $widget->loadMissing('channel')->channel->container();
        }

        $message = $this->route('message');
        if ($message instanceof Message) {
            return $message->loadMissing('channel')->channel->container();
        }

        return null;
    }

    /**
     * The owning *server*, specifically — null when the route leads to a conversation.
     *
     * Used by ServerOwnerRequest, and by it alone. Owner-only powers (deleting a channel,
     * deleting a server) have no counterpart in a DM: there is no owner, and a group's
     * single channel is not a thing you're allowed to delete out from under the chat.
     * Returning null here is what makes those endpoints refuse a conversation's channel.
     */
    protected function resolveServer(): ?Server
    {
        $container = $this->resolveContainer();

        return $container instanceof Server ? $container : null;
    }
}
