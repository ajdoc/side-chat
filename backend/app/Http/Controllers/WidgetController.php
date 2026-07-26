<?php

namespace App\Http\Controllers;

use App\Http\Requests\Widget\EnsureWidgetRequest;
use App\Http\Requests\Widget\WidgetActionRequest;
use App\Http\Requests\Widget\WidgetShowRequest;
use App\Http\Resources\WidgetResource;
use App\Models\Channel;
use App\Models\Widget;
use App\Services\Widgets\WidgetService;
use Illuminate\Http\JsonResponse;
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

    /**
     * The channel's widget of a given type, created on first use.
     *
     * What a "widget app" tab opens onto. Widgets have always been one-per-(channel, type) —
     * the timeline card, the canvas card and now the Side Desk tab are three placements of one
     * row, which is precisely why they stay in sync without anything syncing them. This endpoint
     * is just the existing get-or-create ({@see WidgetService::ensure()}) made reachable
     * directly, instead of only as a side effect of typing a command or dropping a canvas card.
     *
     * A side chat's tab resolves against its parent channel, the same scoping its canvas uses.
     */
    public function ensure(EnsureWidgetRequest $request, Channel $channel): WidgetResource
    {
        $widget = $this->widgets->ensure($channel, $request->user(), $request->string('type'));
        abort_if($widget === null, 422, 'Unknown widget type.');

        return new WidgetResource($widget);
    }

    public function action(WidgetActionRequest $request, Widget $widget): Response|JsonResponse
    {
        $reply = $this->widgets->handleAction(
            $widget,
            $request->user(),
            $request->string('action'),
            $request->array('payload'),
        );

        // A state change is broadcast as WidgetUpdated — the caller just needs the ack. But an
        // action can also fail softly (a quota'd search, an unreadable link): the handler hands
        // back an ephemeral note the caller shows to the actor, since the UI that fired this —
        // the player's own add field — has no chat line to fall back to.
        return $reply !== null
            ? response()->json(['reply' => $reply])
            : response()->noContent();
    }
}
