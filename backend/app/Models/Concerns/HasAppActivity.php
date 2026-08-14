<?php

namespace App\Models\Concerns;

use App\Models\AppActivity;
use App\Models\AppComment;
use App\Models\AppTag;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;

/**
 * Comments, tags and a history — the three things that turn a record into a work item.
 *
 * Pulled into a trait rather than written per model because the whole point of making these
 * tables polymorphic was that the next app to want them adds one `use` line. A tracker task
 * has them now; a kanban card and a calendar entry are the same shape.
 *
 * The consuming model must expose a `channel_id` (or a `channelId()`), since both comments and
 * tags are scoped to a channel for authorisation and vocabulary respectively.
 */
trait HasAppActivity
{
    /**
     * Take the comments, tags and history with the item when it's deleted.
     *
     * A foreign key can't do this. `commentable_id` points at whichever table owns the row, so
     * there is nothing for the database to cascade from — the price of making these tables
     * polymorphic, and it has to be paid somewhere.
     *
     * Model events cover deleting one item. They do *not* cover a parent cascading to its
     * children: deleting a tracker project removes its tasks in the database, where no PHP
     * runs, so anything deleting a parent must call {@see purgeAppActivityFor} for the children
     * it's about to take with it.
     */
    protected static function bootHasAppActivity(): void
    {
        static::deleting(function (self $model) {
            $model->comments()->delete();
            $model->activity()->delete();
            $model->tags()->detach();
        });
    }

    /**
     * Drop the comments, tags and history of many items at once, by id.
     *
     * For the cascade case above — a caller about to delete something whose children will go
     * with it in the database. Bulk deletes rather than loading each child to fire its events,
     * because a project can hold hundreds of tasks.
     *
     * @param  array<int, int>  $ids
     */
    public static function purgeAppActivityFor(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $type = (new static)->getMorphClass();

        AppComment::where('commentable_type', $type)->whereIn('commentable_id', $ids)->delete();
        AppActivity::where('subject_type', $type)->whereIn('subject_id', $ids)->delete();
        DB::table('app_taggables')
            ->where('taggable_type', $type)
            ->whereIn('taggable_id', $ids)
            ->delete();
    }

    /** @return MorphMany<AppComment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(AppComment::class, 'commentable')->oldest();
    }

    /** @return MorphToMany<AppTag, $this> */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(AppTag::class, 'taggable', 'app_taggables', 'taggable_id', 'tag_id');
    }

    /** @return MorphMany<AppActivity, $this> */
    public function activity(): MorphMany
    {
        return $this->morphMany(AppActivity::class, 'subject')->oldest();
    }

    /**
     * Record something that just happened to this item.
     *
     * Called from inside the action that made the change and inside its transaction, so the
     * history can't survive a write that rolled back.
     *
     * @param  array<string, mixed>  $data  whatever the client needs to render the line — for a
     *                                      change, both sides of it
     */
    public function recordActivity(string $kind, ?User $actor = null, array $data = []): AppActivity
    {
        return $this->activity()->create([
            'user_id' => $actor?->getKey(),
            'kind' => $kind,
            'data' => $data ?: null,
        ]);
    }
}
