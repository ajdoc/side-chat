<?php

namespace App\Http\Controllers;

use App\Http\Requests\SideSpace\StoreArpgCharacterRequest;
use App\Http\Resources\ArpgCharacterResource;
use App\Models\ArpgCharacter;
use App\Services\Games\ArpgGame;
use App\Support\Arpg\Jobs;
use App\Support\Arpg\Skills;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Your dungeon heroes — the roster you pick from before stepping through a portal.
 *
 * Sibling to {@see SpaceAppearanceController}: no channel in any of these paths, because a
 * character belongs to a player rather than to a room. You roll one here and take it down
 * whichever Side Space's Labyrinth you happen to walk into.
 *
 * "Which hero am I playing" is deliberately not a field on this API but an *ordering*: the one
 * you played most recently is the one a portal seats you with ({@see ArpgGame::currentCharacter}),
 * and `select` is nothing more than saying you played this one just now. That way there is no
 * second piece of state to keep in step with the runs themselves.
 */
class ArpgCharacterController extends Controller
{
    public function __construct(private readonly ArpgGame $arpg) {}

    /**
     * The whole skill catalogue, plus the rules the client needs to grey a button out.
     *
     * Global — skills are the same for everyone — so it needs nothing but a logged-in caller,
     * like the game catalogue beside it.
     */
    public function skills(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Skills::payload(),
            'meta' => [
                'classes' => array_keys(ArpgGame::CLASSES),
                // The job tree, so a skill screen can group by line and name what you'll become.
                'jobs' => Jobs::all(),
                // Per tier — advancing opens a fresh allowance rather than spending the old one.
                'foreign_limits' => (array) config('arpg.foreign_skill_limits', []),
                'max_skill_level' => (int) config('arpg.max_skill_level', 10),
            ],
        ]);
    }

    /** Your roster, the one you'd take in next at the top. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $characters = ArpgCharacter::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_played_at')
            ->orderByDesc('id')
            ->get();

        return ArpgCharacterResource::collection($characters);
    }

    /** Roll a new one. */
    public function store(StoreArpgCharacterRequest $request): ArpgCharacterResource
    {
        // Six is plenty to try every class twice, and it keeps the select screen a screen.
        $count = ArpgCharacter::query()->where('user_id', $request->user()->id)->count();

        if ($count >= 6) {
            throw ValidationException::withMessages(['name' => 'You already have six heroes. Retire one first.']);
        }

        $name = trim($request->string('name'));

        if (ArpgCharacter::query()->where('user_id', $request->user()->id)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => 'You already have a hero by that name.']);
        }

        return new ArpgCharacterResource(
            $this->arpg->roll($request->user(), $name, $request->string('class'))
        );
    }

    /** Take this one in next. */
    public function select(Request $request, ArpgCharacter $character): ArpgCharacterResource
    {
        $this->requireOwn($request, $character);

        $character->update(['last_played_at' => now()]);

        return new ArpgCharacterResource($character);
    }

    /**
     * Spend a skill point, from the character screen rather than mid-dungeon.
     *
     * The rules — the level it opens at, the ceiling, and the cap on skills borrowed from other
     * classes — are all {@see ArpgGame::learn}'s, which is also what the in-run `learn` action
     * calls. One answer to "may I learn this", wherever it's asked.
     */
    public function learn(Request $request, ArpgCharacter $character): ArpgCharacterResource
    {
        $this->requireOwn($request, $character);

        return new ArpgCharacterResource(
            $this->arpg->learn($character, (string) $request->input('skill'))
        );
    }

    /**
     * Take the next job in the line, from the character screen.
     *
     * A thing you do rather than something that happens to you — see {@see ArpgGame::advance} for
     * why, and for the level gate.
     */
    public function advance(Request $request, ArpgCharacter $character): ArpgCharacterResource
    {
        $this->requireOwn($request, $character);

        return new ArpgCharacterResource($this->arpg->advance($character));
    }

    /** Retire a hero, and everything they were carrying. */
    public function destroy(Request $request, ArpgCharacter $character): JsonResponse
    {
        $this->requireOwn($request, $character);

        $character->delete();

        return response()->json(status: 204);
    }

    /** Somebody else's hero is not yours to play or to retire. */
    private function requireOwn(Request $request, ArpgCharacter $character): void
    {
        abort_unless($character->user_id === $request->user()?->id, 403);
    }
}
