<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\WfhRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Home's number cards and recent changes. Fake people only. */
class HomeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-05-20 15:00'));
    }

    public function test_requests_waiting_are_counted_by_kind_for_hr_only(): void
    {
        $e = $this->employee($this->company);
        LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => LeaveType::first()->id, 'start_date' => '2026-05-25', 'end_date' => '2026-05-25']);
        WfhRequest::create(['employee_id' => $e->id, 'start_date' => '2026-05-22', 'end_date' => '2026-05-22', 'reason' => 'x']);
        WfhRequest::create(['employee_id' => $e->id, 'start_date' => '2026-05-23', 'end_date' => '2026-05-23', 'reason' => 'x']);

        $this->actingAs($this->user('hr'))->get('/dashboard')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('requestsWaiting', ['attendance' => 0, 'leave' => 1, 'wfh' => 2, 'loans' => 0, 'medical' => 0]));
        $this->actingAs($this->user('viewer'))->get('/dashboard')->assertInertia(fn (AssertableInertia $p) => $p->where('requestsWaiting', null));
    }

    public function test_came_in_counts_people_who_punched_on_the_latest_day(): void
    {
        $this->employee($this->company, ['full_name' => 'Fake In', 'attendance_machine_id' => '1']);
        $this->employee($this->company, ['full_name' => 'Fake Away', 'attendance_machine_id' => '2']);
        $this->employee($this->company, ['full_name' => 'Fake Unlinked']);
        foreach (['2026-05-19 09:00', '2026-05-19 17:00'] as $at) {
            AttendancePunch::create(['source' => 'machine', 'device_user_id' => '1', 'device_name' => 'Fake In', 'punched_at' => $at, 'work_date' => '2026-05-19']);
        }

        $this->actingAs($this->user('hr'))->get('/dashboard')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('attendance.date', '2026-05-19')->where('attendance.came_in', 1)->where('attendance.people', 3)->where('attendance.linked', 2));
    }

    public function test_recent_changes_leave_out_system_and_import_rows_and_count_today(): void
    {
        $hr = $this->user('hr');
        $this->actingAs($hr);
        $e = $this->employee($this->company, ['full_name' => 'Fake Edited']);
        ActivityLog::query()->delete();
        $e->update(['role_title' => 'Fake role']);   // a person's change, today
        ActivityLog::create(['subject_type' => 'Employee', 'subject_id' => $e->id, 'event' => 'imported', 'description' => 'Import', 'user_id' => $hr->id]);
        ActivityLog::create(['subject_type' => 'Employee', 'subject_id' => $e->id, 'event' => 'updated', 'description' => 'Machine sync', 'user_id' => null]);

        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $p) => $p
            ->has('activity', 1)->where('activity.0.user', $hr->name)->where('activityToday', 1));
    }
}
