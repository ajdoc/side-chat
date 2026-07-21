<?php

namespace App\Http\Controllers;

use App\Actions\Thread\SendThreadMessageAction;
use App\DTOs\Message\SendMessageData;
use App\Http\Requests\Thread\IndexThreadMessageRequest;
use App\Http\Requests\Thread\StoreThreadMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Thread;
use App\Services\MessageService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ThreadMessageController extends Controller
{
    public function __construct(private readonly MessageService $messages) {}

    public function index(IndexThreadMessageRequest $request, Thread $thread): AnonymousResourceCollection
    {
        $page = $this->messages->forThread($thread, $request->integer('before') ?: null);

        return MessageResource::collection($page['messages'])
            ->additional(['has_more' => $page['has_more']]);
    }

    public function store(StoreThreadMessageRequest $request, Thread $thread, SendThreadMessageAction $action): MessageResource
    {
        return new MessageResource(
            $action->handle(
                $thread,
                $request->user(),
                SendMessageData::fromArray($request->safe()->except('attachments', 'uploads')),
                $request->file('attachments', []),
                $request->validated('uploads', []),
            )
        );
    }
}
