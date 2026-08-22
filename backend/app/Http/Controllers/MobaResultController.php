<?php

namespace App\Http\Controllers;

use App\Models\MobaMatch;
use App\Services\Moba\RatingService;
use App\Support\Moba\MatchTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The one endpoint the game server calls.
 *
 * ## Why this is not behind the session guard
 *
 * The caller is a Rust process, not a browser. It has no cookie and no Sanctum token; what it
 * has is the shared secret, and it proves possession of it by signing the report the same way a
 * ticket is signed. That makes the secret the whole of the authentication here, which is why
 * {@see MatchTicket::isConfigured} exists — a stack running on the app-key fallback should not
 * be reachable from anywhere but a development machine.
 *
 * ## Why the result is idempotent
 *
 * A game server that reports, times out waiting for the response, and retries must not apply
 * rating twice. The status check is the guard: a match that has already finished takes no
 * further reports, and says so with 200 rather than an error, because from the caller's point of
 * view the outcome it wanted has happened.
 */
class MobaResultController extends Controller
{
    public function __construct(private readonly RatingService $ratings) {}

    public function store(Request $request, MobaMatch $match): JsonResponse
    {
        $signature = (string) $request->header('X-Moba-Signature', '');
        if (! $this->signatureIsValid($request, $signature)) {
            return response()->json(['message' => 'Bad signature.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($match->isOver()) {
            // Already recorded. A retry after a timed-out response lands here, and it is not an
            // error — the result the caller wanted is in the database.
            return response()->json(['message' => 'Already recorded.']);
        }

        $data = $request->validate([
            'winning_team' => ['required', 'integer', 'in:0,1'],
            'players' => ['required', 'array'],
            'players.*.slot' => ['required', 'integer', 'min:0'],
            'players.*.kills' => ['required', 'integer', 'min:0'],
            'players.*.deaths' => ['required', 'integer', 'min:0'],
            'players.*.assists' => ['required', 'integer', 'min:0'],
            'players.*.gold' => ['required', 'integer', 'min:0'],
            'players.*.damage' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($match, $data) {
            foreach ($data['players'] as $row) {
                // Matched on slot, not on user id. The game server seats *slots* — it does not
                // necessarily know who is behind one after a reconnect — and slot is the handle
                // both sides already agree on.
                $match->players()
                    ->where('slot', $row['slot'])
                    ->update([
                        'kills' => $row['kills'],
                        'deaths' => $row['deaths'],
                        'assists' => $row['assists'],
                        'gold' => $row['gold'],
                        'damage' => $row['damage'],
                    ]);
            }

            $match->update([
                'status' => MobaMatch::STATUS_FINISHED,
                'winning_team' => $data['winning_team'],
                'ended_at' => now(),
            ]);

            $this->ratings->apply($match->fresh('players'));
        });

        return response()->json(['message' => 'Recorded.']);
    }

    /**
     * Whether this report really came from the game server.
     *
     * Signs the raw body, so a report cannot be replayed against a different match or have a
     * field edited in flight. Constant-time comparison for the same reason the ticket verifier
     * uses one.
     */
    private function signatureIsValid(Request $request, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $this->secret());

        return hash_equals($expected, $signature);
    }

    private function secret(): string
    {
        return (string) (config('services.moba.secret') ?: config('app.key'));
    }
}
