<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Services\AttendanceRules;
use App\Services\ScheduleAssigner;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Office timing profiles (9-5 strict, 12-6, flexible ...) and who works to which. */
class WorkScheduleController extends Controller
{
    public function index(): Response
    {
        $today = CarbonImmutable::today()->toDateString();
        $current = ScheduleAssigner::current(Employee::whereIn('status', ['active', 'offboarding'])->pluck('id')->all(), $today);
        $counts = array_count_values($current);
        $people = Employee::whereIn('status', ['active', 'offboarding'])->count();

        return Inertia::render('attendance/schedules', [
            'schedules' => WorkSchedule::query()->orderByDesc('is_default')->orderBy('name')->get()->map(fn (WorkSchedule $s) => [
                ...$s->only('id', 'name', 'type', 'start_time', 'end_time', 'late_grace_minutes', 'early_grace_minutes', 'absent_after', 'late_half_day_minutes',
                    'full_day_minutes', 'half_day_minutes', 'overtime_after_minutes', 'working_days', 'missing_checkout', 'is_default', 'notes'),
                'summary' => $s->summary(),
                'absent_after_label' => $s->absentAfterLabel(),
                'people' => $counts[$s->id] ?? 0,
            ]),
            'unassigned' => $people - count($current),
            'companies' => Company::query()->orderBy('name')->with('departments:id,company_id,name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'departments' => $c->departments->map->only('id', 'name')->all()]),
            'today' => $today,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $schedule = DB::transaction(function () use ($request) {
            $data = $this->validated($request, null);
            if ($data['is_default']) {
                $this->clearDefault();
            }

            return WorkSchedule::create($data);
        });

        return back()->with('success', "Added the {$schedule->name} schedule.");
    }

    public function update(Request $request, WorkSchedule $schedule): RedirectResponse
    {
        DB::transaction(function () use ($request, $schedule) {
            $data = $this->validated($request, $schedule);
            if ($data['is_default'] && ! $schedule->is_default) {
                $this->clearDefault();
            }
            $schedule->update($data);
        });

        return back()->with('success', 'Saved. Past days are worked out again with the new rules.');
    }

    public function destroy(WorkSchedule $schedule): RedirectResponse
    {
        if ($schedule->assignments()->exists()) {
            throw ValidationException::withMessages(['schedule' => "{$schedule->name} has been used, so it can't be deleted. Move people to another schedule instead."]);
        }
        $schedule->delete();

        return back()->with('success', "Deleted the {$schedule->name} schedule.");
    }

    /** Put chosen people, or everyone in a department (or company), on this schedule from a date. */
    public function assign(Request $request, WorkSchedule $schedule): RedirectResponse
    {
        $data = $request->validate([
            'employee_ids' => ['nullable', 'array', 'max:500'],
            'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'starts_on' => ['required', 'date'],
            'only_unassigned' => ['boolean'],
        ]);
        $picked = $data['employee_ids'] ?? [];
        if (! $picked && empty($data['company_id']) && empty($data['department_id'])) {
            throw ValidationException::withMessages([$request->has('employee_ids') ? 'employee_ids' : 'department_id' => $request->has('employee_ids')
                ? 'Pick at least one person.' : 'Choose a company or a department.']);
        }
        $employees = Employee::query()->whereIn('status', ['active', 'offboarding'])
            ->when($picked, fn ($q) => $q->whereIn('id', $picked))
            ->when(! $picked && ($data['department_id'] ?? null), fn ($q) => $q->where('department_id', $data['department_id']))
            ->when(! $picked && ! ($data['department_id'] ?? null) && ($data['company_id'] ?? null), fn ($q) => $q->where('company_id', $data['company_id']))
            ->get(['id']);
        if (! $picked && ($data['only_unassigned'] ?? false)) {
            $has = ScheduleAssigner::current($employees->pluck('id')->all(), CarbonImmutable::parse($data['starts_on'])->toDateString());
            $employees = $employees->reject(fn ($e) => isset($has[$e->id]));
        }
        $n = ScheduleAssigner::assign($employees, $schedule, CarbonImmutable::parse($data['starts_on'])->toDateString(), $request->user()->id);

        return back()->with('success', $n === 1 ? "1 person moved to {$schedule->name}." : "$n people moved to {$schedule->name}.");
    }

    private function clearDefault(): void
    {
        foreach (WorkSchedule::where('is_default', true)->get() as $s) {
            $s->update(['is_default' => false]);
        }
    }

    private function validated(Request $request, ?WorkSchedule $schedule): array
    {
        $flexible = in_array($request->input('type'), ['flexible', 'none'], true);   // no start or end time
        $time = ['regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('work_schedules', 'name')->ignore($schedule?->id)],
            'type' => ['required', Rule::in(['fixed', 'flexible', 'none'])],
            'start_time' => [$flexible ? 'nullable' : 'required', ...$time],
            'end_time' => [$flexible ? 'nullable' : 'required', ...$time],
            'late_grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'early_grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'absent_after' => ['nullable', ...$time],
            'late_half_day_minutes' => ['nullable', 'integer', 'min:30', 'lte:full_day_minutes'],
            'full_day_minutes' => ['required', 'integer', 'min:30', 'max:1440'],
            'half_day_minutes' => ['required', 'integer', 'min:0', 'max:1440', 'lte:full_day_minutes'],
            'overtime_after_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['integer', 'between:1,7', 'distinct'],
            'missing_checkout' => ['required', Rule::in(['flag', 'auto'])],
            'is_default' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'start_time.regex' => 'Use a 24-hour time like 09:00.',
            'end_time.regex' => 'Use a 24-hour time like 17:00.',
            'absent_after.regex' => 'Use a 24-hour time like 13:00.',
            'half_day_minutes.lte' => 'Half day must be shorter than a full day.',
            'late_half_day_minutes.min' => 'Use at least half an hour.',
            'late_half_day_minutes.lte' => 'Use no more than a full day.',
        ]);
        if (! $flexible && $data['end_time'] === $data['start_time']) {
            throw ValidationException::withMessages(['end_time' => 'The day must end at a different time than it starts. An end before the start means the next morning (night shift).']);
        }
        if (! $flexible && ($data['absent_after'] ?? null)) {
            $start = AttendanceRules::minutes($data['start_time']);
            $end = AttendanceRules::minutes($data['end_time']);
            $end += $end <= $start ? 1440 : 0;
            $cut = (new WorkSchedule($data))->absentAfterMinutes();
            if ($cut <= $start + $data['late_grace_minutes'] || $cut > $end) {
                throw ValidationException::withMessages(['absent_after' => 'Pick a time after the start (and its grace) and no later than the end of the day.']);
            }
        }
        if ($data['type'] === 'none' && ! empty($data['is_default'])) {
            throw ValidationException::withMessages(['is_default' => "A No clock-in schedule can't be the default: nobody would ever show as absent."]);
        }
        if ($data['type'] === 'none') {
            $data['overtime_after_minutes'] = null;
        }
        if ($flexible) {
            $data['start_time'] = $data['end_time'] = $data['absent_after'] = $data['late_half_day_minutes'] = null;
            $data['late_grace_minutes'] = $data['early_grace_minutes'] = 0;
            $data['missing_checkout'] = 'flag';   // no end time to check out at
        }
        $data['working_days'] = array_values(array_map('intval', $data['working_days']));
        sort($data['working_days']);
        $data['is_default'] = (bool) ($data['is_default'] ?? false);

        return $data;
    }
}
