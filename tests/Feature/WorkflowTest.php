<?php

namespace Tests\Feature;

use App\Models\ChecklistEntry;
use App\Models\ChecklistItem;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Offboarding;
use App\Models\Onboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** End-to-end HR flows through the HTTP layer. */
class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function set(Employee $e, string $list, string $key, ?string $status): void
    {
        $item = ChecklistItem::where('list', $list)->where('key', $key)->firstOrFail();
        ChecklistEntry::updateOrCreate(['employee_id' => $e->id, 'checklist_item_id' => $item->id], ['status' => $status]);
    }

    public function test_adding_an_employee_assigns_a_code_and_starts_onboarding(): void
    {
        [$company, $dept] = $this->baseData();
        $this->employee($company);   // BCC-001 exists
        $hr = $this->user('hr');

        $this->actingAs($hr)->post('/employees', [
            'company_id' => $company->id, 'department_id' => $dept->id, 'full_name' => 'New Hire',
            'start_date' => '2026-10-01', 'employment_type' => 'Full Time', 'cnic' => '3520212345671',
        ])->assertRedirect();

        $e = Employee::where('full_name', 'New Hire')->firstOrFail();
        $this->assertSame('BCC-002', $e->employee_code);
        $this->assertSame('35202-1234567-1', $e->cnic, 'CNIC is stored with dashes');
        $this->assertSame('2026-12-30', $e->onboarding->probation_end_date->toDateString());
        $this->assertSame('Pending', $e->onboarding->probation_result);
    }

    public function test_department_must_belong_to_the_company(): void
    {
        [$company] = $this->baseData();
        $other = Company::create(['name' => 'Emerge', 'code' => 'EMG', 'employee_code_prefix' => 'Emerge-', 'employee_code_digits' => 3]);
        $otherDept = $other->departments()->create(['name' => 'AI']);
        $this->actingAs($this->user('hr'))->post('/employees', ['company_id' => $company->id, 'department_id' => $otherDept->id, 'full_name' => 'X'])
            ->assertSessionHasErrors('department_id');
    }

    public function test_unassigned_import_gets_a_real_code_when_company_is_set(): void
    {
        [$company] = $this->baseData();
        $e = Employee::create(['employee_code' => 'UNASSIGNED-001', 'full_name' => 'Imported Person', 'status' => 'active', 'needs_review' => true]);

        $this->actingAs($this->user('hr'))->put("/employees/{$e->id}", ['company_id' => $company->id, 'full_name' => 'Imported Person', 'needs_review' => false])
            ->assertRedirect();
        $e->refresh();
        $this->assertSame('BCC-001', $e->employee_code);
        $this->assertFalse($e->needs_review);
    }

    public function test_system_id_is_set_once_and_survives_a_code_change(): void
    {
        [$company] = $this->baseData();
        $e = Employee::create(['employee_code' => 'UNASSIGNED-001', 'full_name' => 'Imported Person', 'status' => 'active']);
        $systemId = Employee::systemIdFor($e->id);
        $this->assertSame($systemId, $e->fresh()->system_id);

        $this->actingAs($this->user('hr'))->put("/employees/{$e->id}", ['company_id' => $company->id, 'full_name' => 'Imported Person', 'system_id' => 'HRM-99999'])
            ->assertRedirect();
        $e->refresh();
        $this->assertSame('BCC-001', $e->employee_code);
        $this->assertSame($systemId, $e->system_id, 'not typed, not changed by a code change');

        $this->actingAs($this->user('viewer'))->get('/employees?q='.$systemId)
            ->assertInertia(fn (AssertableInertia $p) => $p->where('employees.data.0.id', $e->id)->where('employees.total', 1));
    }

    public function test_changing_start_date_moves_the_computed_probation_end(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company, ['start_date' => '2026-01-01', 'full_name' => 'P']);
        Onboarding::create(['employee_id' => $e->id, 'probation_end_date' => '2026-04-01', 'probation_result' => 'Pending']);

        $this->actingAs($this->user('hr'))->put("/employees/{$e->id}", ['company_id' => $company->id, 'full_name' => 'P', 'start_date' => '2026-02-01']);
        $this->assertSame('2026-05-02', $e->onboarding()->first()->probation_end_date->toDateString());
    }

    public function test_bulk_update_sets_one_item_for_many_people(): void
    {
        [$company] = $this->baseData();
        $a = $this->employee($company);
        $b = $this->employee($company);
        $item = ChecklistItem::where('key', 'orientation_2')->first();

        $this->actingAs($this->user('hr'))->post('/checklist/bulk', ['employee_ids' => [$a->id, $b->id], 'item_id' => $item->id, 'status' => 'Done'])
            ->assertSessionHas('success');
        $this->assertSame(2, ChecklistEntry::where('checklist_item_id', $item->id)->where('status', 'Done')->count());

        $this->actingAs($this->user('hr'))->post('/checklist/bulk', ['employee_ids' => [$a->id], 'item_id' => $item->id, 'status' => 'Yes'])
            ->assertSessionHasErrors('status');
    }

    public function test_90_day_plan_checkins_tick_the_onboarding_checklist_and_set_the_result(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company, ['start_date' => '2026-07-01']);
        $hr = $this->user('hr');

        $this->actingAs($hr)->post("/employees/{$e->id}/plan")->assertRedirect("/employees/{$e->id}/plan");
        $plan = $e->probationPlan()->with('items', 'checkins')->first();
        $this->assertSame(15, $plan->items->count(), '4 goals, 4 behaviour, 4 attendance, 3 deliverables');
        $this->assertSame(['day30', 'day60', 'day90'], $plan->checkins->pluck('kind')->all());

        $day30 = $plan->checkins->firstWhere('kind', 'day30');
        $goal = $plan->items->firstWhere('section', 'goal');
        $this->actingAs($hr)->put("/employees/{$e->id}/plan", [
            'probation_result' => 'Extend',
            'probation_end_date' => '2026-10-29',   // Extend needs a later end date (was 2026-09-29)
            'items' => [['id' => $goal->id, 'title' => 'Ship the first 20 mockups', 'status' => 'In Progress']],
            'checkins' => [['id' => $day30->id, 'held_on' => '2026-07-31', 'manager_summary' => 'Good start']],
        ])->assertSessionHas('success');

        $item = ChecklistItem::where('key', 'day30_checkin')->first();
        $this->assertSame('Yes', ChecklistEntry::where('employee_id', $e->id)->where('checklist_item_id', $item->id)->value('status'));
        $this->assertSame('Extend', $e->onboarding()->first()->probation_result);
        $this->assertSame('2026-10-29', $e->onboarding()->first()->probation_end_date->toDateString());
        $this->assertSame('Ship the first 20 mockups', $goal->fresh()->title);
    }

    public function test_offboarding_needs_full_clearance_then_marks_employee_exited(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company);
        $hr = $this->user('hr');

        $this->actingAs($hr)->post('/offboarding', ['employee_id' => $e->id, 'type' => 'Resignation', 'last_working_day' => '2026-10-31'])->assertRedirect();
        $case = Offboarding::firstOrFail();
        $this->assertSame('offboarding', $e->fresh()->status);

        // second open case for the same person is refused
        $this->actingAs($hr)->post('/offboarding', ['employee_id' => $e->id, 'type' => 'Other'])->assertSessionHasErrors('employee_id');

        $this->actingAs($hr)->post("/offboarding/{$case->id}/complete")->assertSessionHas('error');
        $this->assertSame('in_progress', $case->fresh()->status);

        foreach (ChecklistItem::where('list', 'offboarding')->pluck('key') as $key) {
            $this->set($e, 'offboarding', $key, $key === 'experience_letter_issued' ? 'Not Required' : 'Done');
        }
        $this->actingAs($hr)->post("/offboarding/{$case->id}/complete")->assertSessionHas('success');
        $this->assertSame('completed', $case->fresh()->status);
        $this->assertSame('exited', $e->fresh()->status);
        $this->assertSame('2026-10-31', $e->fresh()->exit_date->toDateString());
    }

    public function test_dashboard_counts_each_document_in_its_own_column(): void
    {
        [$company] = $this->baseData();
        $a = $this->employee($company);
        $b = $this->employee($company);
        foreach (ChecklistItem::where('list', 'document')->pluck('key') as $key) {
            $this->set($a, 'document', $key, 'Yes');
            $this->set($b, 'document', $key, 'Yes');
        }
        $this->set($b, 'document', 'bank_details', 'No');           // one person missing bank details
        $this->set($b, 'document', 'emergency_contact', 'Pending');  // and emergency contact pending

        $this->actingAs($this->user('viewer'))->get('/dashboard')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('dashboard')
            ->where('documents.population', 2)
            ->where('documents.compliant', 1)
            ->where('documents.items', fn ($items) => collect($items)->firstWhere('key', 'bank_details')['no'] === 1
                && collect($items)->firstWhere('key', 'emergency_contact')['pending'] === 1
                && collect($items)->firstWhere('key', 'cnic_copy')['yes'] === 2));
    }

    public function test_onboarding_grid_filters_by_computed_status(): void
    {
        [$company] = $this->baseData();
        $a = $this->employee($company, ['full_name' => 'Alpha']);
        $b = $this->employee($company, ['full_name' => 'Beta']);
        Onboarding::create(['employee_id' => $a->id, 'probation_result' => 'Pending', 'probation_end_date' => now()->subDay()->toDateString()]);
        Onboarding::create(['employee_id' => $b->id, 'probation_result' => 'Pass']);
        foreach (['orientation_1', 'orientation_2', 'orientation_3'] as $k) {
            $this->set($b, 'onboarding', $k, 'Done');
        }

        $viewer = $this->user('viewer');
        $this->actingAs($viewer)->get('/onboarding?probation=overdue')->assertInertia(fn (AssertableInertia $p) => $p->has('rows', 1)->where('rows.0.name', 'Alpha'));
        $this->actingAs($viewer)->get('/onboarding?orientation=Complete')->assertInertia(fn (AssertableInertia $p) => $p->has('rows', 1)->where('rows.0.name', 'Beta'));
    }

    public function test_csv_export_downloads_with_bom(): void
    {
        [$company] = $this->baseData();
        $this->employee($company, ['full_name' => 'Export Me']);
        $res = $this->actingAs($this->user('viewer'))->get('/export/documents');
        $res->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $body = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFCode,Name", $body);
        $this->assertStringContainsString('Export Me', $body);
    }
}
