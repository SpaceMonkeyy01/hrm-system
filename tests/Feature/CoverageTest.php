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

/** Behaviour the architecture promises: login limits, the audit log, soft delete, cancel, exports, plan mapping. */
class CoverageTest extends TestCase
{
    use RefreshDatabase;

    private function set(Employee $e, string $list, string $key, ?string $status): void
    {
        $item = ChecklistItem::where('list', $list)->where('key', $key)->firstOrFail();
        ChecklistEntry::updateOrCreate(['employee_id' => $e->id, 'checklist_item_id' => $item->id], ['status' => $status]);
    }

    // T3
    public function test_login_is_rate_limited_after_5_wrong_tries(): void
    {
        $this->user('hr', ['email' => 'rl@test.local', 'password' => 'right-pass']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'rl@test.local', 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        // 6th try, even with the right password and different casing, is refused
        $this->post('/login', ['email' => 'RL@test.local', 'password' => 'right-pass'])->assertSessionHasErrors('email');
        $this->assertStringStartsWith('Too many attempts.', session('errors')->first('email'));
        $this->assertGuest();
    }

    // T4
    public function test_cell_change_logs_old_and_new_value(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company);
        $item = ChecklistItem::where('key', 'nda_signed')->first();
        $hr = $this->user('hr');

        $this->actingAs($hr)->put("/checklist/{$e->id}/{$item->id}", ['status' => 'No']);
        $this->actingAs($hr)->put("/checklist/{$e->id}/{$item->id}", ['status' => 'Yes']);

        $log = ActivityLog::where('subject_type', 'ChecklistEntry')->latest('id')->first();
        $this->assertSame('updated', $log->event);
        $this->assertSame(['No', 'Yes'], $log->changes['status']);
        $this->assertSame($hr->id, $log->user_id);
    }

    // T5
    public function test_admin_delete_is_a_soft_delete_and_logged(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company);

        $this->actingAs($this->user('admin'))->delete("/employees/{$e->id}")->assertRedirect('/employees');

        $this->assertSoftDeleted('employees', ['id' => $e->id]);
        $this->assertTrue(ActivityLog::where('subject_type', 'Employee')->where('subject_id', $e->id)->where('event', 'deleted')->exists());
        $this->actingAs($this->user('viewer'))->get('/employees')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('employees.total', 0));
    }

    // T6
    public function test_saving_an_unchanged_cell_writes_nothing(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company);
        $item = ChecklistItem::where('key', 'nda_signed')->first();
        $hr = $this->user('hr');

        $this->actingAs($hr)->put("/checklist/{$e->id}/{$item->id}", ['status' => 'Yes']);
        $this->assertSame(1, ActivityLog::where('subject_type', 'ChecklistEntry')->count());
        $stampBefore = ChecklistEntry::first()->updated_at;

        $this->travel(5)->minutes();
        $this->actingAs($hr)->put("/checklist/{$e->id}/{$item->id}", ['status' => 'Yes'])->assertRedirect();

        $this->assertSame(1, ActivityLog::where('subject_type', 'ChecklistEntry')->count());
        $this->assertEquals($stampBefore, ChecklistEntry::first()->updated_at);
    }

    // T7
    public function test_cancel_offboarding_returns_employee_to_active(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company);
        $hr = $this->user('hr');

        $this->actingAs($hr)->post('/offboarding', ['employee_id' => $e->id, 'type' => 'Resignation']);
        $case = Offboarding::firstOrFail();
        $this->actingAs($hr)->post("/offboarding/{$case->id}/cancel")->assertSessionHas('success');

        $this->assertSame('cancelled', $case->fresh()->status);
        $this->assertSame('active', $e->fresh()->status);

        // a cancelled case cannot then be completed
        $this->actingAs($hr)->post("/offboarding/{$case->id}/complete")->assertSessionHas('error');
        $this->assertSame('cancelled', $case->fresh()->status);
        $this->assertSame('active', $e->fresh()->status);
    }

    // T8
    public function test_csv_exports_match_the_screens(): void
    {
        [$company] = $this->baseData();
        $a = $this->employee($company, ['full_name' => 'Alpha']);
        $b = $this->employee($company, ['full_name' => 'Beta']);
        $x = $this->employee($company, ['full_name' => 'Gone', 'status' => 'exited']);
        foreach ([$a, $b, $x] as $p) {
            Onboarding::create(['employee_id' => $p->id, 'probation_result' => 'Pass']);
        }
        foreach (ChecklistItem::where('list', 'document')->pluck('key') as $k) {
            $this->set($a, 'document', $k, 'Yes');
        }
        foreach (['orientation_1', 'orientation_2', 'orientation_3'] as $k) {
            $this->set($a, 'onboarding', $k, 'Done');
        }
        foreach (['day30_checkin', 'day60_checkin', 'day90_evaluation'] as $k) {
            $this->set($a, 'onboarding', $k, 'Yes');
        }
        $this->set($b, 'onboarding', 'orientation_1', 'Done');
        // a switched-off optional item is neither a column nor counted
        $opt = ChecklistItem::where('list', 'document')->whereNotIn('key', HrRules::REQUIRED_KEYS['document'])->first();
        $opt->update(['is_active' => false]);
        $this->set($b, 'document', $opt->key, 'No');

        $viewer = $this->user('viewer');
        $screenDocs = [];
        $this->actingAs($viewer)->get('/documents')->assertInertia(function (AssertableInertia $p) use (&$screenDocs) {
            $screenDocs = collect($p->toArray()['props']['rows'])->pluck('status', 'name')->all();
        });
        $screenOnb = [];
        $this->actingAs($viewer)->get('/onboarding')->assertInertia(function (AssertableInertia $p) use (&$screenOnb) {
            $screenOnb = collect($p->toArray()['props']['rows'])->mapWithKeys(fn ($r) => [$r['name'] => [$r['orientation'], $r['documents'], $r['status']]])->all();
        });

        $docs = $this->csv($this->actingAs($viewer)->get('/export/documents')->assertOk()->streamedContent());
        $this->assertSame($screenDocs, collect($docs)->pluck('Status', 'Name')->all());
        $this->assertSame(['Alpha' => 'Complete', 'Beta' => 'Pending'], $screenDocs);
        $this->assertArrayNotHasKey($opt->label, $docs[0]);

        $onb = $this->csv($this->actingAs($viewer)->get('/export/onboarding')->assertOk()->streamedContent());
        $this->assertSame($screenOnb, collect($onb)->mapWithKeys(fn ($r) => [$r['Name'] => [$r['Orientation'], $r['Documents'], $r['Onboarding status']]])->all());
        $this->assertSame(['Onboarding Complete', 'Onboarding In Progress'], array_column(array_values($screenOnb), 2));

        $emp = $this->csv($this->actingAs($viewer)->get('/export/employees')->assertOk()->streamedContent());
        $this->assertCount(3, $emp, 'employees export includes exited people');
        // CNIC is private: viewers don't get the column, HR does.
        $this->assertArrayNotHasKey('CNIC', $emp[0]);
        $this->assertArrayHasKey('CNIC', $this->csv($this->actingAs($this->user('hr'))->get('/export/employees')->assertOk()->streamedContent())[0]);
    }

    // T9
    public function test_day60_and_day90_checkins_tick_via_config_mapping(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company, ['start_date' => '2026-07-01']);
        $hr = $this->user('hr');
        $this->actingAs($hr)->post("/employees/{$e->id}/plan");
        $plan = $e->probationPlan()->with('checkins')->first();
        $rows = $plan->checkins->map(fn ($ch) => ['id' => $ch->id, 'held_on' => $ch->kind === 'day30' ? null : '2026-09-01'])->all();

        $this->actingAs($hr)->put("/employees/{$e->id}/plan", ['checkins' => $rows])->assertSessionHas('success');

        $val = fn ($k) => ChecklistEntry::where('employee_id', $e->id)->where('checklist_item_id', ChecklistItem::where('key', $k)->value('id'))->value('status');
        $this->assertNull($val('day30_checkin'), 'undated check-in does not tick');
        $this->assertSame('Yes', $val('day60_checkin'));
        $this->assertSame('Yes', $val('day90_evaluation'));

        // the mapping is read from config, not hard-coded
        config(['hrm.plan.checkin_items.day30' => 'email_created']);
        $day30 = $plan->checkins->firstWhere('kind', 'day30');
        $this->actingAs($hr)->put("/employees/{$e->id}/plan", ['checkins' => [['id' => $day30->id, 'held_on' => '2026-08-01']]]);
        $this->assertSame('Yes', $val('email_created'));
        $this->assertNull($val('day30_checkin'));
    }

    // T9
    public function test_no_required_key_can_be_switched_off(): void
    {
        $this->baseData();
        $admin = $this->user('admin');
        foreach (HrRules::REQUIRED_KEYS as $list => $keys) {
            foreach ($keys as $key) {
                $item = ChecklistItem::where('list', $list)->where('key', $key)->firstOrFail();
                $this->actingAs($admin)->put("/settings/checklists/{$item->id}", ['label' => 'Renamed', 'is_active' => false, 'sort_order' => 1])
                    ->assertSessionHasErrors('is_active');
                $this->assertTrue($item->fresh()->is_active, $key);
            }
        }
        // an optional item can be switched off
        $opt = ChecklistItem::where('list', 'document')->whereNotIn('key', HrRules::REQUIRED_KEYS['document'])->first();
        $this->actingAs($admin)->put("/settings/checklists/{$opt->id}", ['label' => $opt->label, 'is_active' => false, 'sort_order' => 1])->assertSessionHasNoErrors();
        $this->assertFalse($opt->fresh()->is_active);
    }

    /** Parses a UTF-8 BOM CSV export into rows keyed by header. */
    private function csv(string $body): array
    {
        $lines = array_map(fn ($l) => str_getcsv($l, escape: ''), array_filter(explode("\n", substr($body, 3))));
        $head = array_shift($lines);

        return array_map(fn ($l) => array_combine($head, $l), $lines);
    }
}
