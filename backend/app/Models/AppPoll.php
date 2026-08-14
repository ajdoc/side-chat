<?php

namespace App\Models;

use App\Models\Concerns\HasAppActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One poll on a channel's wall. Carries comments, tags, reactions and a history through
 * {@see HasAppActivity} — which is the whole reason those tables were made polymorphic.
 */
class AppPoll extends Model
{
    use HasAppActivity;

    /** How many options a voter may pick. See the migration. */
    public const TYPES = ['yes_no', 'single', 'multiple'];

    protected $fillable = ['channel_id', 'type', 'question', 'description', 'anonymous', 'closed_at', 'created_by'];

    protected $attributes = ['type' => 'single', 'anonymous' => false];

    protected function casts(): array
    {
        return ['anonymous' => 'boolean', 'closed_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<AppPollOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(AppPollOption::class, 'poll_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<AppPollVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(AppPollVote::class, 'poll_id');
    }

    /** Still taking votes? Closing refuses new ones and keeps the old — see the migration. */
    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /** May a voter pick more than one option? */
    public function allowsMultiple(): bool
    {
        return $this->type === 'multiple';
    }

    /**
     * How many *people* have answered, which is not the same as how many votes were cast.
     *
     * A multiple-choice poll gets several rows from one person, so "27 votes" would be a
     * number nobody could interpret. The client labels it "voters" for those and "votes" for
     * the single-answer kinds; both come from here so the two can't disagree.
     */
    public function voterCount(): int
    {
        return $this->votes()->distinct('user_id')->count('user_id');
    }
}
