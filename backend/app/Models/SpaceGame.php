<?php

namespace App\Models;

use App\Services\Games\GameHandler;
use App\Services\Games\GameService;
use Database\Factories\SpaceGameFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A game living in a Side Space channel.
 *
 * The framework half of "the room becomes a game": this model knows a game has a type, a status,
 * some state and a start-vote, and nothing whatever about what any particular game *is*. The
 * rules live in a {@see GameHandler}; the orchestration in
 * {@see GameService}. This is deliberately as thin as {@see Widget} is, and
 * for the same reason — one table, many games, no per-game schema.
 */
class SpaceGame extends Model
{
    /** @use HasFactory<SpaceGameFactory> */
    use HasFactory;

    /** Being put to the room — collecting votes, not yet started. */
    public const VOTING = 'voting';

    /** Live. */
    public const RUNNING = 'running';

    /** Over — the result is up until somebody starts the next one. */
    public const ENDED = 'ended';

    /**
     * How long an ending stays on screen before the room is simply back to being a room.
     *
     * The result card ("Crew win", "you left the dungeon", a battle's outcome) is a *moment*, not
     * a state of the room: it's meant to be read once by whoever was there and then dismissed.
     * The row, though, outlives it — nothing deletes an ended game until somebody proposes the
     * next one — so without a shelf life every later page load hands that card back out, to
     * everyone, forever. Dating it from when the game ended keeps the card up for the people who
     * were playing (and for a reload right after) and lets it lapse for everybody else.
     */
    public const ENDED_TTL_SECONDS = 120;

    protected $fillable = [
        'channel_id',
        'type',
        'status',
        'state',
        'votes',
        'created_by',
        'opponent_id',
    ];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'votes' => 'array',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isVoting(): bool
    {
        return $this->status === self::VOTING;
    }

    public function isRunning(): bool
    {
        return $this->status === self::RUNNING;
    }

    public function isEnded(): bool
    {
        return $this->status === self::ENDED;
    }

    /**
     * An ending that has had its moment — old enough that nobody is still reading it.
     *
     * Timed from `updated_at`, which is exactly when the status became `ended`: an ended game is
     * never written again (the next propose overwrites the row wholesale).
     */
    public function isStaleEnding(): bool
    {
        return $this->isEnded()
            && $this->updated_at !== null
            && $this->updated_at->lt(now()->subSeconds(self::ENDED_TTL_SECONDS));
    }
}
