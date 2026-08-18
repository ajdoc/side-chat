<?php

namespace App\Models;

use Database\Factories\VoiceParticipantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** One user sitting in one voice channel. */
class VoiceParticipant extends Model
{
    /** @use HasFactory<VoiceParticipantFactory> */
    use HasFactory;

    protected $fillable = [
        'channel_id',
        'user_id',
        'muted',
        'deafened',
        'screen_sharing',
        'camera_on',
        'audio_sharing',
        // Somebody in the room is recording the call. See the migration for why it's per person.
        'recording',
        'last_seen_at',
        // Where they're standing, in a Side Space. Null in a voice channel or a DM.
        'x',
        'y',
        'facing',
        // Which of the channel's maps that position is on — see the migration. Null for a row
        // written before Side Spaces held interiors, and read as the way in.
        'space_map',
    ];

    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'muted' => 'boolean',
            'deafened' => 'boolean',
            'screen_sharing' => 'boolean',
            'camera_on' => 'boolean',
            'audio_sharing' => 'boolean',
            'recording' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The moment before which a row is considered a ghost left behind by a dead tab. */
    public static function staleBefore(): Carbon
    {
        return now()->subSeconds((int) config('webrtc.stale_after_seconds'));
    }

    /** @param  Builder<VoiceParticipant>  $query */
    public function scopeAlive(Builder $query): void
    {
        $query->where('last_seen_at', '>=', self::staleBefore());
    }
}
