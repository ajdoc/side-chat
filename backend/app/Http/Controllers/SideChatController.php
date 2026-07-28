<?php

namespace App\Http\Controllers;

use App\Actions\SideChat\AddParticipantsAction;
use App\Actions\SideChat\CreateSideChatAction;
use App\Actions\SideChat\DeleteSideChatAction;
use App\Actions\SideChat\JoinSideChatAction;
use App\Actions\SideChat\LeaveSideChatAction;
use App\Actions\SideChat\ToggleSideChatCommentAction;
use App\Actions\SideChat\ToggleSideChatReactionAction;
use App\Actions\SideChat\UpdateSideChatAction;
use App\DTOs\Comment\AddCommentData;
use App\DTOs\SideChat\CreateSideChatData;
use App\Events\SideChatActivity;
use App\Http\Requests\SideChat\AddParticipantsRequest;
use App\Http\Requests\SideChat\DeleteSideChatCommentRequest;
use App\Http\Requests\SideChat\DeleteSideChatRequest;
use App\Http\Requests\SideChat\IndexSideChatRequest;
use App\Http\Requests\SideChat\JoinSideChatRequest;
use App\Http\Requests\SideChat\StoreSideChatCommentRequest;
use App\Http\Requests\SideChat\StoreSideChatRequest;
use App\Http\Requests\SideChat\ToggleSideChatReactionRequest;
use App\Http\Requests\SideChat\UpdateSideChatRequest;
use App\Http\Requests\SideChat\ViewSideChatRequest;
use App\Http\Resources\CommentResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\SideChatResource;
use App\Models\Channel;
use App\Models\SideChat;
use App\Models\SideChatComment;
use App\Services\SideChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

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

    /**
     * Retitle and/or retag the post. The OP's, or the server staff's.
     *
     * Title and tags move together because they're edited together: both are how the post
     * presents itself in the forum list, and neither is a thing you change without looking
     * at the other.
     */
    public function update(UpdateSideChatRequest $request, SideChat $sideChat, UpdateSideChatAction $action): SideChatResource
    {
        // Tags are read off the request, not out of validated(): validation drops a parent
        // array whose `tags.*` rule matched nothing, so an *empty* list — "remove every
        // tag", a perfectly ordinary edit — would come back absent and be read as "leave
        // them alone". `has` distinguishes sent-and-empty from not-sent, which is the whole
        // distinction the action's null means.
        $tags = $request->has('tags') ? (array) $request->input('tags', []) : null;

        // Same sent-vs-absent distinction, for the same reason: `null` here means "move it
        // back to Uncategorised", so it can't also stand for "the dialog didn't touch it".
        $validated = $request->validated();
        $movesForum = array_key_exists('side_chat_forum_id', $validated);
        $forumId = $movesForum && $validated['side_chat_forum_id'] !== null
            ? (int) $validated['side_chat_forum_id']
            : null;

        return new SideChatResource(
            $this->sideChats->loadForDisplay(
                $action->handle($sideChat, $validated['name'] ?? null, $tags, $movesForum, $forumId)
            )
        );
    }

    /** Delete the post and everything inside it. The origin message in the channel stays. */
    public function destroy(DeleteSideChatRequest $request, SideChat $sideChat, DeleteSideChatAction $action): Response
    {
        $action->handle($sideChat);

        return response()->noContent();
    }

    /** React to the post itself — the forum list's chips. Anyone in the channel may. */
    public function react(ToggleSideChatReactionRequest $request, SideChat $sideChat, ToggleSideChatReactionAction $action): SideChatResource
    {
        return new SideChatResource(
            $this->sideChats->loadForDisplay(
                $action->handle($sideChat, $request->user(), $request->validated()['emoji'])
            )
        );
    }

    /**
     * Comment on the post, or take the comment back (a chip toggle). Anyone in the channel.
     *
     * Distinct from replying: a reply is a message in the side chat's own timeline, which
     * needs a place on the roster. A comment is a short co-signable phrase *about* the
     * post, which doesn't.
     */
    public function comment(StoreSideChatCommentRequest $request, SideChat $sideChat, ToggleSideChatCommentAction $action): SideChatResource
    {
        return new SideChatResource(
            $this->sideChats->loadForDisplay(
                $action->handle($sideChat, $request->user(), AddCommentData::fromArray($request->validated()))
            )
        );
    }

    /** The full comment list behind the post's chips — the "see all". */
    public function comments(ViewSideChatRequest $request, SideChat $sideChat): AnonymousResourceCollection
    {
        return CommentResource::collection($sideChat->comments()->with('user')->orderBy('id')->get());
    }

    /** Remove one of your own comments from that list. */
    public function destroyComment(DeleteSideChatCommentRequest $request, SideChatComment $comment): SideChatResource
    {
        $sideChat = $comment->sideChat;
        $comment->delete();

        broadcast(new SideChatActivity($sideChat));

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
