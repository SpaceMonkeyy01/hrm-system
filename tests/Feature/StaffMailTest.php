<?php

namespace Tests\Feature;

use App\Mail\RemindersDigest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\LetterTemplate;
use App\Models\Loan;
use App\Models\User;
use App\Models\WfhRequest;
use App\Notifications\StaffNotice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Emails to staff (StaffMail): letters, things to acknowledge, HR's answers; and waiting requests in HR's daily email. */
class StaffMailTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $me;

    private User $login;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-05-20 09:00'));
        config(['mail.from.address' => 'hr@example.com']);
        $this->company->update(['hr_email' => 'hr.bcc@example.com']);
        $this->me = $this->employee($this->company, ['full_name' => 'Fake Me']);
        $this->login = $this->user('employee', ['employee_id' => $this->me->id]);
        $this->hr = $this->user('hr');
        Notification::fake();
    }

    /** The one notice sent to the person's login, as an email. */
    private function sent(): array
    {
        $mails = [];
        Notification::assertSentTo($this->login, StaffNotice::class, function (StaffNotice $n) use (&$mails) {
            $mails[] = $n->toMail($this->login);

            return true;
        });

        return $mails;
    }

    public function test_issuing_a_letter_emails_the_person_from_their_company_without_the_letter(): void
    {
        $template = LetterTemplate::first();
        $this->actingAs($this->hr)->post("/employees/{$this->me->id}/letters", ['template_id' => $template->id, 'subject' => 'Experience letter', 'body' => 'SECRET BODY'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Fake Me was emailed.'));
        [$mail] = $this->sent();
        $this->assertSame(['hr.bcc@example.com', 'BCC HR'], $mail->from);
        $this->assertSame('HR has issued you a letter', $mail->subject);
        $this->assertStringNotContainsString('SECRET BODY', implode(' ', $mail->introLines));
        $this->assertStringEndsWith('/me#documents', $mail->actionUrl);

        // HR can choose not to.
        Notification::fake();
        $this->actingAs($this->hr)->post("/employees/{$this->me->id}/letters", ['template_id' => $template->id, 'body' => 'x', 'notify' => false]);
        Notification::assertNothingSent();
    }

    public function test_no_login_no_email(): void
    {
        $other = $this->employee($this->company);
        $this->actingAs($this->hr)->post("/employees/{$other->id}/conduct", ['type' => 'appreciation', 'happened_on' => '2026-05-19', 'title' => 'Great work'])
            ->assertSessionHas('success', fn ($m) => ! str_contains($m, 'emailed'));
        Notification::assertNothingSent();
    }

    public function test_feedback_emails_say_something_is_waiting_but_not_what(): void
    {
        $this->actingAs($this->hr)->post("/employees/{$this->me->id}/conduct", ['type' => 'final_warning', 'happened_on' => '2026-05-19', 'title' => 'Repeated absence']);
        [$mail] = $this->sent();
        $this->assertStringNotContainsString('Repeated absence', implode(' ', $mail->introLines));
        $this->assertStringNotContainsString('warning', strtolower($mail->subject.implode(' ', $mail->introLines)));

        // Private meeting notes: nothing. Shared later: an email.
        Notification::fake();
        $this->actingAs($this->hr)->post("/employees/{$this->me->id}/meetings", ['type' => 'one_to_one', 'held_on' => '2026-05-19', 'notes' => 'x']);
        Notification::assertNothingSent();
        $meeting = \App\Models\Meeting::sole();
        $this->actingAs($this->hr)->put("/meetings/{$meeting->id}", ['type' => 'one_to_one', 'held_on' => '2026-05-19', 'notes' => 'x', 'shared' => true]);
        $this->assertSame('Meeting notes shared with you', $this->sent()[0]->subject);
    }

    public function test_answers_to_requests_are_emailed(): void
    {
        $wfh = WfhRequest::create(['employee_id' => $this->me->id, 'start_date' => '2026-05-22', 'end_date' => '2026-05-22', 'reason' => 'x']);
        $this->actingAs($this->hr)->post("/attendance/wfh/{$wfh->id}/reject", ['note' => 'Client visit that day']);
        [$mail] = $this->sent();
        $this->assertSame('Work from home not approved', $mail->subject);
        $this->assertContains("HR's note: Client visit that day", $mail->introLines);

        Notification::fake();
        $loan = Loan::create(['employee_id' => $this->me->id, 'amount' => 12000, 'months' => 3, 'reason' => 'x']);
        $this->actingAs($this->hr)->post("/loans/{$loan->id}/approve", ['first_month' => '2026-06', 'months' => 3]);
        $this->assertStringContainsString('Rs 4,000 will be deducted from your salary each month for 3 months, starting June 2026', $this->sent()[0]->introLines[0]);

        Notification::fake();
        $type = LeaveType::first();
        $leave = \App\Models\LeaveRequest::create(['employee_id' => $this->me->id, 'leave_type_id' => $type->id, 'start_date' => '2026-05-25', 'end_date' => '2026-05-25', 'reason' => 'x']);
        $this->actingAs($this->hr)->post("/leave/requests/{$leave->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('Your leave was approved', $this->sent()[0]->subject);
    }

    public function test_waiting_requests_go_in_hrs_daily_email(): void
    {
        Mail::fake();
        $this->artisan('hr:send-reminders')->expectsOutputToContain('Nothing needs attention');
        WfhRequest::create(['employee_id' => $this->me->id, 'start_date' => '2026-05-22', 'end_date' => '2026-05-22', 'reason' => 'x']);
        $this->artisan('hr:send-reminders')->assertSuccessful();
        Mail::assertSent(RemindersDigest::class, fn (RemindersDigest $m) => $m->hasTo($this->hr->email)
            && $m->waitingRequests[0]['title'] === 'Work from home' && $m->waitingRequests[0]['pending'] === 1
            && str_contains($m->envelope()->subject, '1 request waiting')
            && str_contains($m->render(), 'Requests waiting for HR'));
    }
}
