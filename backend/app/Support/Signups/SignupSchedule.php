<?php

namespace App\Support\Signups;

use Illuminate\Support\Carbon;

/**
 * Which days of a month a schedule lands on.
 *
 * "Weeks" means occurrences of the weekday within the month, not calendar rows: the 2nd
 * Saturday is the 2nd Saturday whatever day the month starts on. `-1` is the last one, which
 * is the 4th or the 5th depending on the month.
 */
final class SignupSchedule
{
    public const LAST = -1;

    /**
     * @param  list<int>  $weekdays  0 = Sunday … 6 = Saturday
     * @param  list<int>|null  $weeks  null for every week
     * @return list<string> Y-m-d, ascending
     */
    public static function dates(array $weekdays, ?array $weeks, Carbon $month): array
    {
        $day = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $out = [];

        for (; $day->lte($end); $day->addDay()) {
            if (! in_array($day->dayOfWeek, $weekdays, true)) {
                continue;
            }

            if ($weeks !== null && ! in_array(self::nth($day), $weeks, true)
                && ! (in_array(self::LAST, $weeks, true) && self::isLast($day))) {
                continue;
            }

            $out[] = $day->toDateString();
        }

        return $out;
    }

    /** Which occurrence of its weekday this date is: 1–5. */
    public static function nth(Carbon $date): int
    {
        return intdiv($date->day - 1, 7) + 1;
    }

    public static function isLast(Carbon $date): bool
    {
        return $date->day + 7 > $date->daysInMonth;
    }

    /**
     * The same slot a month later: the 2nd Tuesday stays the 2nd Tuesday. A 5th occurrence is
     * always a month's last, so it becomes next month's last rather than disappearing.
     */
    public static function sameSlotNextMonth(Carbon $date): Carbon
    {
        $next = $date->copy()->startOfMonth()->addMonth();
        $candidates = self::dates([$date->dayOfWeek], null, $next);
        $nth = self::nth($date);

        return Carbon::parse($nth === 5 ? end($candidates) : $candidates[$nth - 1]);
    }
}
