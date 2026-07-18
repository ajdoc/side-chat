<?php

namespace App\Http\Controllers;

use App\Http\Requests\Widget\WidgetActionRequest;
use App\Http\Requests\Widget\WidgetShowRequest;
use App\Http\Resources\WidgetResource;
use App\Models\Widget;
use App\Services\Widgets\WidgetService;
use Illuminate\Http\Response;

/**
 * Card actions — everything a widget does that isn't a typed chat command. The player's
 * buttons, a kanban card dragged to another column, ticking a checkbox. Commands go
 * through the send path (see SendMessageAction); this is its non-typed twin, and both
 * meet in the same {@see WidgetService}.
 */
class WidgetController extends Controller
{
    public function __construct(private readonly WidgetService $widgets) {}

    /**
     * A widget's live state. Fetched after a reference-only WidgetUpdated / MessageSent —
     * the state is too big to ride the socket (Pusher's 10KB event cap).
     */
    public function show(WidgetShowRequest $request, Widget $widget): WidgetResource
    {
        return new WidgetResource($widget);
    }

    public function action(WidgetActionRequest $request, Widget $widget): Response
    {
        $this->widgets->handleAction(
            $widget,
            $request->user(),
            $request->string('action'),
            $request->array('payload'),
        );

        // The effect is broadcast as WidgetUpdated; the caller just needs the ack.
        return response()->noContent();
    }
}
