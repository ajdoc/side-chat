<?php

namespace App\Http\Controllers;

use App\Events\SideSpaceMapUpdated;
use App\Http\Requests\SideSpace\InteractWithSpaceObjectRequest;
use App\Http\Requests\SideSpace\ShowSideSpaceMapRequest;
use App\Http\Requests\SideSpace\UpdateSideSpaceMapRequest;
use App\Http\Requests\SideSpace\UpdateSpaceObjectsRequest;
use App\Http\Requests\SideSpace\UpdateSpacePositionRequest;
use App\Http\Resources\SideSpaceMapResource;
use App\Http\Resources\WidgetResource;
use App\Models\Channel;
use App\Models\SideSpaceMap;
use App\Models\VoiceParticipant;
use App\Services\Widgets\WidgetService;
use App\Support\SideSpace\Decorations;
use App\Support\SideSpace\MapPresets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

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
    public function __construct(private readonly WidgetService $widgets) {}

    /**
     * The rooms a Side Space can be built as — read when creating a channel, and again by the
     * editor when somebody swaps an existing room's layout. Needs nothing but a logged-in
     * caller: presets are the same for everybody.
     *
     * Every preset travels *whole*, spawn and all, because the editor doesn't merely draw these —
     * it loads one over the room it's editing, and a layout arriving without its entrance would
     * leave people walking in wherever the old room's door happened to be.
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
                'objects' => $preset['objects'],
                'spawn' => $preset['spawn'],
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
            // Optional in the request, so absent means "unfurnished" rather than "unchanged" —
            // a save is a whole room, and half-saving one would leave furniture floating over a
            // floor plan that no longer has a wall under it.
            'objects' => $request->validated('objects', []),
            'spawn' => $request->validated('spawn'),
            'updated_by' => $request->user()?->id,
        ]);

        broadcast(new SideSpaceMapUpdated($map));

        return new SideSpaceMapResource($map->load('editor'));
    }

    /**
     * Rearrange the furniture. Any member — see {@see UpdateSpaceObjectsRequest} for why this is
     * open where rebuilding the room is not.
     *
     * The furniture is checked against the map's *stored* tiles rather than any it sent, because
     * it sent none: a member can move a couch but not the wall behind it. Everything else about
     * the map — its geometry, its name, who last rebuilt it — is left exactly as it was, so this
     * can't be a way to smuggle a geometry change past the owner-only gate.
     */
    public function objects(UpdateSpaceObjectsRequest $request, Channel $channel): SideSpaceMapResource
    {
        $map = $this->mapFor($channel);
        $objects = array_values($request->validated('objects', []));

        $problems = Decorations::problems($objects, $map->tiles ?? [], $map->width, $map->height);

        if ($problems !== []) {
            throw ValidationException::withMessages(
                array_combine(
                    array_map(fn ($i) => "objects.$i", array_keys($problems)),
                    array_map(fn ($m) => [$m], $problems),
                )
            );
        }

        // Only the furniture, and not `updated_by` — that names whoever last rebuilt the *room*,
        // and moving a chair is not rebuilding the room.
        $map->update(['objects' => $objects]);

        broadcast(new SideSpaceMapUpdated($map->load('editor')));

        return new SideSpaceMapResource($map);
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
     * Use a piece of furniture: the speaker, the TV, the arcade cabinet in the corner.
     *
     * What comes back is a **widget** — the channel's one music player, its one video player,
     * the same shared object `m!` or `v!` would have reached, created on first use with the
     * handler's own initial state. That's the entire trick behind interactive furniture: the
     * room doesn't gain a music player, it gains a *door* to the one the channel already has.
     * So listening along, the queue, permissions and the floating window all work already, and
     * two people pressing E on the same speaker are unmistakably in the same session.
     *
     * The map decides what a given object opens, not the caller. See
     * {@see InteractWithSpaceObjectRequest}.
     */
    public function interact(InteractWithSpaceObjectRequest $request, Channel $channel): WidgetResource
    {
        $map = $this->mapFor($channel);
        $id = $request->validated('object_id');

        $object = collect($map->objects ?? [])->firstWhere('id', $id);
        $kind = $object !== null ? Decorations::find((string) ($object['kind'] ?? '')) : null;

        // No such object, or one that doesn't do anything. Both are "there is nothing here to
        // use" — the room may have been rebuilt between the prompt appearing and E being
        // pressed, which is a 404 rather than anybody's mistake.
        abort_if($kind === null || $kind['interact'] === null, 404);

        $widget = $this->widgets->ensure($channel, $request->user(), $kind['interact']);

        abort_if($widget === null, 404);

        return new WidgetResource($widget);
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
