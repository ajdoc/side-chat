<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A channel's kanban board — its columns, and the cards under them.
 *
 * One per channel (see the migration): the timeline card, the Side Desk tab, the Open Canvas
 * card and a kanban app channel are four views of this row, which is what they always were
 * back when the board lived in the widget's state.
 *
 * The columns are a small ordered list of `{key, label}` kept as JSON on this row. The `key` is
 * what a card stores and what `k!move <n> <column>` matches, so it is minted once from the
 * label and never rewritten — renaming "Doing" to "In Progress" must not orphan the cards in
 * it, and a key that tracked the label would do exactly that.
 */
class KanbanBoard extends Model
{
    /** What a new board starts with. Mirrored in the migration, which backfills to the same set. */
    public const DEFAULT_COLUMNS = [
        ['key' => 'todo', 'label' => 'To Do'],
        ['key' => 'doing', 'label' => 'Doing'],
        ['key' => 'done', 'label' => 'Done'],
    ];

    /** A board with no columns has nowhere to put a card, so removing the last one is refused. */
    public const MAX_COLUMNS = 12;

    protected $fillable = ['channel_id', 'columns', 'created_by'];

    protected function casts(): array
    {
        return ['columns' => 'array'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return HasMany<KanbanCard, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(KanbanCard::class, 'board_id')->orderBy('position')->orderBy('id');
    }

    /** @return array<int, string> the column keys, in board order */
    public function columnKeys(): array
    {
        return array_column($this->columns ?? [], 'key');
    }

    public function hasColumn(string $key): bool
    {
        return in_array($key, $this->columnKeys(), true);
    }

    /** The leftmost column — where a card with no column named goes, and where an orphan lands. */
    public function firstColumn(): ?string
    {
        return $this->columnKeys()[0] ?? null;
    }

    /**
     * Resolve what someone *typed* to a column key.
     *
     * Matches the key first, then the label case-insensitively, then a slug of the label — so
     * `k!move 3 in progress`, `k!move 3 In Progress` and `k!move 3 in-progress` all land on the
     * same column, which is what a person typing from the screen expects. Null if nothing fits.
     */
    public function resolveColumn(string $input): ?string
    {
        $needle = mb_strtolower(trim($input));

        if ($needle === '') {
            return null;
        }

        foreach ($this->columns ?? [] as $column) {
            $key = (string) $column['key'];
            $label = (string) ($column['label'] ?? '');

            if ($needle === mb_strtolower($key)
                || $needle === mb_strtolower($label)
                || Str::slug($needle) === $key) {
                return $key;
            }
        }

        return null;
    }

    /**
     * A key for a new column: a slug of its label, suffixed until it's free.
     *
     * A label with nothing sluggable in it (an emoji, a non-Latin script) still needs a key, so
     * there's a fallback rather than a validation error — the label is the part people read.
     */
    public function mintColumnKey(string $label): string
    {
        $base = Str::limit(Str::slug($label), 30, '') ?: 'column';
        $key = $base;

        for ($n = 2; $this->hasColumn($key); $n++) {
            $key = $base.'-'.$n;
        }

        return $key;
    }
}
