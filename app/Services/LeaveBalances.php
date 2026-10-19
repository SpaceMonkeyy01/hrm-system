<?php

namespace App\Services;

use App\Http\Controllers\LeaveController;
use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Leave balances, from the leave types' allowances (company policy, 2026-10-19):
 *
 *   per year     leave_types.yearly_days working days for the calendar year (Annual 14, Casual 10, Sick 6).
 *                Someone who started during the year gets the share for the days left from their start date,
 *                rounded to the nearest half day. Unused days lapse: every year starts fresh.
 *                used = working days of that type falling in the year (a leave across 31 Dec is split), plus
 *                leave_adjustments of that type (opening balances from HR's sheet).
 *   deductions   absences and late sign-ins / early sign-offs, weighted by LeaveSettings (HR's sheet: 1 and 0.25),
 *                counted from attendance on and after LeaveSettings deductions_from (empty = none), plus the
 *                deductions in an uploaded sheet (leave_adjustments without a type). They come out of Casual until
 *                Casual is used up, then Annual (which can go below zero).
 *                left can go below zero: HR records approved leave, the app only shows it went over.
 *   per leave    leave_types.per_leave_days calendar days each time (Maternity 90, Wedding 14, Paternity 7).
 *                No yearly balance; recording a longer leave is allowed with a warning (overLimit).
 */
class LeaveBalances
{
    /** Allowance for a year: full, or pro-rated from a start date inside the year; 0 if they start after it. */
    public static function allowance(float $yearly, ?CarbonInterface $start, int $year): float
    {
        $first = CarbonImmutable::create($year, 1, 1);
        $last = CarbonImmutable::create($year, 12, 31);
        if ($start === null || $start->lte($first)) {
            return $yearly;
        }
        if ($start->gt($last)) {
            return 0.0;
        }
        $share = ($start->diffInDays($last) + 1) / ($first->diffInDays($last) + 1);

        return round($yearly * $share * 2) / 2;
    }

    /** @return Collection<int, LeaveType> active types with a yearly allowance */
    public static function yearlyTypes(): Collection
    {
        return LeaveType::query()->whereNotNull('yearly_days')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Balances for many people at once.
     *
     * @param  Collection<int, Employee>  $employees  with id, start_date, company_id
     * @return array<int, list<array{type_id: int, type: string, allowance: float, used: float, deducted: float, left: float}>> employee id => one row per yearly type
     */
    public static function forEmployees(Collection $employees, int $year, ?Collection $types = null): array
    {
        return array_map(fn ($s) => $s['balances'], self::summary($employees, $year, $types));
    }

    /** @return list<array{type_id: int, type: string, allowance: float, used: float, deducted: float, left: float}> */
    public static function forEmployee(Employee $employee, int $year): array
    {
        return self::forEmployees(collect([$employee]), $year)[$employee->id];
    }

    /**
     * Balances plus where the deductions came from.
     *
     * @return array<int, array{balances: list<array>, deductions: array{absent: int, late_early: int, sheet: float, days: float}}>
     */
    public static function summary(Collection $employees, int $year, ?Collection $types = null): array
    {
        $types ??= self::yearlyTypes();
        $from = "$year-01-01";
        $to = "$year-12-31";
        $ids = $employees->pluck('id')->all();
        $used = [];
        $sheetDeductions = [];
        if ($employees->isNotEmpty()) {
            $byId = $employees->keyBy('id');
            if ($types->isNotEmpty()) {
                $records = LeaveRecord::query()->whereIn('employee_id', $ids)->whereIn('leave_type_id', $types->pluck('id'))
                    ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
                    ->get(['employee_id', 'leave_type_id', 'start_date', 'end_date', 'part', 'days']);
                foreach ($records as $r) {
                    $inside = $r->start_date->toDateString() >= $from && $r->end_date->toDateString() <= $to;
                    $days = $inside ? (float) $r->days : LeaveController::workingDays($byId[$r->employee_id],
                        max($r->start_date->toDateString(), $from), min($r->end_date->toDateString(), $to), $r->part);
                    $used[$r->employee_id][$r->leave_type_id] = ($used[$r->employee_id][$r->leave_type_id] ?? 0) + $days;
                }
            }
            foreach (LeaveAdjustment::query()->whereIn('employee_id', $ids)->where('year', $year)->get(['employee_id', 'leave_type_id', 'days']) as $a) {
                if ($a->leave_type_id === null) {
                    $sheetDeductions[$a->employee_id] = ($sheetDeductions[$a->employee_id] ?? 0) + $a->days;
                } else {
                    $used[$a->employee_id][$a->leave_type_id] = ($used[$a->employee_id][$a->leave_type_id] ?? 0) + $a->days;
                }
            }
        }
        $attendance = self::attendanceDeductions($ids, $year);
        $casual = $types->firstWhere('name', 'Casual');
        $annual = $types->firstWhere('name', 'Annual');

        $out = [];
        foreach ($employees as $e) {
            $rows = $types->mapWithKeys(fn (LeaveType $t) => [$t->id => [
                'type_id' => $t->id, 'type' => $t->name, 'allowance' => self::allowance((float) $t->yearly_days, $e->start_date, $year),
                'used' => (float) ($used[$e->id][$t->id] ?? 0), 'deducted' => 0.0,
            ]])->all();
            $att = $attendance[$e->id] ?? ['absent' => 0, 'late_early' => 0, 'days' => 0.0];
            $deduct = $att['days'] + ($sheetDeductions[$e->id] ?? 0);
            // Casual until it is used up, then Annual; with neither type, nothing to take it from.
            $order = array_values(array_filter([$casual?->id, $annual?->id]));
            foreach ($order as $i => $typeId) {
                if ($deduct <= 0) {
                    break;
                }
                $room = max(0, $rows[$typeId]['allowance'] - $rows[$typeId]['used']);
                $take = $i === count($order) - 1 ? $deduct : min($deduct, $room);
                $rows[$typeId]['deducted'] += $take;
                $deduct -= $take;
            }
            $out[$e->id] = [
                'balances' => array_values(array_map(fn ($r) => $r + ['left' => $r['allowance'] - $r['used'] - $r['deducted']], $rows)),
                'deductions' => ['absent' => $att['absent'], 'late_early' => $att['late_early'], 'sheet' => (float) ($sheetDeductions[$e->id] ?? 0),
                    'days' => $att['days'] + (float) ($sheetDeductions[$e->id] ?? 0)],
            ];
        }

        return $out;
    }

    /**
     * Absent days and late / early days from attendance, from LeaveSettings deductions_from (and the start of the year)
     * up to yesterday (today isn't over yet).
     *
     * @param  list<int>  $ids
     * @return array<int, array{absent: int, late_early: int, days: float}>
     */
    public static function attendanceDeductions(array $ids, int $year): array
    {
        $s = LeaveSettings::all();
        if (! $s['deductions_from'] || $ids === [] || ($s['absent_days'] <= 0 && $s['late_early_days'] <= 0)) {
            return [];
        }
        $from = max($s['deductions_from'], "$year-01-01");
        $to = min(CarbonImmutable::yesterday()->toDateString(), "$year-12-31");
        if ($from > $to) {
            return [];
        }
        $people = Employee::query()->whereIn('id', $ids)
            ->get(['id', 'start_date', 'exit_date', 'company_id', 'attendance_machine_id', 'hr_portal_id']);
        $board = new AttendanceBoard($people, $from, $to);
        $now = CarbonImmutable::now();
        $out = [];
        foreach ($people as $e) {
            $absent = 0;
            $lateEarly = 0;
            foreach ($board->dates() as $date) {
                $day = $board->day($e, $date, $now);
                if (! $day) {
                    continue;
                }
                $absent += $day['status'] === 'Absent' ? 1 : 0;
                $lateEarly += ($day['late'] > 0 ? 1 : 0) + ($day['early'] > 0 ? 1 : 0);
            }
            if ($absent || $lateEarly) {
                $out[$e->id] = ['absent' => $absent, 'late_early' => $lateEarly, 'days' => $absent * $s['absent_days'] + $lateEarly * $s['late_early_days']];
            }
        }

        return $out;
    }

    /** Calendar days over a per-leave allowance (0 when within it or the type has none). */
    public static function overLimit(LeaveType $type, string $from, string $to): int
    {
        if ($type->per_leave_days === null) {
            return 0;
        }
        $span = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;

        return max(0, (int) $span - $type->per_leave_days);
    }
}
