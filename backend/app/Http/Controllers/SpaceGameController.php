<?php

namespace App\Http\Controllers;

use App\Http\Requests\SideSpace\ProposeSpaceGameRequest;
use App\Http\Requests\SideSpace\ShowSideSpaceMapRequest;
use App\Http\Requests\SideSpace\SpaceGameActionRequest;
use App\Http\Requests\SideSpace\VoteSpaceGameRequest;
use App\Models\Channel;
use App\Models\SpaceGame;
use App\Models\VoiceParticipant;
use App\Services\Games\GameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Games in a Side Space: proposing one, voting it in, and playing it.
 *
 * The thin HTTP skin over {@see GameService}. Every endpoint here answers with *this caller's
 * view* of the game — the redaction that keeps the impostor secret happens on the way out, per
 * request, which is why even reading the game goes through the service rather than a plain
 * resource. Movement during a game is still whispered peer-to-peer like any other movement; only
 * the game's own moves come through here.
 */
class SpaceGameController extends Controller
{
    public function __construct(private readonly GameService $games) {}

    /**
     * The games that can be proposed. Global — the catalogue is the same in every room — so it
     * needs nothing but a logged-in caller.
     */
    public function catalogue(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->games->catalogue()]);
    }

    /** This caller's view of the room's game, or `{game: null}` when there isn't one. */
    public function show(ShowSideSpaceMapRequest $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->isSpace(), 404);

        return response()->json(['data' => $this->games->present($channel, $request->user())]);
    }

    public function propose(ProposeSpaceGameRequest $request, Channel $channel): JsonResponse
    {
        $this->requireInRoom($request, $channel);

        $opponent = $request->input('opponent');

        $this->games->propose(
            $channel,
            $request->user(),
            $request->string('type'),
            $opponent !== null ? (int) $opponent : null,
        );

        return $this->presented($request, $channel);
    }

    public function vote(VoteSpaceGameRequest $request, Channel $channel): JsonResponse
    {
        $this->requireInRoom($request, $channel);

        $this->games->vote($this->gameFor($channel), $request->user(), $request->boolean('vote'));

        return $this->presented($request, $channel);
    }

    public function act(SpaceGameActionRequest $request, Channel $channel): JsonResponse
    {
        $this->requireInRoom($request, $channel);

        $this->games->act(
            $this->gameFor($channel),
            $request->user(),
            $request->string('action'),
            $request->array('payload'),
        );

        return $this->presented($request, $channel);
    }

    public function cancel(ShowSideSpaceMapRequest $request, Channel $channel): JsonResponse
    {
        $this->requireInRoom($request, $channel);

        $this->games->cancel($this->gameFor($channel));

        return $this->presented($request, $channel);
    }

    /** The room's game or a 404 — you can't vote on or play a game that isn't there. */
    private function gameFor(Channel $channel): SpaceGame
    {
        abort_unless($channel->isSpace(), 404);

        $game = $channel->spaceGame;
        abort_if($game === null, 404);

        return $game;
    }

    /**
     * Only people standing in the room take part.
     *
     * Membership (checked by the request) lets you *read*; being in the room is what lets you
     * propose, vote and play — the electorate and the players are the room's occupants, and a
     * member reading the timeline from another channel is neither. Enforced here rather than in
     * the request because it's a fact about the call roster, not about the route.
     */
    private function requireInRoom(Request $request, Channel $channel): void
    {
        $inRoom = VoiceParticipant::query()
            ->where('channel_id', $channel->id)
            ->where('user_id', $request->user()?->id)
            ->exists();

        abort_unless($inRoom, 403, 'You have to be in the room to do that.');
    }

    private function presented(Request $request, Channel $channel): JsonResponse
    {
        return response()->json(['data' => $this->games->present($channel->refresh(), $request->user())]);
    }
}
