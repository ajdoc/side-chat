<?php

namespace App\Http\Resources;

use App\Models\ArpgCharacter;
use App\Support\Arpg\Jobs;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A hero, as their own player sees them.
 *
 * Only ever serialised for the owner — the character-select screen and the sheet — so nothing is
 * held back here. What the *party* sees of you is a different, much thinner thing, and it comes
 * from the game's own redaction rather than from this.
 *
 * @mixin ArpgCharacter
 */
class ArpgCharacterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // The line they were born into, and where along it they stand now.
            'class' => $this->class,
            'job' => $this->job ?? $this->class,
            'job_name' => Jobs::name($this->job ?? $this->class),
            'level' => $this->level,
            'xp' => $this->xp,
            'gold' => $this->gold,
            'stats' => $this->stats,
            'skills' => $this->skills,
            'skill_points' => $this->skill_points,
            'inventory' => $this->inventory,
            'equipment' => $this->equipment,
            // The deepest floor they've reached, ever — the number a returning player looks for.
            'depth' => $this->depth,
            'last_played_at' => $this->last_played_at,
        ];
    }
}
