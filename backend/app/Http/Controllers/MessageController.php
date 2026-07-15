<?php

namespace App\Http\Controllers;

use App\Actions\Message\DeleteMessageAction;
use App\Actions\Message\SendMessageAction;
use App\Actions\Message\UpdateMessageAction;
use App\DTOs\Message\SendMessageData;
use App\DTOs\Message\UpdateMessageData;
use App\Http\Requests\Message\DeleteMessageRequest;
use App\Http\Requests\Message\IndexMessageRequest;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Http\Requests\Message\UpdateMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Channel;
use App\Models\Message;
use App\Services\MessageService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

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
                SendMessageData::fromArray($request->safe()->except('attachments')),
                $request->file('attachments', []),
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
}
