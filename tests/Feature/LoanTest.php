<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Loans: asked on My page, approved by HR alone, repaid by deductions HR records. Today is Wed 20 May 2026. */
class LoanTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $me;

    private User $login;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-05-20 09:00'));
        $this->me = $this->employee($this->company, ['full_name' => 'Fake Me']);
        $this->login = $this->user('employee', ['employee_id' => $this->me->id]);
    }

    public function test_request_approve_and_repay_until_closed(): void
    {
        $ask = fn (array $d) => $this->actingAs($this->login)->from('/me')->post('/me/loans', $d);
        $ask(['amount' => 500, 'months' => 2, 'reason' => 'x'])->assertSessionHasErrors('amount');
        $ask(['amount' => 10000, 'months' => 61, 'reason' => 'x'])->assertSessionHasErrors('months');
        $ask(['amount' => 10000, 'months' => 3, 'reason' => 'Medical bills'])->assertSessionHasNoErrors();
        $ask(['amount' => 5000, 'months' => 1, 'reason' => 'Again'])->assertSessionHasErrors('amount');   // one waiting at a time
        $loan = Loan::sole();

        // Amounts and reasons stay out of the activity log.
        $this->assertSame(['hidden', 'hidden'], [ActivityLog::where('subject_type', 'Loan')->sole()->changes['amount'], ActivityLog::where('subject_type', 'Loan')->sole()->changes['reason']]);

        $hr = $this->user('hr');
        $this->actingAs($this->user('viewer'))->get('/loans')->assertForbidden();
        $this->actingAs($hr)->get('/requests')->assertInertia(fn (AssertableInertia $p) => $p->where('nav.inbox', 1));
        $this->actingAs($hr)->get('/loans')->assertInertia(fn (AssertableInertia $p) => $p->where('loans.0.amount', 10000)->where('loans.0.employee.name', 'Fake Me'));

        $this->actingAs($hr)->post("/loans/{$loan->id}/approve", ['months' => 3])->assertSessionHasErrors('first_month');
        $this->actingAs($hr)->post("/loans/{$loan->id}/approve", ['first_month' => '2026-06', 'months' => 4])->assertSessionHasNoErrors();
        $loan->refresh();
        $this->assertSame(['active', 4, 2500.0, '2026-06-01'], [$loan->status, $loan->months, $loan->installment, $loan->first_month->toDateString()]);

        $pay = fn (string $month, $amount) => $this->actingAs($hr)->post("/loans/{$loan->id}/repayments", ['month' => $month, 'amount' => $amount]);
        $pay('2026-06', 2500)->assertSessionHasNoErrors();
        $pay('2026-06', 2500)->assertSessionHasErrors('month');   // already recorded
        $pay('2026-07', 9000)->assertSessionHasErrors('amount');  // more than is left
        $pay('2026-07', 7500)->assertSessionHasNoErrors();
        $this->assertSame('repaid', $loan->fresh()->status);
        $pay('2026-08', 100)->assertSessionHasErrors('status');

        // Removing a deduction opens it again.
        $this->actingAs($hr)->delete('/loan-repayments/'.$loan->repayments()->whereDate('month', '2026-07-01')->sole()->id)->assertSessionHasNoErrors();
        $this->assertSame(['active', 2500.0], [$loan->fresh()->status, $loan->fresh()->repaid()]);

        $this->actingAs($this->login)->get('/me')->assertInertia(fn (AssertableInertia $p) => $p->where('loans.0.left', 7500)->where('loans.0.status_label', 'Being repaid'));
    }

    public function test_rejecting_needs_a_reason_and_only_pending_requests_cancel(): void
    {
        $loan = Loan::create(['employee_id' => $this->me->id, 'amount' => 20000, 'months' => 4, 'reason' => 'x']);
        $hr = $this->user('hr');
        $this->actingAs($hr)->post("/loans/{$loan->id}/reject")->assertSessionHasErrors('note');
        $this->actingAs($hr)->post("/loans/{$loan->id}/reject", ['note' => 'Still repaying the last one'])->assertSessionHasNoErrors();
        $this->actingAs($this->login)->delete("/me/loans/{$loan->id}")->assertSessionHasErrors('status');

        $other = Loan::create(['employee_id' => $this->employee($this->company)->id, 'amount' => 20000, 'months' => 4, 'reason' => 'x']);
        $this->actingAs($this->login)->delete("/me/loans/{$other->id}")->assertNotFound();
    }
}
