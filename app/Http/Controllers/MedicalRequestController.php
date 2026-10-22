<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\MedicalRequest;
use App\Services\StaffMail;
use Carbon\CarbonImmutable;
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
 * Medical requests (can:edit-hr): HR's queue of bills to claim back and advances for treatment from My page. HR
 * approves the amount asked or less (no yearly limit; the page shows what the person had this year), then marks it
 * paid with the date and how, until payroll is in the app.
 */
class MedicalRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(array_keys(MedicalRequest::STATUSES))]]);
        $status = $f['status'] ?? 'pending';
        $switch = Company::switchProps();
        $inCompany = fn ($q) => $q->inCompany($switch['company']);
        $requests = MedicalRequest::query()->with(['decider:id,name', 'payer:id,name', 'employee:id,employee_code,full_name,department_id', 'employee.department:id,name'])
            ->where('status', $status)->whereHas('employee', $inCompany)
            ->when($status === 'pending', fn ($q) => $q->orderBy('created_at'), fn ($q) => $q->latest('decided_at')->latest('id'))
            ->limit(200)->get();
        $year = CarbonImmutable::today()->year;
        $thisYear = MedicalRequest::approvedInYear($requests->pluck('employee_id')->unique()->values()->all(), $year);

        return Inertia::render('medical/index', [
            'status' => $status,
            'statuses' => MedicalRequest::STATUSES,
            'counts' => MedicalRequest::query()->whereHas('employee', $inCompany)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status'),
            'requests' => $requests->map(fn (MedicalRequest $r) => $r->props() + [
                'employee' => ['id' => $r->employee->id, 'name' => $r->employee->full_name, 'code' => $r->employee->employee_code,
                    'department' => $r->employee->department?->name],
                'this_year' => $thisYear[$r->employee_id] ?? 0,
            ])->values(),
            'year' => $year,
            'today' => CarbonImmutable::today()->toDateString(),
        ] + $switch);
    }

    /** The attached bills, inline (PDF and images open in the browser). */
    public function file(MedicalRequest $medicalRequest): StreamedResponse
    {
        return self::stream($medicalRequest);
    }

    /** Approve the amount asked or less. */
    public function approve(Request $request, MedicalRequest $medicalRequest): RedirectResponse
    {
        $data = $request->validate([
            'approved_amount' => ['required', 'numeric', 'min:1', 'max:'.$medicalRequest->amount],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['approved_amount.max' => 'Approve at most the Rs '.number_format($medicalRequest->amount, 2).' they asked for.']);
        $this->mustBe($medicalRequest, 'pending');
        $medicalRequest->update(['status' => 'approved', 'approved_amount' => $data['approved_amount'], 'decided_by' => $request->user()->id,
            'decided_at' => now(), 'decision_note' => $data['note'] ?? null]);

        $amount = 'Rs '.number_format($medicalRequest->approved_amount);
        $part = $medicalRequest->approved_amount < $medicalRequest->amount ? " of the Rs ".number_format($medicalRequest->amount).' you asked for' : '';
        $sent = StaffMail::send($medicalRequest->employee, 'Your medical request was approved', [
            "Your medical request was approved for $amount$part. HR will let you know when it is paid.",
            ...StaffMail::hrNote($data['note'] ?? null),
        ], '/me#requests');

        return back()->with('success', "Approved $amount for {$medicalRequest->employee->full_name}.".StaffMail::note($sent, $medicalRequest->employee->full_name));
    }

    public function reject(Request $request, MedicalRequest $medicalRequest): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']], ['note.required' => 'Say why, so the person knows what to do next.']);
        $this->mustBe($medicalRequest, 'pending');
        $medicalRequest->update(['status' => 'rejected', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $data['note']]);
        $sent = StaffMail::send($medicalRequest->employee, 'Your medical request was not approved',
            ['Your medical request was not approved.', ...StaffMail::hrNote($data['note'])], '/me#requests');

        return back()->with('success', 'Not approved. The person sees your reason on My page.'.StaffMail::note($sent, $medicalRequest->employee->full_name));
    }

    /** The money reached them: the date and how (cash, bank transfer, with the salary). */
    public function paid(Request $request, MedicalRequest $medicalRequest): RedirectResponse
    {
        $data = $request->validate([
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'paid_note' => ['nullable', 'string', 'max:300'],
        ], ['paid_on.before_or_equal' => 'Mark it paid once the money has gone out.']);
        $this->mustBe($medicalRequest, 'approved');
        $medicalRequest->update(['status' => 'paid', 'paid_on' => $data['paid_on'], 'paid_note' => $data['paid_note'] ?? null, 'paid_by' => $request->user()->id]);
        $sent = StaffMail::send($medicalRequest->employee, 'Your medical request was paid', [
            'Rs '.number_format($medicalRequest->approved_amount).' for your medical request was paid on '.$medicalRequest->paid_on->format('j M Y')
                .($medicalRequest->paid_note ? " ({$medicalRequest->paid_note})" : '').'.',
        ], '/me#requests');

        return back()->with('success', 'Marked as paid.'.StaffMail::note($sent, $medicalRequest->employee->full_name));
    }

    /** Opens a request's attachment; the caller checks who may see it. */
    public static function stream(MedicalRequest $r): StreamedResponse
    {
        $disk = Storage::disk(config('hrm.uploads.disk'));
        abort_unless($r->attachment_path && $disk->exists($r->attachment_path), 404, 'The file is missing from storage.');

        return $disk->response($r->attachment_path, $r->attachment_name, [
            'Content-Type' => $r->attachment_mime ?? 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    private function mustBe(MedicalRequest $r, string $status): void
    {
        if ($r->status !== $status) {
            throw ValidationException::withMessages(['status' => 'This request is '.strtolower(MedicalRequest::STATUSES[$r->status] ?? $r->status).'.']);
        }
    }
}
