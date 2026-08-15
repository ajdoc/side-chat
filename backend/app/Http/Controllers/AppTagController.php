<?php

namespace App\Http\Controllers;

use App\Events\TrackerChanged;
use App\Http\Requests\Tracker\StoreAppTagRequest;
use App\Http\Requests\Tracker\TrackerRequest;
use App\Http\Resources\AppTagResource;
use App\Models\AppTag;
use App\Models\Channel;
use App\Support\Apps\AppSubjects;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * A channel's tag vocabulary, and attaching it to things.
 *
 * Channel-scoped rather than per app or per project — see the migration. That's what makes a
 * tag worth having: "blocked" means the same thing on a tracker task as it will on a kanban
 * card, and the picker offers what the channel already uses instead of starting empty in every
 * app.
 */
class AppTagController extends Controller
{
    /** Every tag in the channel, with how many things wear it. */
    public function index(TrackerRequest $request, Channel $channel): AnonymousResourceCollection
    {
        return AppTagResource::collection(
            $channel->appTags()->withCount('taggables')->orderBy('name')->get()
        );
    }

    /**
     * Create a tag — or hand back the one that already answers to this label.
     *
     * Typing an existing name is how you reuse a tag, not a mistake to report: the picker is a
     * combo box, and somebody typing "bug" into it means the "bug" that exists. Returned with a
     * 200 rather than a 201 so the client can tell which happened, and the colour of an
     * existing tag is deliberately left alone — reusing a tag shouldn't recolour it for
     * everybody else.
     */
    public function store(StoreAppTagRequest $request, Channel $channel): JsonResponse
    {
        $label = trim((string) $request->validated('label'));
        $name = AppTag::normalize($label);

        $existing = $channel->appTags()->where('name', $name)->first();

        if ($existing !== null) {
            $existing->taggables_count = $existing->taggables()->count();

            // 200, not 201: nothing was created. That's the signal the client uses to tell
            // "reused the tag you meant" from "added a new word to the vocabulary" — worth
            // saying, since a typo'd tag is otherwise indistinguishable from a real new one.
            return (new AppTagResource($existing))->response()->setStatusCode(200);
        }

        $tag = $channel->appTags()->create([
            'name' => $name,
            'label' => $label,
            'color' => $request->validated('color', 'slate'),
        ]);

        $tag->taggables_count = 0;
        $this->broadcast($channel, 'saved', $tag);

        return (new AppTagResource($tag))->response()->setStatusCode(201);
    }

    /** Rename or recolour a tag, everywhere it's worn at once. */
    public function update(StoreAppTagRequest $request, Channel $channel, AppTag $tag): AppTagResource
    {
        abort_unless($tag->channel_id === $channel->id, 404);

        $changes = [];

        if ($request->has('label')) {
            $label = trim((string) $request->validated('label'));
            $name = AppTag::normalize($label);

            // Renaming onto a name the channel already uses would collide with the unique
            // index. Refused rather than silently merged: merging two tags is a decision about
            // everything wearing them, not a side effect of a typo in a rename box.
            $clash = $channel->appTags()->where('name', $name)->whereKeyNot($tag->getKey())->exists();
            abort_if($clash, 422);

            $changes = ['label' => $label, 'name' => $name];
        }

        if ($request->has('color')) {
            $changes['color'] = $request->validated('color');
        }

        $tag->update($changes);
        $tag->taggables_count = $tag->taggables()->count();

        $this->broadcast($channel, 'saved', $tag);

        return new AppTagResource($tag);
    }

    /** Delete a tag from the channel's vocabulary, and off everything wearing it. */
    public function destroy(TrackerRequest $request, Channel $channel, AppTag $tag): Response
    {
        abort_unless($tag->channel_id === $channel->id, 404);

        $tag->delete();

        broadcast(new TrackerChanged('channel.'.$channel->id, 'tag', 'removed', ['id' => $tag->id]))->toOthers();

        return response()->noContent();
    }

    /**
     * The tags currently on one item.
     *
     * Needed because nothing else can answer it: an app's own resource doesn't carry tags (a
     * calendar event knows nothing about them), so a panel opening on an item had no way to
     * learn what it already wore and drew an empty row. Its own endpoint rather than folding
     * them into every app's resource, for exactly the reason those tables are polymorphic.
     */
    public function forItem(TrackerRequest $request, Channel $channel, string $type, int $id): AnonymousResourceCollection
    {
        $subject = AppSubjects::resolve($channel, $type, $id);
        abort_if($subject === null, 404);

        return AppTagResource::collection($subject->tags()->get());
    }

    /**
     * Put a tag on something, or take it off.
     *
     * Attaching is idempotent — `syncWithoutDetaching` against the unique index, so a
     * double-click is one row and one chip rather than two.
     */
    public function attach(TrackerRequest $request, Channel $channel, string $type, int $id, AppTag $tag): AppTagResource
    {
        abort_unless($tag->channel_id === $channel->id, 404);

        $subject = AppSubjects::resolve($channel, $type, $id);
        abort_if($subject === null, 404);

        $subject->tags()->syncWithoutDetaching([$tag->getKey()]);
        $tag->taggables_count = $tag->taggables()->count();

        return new AppTagResource($tag);
    }

    public function detach(TrackerRequest $request, Channel $channel, string $type, int $id, AppTag $tag): Response
    {
        abort_unless($tag->channel_id === $channel->id, 404);

        $subject = AppSubjects::resolve($channel, $type, $id);
        abort_if($subject === null, 404);

        $subject->tags()->detach($tag->getKey());

        return response()->noContent();
    }

    private function broadcast(Channel $channel, string $action, AppTag $tag): void
    {
        broadcast(new TrackerChanged(
            'channel.'.$channel->id,
            'tag',
            $action,
            (new AppTagResource($tag))->resolve(),
        ))->toOthers();
    }
}
