<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Services\AttendanceBoard;
use Carbon\CarbonImmutable;
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

        $records = LeaveRecord::query()->with(['employee:id,employee_code,full_name,department_id', 'employee.department:id,name', 'type:id,name', 'author:id,name'])
            ->where('start_date', '<=', $last->toDateString())->where('end_date', '>=', $first->toDateString())
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('leave_type_id', $v))
            ->whereHas('employee', fn ($q) => $q->search($f['q'] ?? null)->when($f['department'] ?? null, fn ($d, $v) => $d->where('department_id', $v)))
            ->orderBy('start_date')->get();

        return Inertia::render('leave/index', [
            'month' => $first->format('Y-m'),
            'today' => $today->toDateString(),
            'filters' => $f,
            'records' => $records->map(fn (LeaveRecord $r) => $this->recordProps($r))->values(),
            'onLeaveToday' => LeaveRecord::query()->with('employee:id,full_name', 'type:id,name')
                ->where('start_date', '<=', $today->toDateString())->where('end_date', '>=', $today->toDateString())->get()
                ->map(fn ($r) => ['id' => $r->employee->id, 'name' => $r->employee->full_name, 'type' => $r->type->name, 'part' => $r->part])->values(),
            'types' => LeaveType::query()->withCount('records')->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn ($t) => [...$t->only('id', 'name', 'is_paid', 'is_active', 'sort_order'), 'used' => $t->records_count]),
            'holidays' => Holiday::query()->with('company:id,name')->whereYear('date', $first->year)->orderBy('date')->get()
                ->map(fn ($h) => ['id' => $h->id, 'date' => $h->date->toDateString(), 'name' => $h->name, 'company' => $h->company?->name, 'company_id' => $h->company_id]),
            'year' => $first->year,
            'people' => Employee::query()->whereIn('status', ['active', 'offboarding'])->orderBy('full_name')->get(['id', 'employee_code', 'full_name'])
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
        if ($data['part'] !== 'full' && $data['start_date'] !== $data['end_date']) {
            throw ValidationException::withMessages(['part' => 'Half-day leave is for a single day.']);
        }
        if (CarbonImmutable::parse($data['start_date'])->diffInDays(CarbonImmutable::parse($data['end_date'])) > 366) {
            throw ValidationException::withMessages(['end_date' => 'Leave can be at most a year long.']);
        }
        $clash = LeaveRecord::query()->with('type:id,name')->where('employee_id', $data['employee_id'])
            ->where('start_date', '<=', $data['end_date'])->where('end_date', '>=', $data['start_date'])->first();
        if ($clash) {
            throw ValidationException::withMessages(['start_date' => "Overlaps {$clash->type->name} leave from ".$clash->start_date->format('j M').' to '.$clash->end_date->format('j M').'.']);
        }

        $employee = Employee::findOrFail($data['employee_id']);
        $days = self::workingDays($employee, $data['start_date'], $data['end_date'], $data['part']);
        LeaveRecord::create($data + ['days' => $days, 'source' => 'manual', 'created_by' => $request->user()->id]);

        return back()->with('success', "Recorded {$days} ".($days == 1 ? 'day' : 'days')." of leave for {$employee->full_name}.");
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
        ]);
        $type->update($data);

        return back()->with('success', 'Saved.');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Working days in the range for this person: their schedule's working days, minus holidays.
     * Half a day for first_half / second_half. With no schedule, Monday to Friday.
     */
    public static function workingDays(Employee $employee, string $from, string $to, string $part = 'full'): float
    {
        if ($part !== 'full') {
            return 0.5;
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
