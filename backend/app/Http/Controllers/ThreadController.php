<?php

namespace App\Http\Controllers;

use App\Actions\Thread\CreateThreadAction;
use App\DTOs\Thread\CreateThreadData;
use App\Http\Requests\SideChat\StoreSideChatThreadRequest;
use App\Http\Requests\SideChat\ViewSideChatRequest;
use App\Http\Requests\Thread\IndexThreadRequest;
use App\Http\Requests\Thread\StoreThreadRequest;
use App\Http\Requests\Thread\ViewThreadRequest;
use App\Http\Resources\ThreadResource;
use App\Models\Channel;
use App\Models\SideChat;
use App\Models\Thread;
use App\Services\ThreadService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ThreadController extends Controller
{
    public function __construct(private readonly ThreadService $threads) {}

    public function index(IndexThreadRequest $request, Channel $channel): AnonymousResourceCollection
    {
        return ThreadResource::collection($this->threads->forChannel($channel));
    }

    public function store(StoreThreadRequest $request, Channel $channel, CreateThreadAction $action): ThreadResource
    {
        return new ThreadResource(
            $action->handle($channel, $request->user(), CreateThreadData::fromArray($request->validated()))
        );
    }

    public function show(ViewThreadRequest $request, Thread $thread): ThreadResource
    {
        return new ThreadResource($this->threads->loadForDisplay($thread));
    }

    /** A side chat's own threads — its workspace list, separate from the channel's. */
    public function sideChatIndex(ViewSideChatRequest $request, SideChat $sideChat): AnonymousResourceCollection
    {
        return ThreadResource::collection($this->threads->forSideChat($sideChat));
    }

    public function sideChatStore(StoreSideChatThreadRequest $request, SideChat $sideChat, CreateThreadAction $action): ThreadResource
    {
        return new ThreadResource(
            $action->handleForSideChat($sideChat, $request->user(), CreateThreadData::fromArray($request->validated()))
        );
    }
}
