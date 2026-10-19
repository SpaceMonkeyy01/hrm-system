<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\WorkSchedule;
use App\Services\LeaveBalances;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Leave allowances: yearly (pro-rated, lapsing) and per leave. Policy numbers from the migration. Fake people only. */
class LeaveBalancesTest extends TestCase
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

    private function type(string $name): LeaveType
    {
        return LeaveType::where('name', $name)->firstOrFail();
    }

    private function leave(int $employeeId, string $type, string $from, string $to, string $part = 'full')
    {
        return $this->actingAs($this->user('hr'))->post('/leave', ['employee_id' => $employeeId, 'leave_type_id' => $this->type($type)->id,
            'start_date' => $from, 'end_date' => $to, 'part' => $part]);
    }

    public function test_policy_allowances_are_set_and_wedding_exists(): void
    {
        $this->assertSame([14.0, 10.0, 6.0], [$this->type('Annual')->yearly_days, $this->type('Casual')->yearly_days, $this->type('Sick')->yearly_days]);
        $this->assertSame([90, 14, 7], [$this->type('Maternity')->per_leave_days, $this->type('Wedding')->per_leave_days, $this->type('Paternity')->per_leave_days]);
        $this->assertNull($this->type('Unpaid')->yearly_days);
    }

    public function test_allowance_is_pro_rated_from_the_start_date(): void
    {
        $this->assertSame(14.0, LeaveBalances::allowance(14, null, 2026));
        $this->assertSame(14.0, LeaveBalances::allowance(14, CarbonImmutable::parse('2020-03-01'), 2026));
        $this->assertSame(14.0, LeaveBalances::allowance(14, CarbonImmutable::parse('2026-01-01'), 2026));
        // 1 Jul: 184 of 365 days left -> 7.06 -> 7
        $this->assertSame(7.0, LeaveBalances::allowance(14, CarbonImmutable::parse('2026-07-01'), 2026));
        // 1 Oct: 92/365 of 10 -> 2.52 -> 2.5
        $this->assertSame(2.5, LeaveBalances::allowance(10, CarbonImmutable::parse('2026-10-01'), 2026));
        $this->assertSame(0.0, LeaveBalances::allowance(14, CarbonImmutable::parse('2027-02-01'), 2026));
        // Next year: the full allowance again (unused days lapse, nothing carries)
        $this->assertSame(14.0, LeaveBalances::allowance(14, CarbonImmutable::parse('2026-07-01'), 2027));
    }

    public function test_recording_leave_shows_what_is_left_and_warns_when_over(): void
    {
        $e = $this->employee($this->company, ['start_date' => '2024-01-01']);
        // Mon 11 - Fri 15 May: 5 working days of Sick (6 a year)
        $this->leave($e->id, 'Sick', '2026-05-11', '2026-05-15')->assertSessionHas('success', "Recorded 5 days of leave for {$e->full_name}. Sick: 1 of 6 days left in 2026.");
        $this->leave($e->id, 'Sick', '2026-05-18', '2026-05-19')->assertSessionHas('success', "Recorded 2 days of leave for {$e->full_name}. Sick: 1 day over the 6 for 2026.");
        $this->leave($e->id, 'Casual', '2026-05-20', '2026-05-20', 'first_half')->assertSessionHas('success', "Recorded 0.5 days of leave for {$e->full_name}. Casual: 9.5 of 10 days left in 2026.");

        // Per leave: calendar days. Wedding 14: 1-18 Jun is 18 days, 4 over (allowed, with a warning).
        $this->leave($e->id, 'Wedding', '2026-06-01', '2026-06-18')->assertSessionHas('success', fn ($m) => str_ends_with($m, 'Longer than the 14-day Wedding allowance (by 4 days).'));
        $this->leave($e->id, 'Paternity', '2026-07-06', '2026-07-12')->assertSessionHas('success', fn ($m) => ! str_contains($m, 'allowance'));
        $this->assertSame(5, LeaveRecord::count());
    }

    public function test_balances_on_the_leave_page_profile_and_my_page(): void
    {
        $old = $this->employee($this->company, ['full_name' => 'Old Hand', 'start_date' => '2020-01-01']);
        $new = $this->employee($this->company, ['full_name' => 'New Joiner', 'start_date' => '2026-07-01']);
        $this->leave($old->id, 'Annual', '2026-05-11', '2026-05-15');

        $this->actingAs($this->user('viewer'))->get('/leave')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('balances.year', 2026)
            ->where('balances.types', fn ($t) => collect($t)->pluck('name')->all() === ['Annual', 'Casual', 'Sick'])
            ->where('balances.rows', function ($rows) {
                $rows = collect($rows)->keyBy('name');

                return $rows['Old Hand']['balances'][0] == ['type_id' => $this->type('Annual')->id, 'type' => 'Annual', 'allowance' => 14, 'used' => 5, 'left' => 9]
                    && $rows['New Joiner']['balances'][0]['allowance'] == 7 && $rows['New Joiner']['balances'][1]['allowance'] == 5;
            }));

        $this->actingAs($this->user('hr'))->get("/employees/{$old->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('leave.balances.0.left', 9)->where('leave.balances.2.type', 'Sick'));
        $login = $this->user('employee', ['employee_id' => $old->id]);
        $this->actingAs($login)->get('/me')->assertInertia(fn (AssertableInertia $p) => $p->where('leave.balances.0.used', 5));
    }

    public function test_leave_across_new_year_is_split_between_the_years(): void
    {
        $e = $this->employee($this->company, ['start_date' => '2020-01-01']);
        // Mon 28 Dec 2026 - Fri 8 Jan 2027: 4 working days in 2026 (28-31), 6 in 2027 (1, 4-8)
        $this->leave($e->id, 'Annual', '2026-12-28', '2027-01-08')->assertSessionHasNoErrors();
        $this->assertSame(10.0, LeaveRecord::sole()->days);
        $this->assertSame(4.0, LeaveBalances::forEmployee($e, 2026)[0]['used']);
        $this->assertSame(6.0, LeaveBalances::forEmployee($e, 2027)[0]['used']);
    }

    public function test_hr_sets_allowances_on_leave_types(): void
    {
        $hr = $this->user('hr');
        $type = $this->type('Bereavement');
        $base = ['name' => 'Bereavement', 'is_paid' => true, 'is_active' => true];
        $this->actingAs($hr)->put("/leave/types/{$type->id}", $base + ['yearly_days' => 3, 'per_leave_days' => 3])->assertSessionHasErrors('yearly_days');
        $this->actingAs($hr)->put("/leave/types/{$type->id}", $base + ['yearly_days' => 2.25])->assertSessionHasErrors('yearly_days');
        $this->actingAs($hr)->put("/leave/types/{$type->id}", $base + ['per_leave_days' => 3])->assertSessionHasNoErrors();
        $this->assertSame([null, 3], [$type->fresh()->yearly_days, $type->fresh()->per_leave_days]);
        $this->actingAs($hr)->put("/leave/types/{$type->id}", $base + ['yearly_days' => 2.5])->assertSessionHasNoErrors();
        $this->assertSame([2.5, null], [$type->fresh()->yearly_days, $type->fresh()->per_leave_days]);
        $this->actingAs($this->user('viewer'))->put("/leave/types/{$type->id}", $base)->assertForbidden();
    }
}
