<?php

namespace App\Http\Controllers;

use App\Events\SideSpaceMapUpdated;
use App\Http\Requests\SideSpace\AssignSpaceRoomOwnerRequest;
use App\Http\Requests\SideSpace\DestroySpaceLockRequest;
use App\Http\Requests\SideSpace\EnterSpaceLockRequest;
use App\Http\Requests\SideSpace\IndexSpaceLocksRequest;
use App\Http\Requests\SideSpace\InteractWithSpaceObjectRequest;
use App\Http\Requests\SideSpace\StoreSpaceLockRequest;
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
use App\Support\DeskApps;
use App\Models\SideSpaceLock;
use App\Models\SideSpaceRoom;
use App\Models\User;
use App\Support\SideSpace\Decorations;
use App\Support\SideSpace\Doors;
use App\Support\SideSpace\MapPresets;
use App\Support\SideSpace\RoomPresets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
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
                // Which heading the picker files it under — see MapPresets::GROUPS. Sent rather
                // than inferred client-side so the two lists can't drift over what "Themed" is.
                'group' => MapPresets::groupOf($key),
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

    /**
     * The ways a room drawn inside a map can be furnished.
     *
     * Deliberately a separate list from {@see self::presets()} above, not a section of it: a map
     * preset replaces a whole Side Space and a room preset fills a rectangle inside one, and the
     * two have different shapes (no size, no walls, no entrance here) because they answer
     * different questions. Kept on the server for the same reason the other one is — the client
     * has to be *given* a room to stamp rather than inventing geometry we'd then have to trust.
     */
    public function roomPresets(): JsonResponse
    {
        $presets = [];

        foreach (RoomPresets::all() as $key => $preset) {
            $presets[] = ['key' => $key, ...$preset];
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
     * Use a piece of furniture: the speaker, the TV, the whiteboard in the corner.
     *
     * What comes back is a **door to something the channel already has**, and which kind of
     * door depends on the app the furniture points at:
     *
     *   - a widget app answers with the channel's one music player, its one video player — the
     *     same shared object `m!` or `v!` would have reached, created on first use with the
     *     handler's own initial state.
     *   - a surface app answers with the app's *name*, because the board, the notes and the
     *     calendar have no widget row: they hang off the channel's Side Desk, and the client
     *     floats the very panel `a!board` would have opened.
     *
     * Either way the room gains no state of its own. That's the entire trick behind interactive
     * furniture, and it's why two people pressing E on the same speaker are unmistakably in the
     * same session, and why drawing on the whiteboard in the room shows up on the Board tab of
     * everyone who never walked in.
     *
     * The map decides what a given object opens, not the caller. See
     * {@see InteractWithSpaceObjectRequest}.
     */
    public function interact(InteractWithSpaceObjectRequest $request, Channel $channel): JsonResponse
    {
        $map = $this->mapFor($channel);
        $id = $request->validated('object_id');

        $object = collect($map->objects ?? [])->firstWhere('id', $id);
        $kind = $object !== null ? Decorations::find((string) ($object['kind'] ?? '')) : null;

        // No such object, or one that doesn't do anything. Both are "there is nothing here to
        // use" — the room may have been rebuilt between the prompt appearing and E being
        // pressed, which is a 404 rather than anybody's mistake.
        abort_if($kind === null || $kind['interact'] === null, 404);

        $app = $kind['interact'];

        // A surface app is opened by name. Nothing is created here, because there is nothing to
        // create: the board exists as soon as somebody draws on it, and the client already
        // knows how to float one from the channel it's standing in.
        if (! in_array($app, DeskApps::WIDGET_APPS, true)) {
            abort_unless(in_array($app, DeskApps::all(), true), 404);

            return response()->json(['type' => 'app', 'app' => $app]);
        }

        $widget = $this->widgets->ensure($channel, $request->user(), $app);

        abort_if($widget === null, 404);

        return response()->json([
            'type' => 'widget',
            'app' => $app,
            'data' => (new WidgetResource($widget))->resolve(),
        ]);
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

        // Rooms and locks ride along with every read of the map. The browser needs them to
        // decide whether a door opens, which is a question it answers per frame and cannot go
        // and ask about. See SideSpaceMapResource.
        return $map->load('rooms.owner', 'locks.creator');
    }

    // --- rooms and their doors ---

    /**
     * Set who is in charge of a room — none, one, or several people.
     *
     * Server owner only ({@see AssignSpaceRoomOwnerRequest}), and the root of every other
     * permission in this file: a room owner may lock their room's doors, so being able to
     * appoint one is being able to lock anything.
     *
     * Takes the whole set and replaces it, rather than adding or removing one at a time. Two
     * reasons, and the second is the one that matters: it makes "remove Alice" the same call as
     * "add Bob", so there is one code path instead of three; and it means two people editing the
     * list can't interleave into a state neither of them asked for — the last writer's list is
     * simply the list.
     *
     * The zone has to exist *now* — appointing somebody to a room that was erased is a row that
     * could never resolve, and refusing it is a much clearer answer than accepting it and having
     * nothing happen. Owners have to be members for the same reason a lock's key-holders do: an
     * owner who can't get into the server can't get into the room.
     */
    public function assignRoom(AssignSpaceRoomOwnerRequest $request, Channel $channel, string $zone): JsonResponse
    {
        $map = $this->mapFor($channel);

        abort_unless(collect($map->zones ?? [])->contains(fn ($z) => (string) ($z['id'] ?? '') === $zone), 404);

        $ownerIds = collect($request->validated('owner_ids', []))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        // Every one of them, before any of them: a partly-applied list would leave the room in a
        // state the caller never asked for and can only fix by guessing what landed.
        $members = User::query()->whereIn('id', $ownerIds)->get();

        abort_unless(
            $members->count() === $ownerIds->count() && $members->every(fn ($u) => $channel->hasMember($u)),
            422,
        );

        // Replace the set. Rows for people no longer on the list go, rather than lingering as
        // owners nobody meant to leave in charge.
        SideSpaceRoom::query()
            ->where('side_space_map_id', $map->id)
            ->where('zone_id', $zone)
            ->whereNotIn('owner_id', $ownerIds)
            ->delete();

        foreach ($ownerIds as $ownerId) {
            SideSpaceRoom::firstOrCreate(
                ['side_space_map_id' => $map->id, 'zone_id' => $zone, 'owner_id' => $ownerId],
                ['assigned_by' => $request->user()?->id],
            );
        }

        return $this->announce($map);
    }

    /**
     * Lock a door, or change who may come through one that's already locked.
     *
     * Two gates, and only the first is in the request class. Membership gets you here; whether
     * you may lock *this* door depends on the room it turns out to guard, which needs the map,
     * so it's asked here — of the same {@see Doors::mayAdminister} the listing and the removal
     * ask, because three different answers to "is this your door" is three different bugs.
     *
     * The room is worked out once, now, and stored on the lock. It is not recomputed later: a
     * wall that moves must not quietly transfer somebody's lock to a room they have nothing to
     * do with.
     */
    public function lockDoor(StoreSpaceLockRequest $request, Channel $channel, string $object): JsonResponse
    {
        $map = $this->mapFor($channel);
        $door = Doors::all($map)[$object] ?? null;

        // Not a door, or not there any more. The room may have been rebuilt between the panel
        // being drawn and the button being pressed, which is a 404 rather than anybody's mistake.
        abort_if($door === null, 404);

        $zone = Doors::zoneFor($map, $door);

        abort_unless(Doors::mayAdminister($map, $request->user(), $zone), 403);

        $attributes = [
            'zone_id' => $zone,
            'created_by' => $request->user()?->id,
            // Only the explicit key-holders. The three who can always pass are resolved on
            // the way out — see Doors::keyholders.
            'allowed' => array_values(array_unique(array_map('intval', $request->validated('allowed', [])))),
        ];

        /*
         * The password, which is three states and not two — see StoreSpaceLockRequest.
         *
         * A request that doesn't mention it leaves it alone, because the ordinary edit here is
         * "give Alice a key" and that must not clear the door's phrase as a side effect.
         *
         * Setting or clearing it also forgets everybody who had entered the old one. That's the
         * only thing that makes changing a password mean anything: if the people who knew the
         * previous phrase kept walking through, changing it would shut out nobody at all.
         */
        if ($request->has('password')) {
            $password = $request->input('password');
            $attributes['password'] = $password === null || $password === '' ? null : $password;
            $attributes['passed'] = [];
        }

        SideSpaceLock::updateOrCreate(
            ['side_space_map_id' => $map->id, 'object_id' => $object],
            $attributes,
        );

        return $this->announce($map);
    }

    /**
     * Say the password at a door, and get through it if it's right.
     *
     * The other half of what a lock is for. A key-holder list can only let in people the room's
     * owner could name in advance; a password lets in anybody who was told it, which is how most
     * private rooms actually work — the phrase goes in a pinned message, and whoever reads it
     * comes in.
     *
     * Two decisions worth stating:
     *
     *   - **the answer is not remembered for long**. Saying the words buys a pass measured in
     *     seconds ({@see SideSpaceLock::PASS_SECONDS}), enough to walk through the door in front
     *     of you and no more; coming back out means saying them again. A password is something
     *     you present at a door, not a key you keep, and the version that was kept meant somebody
     *     told the code in March still had the run of the room in July.
     *   - **the pass rides in the map as a deadline**, not as a name on the key-holder list, so
     *     every browser in the room closes the door again at the same moment without being told.
     *     Setting a new password still clears the outstanding passes ({@see lockDoor}) — they are
     *     seconds from lapsing anyway, but the door should shut the instant the phrase changes.
     *   - **a wrong guess is a 422**, the same shape as any other rejected input, and the door
     *     stays exactly as it was. Guessing is throttled on the route, because what needs
     *     limiting is the rate of attempts at a door and not anything about one request.
     *
     * Answers on a door that will already open for you rather than making you say the words
     * anyway: the end state asked for is the end state, and a key-holder typing the password is
     * not wrong, just redundant.
     */
    public function enterDoor(EnterSpaceLockRequest $request, Channel $channel, string $object): JsonResponse
    {
        $map = $this->mapFor($channel);
        $lock = $map->locks->firstWhere('object_id', $object);
        $user = $request->user();

        // No lock, or no password on it: there is nothing here a password could open. 404 rather
        // than a polite success, because a client that asked has a stale idea of the door.
        abort_if($lock === null || ! $lock->hasPassword(), 404);
        abort_if($user === null, 403);

        // Judged on the standing keys alone, not on an outstanding pass: somebody whose pass is
        // about to run out saying the words again is asking for another one, and answering "you
        // may already pass" would leave them the two seconds they had left.
        if (in_array($user->id, Doors::granted($map, $lock), true)) {
            return $this->announce($map);
        }

        if (! Hash::check((string) $request->validated('password'), $lock->password)) {
            throw ValidationException::withMessages(['password' => 'That is not the password.']);
        }

        $lock->grantPass($user->id);

        return $this->announce($map);
    }

    /**
     * Unlock a door.
     *
     * Deleting the row *is* unlocking: there is no such thing as an unlocked lock, so a flag
     * would only be a second way to spell "no row".
     *
     * Answers on a door that is already unlocked rather than 404ing — the end state the caller
     * asked for is the end state they get, and racing two people pressing unlock should not make
     * one of them wrong.
     */
    public function unlockDoor(DestroySpaceLockRequest $request, Channel $channel, string $object): JsonResponse
    {
        $map = $this->mapFor($channel);
        $lock = $map->locks->firstWhere('object_id', $object);

        if ($lock === null) {
            return $this->announce($map);
        }

        // Judged on the room the lock was *set* against, not on where the door is now: that is
        // the room whose owner took responsibility for it, whatever has been built since.
        abort_unless(Doors::mayAdminister($map, $request->user(), $lock->zone_id), 403);

        $lock->delete();

        return $this->announce($map);
    }

    /**
     * The locks somebody is entitled to manage.
     *
     * The scoping *is* the feature:
     *
     *   - the **server's owner** sees every lock in the space, theirs and everybody else's. It's
     *     their server, and a lock they can't see is a room they can't get back.
     *   - a **room owner** sees the locks they set. Not every lock in their room — a lock the
     *     server owner put on their door is not theirs to remove, and listing it would only
     *     offer them a button that 403s.
     *   - **everybody else** sees an empty list.
     *
     * Stale rows are included and flagged rather than hidden. A lock whose door has since been
     * taken out of the wall is exactly the thing somebody needs to see in order to tidy it up;
     * silently filtering it would leave a row nobody could reach.
     */
    public function locks(IndexSpaceLocksRequest $request, Channel $channel): JsonResponse
    {
        $map = $this->mapFor($channel);
        $user = $request->user();
        $isServerOwner = Doors::isServerOwner($map, $user);

        $rows = $map->locks
            ->when(! $isServerOwner, fn ($locks) => $locks->where('created_by', $user?->id))
            ->values();

        $doors = Doors::all($map);
        $zones = collect($map->zones ?? [])->keyBy('id');
        $names = User::query()
            ->whereIn('id', $rows->flatMap(fn ($lock) => Doors::granted($map, $lock))->unique())
            ->pluck('name', 'id');

        return response()->json([
            'data' => $rows->map(fn (SideSpaceLock $lock) => [
                'object_id' => $lock->object_id,
                'door' => Decorations::find((string) ($doors[$lock->object_id]['kind'] ?? ''))['label'] ?? null,
                // Null once the door has been removed from the map — the row is stale, and the
                // client says so rather than drawing a lock on nothing.
                'present' => isset($doors[$lock->object_id]),
                'zone_id' => $lock->zone_id,
                'room' => $zones[$lock->zone_id]['name'] ?? null,
                'created_by' => $lock->creator?->name,
                'mine' => $lock->created_by === $user?->id,
                // Everybody who holds a standing key, resolved — for showing. Not the people
                // currently through on a password: those lapse in seconds, and a panel listing
                // them beside the key-holders would read as "the owner gave this person a key".
                'allowed' => collect(Doors::granted($map, $lock))
                    ->map(fn (int $id) => ['id' => $id, 'name' => $names[$id] ?? null])
                    ->values(),
                /*
                 * The keys actually *stored* on this lock — for editing.
                 *
                 * Sent apart from `allowed` because the two are different questions and the
                 * client needs both. Editing must send back the explicit list alone: fold the
                 * resolved people into it and the room's current owners get written into the row,
                 * where they'd stay as standing keys after the room changed hands. Deriving one
                 * from the other client-side means guessing which of the names it can see were
                 * granted, which is exactly the sort of guess that quietly stops being right.
                 */
                'granted' => array_values(array_map('intval', $lock->allowed ?? [])),
                // Whether a password is set, and how many people are through on one at this
                // instant. Never the phrase itself — it isn't stored in a form anybody could be
                // shown, including its owner, which is why the panel offers "change it" and
                // never "what is it".
                'has_password' => $lock->hasPassword(),
                'passed_count' => count($lock->activePasses()),
                'created_at' => $lock->created_at,
            ]),
            // What the panel needs to know about *itself*: whether to offer the rooms tab, and
            // which rooms this person may lock doors in.
            'can_manage_rooms' => $isServerOwner,
            'my_rooms' => $isServerOwner
                ? collect($map->zones ?? [])->pluck('id')->values()
                : $map->rooms->where('owner_id', $user?->id)->pluck('zone_id')->unique()->values(),
        ]);
    }

    /**
     * Hand back the map, and tell everyone standing in it.
     *
     * Every write above changes what a door will do, and a door that opens on one screen and not
     * another is the one failure this whole feature has to avoid. So they all broadcast the map,
     * exactly as moving a couch does — the locks travel inside it.
     *
     * ## Why it touches the map first
     *
     * The broadcast is only a ping — an id and a version — because a furnished room is far past
     * the websocket's frame limit (see SideSpaceMapUpdated). Clients compare that version against
     * the map they already hold and refetch only when it differs, which is what stops a save
     * echoing back to its own author as a pointless round trip.
     *
     * But every write here changes a *different table*: rooms and locks hang off the map and
     * leave its own `updated_at` exactly where it was. So the ping went out carrying a version
     * everybody already had, every listener concluded it had nothing to learn, and the new lock
     * never reached a single screen — the map only ever caught up when something else happened
     * to save the room. Locking a door, handing out a key and saying a password all looked like
     * they had done nothing at all.
     *
     * Touching the map is what makes the version mean "the room as the browsers see it", locks
     * included, rather than "the tiles". Cheap, and nothing at 60fps is behind it: these are the
     * rare, deliberate writes, and walking never comes through here.
     */
    private function announce(SideSpaceMap $map): JsonResponse
    {
        $map->touch();

        $fresh = $map->fresh(['rooms.owner', 'locks.creator', 'editor']);

        broadcast(new SideSpaceMapUpdated($fresh));

        return response()->json(['data' => (new SideSpaceMapResource($fresh))->resolve()]);
    }
}
