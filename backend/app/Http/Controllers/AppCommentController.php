<?php

namespace App\Http\Controllers;

use App\Events\TrackerChanged;
use App\Http\Requests\Tracker\StoreAppCommentRequest;
use App\Http\Requests\Tracker\TrackerRequest;
use App\Http\Resources\AppCommentResource;
use App\Models\AppComment;
use App\Models\Channel;
use App\Models\Server;
use App\Support\Apps\AppSubjects;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Comments on anything an app owns.
 *
 * One controller for every commentable kind, addressed as
 * `channels/{channel}/apps/{type}/{id}/comments`. The `{type}` is the short morph name, resolved
 * by {@see AppSubjects} — so the Tracker's tasks are served by this today, and a kanban card
 * becomes commentable by adding a resolver rather than another controller.
 *
 * Authorisation is two questions, asked in order and by different things: the request class
 * establishes that the caller is in this channel, and {@see AppSubjects} establishes that the
 * item is too. Neither knows about the other, and together they're the whole rule.
 */
class AppCommentController extends Controller
{
    public function index(TrackerRequest $request, Channel $channel, string $type, int $id): AnonymousResourceCollection
    {
        $subject = AppSubjects::resolve($channel, $type, $id);
        abort_if($subject === null, 404);

        return AppCommentResource::collection($subject->comments()->with('user')->get());
    }

    public function store(StoreAppCommentRequest $request, Channel $channel, string $type, int $id): AppCommentResource
    {
        $subject = AppSubjects::resolve($channel, $type, $id);
        abort_if($subject === null, 404);

        $comment = $subject->comments()->create([
            // Denormalised onto the row so later reads and permission checks don't have to walk
            // back up through whichever app owns it. See the migration.
            'channel_id' => $channel->getKey(),
            'user_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        $this->broadcast($channel, 'saved', $comment->load('user'));

        return new AppCommentResource($comment);
    }

    /**
     * Edit your own comment.
     *
     * Author only — not channel staff. Staff can *delete* a comment that shouldn't be there,
     * but rewriting the words under somebody else's name is a different power, and one nothing
     * in this app grants.
     */
    public function update(StoreAppCommentRequest $request, Channel $channel, AppComment $comment): AppCommentResource
    {
        abort_unless($comment->channel_id === $channel->id, 404);
        abort_unless($comment->user_id === $request->user()->id, 403);

        $comment->update([
            'body' => $request->validated('body'),
            // Stamped so the client can mark it edited. A comment that changed silently is a
            // record of a conversation that didn't happen.
            'edited_at' => now(),
        ]);

        $this->broadcast($channel, 'saved', $comment->load('user'));

        return new AppCommentResource($comment);
    }

    /** Author or channel staff. */
    public function destroy(TrackerRequest $request, Channel $channel, AppComment $comment): Response
    {
        abort_unless($comment->channel_id === $channel->id, 404);

        $user = $request->user();
        $container = $channel->container();
        $isStaff = $container instanceof Server && $container->isStaff($user);

        abort_unless($comment->user_id === $user->id || $isStaff, 403);

        $comment->delete();

        broadcast(new TrackerChanged('channel.'.$channel->id, 'comment', 'removed', [
            'id' => $comment->id,
            'commentable_type' => $comment->commentable_type,
            'commentable_id' => $comment->commentable_id,
        ]))->toOthers();

        return response()->noContent();
    }

    private function broadcast(Channel $channel, string $action, AppComment $comment): void
    {
        broadcast(new TrackerChanged(
            'channel.'.$channel->id,
            'comment',
            $action,
            (new AppCommentResource($comment))->resolve(),
        ))->toOthers();
    }
}
