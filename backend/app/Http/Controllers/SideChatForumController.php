<?php

namespace App\Http\Controllers;

use App\Events\SideChatForumsUpdated;
use App\Http\Requests\SideChatForum\DeleteSideChatForumRequest;
use App\Http\Requests\SideChatForum\IndexSideChatForumRequest;
use App\Http\Requests\SideChatForum\ReorderSideChatForumsRequest;
use App\Http\Requests\SideChatForum\StoreSideChatForumRequest;
use App\Http\Requests\SideChatForum\UpdateSideChatForumRequest;
use App\Http\Resources\SideChatForumResource;
use App\Models\Channel;
use App\Models\SideChatForum;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The channel's forum groups — the headings its side chat list folds under.
 *
 * Small enough to have no action classes of its own: creating a group is one insert, and
 * there is no snapshotting, no roster and no derived state to keep honest. What the actions
 * elsewhere in this app exist for is orchestration, and there is none here.
 */
class SideChatForumController extends Controller
{
    /**
     * The groups, in display order. Anyone who can read the channel can read these.
     *
     * `meta.can_manage` answers the question the per-row `can_manage` can't: may you make
     * the *first* group? With no rows there is nothing to carry the flag, and a channel
     * with no groups yet is precisely when somebody needs to be offered the control.
     */
    public function index(IndexSideChatForumRequest $request, Channel $channel): AnonymousResourceCollection
    {
        return SideChatForumResource::collection($channel->sideChatForums()->get())
            ->additional(['meta' => [
                'can_manage' => SideChatForum::canManageIn($channel, $request->user()),
            ]]);
    }

    public function store(StoreSideChatForumRequest $request, Channel $channel): SideChatForumResource
    {
        // New groups land at the bottom. Anywhere else and creating one would silently
        // rearrange a list somebody had already put in an order.
        $forum = $channel->sideChatForums()->create([
            'name' => $request->validated()['name'],
            'position' => (int) $channel->sideChatForums()->max('position') + 1,
        ]);

        broadcast(new SideChatForumsUpdated($channel));

        return new SideChatForumResource($forum);
    }

    public function update(UpdateSideChatForumRequest $request, SideChatForum $forum): SideChatForumResource
    {
        $forum->update($request->validated());

        broadcast(new SideChatForumsUpdated($forum->loadMissing('channel')->channel));

        return new SideChatForumResource($forum);
    }

    /**
     * Set the whole running order at once — `ids`, top to bottom.
     *
     * Groups the call didn't mention keep their existing positions and so fall in after the
     * ones it did, which is what makes a partial list (a drag within the visible groups)
     * behave sensibly rather than scattering the rest.
     */
    public function reorder(ReorderSideChatForumsRequest $request, Channel $channel): AnonymousResourceCollection
    {
        foreach ($request->validated()['ids'] as $index => $id) {
            $channel->sideChatForums()->whereKey($id)->update(['position' => $index]);
        }

        broadcast(new SideChatForumsUpdated($channel));

        return SideChatForumResource::collection($channel->sideChatForums()->get());
    }

    /**
     * Remove the group. Its posts are *not* removed — the foreign key nulls them, and they
     * reappear under "Uncategorised". See the migration for why that isn't a cascade.
     */
    public function destroy(DeleteSideChatForumRequest $request, SideChatForum $forum): Response
    {
        $channel = $forum->loadMissing('channel')->channel;

        $forum->delete();

        broadcast(new SideChatForumsUpdated($channel));

        return response()->noContent();
    }
}
