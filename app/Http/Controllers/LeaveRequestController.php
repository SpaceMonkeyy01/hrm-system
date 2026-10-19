<?php

namespace App\Http\Controllers;

use App\Models\LeaveRecord;
use App\Models\LeaveRequest;
use App\Services\LeaveBalances;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR's queue of leave requests from My page (can:edit-hr). HR checks the attached form (the manager's approval is
 * on it) and approves, which records the leave like Record leave does; rejecting needs a reason the person sees.
 */
class LeaveRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(array_keys(LeaveRequest::STATUSES))]]);
        $status = $f['status'] ?? 'pending';
        $requests = LeaveRequest::query()->with(['type:id,name,yearly_days,per_leave_days', 'decider:id,name',
            'employee:id,employee_code,full_name,department_id,company_id,start_date', 'employee.department:id,name'])
            ->where('status', $status)->whereHas('employee')
            ->when($status === 'pending', fn ($q) => $q->orderBy('start_date'), fn ($q) => $q->latest('decided_at')->latest('id'))
            ->limit(200)->get();

        return Inertia::render('leave/requests', [
            'status' => $status,
            'statuses' => LeaveRequest::STATUSES,
            'counts' => LeaveRequest::query()->whereHas('employee')->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status'),
            'requests' => $requests->map(fn (LeaveRequest $r) => $r->props() + [
                'employee' => ['id' => $r->employee->id, 'name' => $r->employee->full_name, 'code' => $r->employee->employee_code,
                    'department' => $r->employee->department?->name],
                // What approving would leave: this type's balance for the year, or how far past a per-leave allowance it runs
                'balance' => $r->type?->yearly_days !== null
                    ? collect(LeaveBalances::forEmployee($r->employee, $r->start_date->year))->firstWhere('type_id', $r->leave_type_id)
                    : null,
                'over_per_leave' => $r->type ? LeaveBalances::overLimit($r->type, $r->start_date->toDateString(), $r->end_date->toDateString()) : 0,
                'per_leave_days' => $r->type?->per_leave_days,
            ])->values(),
        ]);
    }

    /** The attached form, inline (PDF and images open in the browser). */
    public function file(LeaveRequest $leaveRequest): StreamedResponse
    {
        return self::stream($leaveRequest);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->mustBePending($leaveRequest);
        $leaveRequest->loadMissing('employee', 'type');
        $from = $leaveRequest->start_date->toDateString();
        $to = $leaveRequest->end_date->toDateString();
        LeaveController::refuseClash($leaveRequest->employee_id, $from, $to);

        DB::transaction(function () use ($leaveRequest, $data, $request, $from, $to) {
            $record = LeaveRecord::create([
                'employee_id' => $leaveRequest->employee_id, 'leave_type_id' => $leaveRequest->leave_type_id, 'start_date' => $from, 'end_date' => $to,
                'part' => $leaveRequest->part, 'source' => 'request', 'created_by' => $request->user()->id,
                'days' => LeaveController::workingDays($leaveRequest->employee, $from, $to, $leaveRequest->part),
                'note' => trim('Request: '.($leaveRequest->reason ?? '').(! empty($data['note']) ? " ({$data['note']})" : '')),
            ]);
            $leaveRequest->update(['status' => 'approved', 'decided_by' => $request->user()->id, 'decided_at' => now(),
                'decision_note' => $data['note'] ?? null, 'leave_record_id' => $record->id]);
        });

        return back()->with('success', "Approved {$leaveRequest->employee->full_name}'s {$leaveRequest->type->name} leave."
            .LeaveController::balanceNote($leaveRequest->employee, $leaveRequest->type, $from, $to));
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']], ['note.required' => 'Say why, so the person knows what to do next.']);
        $this->mustBePending($leaveRequest);
        $leaveRequest->update(['status' => 'rejected', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $data['note']]);

        return back()->with('success', 'Not approved. The person sees your reason on My page.');
    }

    /** Opens a request's attachment; the caller checks who may see it. */
    public static function stream(LeaveRequest $r): StreamedResponse
    {
        $disk = Storage::disk(config('hrm.uploads.disk'));
        abort_unless($r->attachment_path && $disk->exists($r->attachment_path), 404, 'The file is missing from storage.');

        return $disk->response($r->attachment_path, $r->attachment_name, [
            'Content-Type' => $r->attachment_mime ?? 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    private function mustBePending(LeaveRequest $r): void
    {
        if ($r->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'This request was already '.strtolower(LeaveRequest::STATUSES[$r->status] ?? $r->status).'.']);
        }
    }
}
