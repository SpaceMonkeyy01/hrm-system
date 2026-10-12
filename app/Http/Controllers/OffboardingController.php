<?php

namespace App\Http\Controllers;

use App\Models\ChecklistEntry;
use App\Models\Employee;
use App\Models\Offboarding;
use App\Services\HrRules;
use App\Services\StatusBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Offboarding_Tracker: type, reason, dates and exit interview, plus a clearance checklist.
 * Completing an offboarding marks the employee as exited.
 */
class OffboardingController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['in_progress', 'completed', 'cancelled', 'all'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $f['status'] ?? 'in_progress';

        $cases = Offboarding::query()
            ->with(['employee' => fn ($q) => $q->withTrashed()->with('department:id,name')])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($f['q'] ?? null, fn ($q, $term) => $q->whereHas('employee', fn ($e) => $e->withTrashed()->search($term)))
            ->orderByRaw('last_working_day is null')->orderBy('last_working_day')->latest('id')
            ->get();

        $board = new StatusBoard($cases->pluck('employee_id')->all());
        $total = count($board->items['offboarding']);

        return Inertia::render('offboarding/index', [
            'cases' => $cases->map(function (Offboarding $o) use ($board, $total) {
                $values = $board->values($o->employee_id, 'offboarding');

                return [
                    'id' => $o->id, 'type' => $o->type, 'status' => $o->status,
                    'notice_date' => $o->notice_date?->toDateString(),
                    'last_working_day' => $o->last_working_day?->toDateString(),
                    'employee' => ['id' => $o->employee->id, 'code' => $o->employee->employee_code,
                        'name' => $o->employee->full_name, 'department' => $o->employee->department?->name],
                    'cleared' => collect($values)->filter(fn ($v) => HrRules::isDone($v, 'clearance'))->count(),
                    'total' => $total,
                ];
            }),
            'filters' => ['status' => $status] + $f,
            'types' => config('hrm.offboarding_types'),
            // People who can be offboarded: current employees without an open case
            'employees' => Employee::query()->where('status', 'active')->orderBy('full_name')
                ->get(['id', 'employee_code', 'full_name'])
                ->map(fn ($e) => ['id' => $e->id, 'label' => "{$e->full_name} ({$e->employee_code})"]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // ARCH §4: only an active person can start offboarding. Exited is final; deleted people are gone.
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'type' => ['required', Rule::in(config('hrm.offboarding_types'))],
            'notice_date' => ['nullable', 'date'],
            'last_working_day' => ['nullable', 'date', 'after_or_equal:notice_date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ], [
            'employee_id.exists' => 'Only a current (active) employee can be offboarded. This person is already offboarding, has exited or was deleted.',
        ]);
        $employee = Employee::findOrFail($data['employee_id']);
        if (Offboarding::where('employee_id', $employee->id)->where('status', 'in_progress')->exists()) {
            throw ValidationException::withMessages(['employee_id' => "{$employee->full_name} already has an open offboarding."]);
        }

        $case = DB::transaction(function () use ($data, $employee, $request) {
            $employee->update(['status' => 'offboarding']);

            return Offboarding::create($data + ['status' => 'in_progress', 'created_by' => $request->user()->id]);
        });

        return redirect()->route('offboarding.show', $case)->with('success', "Offboarding started for {$employee->full_name}.");
    }

    public function show(Offboarding $offboarding): Response
    {
        $offboarding->load(['employee' => fn ($q) => $q->withTrashed()->with('department:id,name', 'company:id,name')]);
        $e = $offboarding->employee;
        $board = new StatusBoard([$e->id]);

        return Inertia::render('offboarding/show', [
            'case' => [
                ...$offboarding->only(['id', 'type', 'reason', 'status', 'exit_interview_notes', 'notes']),
                'notice_date' => $offboarding->notice_date?->toDateString(),
                'last_working_day' => $offboarding->last_working_day?->toDateString(),
                'completed_at' => $offboarding->completed_at?->toIso8601String(),
            ],
            'employee' => ['id' => $e->id, 'code' => $e->employee_code, 'name' => $e->full_name,
                'department' => $e->department?->name, 'company' => $e->company?->name,
                'start_date' => $e->start_date?->toDateString(), 'status' => $e->status],
            'checklist' => ['columns' => $board->columns('offboarding'), 'values' => $board->values($e->id, 'offboarding')],
            'optionSets' => StatusBoard::optionSets(),
            'types' => config('hrm.offboarding_types'),
        ]);
    }

    public function update(Request $request, Offboarding $offboarding): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(config('hrm.offboarding_types'))],
            'notice_date' => ['nullable', 'date'],
            'last_working_day' => ['nullable', 'date', 'after_or_equal:notice_date'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'exit_interview_notes' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $offboarding->update($data);

        return back()->with('success', 'Saved.');
    }

    public function complete(Offboarding $offboarding): RedirectResponse
    {
        $offboarding->loadMissing('employee');
        if ($offboarding->status !== 'in_progress') {
            return back()->with('error', 'Only an open offboarding can be completed.');
        }
        if (! $offboarding->employee || $offboarding->employee->trashed()) {
            return back()->with('error', "This employee was deleted, so they can't be marked as exited. Cancel the offboarding instead.");
        }
        $board = new StatusBoard([$offboarding->employee_id]);
        $columns = collect($board->columns('offboarding'));
        if ($columns->isEmpty()) {
            return back()->with('error', "There are no clearance items switched on, so clearance can't be checked. Switch them on in Settings, Checklists first.");
        }
        $values = $board->values($offboarding->employee_id, 'offboarding');
        $open = $columns->filter(fn ($c) => ! HrRules::isDone($values[$c['key']] ?? null, 'clearance'))->pluck('label');

        // Items switched off in Settings still count if this person has a value that isn't done.
        $switchedOff = ChecklistEntry::query()
            ->where('employee_id', $offboarding->employee_id)
            ->whereNotNull('status')
            ->whereHas('item', fn ($q) => $q->where('list', 'offboarding')->where('is_active', false))
            ->with('item:id,label')
            ->get()
            ->filter(fn ($entry) => ! HrRules::isDone($entry->status, 'clearance'))
            ->map(fn ($entry) => $entry->item->label.' (switched off in Settings)');

        $open = $open->concat($switchedOff);
        if ($open->isNotEmpty()) {
            return back()->with('error', 'Still open: '.$open->implode(', ').'. Mark them Done or Not Required first.');
        }

        DB::transaction(function () use ($offboarding) {
            $offboarding->update(['status' => 'completed', 'completed_at' => now()]);
            $offboarding->employee->update([
                'status' => 'exited',
                'exit_date' => $offboarding->last_working_day?->toDateString() ?? now()->toDateString(),
            ]);
        });

        return back()->with('success', "{$offboarding->employee->full_name} is now marked as exited.");
    }

    public function cancel(Offboarding $offboarding): RedirectResponse
    {
        $offboarding->loadMissing('employee');
        if ($offboarding->status !== 'in_progress') {
            return back()->with('error', 'Only an open offboarding can be cancelled.');
        }
        $reactivated = DB::transaction(function () use ($offboarding) {
            $offboarding->update(['status' => 'cancelled']);
            // Only someone who is offboarding goes back to active. Never an exited (or otherwise changed) person.
            if ($offboarding->employee?->status === 'offboarding') {
                $offboarding->employee->update(['status' => 'active']);

                return true;
            }

            return false;
        });

        return back()->with('success', $reactivated ? 'Offboarding cancelled. The employee is active again.' : 'Offboarding cancelled.');
    }
}
