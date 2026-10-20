<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ControlledDocument;
use App\Models\Employee;
use App\Models\IssuedLetter;
use App\Models\LeaveRecord;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Leave requests from My page (signed form attached, HR approves) and the My documents tab. Fake people only. */
class LeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $me;

    private User $login;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-05-20 12:00'));   // Wednesday
        WorkSchedule::create(['name' => 'Ten to six', 'start_time' => '10:00', 'end_time' => '18:00', 'late_grace_minutes' => 10,
            'working_days' => [1, 2, 3, 4, 5], 'is_default' => true]);
        $this->me = $this->employee($this->company, ['full_name' => 'Fake Me', 'start_date' => '2020-01-01']);
        $this->login = $this->user('employee', ['employee_id' => $this->me->id]);
    }

    private function type(string $name): LeaveType
    {
        return LeaveType::where('name', $name)->firstOrFail();
    }

    private function apply(array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->login)->post('/me/leave-requests', $data + [
            'leave_type_id' => $this->type('Casual')->id, 'start_date' => '2026-05-25', 'end_date' => '2026-05-27', 'part' => 'full',
            'reason' => 'Family wedding', 'attachment' => UploadedFile::fake()->create('form.pdf', 200, 'application/pdf'),
        ]);
    }

    public function test_apply_with_the_signed_form_and_hr_approves(): void
    {
        $this->apply()->assertSessionHas('success', "Sent to HR. You'll see their answer here.");
        $r = LeaveRequest::sole();
        $this->assertSame([3.0, 'pending', 'form.pdf'], [$r->days, $r->status, $r->attachment_name]);
        Storage::disk('local')->assertExists($r->attachment_path);

        // The form is required while uploads are on; overlapping days are refused
        $this->apply(['attachment' => null, 'start_date' => '2026-06-01', 'end_date' => '2026-06-01'])->assertSessionHasErrors('attachment');
        $this->apply(['attachment' => UploadedFile::fake()->create('form.exe', 10)])->assertSessionHasErrors('attachment');
        $this->apply(['start_date' => '2026-05-27', 'end_date' => '2026-05-28'])->assertSessionHasErrors('start_date');

        // HR's queue and the sidebar badge
        $hr = $this->user('hr');
        $this->actingAs($hr)->get('/leave/requests')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('leave/requests')->has('requests', 1)->where('nav.leave', 1)
            ->where('requests.0.balance.left', 10)->where('requests.0.employee.name', 'Fake Me'));
        $this->actingAs($hr)->get("/leave/requests/{$r->id}/file")->assertOk();
        $this->actingAs($this->user('viewer'))->get('/leave/requests')->assertForbidden();

        $this->actingAs($hr)->post("/leave/requests/{$r->id}/approve", ['note' => 'Form checked'])
            ->assertSessionHas('success', "Approved Fake Me's Casual leave. Casual: 7 of 10 days left in 2026. Fake Me was emailed.");
        $record = LeaveRecord::sole();
        $this->assertSame(['request', 3.0, $record->id, 'approved'], [$record->source, $record->days, $r->fresh()->leave_record_id, $r->fresh()->status]);
        $this->actingAs($hr)->post("/leave/requests/{$r->id}/reject", ['note' => 'x'])->assertSessionHasErrors('status');

        // Their own page shows the answer and the new balance
        $this->actingAs($this->login)->get('/me')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('leaveRequests.0.status', 'approved')->where('leaveRequests.0.decision_note', 'Form checked')
            ->where('leave.balances.1.left', 7));
    }

    public function test_reject_needs_a_reason_and_cancel_only_while_pending(): void
    {
        $this->apply();
        $this->apply(['start_date' => '2026-06-08', 'end_date' => '2026-06-08']);
        [$a, $b] = LeaveRequest::orderBy('id')->get()->all();
        $hr = $this->user('hr');
        $this->actingAs($hr)->post("/leave/requests/{$a->id}/reject", [])->assertSessionHasErrors('note');
        $this->actingAs($hr)->post("/leave/requests/{$a->id}/reject", ['note' => 'No manager signature'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $a->fresh()->status);
        $this->actingAs($this->login)->delete("/me/leave-requests/{$a->id}")->assertSessionHasErrors('status');
        $this->actingAs($this->login)->delete("/me/leave-requests/{$b->id}")->assertSessionHas('success', 'Request cancelled.');
        $this->assertSame('cancelled', $b->fresh()->status);
        $this->assertSame(0, LeaveRecord::count());
    }

    public function test_over_the_balance_is_allowed_with_a_warning(): void
    {
        // Sick: 6 a year. 25 May - 3 Jun = 8 working days
        $this->apply(['leave_type_id' => $this->type('Sick')->id, 'start_date' => '2026-05-25', 'end_date' => '2026-06-03'])
            ->assertSessionHas('success', fn ($m) => str_ends_with($m, 'This goes 2 days over your Sick balance for 2026; HR decides.'));
        $this->apply(['leave_type_id' => $this->type('Wedding')->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-20'])
            ->assertSessionHas('success', fn ($m) => str_ends_with($m, 'It is 6 days longer than the 14-day Wedding allowance; HR decides.'));
        $this->assertSame(2, LeaveRequest::count());
    }

    public function test_with_uploads_off_the_form_goes_to_hr_on_paper(): void
    {
        config(['hrm.uploads.enabled' => false]);
        $this->apply()->assertSessionHasErrors('attachment');
        $this->apply(['attachment' => null])->assertSessionHasNoErrors();
        $this->assertNull(LeaveRequest::sole()->attachment_path);
    }

    public function test_people_only_see_their_own_requests_and_letters(): void
    {
        $this->apply();
        $r = LeaveRequest::sole();
        $other = $this->employee($this->company, ['full_name' => 'Fake Other']);
        $otherLogin = $this->user('employee', ['employee_id' => $other->id]);
        $this->actingAs($this->login)->get("/me/leave-requests/{$r->id}/file")->assertOk();
        $this->actingAs($otherLogin)->get("/me/leave-requests/{$r->id}/file")->assertNotFound();
        $this->actingAs($otherLogin)->delete("/me/leave-requests/{$r->id}")->assertNotFound();
        $this->actingAs($otherLogin)->get('/leave/requests')->assertForbidden();

        $letter = IssuedLetter::create(['employee_id' => $this->me->id, 'reference' => 'BCC/HR/2026/001', 'subject' => 'Experience letter',
            'body' => 'Fake body', 'issued_on' => '2026-05-19', 'issued_by' => $this->user('hr')->id]);
        ControlledDocument::create(['doc_number' => 'BCC-0101', 'doc_type' => 'Policy', 'name' => 'Fake Leave Policy', 'status' => 'Active', 'link_url' => 'https://example.test/p']);
        ControlledDocument::create(['doc_number' => 'BCC-0102', 'doc_type' => 'Policy', 'name' => 'Fake Draft Policy', 'status' => 'Draft']);

        $this->actingAs($this->login)->get('/me')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('letters.0.reference', 'BCC/HR/2026/001')
            ->where('policies', fn ($d) => collect($d)->pluck('name')->all() === ['Fake Leave Policy']));
        $this->actingAs($this->login)->get("/me/letters/{$letter->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->component('print/letter')->where('back.href', '/me#documents'));
        $this->actingAs($otherLogin)->get("/me/letters/{$letter->id}")->assertNotFound();
        $this->actingAs($otherLogin)->get('/me')->assertInertia(fn (AssertableInertia $p) => $p->where('letters', []));
    }
}
