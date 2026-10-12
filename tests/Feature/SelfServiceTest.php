<?php

namespace Tests\Feature;

use App\Models\AttendanceAdjustment;
use App\Models\AttendancePunch;
use App\Models\AttendanceRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** My page (employee logins) and HR's request queue. Fake people only; today is Wed 20 May 2026. */
class SelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $me;

    private Employee $colleague;

    private User $login;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-05-20 12:00'));
        WorkSchedule::create(['name' => '9 to 5', 'type' => 'fixed', 'start_time' => '09:00', 'end_time' => '17:00', 'late_grace_minutes' => 10,
            'full_day_minutes' => 480, 'half_day_minutes' => 240, 'working_days' => [1, 2, 3, 4, 5], 'is_default' => true]);
        $this->me = $this->employee($this->company, ['full_name' => 'Fake Me', 'start_date' => '2025-01-01', 'attendance_machine_id' => '7',
            'cnic' => '35202-1111111-1', 'bank_account_number' => 'PK00 1234 5678 9012']);
        $this->colleague = $this->employee($this->company, ['full_name' => 'Fake Colleague', 'start_date' => '2025-01-01', 'cnic' => '35202-2222222-2']);
        $this->login = $this->user('employee', ['employee_id' => $this->me->id, 'email' => 'me@test.local', 'password' => 'secret-pass']);
        AttendancePunch::create(['source' => 'machine', 'device_user_id' => '7', 'punched_at' => '2026-05-18 09:02:00', 'work_date' => '2026-05-18']);
    }

    private function ask(array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->login)->post('/me/requests', $data + ['reason' => 'Forgot to punch out']);
    }

    public function test_employee_login_lands_on_my_page_and_cannot_open_hr_pages(): void
    {
        $this->post('/login', ['email' => 'me@test.local', 'password' => 'secret-pass'])->assertRedirect('/me');
        $this->get('/')->assertRedirect('/me');
        foreach (['/dashboard', '/employees', "/employees/{$this->colleague->id}", '/attendance', '/reports/headcount', '/export/employees'] as $url) {
            $this->get($url)->assertForbidden();
        }
        // Even when the login was heading for an HR page before signing in.
        $this->post('/logout');
        $this->get('/employees')->assertRedirect('/login');
        $this->post('/login', ['email' => 'me@test.local', 'password' => 'secret-pass'])->assertRedirect('/me');
    }

    public function test_my_page_shows_only_my_own_record(): void
    {
        $this->actingAs($this->login)->get('/me?month=2026-05')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('me/show')
            ->where('employee.full_name', 'Fake Me')
            ->where('employee.cnic', '35202-1111111-1')
            ->where('employee.bank_account_last4', '9012')
            ->missing('employee.bank_account_number')
            ->where('attendance.days', fn ($days) => collect($days)->firstWhere('date', '2026-05-18')['status'] === 'Missing punch')
            ->etc());
        // A ?employee= or similar never switches the record.
        $this->actingAs($this->login)->get("/me?employee={$this->colleague->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('employee.full_name', 'Fake Me')->etc());
    }

    public function test_a_login_without_an_employee_gets_a_message_not_someone_elses_data(): void
    {
        $unlinked = $this->user('employee');
        $this->actingAs($unlinked)->get('/me')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('employee', null));
        $this->ask(['date' => '2026-05-18', 'check_out' => '17:00'], $unlinked)->assertForbidden();
        $this->actingAs($unlinked)->put('/me/contact', ['phone' => '1'])->assertForbidden();
    }

    public function test_contact_update_changes_only_the_allowed_fields(): void
    {
        $this->actingAs($this->login)->put('/me/contact', [
            'phone' => '0300 0000000', 'emergency_contact_name' => 'Fake Parent',
            'cnic' => '00000-0000000-0', 'full_name' => 'Hacked', 'role_title' => 'CEO', 'status' => 'exited',
        ])->assertRedirect();
        $me = $this->me->fresh();
        $this->assertSame(['0300 0000000', 'Fake Parent', '35202-1111111-1', 'Fake Me', 'active'],
            [$me->phone, $me->emergency_contact_name, $me->cnic, $me->full_name, $me->status]);
        $this->assertDatabaseHas('activity_log', ['employee_id' => $me->id, 'user_id' => $this->login->id, 'event' => 'updated']);
    }

    public function test_requests_are_checked(): void
    {
        $this->ask(['date' => '2026-05-21', 'check_in' => '09:00'])->assertSessionHasErrors('date');          // future
        $this->ask(['date' => '2026-01-01', 'check_in' => '09:00'])->assertSessionHasErrors('date');          // older than 60 days
        $this->ask(['date' => '2026-05-18'])->assertSessionHasErrors('check_in');                             // no times
        $this->ask(['date' => '2026-05-18', 'check_in' => '9am'])->assertSessionHasErrors('check_in');
        $this->ask(['date' => '2026-05-18', 'check_out' => '17:00'])->assertSessionHasNoErrors();
        $this->ask(['date' => '2026-05-18', 'check_out' => '17:30'])->assertSessionHasErrors('date');         // one waiting already

        $r = AttendanceRequest::sole();
        $this->assertSame([$this->me->id, $this->login->id, 'pending', null, '17:00'], [$r->employee_id, $r->requested_by, $r->status, $r->check_in, $r->check_out]);
    }

    public function test_nobody_can_cancel_or_decide_someone_elses_request(): void
    {
        $theirs = AttendanceRequest::create(['employee_id' => $this->colleague->id, 'date' => '2026-05-18', 'check_in' => '09:00', 'reason' => 'x']);
        $this->actingAs($this->login)->delete("/me/requests/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->login)->post("/attendance/requests/{$theirs->id}/approve")->assertForbidden();
        $this->actingAs($this->user('viewer'))->post("/attendance/requests/{$theirs->id}/approve")->assertForbidden();
        $this->assertSame('pending', $theirs->fresh()->status);
    }

    public function test_hr_approves_into_an_adjustment_and_attendance_counts_it(): void
    {
        $this->ask(['date' => '2026-05-18', 'check_out' => '17:05']);
        $r = AttendanceRequest::sole();
        $hr = $this->user('hr');
        $this->actingAs($hr)->get('/attendance/requests')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('attendance/requests')->has('requests', 1)->where('requests.0.now.status', 'Missing punch')->where('requests.0.now.punches', ['09:02']));

        $this->actingAs($hr)->post("/attendance/requests/{$r->id}/approve", ['note' => 'Manager confirmed'])->assertSessionHasNoErrors();
        $adj = AttendanceAdjustment::sole();
        $this->assertSame([null, '17:05', 'Approved request: Forgot to punch out (Manager confirmed)'], [$adj->check_in, $adj->check_out, $adj->note]);
        $this->assertSame(['approved', $hr->id], [$r->fresh()->status, $r->fresh()->decided_by]);

        $this->actingAs($this->login)->get('/me?month=2026-05')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('attendance.days', fn ($days) => collect($days)->firstWhere('date', '2026-05-18')['status'] === 'Present')
            ->where('requests.0.status_label', 'Approved')->etc());

        $this->actingAs($hr)->post("/attendance/requests/{$r->id}/approve")->assertSessionHasErrors('request');
    }

    public function test_rejecting_needs_a_reason_the_person_sees(): void
    {
        $this->ask(['date' => '2026-05-18', 'check_out' => '17:05']);
        $r = AttendanceRequest::sole();
        $this->actingAs($this->user('hr'))->post("/attendance/requests/{$r->id}/reject")->assertSessionHasErrors('note');
        $this->actingAs($this->user('hr'))->post("/attendance/requests/{$r->id}/reject", ['note' => 'Machine shows 16:10'])->assertSessionHasNoErrors();
        $this->assertSame(0, AttendanceAdjustment::count());
        $this->actingAs($this->login)->get('/me')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('requests.0.status', 'rejected')->where('requests.0.decision_note', 'Machine shows 16:10')->etc());
        $this->actingAs($this->login)->delete("/me/requests/{$r->id}")->assertSessionHasErrors('request');
    }

    public function test_hr_staff_linked_to_their_record_get_my_page_too(): void
    {
        $hr = $this->user('hr', ['employee_id' => $this->colleague->id]);
        $this->actingAs($hr)->get('/me')->assertInertia(fn (AssertableInertia $p) => $p->where('employee.full_name', 'Fake Colleague')
            ->where('auth.can.self', true)->where('auth.can.view', true)->etc());
        $this->actingAs($hr)->get('/dashboard')->assertOk();
    }

    public function test_admin_links_logins_to_one_employee_each(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->post('/settings/users', ['name' => 'X', 'email' => 'x@test.local', 'role' => 'employee', 'password' => 'secret-pass'])
            ->assertSessionHasErrors(['employee_id' => 'Pick the employee this login belongs to.']);
        $this->actingAs($admin)->post('/settings/users', ['name' => 'X', 'email' => 'x@test.local', 'role' => 'employee', 'password' => 'secret-pass', 'employee_id' => $this->me->id])
            ->assertSessionHasErrors(['employee_id' => 'That employee already has a login.']);
        $this->actingAs($admin)->post('/settings/users', ['name' => 'X', 'email' => 'x@test.local', 'role' => 'employee', 'password' => 'secret-pass', 'employee_id' => $this->colleague->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->colleague->id, User::where('email', 'x@test.local')->value('employee_id'));
    }
}
