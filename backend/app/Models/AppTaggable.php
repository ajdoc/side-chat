<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One "this thing wears this tag" row.
 *
 * Normally a pivot needs no model at all — {@see AppTag::tasks()} and the `tags()` relation on
 * {@see Concerns\HasAppActivity} both go straight through the table. This exists for the one
 * question the morph relations can't answer: *how many* things wear a tag, across every kind of
 * thing at once. `withCount('taggables')` needs a relation to count, and a morphToMany can only
 * count one target type at a time.
 */
class AppTaggable extends Model
{
    protected $table = 'app_taggables';

    protected $fillable = ['tag_id', 'taggable_type', 'taggable_id'];

    public function tag(): BelongsTo
    {
        return $this->belongsTo(AppTag::class, 'tag_id');
    }

    public function taggable(): MorphTo
    {
        return $this->morphTo();
    }
}
