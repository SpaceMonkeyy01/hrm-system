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
 *
 * "No data": attendance arrives as uploaded files, so a day nobody's file covers can't be called absent.
 * A day counts as covered for a person when anyone at all punched that day in a source the person uses
 * (machine and/or HR.my). Otherwise Absent and Not in yet read "No data". The app's own clock-in is live, not a
 * file: anyone who has ever clocked in on the app counts as covered every day (and as linked).
 */
class AttendanceBoard
{
    /** @var Collection<int, WorkSchedule> */
    public Collection $schedules;

    private ?WorkSchedule $default;

    /** @var array<int, list<array{from: string, schedule: int}>> employee id => assignments, newest first */
    private array $assignments = [];

    /** @var array<int, array<string, list<array{time: string, source: string, method: ?string, place: ?string}>>> employee id => date => punches */
    private array $punches = [];

    /** @var array<int, array<string, array>> employee id => date => adjustment */
    private array $adjustments = [];

    /** @var array<string, array<int, string>> date => [company id (0 = all) => holiday name] */
    private array $holidays = [];

    /** @var array<int, list<array{from: string, to: string, type: string, part: string}>> employee id => leave */
    private array $leave = [];

    /** @var array<string, array<string, true>> source => date => anyone punched that day */
    private array $covered = ['machine' => [], 'hrmy' => []];

    /** @var array<int, true> employee ids that have ever clocked in on the app */
    private array $appUsers = [];

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

        // Device ids => employee. Machine punches link by attendance_machine_id, HR.my punches by hr_portal_id,
        // app clock-ins by the employee id itself.
        $byDevice = ['machine' => [], 'hrmy' => [], AttendanceClock::SOURCE => []];
        foreach ($employees as $e) {
            if ($e->attendance_machine_id) {
                $byDevice['machine'][$e->attendance_machine_id] = $e->id;
            }
            if ($e->hr_portal_id) {
                $byDevice['hrmy'][$e->hr_portal_id] = $e->id;
            }
            $byDevice[AttendanceClock::SOURCE][(string) $e->id] = $e->id;
        }
        $query = AttendancePunch::query()
            ->whereBetween('work_date', [$from, $to])
            ->where(fn ($q) => $q
                ->where(fn ($m) => $m->where('source', 'machine')->whereIn('device_user_id', array_map('strval', array_keys($byDevice['machine']))))
                ->orWhere(fn ($h) => $h->where('source', 'hrmy')->whereIn('device_user_id', array_map('strval', array_keys($byDevice['hrmy']))))
                ->orWhere(fn ($a) => $a->where('source', AttendanceClock::SOURCE)->whereIn('device_user_id', array_map('strval', $ids))));
        foreach ($query->toBase()->get(['source', 'device_user_id', 'punched_at', 'work_date', 'method', 'place']) as $p) {
            $employeeId = $byDevice[$p->source][$p->device_user_id] ?? null;
            if ($employeeId) {
                $at = CarbonImmutable::parse($p->punched_at);
                $day = substr((string) $p->work_date, 0, 10);
                // A punch the next morning on a night shift: 09:00 the day after reads "33:00:00".
                $hours = (int) $at->format('H') + 24 * (int) CarbonImmutable::parse($day)->diffInDays($at->startOfDay());
                $this->punches[$employeeId][$day][] = ['time' => sprintf('%02d', $hours).$at->format(':i:s'), 'source' => $p->source, 'method' => $p->method, 'place' => $p->place];
            }
        }

        foreach (AttendancePunch::query()->where('source', AttendanceClock::SOURCE)->whereIn('device_user_id', array_map('strval', $ids))
            ->distinct()->pluck('device_user_id') as $id) {
            $this->appUsers[(int) $id] = true;
        }
        foreach (AttendancePunch::query()->whereBetween('work_date', [$from, $to])->distinct()->toBase()->get(['source', 'work_date']) as $c) {
            $this->covered[$c->source][substr((string) $c->work_date, 0, 10)] = true;
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
        // No machine or HR.my number and never clocked in on the app: there can't be punches, so "absent" would be a guess.
        if (! $this->isLinked($e) && in_array($day['status'], ['Absent', 'Not in yet', 'No punches'], true)) {
            $day['status'] = 'Not linked';
        } elseif (in_array($day['status'], ['Absent', 'Not in yet'], true) && ! $this->isCovered($e, $date)) {
            // No file loaded for that day yet: nobody knows whether they came in.
            $day['status'] = 'No data';
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
                $working += $day['working_day'] && ! in_array($day['status'], ['Not linked', 'No data'], true) ? 1 : 0;
            }
        }

        return ['statuses' => $statuses, 'late' => $late, 'working_days' => $working, 'people' => $this->employees->count(),
            'linked' => $this->employees->filter(fn ($e) => $this->isLinked($e))->count()];
    }

    /** A machine number, an HR.my ID, or clock-ins on the app: punches can reach this person. */
    public function isLinked(Employee $e): bool
    {
        return $e->attendance_machine_id || $e->hr_portal_id || isset($this->appUsers[$e->id]);
    }

    /** Anyone punched on $date in a source this person uses (the app's clock-in always counts: it's live). */
    public function isCovered(Employee $e, string $date): bool
    {
        return isset($this->appUsers[$e->id])
            || ($e->attendance_machine_id && isset($this->covered['machine'][$date]))
            || ($e->hr_portal_id && isset($this->covered['hrmy'][$date]));
    }

    /** @return list<string> every date from..to */
    public function dates(): array
    {
        return array_map(fn ($d) => $d->toDateString(), iterator_to_array(CarbonPeriod::create($this->from, $this->to)));
    }

    /** Raw punch times on a day, for the detail view. @return list<string> */
    public function punchesOn(int $employeeId, string $date): array
    {
        $p = array_column($this->punches[$employeeId][$date] ?? [], 'time');
        sort($p);

        return $p;
    }
}
