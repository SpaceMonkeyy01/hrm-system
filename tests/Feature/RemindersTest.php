<?php

namespace Tests\Feature;

use App\Mail\RemindersDigest;
use App\Models\ChecklistEntry;
use App\Models\ChecklistItem;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Offboarding;
use App\Models\Onboarding;
use App\Services\Reminders;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Reminders (dashboard "Needs attention" + daily email), with a fixed today. Fake data only. */
class RemindersTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $today;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = CarbonImmutable::parse('2026-10-08');
        [$this->company] = $this->baseData();
    }

    private function day(int $offset): string
    {
        return $this->today->addDays($offset)->toDateString();
    }

    private function set(Employee $e, string $list, string $key, ?string $status): void
    {
        $item = ChecklistItem::where('list', $list)->where('key', $key)->firstOrFail();
        ChecklistEntry::updateOrCreate(['employee_id' => $e->id, 'checklist_item_id' => $item->id], ['status' => $status]);
    }

    private function allDocumentsDone(Employee $e): void
    {
        foreach (ChecklistItem::where('list', 'document')->pluck('key') as $key) {
            $this->set($e, 'document', $key, 'Yes');
        }
    }

    /** Person on probation; only the given rule should be able to fire. */
    private function person(array $attrs = [], ?array $onboarding = null): Employee
    {
        $e = $this->employee($this->company, $attrs);
        if ($onboarding !== null) {
            Onboarding::create($onboarding + ['employee_id' => $e->id, 'probation_result' => 'Pending']);
        }

        return $e;
    }

    /** @return list<array<string, mixed>> reminders of one type */
    private function of(string $type): array
    {
        return array_values(array_filter(Reminders::forDate($this->today), fn ($r) => $r['type'] === $type));
    }

    // ------------------------------------------------------------------ probation

    public function test_probation_ending_boundaries(): void
    {
        $today = $this->person(['full_name' => 'Ends Today'], ['probation_end_date' => $this->day(0)]);
        $edge = $this->person(['full_name' => 'Ends Plus Seven'], ['probation_end_date' => $this->day(7), 'probation_result' => 'Extend']);
        $this->person(['full_name' => 'Ends Plus Eight'], ['probation_end_date' => $this->day(8)]);
        $past = $this->person(['full_name' => 'Ended Yesterday'], ['probation_end_date' => $this->day(-1)]);

        $ending = $this->of('probation_ending');
        $this->assertEqualsCanonicalizing([$today->id, $edge->id], array_column($ending, 'employee_id'));
        $r = collect($ending)->firstWhere('employee_id', $edge->id);
        $this->assertSame('due_soon', $r['severity']);
        $this->assertSame(7, $r['days']);
        $this->assertSame($this->day(7), $r['due_date']);
        $this->assertSame('Probation ends in 7 days', $r['message']);
        $this->assertSame("/employees/{$edge->id}/plan", $r['link']);
        $this->assertSame('Probation ends today', collect($ending)->firstWhere('employee_id', $today->id)['message']);

        $overdue = $this->of('probation_overdue');
        $this->assertSame([$past->id], array_column($overdue, 'employee_id'));
        $this->assertSame('overdue', $overdue[0]['severity']);
        $this->assertSame(-1, $overdue[0]['days']);
        $this->assertSame('Probation ended yesterday and still needs a decision', $overdue[0]['message']);
    }

    public function test_pass_and_fail_probations_give_no_reminders(): void
    {
        foreach (['Pass', 'Fail'] as $result) {
            // Start 29 days ago: 30-day check-in due tomorrow; probation ended yesterday.
            $e = $this->person(['start_date' => $this->day(-29)], ['probation_end_date' => $this->day(-1), 'probation_result' => $result]);
            $this->allDocumentsDone($e);
        }

        $this->assertSame([], Reminders::forDate($this->today));
    }

    // ------------------------------------------------------------------ check-ins

    public function test_checkin_due_boundaries(): void
    {
        $far = ['probation_end_date' => $this->day(60)];
        $dueToday = $this->person(['full_name' => 'Due Today', 'start_date' => $this->day(-30)], $far);
        $edge = $this->person(['full_name' => 'Due In Three', 'start_date' => $this->day(-27)], $far);
        $this->person(['full_name' => 'Due In Four', 'start_date' => $this->day(-26)], $far);
        $missed = $this->person(['full_name' => 'Was Due Yesterday', 'start_date' => $this->day(-31)], $far);
        $done = $this->person(['full_name' => 'Done Already', 'start_date' => $this->day(-30)], $far);
        $this->set($done, 'onboarding', 'day30_checkin', 'Yes');
        $this->person(['full_name' => 'No Start Date'], $far);
        $this->employee($this->company, ['full_name' => 'No Onboarding Row', 'start_date' => $this->day(-30)]);

        $due = $this->of('checkin_due');
        $this->assertEqualsCanonicalizing([$dueToday->id, $edge->id], array_column($due, 'employee_id'));
        $r = collect($due)->firstWhere('employee_id', $edge->id);
        $this->assertSame('30-day check-in is due in 3 days', $r['message']);
        $this->assertSame('due_soon', $r['severity']);
        $this->assertSame($this->day(3), $r['due_date']);
        $this->assertSame("/employees/{$edge->id}/plan", $r['link']);
        $this->assertSame('30-day check-in is due today', collect($due)->firstWhere('employee_id', $dueToday->id)['message']);

        $missedRows = $this->of('checkin_missed');
        $this->assertSame([$missed->id], array_column($missedRows, 'employee_id'));
        $this->assertSame('overdue', $missedRows[0]['severity']);
        $this->assertSame('30-day check-in was due yesterday', $missedRows[0]['message']);
    }

    public function test_60_and_90_day_checkins_use_their_own_offsets(): void
    {
        $e = $this->person(['start_date' => $this->day(-88)], ['probation_end_date' => $this->day(30), 'probation_result' => 'Extend']);
        $this->set($e, 'onboarding', 'day30_checkin', 'Yes');

        $this->assertSame(['60-day check-in was due 28 days ago'], array_column($this->of('checkin_missed'), 'message'));
        $this->assertSame(['90-day evaluation is due in 2 days'], array_column($this->of('checkin_due'), 'message'));
    }

    // ------------------------------------------------------------------ documents

    public function test_documents_pending_boundaries(): void
    {
        $edge = $this->employee($this->company, ['full_name' => 'Started 14 Days Ago', 'start_date' => $this->day(-14)]);
        $this->employee($this->company, ['full_name' => 'Started 13 Days Ago', 'start_date' => $this->day(-13)]);
        $late = $this->employee($this->company, ['full_name' => 'Started 15 Days Ago', 'start_date' => $this->day(-15)]);
        $this->set($late, 'document', 'cnic_copy', 'Yes');
        $this->set($late, 'document', 'photo', 'Not Required');
        $complete = $this->employee($this->company, ['start_date' => $this->day(-100)]);
        $this->allDocumentsDone($complete);
        $this->employee($this->company, ['full_name' => 'No Start Date']);

        $rows = collect($this->of('documents_pending'))->keyBy('employee_id');
        $this->assertEqualsCanonicalizing([$edge->id, $late->id], $rows->keys()->all());
        $total = ChecklistItem::where('list', 'document')->where('is_active', true)->count();

        $this->assertSame('due_soon', $rows[$edge->id]['severity']);
        $this->assertSame(0, $rows[$edge->id]['days']);
        $this->assertSame("$total documents still missing, started 14 days ago", $rows[$edge->id]['message']);
        $this->assertSame("/employees/{$edge->id}", $rows[$edge->id]['link']);

        $this->assertSame('overdue', $rows[$late->id]['severity']);
        $this->assertSame(-1, $rows[$late->id]['days']);
        $this->assertSame(($total - 2).' documents still missing, started 15 days ago', $rows[$late->id]['message']);
    }

    public function test_only_recent_starters_get_per_person_reminders(): void
    {
        $edge = $this->person(['full_name' => 'Started 180 Days Ago', 'start_date' => $this->day(-180)], ['probation_end_date' => $this->day(-90)]);
        $old = $this->person(['full_name' => 'Started 181 Days Ago', 'start_date' => $this->day(-181)], ['probation_end_date' => $this->day(-91)]);
        $this->allDocumentsDone($edge);

        $ids = array_unique(array_column(Reminders::forDate($this->today), 'employee_id'));
        $this->assertContains($edge->id, $ids);
        $this->assertNotContains($old->id, $ids);
        $this->assertSame(1, Reminders::documentsBacklog($this->today));
    }

    public function test_backlog_line_goes_in_the_email_on_the_backlog_day_only(): void
    {
        Mail::fake();
        $this->user('hr', ['email' => 'hr@example.test']);
        $this->employee($this->company, ['full_name' => 'Long Serving', 'start_date' => $this->day(-1000)]);
        $done = $this->employee($this->company, ['start_date' => $this->day(-1000)]);
        $this->allDocumentsDone($done);

        $this->travelTo($this->today->setTime(8, 0)); // Thursday
        $this->artisan('hr:send-reminders')->expectsOutputToContain('Nothing needs attention')->assertSuccessful();
        Mail::assertNothingSent();

        $monday = CarbonImmutable::parse('2026-10-12');
        $this->travelTo($monday->setTime(8, 0));
        $this->artisan('hr:send-reminders')->assertSuccessful();
        Mail::assertSent(RemindersDigest::class, fn (RemindersDigest $m) => $m->backlog === 1 && $m->reminders === []
            && $m->hasSubject('HR reminders for 12 Oct 2026: older missing documents'));

        $html = (new RemindersDigest($monday, [], 1))->render();
        $this->assertStringContainsString('1 person who started more than 180 days ago still has missing documents', $html);
        $this->assertStringContainsString(url('/documents?status=Pending'), $html);
    }

    // ------------------------------------------------------------------ offboarding

    private function offboarding(string $lastDay, array $attrs = [], string $employeeStatus = 'offboarding'): Offboarding
    {
        $e = $this->employee($this->company, ['status' => $employeeStatus]);
        $this->allDocumentsDone($e);

        return Offboarding::create($attrs + ['employee_id' => $e->id, 'type' => 'Resignation', 'last_working_day' => $lastDay]);
    }

    public function test_offboarding_due_boundaries(): void
    {
        $today = $this->offboarding($this->day(0));
        $edge = $this->offboarding($this->day(3));
        $this->offboarding($this->day(4));
        $past = $this->offboarding($this->day(-1));
        $cleared = $this->offboarding($this->day(1));
        foreach (ChecklistItem::where('list', 'offboarding')->pluck('key') as $key) {
            $this->set($cleared->employee()->first(), 'offboarding', $key, 'Done');
        }
        $this->offboarding($this->day(1), ['status' => 'cancelled'], 'active');
        $this->offboarding($this->day(1), [], 'active');   // case open but employee not in offboarding status

        $rows = collect($this->of('offboarding_due'))->keyBy('employee_id');
        $this->assertEqualsCanonicalizing([$today->employee_id, $edge->employee_id, $past->employee_id], $rows->keys()->all());
        $items = ChecklistItem::where('list', 'offboarding')->where('is_active', true)->count();

        $this->assertSame('due_soon', $rows[$edge->employee_id]['severity']);
        $this->assertSame("Last working day is in 3 days, $items clearance items still open", $rows[$edge->employee_id]['message']);
        $this->assertSame("/offboarding/{$edge->id}", $rows[$edge->employee_id]['link']);
        $this->assertSame('overdue', $rows[$past->employee_id]['severity']);
        $this->assertSame("Last working day was yesterday, $items clearance items still open", $rows[$past->employee_id]['message']);
        $this->assertSame(0, $rows[$today->employee_id]['days']);
    }

    // ------------------------------------------------------------------ who is included

    public function test_exited_and_deleted_employees_give_no_reminders(): void
    {
        $onb = ['probation_end_date' => $this->day(-5)];
        $this->person(['status' => 'exited', 'start_date' => $this->day(-31)], $onb);
        $deleted = $this->person(['start_date' => $this->day(-31)], $onb);
        $deleted->delete();

        $gone = $this->offboarding($this->day(-1));
        Employee::find($gone->employee_id)->delete();

        $this->assertSame([], Reminders::forDate($this->today));
    }

    public function test_sorted_overdue_first_then_by_due_date(): void
    {
        $this->person(['full_name' => 'A Soon'], ['probation_end_date' => $this->day(2)]);
        $this->person(['full_name' => 'B Late'], ['probation_end_date' => $this->day(-1)]);
        $this->person(['full_name' => 'C Later'], ['probation_end_date' => $this->day(-9)]);
        $this->person(['full_name' => 'D Sooner'], ['probation_end_date' => $this->day(1)]);

        $this->assertSame(['C Later', 'B Late', 'D Sooner', 'A Soon'], array_column(Reminders::forDate($this->today), 'employee_name'));
    }

    // ------------------------------------------------------------------ dashboard

    public function test_dashboard_sends_the_reminders_prop(): void
    {
        $this->travelTo($this->today->setTime(9, 0));
        $e = $this->person(['employee_code' => 'BCC-900', 'full_name' => 'Fake Person'], ['probation_end_date' => $this->day(2)]);

        $this->actingAs($this->user('viewer'))->get('/dashboard')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('dashboard')
                ->has('reminders', 1)
                ->where('reminders.0', [
                    'type' => 'probation_ending', 'severity' => 'due_soon', 'employee_id' => $e->id,
                    'employee_code' => 'BCC-900', 'employee_name' => 'Fake Person', 'due_date' => '2026-10-10',
                    'days' => 2, 'message' => 'Probation ends in 2 days', 'link' => "/employees/{$e->id}/plan",
                ]));
    }

    public function test_dashboard_sends_an_empty_list_when_nothing_is_due(): void
    {
        $this->actingAs($this->user('hr'))->get('/dashboard')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('reminders', 0));
    }

    // ------------------------------------------------------------------ email

    public function test_command_sends_one_digest_per_active_hr_and_admin_user(): void
    {
        $this->travelTo($this->today->setTime(8, 0));
        Mail::fake();
        $this->person(['full_name' => 'Fake Person'], ['probation_end_date' => $this->day(-2)]);
        $this->person(['full_name' => 'Other Person'], ['probation_end_date' => $this->day(3)]);
        $admin = $this->user('admin', ['email' => 'admin@example.test']);
        $hr = $this->user('hr', ['email' => 'hr@example.test']);
        $viewer = $this->user('viewer', ['email' => 'viewer@example.test']);
        $inactive = $this->user('hr', ['email' => 'off@example.test', 'is_active' => false]);

        $this->artisan('hr:send-reminders')->assertSuccessful();

        Mail::assertSent(RemindersDigest::class, 2);
        foreach ([$admin, $hr] as $u) {
            Mail::assertSent(RemindersDigest::class, fn (RemindersDigest $m) => $m->hasTo($u->email)
                && $m->hasSubject('HR reminders for 8 Oct 2026: 2 items') && count($m->reminders) === 2);
        }
        foreach ([$viewer, $inactive] as $u) {
            Mail::assertNotSent(RemindersDigest::class, fn (RemindersDigest $m) => $m->hasTo($u->email));
        }
    }

    public function test_digest_groups_by_type_and_links_to_the_app(): void
    {
        $e = $this->person(['full_name' => 'Fake Person'], ['probation_end_date' => $this->day(-2)]);
        $html = (new RemindersDigest($this->today, Reminders::forDate($this->today)))->render();

        $this->assertStringContainsString('Probation past its end date (1)', $html);
        $this->assertStringContainsString(url("/employees/{$e->id}/plan"), $html);
        $this->assertStringContainsString('Probation ended 2 days ago and still needs a decision', $html);
    }

    public function test_command_sends_nothing_when_there_are_no_reminders(): void
    {
        Mail::fake();
        $this->user('admin');
        $this->user('hr');

        $this->artisan('hr:send-reminders')->expectsOutputToContain('Nothing needs attention')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_dry_run_prints_and_sends_nothing(): void
    {
        $this->travelTo($this->today->setTime(8, 0));
        Mail::fake();
        $this->user('hr', ['email' => 'hr@example.test']);
        $this->person(['full_name' => 'Fake Person'], ['probation_end_date' => $this->day(1)]);

        $this->artisan('hr:send-reminders', ['--dry-run' => true])
            ->expectsOutputToContain('Probation ends tomorrow')
            ->expectsOutputToContain('== For both companies (hr@example.test)')
            ->expectsOutputToContain('Dry run: no email sent.')
            ->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_each_login_gets_only_its_home_companys_people(): void
    {
        $this->travelTo($this->today->setTime(8, 0));
        Mail::fake();
        $emerge = \App\Models\Company::create(['name' => 'Emerge', 'code' => 'EMG', 'employee_code_prefix' => 'EMG-', 'employee_code_digits' => 3]);
        $this->person(['full_name' => 'Fake Bcc Person'], ['probation_end_date' => $this->day(1)]);
        $emg = $this->person(['full_name' => 'Fake Emerge Person'], ['probation_end_date' => $this->day(1)]);
        $emg->update(['company_id' => $emerge->id]);
        $this->user('hr', ['email' => 'hr.bcc@example.test', 'company_id' => $this->company->id]);
        $this->user('hr', ['email' => 'hr.emerge@example.test', 'company_id' => $emerge->id]);
        $this->user('admin', ['email' => 'boss@example.test']);   // no home company: both

        $this->artisan('hr:send-reminders')->assertSuccessful();
        $names = fn (RemindersDigest $m) => array_column($m->reminders, 'employee_name');
        Mail::assertSent(RemindersDigest::class, fn ($m) => $m->hasTo('hr.bcc@example.test') && $names($m) === ['Fake Bcc Person']);
        Mail::assertSent(RemindersDigest::class, fn ($m) => $m->hasTo('hr.emerge@example.test') && $names($m) === ['Fake Emerge Person']);
        Mail::assertSent(RemindersDigest::class, fn ($m) => $m->hasTo('boss@example.test') && count($names($m)) === 2);

        // Nothing for one company: that login gets no email at all
        Mail::fake();
        Onboarding::where('employee_id', $emg->id)->sole()->update(['probation_end_date' => $this->day(40)]);
        $this->artisan('hr:send-reminders')->assertSuccessful();
        Mail::assertNotSent(RemindersDigest::class, fn ($m) => $m->hasTo('hr.emerge@example.test'));
        Mail::assertSent(RemindersDigest::class, fn ($m) => $m->hasTo('hr.bcc@example.test'));
    }

    public function test_command_is_scheduled_daily_at_the_configured_time(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'hr:send-reminders'));

        $this->assertCount(1, $events);
        $this->assertSame('0 8 * * *', $events->first()->expression);
        $this->assertSame(config('app.timezone'), $events->first()->timezone);
    }
}
