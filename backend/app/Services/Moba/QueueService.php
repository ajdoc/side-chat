<?php

namespace App\Services\Moba;

use App\Models\MobaMatch;
use App\Models\MobaMatchPlayer;
use App\Models\MobaProfile;
use App\Models\MobaQueueEntry;
use App\Models\User;
use App\Support\Moba\Heroes;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Matchmaking: who is waiting, and when enough of them are close enough to play.
 *
 * ## The shape of the problem
 *
 * A MOBA's queue is not a line. Ten people arriving in order is not a match if their ratings
 * are hundreds of points apart, and two people three points apart are not a match if they both
 * queued for different sizes. So the queue is grouped by size, sorted by rating, and matched in
 * runs — the tightest window that yields a full roster wins.
 *
 * ## Why the window widens with waiting
 *
 * A fixed rating window either matches nobody at the edges of the distribution or matches
 * everybody badly. Widening it over time is the standard answer and the right one: a player who
 * has waited two minutes would rather have an uneven game than no game, and one who has waited
 * ten seconds would not. See {@see windowFor}.
 *
 * ## What this deliberately does not do
 *
 * No parties, no role queue, no dodge penalty, no region. Each of those changes the shape of the
 * problem rather than adding to it, and inventing them before anyone has played is how a
 * matchmaker ends up with rules nobody wanted.
 */
class QueueService
{
    /** Ratings start this far apart being acceptable, and widen from there. */
    private const BASE_WINDOW = 150;

    /** Extra rating tolerance per second of waiting. */
    private const WIDEN_PER_SECOND = 8;

    /** Beyond this the search takes anyone: a match is better than an empty lobby. */
    private const MAX_WINDOW = 2000;

    /**
     * Join the queue.
     *
     * Replaces any existing entry rather than refusing one. Someone pressing "find match" again
     * with a different hero means they changed their mind, and making them leave the queue first
     * is a rule that exists only because it was easier to write.
     */
    public function join(User $user, int $teamSize, string $hero, ?int $channelId = null): MobaQueueEntry
    {
        if (! Heroes::exists($hero)) {
            throw ValidationException::withMessages(['hero' => 'No such hero.']);
        }
        if ($teamSize < 1 || $teamSize > 5) {
            throw ValidationException::withMessages(['team_size' => 'Team size must be 1 to 5.']);
        }

        // Being seated in a live match and queueing for another is how a player ends up in two
        // games and present in neither.
        if ($this->liveMatchFor($user) !== null) {
            throw ValidationException::withMessages(['user' => 'You are already in a match.']);
        }

        $mmr = MobaProfile::forUser($user)->mmr;

        return MobaQueueEntry::updateOrCreate(
            ['user_id' => $user->getKey()],
            ['team_size' => $teamSize, 'hero' => $hero, 'mmr' => $mmr, 'channel_id' => $channelId],
        );
    }

    public function leave(User $user): void
    {
        MobaQueueEntry::where('user_id', $user->getKey())->delete();
    }

    /**
     * The match this user is currently seated in, if any.
     *
     * Includes `queued` as well as `live`: a roster that has been formed but not yet started is
     * still a commitment, and letting someone re-queue out of it would leave nine people waiting
     * for a tenth who is not coming.
     */
    public function liveMatchFor(User $user): ?MobaMatch
    {
        $this->abandonStale();

        return MobaMatch::query()
            ->whereIn('status', [MobaMatch::STATUS_QUEUED, MobaMatch::STATUS_LIVE])
            ->whereHas('players', fn ($q) => $q->where('user_id', $user->getKey()))
            ->latest('id')
            ->first();
    }

    /**
     * Retire matches that will never be played.
     *
     * **Without this the queue is a one-way door.** A match row is created the moment a roster
     * forms, and {@see liveMatchFor} treats a `queued` match as a commitment — which is right,
     * or nine people would be left waiting for a tenth who re-queued. But nothing else ever
     * clears that status: if the players never connect, or the game server was not running, the
     * row sits there forever and everyone in it is permanently unable to queue again. That is
     * exactly what happened the first time this was played.
     *
     * Two timeouts, because they are two different failures. A match nobody ever started is
     * abandoned quickly — the players are sitting in front of a lobby wondering why it is
     * stuck. A match that *did* start and never reported a result took a server crash to get
     * there, so it is given long enough that a genuinely long game is never cut short.
     */
    public function abandonStale(): void
    {
        MobaMatch::query()
            ->where('status', MobaMatch::STATUS_QUEUED)
            ->where('created_at', '<', now()->subMinutes(3))
            ->update(['status' => MobaMatch::STATUS_ABANDONED, 'ended_at' => now()]);

        MobaMatch::query()
            ->where('status', MobaMatch::STATUS_LIVE)
            ->where('created_at', '<', now()->subHours(2))
            ->update(['status' => MobaMatch::STATUS_ABANDONED, 'ended_at' => now()]);
    }

    /**
     * Give up on a match that has not finished.
     *
     * Frees everyone in it, not just the caller. A MOBA cannot be played four-versus-five, so
     * one person leaving a match that has not properly started ends it for everybody — which is
     * kinder than leaving four people in a game they cannot win and cannot leave.
     *
     * Refuses to touch a finished match: the result is the record, and a stray leave must not
     * be able to rewrite it.
     */
    public function abandon(MobaMatch $match): void
    {
        if ($match->isOver()) {
            return;
        }

        $match->update([
            'status' => MobaMatch::STATUS_ABANDONED,
            'ended_at' => now(),
        ]);
    }

    /**
     * Note that a match is being played.
     *
     * Called when a player takes a ticket, which is the closest thing the API has to knowing:
     * the game server does not report a start, and asking it to would be a third crossing
     * between the two halves for a fact worth one timestamp.
     */
    public function markLive(MobaMatch $match): void
    {
        if ($match->status === MobaMatch::STATUS_QUEUED) {
            $match->update(['status' => MobaMatch::STATUS_LIVE, 'started_at' => now()]);
        }
    }

    /**
     * How far apart two ratings may be, given how long the earlier one has waited.
     */
    public function windowFor(int $waitedSeconds): int
    {
        return (int) min(self::MAX_WINDOW, self::BASE_WINDOW + $waitedSeconds * self::WIDEN_PER_SECOND);
    }

    /**
     * Try to form matches out of everyone currently waiting.
     *
     * Called from the queue poll rather than from a scheduler, so a match forms the moment the
     * last player it needs appears rather than up to a tick later. It is cheap — one indexed
     * read per size — and the alternative is a player watching a "found!" that arrives seconds
     * after it was true.
     *
     * @return array<int, MobaMatch> whatever was formed this pass
     */
    public function formMatches(): array
    {
        $formed = [];

        foreach (MobaQueueEntry::query()->distinct()->pluck('team_size') as $teamSize) {
            while ($match = $this->formOne((int) $teamSize)) {
                $formed[] = $match;
            }
        }

        return $formed;
    }

    /**
     * Form one match at a given size, or return null if no run of players is close enough yet.
     */
    private function formOne(int $teamSize): ?MobaMatch
    {
        $needed = $teamSize * 2;

        // Sorted by rating so that "close enough" is a contiguous run, then scanned with a
        // sliding window. Sorting by wait time instead would make the tightest group depend on
        // arrival order, which is not what anyone means by a fair match.
        $waiting = MobaQueueEntry::query()
            ->where('team_size', $teamSize)
            ->orderBy('mmr')
            ->orderBy('id')
            ->get();

        if ($waiting->count() < $needed) {
            return null;
        }

        foreach ($waiting as $index => $entry) {
            $run = $waiting->slice($index, $needed);
            if ($run->count() < $needed) {
                break;
            }

            // The window is granted by whoever has waited *longest* in the run: the point of
            // widening is to rescue the person who has been waiting, so their patience is what
            // pays for the looser match.
            $longestWait = $run->max(fn (MobaQueueEntry $e) => now()->diffInSeconds($e->created_at, true));
            $spread = $run->max('mmr') - $run->min('mmr');

            if ($spread <= $this->windowFor((int) $longestWait)) {
                return $this->seat($run->values()->all(), $teamSize);
            }
        }

        return null;
    }

    /**
     * Turn a run of queue entries into a match, and take them out of the queue.
     *
     * @param  array<int, MobaQueueEntry>  $entries
     */
    private function seat(array $entries, int $teamSize): MobaMatch
    {
        return DB::transaction(function () use ($entries, $teamSize) {
            $match = MobaMatch::create([
                // The channel of whoever queued first, so a match started from a channel reports
                // back to it. Ten people from ten channels is a real possibility and the first
                // is as good an answer as any.
                'channel_id' => $entries[0]->channel_id,
                'team_size' => $teamSize,
                'status' => MobaMatch::STATUS_QUEUED,
                'server_address' => Config::get('services.moba.server_address'),
            ]);

            // Snake seating — the highest-rated player and the lowest go on the same side, and
            // so on inward. Alternating instead would stack the top half of the run onto one
            // team, which is the *opposite* of balancing a match that was formed on rating.
            $count = count($entries);
            foreach ($entries as $index => $entry) {
                $team = ($index % 4 === 0 || $index % 4 === 3)
                    ? MobaMatch::TEAM_BLUE
                    : MobaMatch::TEAM_RED;

                MobaMatchPlayer::create([
                    'moba_match_id' => $match->getKey(),
                    'user_id' => $entry->user_id,
                    'team' => $team,
                    // Slots interleave by team so the game server's own alternating seat
                    // assignment agrees with ours: even slots Blue, odd slots Red.
                    'slot' => $this->slotFor($index, $team, $count),
                    'hero' => $entry->hero,
                ]);

                $entry->delete();
            }

            return $match->fresh('players');
        });
    }

    /**
     * The seat number for a player, given the side snake seating put them on.
     *
     * The game server assigns even slots to Blue and odd to Red, and it does so independently.
     * Rather than teach it our seating, we hand it slots that already agree — which keeps the
     * two halves from having to share a rule.
     */
    private function slotFor(int $index, int $team, int $total): int
    {
        $before = 0;
        for ($i = 0; $i < $index; $i++) {
            $iTeam = ($i % 4 === 0 || $i % 4 === 3) ? MobaMatch::TEAM_BLUE : MobaMatch::TEAM_RED;
            if ($iTeam === $team) {
                $before++;
            }
        }

        return $team === MobaMatch::TEAM_BLUE ? $before * 2 : $before * 2 + 1;
    }
}
