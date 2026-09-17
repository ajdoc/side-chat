<?php

namespace App\Models;

use App\Support\Signups\SignupSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** A recurring schedule: a sheet template and the days it repeats on. See the migration. */
class SignupSeries extends Model
{
    protected $table = 'signup_series';

    protected $fillable = [
        'channel_id', 'group_id', 'title', 'header', 'columns', 'fees', 'slots',
        'weekdays', 'weeks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'header' => 'array', 'columns' => 'array', 'fees' => 'array',
            'weekdays' => 'array', 'weeks' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(SignupGroup::class, 'group_id');
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(SignupSheet::class, 'series_id');
    }

    /** @return list<string> */
    public function datesIn(Carbon $month): array
    {
        return SignupSchedule::dates($this->weekdays, $this->weeks, $month);
    }

    /**
     * Make this month's sheets. Dates that already have one are skipped, so running it twice
     * is harmless — and a sheet somebody deleted by hand comes back, which is what asking for
     * the month again means.
     *
     * @return Collection<int, SignupSheet> the sheets created
     */
    public function generate(Carbon $month, ?User $by = null): Collection
    {
        $have = $this->sheets()
            ->whereBetween('event_date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->pluck('event_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString());

        return collect($this->datesIn($month))
            ->reject(fn ($date) => $have->contains($date))
            ->map(fn ($date) => SignupSheet::create([
                'channel_id' => $this->channel_id,
                'group_id' => $this->group_id,
                'series_id' => $this->id,
                'title' => $this->title,
                'event_date' => $date,
                'year' => (int) substr($date, 0, 4),
                'header' => $this->header,
                'columns' => $this->columns,
                'fees' => $this->fees,
                'slots' => $this->slots,
                'created_by' => $by?->id,
            ]))
            ->values();
    }
}
