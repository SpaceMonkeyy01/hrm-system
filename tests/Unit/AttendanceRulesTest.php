<?php

namespace Tests\Unit;

use App\Models\WorkSchedule;
use App\Services\AttendanceRules;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** One day's status from punches + schedule. 2026-05-13 is a Wednesday. */
class AttendanceRulesTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-05-20 12:00');
    }

    private function fixed(array $attrs = []): WorkSchedule
    {
        return new WorkSchedule($attrs + ['name' => 'Ten to six', 'type' => 'fixed', 'start_time' => '10:00', 'end_time' => '18:00',
            'late_grace_minutes' => 10, 'early_grace_minutes' => 0, 'full_day_minutes' => 480, 'half_day_minutes' => 240,
            'working_days' => [1, 2, 3, 4, 5], 'missing_checkout' => 'flag']);
    }

    private function day(array $punches, ?WorkSchedule $s, ?array $adj = null, string $date = '2026-05-13', ?CarbonImmutable $now = null): array
    {
        return AttendanceRules::day($date, $punches, $adj, $s, $now ?? $this->now);
    }

    public function test_on_time_full_day_is_present(): void
    {
        $d = $this->day(['18:02:05', '09:58:03'], $this->fixed());
        $this->assertSame(['Present', '09:58', '18:02', 484, 0, 0], [$d['status'], $d['in'], $d['out'], $d['worked'], $d['late'], $d['early']]);
    }

    public function test_late_only_after_the_grace_and_counted_from_the_start(): void
    {
        $this->assertSame(0, $this->day(['10:10:00', '18:00:00'], $this->fixed())['late']);
        $this->assertSame(11, $this->day(['10:11:00', '18:00:00'], $this->fixed())['late']);
        $this->assertSame(1, $this->day(['10:01:00', '18:00:00'], $this->fixed(['late_grace_minutes' => 0]))['late']);
    }

    public function test_early_leave_and_half_day(): void
    {
        $d = $this->day(['10:00:00', '16:05:00'], $this->fixed());
        $this->assertSame(['Present', 115], [$d['status'], $d['early']]);
        $d = $this->day(['10:00:00', '13:11:00'], $this->fixed());
        $this->assertSame('Half day', $d['status']);
        $this->assertSame(0, $this->day(['10:00:00', '17:50:00'], $this->fixed(['early_grace_minutes' => 15]))['early']);
    }

    public function test_double_taps_are_one_punch(): void
    {
        $d = $this->day(['10:00:00', '10:02:00', '10:04:30'], $this->fixed());
        $this->assertSame(1, $d['punches']);
        $this->assertSame('Missing punch', $d['status']);
    }

    public function test_forgotten_punch_out_flags_or_auto_checks_out(): void
    {
        $this->assertSame('Missing punch', $this->day(['10:00:00'], $this->fixed())['status']);

        $d = $this->day(['10:00:00'], $this->fixed(['missing_checkout' => 'auto']));
        $this->assertSame(['Present', '18:00', 'auto', 480], [$d['status'], $d['out'], $d['out_source'], $d['worked']]);
        // A lone punch after the end time is not a check-in to close.
        $this->assertSame('Missing punch', $this->day(['19:00:00'], $this->fixed(['missing_checkout' => 'auto']))['status']);
        // Today the person may still be in.
        $today = CarbonImmutable::parse('2026-05-13 15:00');
        $this->assertSame('In office', $this->day(['10:00:00'], $this->fixed(['missing_checkout' => 'auto']), null, '2026-05-13', $today)['status']);
    }

    public function test_hr_times_override_the_punches(): void
    {
        $d = $this->day(['10:00:00'], $this->fixed(), ['check_in' => null, 'check_out' => '18:00', 'note' => 'Forgot']);
        $this->assertSame(['Present', '10:00', 'punch', '18:00', 'manual', true, 'Forgot'],
            [$d['status'], $d['in'], $d['in_source'], $d['out'], $d['out_source'], $d['adjusted'], $d['note']]);
    }

    public function test_absent_day_off_not_in_yet_and_future(): void
    {
        $this->assertSame('Absent', $this->day([], $this->fixed())['status']);
        $this->assertSame('Day off', $this->day([], $this->fixed(), null, '2026-05-16')['status']);   // Saturday
        $sat = $this->day(['11:00:00', '15:00:00'], $this->fixed(), null, '2026-05-16');
        $this->assertSame(['Worked day off', 0], [$sat['status'], $sat['late']]);
        $morning = CarbonImmutable::parse('2026-05-13 09:00');
        $this->assertSame('Not in yet', $this->day([], $this->fixed(), null, '2026-05-13', $morning)['status']);
        $evening = CarbonImmutable::parse('2026-05-13 19:00');
        $this->assertSame('Absent', $this->day([], $this->fixed(), null, '2026-05-13', $evening)['status']);
        $this->assertNull($this->day([], $this->fixed(), null, '2026-06-01')['status']);
    }

    public function test_flexible_has_no_late_but_flags_short_hours(): void
    {
        $flex = new WorkSchedule(['name' => 'Flexible', 'type' => 'flexible', 'full_day_minutes' => 480, 'half_day_minutes' => 240,
            'working_days' => [1, 2, 3, 4, 5], 'missing_checkout' => 'flag']);
        $d = $this->day(['13:00:00', '19:30:00'], $flex);
        $this->assertSame(['Present', 0, 0, 90], [$d['status'], $d['late'], $d['early'], $d['short']]);
        $this->assertSame('Missing punch', $this->day(['13:00:00'], $flex)['status']);
    }

    public function test_no_schedule_shows_times_without_judging(): void
    {
        $this->assertSame('No punches', $this->day([], null)['status']);
        $d = $this->day(['13:00:00', '14:00:00'], null);
        $this->assertSame(['Present', 0], [$d['status'], $d['late']]);
    }

    public function test_night_shift_ends_the_next_morning(): void
    {
        $night = $this->fixed(['start_time' => '21:00', 'end_time' => '06:00', 'late_grace_minutes' => 0]);
        $d = $this->day(['21:05:00', '30:00:00'], $night);
        $this->assertSame(['Present', '21:05', '30:00', 535, 5, 0], [$d['status'], $d['in'], $d['out'], $d['worked'], $d['late'], $d['early']]);
        $this->assertSame(60, $this->day(['21:00:00', '29:00:00'], $night)['early']);
        // HR types 06:00 as the check-out: the next morning.
        $d = $this->day(['21:00:00'], $night, ['check_in' => null, 'check_out' => '06:00', 'note' => 'x']);
        $this->assertSame(['30:00', 540], [$d['out'], $d['worked']]);
        // Auto check-out at the next morning's end time.
        $d = $this->day(['21:00:00'], $this->fixed(['start_time' => '21:00', 'end_time' => '06:00', 'missing_checkout' => 'auto']));
        $this->assertSame(['30:00', 'auto'], [$d['out'], $d['out_source']]);
    }

    public function test_overtime_after_the_tolerance(): void
    {
        $s = $this->fixed(['overtime_after_minutes' => 30]);
        $this->assertSame(0, $this->day(['10:00:00', '18:25:00'], $s)['overtime']);
        $this->assertSame(132, $this->day(['10:00:00', '20:12:00'], $s)['overtime']);
        $this->assertSame(0, $this->day(['10:00:00', '20:12:00'], $this->fixed())['overtime']);   // not counted on this schedule
        $this->assertSame(240, $this->day(['11:00:00', '15:00:00'], $s, null, '2026-05-16')['overtime']);   // Saturday: all of it
    }

    public function test_holiday_and_leave_context(): void
    {
        $this->assertSame('Holiday', AttendanceRules::day('2026-05-13', [], null, $this->fixed(), $this->now, ['holiday' => 'Fake'])['status']);
        $this->assertSame('On leave', AttendanceRules::day('2026-05-13', [], null, $this->fixed(), $this->now, ['leave' => ['type' => 'Sick', 'part' => 'full']])['status']);
        $d = AttendanceRules::day('2026-05-13', ['10:00:00', '13:00:00'], null, $this->fixed(), $this->now, ['leave' => ['type' => 'Casual', 'part' => 'second_half']]);
        $this->assertSame(['Present', 0], [$d['status'], $d['early']]);
    }

    private static function m(string $t): array
    {
        return ['time' => $t, 'source' => 'machine', 'method' => 'FACE'];
    }

    private static function h(string $t, string $action): array
    {
        return ['time' => $t, 'source' => 'hrmy', 'method' => $action];
    }

    public function test_office_then_remote_counts_two_sessions_not_the_gap(): void
    {
        $s = $this->fixed(['overtime_after_minutes' => 30]);
        $d = $this->day([self::m('09:58:00'), self::m('14:00:00'), self::h('20:00:00', 'In'), self::h('23:00:00', 'Out')], $s);

        $this->assertSame([['09:58', '14:00', 'machine', 242, 'office', 'office'], ['20:00', '23:00', 'hrmy', 180, 'remote', 'remote']],
            array_map(fn ($s) => [$s['in'], $s['out'], $s['source'], $s['minutes'], $s['in_where'], $s['out_where']], $d['sessions']));
        $this->assertSame(['both', 'office', 'remote'], [$d['where'], $d['in_where'], $d['out_where']]);
        $this->assertSame(['Present', '09:58', '23:00', 422, 0, 0, 4], [$d['status'], $d['in'], $d['out'], $d['worked'], $d['early'], $d['overtime'], $d['punches']]);
        $this->assertSame('09:58-14:00 office, 20:00-23:00 remote', AttendanceRules::sessionsText($d['sessions']));
    }

    public function test_overtime_only_runs_on_from_the_office_session(): void
    {
        $s = $this->fixed(['overtime_after_minutes' => 30]);
        $d = $this->day([self::m('10:00:00'), self::m('19:00:00'), self::h('21:00:00', 'In'), self::h('23:00:00', 'Out')], $s);
        $this->assertSame([660, 60], [$d['worked'], $d['overtime']]);   // 9 h office + 2 h remote; overtime 18:00-19:00 only
        $this->assertSame(0, $this->day([self::h('19:00:00', 'In'), self::h('23:00:00', 'Out')], $s)['overtime']);   // evening only: no overtime
    }

    public function test_open_last_session_is_a_missing_punch_and_overlaps_count_once(): void
    {
        $d = $this->day([self::m('10:00:00'), self::m('18:00:00'), self::h('20:00:00', 'In')], $this->fixed());
        $this->assertSame(['Missing punch', null, 480], [$d['status'], $d['out'], $d['worked']]);
        $this->assertNull($d['sessions'][1]['out']);

        // Clocked in on HR.my from home and punched the machine too: the same hours once.
        $d = $this->day([self::h('09:00:00', 'In'), self::m('10:00:00'), self::m('18:00:00'), self::h('17:00:00', 'Out')], $this->fixed());
        $this->assertSame(540, $d['worked']);

        // In at the office, out from home: one session.
        $d = $this->day([self::m('10:00:00'), self::h('18:30:00', 'Out')], $this->fixed());
        $this->assertSame([['10:00', '18:30', 'mixed', 510]], array_map(fn ($s) => [$s['in'], $s['out'], $s['source'], $s['minutes']], $d['sessions']));
        $this->assertSame(['both', 'office', 'remote'], [$d['where'], $d['in_where'], $d['out_where']]);
        $this->assertSame('10:00-18:30 office to remote', AttendanceRules::sessionsText($d['sessions']));
    }

    public function test_hr_times_move_the_first_start_and_last_end_of_a_split_day(): void
    {
        $d = $this->day([self::m('10:20:00'), self::m('14:00:00'), self::h('20:00:00', 'In')], $this->fixed(), ['check_in' => '10:00', 'check_out' => '22:00', 'note' => 'Fixed']);
        $this->assertSame(['10:00-14:00', '20:00-22:00', 360, 0], [$d['sessions'][0]['in'].'-'.$d['sessions'][0]['out'], $d['sessions'][1]['in'].'-'.$d['sessions'][1]['out'], $d['worked'], $d['late']]);
    }

    public function test_where_the_day_was_worked(): void
    {
        $this->assertSame(['office', 'office', 'office'], array_values(array_intersect_key($this->day(['09:00:00', '17:00:00'], $this->fixed()), array_flip(['where', 'in_where', 'out_where']))));
        $remote = $this->day([self::h('09:00:00', 'In'), self::h('17:00:00', 'Out')], $this->fixed());
        $this->assertSame(['remote', 'remote', 'remote'], [$remote['where'], $remote['in_where'], $remote['out_where']]);
        $this->assertNull($this->day([], $this->fixed())['where']);

        // HR moved the times: still an office day, marked as changed by HR; times HR typed with no punch have no place.
        $d = $this->day([self::m('10:20:00'), self::m('17:00:00')], $this->fixed(), ['check_in' => '10:00', 'check_out' => null, 'note' => 'Fixed']);
        $this->assertSame(['office', true, '10:00-17:00 office (HR)'], [$d['where'], $d['sessions'][0]['edited'], AttendanceRules::sessionsText($d['sessions'])]);
        $this->assertNull($this->day([], $this->fixed(), ['check_in' => '10:00', 'check_out' => '18:00', 'note' => 'Fixed'])['where']);
    }
}
