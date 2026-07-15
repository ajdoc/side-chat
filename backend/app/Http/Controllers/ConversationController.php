<?php

namespace App\Http\Controllers;

use App\Actions\Conversation\AddGroupMembersAction;
use App\Actions\Conversation\CreateDirectMessageAction;
use App\Actions\Conversation\CreateGroupAction;
use App\Actions\Conversation\LeaveConversationAction;
use App\Actions\Conversation\RenameConversationAction;
use App\DTOs\Conversation\AddMembersData;
use App\DTOs\Conversation\CreateGroupData;
use App\DTOs\Conversation\UpdateConversationData;
use App\Http\Requests\Conversation\AddMembersRequest;
use App\Http\Requests\Conversation\DeclineCallRequest;
use App\Http\Requests\Conversation\IndexConversationRequest;
use App\Http\Requests\Conversation\LeaveConversationRequest;
use App\Http\Requests\Conversation\StoreDirectMessageRequest;
use App\Http\Requests\Conversation\StoreGroupRequest;
use App\Http\Requests\Conversation\UpdateConversationRequest;
use App\Http\Requests\Conversation\ViewConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\VoiceParticipantResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\CallService;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * DMs and group chats.
 *
 * Notice what isn't here: sending a message, editing one, reacting, pinning, uploading,
 * threading, or joining the call. All of that already works in a chat and none of it needed
 * a line of code, because a conversation owns a Channel and the entire message stack is
 * addressed by channel id. This controller only does the things a chat has that a channel
 * doesn't: who's in it, what it's called, and how you decline a ringing phone.
 */
class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly CallService $calls,
    ) {}

    /** The Chats section of the sidebar. Most recently active first. */
    public function index(IndexConversationRequest $request): AnonymousResourceCollection
    {
        return ConversationResource::collection(
            $this->conversations->forUser($request->user()),
        );
    }

    public function show(ViewConversationRequest $request, Conversation $conversation): ConversationResource
    {
        return new ConversationResource($conversation->load('members', 'channel'));
    }

    /**
     * Open a DM — or reopen the one that was always there. Idempotent by design: see
     * CreateDirectMessageAction for why the database, not a lookup, decides that.
     *
     * 200, not 201, and deliberately: this is "make sure the DM with Ana exists and give
     * it to me", which is the same answer however many times you ask. A 201 would be a
     * claim that something new happened, and most of the time nothing did.
     */
    public function storeDirect(
        StoreDirectMessageRequest $request,
        CreateDirectMessageAction $action,
    ): JsonResponse {
        $conversation = $action->handle(
            $request->user(),
            User::findOrFail($request->integer('user_id')),
        );

        return (new ConversationResource($conversation))->response()->setStatusCode(200);
    }

    public function storeGroup(StoreGroupRequest $request, CreateGroupAction $action): ConversationResource
    {
        return new ConversationResource(
            $action->handle($request->user(), CreateGroupData::fromArray($request->validated())),
        );
    }

    /** Rename a group. Owner only; a DM has no name to change. */
    public function update(
        UpdateConversationRequest $request,
        Conversation $conversation,
        RenameConversationAction $action,
    ): ConversationResource {
        return new ConversationResource(
            $action->handle($conversation, UpdateConversationData::fromArray($request->validated())),
        );
    }

    public function addMembers(
        AddMembersRequest $request,
        Conversation $conversation,
        AddGroupMembersAction $action,
    ): AnonymousResourceCollection {
        return UserResource::collection(
            $action->handle($conversation, $request->user(), AddMembersData::fromArray($request->validated())),
        );
    }

    /** Walk out of a group. You can't leave a DM — the action explains why. */
    public function leave(
        LeaveConversationRequest $request,
        Conversation $conversation,
        LeaveConversationAction $action,
    ): Response {
        $action->handle($conversation, $request->user());

        return response()->noContent();
    }

    /** Who's in this chat's call right now — the faces above the timeline before you join. */
    public function voice(ViewConversationRequest $request, Conversation $conversation): JsonResponse
    {
        return response()->json([
            'data' => VoiceParticipantResource::collection(
                $this->calls->participants($conversation),
            )->resolve(),
        ]);
    }

    /** "Not now." Stops the ring — here, and on your other tabs. */
    public function declineCall(DeclineCallRequest $request, Conversation $conversation): Response
    {
        $this->calls->decline($conversation, $request->user());

        return response()->noContent();
    }

    /** People you could start a chat with: anyone you share a server with. */
    public function contacts(IndexConversationRequest $request): AnonymousResourceCollection
    {
        return UserResource::collection(
            $this->conversations->contactsFor($request->user(), $request->string('q')->toString() ?: null),
        );
    }
}
