<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\AttendanceBoard;
use App\Services\AttendanceRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Clock in / out on the My page (AttendanceClock). Fake people only; today is Wed 20 May 2026. */
class ClockInTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $me;

    private User $login;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-05-20 09:05'));
        config(['hrm.clock.office_ips' => ['203.0.113.7', '198.51.100.0/24']]);
        WorkSchedule::create(['name' => '9 to 5', 'type' => 'fixed', 'start_time' => '09:00', 'end_time' => '17:00', 'late_grace_minutes' => 10,
            'full_day_minutes' => 480, 'half_day_minutes' => 240, 'working_days' => [1, 2, 3, 4, 5], 'is_default' => true]);
        $this->me = $this->employee($this->company, ['full_name' => 'Fake Me', 'start_date' => '2025-01-01']);
        $this->login = $this->user('employee', ['employee_id' => $this->me->id]);
    }

    private function press(string $action, string $ip = '10.0.0.5', array $extra = [])
    {
        return $this->actingAs($this->login)->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from('/me')->post('/me/clock', ['action' => $action] + $extra);
    }

    private function punches()
    {
        return AttendancePunch::query()->where('source', 'app')->orderBy('punched_at')->get();
    }

    private function day(string $date): array
    {
        $now = CarbonImmutable::now();
        $board = new AttendanceBoard(Employee::query()->whereKey($this->me->id)->get(), $date, $date);

        return $board->day($this->me->fresh(), $date, $now);
    }

    public function test_clock_in_and_out_from_home_is_one_remote_session(): void
    {
        $this->press('in', '10.0.0.5', ['latitude' => 31.5204, 'longitude' => 74.3587, 'accuracy' => 20])
            ->assertRedirect('/me')->assertSessionHas('success', 'Clocked in at 09:05 (remote).');
        $this->travelTo(CarbonImmutable::parse('2026-05-20 17:10'));
        $this->press('out')->assertSessionHas('success', 'Clocked out at 17:10 (remote).');

        $p = $this->punches();
        $this->assertSame(['In', 'Out'], $p->pluck('method')->all());
        $this->assertSame((string) $this->me->id, $p[0]->device_user_id);
        $this->assertSame('remote', $p[0]->place);
        $this->assertSame('10.0.0.5', $p[0]->ip);
        $this->assertEqualsWithDelta(31.5204, $p[0]->latitude, 0.0001);
        $this->assertSame(20, $p[0]->accuracy);

        // No machine number or HR.my ID, yet the day counts: app clock-ins link by the employee id.
        $day = $this->day('2026-05-20');
        $this->assertSame('Present', $day['status']);
        $this->assertSame(['09:05', '17:10'], [$day['in'], $day['out']]);
        $this->assertSame('remote', $day['where']);
        $this->assertSame('app', $day['sessions'][0]['source']);
    }

    public function test_clock_in_from_an_office_ip_counts_as_office(): void
    {
        $this->press('in', '198.51.100.40')->assertSessionHas('success', 'Clocked in at 09:05 (at the office).');
        $this->travelTo(CarbonImmutable::parse('2026-05-20 13:00'));
        $this->press('out', '198.51.100.40');
        // Evening session from home.
        $this->travelTo(CarbonImmutable::parse('2026-05-20 20:00'));
        $this->press('in', '10.0.0.5');
        $this->travelTo(CarbonImmutable::parse('2026-05-20 23:00'));
        $this->press('out', '10.0.0.5');

        $day = $this->day('2026-05-20');
        $this->assertSame('both', $day['where']);
        $this->assertSame([['09:05', '13:00', 'office'], ['20:00', '23:00', 'remote']],
            array_map(fn ($s) => [$s['in'], $s['out'], $s['in_where']], $day['sessions']));
        $this->assertSame(415, $day['worked']);
        $this->assertSame('09:05-13:00 office, 20:00-23:00 remote', AttendanceRules::sessionsText($day['sessions']));
    }

    public function test_a_second_tap_or_a_stale_button_is_refused_not_flipped(): void
    {
        $this->press('in');
        $this->travelTo(CarbonImmutable::parse('2026-05-20 09:05:30'));
        $this->press('out')->assertSessionHasErrors(['clock' => 'Already saved a moment ago.']);
        $this->travelTo(CarbonImmutable::parse('2026-05-20 10:00'));
        $this->press('in')->assertSessionHasErrors('clock');      // a tab still showing "Clock in"
        $this->assertCount(1, $this->punches());

        $this->press('out');
        $this->travelTo(CarbonImmutable::parse('2026-05-20 11:00'));
        $this->press('out')->assertSessionHasErrors(['clock' => 'You\'re not clocked in, so there is nothing to clock out of.']);
        $this->assertCount(2, $this->punches());
    }

    public function test_night_shift_clock_out_stays_on_the_day_it_started(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-20 21:00'));
        $this->press('in');
        $this->travelTo(CarbonImmutable::parse('2026-05-21 06:00'));
        $this->get('/me')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('clock.open.time', '21:00')->where('clock.date', '2026-05-20'));
        $this->press('out');

        $this->assertSame(['2026-05-20', '2026-05-20'], $this->punches()->map(fn ($p) => $p->work_date->toDateString())->all());
        $day = $this->day('2026-05-20');
        $this->assertSame(['21:00', '30:00'], [$day['in'], $day['out']]);
        $this->assertSame(540, $day['worked']);
    }

    public function test_a_clock_in_left_open_too_long_stays_missing_and_the_next_press_starts_a_new_day(): void
    {
        $this->press('in');                                          // forgot to clock out on Wednesday
        $this->travelTo(CarbonImmutable::parse('2026-05-21 09:00'));
        $this->get('/me')->assertInertia(fn (AssertableInertia $page) => $page->where('clock.open', null)->where('clock.date', '2026-05-21'));
        $this->press('out')->assertSessionHasErrors('clock');
        $this->press('in')->assertSessionHasNoErrors();

        $this->assertSame(['2026-05-20', '2026-05-21'], $this->punches()->map(fn ($p) => $p->work_date->toDateString())->all());
        $this->assertSame('Missing punch', $this->day('2026-05-20')['status']);
    }

    public function test_switched_off_left_staff_and_unlinked_logins_cannot_clock_in(): void
    {
        config(['hrm.clock.enabled' => false]);
        $this->press('in')->assertSessionHasErrors('clock');
        $this->get('/me')->assertInertia(fn (AssertableInertia $page) => $page->where('clock.enabled', false));
        config(['hrm.clock.enabled' => true]);

        $this->me->update(['exit_date' => '2026-05-19']);
        $this->press('in')->assertSessionHasErrors('clock');

        $hr = $this->user('hr');                                     // no employee record
        $this->actingAs($hr)->post('/me/clock', ['action' => 'in'])->assertForbidden();
        $this->assertCount(0, $this->punches());
    }

    public function test_bad_input_is_rejected(): void
    {
        $this->press('sideways')->assertSessionHasErrors('action');
        $this->press('in', '10.0.0.5', ['latitude' => 120, 'longitude' => 10])->assertSessionHasErrors('latitude');
        $this->press('in', '10.0.0.5', ['latitude' => 31.5])->assertSessionHasErrors('longitude');
        $this->assertCount(0, $this->punches());
    }

    public function test_people_who_clock_in_on_the_app_are_absent_on_days_they_dont(): void
    {
        // Monday clocked in, Tuesday nothing: Tuesday is Absent, not "Not linked" or "No data".
        AttendancePunch::create(['source' => 'app', 'device_user_id' => (string) $this->me->id, 'punched_at' => '2026-05-18 09:00:00',
            'work_date' => '2026-05-18', 'method' => 'In', 'place' => 'remote']);
        $this->assertSame('Absent', $this->day('2026-05-19')['status']);

        // Someone who has never clocked in and has no numbers stays "Not linked".
        $other = $this->employee($this->company, ['full_name' => 'Fake Other', 'start_date' => '2025-01-01']);
        $board = new AttendanceBoard(Employee::query()->whereKey($other->id)->get(), '2026-05-19', '2026-05-19');
        $this->assertSame('Not linked', $board->day($other, '2026-05-19', CarbonImmutable::now())['status']);
    }

    public function test_my_page_and_hr_attendance_show_clock_ins(): void
    {
        $this->press('in', '203.0.113.7');
        $this->actingAs($this->login)->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('/me')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clock.open.time', '09:05')->where('clock.open.place', 'office')->where('clock.place', 'office')
                ->has('clock.presses', 1)->where('attendance.linked', true));

        $this->actingAs($this->user('hr'))->get('/attendance?date=2026-05-20')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.0.in', '09:05')->where('rows.0.in_where', 'office')
                ->where('rows.0.employee.linked', true));
    }
}
