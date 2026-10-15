<?php

namespace App\Services\Reports;

use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** The filters a report was run with, already validated and defaulted by ReportController. */
final class ReportFilters
{
    public function __construct(
        public readonly CarbonImmutable $today,
        public readonly string $month,      // Y-m
        public readonly string $from,       // Y-m-d
        public readonly string $to,         // Y-m-d
        public readonly int $year,
        public readonly string $date,       // Y-m-d, "as of"
        public readonly int $within,        // days ahead
        public readonly string $day = '',   // Y-m-d, a single day (daily report)
        public readonly string $week = '',  // Y-m-d, the Monday that starts the week (weekly report)
        public readonly ?int $company = null,
        public readonly ?int $department = null,
    ) {}

    public function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->month.'-01');
    }

    /** The month, up to today when it is the current month. */
    public function monthEnd(): CarbonImmutable
    {
        return $this->monthStart()->endOfMonth()->min($this->today);
    }

    public function weekStart(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->week);
    }

    /** Sunday: the whole week, days after today show as not yet. */
    public function weekEnd(): CarbonImmutable
    {
        return $this->weekStart()->addDays(6);
    }

    /** Employees in the chosen company / department. */
    public function employees(): Builder
    {
        return Employee::query()
            ->when($this->company, fn ($q, $v) => $q->where('company_id', $v))
            ->when($this->department, fn ($q, $v) => $q->where('department_id', $v));
    }

    /** Current staff (active or offboarding) in the chosen company / department. */
    public function current(): Builder
    {
        return $this->employees()->whereIn('status', ['active', 'offboarding']);
    }

    /** Anyone employed for at least one day between $from and $to. */
    public function employedBetween(string $from, string $to): Builder
    {
        return $this->employees()
            ->where('status', '!=', 'review')   // not confirmed as staff yet
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $to))
            ->where(fn ($q) => $q->where('exit_date', '>=', $from)
                ->orWhere(fn ($n) => $n->whereNull('exit_date')->where('status', '!=', 'exited')));
    }
}
