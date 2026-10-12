<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRequest;
use App\Models\Employee;
use App\Services\EmployeeRecords;
use App\Services\HrRules;
use App\Services\StatusBoard;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My page: a login linked to an employee sees their own record, attendance and leave, updates their contact
 * details and asks HR to fix a day. Every query here starts from $request->user()->employee_id, never from
 * an id in the URL, so nobody can open someone else's record.
 */
class MeController extends Controller
{
    /** Same rules as the HR edit form for these fields (EmployeeController::rules). */
    private const CONTACT_RULES = [
        'phone' => ['nullable', 'string', 'max:40'],
        'alternate_phone' => ['nullable', 'string', 'max:40'],
        'personal_email' => ['nullable', 'email', 'max:150'],
        'current_address' => ['nullable', 'string', 'max:500'],
        'city' => ['nullable', 'string', 'max:80'],
        'emergency_contact_name' => ['nullable', 'string', 'max:150'],
        'emergency_contact_relation' => ['nullable', 'string', 'max:40'],
        'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
    ];

    public function show(Request $request): Response
    {
        $employee = $this->employee($request, required: false);
        if (! $employee) {
            return Inertia::render('me/show', ['employee' => null]);
        }
        $employee->load(['company:id,name', 'department:id,name', 'team:id,name', 'manager:id,full_name']);
        $board = new StatusBoard([$employee->id]);
        $docs = $board->values($employee->id, 'document');

        return Inertia::render('me/show', [
            'employee' => [
                ...$employee->only(['system_id', 'employee_code', 'full_name', 'email', 'role_title', 'employment_type', 'work_mode', 'work_location',
                    'cnic', 'father_name', 'gender', 'marital_status', 'blood_group', 'bank_name', 'bank_account_title', 'permanent_address',
                    ...config('hrm.self_service.editable_fields')]),
                'start_date' => $employee->start_date?->toDateString(),
                'confirmation_date' => $employee->confirmation_date?->toDateString(),
                'date_of_birth' => $employee->date_of_birth?->toDateString(),
                'cnic_expiry' => $employee->cnic_expiry?->toDateString(),
                // Only the last four digits: enough to recognise the account.
                'bank_account_last4' => $employee->bank_account_number ? substr(preg_replace('/\D/', '', $employee->bank_account_number), -4) : null,
                'company' => $employee->company?->name,
                'department' => $employee->department?->name,
                'team' => $employee->team?->name,
                'manager' => $employee->manager?->full_name,
            ],
            'documents' => $board->items['document']->map(fn ($i) => [
                'label' => $i->label, 'status' => $docs[$i->key] ?? null, 'done' => HrRules::isDone($docs[$i->key] ?? null, 'document'),
            ])->values(),
            'attendance' => EmployeeRecords::attendanceMonth($employee, $request->input('month')),
            'leave' => EmployeeRecords::leaveYear($employee),
            'requests' => AttendanceRequest::query()->with('decider:id,name')->where('employee_id', $employee->id)
                ->latest('id')->limit(30)->get()->map->props(),
            'rules' => [
                'daysBack' => (int) config('hrm.self_service.request_days_back'),
                'editable' => config('hrm.self_service.editable_fields'),
            ],
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function updateContact(Request $request): RedirectResponse
    {
        $employee = $this->employee($request);
        $data = $request->validate(array_intersect_key(self::CONTACT_RULES, array_flip(config('hrm.self_service.editable_fields'))));
        $employee->update($data);

        return back()->with('success', 'Saved your contact details.');
    }

    public function storeRequest(Request $request): RedirectResponse
    {
        $employee = $this->employee($request);
        $today = CarbonImmutable::today();
        $earliest = $today->subDays((int) config('hrm.self_service.request_days_back'));
        $time = ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString(), 'after_or_equal:'.$earliest->toDateString()],
            'check_in' => $time,
            'check_out' => $time,
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'date.before_or_equal' => 'You can only ask about today or an earlier day.',
            'date.after_or_equal' => 'You can ask about the last '.config('hrm.self_service.request_days_back').' days. For older days, talk to HR.',
            'check_in.regex' => 'Use a 24-hour time like 09:00.',
            'check_out.regex' => 'Use a 24-hour time like 17:00.',
            'reason.required' => 'Say what happened, e.g. "Forgot to punch out".',
        ]);
        if (empty($data['check_in']) && empty($data['check_out'])) {
            throw ValidationException::withMessages(['check_in' => 'Enter the check-in time, the check-out time, or both.']);
        }
        if (! empty($data['check_in']) && ($data['check_out'] ?? null) === $data['check_in']) {
            throw ValidationException::withMessages(['check_out' => 'Check-out can\'t be the same time as check-in.']);
        }
        if ($employee->start_date && $data['date'] < $employee->start_date->toDateString()) {
            throw ValidationException::withMessages(['date' => 'That day is before your start date.']);
        }
        if (AttendanceRequest::where('employee_id', $employee->id)->whereDate('date', $data['date'])->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['date' => 'You already have a request for that day waiting for HR. Cancel it first to send a new one.']);
        }

        AttendanceRequest::create($data + ['employee_id' => $employee->id, 'requested_by' => $request->user()->id]);

        return back()->with('success', 'Sent to HR. You\'ll see the answer under Requests.');
    }

    public function cancelRequest(Request $request, AttendanceRequest $correction): RedirectResponse
    {
        $employee = $this->employee($request);
        // Someone else's request looks the same as one that doesn't exist.
        abort_unless($correction->employee_id === $employee->id, 404);
        if ($correction->status !== 'pending') {
            throw ValidationException::withMessages(['request' => 'HR has already answered this request.']);
        }
        $correction->update(['status' => 'cancelled']);

        return back()->with('success', 'Request cancelled.');
    }

    private function employee(Request $request, bool $required = true): ?Employee
    {
        $id = $request->user()->employee_id;
        $employee = $id ? Employee::find($id) : null;
        abort_if($required && ! $employee, 403);

        return $employee;
    }
}
