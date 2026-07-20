<?php

namespace App\Http\Controllers;

use App\Events\CanvasItemRemoved;
use App\Events\CanvasItemSaved;
use App\Http\Requests\Canvas\ChannelCanvasItemRequest;
use App\Http\Requests\Space\ChannelSpaceRequest;
use App\Http\Resources\CanvasItemResource;
use App\Models\CanvasItem;
use App\Models\Channel;
use App\Services\Widgets\WidgetService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * A channel's (or DM's) Open Canvas — the same free 2D card surface a side chat has
 * ({@see CanvasController}), hanging off a plain channel. A channel has no roster, so
 * membership is the whole gate for both reading and authoring. Everything else — the events,
 * the resource, the surface stream — is shared with the side chat canvas.
 */
class ChannelCanvasController extends Controller
{
    /** Every card on the board, in stack order. */
    public function index(ChannelSpaceRequest $request, Channel $channel): AnonymousResourceCollection
    {
        return CanvasItemResource::collection(
            $channel->canvasItems()->with(['user', 'widget'])->get()
        );
    }

    public function store(ChannelCanvasItemRequest $request, Channel $channel, WidgetService $widgets): CanvasItemResource
    {
        // A `widget` card places the channel's widget of the named type, creating it (with the
        // handler's initial state) on first use — the very same widget a chat command reaches.
        $widgetId = null;
        if ($request->validated('kind') === 'widget') {
            $widget = $widgets->ensure($channel, $request->user(), $request->validated('content')['type']);
            abort_if($widget === null, 422, 'Unknown widget type.');
            $widgetId = $widget->id;
        }

        $item = $channel->canvasItems()->create([
            'user_id' => $request->user()->id,
            'widget_id' => $widgetId,
            'kind' => $request->validated('kind'),
            'content' => $request->validated('content'),
            'x' => (int) $request->validated('x', 0),
            'y' => (int) $request->validated('y', 0),
            'w' => (int) $request->validated('w', 240),
            'h' => (int) $request->validated('h', 180),
            'z' => (int) $channel->canvasItems()->max('z') + 1,
        ]);

        broadcast(new CanvasItemSaved($item))->toOthers();

        return new CanvasItemResource($item->load(['user', 'widget']));
    }

    /** Move, resize, restack or edit a card. Only the present fields change (a drag saves x/y). */
    public function update(ChannelCanvasItemRequest $request, Channel $channel, CanvasItem $item): CanvasItemResource
    {
        abort_unless($item->channel_id === $channel->id, 404);

        $item->update($request->safe()->only(['content', 'x', 'y', 'w', 'h', 'z']));
        broadcast(new CanvasItemSaved($item))->toOthers();

        return new CanvasItemResource($item->load(['user', 'widget']));
    }

    public function destroy(ChannelCanvasItemRequest $request, Channel $channel, CanvasItem $item): Response
    {
        abort_unless($item->channel_id === $channel->id, 404);

        $item->delete();
        broadcast(new CanvasItemRemoved('channel.'.$channel->id, $item->id))->toOthers();

        return response()->noContent();
    }
}
