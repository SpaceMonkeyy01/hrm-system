<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Org chart: the manager check-up, dotted-line reports, department heads. Fake people only. */
class OrgChartCheckupTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->dept] = $this->baseData();
    }

    private function props(string $url = '/org-chart'): array
    {
        $props = [];
        $this->actingAs($this->user('viewer'))->get($url)->assertInertia(function (AssertableInertia $p) use (&$props) {
            $props = $p->toArray()['props'];
        });

        return $props;
    }

    public function test_checkup_finds_each_kind_of_problem(): void
    {
        $emerge = Company::create(['name' => 'Emerge', 'code' => 'EMG', 'employee_code_prefix' => 'EMG-', 'employee_code_digits' => 3]);
        $boss = $this->employee($this->company, ['full_name' => 'Boss']);
        $this->employee($this->company, ['full_name' => 'Ok', 'manager_id' => $boss->id]);
        $this->employee($this->company, ['full_name' => 'Alone']);
        $gone = $this->employee($this->company, ['full_name' => 'Gone Manager', 'status' => 'exited']);
        $this->employee($this->company, ['full_name' => 'Orphan', 'manager_id' => $gone->id]);
        $other = $this->employee($emerge, ['full_name' => 'Emerge Boss']);
        $this->employee($this->company, ['full_name' => 'Crossed', 'manager_id' => $other->id]);
        $a = $this->employee($this->company, ['full_name' => 'Loop A']);
        $b = $this->employee($this->company, ['full_name' => 'Loop B', 'manager_id' => $a->id]);
        $a->update(['manager_id' => $b->id]);

        $c = $this->props()['checkup'];
        $names = fn (string $k) => collect($c[$k])->pluck('name')->sort()->values()->all();
        $this->assertSame(['Boss', 'Emerge Boss'], $names('tops'));
        $this->assertSame(['Alone'], $names('no_manager'));
        $this->assertSame(['Orphan'], $names('manager_gone'));
        $this->assertSame('Manager Gone Manager has left', $c['manager_gone'][0]['detail']);
        $this->assertSame(['Crossed'], $names('cross_company'));
        $this->assertCount(1, $c['loops']);
        $this->assertStringContainsString('Loop A', $c['loops'][0]['detail']);
        $this->assertStringContainsString('Loop B', $c['loops'][0]['detail']);

        // One company: a manager outside the view is "in the other company", not "gone".
        $bcc = $this->props("/org-chart?company={$this->company->id}")['checkup'];
        $this->assertSame(['Crossed'], collect($bcc['cross_company'])->pluck('name')->all());

        // The "fix several at once" link lands on the list of people with no manager.
        $this->actingAs($this->user('viewer'))->get('/employees?no_manager=1')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('employees.data', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['Alone', 'Boss', 'Emerge Boss']));
    }

    public function test_second_managers_are_dotted_lines(): void
    {
        $boss = $this->employee($this->company, ['full_name' => 'Boss']);
        $lead = $this->employee($this->company, ['full_name' => 'Lead']);   // nobody's manager, only a second manager
        $this->employee($this->company, ['full_name' => 'Worker', 'manager_id' => $boss->id, 'secondary_manager_id' => $lead->id]);

        $m = $this->props()['managers'];
        $roots = collect($m['roots'])->keyBy('name');
        $this->assertSame(['Worker'], collect($roots['Lead']['dotted'])->pluck('name')->all());
        $this->assertSame(['Worker'], collect($roots['Boss']['reports'])->pluck('name')->all());
        $this->assertSame(1, $m['dotted']);
    }

    public function test_department_head_is_set_in_settings_and_shown_on_the_chart(): void
    {
        $admin = $this->user('admin');
        $head = $this->employee($this->company, ['full_name' => 'Head Person', 'department_id' => $this->dept->id]);
        $emerge = Company::create(['name' => 'Emerge', 'code' => 'EMG', 'employee_code_prefix' => 'EMG-', 'employee_code_digits' => 3]);
        $outsider = $this->employee($emerge);

        $this->actingAs($this->user('hr'))->put("/settings/departments/{$this->dept->id}/head", ['head_employee_id' => $head->id])->assertForbidden();
        $this->actingAs($admin)->put("/settings/departments/{$this->dept->id}/head", ['head_employee_id' => $outsider->id])->assertSessionHasErrors('head_employee_id');
        $this->actingAs($admin)->put("/settings/departments/{$this->dept->id}/head", ['head_employee_id' => $head->id])->assertSessionHas('success');
        $this->assertSame($head->id, $this->dept->fresh()->head_employee_id);

        $dept = collect($this->props()['structure'][0]['departments'])->firstWhere('name', 'HR');
        $this->assertSame(['id' => $head->id, 'name' => 'Head Person'], $dept['head']);

        // The add-employee form gets each department's head, to suggest as manager.
        $this->actingAs($admin)->get('/employees/create')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('companies', fn ($cs) => collect($cs)->firstWhere('name', 'BCC')['departments'][0]['head_id'] === $head->id));

        $this->actingAs($admin)->put("/settings/departments/{$this->dept->id}/head", ['head_employee_id' => null])->assertSessionHas('success');
        $this->assertNull($this->dept->fresh()->head_employee_id);
    }
}
