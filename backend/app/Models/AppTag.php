<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

/**
 * One tag in a channel's vocabulary.
 *
 * Channel-scoped rather than project-scoped: a tracker channel with three projects shares one
 * set of labels, which is the only arrangement where filtering by "blocked" means anything
 * across them.
 */
class AppTag extends Model
{
    protected $fillable = ['channel_id', 'name', 'label', 'color'];

    /**
     * The lookup form of a label: what makes "Bug", "bug" and " bug " the same tag.
     *
     * Kept here so creating a tag, the unique index's fill and any later backfill all normalize
     * identically — the same reasoning as {@see Comment::normalize()}.
     */
    public static function normalize(string $label): string
    {
        return Str::lower(trim($label));
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return MorphToMany<TrackerTask, $this> */
    public function tasks(): MorphToMany
    {
        return $this->morphedByMany(TrackerTask::class, 'taggable', 'app_taggables', 'tag_id');
    }

    /**
     * Every attachment of this tag, whatever kind of thing it's on — what the usage count is
     * counted over. See {@see AppTaggable} for why the pivot has a model at all.
     *
     * @return HasMany<AppTaggable, $this>
     */
    public function taggables(): HasMany
    {
        return $this->hasMany(AppTaggable::class, 'tag_id');
    }
}
