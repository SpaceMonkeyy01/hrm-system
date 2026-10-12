<?php

namespace App\Http\Controllers;

use App\Models\AttendanceAdjustment;
use App\Models\AttendanceRequest;
use App\Models\Employee;
use App\Services\AttendanceBoard;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR's queue of attendance fixes staff asked for on My page. Approving writes the asked-for times into an
 * AttendanceAdjustment (the same record HR's own manual times use); rejecting needs a reason the person sees.
 */
class AttendanceRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(array_keys(AttendanceRequest::STATUSES))]]);
        $status = $f['status'] ?? 'pending';
        $requests = AttendanceRequest::query()->with(['employee:id,employee_code,full_name,department_id,company_id,start_date,exit_date,status,attendance_machine_id,hr_portal_id',
            'employee.department:id,name', 'decider:id,name'])
            ->where('status', $status)->whereHas('employee')
            ->when($status === 'pending', fn ($q) => $q->orderBy('date'), fn ($q) => $q->latest('decided_at')->latest('id'))
            ->limit(200)->get();

        // What the day looks like now, so HR can compare before approving.
        $now = CarbonImmutable::now();
        $board = $requests->isEmpty() ? null : new AttendanceBoard(
            $requests->pluck('employee')->unique('id')->values(),
            $requests->min(fn ($r) => $r->date->toDateString()),
            $requests->max(fn ($r) => $r->date->toDateString()),
        );

        return Inertia::render('attendance/requests', [
            'status' => $status,
            'statuses' => AttendanceRequest::STATUSES,
            'counts' => AttendanceRequest::query()->whereHas('employee')->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status'),
            'requests' => $requests->map(function (AttendanceRequest $r) use ($board, $now) {
                $day = $board?->day($r->employee, $r->date->toDateString(), $now);

                return $r->props() + [
                    'employee' => ['id' => $r->employee->id, 'name' => $r->employee->full_name, 'code' => $r->employee->employee_code,
                        'department' => $r->employee->department?->name],
                    'now' => $day ? ['status' => $day['status'], 'in' => $day['in'], 'out' => $day['out'],
                        'punches' => array_map(fn ($t) => substr($t, 0, 5), $board->punchesOn($r->employee_id, $r->date->toDateString()))] : null,
                ];
            })->values(),
            'attendanceStatuses' => config('hrm.attendance.statuses'),
        ]);
    }

    public function approve(Request $request, AttendanceRequest $correction): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->mustBePending($correction);

        DB::transaction(function () use ($correction, $data, $request) {
            $existing = AttendanceAdjustment::where('employee_id', $correction->employee_id)->whereDate('date', $correction->date)->first();
            $note = 'Approved request: '.$correction->reason.(! empty($data['note']) ? " ({$data['note']})" : '');
            // A request for only the check-out keeps a check-in HR already set, and the other way round.
            $values = [
                'check_in' => $correction->check_in ?? $existing?->check_in,
                'check_out' => $correction->check_out ?? $existing?->check_out,
                'note' => $note, 'updated_by' => $request->user()->id,
            ];
            if ($existing) {
                $existing->update($values);
            } else {
                AttendanceAdjustment::create($values + ['employee_id' => $correction->employee_id, 'date' => $correction->date->toDateString()]);
            }
            $correction->update(['status' => 'approved', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null]);
        });

        return back()->with('success', 'Approved. The new times count on '.$correction->date->format('j M').'.');
    }

    public function reject(Request $request, AttendanceRequest $correction): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']], ['note.required' => 'Say why, so the person knows what to do next.']);
        $this->mustBePending($correction);
        $correction->update(['status' => 'rejected', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $data['note']]);

        return back()->with('success', 'Marked as not approved. The person sees your reason on their page.');
    }

    private function mustBePending(AttendanceRequest $correction): void
    {
        abort_unless(Employee::whereKey($correction->employee_id)->exists(), 404);
        if ($correction->status !== 'pending') {
            throw ValidationException::withMessages(['request' => 'This request was already '.strtolower(AttendanceRequest::STATUSES[$correction->status] ?? $correction->status).'.']);
        }
    }
}
