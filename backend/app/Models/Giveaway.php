<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A prize, a message to react to, and a closing time. See the giveaways migration.
 */
class Giveaway extends Model
{
    protected $fillable = [
        'server_id', 'channel_id', 'message_id', 'prize', 'emoji',
        'winner_count', 'required_badge_id', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'ends_at' => 'datetime',
            'drawn_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function requiredBadge(): BelongsTo
    {
        return $this->belongsTo(Badge::class, 'required_badge_id');
    }

    /** @return HasMany<GiveawayEntry> */
    public function entries(): HasMany
    {
        return $this->hasMany(GiveawayEntry::class);
    }

    /** Still taking entries? */
    public function isOpen(): bool
    {
        return $this->drawn_at === null
            && $this->cancelled_at === null
            && $this->ends_at->isFuture();
    }

    /** Derived rather than stored — see the migration for why there's no status column. */
    public function status(): string
    {
        return match (true) {
            $this->cancelled_at !== null => 'cancelled',
            $this->drawn_at !== null => 'drawn',
            $this->ends_at->isFuture() => 'running',
            // Past its end but not yet drawn: the runner hasn't got to it, which is a real
            // state somebody may be looking at for up to a minute.
            default => 'ending',
        };
    }

    /**
     * Pick the winners.
     *
     * Drawn in one pass with `inRandomOrder()` rather than picking one at a time, which is
     * what guarantees distinct people: two independent picks can land on the same entry, and
     * a three-winner giveaway that names the same person twice is a bug nobody will believe
     * was random.
     *
     * Returns the winning entries. An empty collection is a real answer — a giveaway nobody
     * entered has no winner, and inventing one would be worse.
     *
     * @return \Illuminate\Support\Collection<int, GiveawayEntry>
     */
    public function draw(): \Illuminate\Support\Collection
    {
        $winners = $this->entries()->inRandomOrder()->limit($this->winner_count)->get();

        if ($winners->isNotEmpty()) {
            $this->entries()->whereKey($winners->pluck('id'))->update(['won' => true]);
        }

        $this->forceFill(['drawn_at' => now()])->saveQuietly();

        return $winners;
    }
}
