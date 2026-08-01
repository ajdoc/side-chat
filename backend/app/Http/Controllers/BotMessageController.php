<?php

namespace App\Http\Controllers;

use App\Actions\Message\SendMessageAction;
use App\DTOs\Message\SendMessageData;
use App\Http\Requests\Bot\BotSendMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Channel;

/**
 * The bot API's one write: post a message.
 *
 * It goes through {@see SendMessageAction}, the same path the composer uses, so a bot's
 * message unfurls its links, notifies who it mentions, wakes the unread badge and arrives
 * over the websocket without any of that being reimplemented here. The only difference
 * between this and MessageController::store is who authenticated.
 *
 * That includes widget commands: a bot sending `k!add ship it` drives the channel's kanban
 * board exactly as a person typing it would. Which is a feature — a CI bot filing its own
 * cards is the obvious use — but it does mean a bot's reach is the whole command surface,
 * not just chat.
 */
class BotMessageController extends Controller
{
    public function store(BotSendMessageRequest $request, Channel $channel, SendMessageAction $action): MessageResource
    {
        return new MessageResource(
            $action->handle(
                $channel,
                $request->user(),
                SendMessageData::fromArray($request->validated()),
            )
        );
    }
}
