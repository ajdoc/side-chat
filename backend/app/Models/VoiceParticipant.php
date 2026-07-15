<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** One user sitting in one voice channel. */
class VoiceParticipant extends Model
{
    /** @use HasFactory<\Database\Factories\VoiceParticipantFactory> */
    use HasFactory;

    protected $fillable = [
        'channel_id',
        'user_id',
        'muted',
        'deafened',
        'screen_sharing',
        'camera_on',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'muted' => 'boolean',
            'deafened' => 'boolean',
            'screen_sharing' => 'boolean',
            'camera_on' => 'boolean',
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
