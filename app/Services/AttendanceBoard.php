<?php

namespace App\Services;

use App\Models\AttendanceAdjustment;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Attendance for many people over a date range in a handful of queries (like StatusBoard for checklists).
 * Each day goes through AttendanceRules::day().
 *
 * People count from their start date up to their exit date; days outside that are left out.
 */
class AttendanceBoard
{
    /** @var Collection<int, WorkSchedule> */
    public Collection $schedules;

    private ?WorkSchedule $default;

    /** @var array<int, list<array{from: string, schedule: int}>> employee id => assignments, newest first */
    private array $assignments = [];

    /** @var array<int, array<string, list<string>>> employee id => date => punch times */
    private array $punches = [];

    /** @var array<int, array<string, array>> employee id => date => adjustment */
    private array $adjustments = [];

    /** @var array<string, array<int, string>> date => [company id (0 = all) => holiday name] */
    private array $holidays = [];

    /** @var array<int, list<array{from: string, to: string, type: string, part: string}>> employee id => leave */
    private array $leave = [];

    /**
     * @param  Collection<int, Employee>  $employees  with id, start_date, exit_date, attendance_machine_id, hr_portal_id
     */
    public function __construct(public Collection $employees, public string $from, public string $to)
    {
        $this->schedules = WorkSchedule::query()->orderBy('name')->get()->keyBy('id');
        $this->default = $this->schedules->firstWhere('is_default', true);
        $ids = $employees->pluck('id')->all();

        foreach (EmployeeWorkSchedule::query()->whereIn('employee_id', $ids)->orderByDesc('starts_on')
            ->get(['employee_id', 'work_schedule_id', 'starts_on']) as $a) {
            $this->assignments[$a->employee_id][] = ['from' => $a->starts_on->toDateString(), 'schedule' => $a->work_schedule_id];
        }

        // Device ids => employee. Machine punches link by attendance_machine_id, HR.my punches by hr_portal_id.
        $byDevice = ['machine' => [], 'hrmy' => []];
        foreach ($employees as $e) {
            if ($e->attendance_machine_id) {
                $byDevice['machine'][$e->attendance_machine_id] = $e->id;
            }
            if ($e->hr_portal_id) {
                $byDevice['hrmy'][$e->hr_portal_id] = $e->id;
            }
        }
        $query = AttendancePunch::query()
            ->whereBetween('work_date', [$from, $to])
            ->where(fn ($q) => $q
                ->where(fn ($m) => $m->where('source', 'machine')->whereIn('device_user_id', array_map('strval', array_keys($byDevice['machine']))))
                ->orWhere(fn ($h) => $h->where('source', 'hrmy')->whereIn('device_user_id', array_map('strval', array_keys($byDevice['hrmy'])))));
        foreach ($query->toBase()->get(['source', 'device_user_id', 'punched_at', 'work_date']) as $p) {
            $employeeId = $byDevice[$p->source][$p->device_user_id] ?? null;
            if ($employeeId) {
                $at = CarbonImmutable::parse($p->punched_at);
                $day = substr((string) $p->work_date, 0, 10);
                // A punch the next morning on a night shift: 09:00 the day after reads "33:00:00".
                $hours = (int) $at->format('H') + 24 * (int) CarbonImmutable::parse($day)->diffInDays($at->startOfDay());
                $this->punches[$employeeId][$day][] = sprintf('%02d', $hours).$at->format(':i:s');
            }
        }

        foreach (AttendanceAdjustment::query()->whereIn('employee_id', $ids)->whereBetween('date', [$from, $to])
            ->get(['employee_id', 'date', 'check_in', 'check_out', 'note']) as $a) {
            $this->adjustments[$a->employee_id][$a->date->toDateString()] = $a->only('check_in', 'check_out', 'note');
        }

        foreach (Holiday::query()->whereBetween('date', [$from, $to])->get(['date', 'name', 'company_id']) as $h) {
            $this->holidays[$h->date->toDateString()][$h->company_id ?? 0] = $h->name;
        }
        foreach (LeaveRecord::query()->with('type:id,name')->whereIn('employee_id', $ids)
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
            ->get(['employee_id', 'leave_type_id', 'start_date', 'end_date', 'part']) as $l) {
            $this->leave[$l->employee_id][] = ['from' => $l->start_date->toDateString(), 'to' => $l->end_date->toDateString(),
                'type' => $l->type?->name ?? 'Leave', 'part' => $l->part];
        }
    }

    /** Holiday name for this person's company (or a holiday for everyone) on $date. */
    public function holidayFor(?int $companyId, string $date): ?string
    {
        return $this->holidays[$date][$companyId ?? -1] ?? $this->holidays[$date][0] ?? null;
    }

    /** @return array{type: string, part: string}|null */
    public function leaveFor(int $employeeId, string $date): ?array
    {
        foreach ($this->leave[$employeeId] ?? [] as $l) {
            if ($l['from'] <= $date && $l['to'] >= $date) {
                return ['type' => $l['type'], 'part' => $l['part']];
            }
        }

        return null;
    }

    /** Schedule that applied to the person on $date: the latest assignment starting on or before it, else the default. */
    public function scheduleFor(int $employeeId, string $date): ?WorkSchedule
    {
        foreach ($this->assignments[$employeeId] ?? [] as $a) {
            if ($a['from'] <= $date) {
                return $this->schedules->get($a['schedule']);
            }
        }

        return $this->default;
    }

    /** The day, or null when the person wasn't employed then. */
    public function day(Employee $e, string $date, CarbonInterface $now): ?array
    {
        if (($e->start_date && $date < $e->start_date->toDateString()) || ($e->exit_date && $date > $e->exit_date->toDateString())) {
            return null;
        }
        $schedule = $this->scheduleFor($e->id, $date);
        $day = AttendanceRules::day($date, $this->punches[$e->id][$date] ?? [], $this->adjustments[$e->id][$date] ?? null, $schedule, $now, [
            'holiday' => $this->holidayFor($e->company_id, $date),
            'leave' => $this->leaveFor($e->id, $date),
        ]);
        // No machine or HR.my number: there can't be punches, so "absent" would be a guess.
        if (! $e->attendance_machine_id && ! $e->hr_portal_id && in_array($day['status'], ['Absent', 'Not in yet', 'No punches'], true)) {
            $day['status'] = 'Not linked';
        }

        return $day + ['schedule_id' => $schedule?->id];
    }

    /**
     * Totals over every person and day on the board: day count per status, plus late arrivals, working days
     * and the people with any punches. "Late" is a subset of Present.
     *
     * @return array{statuses: array<string, int>, late: int, working_days: int, people: int, linked: int}
     */
    public function totals(CarbonInterface $now): array
    {
        $statuses = [];
        $late = 0;
        $working = 0;
        foreach ($this->employees as $e) {
            foreach ($this->dates() as $date) {
                $day = $this->day($e, $date, $now);
                if (! $day || $day['status'] === null) {
                    continue;
                }
                $statuses[$day['status']] = ($statuses[$day['status']] ?? 0) + 1;
                $late += $day['late'] > 0 ? 1 : 0;
                $working += $day['working_day'] && $day['status'] !== 'Not linked' ? 1 : 0;
            }
        }

        return ['statuses' => $statuses, 'late' => $late, 'working_days' => $working, 'people' => $this->employees->count(),
            'linked' => $this->employees->filter(fn ($e) => $e->attendance_machine_id || $e->hr_portal_id)->count()];
    }

    /** @return list<string> every date from..to */
    public function dates(): array
    {
        return array_map(fn ($d) => $d->toDateString(), iterator_to_array(CarbonPeriod::create($this->from, $this->to)));
    }

    /** Raw punch times on a day, for the detail view. @return list<string> */
    public function punchesOn(int $employeeId, string $date): array
    {
        $p = $this->punches[$employeeId][$date] ?? [];
        sort($p);

        return $p;
    }
}
