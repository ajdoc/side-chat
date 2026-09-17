<?php

namespace App\Support\Signups;

use App\Models\SignupSeries;
use App\Models\SignupSheet;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * A sheet's *layout* — the part a schedule hands down — and applying it to sheets.
 *
 * The layout is what "apply to all" carries: title, group, header, columns, fees and rows. A
 * sheet's date, lock and names are its own and never travel. Archived sheets are never touched.
 */
final class SignupLayout
{
    public const FIELDS = ['title', 'group_id', 'header', 'columns', 'fees', 'slots'];

    /** How far an edit reaches along a schedule. */
    public const SHEET_SCOPES = ['this', 'following', 'all'];

    public const SERIES_SCOPES = ['none', 'upcoming', 'all'];

    /**
     * The layout fields present in validated input, with column and fee keys normalised.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function from(array $data): array
    {
        $layout = Arr::only($data, self::FIELDS);

        if (array_key_exists('columns', $layout)) {
            $layout['columns'] = SignupKeys::normalise($layout['columns'], 'column');
        }
        if (array_key_exists('fees', $layout)) {
            $layout['fees'] = SignupKeys::normalise($layout['fees'], 'fee');
        }

        return $layout;
    }

    /**
     * Put a layout on a sheet, drop names that no longer have a slot, and re-sync its charges.
     * The caller may have set the sheet's own fields (date, lock) already; this saves them too.
     */
    public static function applyTo(SignupSheet $sheet, array $layout): void
    {
        $sheet->fill($layout);

        if (array_key_exists('columns', $layout)) {
            // Names in a column that no longer exists go with it.
            $sheet->entries()->whereNotIn('column_key', collect($sheet->columns)->pluck('key'))->delete();
        }

        // Rows shrunk: anyone below the new last row is dropped rather than hidden.
        $sheet->entries()->where('position', '>', $sheet->slots)->delete();

        $sheet->save();

        SignupCharges::sync($sheet);
    }

    /**
     * A schedule's sheets an edit should reach. `$from` bounds it (inclusive) for "following" and
     * "upcoming"; null means every sheet.
     *
     * @return Collection<int, SignupSheet>
     */
    public static function sheetsOf(SignupSeries $series, ?string $from, ?int $except = null): Collection
    {
        return $series->sheets()
            ->whereNull('archived_at')
            ->when($from !== null, fn ($q) => $q->whereDate('event_date', '>=', $from))
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->get();
    }

    /** @return int how many sheets were changed */
    public static function fanOut(SignupSeries $series, array $layout, ?string $from, ?int $except = null): int
    {
        if ($layout === []) {
            return 0;
        }

        $sheets = self::sheetsOf($series, $from, $except);
        $sheets->each(fn ($sheet) => self::applyTo($sheet, $layout));

        return $sheets->count();
    }
}
