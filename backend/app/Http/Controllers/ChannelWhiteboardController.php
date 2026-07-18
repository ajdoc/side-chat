<?php

namespace App\Http\Controllers;

use App\Events\WhiteboardCleared;
use App\Events\WhiteboardStrokeAdded;
use App\Events\WhiteboardStrokeRemoved;
use App\Events\WhiteboardStrokeUpdated;
use App\Http\Requests\Whiteboard\ChannelWhiteboardRequest;
use App\Http\Requests\Whiteboard\StoreChannelWhiteboardStrokeRequest;
use App\Http\Requests\Whiteboard\UpdateChannelWhiteboardStrokeRequest;
use App\Http\Resources\WhiteboardStrokeResource;
use App\Models\Channel;
use App\Models\WhiteboardStroke;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * A channel's shared whiteboard — the same persistent, collaborative board a side chat has
 * ({@see WhiteboardController}), hanging off a plain channel so any chat can sketch together.
 *
 * The only thing that differs is the gate: a channel has no roster, so membership is the
 * whole rule for both reading and drawing (ChannelWhiteboardRequest). Everything else — the
 * events, the resource, the whisper-driven live layer on the `channel.{id}` stream — is
 * shared with the side chat board.
 */
class ChannelWhiteboardController extends Controller
{
    /** The whole board — every committed stroke, in paint order. */
    public function index(ChannelWhiteboardRequest $request, Channel $channel): AnonymousResourceCollection
    {
        return WhiteboardStrokeResource::collection(
            $channel->whiteboardStrokes()->with('user')->get()
        );
    }

    public function store(StoreChannelWhiteboardStrokeRequest $request, Channel $channel): WhiteboardStrokeResource
    {
        $stroke = $channel->whiteboardStrokes()->create([
            'user_id' => $request->user()->id,
            'kind' => $request->validated('kind'),
            'payload' => $request->validated('payload'),
            'client_id' => $request->validated('client_id'),
        ]);

        // Broadcast to everyone; each client de-dupes by client_id, so the drawer skips the
        // echo of its own optimistic stroke.
        broadcast(new WhiteboardStrokeAdded($stroke));

        return new WhiteboardStrokeResource($stroke->load('user'));
    }

    /** Move or resize a stroke in place — a text label or sticky note the client dragged. */
    public function update(UpdateChannelWhiteboardStrokeRequest $request, Channel $channel, WhiteboardStroke $stroke): WhiteboardStrokeResource
    {
        abort_unless($stroke->channel_id === $channel->id, 404);

        $stroke->update(['payload' => $request->validated('payload')]);
        broadcast(new WhiteboardStrokeUpdated($stroke));

        return new WhiteboardStrokeResource($stroke->load('user'));
    }

    public function destroy(ChannelWhiteboardRequest $request, Channel $channel, WhiteboardStroke $stroke): Response
    {
        abort_unless($stroke->channel_id === $channel->id, 404);

        $stroke->delete();
        broadcast(new WhiteboardStrokeRemoved('channel.'.$channel->id, $stroke->id));

        return response()->noContent();
    }

    /** Wipe the board. Any member may; it's a shared surface. */
    public function clear(ChannelWhiteboardRequest $request, Channel $channel): Response
    {
        $channel->whiteboardStrokes()->delete();
        broadcast(new WhiteboardCleared('channel.'.$channel->id));

        return response()->noContent();
    }
}
