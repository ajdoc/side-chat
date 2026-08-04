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

    public function __construct(AmongUsGame $amongUs, PetBattleGame $petBattle, ArpgGame $arpg)
    {
        $this->handlers = [
            $amongUs->type() => $amongUs,
            $petBattle->type() => $petBattle,
            $arpg->type() => $arpg,
        ];
    }

    /**
     * Add a game to the catalogue after construction.
     *
     * The constructor lists the games the app ships with; this is for the ones it doesn't know
     * about at wiring time — a game stood up by a test, or one that arrives from somewhere other
     * than this file. Registering over an existing type replaces it.
     */
    public function register(GameHandler $handler): void
    {
        $this->handlers[$handler->type()] = $handler;
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
            'joinable' => $h->joinable(),
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

        // An open game was never up for debate — proposing it *is* starting it.
        if ($handler->startMode() === 'open') {
            $this->start($game, $handler);

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
     * The players are the two duellists for a challenge, the proposer alone for an open game, or
     * everyone in the room for a vote — that difference is the whole of what `startMode` changes
     * about starting. An open game starts empty of everyone but its opener precisely so that
     * joining is the *only* way in, one path whether you're playing alone or waiting for friends.
     */
    private function start(SpaceGame $game, GameHandler $handler): void
    {
        $playerIds = match ($handler->startMode()) {
            'challenge' => [$game->created_by, $game->opponent_id],
            'open' => [$game->created_by],
            default => $this->participantIds($game->channel),
        };

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
     * Walk into a game already in progress.
     *
     * The framework asks everything that isn't the game's business — is it running, does it take
     * newcomers, are you in the room, are you already in it, is there a seat left — and the
     * handler only has to say what a new player *is*. That division is why drop-in co-op costs a
     * game one method rather than its own endpoint.
     */
    public function join(SpaceGame $game, User $user): SpaceGame
    {
        $handler = $this->handlerFor($game->type);

        if ($handler === null || ! $game->isRunning()) {
            throw ValidationException::withMessages(['join' => 'This game is not running.']);
        }

        if (! $handler->joinable()) {
            throw ValidationException::withMessages(['join' => 'You cannot join this one once it has started.']);
        }

        // The roster is the state's `players` map, keyed by user id — the same convention every
        // handler already writes in start(), and the only part of a game's state the framework
        // reads. That's the price of knowing whether a game is full without asking the game.
        $players = $game->state['players'] ?? [];

        if (array_key_exists($user->id, $players)) {
            throw ValidationException::withMessages(['join' => 'You are already playing.']);
        }

        if (count($players) >= $handler->maxPlayers()) {
            throw ValidationException::withMessages(['join' => 'This game is full.']);
        }

        $game->update(['state' => $handler->join($game, $user)]);

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

        // A game that's over and has been over for a while is not shown at all: the row sticks
        // around until the next propose replaces it, and handing an old ending to every fresh
        // load is what makes a result card reappear on refresh, for everyone, days later.
        if ($game === null || $handler === null || $game->isStaleEnding()) {
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
                // When it finished, so a client that has already dismissed this ending can
                // recognise the same one after a reload instead of showing it again.
                'ended_at' => $game->isEnded() ? $game->updated_at?->getTimestamp() : null,
                'min_players' => $handler->minPlayers(),
                // Whether *this* viewer could walk in right now — the whole of what the join
                // button needs to know, worked out here so no client has to re-derive the rules.
                'can_join' => $viewer !== null && $this->canJoin($game, $handler, $viewer),
                // The start vote, only while it's open: who's said yes, and how many are in the
                // room to be won over. Your own vote is whatever you last sent.
                'vote' => $game->isVoting() ? [
                    'yes' => count(array_filter($game->votes, static fn ($v) => $v === true)),
                    'present' => $this->roomSize($channel),
                    'mine' => $game->votes[$viewer?->id] ?? null,
                ] : null,
                // A game that actually ran (or is running) has state to redact; one still being
                // voted on never got any, so there's nothing to show. The test is emptiness
                // rather than "has players", because a run everybody walked out of still has an
                // ending to show and an empty roster.
                'state' => ($game->state ?? []) !== [] ? $handler->view($game, $viewer) : null,
            ],
        ];
    }

    /** The same conditions {@see join} enforces, asked rather than thrown. */
    private function canJoin(SpaceGame $game, GameHandler $handler, User $viewer): bool
    {
        $players = $game->state['players'] ?? [];

        return $game->isRunning()
            && $handler->joinable()
            && ! array_key_exists($viewer->id, $players)
            && count($players) < $handler->maxPlayers()
            && $this->inRoom($game->channel, $viewer->id);
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
