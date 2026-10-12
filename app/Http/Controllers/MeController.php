<?php

namespace App\Http\Controllers;

use App\Models\AssetAssignment;
use App\Models\AttendanceRequest;
use App\Models\Employee;
use App\Models\Review;
use App\Services\EmployeeRecords;
use App\Services\HrRules;
use App\Services\ReviewRules;
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

    private function employee(Request $request, bool $required = true): ?Employee
    {
        $id = $request->user()->employee_id;
        $employee = $id ? Employee::find($id) : null;
        abort_if($required && ! $employee, 403);

        return $employee;
    }
}
