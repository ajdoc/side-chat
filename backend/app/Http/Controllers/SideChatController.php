<?php

namespace App\Http\Controllers;

use App\Actions\SideChat\AddParticipantsAction;
use App\Actions\SideChat\CreateSideChatAction;
use App\Actions\SideChat\JoinSideChatAction;
use App\Actions\SideChat\LeaveSideChatAction;
use App\DTOs\SideChat\CreateSideChatData;
use App\Http\Requests\SideChat\AddParticipantsRequest;
use App\Http\Requests\SideChat\IndexSideChatRequest;
use App\Http\Requests\SideChat\JoinSideChatRequest;
use App\Http\Requests\SideChat\StoreSideChatRequest;
use App\Http\Requests\SideChat\ViewSideChatRequest;
use App\Http\Resources\MessageResource;
use App\Http\Resources\SideChatResource;
use App\Models\Channel;
use App\Models\SideChat;
use App\Services\SideChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SideChatController extends Controller
{
    public function __construct(private readonly SideChatService $sideChats) {}

    /** The channel's side chats — the list behind each timeline card. */
    public function index(IndexSideChatRequest $request, Channel $channel): AnonymousResourceCollection
    {
        return SideChatResource::collection($this->sideChats->forChannel($channel));
    }

    public function store(StoreSideChatRequest $request, Channel $channel, CreateSideChatAction $action): SideChatResource
    {
        return new SideChatResource(
            $action->handle($channel, $request->user(), CreateSideChatData::fromArray($request->validated()))
        );
    }

    public function show(ViewSideChatRequest $request, SideChat $sideChat): SideChatResource
    {
        return new SideChatResource($this->sideChats->loadForDisplay($sideChat));
    }

    /** The side chat's standing highlights — its decisions and pinned messages — for the panel's card. */
    public function highlights(ViewSideChatRequest $request, SideChat $sideChat): JsonResponse
    {
        $highlights = $this->sideChats->highlights($sideChat);

        return response()->json([
            'decisions' => MessageResource::collection($highlights['decisions'])->resolve(),
            'pinned' => MessageResource::collection($highlights['pinned'])->resolve(),
        ]);
    }

    /** Join the roster — what the [Join] button on the card does. */
    public function join(JoinSideChatRequest $request, SideChat $sideChat, JoinSideChatAction $action): SideChatResource
    {
        return new SideChatResource($action->handle($sideChat, $request->user()));
    }

    /** Add other channel members to the roster. Any participant may bring people in. */
    public function addParticipants(AddParticipantsRequest $request, SideChat $sideChat, AddParticipantsAction $action): SideChatResource
    {
        return new SideChatResource($action->handle($sideChat, $request->validated()['user_ids']));
    }

    /** Leave the roster. Anyone may leave; the side chat itself lives on. */
    public function leave(JoinSideChatRequest $request, SideChat $sideChat, LeaveSideChatAction $action): SideChatResource
    {
        return new SideChatResource($action->handle($sideChat, $request->user()));
    }
}
