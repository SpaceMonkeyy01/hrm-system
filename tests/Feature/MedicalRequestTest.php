<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\MedicalRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Medical requests: a bill to claim back or an advance, asked on My page; HR approves an amount, then marks it paid. Today is Wed 20 May 2026. */
class MedicalRequestTest extends TestCase
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
        Storage::fake(config('hrm.uploads.disk'));
        $this->me = $this->employee($this->company, ['full_name' => 'Fake Me']);
        $this->login = $this->user('employee', ['employee_id' => $this->me->id]);
    }

    private function ask(array $data)
    {
        return $this->actingAs($this->login)->from('/me')->post('/me/medical-requests', $data);
    }

    public function test_claim_a_bill_approve_less_and_mark_paid(): void
    {
        $claim = ['kind' => 'reimbursement', 'patient' => 'child', 'treatment_date' => '2026-05-12', 'amount' => 8000, 'reason' => 'Doctor and medicines'];
        $this->ask($claim)->assertSessionHasErrors('attachment');   // bills required for a claim
        $this->ask(['treatment_date' => null] + $claim + ['attachment' => UploadedFile::fake()->create('bill.pdf', 50, 'application/pdf')])->assertSessionHasErrors('treatment_date');
        $this->ask(['treatment_date' => '2026-06-01'] + $claim + ['attachment' => UploadedFile::fake()->create('bill.pdf', 50, 'application/pdf')])->assertSessionHasErrors('treatment_date');   // not yet happened
        $this->ask($claim + ['attachment' => UploadedFile::fake()->create('bill.exe', 50)])->assertSessionHasErrors('attachment');
        $this->ask($claim + ['attachment' => UploadedFile::fake()->create('bill.pdf', 50, 'application/pdf')])->assertSessionHasNoErrors();

        $r = MedicalRequest::sole();
        $this->assertSame(['pending', 'child', 8000.0, 'bill.pdf'], [$r->status, $r->patient, $r->amount, $r->attachment_name]);
        Storage::disk(config('hrm.uploads.disk'))->assertExists($r->attachment_path);
        $this->assertSame('hidden', ActivityLog::where('subject_type', 'MedicalRequest')->sole()->changes['amount']);

        $hr = $this->user('hr');
        $this->actingAs($this->user('viewer'))->get('/medical')->assertForbidden();
        $this->actingAs($hr)->get('/requests')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('queues', fn ($q) => collect($q)->firstWhere('key', 'medical')['pending'] === 1)->where('nav.inbox', 1));
        $this->actingAs($hr)->get('/medical')->assertInertia(fn (AssertableInertia $p) => $p->where('requests.0.employee.name', 'Fake Me')->where('requests.0.this_year', 0));
        $this->actingAs($hr)->get("/medical/{$r->id}/file")->assertOk();

        $this->actingAs($hr)->post("/medical/{$r->id}/paid", ['paid_on' => '2026-05-20'])->assertSessionHasErrors('status');   // not approved yet
        $this->actingAs($hr)->post("/medical/{$r->id}/approve", ['approved_amount' => 9000])->assertSessionHasErrors('approved_amount');   // more than asked
        $this->actingAs($hr)->post("/medical/{$r->id}/approve", ['approved_amount' => 6000, 'note' => 'Covers 75%'])->assertSessionHasNoErrors();
        $this->assertSame(['approved', 6000.0], [$r->fresh()->status, $r->fresh()->approved_amount]);
        $this->actingAs($this->login)->delete("/me/medical-requests/{$r->id}")->assertSessionHasErrors('status');   // too late to cancel

        $this->actingAs($hr)->post("/medical/{$r->id}/paid", ['paid_on' => '2026-05-21'])->assertSessionHasErrors('paid_on');   // future
        $this->actingAs($hr)->post("/medical/{$r->id}/paid", ['paid_on' => '2026-05-20', 'paid_note' => 'Bank transfer'])->assertSessionHasNoErrors();
        $this->assertSame(['paid', '2026-05-20'], [$r->fresh()->status, $r->fresh()->paid_on->toDateString()]);

        // The person sees it and can open their file; HR sees this year's total on the next request.
        $this->actingAs($this->login)->get('/me')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('medicalRequests.0.status_label', 'Paid')->where('medicalRequests.0.approved_amount', 6000)->where('medicalRequests.0.paid_note', 'Bank transfer'));
        $this->actingAs($this->login)->get("/me/medical-requests/{$r->id}/file")->assertOk();
        MedicalRequest::create(['employee_id' => $this->me->id, 'kind' => 'advance', 'amount' => 50000, 'reason' => 'Surgery']);
        $this->actingAs($hr)->get('/medical')->assertInertia(fn (AssertableInertia $p) => $p->where('requests.0.this_year', 6000));
    }

    public function test_advance_needs_no_file_and_others_cant_see_or_cancel(): void
    {
        $this->ask(['kind' => 'advance', 'patient' => 'self', 'amount' => 50000, 'reason' => 'Surgery next week'])->assertSessionHasNoErrors();
        $r = MedicalRequest::sole();
        $this->assertNull($r->attachment_path);

        $hr = $this->user('hr');
        $this->actingAs($hr)->post("/medical/{$r->id}/reject")->assertSessionHasErrors('note');
        $this->actingAs($hr)->post("/medical/{$r->id}/reject", ['note' => 'Talk to HR first'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $r->fresh()->status);

        $other = MedicalRequest::create(['employee_id' => $this->employee($this->company)->id, 'kind' => 'advance', 'amount' => 1000, 'reason' => 'x']);
        $this->actingAs($this->login)->delete("/me/medical-requests/{$other->id}")->assertNotFound();
        $this->actingAs($this->login)->get("/me/medical-requests/{$other->id}/file")->assertNotFound();
        $this->actingAs($this->login)->post('/me/medical-requests', ['kind' => 'advance', 'patient' => 'self', 'amount' => 1000, 'reason' => 'x'])->assertSessionHasNoErrors();
        $this->actingAs($this->login)->delete('/me/medical-requests/'.MedicalRequest::latest('id')->first()->id)->assertSessionHasNoErrors();
        $this->assertSame('cancelled', MedicalRequest::latest('id')->first()->status);
    }

    public function test_with_uploads_off_files_are_refused(): void
    {
        config(['hrm.uploads.enabled' => false]);
        $data = ['kind' => 'reimbursement', 'patient' => 'self', 'treatment_date' => '2026-05-12', 'amount' => 3000, 'reason' => 'Medicines'];
        $this->ask($data + ['attachment' => UploadedFile::fake()->create('bill.pdf', 50, 'application/pdf')])->assertSessionHasErrors('attachment');
        $this->ask($data)->assertSessionHasNoErrors();   // the bills go to HR on paper
    }
}
