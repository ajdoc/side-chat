<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One block one person finished, addressed as week + day key + block index. */
class TrainingTick extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['program_id', 'user_id', 'week', 'day', 'block'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(TrainingProgram::class, 'program_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The client's key for a tick — `week:day:block`, as lib/training.ts builds it. */
    public function key(): string
    {
        return "{$this->week}:{$this->day}:{$this->block}";
    }
}
