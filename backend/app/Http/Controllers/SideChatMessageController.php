<?php

namespace App\Http\Controllers;

use App\Actions\SideChat\SendSideChatMessageAction;
use App\DTOs\Message\SendMessageData;
use App\Http\Requests\SideChat\IndexSideChatMessageRequest;
use App\Http\Requests\SideChat\StoreSideChatMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\SideChat;
use App\Services\MessageService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SideChatMessageController extends Controller
{
    public function __construct(private readonly MessageService $messages) {}

    public function index(IndexSideChatMessageRequest $request, SideChat $sideChat): AnonymousResourceCollection
    {
        $page = $this->messages->forSideChat($sideChat, $request->integer('before') ?: null);

        return MessageResource::collection($page['messages'])
            ->additional(['has_more' => $page['has_more']]);
    }

    public function store(StoreSideChatMessageRequest $request, SideChat $sideChat, SendSideChatMessageAction $action): MessageResource
    {
        return new MessageResource(
            $action->handle(
                $sideChat,
                $request->user(),
                SendMessageData::fromArray($request->safe()->except('attachments')),
                $request->file('attachments', []),
            )
        );
    }
}
