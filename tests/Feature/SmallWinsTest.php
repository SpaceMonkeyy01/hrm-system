<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\ChecklistEntry;
use App\Models\ChecklistItem;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Offboarding;
use App\Models\Team;
use App\Services\Reminders;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** CNIC expiry reminders, the asset register and the org chart. Fake people only; today is Wed 20 May 2026. */
class SmallWinsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-05-20 09:00'));
    }

    // ------------------------------------------------------------------ CNIC

    public function test_cnic_expiry_reminds_hr_but_not_viewers(): void
    {
        $old = $this->employee($this->company, ['full_name' => 'Fake Old', 'start_date' => '2015-01-01', 'cnic_expiry' => '2026-05-10']);
        $soon = $this->employee($this->company, ['full_name' => 'Fake Soon', 'start_date' => '2015-01-01', 'cnic_expiry' => '2026-06-10']);
        $this->employee($this->company, ['full_name' => 'Fake Later', 'start_date' => '2015-01-01', 'cnic_expiry' => '2026-12-01']);

        $list = collect(Reminders::forDate(CarbonImmutable::today()))->keyBy('employee_name');
        $this->assertSame(['cnic_expired', 'overdue', 'CNIC expired 10 days ago. Ask for the renewed card'],
            [$list['Fake Old']['type'], $list['Fake Old']['severity'], $list['Fake Old']['message']]);
        $this->assertSame(['cnic_expiring', 'due_soon', 'CNIC expires in 21 days'], [$list['Fake Soon']['type'], $list['Fake Soon']['severity'], $list['Fake Soon']['message']]);
        $this->assertFalse($list->has('Fake Later'));

        $this->actingAs($this->user('hr'))->get('/dashboard')->assertInertia(fn (AssertableInertia $p) => $p->has('reminders', 2));
        $this->actingAs($this->user('viewer'))->get('/dashboard')->assertInertia(fn (AssertableInertia $p) => $p->has('reminders', 0));
        $this->assertSame($old->id, $list['Fake Old']['employee_id']);
        $this->assertSame($soon->id, $list['Fake Soon']['employee_id']);
    }

    // ------------------------------------------------------------------ assets

    private function laptop(array $attrs = []): Asset
    {
        return Asset::create($attrs + ['asset_tag' => 'LAP-001', 'type' => 'Laptop', 'name' => 'Fake Laptop 14']);
    }

    public function test_hr_adds_gives_and_takes_back_an_asset(): void
    {
        $hr = $this->user('hr');
        $e = $this->employee($this->company, ['full_name' => 'Fake Holder']);
        $this->actingAs($hr)->post('/assets', ['asset_tag' => 'LAP-001', 'type' => 'Laptop', 'name' => 'Fake Laptop 14', 'status' => 'assigned'])
            ->assertSessionHasErrors(['status' => 'Give the asset to someone to mark it as assigned.']);
        $this->actingAs($hr)->post('/assets', ['asset_tag' => 'LAP-001', 'type' => 'Laptop', 'name' => 'Fake Laptop 14', 'status' => 'in_stock'])->assertSessionHasNoErrors();
        $asset = Asset::sole();

        $this->actingAs($hr)->post("/assets/{$asset->id}/assign", ['employee_id' => $e->id, 'assigned_on' => '2026-05-01', 'condition_out' => 'Good'])->assertSessionHasNoErrors();
        $this->assertSame('assigned', $asset->fresh()->status);
        $this->actingAs($hr)->post("/assets/{$asset->id}/assign", ['employee_id' => $e->id, 'assigned_on' => '2026-05-02'])->assertSessionHasErrors('employee_id');
        $this->actingAs($hr)->put("/assets/{$asset->id}", ['asset_tag' => 'LAP-001', 'type' => 'Laptop', 'name' => 'Fake Laptop 14', 'status' => 'in_stock'])
            ->assertSessionHasErrors('status');

        $this->actingAs($hr)->post("/assets/{$asset->id}/return", ['returned_on' => '2026-04-30', 'status' => 'in_stock'])->assertSessionHasErrors('returned_on');
        $this->actingAs($hr)->post("/assets/{$asset->id}/return", ['returned_on' => '2026-05-15', 'status' => 'repair', 'condition_in' => 'Cracked screen'])->assertSessionHasNoErrors();
        $this->assertSame('repair', $asset->fresh()->status);
        $a = AssetAssignment::sole();
        $this->assertSame(['2026-05-15', 'Cracked screen', $hr->id], [$a->returned_on->toDateString(), $a->condition_in, $a->returned_by]);
        // In repair, so it can't go out again until HR puts it back in stock.
        $this->actingAs($hr)->post("/assets/{$asset->id}/assign", ['employee_id' => $e->id, 'assigned_on' => '2026-05-16'])->assertSessionHasErrors('employee_id');
    }

    public function test_viewers_see_assets_but_cannot_change_them(): void
    {
        $asset = $this->laptop();
        $viewer = $this->user('viewer');
        $this->actingAs($viewer)->get('/assets')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('assets', 1)->where('people', []));
        $this->actingAs($viewer)->post("/assets/{$asset->id}/assign", [])->assertForbidden();
    }

    public function test_offboarding_cannot_finish_while_assets_are_out(): void
    {
        $e = $this->employee($this->company, ['full_name' => 'Fake Leaver', 'status' => 'offboarding']);
        $case = Offboarding::create(['employee_id' => $e->id, 'type' => 'Resignation', 'last_working_day' => '2026-05-20']);
        foreach (ChecklistItem::where('list', 'offboarding')->get() as $item) {
            ChecklistEntry::create(['employee_id' => $e->id, 'checklist_item_id' => $item->id, 'status' => 'Done']);
        }
        $asset = $this->laptop(['status' => 'assigned']);
        AssetAssignment::create(['asset_id' => $asset->id, 'employee_id' => $e->id, 'assigned_on' => '2026-01-01']);
        $hr = $this->user('hr');

        $this->actingAs($hr)->get("/offboarding/{$case->id}")->assertInertia(fn (AssertableInertia $p) => $p->has('assets', 1)->where('assets.0.tag', 'LAP-001'));
        $this->actingAs($hr)->post("/offboarding/{$case->id}/complete")->assertSessionHas('error', fn ($m) => str_contains($m, 'LAP-001 Fake Laptop 14'));
        $this->assertSame('offboarding', $e->fresh()->status);

        $this->actingAs($hr)->post("/assets/{$asset->id}/return", ['returned_on' => '2026-05-20', 'status' => 'lost']);
        $this->actingAs($hr)->post("/offboarding/{$case->id}/complete")->assertSessionHasNoErrors();
        $this->assertSame('exited', $e->fresh()->status);
    }

    public function test_people_see_their_own_assets_on_my_page(): void
    {
        $e = $this->employee($this->company);
        AssetAssignment::create(['asset_id' => $this->laptop(['status' => 'assigned'])->id, 'employee_id' => $e->id, 'assigned_on' => '2026-01-01']);
        $this->actingAs($this->user('employee', ['employee_id' => $e->id]))->get('/me')
            ->assertInertia(fn (AssertableInertia $p) => $p->has('assets', 1)->where('assets.0.tag', 'LAP-001')->etc());
    }

    // ------------------------------------------------------------------ org chart

    public function test_org_chart_groups_by_department_and_team_and_draws_reporting_lines(): void
    {
        $dept = $this->company->departments()->first();
        $team = Team::create(['department_id' => $dept->id, 'name' => 'Fake Team']);
        $boss = $this->employee($this->company, ['full_name' => 'Fake Boss', 'department_id' => $dept->id]);
        $lead = $this->employee($this->company, ['full_name' => 'Fake Lead', 'department_id' => $dept->id, 'team_id' => $team->id, 'manager_id' => $boss->id]);
        $this->employee($this->company, ['full_name' => 'Fake Member', 'department_id' => $dept->id, 'team_id' => $team->id, 'manager_id' => $lead->id]);
        $this->employee($this->company, ['full_name' => 'Fake Alone']);
        $this->employee($this->company, ['full_name' => 'Fake Gone', 'status' => 'exited', 'manager_id' => $boss->id]);

        $this->actingAs($this->user('viewer'))->get('/org-chart')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('org-chart')
            ->where('total', 4)
            ->where('structure.0.departments.0.name', 'HR')
            ->where('structure.0.departments.0.teams.0.name', 'Fake Team')
            ->where('structure.0.departments.0.teams.0.people', fn ($people) => collect($people)->pluck('name')->all() === ['Fake Lead', 'Fake Member'])
            ->where('managers.roots.0.name', 'Fake Boss')
            ->where('managers.roots.0.total', 2)
            ->where('managers.roots.0.reports.0.reports.0.name', 'Fake Member')
            ->where('managers.withoutManager', 1));
    }

    public function test_a_reporting_loop_does_not_hang_the_chart(): void
    {
        $a = $this->employee($this->company, ['full_name' => 'Fake A']);
        $b = $this->employee($this->company, ['full_name' => 'Fake B', 'manager_id' => $a->id]);
        Employee::whereKey($a->id)->update(['manager_id' => $b->id]);   // bad data: A and B manage each other
        $this->actingAs($this->user('hr'))->get('/org-chart')->assertOk();
    }
}
