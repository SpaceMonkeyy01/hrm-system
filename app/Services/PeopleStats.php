<?php

namespace App\Services;

use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Headcount, joiners, leavers and turnover over time, from start and exit dates. Used by the Overview
 * page and the people reports, so both show the same numbers.
 *
 *   on the books on a day   started on or before it (no start date = always), and no exit date after it.
 *                           Exited without an exit date: left at an unknown time, so never counted.
 *   joiner / leaver         start date / exit date inside the period
 *   turnover %              leavers / average of the opening and closing headcount
 */
class PeopleStats
{
    /** @var Collection<int, Employee> */
    public Collection $employees;

    public function __construct(?int $companyId = null, ?int $departmentId = null)
    {
        $this->employees = Employee::query()
            ->with(['company:id,name', 'department:id,name'])
            ->when($companyId, fn ($q, $v) => $q->where('company_id', $v))
            ->when($departmentId, fn ($q, $v) => $q->where('department_id', $v))
            ->get(['id', 'employee_code', 'full_name', 'company_id', 'department_id', 'role_title', 'employment_type',
                'start_date', 'exit_date', 'status', 'attendance_machine_id', 'hr_portal_id']);
    }

    public static function onBooks(Employee $e, string $date): bool
    {
        if ($e->start_date && $e->start_date->toDateString() > $date) {
            return false;
        }
        if ($e->exit_date) {
            return $e->exit_date->toDateString() >= $date;
        }

        return ! in_array($e->status, ['exited', 'review'], true);   // review = not confirmed as staff yet
    }

    /** @return Collection<int, Employee> */
    public function onBooksOn(string $date): Collection
    {
        return $this->employees->filter(fn ($e) => self::onBooks($e, $date))->values();
    }

    /** @return Collection<int, Employee> */
    public function joiners(string $from, string $to): Collection
    {
        return $this->employees->filter(fn ($e) => $e->start_date && $e->start_date->toDateString() >= $from && $e->start_date->toDateString() <= $to)->values();
    }

    /** @return Collection<int, Employee> */
    public function leavers(string $from, string $to): Collection
    {
        return $this->employees->filter(fn ($e) => $e->exit_date && $e->exit_date->toDateString() >= $from && $e->exit_date->toDateString() <= $to)->values();
    }

    public static function turnover(int $leavers, int $opening, int $closing): ?float
    {
        $average = ($opening + $closing) / 2;

        return $average > 0 ? round($leavers / $average * 100, 1) : null;
    }

    /**
     * One row per month, oldest first, ending with the month of $last.
     *
     * @return list<array{month: string, label: string, opening: int, joiners: int, leavers: int, closing: int, turnover: ?float}>
     */
    public function monthly(CarbonImmutable $last, int $months = 12, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $rows = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $first = $last->startOfMonth()->subMonthsNoOverflow($i);
            // The current month closes today, not on the 31st.
            $end = $first->endOfMonth()->min($today);
            $opening = $this->onBooksOn($first->subDay()->toDateString())->count();
            $closing = $this->onBooksOn($end->toDateString())->count();
            $leavers = $this->leavers($first->toDateString(), $end->toDateString())->count();
            $rows[] = [
                'month' => $first->format('Y-m'),
                'label' => $first->format('M Y'),
                'opening' => $opening,
                'joiners' => $this->joiners($first->toDateString(), $end->toDateString())->count(),
                'leavers' => $leavers,
                'closing' => $closing,
                'turnover' => self::turnover($leavers, $opening, $closing),
            ];
        }

        return $rows;
    }
}
