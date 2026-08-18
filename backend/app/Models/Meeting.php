<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A meeting — its room, its link, and the policy on who may follow it.
 *
 * See the migration for why this is a table and the calendar still owns "when".
 */
class Meeting extends Model
{
    /**
     * How far open the door is. See the guests migration for why this isn't a pair of booleans.
     *
     * - `members` — only people already in the room; the link is just the address.
     * - `account` — anybody signed in, who is added to the meeting's group chat.
     * - `guest`   — anybody at all: a name, and they're in.
     */
    public const ACCESS = ['members', 'account', 'guest'];

    protected $fillable = [
        'token', 'channel_id', 'created_by', 'title', 'access', 'scheduled_event_id', 'expires_at',
    ];

    protected $attributes = ['access' => 'members'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Minted here rather than by the caller, so no code path can create a meeting whose link
        // is guessable or missing.
        static::creating(function (self $meeting) {
            $meeting->token ??= (string) Str::uuid();
        });
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The calendar entry, when this meeting is scheduled. */
    public function scheduledEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'scheduled_event_id');
    }

    /** @return HasMany<MeetingJoin, $this> */
    public function joins(): HasMany
    {
        return $this->hasMany(MeetingJoin::class);
    }

    /** Is the link still admitting people? The room outlives it either way. */
    public function isOpen(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Can this meeting admit somebody who isn't already in the room?
     *
     * Two conditions, and the second is not a setting: a link cannot let anybody into a *server*,
     * because being in a server is a thing that server's people decide. So external admission is
     * a property of meetings held in a group conversation, whatever the flag says.
     */
    public function admitsOutsiders(): bool
    {
        return $this->access !== 'members'
            && $this->isOpen()
            && $this->channel?->conversation_id !== null;
    }

    /**
     * Can somebody with no account at all walk in?
     *
     * The strictest of the three doors, and it carries two conditions the setting can't override.
     * **Never a server room** — a link is not a way into a server, and a throwaway account is the
     * last thing that should be. **Never an encrypted channel** — device keys belong to accounts
     * that persist, so a guest could either not read the room or would break the promise that
     * only its people can; refusing is the only honest answer.
     */
    public function admitsGuests(): bool
    {
        return $this->access === 'guest'
            && $this->isOpen()
            && $this->channel?->conversation_id !== null
            && ! $this->channel?->encrypted;
    }
}
