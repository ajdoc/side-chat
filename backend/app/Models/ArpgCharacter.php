<?php

namespace App\Models;

use Database\Factories\ArpgCharacterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dungeon-crawl hero, belonging to a player rather than to a room.
 *
 * Deliberately as thin as {@see SpaceGame}: it knows a hero has a level, some numbers and some
 * things in a bag, and nothing about what any of them *do*. What a point of strength is worth,
 * how much experience the next level costs, what an item rolls — all of that is the crawl's
 * handler, in the one place the game's rules live. This is only where the hero survives the run.
 */
class ArpgCharacter extends Model
{
    /** @use HasFactory<ArpgCharacterFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        // Who they've always been (the line), and where along it they are now. See Jobs.
        'class',
        'job',
        'level',
        'xp',
        'gold',
        'stats',
        'skills',
        'skill_points',
        'inventory',
        'equipment',
        'depth',
        'last_played_at',
    ];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            // {skillId: level} — what this hero can do, and how well. The skills themselves are
            // App\Support\Arpg\Skills; this is only which of them were paid for.
            'skills' => 'array',
            'skill_points' => 'integer',
            'inventory' => 'array',
            'equipment' => 'array',
            'level' => 'integer',
            'xp' => 'integer',
            'gold' => 'integer',
            'depth' => 'integer',
            'last_played_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
