<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\WfhRequest;
use App\Services\StaffMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR's queue of work from home requests from My page (can:edit-hr). Rejecting needs a reason the person sees. */
class WfhRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(array_keys(WfhRequest::STATUSES))]]);
        $status = $f['status'] ?? 'pending';
        $switch = Company::switchProps();
        $inCompany = fn ($q) => $q->inCompany($switch['company']);
        $requests = WfhRequest::query()->with(['decider:id,name', 'employee:id,employee_code,full_name,department_id,work_mode', 'employee.department:id,name'])
            ->where('status', $status)->whereHas('employee', $inCompany)
            ->when($status === 'pending', fn ($q) => $q->orderBy('start_date'), fn ($q) => $q->latest('decided_at')->latest('id'))
            ->limit(200)->get();

        return Inertia::render('attendance/wfh', [
            'status' => $status,
            'statuses' => WfhRequest::STATUSES,
            'counts' => WfhRequest::query()->whereHas('employee', $inCompany)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status'),
            'requests' => $requests->map(fn (WfhRequest $r) => $r->props() + [
                'employee' => ['id' => $r->employee->id, 'name' => $r->employee->full_name, 'code' => $r->employee->employee_code,
                    'department' => $r->employee->department?->name, 'work_mode' => $r->employee->work_mode],
            ])->values(),
        ] + $switch);
    }

    public function approve(Request $request, WfhRequest $wfhRequest): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->mustBePending($wfhRequest);
        $wfhRequest->update(['status' => 'approved', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null]);
        $sent = $this->tell($wfhRequest, 'approved', $data['note'] ?? null);

        return back()->with('success', "Approved {$wfhRequest->employee->full_name}'s work from home.".StaffMail::note($sent, $wfhRequest->employee->full_name));
    }

    private function tell(WfhRequest $r, string $outcome, ?string $note): bool
    {
        $when = StaffMail::dates($r->start_date, $r->end_date);

        return StaffMail::send($r->employee, $outcome === 'approved' ? 'Work from home approved' : 'Work from home not approved', [
            "Your request to work from home on $when was ".($outcome === 'approved' ? 'approved. Clock in on My page from home as usual.' : 'not approved.'),
            ...StaffMail::hrNote($note),
        ], '/me#requests');
    }

    public function reject(Request $request, WfhRequest $wfhRequest): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']], ['note.required' => 'Say why, so the person knows what to do next.']);
        $this->mustBePending($wfhRequest);
        $wfhRequest->update(['status' => 'rejected', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $data['note']]);
        $sent = $this->tell($wfhRequest, 'rejected', $data['note']);

        return back()->with('success', 'Not approved. The person sees your reason on My page.'.StaffMail::note($sent, $wfhRequest->employee->full_name));
    }

    private function mustBePending(WfhRequest $r): void
    {
        if ($r->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'This request was already '.strtolower(WfhRequest::STATUSES[$r->status] ?? $r->status).'.']);
        }
    }
}
