<?php

namespace App\Services\Games;

use App\Events\SpaceGameUpdated;
use App\Models\Channel;
use App\Models\SpaceGame;
use App\Models\User;
use App\Models\VoiceParticipant;
use App\Services\Widgets\WidgetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything a game needs that isn't the game: proposing it, voting it in, running its actions,
 * and telling the room. The counterpart of {@see WidgetService}, and built
 * the same way — a registry of handlers keyed by type, with all the plumbing here so a handler
 * can be nothing but rules.
 *
 * ## The electorate
 *
 * "The room" means the people standing in it — the {@see VoiceParticipant} rows for the channel,
 * which is exactly who has walked in. A game is proposed to them, voted on by them, and played by
 * whoever's there when it starts. Somebody reading the timeline from outside is not consulted and
 * cannot join; somebody who wanders in mid-game spectates.
 */
class GameService
{
    /** @var array<string, GameHandler> type => handler */
    private array $handlers;

    public function __construct(AmongUsGame $amongUs, PetBattleGame $petBattle)
    {
        $this->handlers = [
            $amongUs->type() => $amongUs,
            $petBattle->type() => $petBattle,
        ];
    }

    public function handlerFor(string $type): ?GameHandler
    {
        return $this->handlers[$type] ?? null;
    }

    /**
     * The games the propose menu offers.
     *
     * @return array<int, array{type: string, label: string, blurb: string, min: int, max: int}>
     */
    public function catalogue(): array
    {
        return array_values(array_map(fn (GameHandler $h) => [
            'type' => $h->type(),
            'label' => $h->label(),
            'blurb' => $h->blurb(),
            'mode' => $h->startMode(),
            'min' => $h->minPlayers(),
            'max' => $h->maxPlayers(),
        ], $this->handlers));
    }

    /**
     * Put a game to the room.
     *
     * Replaces whatever was there — a finished game, or an abandoned vote — because a room holds
     * one game at a time and the unique key on the table says so. The proposer is counted as a
     * yes, since proposing is wanting to play.
     */
    public function propose(Channel $channel, User $user, string $type, ?int $opponentId = null): SpaceGame
    {
        $handler = $this->handlerFor($type);

        if ($handler === null) {
            throw ValidationException::withMessages(['type' => 'There is no such game.']);
        }

        $challenge = $handler->startMode() === 'challenge';

        if ($challenge) {
            // A duel needs a specific, present, other person. Everything else about starting it
            // is the same machinery — it just waits on one yes instead of a majority.
            if ($opponentId === null || $opponentId === $user->id) {
                throw ValidationException::withMessages(['opponent' => 'Pick someone to challenge.']);
            }
            if (! $this->inRoom($channel, $opponentId)) {
                throw ValidationException::withMessages(['opponent' => 'They have to be in the room.']);
            }
        } elseif ($this->roomSize($channel) < $handler->minPlayers()) {
            throw ValidationException::withMessages([
                'type' => "You need at least {$handler->minPlayers()} people in the room to start {$handler->label()}.",
            ]);
        }

        $game = DB::transaction(function () use ($channel, $user, $type, $opponentId) {
            // One game per room: the unique key means we update the existing row rather than
            // stacking a second game on the channel. updateOrCreate keeps a running game from
            // being clobbered — see the guard.
            $existing = $channel->spaceGame()->lockForUpdate()->first();

            if ($existing && $existing->isRunning()) {
                throw ValidationException::withMessages(['type' => 'A game is already running here.']);
            }

            $game = $channel->spaceGame()->updateOrCreate([], [
                'type' => $type,
                'status' => SpaceGame::VOTING,
                'state' => [],
                'votes' => [$user->id => true],
                'created_by' => $user->id,
                'opponent_id' => $opponentId,
            ]);

            $this->maybeStart($game);

            return $game;
        });

        // Tell the room — otherwise the vote card and the challenge only ever appear for the
        // person who started it, since everyone else learns of a game solely through this ping.
        // After the transaction, so a rolled-back propose is never announced.
        broadcast(new SpaceGameUpdated($game));

        return $game;
    }

    /**
     * Record a vote, and start the game the instant the room has agreed.
     *
     * A vote is only meaningful while the game is still being decided; once it's running the
     * ballot is closed. `null` is a real value here only in the sense that a missing vote is a
     * non-vote — you send true or false.
     */
    public function vote(SpaceGame $game, User $user, bool $yes): SpaceGame
    {
        if (! $game->isVoting()) {
            throw ValidationException::withMessages(['vote' => 'The vote is closed.']);
        }

        $handler = $this->handlerFor($game->type);

        // A challenge is answered by the challenged, and by them alone. A no is a decline, which
        // ends it there rather than leaving a dead invitation on the table.
        if ($handler !== null && $handler->startMode() === 'challenge') {
            if ($game->opponent_id !== $user->id) {
                throw ValidationException::withMessages(['vote' => 'This challenge is not yours to answer.']);
            }
            if (! $yes) {
                return $this->cancel($game);
            }
        }

        $votes = $game->votes;
        $votes[$user->id] = $yes;
        $game->update(['votes' => $votes]);

        $this->maybeStart($game);

        // The whole room follows the tally and, when it tips, the game starting — so every vote
        // is broadcast, not just the ones that end the ballot.
        broadcast(new SpaceGameUpdated($game));

        return $game;
    }

    /**
     * Start the game if the room has said yes.
     *
     * "Said yes" is a majority of the people currently in the room — not of those who happen to
     * have voted, because a game that starts on two yeses in a room of ten is a game eight people
     * didn't agree to. The proposer's own yes counts; a room of the bare minimum all has to agree.
     */
    private function maybeStart(SpaceGame $game): void
    {
        if (! $game->isVoting()) {
            return;
        }

        $handler = $this->handlerFor($game->type);
        if ($handler === null) {
            return;
        }

        // A challenge starts on the challenged person's yes; a room game on a majority.
        if ($handler->startMode() === 'challenge') {
            if (($game->votes[$game->opponent_id] ?? null) === true) {
                $this->start($game, $handler);
            }

            return;
        }

        $present = $this->roomSize($game->channel);
        $yes = count(array_filter($game->votes, static fn ($v) => $v === true));

        // Majority of the room, and never below the game's own floor.
        if ($present < $handler->minPlayers() || $yes * 2 <= $present) {
            return;
        }

        $this->start($game, $handler);
    }

    /**
     * Turn a passed vote into a running game.
     *
     * The players are the two duellists for a challenge, or everyone in the room for a vote —
     * that difference is the whole of what `startMode` changes about starting.
     */
    private function start(SpaceGame $game, GameHandler $handler): void
    {
        $playerIds = $handler->startMode() === 'challenge'
            ? [$game->created_by, $game->opponent_id]
            : $this->participantIds($game->channel);

        if (count($playerIds) > $handler->maxPlayers()) {
            $playerIds = array_slice($playerIds, 0, $handler->maxPlayers());
        }

        $game->update([
            'status' => SpaceGame::RUNNING,
            'state' => $handler->start($game, $playerIds),
            'votes' => [],
        ]);
    }

    /**
     * Run one action, persist the result, tell the room.
     *
     * The handler either returns the new state or throws to refuse the action — the same shape
     * every game's rules take. A refused action never persists and never broadcasts.
     *
     * @param  array<string, mixed>  $payload
     */
    public function act(SpaceGame $game, User $user, string $action, array $payload): SpaceGame
    {
        $handler = $this->handlerFor($game->type);

        if ($handler === null || ! $game->isRunning()) {
            throw ValidationException::withMessages(['action' => 'This game is not running.']);
        }

        $state = $handler->act($game, $user, $action, $payload);
        $game->update(['state' => $state]);

        // A game whose state now says it's over is over — the handler decides the win, the
        // service records it. Ending is a status change so the room can drop out of game mode.
        if (($state['winner'] ?? null) !== null) {
            $game->update(['status' => SpaceGame::ENDED]);
        }

        broadcast(new SpaceGameUpdated($game));

        return $game;
    }

    /**
     * End a game early, or clear a vote nobody wants.
     *
     * Anyone in the room may — a game people have walked away from shouldn't need its proposer to
     * come back and dismiss it. There's nothing to redact about "it's over", so this just marks
     * it ended and tells the room.
     */
    public function cancel(SpaceGame $game): SpaceGame
    {
        $game->update(['status' => SpaceGame::ENDED]);
        broadcast(new SpaceGameUpdated($game));

        return $game;
    }

    /** How many people are standing in the room — the electorate, and the player pool. */
    public function roomSize(Channel $channel): int
    {
        return VoiceParticipant::query()->where('channel_id', $channel->id)->count();
    }

    /**
     * The whole client payload for one viewer: the framework's public facts, plus this viewer's
     * redacted slice of the game's own state.
     *
     * @return array<string, mixed>
     */
    public function present(Channel $channel, ?User $viewer = null): array
    {
        $game = $channel->spaceGame;
        $handler = $game ? $this->handlerFor($game->type) : null;

        if ($game === null || $handler === null) {
            return ['game' => null];
        }

        return [
            'game' => [
                'type' => $game->type,
                'label' => $handler->label(),
                'status' => $game->status,
                'created_by' => $game->created_by,
                // Who was challenged, for a duel — null for a room game.
                'opponent' => $game->opponent_id,
                'start_mode' => $handler->startMode(),
                'min_players' => $handler->minPlayers(),
                // The start vote, only while it's open: who's said yes, and how many are in the
                // room to be won over. Your own vote is whatever you last sent.
                'vote' => $game->isVoting() ? [
                    'yes' => count(array_filter($game->votes, static fn ($v) => $v === true)),
                    'present' => $this->roomSize($channel),
                    'mine' => $game->votes[$viewer?->id] ?? null,
                ] : null,
                // A game that actually ran (or is running) has state to redact; one cancelled
                // while still being voted on never got any, so there's nothing to show.
                'state' => ($game->state['players'] ?? null) ? $handler->view($game, $viewer) : null,
            ],
        ];
    }

    /** Is this particular person standing in the room? */
    private function inRoom(Channel $channel, int $userId): bool
    {
        return VoiceParticipant::query()
            ->where('channel_id', $channel->id)
            ->where('user_id', $userId)
            ->exists();
    }

    /** The ids of everyone standing in the room, oldest arrival first. */
    private function participantIds(Channel $channel): array
    {
        return VoiceParticipant::query()
            ->where('channel_id', $channel->id)
            ->orderBy('created_at')
            ->pluck('user_id')
            ->all();
    }
}
