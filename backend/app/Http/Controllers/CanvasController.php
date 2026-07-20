<?php

namespace App\Http\Controllers;

use App\Events\CanvasItemRemoved;
use App\Events\CanvasItemSaved;
use App\Http\Requests\Canvas\CanvasItemRequest;
use App\Http\Requests\SideChat\ViewSideChatRequest;
use App\Http\Resources\CanvasItemResource;
use App\Models\CanvasItem;
use App\Models\SideChat;
use App\Services\Widgets\WidgetService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * A side chat's Open Canvas — the free 2D card surface beside the board and notes. Reading it
 * is a channel-membership power (ViewSideChatRequest); authoring is a roster power
 * (CanvasItemRequest), exactly the line join draws for posting and drawing. The channel's own
 * canvas is the near-identical {@see ChannelCanvasController}.
 */
class CanvasController extends Controller
{
    public function index(ViewSideChatRequest $request, SideChat $sideChat): AnonymousResourceCollection
    {
        return CanvasItemResource::collection(
            $sideChat->canvasItems()->with(['user', 'widget'])->get()
        );
    }

    public function store(CanvasItemRequest $request, SideChat $sideChat, WidgetService $widgets): CanvasItemResource
    {
        // A `widget` card places the *parent channel's* widget of the named type — widgets are
        // channel-scoped, so a side chat pins its channel's shared widget (the same one the
        // timeline and the channel's own canvas use).
        $widgetId = null;
        if ($request->validated('kind') === 'widget') {
            $channel = $sideChat->loadMissing('channel')->channel;
            $widget = $widgets->ensure($channel, $request->user(), $request->validated('content')['type']);
            abort_if($widget === null, 422, 'Unknown widget type.');
            $widgetId = $widget->id;
        }

        $item = $sideChat->canvasItems()->create([
            'user_id' => $request->user()->id,
            'widget_id' => $widgetId,
            'kind' => $request->validated('kind'),
            'content' => $request->validated('content'),
            'x' => (int) $request->validated('x', 0),
            'y' => (int) $request->validated('y', 0),
            'w' => (int) $request->validated('w', 240),
            'h' => (int) $request->validated('h', 180),
            'z' => (int) $sideChat->canvasItems()->max('z') + 1,
        ]);

        broadcast(new CanvasItemSaved($item))->toOthers();

        return new CanvasItemResource($item->load(['user', 'widget']));
    }

    public function update(CanvasItemRequest $request, SideChat $sideChat, CanvasItem $item): CanvasItemResource
    {
        abort_unless($item->side_chat_id === $sideChat->id, 404);

        $item->update($request->safe()->only(['content', 'x', 'y', 'w', 'h', 'z']));
        broadcast(new CanvasItemSaved($item))->toOthers();

        return new CanvasItemResource($item->load(['user', 'widget']));
    }

    public function destroy(CanvasItemRequest $request, SideChat $sideChat, CanvasItem $item): Response
    {
        abort_unless($item->side_chat_id === $sideChat->id, 404);

        $item->delete();
        broadcast(new CanvasItemRemoved('sidechat.'.$sideChat->id, $item->id))->toOthers();

        return response()->noContent();
    }
}
