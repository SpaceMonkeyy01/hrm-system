<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Onboarding;
use App\Services\HrRules;
use App\Services\Reminders;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** The 90-day plan switched off, and HR setting the probation length and check-in days. Fake people only. */
class OnboardingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00'));
    }

    private function hire(?string $start, array $onboarding = []): Employee
    {
        $e = $this->employee($this->company, ['start_date' => $start]);
        Onboarding::startFor($e)->update($onboarding);

        return $e;
    }

    public function test_the_90_day_plan_can_be_switched_off(): void
    {
        config(['hrm.probation_plan' => false]);
        $e = $this->hire('2026-09-06');
        $hr = $this->user('hr');

        $this->actingAs($hr)->get("/employees/{$e->id}/plan")->assertRedirect("/employees/{$e->id}#onboarding");
        $this->actingAs($hr)->post("/employees/{$e->id}/plan")->assertNotFound();
        $this->actingAs($hr)->get("/employees/{$e->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('planEnabled', false));
        $r = collect(Reminders::forDate(CarbonImmutable::today()))->firstWhere('type', 'checkin_missed');
        $this->assertSame("/employees/{$e->id}#onboarding", $r['link']);
    }

    public function test_hr_sets_probation_length_and_check_in_days(): void
    {
        $admin = $this->user('admin');
        $ok = ['days' => 120, 'checkins' => ['day30_checkin' => 45, 'day60_checkin' => 90, 'day90_evaluation' => 120]];

        $this->actingAs($this->user('hr'))->put('/settings/probation', $ok)->assertForbidden();
        $this->actingAs($admin)->put('/settings/probation', ['checkins' => ['day30_checkin' => 60, 'day60_checkin' => 30, 'day90_evaluation' => 90]] + $ok)
            ->assertSessionHasErrors('checkins');
        $this->actingAs($admin)->put('/settings/probation', ['checkins' => ['day30_checkin' => 45, 'day60_checkin' => 90, 'day90_evaluation' => 150]] + $ok)
            ->assertSessionHasErrors('checkins.day90_evaluation');
        $this->actingAs($admin)->put('/settings/probation', $ok)->assertSessionHas('success', 'Saved. Probation is now 120 days.');

        $this->assertSame(120, HrRules::probationDays());
        $this->assertSame(['day30_checkin' => 45, 'day60_checkin' => 90, 'day90_evaluation' => 120], HrRules::checkinOffsets());
        $this->actingAs($admin)->get('/settings/checklists')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('probation.days', 120)->where('probation.checkins.0.days', 45)->where('probation.checkins.0.label', '30-day check-in'));

        // A new joiner gets the new length; check-ins are due on the new days.
        $new = $this->hire('2026-10-01');
        $this->assertSame('2027-01-29', $new->onboarding()->first()->probation_end_date->toDateString());
        $this->assertSame('2026-11-15', HrRules::checkinDueDate($new->start_date, 'day30_checkin'));
    }

    public function test_changing_the_length_can_move_open_probations_still_on_the_old_one(): void
    {
        $admin = $this->user('admin');
        $onDefault = $this->hire('2026-09-01');                                                     // ends 30 Nov (90 days)
        $typed = $this->hire('2026-09-01', ['probation_end_date' => '2026-12-15']);                // set by hand
        $extended = $this->hire('2026-09-01', ['probation_result' => 'Extend', 'probation_end_date' => '2026-11-30']);
        $closed = $this->hire('2026-09-01', ['closed_at' => now()]);
        $form = ['days' => 180, 'checkins' => ['day30_checkin' => 30, 'day60_checkin' => 60, 'day90_evaluation' => 90]];

        $this->actingAs($admin)->put('/settings/probation', $form + ['update_open' => true])
            ->assertSessionHas('success', 'Saved. Probation is now 180 days. Moved the end date for 1 open probation.');

        $end = fn (Employee $e) => $e->onboarding()->first()->probation_end_date->toDateString();
        $this->assertSame('2027-02-28', $end($onDefault));
        $this->assertSame('2026-12-15', $end($typed));
        $this->assertSame('2026-11-30', $end($extended));
        $this->assertSame('2026-11-30', $end($closed));
    }
}
