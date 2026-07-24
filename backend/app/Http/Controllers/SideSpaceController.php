<?php

namespace App\Http\Controllers;

use App\Events\SideSpaceMapUpdated;
use App\Http\Requests\SideSpace\ShowSideSpaceMapRequest;
use App\Http\Requests\SideSpace\UpdateSideSpaceMapRequest;
use App\Http\Requests\SideSpace\UpdateSpacePositionRequest;
use App\Http\Resources\SideSpaceMapResource;
use App\Models\Channel;
use App\Models\SideSpaceMap;
use App\Models\VoiceParticipant;
use App\Support\SideSpace\MapPresets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * A Side Space's room: reading the map, rebuilding it, and remembering where people stood.
 *
 * Deliberately *not* where anybody's movement goes. Walking around is whispered peer-to-peer
 * over the room's presence channel many times a second and never touches this server — the
 * same arrangement the whiteboard's live strokes use, and for the same reason. What's here is
 * the slow, durable half: the geometry everyone renders, and the one position per person that
 * survives a reload.
 */
class SideSpaceController extends Controller
{
    /**
     * The rooms a new Side Space can be created as. Read by the channel-creation page, so it
     * needs nothing but a logged-in caller — presets are the same for everybody.
     */
    public function presets(): JsonResponse
    {
        $presets = [];

        foreach (MapPresets::all() as $key => $preset) {
            $presets[] = [
                'key' => $key,
                'label' => $preset['label'],
                'description' => $preset['description'],
                'width' => $preset['width'],
                'height' => $preset['height'],
                // The grid itself, so the picker can draw a real thumbnail of the room rather
                // than a stock illustration of one.
                'tiles' => $preset['tiles'],
                'zones' => $preset['zones'],
            ];
        }

        return response()->json(['data' => $presets]);
    }

    /** The channel's map, whole. */
    public function show(ShowSideSpaceMapRequest $request, Channel $channel): SideSpaceMapResource
    {
        return new SideSpaceMapResource($this->mapFor($channel));
    }

    /**
     * Rebuild the room. Owner only (see the request), and broadcast to everyone standing in it
     * — a wall nobody else knows about is a wall that only stops the person who painted it.
     */
    public function update(UpdateSideSpaceMapRequest $request, Channel $channel): SideSpaceMapResource
    {
        $map = $this->mapFor($channel);

        $map->update([
            'name' => $request->validated('name'),
            'width' => $request->validated('width'),
            'height' => $request->validated('height'),
            'tiles' => $request->validated('tiles'),
            'zones' => $request->validated('zones'),
            'spawn' => $request->validated('spawn'),
            'updated_by' => $request->user()?->id,
        ]);

        broadcast(new SideSpaceMapUpdated($map));

        return new SideSpaceMapResource($map->load('editor'));
    }

    /**
     * Remember where somebody is standing.
     *
     * Silent on purpose: no broadcast, no event. Everyone who needs to know where you are is
     * already being told by your whispers, several times a second, for free. This exists only
     * so that closing the tab and coming back doesn't put you at the front door — which makes
     * it the rare write worth doing on a long throttle and worth nobody hearing about.
     *
     * A position for somebody who isn't in the room is stale rather than wrong (they walked out
     * between the throttle firing and the request landing), so it's dropped quietly.
     */
    public function position(UpdateSpacePositionRequest $request, Channel $channel): Response
    {
        abort_unless($channel->isSpace(), 404);

        VoiceParticipant::query()
            ->where('channel_id', $channel->id)
            ->where('user_id', $request->user()?->id)
            ->update([
                'x' => $request->validated('x'),
                'y' => $request->validated('y'),
                'facing' => $request->validated('facing'),
            ]);

        return response()->noContent();
    }

    /**
     * The channel's map, or a 404.
     *
     * Two ways to miss: the channel isn't a Side Space at all (so there is no room to ask
     * about), or it is one whose map never got seeded. Both are "no such thing here", which is
     * what a 404 says — and neither is something a client can fix by authenticating differently,
     * which is why this isn't a 403.
     */
    private function mapFor(Channel $channel): SideSpaceMap
    {
        abort_unless($channel->isSpace(), 404);

        $map = $channel->spaceMap;

        abort_if($map === null, 404);

        return $map;
    }
}
