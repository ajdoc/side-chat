<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line in a work item's history. Append-only — see the migration.
 */
class AppActivity extends Model
{
    protected $table = 'app_activity';

    /** Written once, never touched again, so there is no `updated_at` to maintain. */
    public const UPDATED_AT = null;

    protected $fillable = ['subject_type', 'subject_id', 'user_id', 'kind', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
