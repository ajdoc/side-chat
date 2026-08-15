<?php

namespace App\Http\Controllers;

use App\Events\TrackerChanged;
use App\Http\Requests\Tracker\TrackerRequest;
use App\Http\Resources\AppStickerResource;
use App\Models\AppSticker;
use App\Models\Channel;
use App\Models\Server;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The Sticker Wall — a channel's shared collage.
 *
 * Unpaginated on purpose: a wall is a picture, and half a picture is not a useful thing to
 * render. If a wall ever grows past what one payload should carry, the answer is a viewport
 * query, not pages — you would still need every sticker in view at once.
 */
class AppStickerController extends Controller
{
    /**
     * How big a stored drawing may be, in bytes of JSON.
     *
     * The editor simplifies and shows a meter, but a client is not a gate: without this a
     * hand-written request could put an unbounded blob on a wall that everybody then loads
     * forever. Mirrors MAX_STICKER_BYTES in lib/stickers.ts, with slack for encoding.
     */
    private const MAX_CONTENT_BYTES = 32_000;

    /** One sticker, drawing included — what a client fetches after a placement-only broadcast. */
    public function show(TrackerRequest $request, Channel $channel, AppSticker $sticker): AppStickerResource
    {
        abort_unless($sticker->channel_id === $channel->id, 404);

        return new AppStickerResource($sticker->load('user'));
    }

    public function index(TrackerRequest $request, Channel $channel): AnonymousResourceCollection
    {
        return AppStickerResource::collection(
            $channel->stickers()->with('user')->orderBy('z')->orderBy('id')->get()
        );
    }

    public function store(TrackerRequest $request, Channel $channel): AppStickerResource
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            // The drawing. Free-form, like a widget's state — its shape belongs to the editor
            // and the renderer, not to the API. Size-capped so the wall can't become a file
            // host; the cap is generous next to what a small drawing actually weighs.
            'content' => ['required', 'array', $this->sizeLimit()],
            'x' => ['sometimes', 'integer'],
            'y' => ['sometimes', 'integer'],
            'w' => ['sometimes', 'integer', 'min:24', 'max:1200'],
            'h' => ['sometimes', 'integer', 'min:24', 'max:1200'],
            'rotation' => ['sometimes', 'integer', 'min:-180', 'max:180'],
        ]);

        $sticker = $channel->stickers()->create([
            ...$data,
            'user_id' => $request->user()->id,
            // Newest on top. Rearranging is rewriting this, which is what the "bring to front"
            // gesture does.
            'z' => ((int) $channel->stickers()->max('z')) + 1,
        ]);

        $this->broadcast($channel, 'saved', $sticker->load('user'));

        return new AppStickerResource($sticker);
    }

    /** Move, resize, rotate or rename. The drawing itself is editable too. */
    public function update(TrackerRequest $request, Channel $channel, AppSticker $sticker): AppStickerResource
    {
        abort_unless($sticker->channel_id === $channel->id, 404);
        $this->authorizeEdit($request, $channel, $sticker);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'content' => ['sometimes', 'array', $this->sizeLimit()],
            'x' => ['sometimes', 'integer'],
            'y' => ['sometimes', 'integer'],
            'z' => ['sometimes', 'integer'],
            'w' => ['sometimes', 'integer', 'min:24', 'max:1200'],
            'h' => ['sometimes', 'integer', 'min:24', 'max:1200'],
            'rotation' => ['sometimes', 'integer', 'min:-180', 'max:180'],
        ]);

        $sticker->update($data);
        $this->broadcast($channel, 'saved', $sticker->load('user'));

        return new AppStickerResource($sticker);
    }

    public function destroy(TrackerRequest $request, Channel $channel, AppSticker $sticker): Response
    {
        abort_unless($sticker->channel_id === $channel->id, 404);
        $this->authorizeEdit($request, $channel, $sticker);

        $sticker->delete();

        broadcast(new TrackerChanged('channel.'.$channel->id, 'sticker', 'removed', ['id' => $sticker->id]))->toOthers();

        return response()->noContent();
    }

    /**
     * Refuse a drawing too big to belong on a wall.
     *
     * Measured on the encoded JSON rather than on a point count, because that's the thing that
     * actually costs — the row, every wall load, and every client holding it.
     */
    private function sizeLimit(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (strlen((string) json_encode($value)) > self::MAX_CONTENT_BYTES) {
                $fail('That sticker is too detailed to save — try undoing a few strokes.');
            }
        };
    }

    /**
     * Your own sticker, or staff.
     *
     * Stricter than the rest of the Side Desk apps, which let any member edit anything — and
     * deliberately so. A wall is a collage of individual contributions with a name on each, so
     * "anyone may move or delete anyone's" makes it a thing that can be quietly vandalised.
     * Staff keep the override, because a wall also needs moderating.
     */
    private function authorizeEdit(TrackerRequest $request, Channel $channel, AppSticker $sticker): void
    {
        $user = $request->user();
        $container = $channel->container();
        $isStaff = $container instanceof Server && $container->isStaff($user);

        abort_unless($sticker->user_id === $user->id || $isStaff, 403);
    }

    private function broadcast(Channel $channel, string $action, AppSticker $sticker): void
    {
        broadcast(new TrackerChanged(
            'channel.'.$channel->id,
            'sticker',
            $action,
            // Placement only — the drawing is too big for an event. See the resource.
            AppStickerResource::reference($sticker)->resolve(),
        ))->toOthers();
    }
}
