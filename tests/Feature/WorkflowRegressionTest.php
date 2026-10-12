<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ChecklistEntry;
use App\Models\ChecklistItem;
use App\Models\Employee;
use App\Models\Offboarding;
use App\Models\Onboarding;
use App\Services\HrRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Regression tests for review findings R1-R6, F2, F3, L1, L2 (fake data only). */
class WorkflowRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function set(Employee $e, string $list, string $key, ?string $status): void
    {
        $item = ChecklistItem::where('list', $list)->where('key', $key)->firstOrFail();
        ChecklistEntry::updateOrCreate(['employee_id' => $e->id, 'checklist_item_id' => $item->id], ['status' => $status]);
    }

    private function props(string $url, string $role = 'hr'): array
    {
        $props = [];
        $this->actingAs($this->user($role))->get($url)->assertInertia(function (AssertableInertia $p) use (&$props) {
            $props = $p->toArray()['props'];
        });

        return $props;
    }

    // ------------------------------------------------------------------ R1

    public function test_r1_creating_a_plan_starts_onboarding_as_pending_with_end_date(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company, ['start_date' => '2026-07-01']);
        $this->actingAs($this->user('hr'))->post("/employees/{$e->id}/plan")->assertRedirect();

        $o = Onboarding::where('employee_id', $e->id)->firstOrFail();
        $this->assertSame('2026-09-29', $o->probation_end_date?->toDateString());
        $this->assertSame('Pending', $o->probation_result);
    }

    public function test_r1_grid_edit_starts_onboarding_as_pending_with_end_date(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company, ['start_date' => '2026-07-01']);
        $this->actingAs($this->user('hr'))->put("/onboarding/{$e->id}", ['cohort' => 'A'])->assertRedirect();

        $o = Onboarding::where('employee_id', $e->id)->firstOrFail();
        $this->assertSame('2026-09-29', $o->probation_end_date?->toDateString());
        $this->assertSame('Pending', $o->probation_result);
        $this->assertSame('A', $o->cohort);
    }

    public function test_r1_plan_result_save_starts_onboarding_as_pending_first(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company, ['start_date' => '2026-07-01']);
        $hr = $this->user('hr');
        $this->actingAs($hr)->post("/employees/{$e->id}/plan");
        Onboarding::where('employee_id', $e->id)->delete();

        $this->actingAs($hr)->put("/employees/{$e->id}/plan", ['probation_result' => 'Pass'])->assertSessionHasNoErrors();
        $o = Onboarding::where('employee_id', $e->id)->firstOrFail();
        $this->assertSame('2026-09-29', $o->probation_end_date?->toDateString());
        $this->assertSame('Pass', $o->probation_result);
    }

    // ------------------------------------------------------------------ R2

    public function test_r2_extend_without_a_later_end_date_is_refused_on_the_grid(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company, ['start_date' => '2026-01-01']);
        Onboarding::create(['employee_id' => $e->id, 'probation_end_date' => '2026-04-01', 'probation_result' => 'Pending']);
        $hr = $this->user('hr');

        $this->actingAs($hr)->put("/onboarding/{$e->id}", ['probation_result' => 'Extend'])
            ->assertSessionHasErrors(['probation_end_date' => HrRules::EXTEND_NEEDS_LATER_END]);
        $this->actingAs($hr)->put("/onboarding/{$e->id}", ['probation_result' => 'Extend', 'probation_end_date' => '2026-03-01'])
            ->assertSessionHasErrors('probation_end_date');
        $this->assertSame('Pending', $e->onboarding()->first()->probation_result);

        $this->actingAs($hr)->put("/onboarding/{$e->id}", ['probation_result' => 'Extend', 'probation_end_date' => '2026-05-01'])
            ->assertSessionHasNoErrors();
        $o = $e->onboarding()->first();
        $this->assertSame(['Extend', '2026-05-01'], [$o->probation_result, $o->probation_end_date->toDateString()]);
    }

    public function test_r2_extend_from_the_plan_needs_and_saves_the_new_end_date(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company, ['start_date' => '2026-07-01']);
        $hr = $this->user('hr');
        $this->actingAs($hr)->post("/employees/{$e->id}/plan");

        $this->actingAs($hr)->put("/employees/{$e->id}/plan", ['probation_result' => 'Extend'])
            ->assertSessionHasErrors('probation_end_date');
        $this->assertSame('Pending', $e->onboarding()->first()->probation_result);

        $this->actingAs($hr)->put("/employees/{$e->id}/plan", ['probation_result' => 'Extend', 'probation_end_date' => '2026-10-29'])
            ->assertSessionHasNoErrors();
        $o = $e->onboarding()->first();
        $this->assertSame(['Extend', '2026-10-29'], [$o->probation_result, $o->probation_end_date->toDateString()]);

        // already Extend: saving the plan again doesn't need a new date
        $this->actingAs($hr)->put("/employees/{$e->id}/plan", ['probation_result' => 'Extend'])->assertSessionHasNoErrors();
    }

    public function test_r2_r6_extended_probation_is_flagged_on_dashboard_and_grid(): void
    {
        [$company] = $this->baseData();
        $late = $this->employee($company, ['full_name' => 'Late']);
        $soon = $this->employee($company, ['full_name' => 'Soon']);
        Onboarding::create(['employee_id' => $late->id, 'probation_end_date' => now()->subDays(2)->toDateString(), 'probation_result' => 'Extend']);
        Onboarding::create(['employee_id' => $soon->id, 'probation_end_date' => now()->addDays(3)->toDateString(), 'probation_result' => 'Extend']);

        $dash = $this->props('/dashboard');
        $this->assertSame(['Late'], array_column($dash['onboarding']['overdue'], 'name'));
        $this->assertSame(['Soon'], array_column($dash['onboarding']['endingSoon'], 'name'));

        $rows = collect($this->props('/onboarding')['rows'])->keyBy('name');
        $this->assertTrue($rows['Late']['overdue']);
        $this->assertFalse($rows['Late']['ending']);
        $this->assertTrue($rows['Soon']['ending']);
        $this->assertFalse($rows['Soon']['overdue']);
    }

    // ------------------------------------------------------------------ R3

    public function test_r3_cannot_complete_with_every_clearance_item_switched_off(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company);
        $admin = $this->user('admin');
        $this->actingAs($admin)->post('/offboarding', ['employee_id' => $e->id, 'type' => 'Resignation']);
        $case = Offboarding::firstOrFail();
        foreach (ChecklistItem::where('list', 'offboarding')->get() as $i) {
            $this->set($e, 'offboarding', $i->key, 'Done');
        }
        ChecklistItem::where('list', 'offboarding')->get()->each->update(['is_active' => false]);

        $this->actingAs($admin)->post("/offboarding/{$case->id}/complete")->assertSessionHas('error');
        $this->assertSame('offboarding', $e->fresh()->status);
        $this->assertSame('in_progress', $case->fresh()->status);
    }

    public function test_r3_pending_value_on_a_switched_off_item_blocks_completion(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company);
        $admin = $this->user('admin');
        $this->actingAs($admin)->post('/offboarding', ['employee_id' => $e->id, 'type' => 'Resignation']);
        $case = Offboarding::firstOrFail();
        foreach (ChecklistItem::where('list', 'offboarding')->pluck('key') as $key) {
            $this->set($e, 'offboarding', $key, $key === 'assets_returned' ? 'Pending' : 'Done');
        }
        $assets = ChecklistItem::where('list', 'offboarding')->where('key', 'assets_returned')->firstOrFail();
        $this->actingAs($admin)->put("/settings/checklists/{$assets->id}", ['label' => $assets->label, 'is_active' => false, 'sort_order' => $assets->sort_order]);

        $this->actingAs($admin)->post("/offboarding/{$case->id}/complete")
            ->assertSessionHas('error', fn ($msg) => str_contains($msg, $assets->label));
        $this->assertSame('offboarding', $e->fresh()->status);

        $this->set($e, 'offboarding', 'assets_returned', 'Done');
        $this->actingAs($admin)->post("/offboarding/{$case->id}/complete")->assertSessionHas('success');
        $this->assertSame('exited', $e->fresh()->status);
    }

    // ------------------------------------------------------------------ R4

    public function test_r4_exited_employee_cannot_be_put_back_into_offboarding(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company, ['status' => 'exited', 'exit_date' => '2026-01-31']);
        $this->actingAs($this->user('hr'))->post('/offboarding', ['employee_id' => $e->id, 'type' => 'Other'])
            ->assertSessionHasErrors('employee_id');
        $this->assertSame('exited', $e->fresh()->status);
        $this->assertSame(0, Offboarding::count());
    }

    public function test_r4_deleted_employee_cannot_be_offboarded(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company);
        $e->delete();
        $this->actingAs($this->user('hr'))->post('/offboarding', ['employee_id' => $e->id, 'type' => 'Other'])
            ->assertSessionHasErrors('employee_id');
        $this->assertSame(0, Offboarding::count());
    }

    public function test_r4_cancel_only_reactivates_someone_who_was_offboarding(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company);
        $hr = $this->user('hr');
        $this->actingAs($hr)->post('/offboarding', ['employee_id' => $e->id, 'type' => 'Resignation']);
        $case = Offboarding::firstOrFail();
        $this->actingAs($hr)->post("/offboarding/{$case->id}/cancel")->assertSessionHas('success');
        $this->assertSame('cancelled', $case->fresh()->status);
        $this->assertSame('active', $e->fresh()->status);
        $this->actingAs($hr)->post("/offboarding/{$case->id}/complete")->assertSessionHas('error');

        // an old open case for someone who has since exited never makes them active
        $x = $this->employee($company, ['status' => 'exited']);
        $old = Offboarding::create(['employee_id' => $x->id, 'type' => 'Other', 'status' => 'in_progress']);
        $this->actingAs($hr)->post("/offboarding/{$old->id}/cancel")->assertSessionHas('success', 'Offboarding cancelled.');
        $this->assertSame('exited', $x->fresh()->status);
    }

    // ------------------------------------------------------------------ R5

    public function test_r5_offboarding_tile_skips_deleted_employees(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company);
        $keep = $this->employee($company);
        $admin = $this->user('admin');
        $this->actingAs($admin)->post('/offboarding', ['employee_id' => $e->id, 'type' => 'Resignation']);
        $this->actingAs($admin)->post('/offboarding', ['employee_id' => $keep->id, 'type' => 'Resignation']);
        $e->delete();

        $this->assertSame(1, $this->props('/dashboard')['totals']['offboarding']);

        // and the deleted person's case can't be completed (no crash, a clear message)
        $case = Offboarding::where('employee_id', $e->id)->firstOrFail();
        $this->actingAs($admin)->post("/offboarding/{$case->id}/complete")->assertSessionHas('error');
        $this->assertSame('in_progress', $case->fresh()->status);
    }

    // ------------------------------------------------------------------ F2

    public function test_f2_new_item_key_is_unique_across_lists_and_grid_reads_the_document(): void
    {
        [$company] = $this->baseData();
        $admin = $this->user('admin');
        $e = $this->employee($company, ['full_name' => 'Alice']);
        Onboarding::create(['employee_id' => $e->id, 'probation_result' => 'Pending']);

        $this->actingAs($admin)->post('/settings/checklists', ['list' => 'onboarding', 'label' => 'Policy acknowledgment', 'option_set' => 'yes_no'])
            ->assertSessionHasNoErrors();
        $step = ChecklistItem::where('list', 'onboarding')->where('label', 'Policy acknowledgment')->firstOrFail();
        $doc = ChecklistItem::where('list', 'document')->where('key', 'policy_acknowledgment')->firstOrFail();
        $this->assertSame('policy_acknowledgment_2', $step->key);
        $this->assertSame('Policy acknowledgment', $step->label);

        $this->actingAs($admin)->put("/checklist/{$e->id}/{$doc->id}", ['status' => 'Yes'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put("/checklist/{$e->id}/{$step->id}", ['status' => 'No'])->assertSessionHasNoErrors();

        $values = $this->props('/onboarding')['rows'][0]['values'];
        $this->assertSame('Yes', $values['policy_acknowledgment']);
        $this->assertSame('No', $values['policy_acknowledgment_2']);
    }

    public function test_f2_legal_document_value_wins_over_an_existing_clashing_step(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company);
        Onboarding::create(['employee_id' => $e->id, 'probation_result' => 'Pending']);
        // a clash created before the fix (directly, not through Settings)
        ChecklistItem::create(['list' => 'onboarding', 'key' => 'nda_signed', 'label' => 'NDA step', 'option_set' => 'yes_no', 'sort_order' => 999, 'is_active' => true]);
        $this->set($e, 'document', 'nda_signed', 'Yes');
        $this->set($e, 'onboarding', 'nda_signed', 'No');

        $this->assertSame('Yes', $this->props('/onboarding')['rows'][0]['values']['nda_signed']);
    }

    // ------------------------------------------------------------------ F3

    public function test_f3_old_date_value_is_logged_as_y_m_d(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company, ['start_date' => '2026-07-01']);
        $e->update(['start_date' => '2026-07-02']);

        $c = ActivityLog::where('subject_type', 'Employee')->where('event', 'updated')->latest('id')->firstOrFail()->changes;
        $this->assertSame(['2026-07-01', '2026-07-02'], $c['start_date']);
    }

    // ------------------------------------------------------------------ L1

    public function test_l1_plan_item_and_checkin_edits_are_logged_with_old_and_new_values(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company, ['start_date' => '2026-09-01']);
        $hr = $this->user('hr');
        $this->actingAs($hr)->post("/employees/{$e->id}/plan")->assertRedirect();
        $plan = $e->probationPlan()->with('items', 'checkins')->firstOrFail();
        $item = $plan->items->firstWhere('section', 'behaviour');
        $checkin = $plan->checkins->firstWhere('kind', 'day30');

        $this->actingAs($hr)->put("/employees/{$e->id}/plan", ['items' => [['id' => $item->id, 'evaluation' => 'Meets', 'manager_notes' => 'v1']]])->assertSessionHasNoErrors();
        $this->actingAs($hr)->put("/employees/{$e->id}/plan", [
            'items' => [['id' => $item->id, 'evaluation' => 'Needs Improvement', 'manager_notes' => 'v2']],
            'checkins' => [['id' => $checkin->id, 'held_on' => '2026-10-01', 'manager_summary' => 'Fine']],
        ])->assertSessionHasNoErrors();

        $log = ActivityLog::where('subject_type', 'ProbationPlanItem')->where('subject_id', $item->id)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($e->id, $log->employee_id);
        $this->assertSame(['v1', 'v2'], $log->changes['manager_notes']);
        $this->assertSame(['Meets', 'Needs Improvement'], $log->changes['evaluation']);

        $c = ActivityLog::where('subject_type', 'ProbationCheckin')->where('subject_id', $checkin->id)->latest('id')->firstOrFail();
        $this->assertSame($e->id, $c->employee_id);
        $this->assertSame([null, '2026-10-01'], $c->changes['held_on']);

        $this->assertSame(0, ActivityLog::where('employee_id', $e->id)->whereNull('changes')->where('event', 'updated')->count(), 'no changes:null rows');
    }

    public function test_l1_company_changes_are_logged(): void
    {
        [$company] = $this->baseData();
        $company->update(['employee_code_digits' => 4]);
        $log = ActivityLog::where('subject_type', 'Company')->where('event', 'updated')->firstOrFail();
        $this->assertSame([3, 4], $log->changes['employee_code_digits']);
    }

    // ------------------------------------------------------------------ L2

    public function test_l2_model_defaults_match_migrations(): void
    {
        $this->assertSame(0, (new \App\Models\ProbationPlanItem)->sort_order);
        $this->assertSame(0, (new \App\Models\ProbationCheckin)->sort_order);
        $this->assertSame(3, (new \App\Models\Company)->employee_code_digits);
    }
}
