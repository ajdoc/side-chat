<?php

namespace App\Http\Controllers;

use App\Models\MobaMatch;
use App\Models\MobaProfile;
use App\Models\MobaQueueEntry;
use App\Services\Moba\QueueService;
use App\Support\Moba\Heroes;
use App\Support\Moba\MatchTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The metagame API: the roster, the queue, and the ticket into a match.
 *
 * Everything here is *around* a match. Nothing in this file knows what a hero does or how a lane
 * works — that is all in the Rust simulation, which this controller's job is to get people into.
 */
class MobaController extends Controller
{
    public function __construct(private readonly QueueService $queue) {}

    /** The roster and the modes, for the lobby screen. */
    public function catalogue(): JsonResponse
    {
        return response()->json([
            'heroes' => Heroes::all(),
            // 1v1 through 5v5. A real mode rather than a testing affordance — the sim scales
            // waves and structures to it, so a 1v1 is a game rather than a 5v5 with eight
            // empty seats. See MOBA.md.
            'team_sizes' => [1, 2, 3, 4, 5],
        ]);
    }

    /** Where this player stands, and what they are currently doing. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = MobaProfile::forUser($user);
        $entry = MobaQueueEntry::where('user_id', $user->getKey())->first();

        return response()->json([
            'mmr' => $profile->mmr,
            'games' => $profile->games,
            'wins' => $profile->wins,
            'queued' => $entry !== null,
            'queued_size' => $entry?->team_size,
            'match' => $this->matchPayload($request, $this->queue->liveMatchFor($user)),
        ]);
    }

    /**
     * Join the queue, and try to form a match immediately.
     *
     * Forming here rather than on a schedule means the last player to arrive completes the match
     * on their own request, instead of watching a spinner until the next tick of a job that runs
     * every few seconds.
     */
    public function join(Request $request): JsonResponse
    {
        $data = $request->validate([
            'team_size' => ['required', 'integer', 'min:1', 'max:5'],
            'hero' => ['required', 'string'],
            'channel_id' => ['nullable', 'integer', 'exists:channels,id'],
        ]);

        $this->queue->join(
            $request->user(),
            (int) $data['team_size'],
            $data['hero'],
            isset($data['channel_id']) ? (int) $data['channel_id'] : null,
        );
        $this->queue->formMatches();

        return $this->me($request);
    }

    public function leave(Request $request): JsonResponse
    {
        $this->queue->leave($request->user());

        return $this->me($request);
    }

    /**
     * Poll the queue.
     *
     * Also attempts to form matches, for the same reason `join` does: whoever polls is a live
     * request that can do the work, and a queue that only advances on a timer keeps everyone
     * waiting for the timer.
     */
    public function status(Request $request): JsonResponse
    {
        $this->queue->formMatches();

        return $this->me($request);
    }

    /**
     * A match, with a freshly minted ticket if the caller is in it.
     *
     * The ticket is minted per request rather than stored: it expires in two minutes, and a
     * reconnect ten minutes into a game needs a *new* one. Storing it would mean either a long
     * expiry — which is what makes a captured ticket dangerous — or a stored value that is
     * usually stale.
     */
    public function show(Request $request, MobaMatch $match): JsonResponse
    {
        $match->load('players.user');
        $seat = $match->players->firstWhere('user_id', $request->user()->getKey());

        if ($seat === null) {
            // Not your match. Spectating is a real feature and not this one — a spectator needs
            // a fog-free view the game server does not currently produce.
            return response()->json(['message' => 'You are not in this match.'], Response::HTTP_FORBIDDEN);
        }

        return response()->json($this->matchPayload($request, $match));
    }

    private function ticketFor(MobaMatch $match, $seat): string
    {
        $this->queue->markLive($match);

        return MatchTicket::mint(
            $match->getKey(),
            (int) $seat->user_id,
            (int) $seat->team,
            (int) $seat->slot,
            $seat->hero,
        );
    }

    /**
     * Give up on a match that has not finished.
     *
     * Ends it for everyone, because a MOBA cannot be played four-versus-five — see
     * {@see QueueService::abandon}. Only someone actually in the match may do it.
     */
    public function leaveMatch(Request $request, MobaMatch $match): JsonResponse
    {
        $inIt = $match->players()->where('user_id', $request->user()->getKey())->exists();
        if (! $inIt) {
            return response()->json(['message' => 'You are not in this match.'], Response::HTTP_FORBIDDEN);
        }

        $this->queue->abandon($match);

        return $this->me($request);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchPayload(Request $request, ?MobaMatch $match): ?array
    {
        if ($match === null) {
            return null;
        }

        $match->loadMissing('players.user');
        $seat = $match->players->firstWhere('user_id', $request->user()->getKey());

        return [
            'id' => $match->getKey(),
            'status' => $match->status,
            'team_size' => $match->team_size,
            'server_address' => $match->server_address,
            'winning_team' => $match->winning_team,
            // Taking a ticket is the API's cue that the match is being played — the game server
            // never reports a start, and asking it to would be a third crossing for one
            // timestamp.
            'ticket' => $seat === null ? null : $this->ticketFor($match, $seat),

            'you' => $seat === null ? null : [
                'team' => $seat->team,
                'slot' => $seat->slot,
                'hero' => $seat->hero,
            ],
            'players' => $match->players->map(fn ($player) => [
                'user_id' => $player->user_id,
                'name' => $player->user?->name,
                'team' => $player->team,
                'slot' => $player->slot,
                'hero' => $player->hero,
                'kills' => $player->kills,
                'deaths' => $player->deaths,
                'assists' => $player->assists,
                'mmr_change' => $player->mmr_change,
            ])->values(),
        ];
    }
}
