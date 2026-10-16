<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\EmploymentHistory;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Settings > Departments and teams: current vs former counts, merging departments, moving teams. */
class StructureToolsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->dept] = $this->baseData();
    }

    public function test_counts_split_current_and_former_staff(): void
    {
        $this->employee($this->company, ['department_id' => $this->dept->id]);
        $this->employee($this->company, ['department_id' => $this->dept->id, 'status' => 'exited']);

        $this->actingAs($this->user('admin'))->get('/settings/structure')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('companies.0.departments.0.current', 1)->where('companies.0.departments.0.employees', 2)->where('companies.0.current', 1));
    }

    public function test_merging_a_department_moves_everyone_and_its_teams(): void
    {
        $admin = $this->user('admin');
        $into = Department::create(['company_id' => $this->company->id, 'name' => 'People Ops']);
        $sameName = Team::create(['department_id' => $into->id, 'name' => 'Recruiting']);
        $joins = Team::create(['department_id' => $this->dept->id, 'name' => 'recruiting']);   // same name, other case
        $moves = Team::create(['department_id' => $this->dept->id, 'name' => 'Payroll']);
        $head = $this->employee($this->company, ['department_id' => $this->dept->id, 'team_id' => $joins->id]);
        $this->dept->update(['head_employee_id' => $head->id]);
        $b = $this->employee($this->company, ['department_id' => $this->dept->id, 'team_id' => $moves->id]);
        $left = $this->employee($this->company, ['department_id' => $this->dept->id, 'status' => 'exited']);
        $second = $this->employee($this->company, ['department_id' => $into->id, 'secondary_department_id' => $this->dept->id]);
        $emerge = Company::create(['name' => 'Emerge', 'code' => 'EMG', 'employee_code_prefix' => 'EMG-', 'employee_code_digits' => 3]);
        $elsewhere = Department::create(['company_id' => $emerge->id, 'name' => 'Ops']);

        $this->actingAs($this->user('hr'))->post("/settings/departments/{$this->dept->id}/merge", ['into_id' => $into->id])->assertForbidden();
        $this->actingAs($admin)->post("/settings/departments/{$this->dept->id}/merge", ['into_id' => $elsewhere->id])->assertSessionHasErrors('into_id');

        $this->actingAs($admin)->post("/settings/departments/{$this->dept->id}/merge", ['into_id' => $into->id])
            ->assertSessionHas('success', 'Merged HR into People Ops (3 people moved).');

        $this->assertNull(Department::find($this->dept->id));
        $this->assertSame([$into->id, $sameName->id], [$head->fresh()->department_id, $head->fresh()->team_id]);
        $this->assertSame([$into->id, $moves->id], [$b->fresh()->department_id, $b->fresh()->team_id]);
        $this->assertSame($into->id, $moves->fresh()->department_id);
        $this->assertNull(Team::find($joins->id));
        $this->assertSame($into->id, $left->fresh()->department_id);
        $this->assertNull($second->fresh()->secondary_department_id);   // would be the same as their main department
        $this->assertSame($head->id, $into->fresh()->head_employee_id);
        // Job history for current staff only.
        $this->assertSame(2, EmploymentHistory::count());
        $this->assertSame('Department HR merged into People Ops', EmploymentHistory::where('employee_id', $b->id)->sole()->note);
    }

    public function test_moving_a_team_takes_its_people_and_joins_a_same_named_team(): void
    {
        $admin = $this->user('admin');
        $sales = Department::create(['company_id' => $this->company->id, 'name' => 'Sales']);
        $team = Team::create(['department_id' => $this->dept->id, 'name' => 'Outreach']);
        $p = $this->employee($this->company, ['department_id' => $this->dept->id, 'team_id' => $team->id]);

        $this->actingAs($admin)->put("/settings/teams/{$team->id}/move", ['department_id' => $sales->id])->assertSessionHas('success', 'Moved Outreach to Sales.');
        $this->assertSame([$sales->id, $team->id], [$p->fresh()->department_id, $p->fresh()->team_id]);
        $this->assertSame($sales->id, $team->fresh()->department_id);
        $this->assertSame('Team Outreach moved from HR to Sales', EmploymentHistory::where('employee_id', $p->id)->sole()->note);

        // Back to HR, which now has its own Outreach: the two become one.
        $hrOutreach = Team::create(['department_id' => $this->dept->id, 'name' => 'Outreach']);
        $this->actingAs($admin)->put("/settings/teams/{$team->id}/move", ['department_id' => $this->dept->id])
            ->assertSessionHas('success', 'Joined Outreach with the Outreach team in HR.');
        $this->assertSame([$this->dept->id, $hrOutreach->id], [$p->fresh()->department_id, $p->fresh()->team_id]);
        $this->assertNull(Team::find($team->id));
    }
}
