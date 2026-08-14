<?php

namespace App\Models;

use App\Http\Requests\Tracker\StoreProjectRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A project in a Tracker channel — a name, a key, and the tasks under it.
 */
class TrackerProject extends Model
{
    protected $fillable = ['channel_id', 'key', 'name', 'description', 'position', 'archived', 'created_by'];

    protected $attributes = ['next_number' => 1, 'archived' => false];

    protected function casts(): array
    {
        return ['archived' => 'boolean'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<TrackerTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(TrackerTask::class, 'project_id');
    }

    /**
     * A project key suggested from its name: "HRIPS Yuck" → "HRIP", "Website Redesign" → "WEBS".
     *
     * The first word, truncated — not the initials. Initials read well for the two-word names
     * people use as examples and badly for everything else: a project called "Yuck" would get
     * the single letter Y, and one called "Q3 Planning For Ops" would get QPFO, which nobody
     * would recognise a week later. The first word is what people already call the project.
     *
     * Only ever a *suggestion* — the client puts it in an editable field, because whoever named
     * the project knows better than a heuristic does. Letters and digits only, and it must
     * start with a letter, matching what {@see StoreProjectRequest}
     * will accept.
     */
    public static function suggestKey(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Skip any leading words that don't start with a letter ("3D Assets" → ASSE), since a
        // key has to begin with one.
        $first = null;
        foreach ($words as $word) {
            if (preg_match('/^[A-Za-z]/', $word)) {
                $first = $word;
                break;
            }
        }

        return $first === null ? 'TASK' : Str::upper(substr($first, 0, 4));
    }

    /**
     * Take the next task number for this project.
     *
     * Locks the project row for the duration, so two people pressing "add task" at the same
     * instant get 4 and 5 rather than both getting 4 and one of them losing the insert to the
     * unique index. Must be called inside a transaction — see TrackerTaskController::store().
     */
    public function takeNextNumber(): int
    {
        $number = (int) DB::table('tracker_projects')
            ->where('id', $this->getKey())
            ->lockForUpdate()
            ->value('next_number');

        DB::table('tracker_projects')
            ->where('id', $this->getKey())
            ->update(['next_number' => $number + 1]);

        $this->next_number = $number + 1;

        return $number;
    }
}
