<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Department;
use App\Models\EmployeeWorkSchedule;
use App\Models\EmploymentHistory;
use App\Models\Team;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Employees list: filters, an export that matches them, and changing many people at once. Fake people only. */
class EmployeeListToolsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->dept] = $this->baseData();
    }

    private function names(string $url): array
    {
        $names = [];
        $this->actingAs($this->user('viewer'))->get($url)->assertInertia(function (AssertableInertia $p) use (&$names) {
            $names = collect($p->toArray()['props']['employees']['data'])->pluck('name')->sort()->values()->all();
        });

        return $names;
    }

    public function test_new_filters(): void
    {
        $boss = $this->employee($this->company, ['full_name' => 'Boss']);
        $this->employee($this->company, ['full_name' => 'Intern Remote', 'employment_type' => 'Intern', 'work_mode' => 'Remote',
            'start_date' => '2026-03-01', 'manager_id' => $boss->id, 'attendance_machine_id' => '7']);
        $app = $this->employee($this->company, ['full_name' => 'App User', 'employment_type' => 'Full Time', 'start_date' => '2025-01-10']);
        $this->employee($this->company, ['full_name' => 'Nobody', 'employment_type' => 'Full Time', 'start_date' => '2024-06-01']);
        $this->user('employee', ['employee_id' => $app->id]);
        AttendancePunch::create(['source' => 'app', 'device_user_id' => (string) $app->id, 'punched_at' => '2026-05-13 09:00:00', 'work_date' => '2026-05-13']);

        $this->assertSame(['Intern Remote'], $this->names('/employees?type=Intern'));
        $this->assertSame(['Intern Remote'], $this->names('/employees?mode=Remote'));
        $this->assertSame(['Intern Remote'], $this->names("/employees?manager={$boss->id}"));
        $this->assertSame(['App User', 'Intern Remote'], $this->names('/employees?started_from=2025-01-01&started_to=2026-12-31'));
        $this->assertSame(['App User'], $this->names('/employees?login=yes'));
        $this->assertSame(['App User', 'Intern Remote'], $this->names('/employees?linked=yes'));
        $this->assertSame(['Boss', 'Nobody'], $this->names('/employees?linked=no'));
    }

    public function test_export_follows_the_filters(): void
    {
        $emerge = Company::create(['name' => 'Emerge', 'code' => 'EMG', 'employee_code_prefix' => 'EMG-', 'employee_code_digits' => 3]);
        $this->employee($this->company, ['full_name' => 'Bcc Person']);
        $this->employee($emerge, ['full_name' => 'Emerge Person']);
        $this->employee($emerge, ['full_name' => 'Emerge Leaver', 'status' => 'exited']);

        $csv = fn (string $q) => $this->actingAs($this->user('viewer'))->get("/export/employees$q")->assertOk()->streamedContent();
        $this->assertStringContainsString('Emerge Leaver', $csv(''));   // a bare link: everyone
        $all = $csv('?status=current');                                  // what the button sends by default
        $this->assertStringContainsString('Bcc Person', $all);
        $this->assertStringNotContainsString('Emerge Leaver', $all);
        $emg = $csv("?company={$emerge->id}&status=current");
        $this->assertStringContainsString('Emerge Person', $emg);
        $this->assertStringNotContainsString('Bcc Person', $emg);
        $this->assertStringContainsString('Emerge Leaver', $csv("?company={$emerge->id}&status=exited"));
        $this->assertStringContainsString('Work mode', $all);
    }

    public function test_bulk_change_saves_each_person_with_history(): void
    {
        $hr = $this->user('hr');
        $sales = Department::create(['company_id' => $this->company->id, 'name' => 'Sales']);
        $emerge = Company::create(['name' => 'Emerge', 'code' => 'EMG', 'employee_code_prefix' => 'EMG-', 'employee_code_digits' => 3]);
        $emergeDept = Department::create(['company_id' => $emerge->id, 'name' => 'Ops']);
        $team = Team::create(['department_id' => $this->dept->id, 'name' => 'Recruiting']);
        $a = $this->employee($this->company, ['department_id' => $this->dept->id, 'team_id' => $team->id]);
        $b = $this->employee($this->company, ['department_id' => $this->dept->id]);
        $other = $this->employee($emerge, ['department_id' => $emergeDept->id]);

        $this->actingAs($this->user('viewer'))->post('/employees/bulk', ['ids' => [$a->id], 'field' => 'work_mode', 'value' => 'Remote'])->assertForbidden();
        $this->actingAs($hr)->post('/employees/bulk', ['ids' => [$a->id], 'field' => 'employment_type', 'value' => 'Astronaut'])->assertSessionHasErrors('value');
        $this->actingAs($hr)->post('/employees/bulk', ['ids' => [], 'field' => 'work_mode'])->assertSessionHasErrors('ids');

        // Department: same company only; the old team goes; the other company is skipped.
        $this->actingAs($hr)->post('/employees/bulk', ['ids' => [$a->id, $b->id, $other->id], 'field' => 'department', 'value' => $sales->id, 'effective_date' => '2026-05-01'])
            ->assertSessionHas('success', fn ($m) => str_starts_with($m, '2 people updated. Skipped 1'));
        $this->assertSame([$sales->id, null], [$a->fresh()->department_id, $a->fresh()->team_id]);
        $this->assertSame($emergeDept->id, $other->fresh()->department_id);
        $h = EmploymentHistory::where('employee_id', $a->id)->sole();
        $this->assertSame(['Transfer', '2026-05-01', 'HR', 'Sales'], [$h->change_type, $h->effective_date->toDateString(), $h->changes['Department'][0], $h->changes['Department'][1]]);
        $this->actingAs($hr)->post('/employees/bulk', ['ids' => [$other->id], 'field' => 'department', 'value' => $sales->id])->assertSessionHasErrors('value');

        // Manager: nobody manages themselves; clearing works.
        $this->actingAs($hr)->post('/employees/bulk', ['ids' => [$a->id, $b->id], 'field' => 'manager', 'value' => $a->id])
            ->assertSessionHas('success', fn ($m) => str_starts_with($m, '1 person updated. Skipped 1'));
        $this->assertSame([null, $a->id], [$a->fresh()->manager_id, $b->fresh()->manager_id]);
        $this->actingAs($hr)->post('/employees/bulk', ['ids' => [$b->id], 'field' => 'manager', 'value' => null])->assertSessionHas('success', '1 person updated.');
        $this->assertNull($b->fresh()->manager_id);

        // Work schedule from a date.
        $s = WorkSchedule::create(['name' => 'Nine to five', 'type' => 'fixed', 'start_time' => '09:00', 'end_time' => '17:00', 'working_days' => [1, 2, 3, 4, 5]]);
        $this->actingAs($hr)->post('/employees/bulk', ['ids' => [$a->id, $b->id], 'field' => 'schedule', 'value' => $s->id, 'effective_date' => '2026-06-01'])
            ->assertSessionHas('success', '2 people moved to the new schedule.');
        $this->assertSame(2, EmployeeWorkSchedule::where('work_schedule_id', $s->id)->where('starts_on', '2026-06-01')->count());
    }
}
