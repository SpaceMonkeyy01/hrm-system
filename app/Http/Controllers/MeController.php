<?php

namespace App\Http\Controllers;

use App\Models\AssetAssignment;
use App\Models\AttendanceRequest;
use App\Models\ConductEntry;
use App\Models\ControlledDocument;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\IssuedLetter;
use App\Models\LeaveRecord;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Loan;
use App\Models\MachineSync;
use App\Models\MedicalRequest;
use App\Models\Meeting;
use App\Models\Review;
use App\Models\WfhRequest;
use App\Services\AttendanceClock;
use App\Services\EmployeeRecords;
use App\Services\HrRules;
use App\Services\ReviewRules;
use App\Services\StatusBoard;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * My page: a login linked to an employee sees their own record, attendance and leave, clocks in and out,
 * updates their contact details and asks HR to fix a day. Every query here starts from $request->user()->employee_id, never from
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
        $employee->load(['company:id,name,hr_email','department:id,name', 'team:id,name', 'manager:id,full_name']);
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
                'photo' => $employee->photoUrl(),
                'bank_account_last4' => $employee->bank_account_number ? substr(preg_replace('/\D/', '', $employee->bank_account_number), -4) : null,
                'company' => $employee->company?->name,
                'department' => $employee->department?->name,
                'team' => $employee->team?->name,
                'manager' => $employee->manager?->full_name,
            ],
            'documents' => $board->items['document']->map(fn ($i) => [
                'label' => $i->label, 'status' => $docs[$i->key] ?? null, 'done' => HrRules::isDone($docs[$i->key] ?? null, 'document'),
            ])->values(),
            'clock' => AttendanceClock::state($employee, now(), $request->ip()),
            'attendance' => EmployeeRecords::attendanceMonth($employee, $request->input('month')),
            'attendanceYear' => fn () => EmployeeRecords::attendanceYear($employee),
            'leave' => EmployeeRecords::leaveYear($employee),
            'assets' => AssetAssignment::rowsFor($employee->id, withReturned: false),
            // Reviews in cycles HR has opened; HR's side only once it's finished (ReviewController::reviewProps).
            'reviews' => Review::query()->with(['cycle', 'reviewer:id,name'])->where('employee_id', $employee->id)
                ->where('status', '!=', 'cancelled')->whereHas('cycle', fn ($q) => $q->where('status', '!=', 'draft'))
                ->latest('id')->get()->map(fn (Review $r) => [
                    'cycle' => ReviewController::cycleProps($r->cycle) + ['criteria' => $r->cycle->criteria],
                    'canSelfReview' => ReviewRules::canSelfReview($r, $r->cycle),
                    ...ReviewController::reviewProps($r, withManager: false),
                    // A pay or promotion recommendation is HR's to act on, not part of the feedback.
                    'recommendation' => null,
                ]),
            'scale' => config('hrm.reviews.scale'),
            'requests' => AttendanceRequest::query()->with('decider:id,name')->where('employee_id', $employee->id)
                ->latest('id')->limit(30)->get()->map->props(),
            'leaveRequests' => LeaveRequest::query()->with('type:id,name', 'decider:id,name')->where('employee_id', $employee->id)
                ->latest('id')->limit(30)->get()->map->props(),
            // Their conduct record (all of it) and the meeting notes HR shared with them.
            'conduct' => ConductEntry::query()->with('author:id,name', 'letter:id,reference')->where('employee_id', $employee->id)
                ->latest('happened_on')->latest('id')->get()->map->props(),
            'meetings' => Meeting::query()->with('author:id,name')->where('employee_id', $employee->id)->where('shared', true)
                ->latest('held_on')->latest('id')->get()->map->props(),
            'loans' => Loan::query()->with('decider:id,name', 'repayments')->where('employee_id', $employee->id)
                ->latest('id')->limit(30)->get()->map->props(),
            'medicalRequests' => MedicalRequest::query()->with('decider:id,name', 'payer:id,name')->where('employee_id', $employee->id)
                ->latest('id')->limit(30)->get()->map->props(),
            'medicalKinds' => MedicalRequest::KINDS,
            'medicalPatients' => MedicalRequest::PATIENTS,
            'wfhRequests' => WfhRequest::query()->with('decider:id,name')->where('employee_id', $employee->id)
                ->latest('id')->limit(30)->get()->map->props(),
            'leaveTypes' => LeaveType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'yearly_days', 'per_leave_days']),
            'leaveParts' => LeaveRecord::PARTS,
            'uploads' => ['enabled' => (bool) config('hrm.uploads.enabled'), 'max_mb' => (int) round(config('hrm.uploads.max_kb') / 1024)],
            // My documents: letters HR issued to them, and the company's active policies
            'letters' => IssuedLetter::query()->where('employee_id', $employee->id)->latest('issued_on')->latest('id')->get(['id', 'reference', 'subject', 'issued_on'])
                ->map(fn ($l) => ['id' => $l->id, 'reference' => $l->reference, 'subject' => $l->subject, 'issued_on' => $l->issued_on->toDateString()]),
            'policies' => ControlledDocument::query()->where('doc_type', 'Policy')->where('status', 'Active')->orderBy('doc_number')
                ->get(['id', 'doc_number', 'name', 'description', 'link_url', 'issued_on'])
                ->map(fn ($d) => [...$d->only('id', 'doc_number', 'name', 'description', 'link_url'), 'issued_on' => $d->issued_on?->toDateString()]),
            'rules' => [
                'daysBack' => (int) config('hrm.self_service.request_days_back'),
                'editable' => config('hrm.self_service.editable_fields'),
            ],
            // This year's public holidays for their company (Leave list and "Coming up" on Today)
            'holidays' => Holiday::query()->whereYear('date', CarbonImmutable::today()->year)
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $employee->company_id))->orderBy('date')->get(['date', 'name'])
                ->map(fn ($h) => ['date' => $h->date->toDateString(), 'name' => $h->name])->unique('date')->values(),
            // Where "Ask HR" links send an email: their company's HR address, else the app's sender
            'hrEmail' => $employee->company?->hr_email ?: config('mail.from.address'),
            // Machine punches arrive with each sync, so Today says how fresh they are
            'lastSync' => $employee->attendance_machine_id
                ? MachineSync::query()->where('ok', true)->when($employee->attendance_device_id, fn ($q, $d) => $q->where('attendance_device_id', $d))
                    ->latest('finished_at')->first(['finished_at'])?->finished_at?->toIso8601String() : null,
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    /** Clock in or out. "action" is the button the page showed, so a second tap or a stale tab can't flip it. */
    public function clock(Request $request): RedirectResponse
    {
        $employee = $this->employee($request);
        $data = $request->validate([
            'action' => ['required', 'in:in,out'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'left_office' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ], ['left_office.regex' => 'Use a 24-hour time like 18:00.']);
        $punch = AttendanceClock::press($employee, $data['action'], $request->ip(), $data, now(), $data['left_office'] ?? null, $closed);
        $where = $punch->place === 'office' ? 'at the office' : 'remote';

        return back()->with('success', ($closed ? "Closed your office time at $closed. " : '')
            .($punch->method === 'In' ? 'Clocked in' : 'Clocked out').' at '.$punch->punched_at->format('H:i')." ($where).");
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

    /** The person's self-review. "submit" sends it to HR; without it the answers are saved as a draft. */
    public function saveSelfReview(Request $request, Review $review): RedirectResponse
    {
        $employee = $this->employee($request);
        abort_unless($review->employee_id === $employee->id, 404);
        $review->load('cycle');
        if (! ReviewRules::canSelfReview($review, $review->cycle)) {
            throw ValidationException::withMessages(['review' => 'This self-review can\'t be changed now.']);
        }
        $data = ReviewController::answers($request, $review->cycle, 'self');
        $submit = $request->boolean('submit');
        if ($submit) {
            $missing = ReviewRules::missingRatings($review->cycle->criteria, $data['self_ratings']);
            if ($missing) {
                throw ValidationException::withMessages(['self_ratings' => 'Rate every line before sending: '.implode(', ', $missing).'.']);
            }
            $data += ['status' => 'manager_review', 'self_submitted_at' => now()];
        }
        $review->update($data);

        return back()->with('success', $submit ? 'Sent to HR. You\'ll see the result here once HR finishes the review.' : 'Saved. You can come back and finish it.');
    }

    /** "I have read my review", with an optional comment for HR. */
    public function acknowledgeReview(Request $request, Review $review): RedirectResponse
    {
        $employee = $this->employee($request);
        abort_unless($review->employee_id === $employee->id, 404);
        if ($review->status !== 'completed' || $review->acknowledged_at) {
            throw ValidationException::withMessages(['review' => 'There is nothing to acknowledge on this review.']);
        }
        $data = $request->validate(['employee_comment' => ['nullable', 'string', 'max:2000']]);
        $review->update(['acknowledged_at' => now(), 'employee_comment' => $data['employee_comment'] ?? null]);

        return back()->with('success', 'Thanks. HR can see you have read your review.');
    }

    // ---------------------------------------------------------------- leave requests

    /**
     * Apply for leave. The signed leave form (with the manager's approval) is attached; HR approves it. Required while
     * uploads are on; with uploads switched off (no file store yet) the person hands the form to HR instead.
     * Going over the balance is allowed: the message says so, and HR decides.
     */
    public function storeLeaveRequest(Request $request): RedirectResponse
    {
        $employee = $this->employee($request);
        $cfg = config('hrm.uploads');
        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('is_active', true)],
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.CarbonImmutable::today()->subDays((int) config('hrm.self_service.request_days_back'))->toDateString()],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'part' => ['required', Rule::in(array_keys(LeaveRecord::PARTS))],
            'reason' => ['required', 'string', 'max:1000'],
            'attachment' => $cfg['enabled'] ? ['required', 'file', 'mimes:'.implode(',', $cfg['extensions']), 'max:'.$cfg['max_kb']] : ['prohibited'],
        ], [
            'start_date.after_or_equal' => 'That is too far back to ask for. Talk to HR.',
            'end_date.after_or_equal' => 'The last day can\'t be before the first day.',
            'reason.required' => 'Say what the leave is for.',
            'attachment.required' => 'Attach the signed leave form with your manager\'s approval.',
            'attachment.mimes' => 'Attach a PDF, JPG or PNG file.',
            'attachment.max' => 'The file is larger than '.round($cfg['max_kb'] / 1024).' MB.',
            'attachment.prohibited' => 'Attachments are switched off for now. Hand the signed form to HR.',
        ]);
        LeaveController::checkDates($data);
        LeaveController::refuseClash($employee->id, $data['start_date'], $data['end_date']);
        $open = LeaveRequest::query()->where('employee_id', $employee->id)->where('status', 'pending')
            ->where('start_date', '<=', $data['end_date'])->where('end_date', '>=', $data['start_date'])->exists();
        if ($open) {
            throw ValidationException::withMessages(['start_date' => 'You already asked for leave on some of these days. Cancel that request first.']);
        }

        $file = $data['attachment'] ?? null;
        $stored = null;
        if ($file) {
            $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
            $stored = $file->storeAs("leave-requests/{$employee->id}", Str::random(40).'.'.$ext, $cfg['disk']);
        }
        $type = LeaveType::findOrFail($data['leave_type_id']);
        try {
            LeaveRequest::create([
                'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => $data['start_date'], 'end_date' => $data['end_date'],
                'part' => $data['part'], 'reason' => $data['reason'], 'requested_by' => $request->user()->id,
                'days' => LeaveController::workingDays($employee, $data['start_date'], $data['end_date'], $data['part']),
                'attachment_path' => $stored, 'attachment_name' => $file ? Str::limit(basename($file->getClientOriginalName()), 250, '') : null,
                'attachment_mime' => $file?->getMimeType(), 'attachment_size' => $file?->getSize(),
            ]);
        } catch (\Throwable $e) {
            $stored && Storage::disk($cfg['disk'])->delete($stored);   // no orphan file without a row
            throw $e;
        }

        return back()->with('success', 'Sent to HR. You\'ll see their answer here.'.$this->overNote($employee, $type, $data['start_date'], $data['end_date'], $data['part']));
    }

    public function cancelLeaveRequest(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        abort_unless($leaveRequest->employee_id === $this->employee($request)->id, 404);
        if ($leaveRequest->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Only a request HR hasn\'t answered yet can be cancelled.']);
        }
        $leaveRequest->update(['status' => 'cancelled']);

        return back()->with('success', 'Request cancelled.');
    }

    public function storeWfhRequest(Request $request): RedirectResponse
    {
        $employee = $this->employee($request);
        $data = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.CarbonImmutable::today()->subDays((int) config('hrm.self_service.request_days_back'))->toDateString()],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'start_date.after_or_equal' => 'That is too far back to ask for. Talk to HR.',
            'end_date.after_or_equal' => 'The last day can\'t be before the first day.',
            'reason.required' => 'Say why you need to work from home.',
        ]);
        if (CarbonImmutable::parse($data['start_date'])->diffInDays(CarbonImmutable::parse($data['end_date'])) + 1 > WfhRequest::MAX_DAYS) {
            throw ValidationException::withMessages(['end_date' => 'Ask for at most '.WfhRequest::MAX_DAYS.' days at a time. For longer, talk to HR about your work mode.']);
        }
        $clash = WfhRequest::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
            ->where('start_date', '<=', $data['end_date'])->where('end_date', '>=', $data['start_date'])->exists();
        if ($clash) {
            throw ValidationException::withMessages(['start_date' => 'You already asked to work from home on some of these days.']);
        }
        WfhRequest::create($data + ['employee_id' => $employee->id, 'requested_by' => $request->user()->id]);

        return back()->with('success', 'Sent to HR. You\'ll see the answer here.');
    }

    public function cancelWfhRequest(Request $request, WfhRequest $wfhRequest): RedirectResponse
    {
        abort_unless($wfhRequest->employee_id === $this->employee($request)->id, 404);
        if ($wfhRequest->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Only a request HR hasn\'t answered yet can be cancelled.']);
        }
        $wfhRequest->update(['status' => 'cancelled']);

        return back()->with('success', 'Request cancelled.');
    }

    public function storeLoan(Request $request): RedirectResponse
    {
        $employee = $this->employee($request);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1000', 'max:10000000'],
            'months' => ['required', 'integer', 'min:1', 'max:60'],
            'reason' => ['required', 'string', 'max:1000'],
        ], ['amount.min' => 'The smallest loan is Rs 1,000.', 'months.max' => 'Repay it in at most 60 months.', 'reason.required' => 'Say what the loan is for.']);
        if (Loan::query()->where('employee_id', $employee->id)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['amount' => 'You already have a loan request waiting for HR. Cancel it first to send a new one.']);
        }
        Loan::create($data + ['employee_id' => $employee->id, 'requested_by' => $request->user()->id]);

        return back()->with('success', 'Sent to HR. You\'ll see the answer here.');
    }

    public function cancelLoan(Request $request, Loan $loan): RedirectResponse
    {
        abort_unless($loan->employee_id === $this->employee($request)->id, 404);
        if ($loan->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Only a request HR hasn\'t answered yet can be cancelled.']);
        }
        $loan->update(['status' => 'cancelled']);

        return back()->with('success', 'Loan request cancelled.');
    }

    /**
     * Claim back a medical bill (bills attached, required while uploads are on) or ask for an advance for treatment
     * (a doctor's estimate may be attached). HR approves the amount, then marks it paid.
     */
    public function storeMedicalRequest(Request $request): RedirectResponse
    {
        $employee = $this->employee($request);
        $cfg = config('hrm.uploads');
        $file = ['file', 'mimes:'.implode(',', $cfg['extensions']), 'max:'.$cfg['max_kb']];
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(MedicalRequest::KINDS))],
            'patient' => ['required', Rule::in(array_keys(MedicalRequest::PATIENTS))],
            'treatment_date' => ['nullable', 'required_if:kind,reimbursement', 'date_format:Y-m-d', 'after_or_equal:'.CarbonImmutable::today()->subYear()->toDateString(), 'before_or_equal:'.CarbonImmutable::today()->addYear()->toDateString()],
            'amount' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'reason' => ['required', 'string', 'max:1000'],
            'attachment' => ! $cfg['enabled'] ? ['prohibited'] : ($request->input('kind') === 'reimbursement' ? ['required', ...$file] : ['nullable', ...$file]),
        ], [
            'treatment_date.required_if' => 'Give the date of the treatment on the bill.',
            'treatment_date.after_or_equal' => 'That is more than a year ago. Talk to HR.',
            'treatment_date.before_or_equal' => 'Pick a date within the next year.',
            'reason.required' => 'Say what the treatment is for.',
            'attachment.required' => 'Attach the bills (a photo is fine). Put several bills in one PDF, or send one request each.',
            'attachment.mimes' => 'Attach a PDF, JPG or PNG file.',
            'attachment.max' => 'The file is larger than '.round($cfg['max_kb'] / 1024).' MB.',
            'attachment.prohibited' => 'Attachments are switched off for now. Hand the bills to HR.',
        ]);
        if ($data['kind'] === 'reimbursement' && ! empty($data['treatment_date']) && $data['treatment_date'] > CarbonImmutable::today()->toDateString()) {
            throw ValidationException::withMessages(['treatment_date' => 'A bill to claim back is for treatment that already happened.']);
        }

        $upload = $data['attachment'] ?? null;
        $stored = null;
        if ($upload) {
            $ext = strtolower($upload->guessExtension() ?: $upload->getClientOriginalExtension());
            $stored = $upload->storeAs("medical-requests/{$employee->id}", Str::random(40).'.'.$ext, $cfg['disk']);
        }
        try {
            MedicalRequest::create([
                'employee_id' => $employee->id, 'kind' => $data['kind'], 'patient' => $data['patient'], 'treatment_date' => $data['treatment_date'] ?? null,
                'amount' => $data['amount'], 'reason' => $data['reason'], 'requested_by' => $request->user()->id,
                'attachment_path' => $stored, 'attachment_name' => $upload ? Str::limit(basename($upload->getClientOriginalName()), 250, '') : null,
                'attachment_mime' => $upload?->getMimeType(), 'attachment_size' => $upload?->getSize(),
            ]);
        } catch (\Throwable $e) {
            $stored && Storage::disk($cfg['disk'])->delete($stored);   // no orphan file without a row
            throw $e;
        }

        return back()->with('success', 'Sent to HR. You\'ll see the answer here.');
    }

    public function cancelMedicalRequest(Request $request, MedicalRequest $medicalRequest): RedirectResponse
    {
        abort_unless($medicalRequest->employee_id === $this->employee($request)->id, 404);
        if ($medicalRequest->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Only a request HR hasn\'t answered yet can be cancelled.']);
        }
        $medicalRequest->update(['status' => 'cancelled']);

        return back()->with('success', 'Medical request cancelled.');
    }

    public function medicalRequestFile(Request $request, MedicalRequest $medicalRequest): StreamedResponse
    {
        abort_unless($medicalRequest->employee_id === $this->employee($request)->id, 404);

        return MedicalRequestController::stream($medicalRequest);
    }

    public function acknowledgeConduct(Request $request, ConductEntry $entry): RedirectResponse
    {
        abort_unless($entry->employee_id === $this->employee($request)->id, 404);

        return $this->acknowledge($request, $entry);
    }

    public function acknowledgeMeeting(Request $request, Meeting $meeting): RedirectResponse
    {
        abort_unless($meeting->employee_id === $this->employee($request)->id && $meeting->shared, 404);

        return $this->acknowledge($request, $meeting);
    }

    /** "I've read this", with an optional comment HR sees. Once per version: HR changing it asks again. */
    private function acknowledge(Request $request, ConductEntry|Meeting $item): RedirectResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        if ($item->acknowledged_at) {
            throw ValidationException::withMessages(['comment' => 'You already acknowledged this.']);
        }
        $item->update(['acknowledged_at' => now(), 'employee_comment' => $data['comment'] ?? $item->employee_comment]);

        return back()->with('success', 'Acknowledged. HR can see that you read it'.(! empty($data['comment']) ? ', and your comment.' : '.'));
    }

    public function leaveRequestFile(Request $request, LeaveRequest $leaveRequest): StreamedResponse
    {
        abort_unless($leaveRequest->employee_id === $this->employee($request)->id, 404);

        return LeaveRequestController::stream($leaveRequest);
    }

    /** A letter HR issued to them, on the same A4 sheet HR prints. */
    public function letter(Request $request, IssuedLetter $letter): Response
    {
        abort_unless($letter->employee_id === $this->employee($request)->id, 404);

        return LetterController::printPage($letter, ['href' => '/me#documents', 'label' => 'My page']);
    }

    /** " This goes 2 days over your Annual balance for 2026; HR decides." when the request would. */
    private function overNote(Employee $employee, LeaveType $type, string $from, string $to, string $part): string
    {
        if ($type->per_leave_days !== null) {
            $over = \App\Services\LeaveBalances::overLimit($type, $from, $to);

            return $over ? " It is $over ".($over === 1 ? 'day' : 'days')." longer than the {$type->per_leave_days}-day {$type->name} allowance; HR decides." : '';
        }
        if ($type->yearly_days === null) {
            return '';
        }
        $year = (int) substr($from, 0, 4);
        $b = collect(\App\Services\LeaveBalances::forEmployee($employee, $year))->firstWhere('type_id', $type->id);
        $after = $b ? $b['left'] - LeaveController::workingDays($employee, $from, $to, $part) : 0;

        return $b && $after < 0 ? ' This goes '.(0 - $after)." ".(0 - $after == 1 ? 'day' : 'days')." over your {$type->name} balance for $year; HR decides." : '';
    }

    private function employee(Request $request, bool $required = true): ?Employee
    {
        $id = $request->user()->employee_id;
        $employee = $id ? Employee::find($id) : null;
        abort_if($required && ! $employee, 403);

        return $employee;
    }
}
