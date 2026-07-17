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
        $container = $this->resolveContainer();
        $user = $this->user();

        return $container !== null && $user !== null && $container->hasMember($user);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
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
