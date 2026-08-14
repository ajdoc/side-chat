<?php

namespace App\Http\Controllers;

use App\Events\BoardLayersSaved;
use App\Http\Requests\SideChat\ViewSideChatRequest;
use App\Http\Requests\Whiteboard\ChannelBoardLayersRequest;
use App\Http\Requests\Whiteboard\ChannelWhiteboardRequest;
use App\Http\Requests\Whiteboard\SideChatBoardLayersRequest;
use App\Models\Channel;
use App\Models\SideChat;
use Illuminate\Http\JsonResponse;

/**
 * A board's layers — what they're called and whether they're shown.
 *
 * One controller for both kinds of surface, exactly as {@see DeskAppsController} is and for the
 * same reason: this is a single JSON column with the same handling either side, and the
 * differing authorisation already lives in the request classes.
 *
 * The array index *is* the layer number that strokes carry, so reordering layers is not
 * supported here — it would silently renumber every stroke on the board. Layers are added,
 * renamed, hidden and shown; moving a mark between them is a stroke-level edit.
 *
 * Null means "never used layers" and is returned as null, not as a default: the client renders
 * that as the single unnamed layer every existing board already has.
 */
class BoardLayerController extends Controller
{
    public function showChannel(ChannelWhiteboardRequest $request, Channel $channel): JsonResponse
    {
        return response()->json(['layers' => $channel->board_layers]);
    }

    public function updateChannel(ChannelBoardLayersRequest $request, Channel $channel): JsonResponse
    {
        $layers = array_values($request->validated('layers'));

        $channel->update(['board_layers' => $layers]);
        broadcast(new BoardLayersSaved('channel.'.$channel->id, $layers))->toOthers();

        return response()->json(['layers' => $layers]);
    }

    public function showSideChat(ViewSideChatRequest $request, SideChat $sideChat): JsonResponse
    {
        return response()->json(['layers' => $sideChat->board_layers]);
    }

    public function updateSideChat(SideChatBoardLayersRequest $request, SideChat $sideChat): JsonResponse
    {
        $layers = array_values($request->validated('layers'));

        $sideChat->update(['board_layers' => $layers]);
        broadcast(new BoardLayersSaved('sidechat.'.$sideChat->id, $layers))->toOthers();

        return response()->json(['layers' => $layers]);
    }
}
