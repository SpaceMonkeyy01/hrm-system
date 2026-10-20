<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanRepayment;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Loans (can:edit-hr): HR's queue of requests from My page and the loans being repaid. HR approves alone, setting the
 * first deduction month (and the months, if they change); until payroll exists HR records each month's deduction.
 */
class LoanController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(array_keys(Loan::STATUSES))]]);
        $status = $f['status'] ?? 'pending';
        $loans = Loan::query()->with(['decider:id,name', 'repayments', 'employee:id,employee_code,full_name,department_id,start_date', 'employee.department:id,name'])
            ->where('status', $status)->whereHas('employee')
            ->when($status === 'pending', fn ($q) => $q->orderBy('created_at'), fn ($q) => $q->latest('decided_at')->latest('id'))
            ->limit(200)->get();
        // What else each person still owes, so HR sees it before approving another.
        $owing = Loan::query()->with('repayments')->where('status', 'active')->whereIn('employee_id', $loans->pluck('employee_id'))->get()
            ->groupBy('employee_id')->map(fn ($g) => round($g->sum(fn (Loan $l) => max(0, $l->amount - $l->repaid())), 2));

        return Inertia::render('loans/index', [
            'status' => $status,
            'statuses' => Loan::STATUSES,
            'counts' => Loan::query()->whereHas('employee')->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status'),
            'loans' => $loans->map(fn (Loan $l) => $l->props() + [
                'employee' => ['id' => $l->employee->id, 'name' => $l->employee->full_name, 'code' => $l->employee->employee_code,
                    'department' => $l->employee->department?->name, 'start_date' => $l->employee->start_date?->toDateString()],
                'owing_other' => round(($owing[$l->employee_id] ?? 0) - ($l->status === 'active' ? max(0, $l->amount - $l->repaid()) : 0), 2),
            ])->values(),
            'thisMonth' => CarbonImmutable::today()->format('Y-m'),
        ]);
    }

    public function approve(Request $request, Loan $loan): RedirectResponse
    {
        $data = $request->validate([
            'first_month' => ['required', 'date_format:Y-m'],
            'months' => ['required', 'integer', 'min:1', 'max:60'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['first_month.required' => 'Pick the first month to deduct from.']);
        $this->mustBe($loan, 'pending');
        $loan->update([
            'status' => 'active', 'months' => $data['months'], 'installment' => Loan::installmentFor($loan->amount, $data['months']),
            'first_month' => $data['first_month'].'-01', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null,
        ]);

        return back()->with('success', "Approved {$loan->employee->full_name}'s loan: Rs ".number_format($loan->installment).' a month for '.$loan->months.' months from '
            .CarbonImmutable::parse($loan->first_month)->format('M Y').'.');
    }

    public function reject(Request $request, Loan $loan): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']], ['note.required' => 'Say why, so the person knows what to do next.']);
        $this->mustBe($loan, 'pending');
        $loan->update(['status' => 'rejected', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $data['note']]);

        return back()->with('success', 'Not approved. The person sees your reason on My page.');
    }

    /** A month's deduction. When the deductions reach the amount, the loan closes as Repaid. */
    public function storeRepayment(Request $request, Loan $loan): RedirectResponse
    {
        $this->mustBe($loan, 'active');
        $left = round($loan->amount - $loan->repaid(), 2);
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'amount' => ['required', 'numeric', 'min:1', 'max:'.$left],
            'note' => ['nullable', 'string', 'max:300'],
        ], ['amount.max' => 'Only Rs '.number_format($left, 2).' is left on this loan.']);
        if ($loan->repayments()->whereDate('month', $data['month'].'-01')->exists()) {
            throw ValidationException::withMessages(['month' => 'That month is already recorded for this loan.']);
        }

        DB::transaction(function () use ($loan, $data, $request) {
            LoanRepayment::create(['loan_id' => $loan->id, 'month' => $data['month'].'-01', 'amount' => $data['amount'], 'note' => $data['note'] ?? null,
                'recorded_by' => $request->user()->id]);
            if ($loan->repaid() >= $loan->amount - 0.005) {
                $loan->update(['status' => 'repaid', 'repaid_at' => now()]);
            }
        });

        return back()->with('success', $loan->status === 'repaid' ? 'Recorded. The loan is fully repaid.' : 'Recorded the deduction.');
    }

    /** A deduction recorded by mistake. A loan that had closed opens again if it is no longer covered. */
    public function destroyRepayment(LoanRepayment $repayment): RedirectResponse
    {
        $loan = $repayment->loan;
        DB::transaction(function () use ($repayment, $loan) {
            $repayment->delete();
            if ($loan->status === 'repaid' && $loan->repaid() < $loan->amount - 0.005) {
                $loan->update(['status' => 'active', 'repaid_at' => null]);
            }
        });

        return back()->with('success', 'Removed the deduction.');
    }

    private function mustBe(Loan $loan, string $status): void
    {
        if ($loan->status !== $status) {
            throw ValidationException::withMessages(['status' => 'This loan is '.strtolower(Loan::STATUSES[$loan->status] ?? $loan->status).'.']);
        }
    }
}
