<?php

namespace App\Services;

use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Attendance status for one person on one day: the only place it is worked out (like HrRules for onboarding).
 * Inputs are the day's punch times, HR's manual adjustment and the work schedule that applied that day.
 *
 *   sessions   the day's punches as stretches of work, one loop per source. A machine's punches are one block,
 *              first to last (a lunch break inside it counts as work). HR.my / app punches carry In/Out and pair up
 *              in order. So office 09:00-14:00 then remote 20:00-23:00 is two sessions, and the evening at home
 *              isn't work. An In with no Out, or a block with one punch, is an open session. A session lying wholly
 *              inside another (an HR.my tap during the office day) is shown but adds no time and no place
 *   check-in   HR's time, else the first session's start
 *   check-out  HR's time, else the end of the session that finishes last (still open = missing), else the
 *              schedule's end time when the schedule auto-checks-out, else missing
 *   worked     the sessions added up (overlaps counted once); HR's times move the first start and the last end
 *   late       fixed schedules: in after start + grace; counted from the start time
 *   absent     fixed schedules: not checked in by the schedule's absent cut-off (default the middle of the day, 9-5 ->
 *              13:00). No punches: Not in yet until the cut-off, then Absent. Checked in after it: Absent, with the
 *              times still shown (HR can fix the day), unless the schedule sets late_half_day_minutes and they worked
 *              at least that long: then Half day (still At work today while the session is open). Not applied on a
 *              leave day (a half day off moves the start)
 *   no clock-in schedules (type none): no punches on a working day = Not tracked; any punches = Present; never late,
 *              early, short, half day or absent
 *   early      fixed schedules: out before end - grace; counted to the end time
 *   short      flexible schedules: worked less than the day's hours
 *   overtime   worked past the end time (fixed) or past the day's hours (flexible) by more than the schedule's
 *              overtime_after_minutes; counted from the end / the day's hours. Null on the schedule = not counted.
 *              Fixed: only the session that started before the end counts; a later session (a second shift
 *              from home) is shown but not labelled overtime
 *   night      times can run past midnight: a punch on the next morning arrives as "33:00" (24 + 9). A schedule
 *              whose end is before its start (21:00-06:00) ends the next morning; HR's check-out before the
 *              check-in means the next morning too
 *   holiday    a day off like a weekend (status Holiday, or Worked day off when someone came in)
 *   leave      full day: On leave unless the person punched anyway; half day: no late (first half off) or early
 *              (second half off), and never Half day; short leave: neither late nor early, never Half day
 *
 * Statuses and their colours: config hrm.attendance.statuses.
 */
class AttendanceRules
{
    /** Where a punch puts someone: the machine is on the office wall; HR.my is remote. An app clock-in carries its own
     *  place (office IP or not, AttendanceClock); this is only the fallback. */
    public const PLACES = ['machine' => 'office', 'hrmy' => 'remote', 'app' => 'remote'];

    /**
     * @param  list<string|array{time: string, source?: ?string, method?: ?string, place?: ?string}>  $punches  "H:i:s" times
     *     on $date, any order; with the source, the In/Out when known (HR.my, app) and the place when the punch has one
     * @param  array{check_in: ?string, check_out: ?string, note?: ?string}|null  $adjustment
     * @param  array{holiday?: ?string, leave?: ?array{type: string, part: string}}  $context
     * @return array{date: string, status: ?string, in: ?string, out: ?string, in_source: ?string, out_source: ?string,
     *     worked: ?int, late: int, early: int, short: int, overtime: int, punches: int, working_day: bool, adjusted: bool,
     *     note: ?string, holiday: ?string, leave: ?array{type: string, part: string},
     *     sessions: list<array{in: ?string, out: ?string, source: string, minutes: ?int, edited: bool, in_where: ?string, out_where: ?string,
     *         inside: bool}>,  inside = wholly within another session: shown, adds no time or place
     *     where: ?string, in_where: ?string, out_where: ?string}  where = office | remote | both (PLACES), null with no punches
     *         status null = a future day (nothing to show yet)
     */
    public static function day(string $date, array $punches, ?array $adjustment, ?WorkSchedule $schedule, CarbonInterface $now, array $context = []): array
    {
        $sessions = self::sessions($punches, $count);
        $today = $now->toDateString();
        $holiday = $context['holiday'] ?? null;
        $leave = $context['leave'] ?? null;
        $fullLeave = $leave && $leave['part'] === 'full';
        $working = ($schedule === null || in_array(CarbonImmutable::parse($date)->isoWeekday(), array_map('intval', $schedule->working_days ?? []), true))
            && $holiday === null;

        // HR's times replace the first start and the last end (where the session happened stays as it was).
        $in = $adjustment['check_in'] ?? null;
        $inSource = $in ? 'manual' : null;
        if ($in && $sessions) {
            $sessions[0]['in'] = $in;
            $sessions[0]['edited'] = true;
        } elseif ($in) {
            $sessions[] = ['in' => $in, 'out' => null, 'source' => 'manual', 'in_from' => null, 'out_from' => null, 'edited' => true];
        } elseif ($sessions && $sessions[0]['in']) {
            $in = $sessions[0]['in'];
            $inSource = 'punch';
        }
        $out = $adjustment['check_out'] ?? null;
        $outSource = $out ? 'manual' : null;
        if ($out && $in && self::minutes($out) <= self::minutes($in)) {
            $out = self::clock(self::minutes($out) + 1440);   // HR typed 06:00 for a night shift: next morning
        }
        $last = self::lastSession($sessions);   // the session that finishes the day (not always the last to start)
        if ($out) {
            if ($last === null) {
                $sessions[] = ['in' => null, 'out' => $out, 'source' => 'manual', 'in_from' => null, 'out_from' => null, 'edited' => true];
                $last = 0;
            } else {
                $sessions[$last]['out'] = $out;
                $sessions[$last]['edited'] = true;
            }
        } elseif ($last !== null && $sessions[$last]['out']) {
            $out = $sessions[$last]['out'];
            $outSource = 'punch';
        }
        $fixed = $schedule && ! $schedule->isFlexible() && $schedule->start_time && $schedule->end_time;
        $startMin = $fixed ? self::minutes($schedule->start_time) : null;
        $endMin = $fixed ? self::minutes($schedule->end_time) + (self::minutes($schedule->end_time) <= $startMin ? 1440 : 0) : null;
        $absentMin = $fixed ? $schedule->absentAfterMinutes() : null;
        // One punch after the end of the day is not a check-in to close.
        if ($in && ! $out && $date < $today && $fixed && $schedule->missing_checkout === 'auto'
            && self::minutes($sessions[$last]['in'] ?? $in) < $endMin) {
            $out = self::clock($endMin);
            $outSource = 'auto';
            $sessions[$last]['out'] = $out;
        }
        foreach ($sessions as &$s) {
            $s['minutes'] = $s['in'] && $s['out'] ? max(0, self::minutes($s['out']) - self::minutes($s['in'])) : null;
            $s['edited'] ??= false;
            $s['in_where'] = $s['in_place'] ?? self::PLACES[$s['in_from'] ?? ''] ?? null;
            $s['out_where'] = $s['out'] ? ($s['out_place'] ?? self::PLACES[$s['out_from'] ?? ''] ?? null) : null;
            unset($s['in_place'], $s['out_place']);
        }
        unset($s);
        // A session wholly inside another (HR.my tapped during the office day) adds no time and doesn't make the day
        // "office + remote". Two identical sessions: the first one counts.
        $places = [];
        foreach ($sessions as $i => $s) {
            $sessions[$i]['inside'] = $s['minutes'] !== null && (bool) array_filter(array_keys($sessions), fn ($j) => $j !== $i
                && ($o = $sessions[$j])['minutes'] !== null
                && self::minutes($o['in']) <= self::minutes($s['in']) && self::minutes($s['out']) <= self::minutes($o['out'])
                && ($o['minutes'] > $s['minutes'] || $j < $i));
            if (! $sessions[$i]['inside']) {
                $places += array_flip(array_filter([$s['in_where'], $s['out_where']]));
            }
        }
        $where = match (true) {
            isset($places['office'], $places['remote']) => 'both',
            isset($places['remote']) => 'remote',
            isset($places['office']) => 'office',
            default => null,
        };

        $result = [
            'date' => $date, 'status' => null, 'in' => $in, 'out' => $out, 'in_source' => $inSource, 'out_source' => $outSource,
            'worked' => null, 'late' => 0, 'early' => 0, 'short' => 0, 'overtime' => 0, 'punches' => $count, 'working_day' => $working,
            'adjusted' => $adjustment !== null, 'note' => $adjustment['note'] ?? null, 'holiday' => $holiday, 'leave' => $leave,
            'sessions' => $sessions, 'where' => $where,
            'in_where' => $in ? ($sessions[0]['in_where'] ?? null) : null,
            'out_where' => $out && $outSource !== 'auto' ? ($sessions[$last]['out_where'] ?? null) : null,
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
                $schedule->isUntracked() => 'Not tracked',
                $date === $today && (! $fixed || self::minutes($now->format('H:i')) < $absentMin) => 'Not in yet',
                default => 'Absent',
            };

            return $result;
        }

        // A lone punch after the end of the day is a forgotten check-in (Missing punch), not a late arrival.
        if ($in && $fixed && $working && ! $leave && self::minutes($in) > $absentMin && ($out || self::minutes($in) < $endMin)) {
            $worked = self::worked($sessions);
            $halfAfter = $schedule->late_half_day_minutes;
            $result['worked'] = $worked ?: null;
            $result['status'] = match (true) {
                $halfAfter === null => 'Absent',
                $worked >= $halfAfter => 'Half day',
                $date === $today && ! $out => 'At work',
                default => 'Absent',
            };

            return $result;
        }

        if ($schedule && $schedule->isUntracked()) {
            $result['worked'] = self::worked($sessions) ?: null;
            $result['status'] = match (true) {
                ! $working => 'Worked day off',
                $date === $today && ! $out => 'At work',
                default => 'Present',
            };

            return $result;
        }

        if ($in && $fixed && $working && ! $fullLeave && ! in_array($leave['part'] ?? null, ['first_half', 'short'], true)) {
            if (self::minutes($in) > $startMin + $schedule->late_grace_minutes) {
                $result['late'] = self::minutes($in) - $startMin;
            }
        }

        // ------------------------------------------------------------ one side missing
        if (! $in || ! $out) {
            $result['status'] = $date === $today && $in ? 'At work' : 'Missing punch';
            $result['worked'] = self::worked($sessions) ?: null;   // a split day still shows the sessions that closed

            return $result;
        }

        // ------------------------------------------------------------ both
        $worked = self::worked($sessions);
        $result['worked'] = $worked;
        if ($fixed && $working && ! $fullLeave && ! in_array($leave['part'] ?? null, ['second_half', 'short'], true)) {
            if (self::minutes($out) < $endMin - $schedule->early_grace_minutes) {
                $result['early'] = $endMin - self::minutes($out);
            }
        } elseif ($schedule && $schedule->isFlexible() && $working && ! $leave && $worked < $schedule->full_day_minutes) {
            $result['short'] = $schedule->full_day_minutes - $worked;
        }
        // Fixed: overtime runs on from the session that started before the end, not from a later one.
        $dayOut = $out;
        if ($endMin !== null) {
            $before = array_filter($sessions, fn ($s) => $s['in'] && $s['out'] && self::minutes($s['in']) < $endMin);
            // The latest end among them; only after-hours sessions: nothing runs on past the end.
            $dayOut = $before ? self::clock(max(array_map(fn ($s) => self::minutes($s['out']), $before))) : null;
        }
        $result['overtime'] = $dayOut === null && $working ? 0 : self::overtime($schedule, $dayOut ?? $out, $worked, $working, $endMin);
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
     * The day's punches as sessions, in time order. Each source is its own loop: HR.my / app In/Out pairs make one
     * session each; machine (and unlabelled) punches make one block from first to last, whatever other sources did
     * in between. An Out with no In ends the block before it (in at the office, out from home). Known limit: the
     * machine has no In/Out, so office, home, office again the same day stays one block (the gap counts as work).
     *
     * @param  list<string|array{time: string, source?: ?string, method?: ?string, place?: ?string}>  $punches
     * @param  int|null  $count  set to the number of punches kept (double taps dropped)
     * @return list<array{in: ?string, out: ?string, source: string, in_from: ?string, out_from: ?string, in_place?: ?string,
     *     out_place?: ?string}> source machine | hrmy | app | mixed (in at the office, out from home); in_from / out_from =
     *     the source of each end; in_place / out_place = the punch's own place (app clock-ins), else PLACES by source
     */
    public static function sessions(array $punches, ?int &$count = null): array
    {
        $labelled = [];
        $plain = [];
        foreach ($punches as $p) {
            $p = is_string($p) ? ['time' => $p] : $p;
            $method = ucfirst(strtolower((string) ($p['method'] ?? '')));
            if (in_array($method, ['In', 'Out'], true)) {
                $labelled[] = ['time' => $p['time'], 'source' => $p['source'] ?? 'hrmy', 'method' => $method, 'place' => $p['place'] ?? null];
            } else {
                $plain[$p['source'] ?? 'machine'][] = $p['time'];
            }
        }
        usort($labelled, fn ($a, $b) => strcmp($a['time'], $b['time']));
        $count = count($labelled);

        // 1. HR.my: each In with the Out after it. An Out with nothing open is kept aside for step 3.
        $remote = [];
        $orphans = [];
        $open = null;
        foreach ($labelled as $p) {
            $t = substr($p['time'], 0, 5);
            if ($p['method'] === 'In') {
                $open !== null && $remote[] = $open;
                $open = ['in' => $t, 'out' => null, 'source' => $p['source'], 'in_from' => $p['source'], 'out_from' => null, 'in_place' => $p['place']];
            } elseif ($open !== null) {
                $remote[] = ['in' => $open['in'], 'out' => $t, 'source' => $open['source'] === $p['source'] ? $p['source'] : 'mixed',
                    'in_from' => $open['in_from'], 'out_from' => $p['source'], 'in_place' => $open['in_place'], 'out_place' => $p['place']];
                $open = null;
            } else {
                $orphans[] = ['time' => $t, 'source' => $p['source'], 'place' => $p['place']];
            }
        }
        $open !== null && $remote[] = $open;

        // 2. Machine: all of a source's punches are one block, first to last. Other sources never split it.
        $blocks = [];
        foreach ($plain as $source => $times) {
            $times = self::dedupe($times);
            $count += count($times);
            if ($times) {
                $blocks[] = ['source' => $source, 'in_from' => $source, 'times' => array_map(fn ($t) => substr($t, 0, 5), $times)];
            }
        }

        // 3. An Out with no In ends the block before it (in at the office, out from home).
        foreach ($orphans as $o) {
            $before = array_filter(array_keys($blocks), fn ($k) => strcmp(end($blocks[$k]['times']), $o['time']) < 0);
            if ($before) {
                $k = end($before);
                $blocks[$k]['times'][] = $o['time'];
                $blocks[$k]['out_from'] = $o['source'];
                $blocks[$k]['out_place'] = $o['place'];
                $blocks[$k]['source'] = $blocks[$k]['source'] === $o['source'] ? $o['source'] : 'mixed';
            } else {
                $remote[] = ['in' => null, 'out' => $o['time'], 'source' => $o['source'], 'in_from' => null, 'out_from' => $o['source'], 'out_place' => $o['place']];
            }
        }

        $sessions = $remote;
        foreach ($blocks as $b) {
            $sessions[] = ['in' => $b['times'][0], 'out' => count($b['times']) > 1 ? end($b['times']) : null, 'source' => $b['source'],
                'in_from' => $b['in_from'], 'out_from' => count($b['times']) > 1 ? ($b['out_from'] ?? $b['in_from']) : null,
                'out_place' => count($b['times']) > 1 ? ($b['out_place'] ?? null) : null];
        }
        usort($sessions, fn ($a, $b) => strcmp($a['in'] ?? $a['out'], $b['in'] ?? $b['out']));

        return $sessions;
    }

    /** Index of the session that finishes the day: the latest end (or start, while open); a tie goes to the later one. */
    private static function lastSession(array $sessions): ?int
    {
        $last = null;
        foreach ($sessions as $i => $s) {
            $at = self::minutes($s['out'] ?? $s['in']);
            if ($last === null || $at >= $best) {
                [$last, $best] = [$i, $at];
            }
        }

        return $last;
    }

    /** Minutes covered by the closed sessions, overlaps counted once. */
    private static function worked(array $sessions): int
    {
        $spans = [];
        foreach ($sessions as $s) {
            if ($s['in'] && $s['out']) {
                $spans[] = [self::minutes($s['in']), max(self::minutes($s['in']), self::minutes($s['out']))];
            }
        }
        sort($spans);
        $total = 0;
        $end = -1;
        foreach ($spans as [$a, $b]) {
            $a = max($a, $end);
            if ($b > $a) {
                $total += $b - $a;
                $end = $b;
            }
        }

        return $total;
    }

    /** "09:00-14:00 office, 20:00-23:00 remote": the sessions in a line, for reports. "(HR)" = HR changed a time. */
    public static function sessionsText(array $sessions): string
    {
        return implode(', ', array_map(function ($s) {
            $where = $s['in_where'] ?? null;
            $outWhere = $s['out_where'] ?? null;
            $place = $where && $outWhere && $where !== $outWhere ? "$where to $outWhere" : ($where ?? $outWhere ?? '');

            return trim(($s['in'] ?? '?').'-'.($s['out'] ?? '?').' '.$place.(! empty($s['edited']) ? ' (HR)' : '').(! empty($s['inside']) ? ' (within)' : ''));
        }, $sessions));
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
