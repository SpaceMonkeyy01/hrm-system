<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Leave records, holidays and how attendance shows them. Fake data only. 2026-05-11 is a Monday. */
class LeaveTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-05-20 12:00'));
        WorkSchedule::create(['name' => 'Ten to six', 'start_time' => '10:00', 'end_time' => '18:00', 'late_grace_minutes' => 10,
            'working_days' => [1, 2, 3, 4, 5], 'is_default' => true]);
    }

    private function type(string $name = 'Casual'): LeaveType
    {
        return LeaveType::where('name', $name)->firstOrFail();
    }

    public function test_leave_days_skip_weekends_and_holidays(): void
    {
        $e = $this->employee($this->company, ['attendance_machine_id' => '1']);
        Holiday::create(['date' => '2026-05-13', 'name' => 'Fake holiday']);
        $hr = $this->user('hr');

        // Mon 11 to Mon 18 May: 6 weekdays, minus the Wednesday holiday = 5.
        $this->actingAs($hr)->post('/leave', ['employee_id' => $e->id, 'leave_type_id' => $this->type('Annual')->id,
            'start_date' => '2026-05-11', 'end_date' => '2026-05-18', 'part' => 'full'])
            ->assertSessionHas('success', "Recorded 5 days of leave for {$e->full_name}.");
        $this->assertSame(5.0, LeaveRecord::sole()->days);

        // Overlapping leave is refused; half days are for one day only.
        $this->actingAs($hr)->post('/leave', ['employee_id' => $e->id, 'leave_type_id' => $this->type()->id, 'start_date' => '2026-05-18', 'end_date' => '2026-05-18', 'part' => 'full'])
            ->assertSessionHasErrors('start_date');
        $this->actingAs($hr)->post('/leave', ['employee_id' => $e->id, 'leave_type_id' => $this->type()->id, 'start_date' => '2026-05-19', 'end_date' => '2026-05-20', 'part' => 'first_half'])
            ->assertSessionHasErrors('part');
        $this->actingAs($this->user('viewer'))->post('/leave', ['employee_id' => $e->id, 'leave_type_id' => $this->type()->id, 'start_date' => '2026-05-19', 'end_date' => '2026-05-19', 'part' => 'full'])
            ->assertForbidden();
    }

    public function test_attendance_shows_leave_and_holidays_instead_of_absent(): void
    {
        $e = $this->employee($this->company, ['attendance_machine_id' => '1']);
        Holiday::create(['date' => '2026-05-13', 'name' => 'Fake holiday']);
        LeaveRecord::create(['employee_id' => $e->id, 'leave_type_id' => $this->type('Sick')->id, 'start_date' => '2026-05-12', 'end_date' => '2026-05-12', 'part' => 'full', 'days' => 1]);

        $this->actingAs($this->user('hr'))->get("/employees/{$e->id}?month=2026-05")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('attendance.days', function ($days) {
                $d = collect($days)->keyBy('date');

                return $d['2026-05-11']['status'] === 'Absent'
                    && $d['2026-05-12']['status'] === 'On leave' && $d['2026-05-12']['leave']['type'] === 'Sick'
                    && $d['2026-05-13']['status'] === 'Holiday' && $d['2026-05-13']['holiday'] === 'Fake holiday';
            })
            ->where('leave.totals', ['Sick' => 1]));
    }

    public function test_half_day_leave_is_not_late_or_a_half_day(): void
    {
        $e = $this->employee($this->company, ['attendance_machine_id' => '1']);
        LeaveRecord::create(['employee_id' => $e->id, 'leave_type_id' => $this->type()->id, 'start_date' => '2026-05-12', 'end_date' => '2026-05-12', 'part' => 'first_half', 'days' => 0.5]);
        \App\Models\AttendancePunch::insert([
            ['source' => 'machine', 'device_user_id' => '1', 'punched_at' => '2026-05-12 14:00:00', 'work_date' => '2026-05-12', 'created_at' => now()],
            ['source' => 'machine', 'device_user_id' => '1', 'punched_at' => '2026-05-12 18:00:00', 'work_date' => '2026-05-12', 'created_at' => now()],
        ]);

        $this->actingAs($this->user('viewer'))->get('/attendance?date=2026-05-12')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('rows.0.status', 'Present')->where('rows.0.late', 0)->where('rows.0.leave.part', 'first_half'));
    }

    public function test_holidays_and_types_are_managed_by_hr(): void
    {
        $hr = $this->user('hr');
        $this->actingAs($hr)->post('/leave/holidays', ['date' => '2026-03-20', 'end_date' => '2026-03-22', 'name' => 'Fake Eid'])
            ->assertSessionHas('success', 'Added Fake Eid (3 days).');
        $this->assertSame(3, Holiday::count());
        $this->actingAs($hr)->post('/leave/types', ['name' => 'Study'])->assertSessionHasNoErrors();
        $study = $this->type('Study');
        $this->actingAs($hr)->put("/leave/types/{$study->id}", ['name' => 'Study', 'is_paid' => false, 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($study->fresh()->is_active);

        // A switched-off type can't be used for new leave.
        $e = $this->employee($this->company);
        $this->actingAs($hr)->post('/leave', ['employee_id' => $e->id, 'leave_type_id' => $study->id, 'start_date' => '2026-05-19', 'end_date' => '2026-05-19', 'part' => 'full'])
            ->assertSessionHasErrors('leave_type_id');

        $this->actingAs($this->user('viewer'))->get('/leave?month=2026-03')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('leave/index')->has('holidays', 3)->where('holidays.0.name', 'Fake Eid'));
    }
}
