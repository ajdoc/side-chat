<?php

namespace App\Http\Controllers;

use App\Actions\Message\DeleteMessageAction;
use App\Actions\Message\ForwardMessageAction;
use App\Actions\Message\SendMessageAction;
use App\Actions\Message\UpdateMessageAction;
use App\DTOs\Message\SendMessageData;
use App\DTOs\Message\UpdateMessageData;
use App\Http\Requests\Message\DeleteMessageRequest;
use App\Http\Requests\Message\ForwardMessageRequest;
use App\Http\Requests\Message\IndexMessageRequest;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Http\Requests\Message\UpdateMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Channel;
use App\Models\Message;
use App\Services\MessageService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    public function __construct(private readonly MessageService $messages) {}

    public function index(IndexMessageRequest $request, Channel $channel): AnonymousResourceCollection
    {
        $page = $this->messages->forChannel($channel, $request->integer('before') ?: null);

        return MessageResource::collection($page['messages'])
            ->additional(['has_more' => $page['has_more']]);
    }

    public function store(StoreMessageRequest $request, Channel $channel, SendMessageAction $action): MessageResource
    {
        return new MessageResource(
            $action->handle(
                $channel,
                $request->user(),
                SendMessageData::fromArray($request->safe()->except('attachments', 'uploads')),
                $request->file('attachments', []),
                $request->validated('uploads', []),
            )
        );
    }

    public function update(UpdateMessageRequest $request, Message $message, UpdateMessageAction $action): MessageResource
    {
        return new MessageResource(
            $action->handle(
                $message,
                UpdateMessageData::fromArray($request->safe()->except('attachments')),
                $request->file('attachments', []),
            )
        );
    }

    public function destroy(DeleteMessageRequest $request, Message $message, DeleteMessageAction $action): Response
    {
        $action->handle($message);

        return response()->noContent();
    }

    /**
     * Forward a message into another channel (a DM, a group chat, or a server channel).
     *
     * Only ordinary messages travel: a system notice ("X joined") means nothing out of
     * context, and a widget card is a live, channel-scoped object rather than content — so
     * both are refused here even though the request layer already vetted both endpoints.
     */
    public function forward(ForwardMessageRequest $request, Message $message, ForwardMessageAction $action): MessageResource
    {
        if ($message->isSystem() || $message->isWidget()) {
            throw ValidationException::withMessages(['message' => 'This message can’t be forwarded.']);
        }

        $target = Channel::findOrFail($request->integer('channel_id'));

        return new MessageResource($action->handle($message, $target, $request->user()));
    }
}
