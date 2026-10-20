<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\WfhRequest;
use App\Models\WorkSchedule;
use App\Services\AttendanceBoard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Work from home requests: asked on My page, decided by HR, shown and flagged on attendance. Today is Wed 20 May 2026. */
class WfhRequestTest extends TestCase
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
        WorkSchedule::create(['name' => '9 to 5', 'type' => 'fixed', 'start_time' => '09:00', 'end_time' => '17:00', 'late_grace_minutes' => 10,
            'full_day_minutes' => 480, 'half_day_minutes' => 240, 'working_days' => [1, 2, 3, 4, 5], 'is_default' => true]);
        $this->me = $this->employee($this->company, ['full_name' => 'Fake Me', 'start_date' => '2025-01-01']);
        $this->login = $this->user('employee', ['employee_id' => $this->me->id]);
    }

    private function ask(array $data)
    {
        return $this->actingAs($this->login)->from('/me')->post('/me/wfh-requests', $data);
    }

    private function day(string $date): array
    {
        $board = new AttendanceBoard(Employee::query()->whereKey($this->me->id)->get(), $date, $date);

        return $board->day($this->me->fresh(), $date, CarbonImmutable::now());
    }

    public function test_employee_asks_and_hr_decides(): void
    {
        $this->ask(['start_date' => '2026-05-22', 'end_date' => '2026-05-21', 'reason' => 'x'])->assertSessionHasErrors('end_date');
        $this->ask(['start_date' => '2026-05-22', 'end_date' => '2026-07-22', 'reason' => 'x'])->assertSessionHasErrors('end_date');   // over 31 days
        $this->ask(['start_date' => '2026-05-22', 'end_date' => '2026-05-22'])->assertSessionHasErrors('reason');
        $this->ask(['start_date' => '2026-05-21', 'end_date' => '2026-05-22', 'reason' => 'Plumber coming'])->assertSessionHasNoErrors();
        $this->ask(['start_date' => '2026-05-22', 'end_date' => '2026-05-25', 'reason' => 'Again'])->assertSessionHasErrors('start_date');   // overlaps

        $r = WfhRequest::sole();
        $this->assertSame(['pending', 2], [$r->status, $r->props()['days']]);

        // HR's inbox counts it; the sidebar badge is the total.
        $hr = $this->user('hr');
        $this->actingAs($hr)->get('/requests')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('queues', fn ($q) => collect($q)->firstWhere('key', 'wfh')['pending'] === 1)->where('nav.inbox', 1));
        $this->actingAs($hr)->get('/attendance/wfh')->assertInertia(fn (AssertableInertia $p) => $p->where('requests.0.employee.name', 'Fake Me'));

        $this->actingAs($this->login)->post("/attendance/wfh/{$r->id}/approve")->assertForbidden();
        $this->actingAs($hr)->post("/attendance/wfh/{$r->id}/reject")->assertSessionHasErrors('note');
        $this->actingAs($hr)->post("/attendance/wfh/{$r->id}/approve", ['note' => 'Fine'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $r->fresh()->status);
        $this->actingAs($hr)->post("/attendance/wfh/{$r->id}/approve")->assertSessionHasErrors('status');   // already decided
        $this->actingAs($this->login)->delete("/me/wfh-requests/{$r->id}")->assertSessionHasErrors('status');   // too late to cancel
    }

    public function test_remote_time_is_flagged_unless_approved_or_remote_by_work_mode(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-20 18:00'));
        foreach (['09:00' => 'In', '17:00' => 'Out'] as $t => $m) {
            AttendancePunch::create(['source' => 'app', 'device_user_id' => (string) $this->me->id, 'punched_at' => "2026-05-20 $t:00",
                'work_date' => '2026-05-20', 'method' => $m, 'place' => 'remote']);
        }
        $this->assertSame([false, true], [$this->day('2026-05-20')['wfh'], $this->day('2026-05-20')['remote_unapproved']]);

        WfhRequest::create(['employee_id' => $this->me->id, 'start_date' => '2026-05-20', 'end_date' => '2026-05-20', 'reason' => 'x', 'status' => 'approved']);
        $this->assertSame([true, false], [$this->day('2026-05-20')['wfh'], $this->day('2026-05-20')['remote_unapproved']]);

        WfhRequest::query()->delete();
        $this->me->update(['work_mode' => 'Hybrid']);
        $this->assertFalse($this->day('2026-05-20')['remote_unapproved']);
    }

    public function test_employee_cancels_only_their_own_pending_request(): void
    {
        $other = $this->employee($this->company);
        $theirs = WfhRequest::create(['employee_id' => $other->id, 'start_date' => '2026-05-22', 'end_date' => '2026-05-22', 'reason' => 'x']);
        $this->actingAs($this->login)->delete("/me/wfh-requests/{$theirs->id}")->assertNotFound();

        $mine = WfhRequest::create(['employee_id' => $this->me->id, 'start_date' => '2026-05-22', 'end_date' => '2026-05-22', 'reason' => 'x']);
        $this->actingAs($this->login)->delete("/me/wfh-requests/{$mine->id}")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $mine->fresh()->status);
    }
}
