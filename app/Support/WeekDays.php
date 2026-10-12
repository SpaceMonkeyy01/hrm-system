<?php

namespace App\Support;

/** ISO weekday helpers for work schedules (1 = Monday ... 7 = Sunday). */
final class WeekDays
{
    public const NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /** [1,2,3,4,5] => "Mon-Fri", [1,3,5] => "Mon, Wed, Fri" */
    public static function label(array $days): string
    {
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);
        if (! $days) {
            return 'No working days';
        }
        $contiguous = $days === range($days[0], end($days));

        return $contiguous && count($days) > 2
            ? self::NAMES[$days[0]].'-'.self::NAMES[end($days)]
            : implode(', ', array_map(fn ($d) => self::NAMES[$d], $days));
    }
}
