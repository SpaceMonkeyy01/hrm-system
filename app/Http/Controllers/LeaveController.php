<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\AppSetting;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Services\AttendanceBoard;
use App\Services\LeaveBalances;
use App\Services\LeaveSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Approved leave, public holidays and leave types. Requests and approvals stay in HR.my: HR records the
 * outcome here so attendance shows "On leave" and "Holiday" instead of "Absent".
 */
class LeaveController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'type' => ['nullable', 'integer'],
            'department' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $today = CarbonImmutable::today();
        $first = CarbonImmutable::parse(($f['month'] ?? $today->format('Y-m')).'-01');
        $last = $first->endOfMonth();
        // ?company= (the All / BCC / Emerge switch) narrows people and holidays; leave types and settings stay shared.
        $company = Company::picked();

        $records = LeaveRecord::query()->with(['employee:id,employee_code,full_name,department_id', 'employee.department:id,name', 'type:id,name', 'author:id,name'])
            ->where('start_date', '<=', $last->toDateString())->where('end_date', '>=', $first->toDateString())
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('leave_type_id', $v))
            ->whereHas('employee', fn ($q) => $q->inCompany($company)->search($f['q'] ?? null)->when($f['department'] ?? null, fn ($d, $v) => $d->where('department_id', $v)))
            ->orderBy('start_date')->get();

        return Inertia::render('leave/index', [
            'month' => $first->format('Y-m'),
            'today' => $today->toDateString(),
            'filters' => $f,
            'company' => $company,
            'records' => $records->map(fn (LeaveRecord $r) => $this->recordProps($r))->values(),
            'onLeaveToday' => LeaveRecord::query()->with('employee:id,full_name', 'type:id,name')->whereHas('employee', fn ($q) => $q->inCompany($company))
                ->where('start_date', '<=', $today->toDateString())->where('end_date', '>=', $today->toDateString())->get()
                ->map(fn ($r) => ['id' => $r->employee->id, 'name' => $r->employee->full_name, 'type' => $r->type->name, 'part' => $r->part])->values(),
            'types' => LeaveType::query()->withCount('records')->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn ($t) => [...$t->only('id', 'name', 'is_paid', 'is_active', 'sort_order', 'yearly_days', 'per_leave_days'), 'used' => $t->records_count]),
            'balances' => $this->balances($f, $first->year, $company),
            'settings' => LeaveSettings::all(),
            'holidays' => Holiday::query()->with('company:id,name')->whereYear('date', $first->year)
                ->when($company, fn ($q) => $q->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company)))->orderBy('date')->get()
                ->map(fn ($h) => ['id' => $h->id, 'date' => $h->date->toDateString(), 'name' => $h->name, 'company' => $h->company?->name, 'company_id' => $h->company_id]),
            'year' => $first->year,
            'people' => Employee::query()->whereIn('status', ['active', 'offboarding'])->inCompany($company)->orderBy('full_name')->get(['id', 'employee_code', 'full_name'])
                ->map(fn ($e) => ['id' => $e->id, 'label' => "{$e->full_name} ({$e->employee_code})"]),
            'companies' => Company::query()->orderBy('name')->with('departments:id,company_id,name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'departments' => $c->departments->map->only('id', 'name')->all()]),
            'parts' => LeaveRecord::PARTS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->withoutTrashed()],
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('is_active', true)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'part' => ['required', Rule::in(array_keys(LeaveRecord::PARTS))],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['end_date.after_or_equal' => 'The last day can\'t be before the first day.']);
        self::checkDates($data);
        self::refuseClash((int) $data['employee_id'], $data['start_date'], $data['end_date']);

        $employee = Employee::findOrFail($data['employee_id']);
        $days = self::workingDays($employee, $data['start_date'], $data['end_date'], $data['part']);
        LeaveRecord::create($data + ['days' => $days, 'source' => 'manual', 'created_by' => $request->user()->id]);

        return back()->with('success', "Recorded {$days} ".($days == 1 ? 'day' : 'days')." of leave for {$employee->full_name}."
            .self::balanceNote($employee, LeaveType::findOrFail($data['leave_type_id']), $data['start_date'], $data['end_date']));
    }

    /** For "Mark as leave" on Edit day: the active types with what is left of each this year, and the day parts. */
    public function dayOptions(Request $request): JsonResponse
    {
        $f = $request->validate(['employee' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d']]);
        $employee = Employee::findOrFail($f['employee']);
        $left = collect(LeaveBalances::forEmployee($employee, (int) substr($f['date'], 0, 4)))->pluck('left', 'type_id');

        return response()->json([
            'types' => LeaveType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'per_leave_days'])
                ->map(fn (LeaveType $t) => ['id' => $t->id, 'name' => $t->name, 'left' => $left[$t->id] ?? null, 'per_leave_days' => $t->per_leave_days]),
            'parts' => LeaveRecord::PARTS,
        ]);
    }

    public function destroy(LeaveRecord $record): RedirectResponse
    {
        $record->delete();

        return back()->with('success', 'Leave removed.');
    }

    // ---------------------------------------------------------------- holidays

    public function storeHoliday(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'name' => ['required', 'string', 'max:100'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);
        $from = CarbonImmutable::parse($data['date']);
        $to = CarbonImmutable::parse($data['end_date'] ?? $data['date']);
        if ($from->diffInDays($to) > 14) {
            throw ValidationException::withMessages(['end_date' => 'A holiday can be at most 15 days.']);
        }
        $added = 0;
        for ($d = $from; $d <= $to; $d = $d->addDay()) {
            $holiday = Holiday::firstOrCreate(['date' => $d->toDateString(), 'company_id' => $data['company_id'] ?? null], ['name' => $data['name']]);
            $added += $holiday->wasRecentlyCreated ? 1 : 0;
        }

        return back()->with('success', $added === 1 ? "Added {$data['name']}." : "Added {$data['name']} ($added days).");
    }

    public function destroyHoliday(Holiday $holiday): RedirectResponse
    {
        $holiday->delete();

        return back()->with('success', "Removed {$holiday->name}.");
    }

    // ---------------------------------------------------------------- types

    public function storeType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'unique:leave_types,name'],
            'is_paid' => ['boolean'],
        ]);
        LeaveType::create($data + ['sort_order' => (LeaveType::max('sort_order') ?? 0) + 10]);

        return back()->with('success', "Added {$data['name']} leave.");
    }

    public function updateType(Request $request, LeaveType $type): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('leave_types', 'name')->ignore($type->id)],
            'is_paid' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'yearly_days' => ['nullable', 'numeric', 'min:0', 'max:365', 'multiple_of:0.5'],
            'per_leave_days' => ['nullable', 'integer', 'min:1', 'max:366'],
        ], ['yearly_days.multiple_of' => 'Use whole or half days.']);
        if (($data['yearly_days'] ?? null) !== null && ($data['per_leave_days'] ?? null) !== null) {
            throw ValidationException::withMessages(['yearly_days' => 'Give a type either a yearly allowance or an allowance per leave, not both.']);
        }
        $type->update($data + ['yearly_days' => null, 'per_leave_days' => null]);

        return back()->with('success', 'Saved.');
    }

    // ---------------------------------------------------------------- balances

    /** Deductions and short leave numbers (LeaveSettings). Each change is in the activity log. */
    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'absent_days' => ['required', 'numeric', 'min:0', 'max:2', 'multiple_of:0.25'],
            'late_early_days' => ['required', 'numeric', 'min:0', 'max:1', 'multiple_of:0.25'],
            'short_leave_days' => ['required', 'numeric', 'min:0.25', 'max:1', 'multiple_of:0.25'],
            'deductions_from' => ['nullable', 'date_format:Y-m-d'],
        ], ['*.multiple_of' => 'Use quarter days: 0, 0.25, 0.5, 0.75, 1.']);
        foreach ($data as $key => $value) {
            AppSetting::put("leave.$key", $key === 'deductions_from' ? ($value ?: null) : (float) $value);
        }

        return back()->with('success', $data['deductions_from'] ?? null
            ? 'Saved. Absences and late / early sign-offs count against balances from '.CarbonImmutable::parse($data['deductions_from'])->format('j M Y').'.'
            : 'Saved. Attendance deductions are off until you pick a start date.');
    }

    /** Balances tab: current staff (same company, search and department filters as the list), yearly types only. */
    private function balances(array $f, int $year, ?int $company): array
    {
        $types = LeaveBalances::yearlyTypes();
        $people = Employee::query()->whereIn('status', ['active', 'offboarding'])->inCompany($company)->with('department:id,name')
            ->search($f['q'] ?? null)->when($f['department'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->orderBy('full_name')->get(['id', 'employee_code', 'full_name', 'department_id', 'company_id', 'start_date']);
        $rows = LeaveBalances::summary($people, $year, $types);

        return [
            'year' => $year,
            'types' => $types->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'yearly_days' => $t->yearly_days])->values(),
            'rows' => $people->map(fn ($e) => ['id' => $e->id, 'name' => $e->full_name, 'code' => $e->employee_code,
                'department' => $e->department?->name, 'start_date' => $e->start_date?->toDateString(),
                'balances' => $rows[$e->id]['balances'], 'deductions' => $rows[$e->id]['deductions']])->values(),
        ];
    }

    /** " Annual: 3 of 14 days left in 2026." / " Annual: 2 days over the 14 for 2026." / " Longer than the 14-day Wedding allowance (by 3 days)." */
    public static function balanceNote(Employee $employee, LeaveType $type, string $from, string $to): string
    {
        if ($type->per_leave_days !== null) {
            $over = LeaveBalances::overLimit($type, $from, $to);

            return $over ? " Longer than the {$type->per_leave_days}-day {$type->name} allowance (by $over ".($over === 1 ? 'day' : 'days').').' : '';
        }
        if ($type->yearly_days === null) {
            return '';
        }
        $year = (int) substr($from, 0, 4);
        $b = collect(LeaveBalances::forEmployee($employee, $year))->firstWhere('type_id', $type->id);
        if (! $b) {
            return '';
        }
        $n = fn (float $d) => rtrim(rtrim(number_format($d, 2, '.', ''), '0'), '.');

        return $b['left'] >= 0
            ? " {$type->name}: {$n($b['left'])} of {$n($b['allowance'])} days left in $year."
            : " {$type->name}: {$n(-$b['left'])} ".(-$b['left'] == 1 ? 'day' : 'days')." over the {$n($b['allowance'])} for $year.";
    }

    // ---------------------------------------------------------------- helpers

    /** Half days are for one day; leave is at most a year. Used by Record leave and leave requests. */
    public static function checkDates(array $data): void
    {
        if ($data['part'] !== 'full' && $data['start_date'] !== $data['end_date']) {
            throw ValidationException::withMessages(['part' => 'Half-day and short leave are for a single day.']);
        }
        if (CarbonImmutable::parse($data['start_date'])->diffInDays(CarbonImmutable::parse($data['end_date'])) > 366) {
            throw ValidationException::withMessages(['end_date' => 'Leave can be at most a year long.']);
        }
    }

    /** No two leave records for one person on the same day. */
    public static function refuseClash(int $employeeId, string $from, string $to): void
    {
        $clash = LeaveRecord::query()->with('type:id,name')->where('employee_id', $employeeId)
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)->first();
        if ($clash) {
            throw ValidationException::withMessages(['start_date' => "Overlaps {$clash->type->name} leave from ".$clash->start_date->format('j M').' to '.$clash->end_date->format('j M').'.']);
        }
    }

    /**
     * Working days in the range for this person: their schedule's working days, minus holidays.
     * Half a day for first_half / second_half; a short leave counts LeaveSettings short_leave_days. With no schedule, Monday to Friday.
     */
    public static function workingDays(Employee $employee, string $from, string $to, string $part = 'full'): float
    {
        if ($part !== 'full') {
            return $part === 'short' ? (float) LeaveSettings::get('short_leave_days') : 0.5;
        }
        $board = new AttendanceBoard(collect([$employee]), $from, $to);
        $n = 0;
        foreach ($board->dates() as $date) {
            $schedule = $board->scheduleFor($employee->id, $date);
            $weekdays = $schedule ? array_map('intval', $schedule->working_days ?? []) : [1, 2, 3, 4, 5];
            if (in_array(CarbonImmutable::parse($date)->isoWeekday(), $weekdays, true) && ! $board->holidayFor($employee->company_id, $date)) {
                $n++;
            }
        }

        return (float) $n;
    }

    public static function recordProps(LeaveRecord $r): array
    {
        return [
            'id' => $r->id,
            'employee' => $r->relationLoaded('employee') && $r->employee ? ['id' => $r->employee->id, 'name' => $r->employee->full_name,
                'code' => $r->employee->employee_code, 'department' => $r->employee->relationLoaded('department') ? $r->employee->department?->name : null] : null,
            'type' => $r->type?->name, 'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'part' => $r->part, 'days' => $r->days, 'note' => $r->note, 'source' => $r->source,
            'by' => $r->relationLoaded('author') ? $r->author?->name : null,
        ];
    }
}
