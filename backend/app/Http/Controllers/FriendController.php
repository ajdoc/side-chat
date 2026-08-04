<?php

namespace App\Http\Controllers;

use App\Actions\Friend\BlockUserAction;
use App\Actions\Friend\RemoveFriendAction;
use App\Actions\Friend\RespondToFriendRequestAction;
use App\Actions\Friend\SendFriendRequestAction;
use App\Http\Requests\Friend\BlockUserRequest;
use App\Http\Requests\Friend\StoreFriendRequest;
use App\Http\Resources\FriendshipResource;
use App\Http\Resources\UserResource;
use App\Models\Friendship;
use App\Models\User;
use App\Services\FriendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The friend list: who you know, who's asked, and who you'd rather not hear from.
 *
 * Everything here is one table (see the migration) read from four angles, because the
 * screen has four tabs. What it deliberately isn't is a directory — there is no browse
 * endpoint and no fuzzy search. You add someone you can already see, or by typing their
 * name exactly.
 *
 * The one thing a friendship *does* elsewhere in the app is open a door: friends can DM
 * each other without sharing a server, and a block closes that door in both directions.
 * See StoreDirectMessageRequest.
 */
class FriendController extends Controller
{
    public function __construct(private readonly FriendService $friends) {}

    /** Your friends, as people — this list is rendered as faces, not as rows. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return UserResource::collection($this->friends->friendsOf($request->user()));
    }

    /**
     * Everything outstanding, both directions in one call.
     *
     * One request rather than two, because the tab shows both and a badge counts only the
     * incoming half — splitting it would mean two round trips to render one screen.
     */
    public function pending(Request $request): AnonymousResourceCollection
    {
        return FriendshipResource::collection(
            $this->friends->friendshipsFor($request->user(), Friendship::PENDING),
        );
    }

    public function blocked(Request $request): AnonymousResourceCollection
    {
        return FriendshipResource::collection($this->friends->blockedBy($request->user()));
    }

    /**
     * Send a request — or accept theirs, if they got there first.
     *
     * 200, not 201, deliberately — same call as opening a DM. This is "make sure I've asked
     * Ben", which is the same answer however many times you ask; the action explains why
     * two crossing requests come back accepted rather than as a second row.
     */
    public function store(StoreFriendRequest $request, SendFriendRequestAction $action): JsonResponse
    {
        $friendship = $action->handle($request->user(), $request->target());

        return (new FriendshipResource($friendship))->response()->setStatusCode(200);
    }

    /** Say yes. Only the person who was asked may. */
    public function accept(
        Request $request,
        Friendship $friendship,
        RespondToFriendRequestAction $action,
    ): FriendshipResource {
        return new FriendshipResource($action->handle($request->user(), $friendship, accept: true));
    }

    /** Say no. The row goes away rather than remembering the refusal. */
    public function decline(
        Request $request,
        Friendship $friendship,
        RespondToFriendRequestAction $action,
    ): Response {
        $action->handle($request->user(), $friendship, accept: false);

        return response()->noContent();
    }

    /** Unfriend, or take back a request you sent. Either party, same row. */
    public function destroy(Request $request, Friendship $friendship, RemoveFriendAction $action): Response
    {
        $action->handle($request->user(), $friendship);

        return response()->noContent();
    }

    /** Unfriend by person, for the button that sits next to someone rather than a row. */
    public function destroyByUser(Request $request, User $user, RemoveFriendAction $action): Response
    {
        $action->handleBetween($request->user(), $user);

        return response()->noContent();
    }

    public function block(BlockUserRequest $request, BlockUserAction $action): JsonResponse
    {
        $friendship = $action->handle($request->user(), User::findOrFail($request->integer('user_id')));

        // 200 like `store` above, and for the same reason: blocking someone you've already
        // blocked is the same answer, and 201 would claim something new happened.
        return (new FriendshipResource($friendship))->response()->setStatusCode(200);
    }

    /** The blocker only — the action says why the blocked party can't do this. */
    public function unblock(Request $request, User $user, BlockUserAction $action): Response
    {
        $action->unblock($request->user(), $user);

        return response()->noContent();
    }
}
