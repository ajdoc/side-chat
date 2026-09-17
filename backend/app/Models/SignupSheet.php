<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sign-up sheet: a custom header, custom columns, custom fees, and the names in its slots.
 * See the create_signup_sheets_tables migration for the shape.
 */
class SignupSheet extends Model
{
    /**
     * What a column means. Only `attending` is billed; the rest are there so a sheet can say
     * "waitlist" or "not going (reason)" without anyone paying for it.
     */
    public const COLUMN_KINDS = ['attending', 'waitlist', 'absent', 'info'];

    protected $fillable = [
        'channel_id', 'group_id', 'series_id', 'title', 'year', 'event_date',
        'header', 'columns', 'fees', 'slots', 'locked_at', 'archived_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'header' => 'array',
            'columns' => 'array',
            'fees' => 'array',
            'event_date' => 'date',
            'locked_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(SignupGroup::class, 'group_id');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(SignupSeries::class, 'series_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(SignupEntry::class, 'sheet_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(SignupCharge::class, 'sheet_id');
    }

    /** Neither archived nor locked — may names still be added or removed? */
    public function isOpen(): bool
    {
        return $this->archived_at === null && $this->locked_at === null;
    }

    /** @return array<string, array{key: string, label: string, color: string, kind: string}> */
    public function columnsByKey(): array
    {
        return collect($this->columns)->keyBy('key')->all();
    }

    /** @return list<string> */
    public function attendingKeys(): array
    {
        return collect($this->columns)->where('kind', 'attending')->pluck('key')->values()->all();
    }

    /** The day a charge from this sheet is billed to. */
    public function billedOn(): string
    {
        return ($this->event_date ?? $this->created_at ?? now())->toDateString();
    }

    /**
     * The starter layout — the team sheet this app was modelled on. A sheet is made from this
     * unless the client sends its own structure.
     */
    public static function defaults(): array
    {
        return [
            'header' => [
                ['label' => 'Field', 'value' => ''],
                ['label' => 'Date', 'value' => ''],
                ['label' => 'Jersey', 'value' => ''],
                ['label' => 'Notes', 'value' => ''],
            ],
            'columns' => [
                ['key' => 'boys', 'label' => 'Boys', 'color' => 'blue', 'kind' => 'attending'],
                ['key' => 'girls', 'label' => 'Girls', 'color' => 'pink', 'kind' => 'attending'],
                ['key' => 'nys', 'label' => 'NYS', 'color' => 'orange', 'kind' => 'attending'],
                ['key' => 'not_going', 'label' => 'Not Going (reason)', 'color' => 'red', 'kind' => 'absent'],
            ],
            'fees' => [],
        ];
    }
}
