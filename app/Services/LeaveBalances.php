<?php

namespace App\Services;

use App\Http\Controllers\LeaveController;
use App\Models\Employee;
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
 *                used = working days of that type falling in the year (a leave across 31 Dec is split).
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
     * Balances for many people at once (two queries).
     *
     * @param  Collection<int, Employee>  $employees  with id, start_date, company_id
     * @return array<int, list<array{type_id: int, type: string, allowance: float, used: float, left: float}>> employee id => one row per yearly type
     */
    public static function forEmployees(Collection $employees, int $year, ?Collection $types = null): array
    {
        $types ??= self::yearlyTypes();
        $from = "$year-01-01";
        $to = "$year-12-31";
        $used = [];
        if ($types->isNotEmpty() && $employees->isNotEmpty()) {
            $byId = $employees->keyBy('id');
            $records = LeaveRecord::query()->whereIn('employee_id', $byId->keys())->whereIn('leave_type_id', $types->pluck('id'))
                ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
                ->get(['employee_id', 'leave_type_id', 'start_date', 'end_date', 'part', 'days']);
            foreach ($records as $r) {
                $inside = $r->start_date->toDateString() >= $from && $r->end_date->toDateString() <= $to;
                $days = $inside ? (float) $r->days : LeaveController::workingDays($byId[$r->employee_id],
                    max($r->start_date->toDateString(), $from), min($r->end_date->toDateString(), $to), $r->part);
                $used[$r->employee_id][$r->leave_type_id] = ($used[$r->employee_id][$r->leave_type_id] ?? 0) + $days;
            }
        }

        $out = [];
        foreach ($employees as $e) {
            $out[$e->id] = $types->map(function (LeaveType $t) use ($e, $year, $used) {
                $allowance = self::allowance((float) $t->yearly_days, $e->start_date, $year);
                $u = (float) ($used[$e->id][$t->id] ?? 0);

                return ['type_id' => $t->id, 'type' => $t->name, 'allowance' => $allowance, 'used' => $u, 'left' => $allowance - $u];
            })->values()->all();
        }

        return $out;
    }

    /** @return list<array{type_id: int, type: string, allowance: float, used: float, left: float}> */
    public static function forEmployee(Employee $employee, int $year): array
    {
        return self::forEmployees(collect([$employee]), $year)[$employee->id];
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
