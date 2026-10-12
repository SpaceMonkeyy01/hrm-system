<?php

namespace App\Services;

use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Attendance status for one person on one day: the only place it is worked out (like HrRules for onboarding).
 * Inputs are the day's punch times, HR's manual adjustment and the work schedule that applied that day.
 *
 *   check-in   HR's time, else the first punch
 *   check-out  HR's time, else the last punch (if there are two or more), else the schedule's end time when the
 *              schedule auto-checks-out, else missing
 *   late       fixed schedules: in after start + grace; counted from the start time
 *   early      fixed schedules: out before end - grace; counted to the end time
 *   short      flexible schedules: worked less than the day's hours
 *   overtime   worked past the end time (fixed) or past the day's hours (flexible) by more than the schedule's
 *              overtime_after_minutes; counted from the end / the day's hours. Null on the schedule = not counted
 *   night      times can run past midnight: a punch on the next morning arrives as "33:00" (24 + 9). A schedule
 *              whose end is before its start (21:00-06:00) ends the next morning; HR's check-out before the
 *              check-in means the next morning too
 *   holiday    a day off like a weekend (status Holiday, or Worked day off when someone came in)
 *   leave      full day: On leave unless the person punched anyway; half day: no late (first half off) or early
 *              (second half off), and never Half day
 *
 * Statuses and their colours: config hrm.attendance.statuses.
 */
class AttendanceRules
{
    /**
     * @param  list<string>  $punches  "H:i:s" times on $date, any order
     * @param  array{check_in: ?string, check_out: ?string, note?: ?string}|null  $adjustment
     * @param  array{holiday?: ?string, leave?: ?array{type: string, part: string}}  $context
     * @return array{date: string, status: ?string, in: ?string, out: ?string, in_source: ?string, out_source: ?string,
     *     worked: ?int, late: int, early: int, short: int, overtime: int, punches: int, working_day: bool, adjusted: bool,
     *     note: ?string, holiday: ?string, leave: ?array{type: string, part: string}}
     *         status null = a future day (nothing to show yet)
     */
    public static function day(string $date, array $punches, ?array $adjustment, ?WorkSchedule $schedule, CarbonInterface $now, array $context = []): array
    {
        $times = self::dedupe($punches);
        $today = $now->toDateString();
        $holiday = $context['holiday'] ?? null;
        $leave = $context['leave'] ?? null;
        $fullLeave = $leave && $leave['part'] === 'full';
        $working = ($schedule === null || in_array(CarbonImmutable::parse($date)->isoWeekday(), array_map('intval', $schedule->working_days ?? []), true))
            && $holiday === null;

        $in = $adjustment['check_in'] ?? null;
        $inSource = $in ? 'manual' : null;
        if (! $in && $times) {
            $in = substr($times[0], 0, 5);
            $inSource = 'punch';
        }
        $out = $adjustment['check_out'] ?? null;
        $outSource = $out ? 'manual' : null;
        if (! $out && count($times) >= 2) {
            $out = substr(end($times), 0, 5);
            $outSource = 'punch';
        }
        if ($out && $in && self::minutes($out) <= self::minutes($in)) {
            $out = self::clock(self::minutes($out) + 1440);   // HR typed 06:00 for a night shift: next morning
        }
        $fixed = $schedule && ! $schedule->isFlexible() && $schedule->start_time && $schedule->end_time;
        $startMin = $fixed ? self::minutes($schedule->start_time) : null;
        $endMin = $fixed ? self::minutes($schedule->end_time) + (self::minutes($schedule->end_time) <= $startMin ? 1440 : 0) : null;
        // One punch after the end of the day is not a check-in to close.
        if ($in && ! $out && $date < $today && $fixed && $schedule->missing_checkout === 'auto' && self::minutes($in) < $endMin) {
            $out = self::clock($endMin);
            $outSource = 'auto';
        }

        $result = [
            'date' => $date, 'status' => null, 'in' => $in, 'out' => $out, 'in_source' => $inSource, 'out_source' => $outSource,
            'worked' => null, 'late' => 0, 'early' => 0, 'short' => 0, 'overtime' => 0, 'punches' => count($times), 'working_day' => $working,
            'adjusted' => $adjustment !== null, 'note' => $adjustment['note'] ?? null, 'holiday' => $holiday, 'leave' => $leave,
        ];

        if ($date > $today) {
            return $result;
        }

        // ------------------------------------------------------------ no times at all
        if (! $in && ! $out) {
            $result['status'] = match (true) {
                $holiday !== null => 'Holiday',
                ! $working => 'Day off',
                $fullLeave => 'On leave',
                $schedule === null => 'No punches',
                $date === $today && (! $fixed || self::minutes($now->format('H:i')) < $endMin) => 'Not in yet',
                default => 'Absent',
            };

            return $result;
        }

        if ($in && $fixed && $working && ! $fullLeave && ($leave['part'] ?? null) !== 'first_half') {
            if (self::minutes($in) > $startMin + $schedule->late_grace_minutes) {
                $result['late'] = self::minutes($in) - $startMin;
            }
        }

        // ------------------------------------------------------------ one side missing
        if (! $in || ! $out) {
            $result['status'] = $date === $today && $in ? 'In office' : 'Missing punch';

            return $result;
        }

        // ------------------------------------------------------------ both
        $worked = max(0, self::minutes($out) - self::minutes($in));
        $result['worked'] = $worked;
        if ($fixed && $working && ! $fullLeave && ($leave['part'] ?? null) !== 'second_half') {
            if (self::minutes($out) < $endMin - $schedule->early_grace_minutes) {
                $result['early'] = $endMin - self::minutes($out);
            }
        } elseif ($schedule && $schedule->isFlexible() && $working && ! $leave && $worked < $schedule->full_day_minutes) {
            $result['short'] = $schedule->full_day_minutes - $worked;
        }
        $result['overtime'] = self::overtime($schedule, $out, $worked, $working, $endMin);
        $result['status'] = match (true) {
            ! $working => 'Worked day off',
            $schedule !== null && ! $leave && $worked < $schedule->half_day_minutes => 'Half day',
            default => 'Present',
        };

        return $result;
    }

    /** Minutes worked beyond the day, once past the schedule's tolerance. A day off counts in full. */
    private static function overtime(?WorkSchedule $schedule, string $out, int $worked, bool $working, ?int $endMin): int
    {
        if (! $schedule || $schedule->overtime_after_minutes === null) {
            return 0;
        }
        if (! $working) {
            return $worked;
        }
        $extra = $endMin !== null ? self::minutes($out) - $endMin : $worked - $schedule->full_day_minutes;

        return $extra > $schedule->overtime_after_minutes ? $extra : 0;
    }

    /**
     * Sorted "H:i:s" times without double taps: a punch within config hrm.attendance.duplicate_punch_minutes of the
     * previous kept punch is the same punch (people often tap twice).
     *
     * @param  list<string>  $punches
     * @return list<string>
     */
    public static function dedupe(array $punches): array
    {
        sort($punches);
        $gap = (int) config('hrm.attendance.duplicate_punch_minutes', 5) * 60;
        $kept = [];
        foreach ($punches as $p) {
            if (! $kept || self::seconds($p) - self::seconds(end($kept)) > $gap) {
                $kept[] = $p;
            }
        }

        return $kept;
    }

    /** "09:30" or "09:30:15" => 570 */
    public static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    /** 1980 => "33:00" (the next morning, 09:00). */
    public static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private static function seconds(string $time): int
    {
        $p = array_map('intval', explode(':', $time));

        return $p[0] * 3600 + ($p[1] ?? 0) * 60 + ($p[2] ?? 0);
    }

    /** 485 => "8h 05m" */
    public static function duration(?int $minutes): ?string
    {
        return $minutes === null ? null : sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }
}
