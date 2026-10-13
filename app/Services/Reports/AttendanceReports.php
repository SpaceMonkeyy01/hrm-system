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
        'Holiday' => 'H', 'Day off' => 'O', 'Worked day off' => 'W', 'In office' => 'IN', 'Not in yet' => '', 'No punches' => '?',
        'Not linked' => 'NL', 'No data' => '-',
    ];

    /** @return array{0: Collection<int, Employee>, 1: AttendanceBoard} */
    private function board(ReportFilters $f, string $from, string $to): array
    {
        $employees = $f->employedBetween($from, $to)->with('department:id,name')->orderBy('full_name')->get(self::EMPLOYEE_COLUMNS);

        return [$employees, new AttendanceBoard($employees, $from, $to)];
    }

    private static function source(Employee $e): string
    {
        return match (true) {
            $e->attendance_machine_id && $e->hr_portal_id => 'Machine and HR.my',
            (bool) $e->attendance_machine_id => 'Machine',
            (bool) $e->hr_portal_id => 'HR.my',
            default => 'Not linked',
        };
    }

    private function notLinkedNote(ReportResult $r, Collection $employees): void
    {
        $n = $employees->filter(fn ($e) => ! $e->attendance_machine_id && ! $e->hr_portal_id)->count();
        if ($n) {
            $r->note("$n of {$employees->count()} people have no machine or HR.my ID yet, so they have no punches and show as Not linked. Link them on Attendance > Upload attendance file.");
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
            $row = ['employee' => ReportResult::person($e), 'department' => $e->department?->name, 'source' => self::source($e),
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
                    'Present', 'In office' => $row['present']++,
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
            .' Office = punched on the machine; remote = clocked in on HR.my; office + remote = both on the same day.');
        $this->notLinkedNote($r, $employees);

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
                $key = 'd'.substr($date, 8, 2);
                if (! $day || $day['status'] === null) {
                    $row[$key] = ['code' => '', 'tone' => null, 'title' => $day ? 'Not yet' : 'Not employed'];

                    continue;
                }
                $status = $day['status'] === 'Present' && $day['late'] > 0 ? 'Late' : $day['status'];
                $title = $status.(count($day['sessions']) > 1 ? ' '.AttendanceRules::sessionsText($day['sessions'])
                    : ($day['in'] ? " {$day['in']}".($day['out'] ? "-{$day['out']}" : '') : ''))
                    .($day['late'] ? ", late {$day['late']} min" : '').($day['leave'] ? ", {$day['leave']['type']}" : '').($day['holiday'] ? ", {$day['holiday']}" : '');
                $row[$key] = ['code' => self::CODES[$status] ?? '?', 'tone' => $tones[$status] ?? 'neutral', 'title' => $title];
                $row['p'] += in_array($status, ['Present', 'Late', 'In office'], true) ? 1 : 0;
                $row['l'] += $status === 'Late' ? 1 : 0;
                $row['a'] += $status === 'Absent' ? 1 : 0;
                $row['lv'] += $status === 'On leave' ? 1 : 0;
            }
            $r->rows[] = $row;
        }

        $r->note('P present, L late, HD half day, A absent, MP missing punch, LV on leave, H holiday, O day off, W worked a day off, IN in office now, NL not linked, - no attendance file for that day. Hover a cell for the times.');
        $this->notLinkedNote($r, $employees);

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
        $this->notLinkedNote($r, $employees);

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
