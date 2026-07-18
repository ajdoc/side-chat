<?php

namespace App\Http\Controllers;

use App\Events\WhiteboardCleared;
use App\Events\WhiteboardStrokeAdded;
use App\Events\WhiteboardStrokeRemoved;
use App\Events\WhiteboardStrokeUpdated;
use App\Http\Requests\SideChat\ViewSideChatRequest;
use App\Http\Requests\Whiteboard\ManageWhiteboardRequest;
use App\Http\Requests\Whiteboard\StoreWhiteboardStrokeRequest;
use App\Http\Requests\Whiteboard\UpdateWhiteboardStrokeRequest;
use App\Http\Resources\WhiteboardStrokeResource;
use App\Models\SideChat;
use App\Models\WhiteboardStroke;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * A side chat's shared whiteboard — the persistent, collaborative half of the workspace.
 *
 * Only the durable operations live here: the full board (index), and the three ways it
 * changes (commit a stroke, erase one, clear the lot). The high-frequency half — the live
 * drag and the moving cursor — never reaches Laravel; it rides over Reverb whispers on the
 * `sidechat.{id}` stream and expires, the same split threads/typing and the co-op games use.
 *
 * Reading the board is a channel-membership power (ViewSideChatRequest); drawing on it is a
 * roster power (ManageWhiteboardRequest), exactly the line join draws for posting messages.
 */
class WhiteboardController extends Controller
{
    /** The whole board — every committed stroke, in paint order. */
    public function index(ViewSideChatRequest $request, SideChat $sideChat): AnonymousResourceCollection
    {
        return WhiteboardStrokeResource::collection(
            $sideChat->whiteboardStrokes()->with('user')->get()
        );
    }

    public function store(StoreWhiteboardStrokeRequest $request, SideChat $sideChat): WhiteboardStrokeResource
    {
        $stroke = $sideChat->whiteboardStrokes()->create([
            'user_id' => $request->user()->id,
            'kind' => $request->validated('kind'),
            'payload' => $request->validated('payload'),
            'client_id' => $request->validated('client_id'),
        ]);

        // Broadcast to everyone (as MessageSent does); each client de-dupes by client_id,
        // so the drawer skips the echo of its own optimistic stroke.
        broadcast(new WhiteboardStrokeAdded($stroke));

        return new WhiteboardStrokeResource($stroke->load('user'));
    }

    /** Move or resize a stroke in place — a text label or sticky note the client dragged. */
    public function update(UpdateWhiteboardStrokeRequest $request, SideChat $sideChat, WhiteboardStroke $stroke): WhiteboardStrokeResource
    {
        abort_unless($stroke->side_chat_id === $sideChat->id, 404);

        $stroke->update(['payload' => $request->validated('payload')]);
        broadcast(new WhiteboardStrokeUpdated($stroke));

        return new WhiteboardStrokeResource($stroke->load('user'));
    }

    public function destroy(ManageWhiteboardRequest $request, SideChat $sideChat, WhiteboardStroke $stroke): Response
    {
        abort_unless($stroke->side_chat_id === $sideChat->id, 404);

        $stroke->delete();
        broadcast(new WhiteboardStrokeRemoved('sidechat.'.$sideChat->id, $stroke->id));

        return response()->noContent();
    }

    /** Wipe the board. Any participant may; it's a shared surface. */
    public function clear(ManageWhiteboardRequest $request, SideChat $sideChat): Response
    {
        $sideChat->whiteboardStrokes()->delete();
        broadcast(new WhiteboardCleared('sidechat.'.$sideChat->id));

        return response()->noContent();
    }
}
