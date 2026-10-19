<?php

namespace App\Services;

use App\Http\Controllers\LeaveController;
use App\Models\Employee;
use Carbon\CarbonImmutable;

/** One person's month of attendance and year of leave. The HR profile and My page both show these. */
class EmployeeRecords
{
    /** One month of attendance (?month=2026-05), newest day first. */
    public static function attendanceMonth(Employee $employee, mixed $month): array
    {
        $month = is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : CarbonImmutable::today()->format('Y-m');
        $first = CarbonImmutable::parse($month.'-01');
        $now = CarbonImmutable::now();
        $board = new AttendanceBoard(collect([$employee]), $first->toDateString(), $first->endOfMonth()->toDateString());
        $days = [];
        foreach ($board->dates() as $date) {
            $day = $board->day($employee, $date, $now);
            if ($day && $day['status'] !== null) {
                $days[] = $day + [
                    'schedule' => $day['schedule_id'] ? $board->schedules->get($day['schedule_id'])?->name : null,
                    'punchTimes' => array_map(fn ($t) => substr($t, 0, 5), $board->punchesOn($employee->id, $date)),
                ];
            }
        }
        $current = $board->scheduleFor($employee->id, min($now->toDateString(), $first->endOfMonth()->toDateString()));

        return [
            'month' => $month,
            'days' => array_reverse($days),
            'counts' => array_count_values(array_column($days, 'status')) + ['Late' => count(array_filter($days, fn ($d) => $d['late'] > 0))],
            'worked' => array_sum(array_column($days, 'worked')),
            'schedule' => $current ? ['name' => $current->name, 'summary' => $current->summary()] : null,
            'linked' => $board->isLinked($employee),
            'statuses' => config('hrm.attendance.statuses'),
        ];
    }

    /** Heatmap codes: one letter per day keeps a year of days small. */
    public const YEAR_CODES = [
        'Present' => 'P', 'At work' => 'P', 'Late' => 'L', 'Half day' => 'H', 'Absent' => 'A', 'Missing punch' => 'M',
        'On leave' => 'V', 'Holiday' => 'O', 'Day off' => 'O', 'Worked day off' => 'W', 'Not in yet' => 'N', 'No data' => 'N',
        'Not linked' => 'N', 'No punches' => 'N', 'Not tracked' => 'N',
    ];

    /**
     * The last 12 months (or since the start date) of attendance, one code per day, for the profile's heatmap.
     * Same rules as everywhere else (AttendanceBoard); "Late" is Present with late minutes.
     *
     * @return array{from: string, to: string, days: array<string, string>, late: array<string, int>, counts: array<string, int>}
     */
    public static function attendanceYear(Employee $employee): array
    {
        $now = CarbonImmutable::now();
        $to = $now->toDateString();
        $from = $now->subDays(364)->toDateString();
        if ($employee->start_date && $employee->start_date->toDateString() > $from) {
            $from = $employee->start_date->toDateString();
        }
        if ($from > $to) {
            return ['from' => $to, 'to' => $to, 'days' => [], 'late' => [], 'counts' => []];
        }
        $board = new AttendanceBoard(collect([$employee]), $from, $to);
        $days = [];
        $late = [];
        foreach ($board->dates() as $date) {
            $day = $board->day($employee, $date, $now);
            if (! $day || $day['status'] === null) {
                continue;
            }
            $status = $day['status'] === 'Present' && $day['late'] > 0 ? 'Late' : $day['status'];
            $days[$date] = self::YEAR_CODES[$status] ?? 'N';
            if ($day['late'] > 0) {
                $late[$date] = $day['late'];
            }
        }

        return ['from' => $from, 'to' => $to, 'days' => $days, 'late' => $late, 'counts' => array_count_values($days)];
    }

    /** This year's leave, newest first, with days per type. */
    public static function leaveYear(Employee $employee): array
    {
        $year = CarbonImmutable::today()->year;
        $records = $employee->leaveRecords()->with('type:id,name', 'author:id,name')
            ->where('end_date', '>=', "$year-01-01")->where('start_date', '<=', "$year-12-31")->orderByDesc('start_date')->get();

        return [
            'year' => $year,
            'records' => $records->map(fn ($r) => LeaveController::recordProps($r))->values(),
            'totals' => $records->groupBy(fn ($r) => $r->type->name)->map(fn ($g) => $g->sum('days'))->all(),
        ];
    }
}
