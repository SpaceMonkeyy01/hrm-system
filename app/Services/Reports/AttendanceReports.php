<?php

namespace App\Services\Reports;

use App\Models\Employee;
use App\Services\AttendanceBoard;
use App\Services\AttendanceRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Attendance reports. Every day goes through AttendanceBoard / AttendanceRules, exactly as on the
 * Attendance page, so a report never disagrees with the daily screen.
 */
class AttendanceReports
{
    private const EMPLOYEE_COLUMNS = ['id', 'employee_code', 'full_name', 'department_id', 'company_id', 'start_date', 'exit_date',
        'status', 'attendance_machine_id', 'hr_portal_id'];

    /** Sheet codes. Late is Present with late minutes. */
    public const CODES = [
        'Present' => 'P', 'Late' => 'L', 'Half day' => 'HD', 'Absent' => 'A', 'Missing punch' => 'MP', 'On leave' => 'LV',
        'Holiday' => 'H', 'Day off' => 'O', 'Worked day off' => 'W', 'At work' => 'IN', 'Not in yet' => '', 'No punches' => '?',
        'Not linked' => 'NL', 'No data' => '-',
    ];

    /** @return array{0: Collection<int, Employee>, 1: AttendanceBoard} */
    private function board(ReportFilters $f, string $from, string $to): array
    {
        $employees = $f->employedBetween($from, $to)->with('department:id,name')->orderBy('full_name')->get(self::EMPLOYEE_COLUMNS);

        return [$employees, new AttendanceBoard($employees, $from, $to)];
    }

    /** "Machine", "Machine and HR.my", "HR.my and app" ... or Not linked. */
    private static function source(Employee $e, AttendanceBoard $board): string
    {
        $from = array_keys(array_filter(['Machine' => (bool) $e->attendance_machine_id, 'HR.my' => (bool) $e->hr_portal_id,
            'app' => $board->usesApp($e)]));
        if (! $from) {
            return 'Not linked';
        }
        $last = array_pop($from);

        return ucfirst($from ? implode(', ', $from).' and '.$last : $last);
    }

    private function notLinkedNote(ReportResult $r, Collection $employees, AttendanceBoard $board): void
    {
        $n = $employees->filter(fn ($e) => ! $board->isLinked($e))->count();
        if ($n) {
            $r->note("$n of {$employees->count()} people have no machine or HR.my ID yet and haven't clocked in on the app, so they have no punches and show as Not linked. Link them on Attendance > Upload attendance file.");
        }
    }

    // ------------------------------------------------------------------ monthly summary

    public function monthly(ReportFilters $f): ReportResult
    {
        $from = $f->monthStart()->toDateString();
        $to = $f->monthEnd()->toDateString();
        [$employees, $board] = $this->board($f, $from, $to);
        $now = CarbonImmutable::now();

        $r = (new ReportResult)
            ->column('employee', 'Employee', 'employee')
            ->column('department', 'Department')
            ->column('source', 'Punches from')
            ->column('working_days', 'Working days', 'number')
            ->column('present', 'Present', 'number')
            ->column('late', 'Late', 'number')
            ->column('late_minutes', 'Late by', 'minutes')
            ->column('half_day', 'Half days', 'number')
            ->column('absent', 'Absent', 'number')
            ->column('missing', 'Missing punch', 'number')
            ->column('leave', 'On leave', 'number')
            ->column('holiday', 'Holidays', 'number')
            ->column('day_off_worked', 'Worked day off', 'number')
            ->column('office_days', 'Office days', 'number')
            ->column('remote_days', 'Remote days', 'number')
            ->column('split_days', 'Office + remote days', 'number')
            ->column('worked', 'Hours worked', 'minutes')
            ->column('overtime', 'Overtime', 'minutes');

        $totals = ['present' => 0, 'late' => 0, 'absent' => 0, 'working_days' => 0, 'overtime' => 0, 'remote_days' => 0, 'split_days' => 0];
        foreach ($employees as $e) {
            $row = ['employee' => ReportResult::person($e), 'department' => $e->department?->name, 'source' => self::source($e, $board),
                'working_days' => 0, 'present' => 0, 'late' => 0, 'late_minutes' => 0, 'half_day' => 0, 'absent' => 0, 'missing' => 0,
                'leave' => 0, 'holiday' => 0, 'day_off_worked' => 0, 'office_days' => 0, 'remote_days' => 0, 'split_days' => 0, 'worked' => 0, 'overtime' => 0];
            foreach ($board->dates() as $date) {
                $day = $board->day($e, $date, $now);
                if (! $day || $day['status'] === null) {
                    continue;
                }
                // A day no file covers (or a person with no attendance ID) isn't a working day anyone can count.
                $row['working_days'] += $day['working_day'] && ! in_array($day['status'], ['No data', 'Not linked'], true) ? 1 : 0;
                $row['late'] += $day['late'] > 0 ? 1 : 0;
                $row['late_minutes'] += $day['late'];
                $row['worked'] += $day['worked'] ?? 0;
                $row['overtime'] += $day['overtime'];
                match ($day['where']) {
                    'office' => $row['office_days']++,
                    'remote' => $row['remote_days']++,
                    'both' => $row['split_days']++,
                    default => null,
                };
                match ($day['status']) {
                    'Present', 'At work' => $row['present']++,
                    'Half day' => $row['half_day']++,
                    'Absent' => $row['absent']++,
                    'Missing punch' => $row['missing']++,
                    'On leave' => $row['leave']++,
                    'Holiday' => $row['holiday']++,
                    'Worked day off' => $row['day_off_worked']++,
                    default => null,
                };
            }
            foreach ($totals as $k => $_) {
                $totals[$k] += $row[$k];
            }
            $r->rows[] = $row;
        }

        $r->stat('People', $employees->count())
            ->stat('Present days', $totals['present'], 'done')
            ->stat('Late arrivals', $totals['late'], $totals['late'] ? 'pending' : null)
            ->stat('Absent days', $totals['absent'], $totals['absent'] ? 'missing' : null)
            ->stat('Remote days', $totals['remote_days'] + $totals['split_days'])
            ->stat('Overtime', sprintf('%dh %02dm', intdiv($totals['overtime'], 60), $totals['overtime'] % 60));
        $r->note('Covers '.CarbonImmutable::parse($from)->format('j M').' to '.CarbonImmutable::parse($to)->format('j M Y').'. Present includes late arrivals.'
            .' Office = punched on the machine or clocked in on the app from an office network; remote = HR.my or the app elsewhere; office + remote = both on the same day.');
        $this->notLinkedNote($r, $employees, $board);

        return $r;
    }

    private const WHERE = ['office' => 'Office', 'remote' => 'Remote', 'both' => 'Office + remote'];

    // ------------------------------------------------------------------ day-by-day sheet

    public function sheet(ReportFilters $f): ReportResult
    {
        $from = $f->monthStart()->toDateString();
        $last = $f->monthStart()->endOfMonth();
        // The whole month gets a column; days after today stay blank.
        [$employees, $board] = $this->board($f, $from, $last->toDateString());
        $now = CarbonImmutable::now();
        $tones = config('hrm.attendance.statuses') + ['Late' => 'pending'];

        $r = (new ReportResult)->column('employee', 'Employee', 'employee');
        foreach ($board->dates() as $date) {
            $d = CarbonImmutable::parse($date);
            $r->column('d'.$d->format('d'), $d->format('j'), 'day', ['group' => $d->format('D')]);
        }
        $r->column('p', 'P', 'number')->column('l', 'L', 'number')->column('a', 'A', 'number')->column('lv', 'LV', 'number');

        foreach ($employees as $e) {
            $row = ['employee' => ReportResult::person($e), 'p' => 0, 'l' => 0, 'a' => 0, 'lv' => 0];
            foreach ($board->dates() as $date) {
                $day = $board->day($e, $date, $now);
                $row['d'.substr($date, 8, 2)] = self::cell($day, $tones);
                $status = self::shownStatus($day);
                $row['p'] += in_array($status, ['Present', 'Late', 'At work'], true) ? 1 : 0;
                $row['l'] += $status === 'Late' ? 1 : 0;
                $row['a'] += $status === 'Absent' ? 1 : 0;
                $row['lv'] += $status === 'On leave' ? 1 : 0;
            }
            $r->rows[] = $row;
        }

        $r->note(self::CODES_NOTE);
        $this->notLinkedNote($r, $employees, $board);

        return $r;
    }

    private const CODES_NOTE = 'P present, L late, HD half day, A absent, MP missing punch, LV on leave, H holiday, O day off, W worked a day off, IN at work now, NL not linked, - no attendance data for that day. Hover a cell for the times.';

    /** The day's status with late arrivals shown as Late; null when there's nothing to show (not employed, not yet). */
    private static function shownStatus(?array $day): ?string
    {
        if (! $day || $day['status'] === null) {
            return null;
        }

        return $day['status'] === 'Present' && $day['late'] > 0 ? 'Late' : $day['status'];
    }

    /** One coloured code for a day on the sheets, with the times in the tooltip. */
    private static function cell(?array $day, array $tones): array
    {
        $status = self::shownStatus($day);
        if ($status === null) {
            return ['code' => '', 'tone' => null, 'title' => $day ? 'Not yet' : 'Not employed'];
        }
        $title = $status.(count($day['sessions']) > 1 ? ' '.AttendanceRules::sessionsText($day['sessions'])
            : ($day['in'] ? " {$day['in']}".($day['out'] ? "-{$day['out']}" : '') : ''))
            .($day['late'] ? ", late {$day['late']} min" : '').($day['leave'] ? ", {$day['leave']['type']}" : '').($day['holiday'] ? ", {$day['holiday']}" : '');

        return ['code' => self::CODES[$status] ?? '?', 'tone' => $tones[$status] ?? 'neutral', 'title' => $title];
    }

    // ------------------------------------------------------------------ daily

    /** Everyone employed on the day, one line each, as on the Attendance page. */
    public function daily(ReportFilters $f): ReportResult
    {
        [$employees, $board] = $this->board($f, $f->day, $f->day);
        $now = CarbonImmutable::now();
        $tones = config('hrm.attendance.statuses') + ['Late' => 'pending'];
        $r = (new ReportResult)->column('employee', 'Employee', 'employee')->column('department', 'Department')
            ->column('schedule', 'Schedule')->column('status', 'Status', 'status', ['tones' => $tones])->column('where', 'Where')
            ->column('in', 'In')->column('out', 'Out')->column('sessions', 'Sessions')->column('worked', 'Hours worked', 'minutes')
            ->column('late', 'Late by', 'minutes')->column('early', 'Left early by', 'minutes')->column('overtime', 'Overtime', 'minutes')
            ->column('note', 'Note');

        $count = [];
        foreach ($employees as $e) {
            $day = $board->day($e, $f->day, $now);
            $status = self::shownStatus($day);
            if ($status === null) {
                continue;
            }
            $count[$status] = ($count[$status] ?? 0) + 1;
            $r->rows[] = [
                'employee' => ReportResult::person($e), 'department' => $e->department?->name,
                'schedule' => $day['schedule_id'] ? $board->schedules->get($day['schedule_id'])?->name : null,
                'status' => $status, 'where' => self::WHERE[$day['where']] ?? null, 'in' => $day['in'], 'out' => $day['out'],
                'sessions' => count($day['sessions']) > 1 ? AttendanceRules::sessionsText($day['sessions']) : null,
                'worked' => $day['worked'] ?? 0, 'late' => $day['late'], 'early' => $day['early'], 'overtime' => $day['overtime'],
                'note' => $day['note'] ?? $day['leave']['type'] ?? $day['holiday'],
            ];
        }
        $present = ($count['Present'] ?? 0) + ($count['Late'] ?? 0) + ($count['At work'] ?? 0);
        $late = $count['Late'] ?? 0;
        $absent = $count['Absent'] ?? 0;
        $r->stat('People', count($r->rows))->stat('Present', $present, 'done')->stat('Late', $late, $late ? 'pending' : null)
            ->stat('Absent', $absent, $absent ? 'missing' : null)->stat('On leave', $count['On leave'] ?? 0)
            ->stat('Missing punch', $count['Missing punch'] ?? 0, ($count['Missing punch'] ?? 0) ? 'pending' : null);
        $r->note(CarbonImmutable::parse($f->day)->format('l j F Y').'. Present includes late arrivals.'
            .($f->day === $f->today->toDateString() ? ' Today: Not in yet turns into Absent after the end of the working day.' : ''));
        $this->notLinkedNote($r, $employees, $board);

        return $r;
    }

    // ------------------------------------------------------------------ weekly

    /** Monday to Sunday, a code per day plus the week's totals. */
    public function weekly(ReportFilters $f): ReportResult
    {
        $from = $f->weekStart()->toDateString();
        [$employees, $board] = $this->board($f, $from, $f->weekEnd()->toDateString());
        $now = CarbonImmutable::now();
        $tones = config('hrm.attendance.statuses') + ['Late' => 'pending'];

        $r = (new ReportResult)->column('employee', 'Employee', 'employee')->column('department', 'Department');
        foreach ($board->dates() as $date) {
            $d = CarbonImmutable::parse($date);
            $r->column('d'.$d->format('Ymd'), $d->format('j M'), 'day', ['group' => $d->format('D')]);
        }
        $r->column('present', 'Present', 'number')->column('late', 'Late', 'number')->column('late_minutes', 'Late by', 'minutes')
            ->column('absent', 'Absent', 'number')->column('missing', 'Missing punch', 'number')->column('leave', 'On leave', 'number')
            ->column('worked', 'Hours worked', 'minutes')->column('overtime', 'Overtime', 'minutes');

        $totals = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'worked' => 0];
        foreach ($employees as $e) {
            $row = ['employee' => ReportResult::person($e), 'department' => $e->department?->name, 'present' => 0, 'late' => 0,
                'late_minutes' => 0, 'absent' => 0, 'missing' => 0, 'leave' => 0, 'worked' => 0, 'overtime' => 0];
            foreach ($board->dates() as $date) {
                $day = $board->day($e, $date, $now);
                $row['d'.str_replace('-', '', $date)] = self::cell($day, $tones);
                $status = self::shownStatus($day);
                if ($status === null) {
                    continue;
                }
                $row['present'] += in_array($status, ['Present', 'Late', 'At work'], true) ? 1 : 0;
                $row['late'] += $day['late'] > 0 ? 1 : 0;
                $row['late_minutes'] += $day['late'];
                $row['absent'] += $status === 'Absent' ? 1 : 0;
                $row['missing'] += $status === 'Missing punch' ? 1 : 0;
                $row['leave'] += $status === 'On leave' ? 1 : 0;
                $row['worked'] += $day['worked'] ?? 0;
                $row['overtime'] += $day['overtime'];
            }
            foreach ($totals as $k => $_) {
                $totals[$k] += $row[$k];
            }
            $r->rows[] = $row;
        }

        $r->stat('People', $employees->count())->stat('Present days', $totals['present'], 'done')
            ->stat('Late arrivals', $totals['late'], $totals['late'] ? 'pending' : null)
            ->stat('Absent days', $totals['absent'], $totals['absent'] ? 'missing' : null)->stat('Leave days', $totals['leave'])
            ->stat('Hours worked', sprintf('%dh %02dm', intdiv($totals['worked'], 60), $totals['worked'] % 60));
        $r->note('Week of '.$f->weekStart()->format('l j M').' to '.$f->weekEnd()->format('l j M Y').'. '.self::CODES_NOTE);
        $this->notLinkedNote($r, $employees, $board);

        return $r;
    }

    // ------------------------------------------------------------------ day lists

    /** One row per matching day in the date range. */
    private function days(ReportFilters $f, callable $keep, ReportResult $r, callable $row): array
    {
        [$employees, $board] = $this->board($f, $f->from, $f->to);
        $now = CarbonImmutable::now();
        $people = [];
        foreach ($board->dates() as $date) {
            foreach ($employees as $e) {
                $day = $board->day($e, $date, $now);
                if ($day && $day['status'] !== null && $keep($day)) {
                    $r->rows[] = ['date' => $date, 'employee' => ReportResult::person($e), 'department' => $e->department?->name]
                        + $row($day, $board, $e);
                    $people[$e->id] = true;
                }
            }
        }
        $this->notLinkedNote($r, $employees, $board);

        return $people;
    }

    public function late(ReportFilters $f): ReportResult
    {
        $r = (new ReportResult)->column('date', 'Date', 'date')->column('employee', 'Employee', 'employee')
            ->column('department', 'Department')->column('schedule', 'Schedule')->column('in', 'Checked in')->column('late', 'Late by', 'minutes');
        $people = $this->days($f, fn ($d) => $d['late'] > 0, $r, fn ($d, $board) => [
            'schedule' => $d['schedule_id'] ? $board->schedules->get($d['schedule_id'])?->name : null, 'in' => $d['in'], 'late' => $d['late'],
        ]);
        $minutes = array_sum(array_column($r->rows, 'late'));
        $r->stat('Late arrivals', count($r->rows), count($r->rows) ? 'pending' : null)->stat('People', count($people))
            ->stat('Average', count($r->rows) ? round($minutes / count($r->rows)).' min' : '0 min');

        return $r;
    }

    /** Every day with a time on it, and each stretch of work: office blocks and HR.my sessions. */
    public function log(ReportFilters $f): ReportResult
    {
        $tones = config('hrm.attendance.statuses');
        $r = (new ReportResult)->column('date', 'Date', 'date')->column('employee', 'Employee', 'employee')
            ->column('department', 'Department')->column('status', 'Status', 'status', ['tones' => $tones])
            ->column('where', 'Where')->column('in', 'First in')->column('out', 'Last out')->column('sessions', 'Sessions')
            ->column('worked', 'Hours worked', 'minutes')->column('punches', 'Punches', 'number')->column('note', 'HR note');
        $people = $this->days($f, fn ($d) => $d['sessions'] !== [], $r, fn ($d) => [
            'status' => $d['status'], 'where' => self::WHERE[$d['where']] ?? null, 'in' => $d['in'], 'out' => $d['out'], 'sessions' => AttendanceRules::sessionsText($d['sessions']),
            'worked' => $d['worked'] ?? 0, 'punches' => $d['punches'], 'note' => $d['note'],
        ]);
        $where = array_count_values(array_filter(array_column($r->rows, 'where')));
        $both = $where[self::WHERE['both']] ?? 0;
        $r->stat('Days', count($r->rows))->stat('People', count($people))
            ->stat('Remote', $where[self::WHERE['remote']] ?? 0)->stat('Office + remote', $both, $both ? 'info' : null)
            ->note('Office = the attendance machine; remote = HR.my. Machine punches in a row are one session; each HR.my clock-in and clock-out is one session. The time between sessions is not counted as worked. (HR) = HR changed the time.');

        return $r;
    }

    public function absences(ReportFilters $f): ReportResult
    {
        $tones = config('hrm.attendance.statuses');
        $r = (new ReportResult)->column('date', 'Date', 'date')->column('employee', 'Employee', 'employee')
            ->column('department', 'Department')->column('status', 'Status', 'status', ['tones' => $tones])
            ->column('in', 'In')->column('out', 'Out')->column('note', 'HR note');
        $people = $this->days($f, fn ($d) => in_array($d['status'], ['Absent', 'Missing punch', 'Half day'], true), $r,
            fn ($d) => ['status' => $d['status'], 'in' => $d['in'], 'out' => $d['out'], 'note' => $d['note']]);
        $count = array_count_values(array_column($r->rows, 'status'));
        $r->stat('Absent', $count['Absent'] ?? 0, ($count['Absent'] ?? 0) ? 'missing' : null)
            ->stat('Missing punch', $count['Missing punch'] ?? 0, ($count['Missing punch'] ?? 0) ? 'pending' : null)
            ->stat('Half day', $count['Half day'] ?? 0)->stat('People', count($people));

        return $r;
    }

    public function overtime(ReportFilters $f): ReportResult
    {
        [$employees, $board] = $this->board($f, $f->from, $f->to);
        $now = CarbonImmutable::now();
        $r = (new ReportResult)->column('employee', 'Employee', 'employee')->column('department', 'Department')
            ->column('days', 'Days with overtime', 'number')->column('overtime', 'Overtime', 'minutes')->column('worked', 'Hours worked', 'minutes');
        foreach ($employees as $e) {
            $row = ['employee' => ReportResult::person($e), 'department' => $e->department?->name, 'days' => 0, 'overtime' => 0, 'worked' => 0];
            foreach ($board->dates() as $date) {
                $day = $board->day($e, $date, $now);
                if ($day && $day['status'] !== null) {
                    $row['days'] += $day['overtime'] > 0 ? 1 : 0;
                    $row['overtime'] += $day['overtime'];
                    $row['worked'] += $day['worked'] ?? 0;
                }
            }
            if ($row['overtime'] > 0) {
                $r->rows[] = $row;
            }
        }
        usort($r->rows, fn ($a, $b) => $b['overtime'] <=> $a['overtime']);
        $total = array_sum(array_column($r->rows, 'overtime'));
        $r->stat('People with overtime', count($r->rows))->stat('Total overtime', sprintf('%dh %02dm', intdiv($total, 60), $total % 60));
        if ($board->schedules->every(fn ($s) => $s->overtime_after_minutes === null)) {
            $r->note('No work schedule counts overtime yet. Set "Overtime after" on a schedule under Work schedules.');
        }

        return $r;
    }
}
