<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\Onboarding;
use App\Models\WorkSchedule;
use App\Services\Reports\ReportCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Reports page, Overview and the Home additions. Fake people only; today is Wed 20 May 2026. */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $linked;

    private Employee $leaver;

    private Employee $joiner;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-05-20 12:00'));
        WorkSchedule::create(['name' => '9 to 5', 'type' => 'fixed', 'start_time' => '09:00', 'end_time' => '17:00', 'late_grace_minutes' => 10,
            'full_day_minutes' => 480, 'half_day_minutes' => 240, 'working_days' => [1, 2, 3, 4, 5], 'is_default' => true]);

        $this->linked = $this->employee($this->company, ['full_name' => 'Fake Linked', 'start_date' => '2025-05-22',
            'attendance_machine_id' => '1', 'cnic_expiry' => '2026-06-01', 'cnic' => '35202-1234567-1']);
        $this->leaver = $this->employee($this->company, ['full_name' => 'Fake Leaver', 'start_date' => '2024-03-01',
            'exit_date' => '2026-04-15', 'status' => 'exited']);
        $this->joiner = $this->employee($this->company, ['full_name' => 'Fake Joiner', 'start_date' => '2026-05-04']);

        foreach (['2026-05-18 09:30:00', '2026-05-18 17:05:00'] as $at) {   // Monday: in 30 minutes late
            AttendancePunch::create(['source' => 'machine', 'device_user_id' => '1', 'device_name' => 'Fake Linked', 'punched_at' => $at, 'work_date' => '2026-05-18']);
        }
        // A machine number nobody is linked to punched every day from 1 to 19 May, so the machine files cover
        // those days and a missing punch from Fake Linked is an absence, not "No data".
        for ($d = 1; $d <= 19; $d++) {
            $date = sprintf('2026-05-%02d', $d);
            AttendancePunch::create(['source' => 'machine', 'device_user_id' => '99', 'device_name' => 'Fake Unlinked', 'punched_at' => "$date 10:00:00", 'work_date' => $date]);
        }
    }

    private function props(string $url, string $role = 'hr'): array
    {
        $props = null;
        $this->actingAs($this->user($role))->get($url)->assertOk()
            ->assertInertia(function (AssertableInertia $p) use (&$props) {
                $props = $p->toArray()['props'];
            });

        return $props;
    }

    private function row(array $props, string $name): ?array
    {
        return collect($props['result']['rows'])->firstWhere('employee.name', $name);
    }

    public function test_every_report_opens(): void
    {
        foreach (array_keys(ReportCatalog::all()) as $key) {
            $this->actingAs($this->user('hr'))->get("/reports/$key")->assertOk()
                ->assertInertia(fn (AssertableInertia $p) => $p->component('reports/show')->where('report.key', $key));
            $this->actingAs($this->user('hr'))->get("/reports/$key/export")->assertOk();
        }
        $this->actingAs($this->user('hr'))->get('/reports/nope')->assertNotFound();
    }

    public function test_monthly_summary_counts_the_same_days_as_the_attendance_page(): void
    {
        $row = $this->row($this->props('/reports/attendance-monthly?month=2026-05'), 'Fake Linked');
        $this->assertSame(1, $row['present']);
        $this->assertSame(1, $row['late']);
        $this->assertSame(30, $row['late_minutes']);
        // 1-19 May has 13 working days; the 18th was worked, and today (20th) is "Not in yet", not absent.
        $this->assertSame(12, $row['absent']);
        $this->assertSame('Machine', $row['source']);
        $this->assertSame('Not linked', $this->row($this->props('/reports/attendance-monthly?month=2026-05'), 'Fake Joiner')['source']);
    }

    public function test_sheet_gives_one_code_per_day_and_blanks_outside_employment(): void
    {
        $props = $this->props('/reports/attendance-sheet?month=2026-05');
        $this->assertCount(1 + 31 + 4, $props['result']['columns']);
        $linked = $this->row($props, 'Fake Linked');
        $this->assertSame('L', $linked['d18']['code']);
        $this->assertSame('A', $linked['d19']['code']);
        $this->assertSame('O', $linked['d16']['code'], 'Saturday');
        $this->assertSame('', $linked['d25']['code'], 'future');
        $this->assertSame('', $this->row($props, 'Fake Joiner')['d01']['code'], 'before the start date');
        $this->assertNull($this->row($props, 'Fake Leaver'), 'left in April');
    }

    public function test_days_without_any_file_are_no_data_not_absent(): void
    {
        $sheet = $this->row($this->props('/reports/attendance-sheet?month=2026-04'), 'Fake Linked');
        $this->assertSame('-', $sheet['d01']['code'], 'nobody punched in April: no file covers it');
        $this->assertSame('No data', $sheet['d01']['title']);
        $april = $this->row($this->props('/reports/attendance-monthly?month=2026-04'), 'Fake Linked');
        $this->assertSame([0, 0], [$april['absent'], $april['working_days']]);
    }

    public function test_split_day_shows_each_session_and_counts_only_the_time_worked(): void
    {
        // Office in the morning on the machine, then a shift from home on HR.my.
        $this->linked->update(['hr_portal_id' => 'BCC-901']);
        foreach (['2026-05-19 09:00:00', '2026-05-19 13:00:00'] as $at) {
            AttendancePunch::create(['source' => 'machine', 'device_user_id' => '1', 'punched_at' => $at, 'work_date' => '2026-05-19']);
        }
        foreach (['In' => '2026-05-19 20:00:00', 'Out' => '2026-05-19 23:30:00'] as $action => $at) {
            AttendancePunch::create(['source' => 'hrmy', 'device_user_id' => 'BCC-901', 'punched_at' => $at, 'work_date' => '2026-05-19', 'method' => $action]);
        }

        $log = $this->props('/reports/attendance-log?from=2026-05-18&to=2026-05-19')['result'];
        $this->assertSame([['2026-05-18', '09:30-17:05 machine', 455], ['2026-05-19', '09:00-13:00 machine, 20:00-23:30 HR.my', 450]],
            array_map(fn ($r) => [$r['date'], $r['sessions'], $r['worked']], array_values(array_filter($log['rows'], fn ($r) => $r['employee']['name'] === 'Fake Linked'))));
        $day = collect($this->props('/attendance?date=2026-05-19')['rows'])->firstWhere('employee.name', 'Fake Linked');
        $this->assertSame([450, 2, '23:30'], [$day['worked'], count($day['sessions']), $day['out']]);
        $this->assertStringContainsString('20:00-23:30 HR.my', $this->row($this->props('/reports/attendance-sheet?month=2026-05'), 'Fake Linked')['d19']['title']);
    }

    public function test_late_and_absence_lists(): void
    {
        $late = $this->props('/reports/late-arrivals?from=2026-05-18&to=2026-05-19')['result']['rows'];
        $this->assertSame([['2026-05-18', 'Fake Linked', 30]], array_map(fn ($r) => [$r['date'], $r['employee']['name'], $r['late']], $late));

        $absent = $this->props('/reports/absences?from=2026-05-19&to=2026-05-19')['result']['rows'];
        $this->assertSame(['Fake Linked'], array_column(array_column($absent, 'employee'), 'name'), 'unlinked people are never "absent"');
    }

    public function test_people_reports_use_start_and_exit_dates(): void
    {
        $turnover = collect($this->props('/reports/turnover?month=2026-05')['result']['rows'])->keyBy('month');
        $this->assertSame(1, $turnover['2026-04']['leavers']);
        $this->assertSame(2, $turnover['2026-04']['opening']);
        $this->assertSame(1, $turnover['2026-04']['closing']);
        $this->assertSame(66.7, $turnover['2026-04']['turnover']);
        $this->assertSame(1, $turnover['2026-05']['joiners']);
        $this->assertSame(2, $turnover['2026-05']['closing']);

        $movement = $this->props('/reports/joiners-leavers?from=2026-04-01&to=2026-05-20')['result']['rows'];
        $this->assertSame([['2026-04-15', 'Left', 'Fake Leaver'], ['2026-05-04', 'Joined', 'Fake Joiner']],
            array_map(fn ($r) => [$r['date'], $r['change'], $r['employee']['name']], $movement));

        $headcount = $this->props('/reports/headcount?date=2026-04-10')['result']['rows'];
        $this->assertSame(2, $headcount[0]['headcount'], 'the leaver was still here on 10 April; the joiner not yet');
    }

    public function test_cnic_report_is_hr_only(): void
    {
        $this->actingAs($this->user('viewer'))->get('/reports/cnic-expiry')->assertForbidden();
        $this->actingAs($this->user('viewer'))->get('/reports/cnic-expiry/export')->assertForbidden();
        $keys = collect($this->props('/reports', 'viewer')['groups'])->pluck('reports')->flatten(1)->pluck('key');
        $this->assertNotContains('cnic-expiry', $keys);
        $this->assertContains('attendance-sheet', $keys);

        $rows = $this->props('/reports/cnic-expiry?within=30')['result']['rows'];
        $this->assertSame([['Fake Linked', 12, 'Expires soon']], array_map(fn ($r) => [$r['employee']['name'], $r['days'], $r['state']], $rows));
    }

    public function test_csv_matches_the_screen_with_hours_and_split_names(): void
    {
        $csv = $this->actingAs($this->user('hr'))->get('/reports/late-arrivals/export?from=2026-05-18&to=2026-05-18')->streamedContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim(substr($csv, 3)))));
        $this->assertSame(['Date', 'Code', 'Name', 'Department', 'Schedule', 'Checked in', 'Late by (hours)'], $lines[0]);
        $this->assertSame(['2026-05-18', $this->linked->employee_code, 'Fake Linked', '', '9 to 5', '09:30', '0.5'], $lines[1]);
    }

    public function test_bad_ranges_are_refused(): void
    {
        $this->actingAs($this->user('hr'))->get('/reports/late-arrivals?from=2026-05-10&to=2026-05-01')->assertSessionHasErrors('to');
        $this->actingAs($this->user('hr'))->get('/reports/late-arrivals?from=2024-01-01&to=2026-05-01')->assertSessionHasErrors('to');
    }

    public function test_leave_and_holiday_reports(): void
    {
        $type = LeaveType::firstOrCreate(['name' => 'Annual']);
        LeaveRecord::create(['employee_id' => $this->linked->id, 'leave_type_id' => $type->id, 'start_date' => '2026-05-05', 'end_date' => '2026-05-06', 'days' => 2]);
        Holiday::create(['date' => '2026-05-01', 'name' => 'Fake Labour Day']);

        $leave = $this->props('/reports/leave-taken?from=2026-05-01&to=2026-05-20')['result']['rows'];
        $this->assertSame([['Fake Linked', 'Annual', 1, 2]], array_map(fn ($r) => [$r['employee']['name'], $r['type'], $r['records'], (int) $r['days']], $leave));
        $this->assertSame('Fake Labour Day', $this->props('/reports/holidays?year=2026')['result']['rows'][0]['name']);
        $this->assertSame('H', $this->row($this->props('/reports/attendance-sheet?month=2026-05'), 'Fake Linked')['d01']['code']);
    }

    public function test_overview_and_home(): void
    {
        Onboarding::create(['employee_id' => $this->joiner->id, 'probation_result' => 'Pending', 'probation_end_date' => '2026-08-02']);
        $o = $this->props('/overview', 'viewer');
        $this->assertSame(2, $o['tiles']['headcount']);
        $this->assertSame(1, $o['tiles']['joined30']);
        $this->assertCount(12, $o['monthly']);
        $this->assertSame('2026-05', end($o['monthly'])['month']);
        $this->assertSame(1, $o['onboarding']['total']);
        $this->assertSame(1, $o['attendance']['late']);

        $home = $this->props('/dashboard', 'viewer');
        $this->assertSame('2026-05-19', $home['attendance']['date'], 'the last day punches were loaded for');
        $this->assertSame(['Fake Joiner'], array_column($home['newPeople'], 'name'));
        $this->assertSame([['Fake Linked', 'anniversary', 1]], array_map(fn ($c) => [$c['name'], $c['kind'], $c['years']], $home['celebrations']));
    }

    public function test_birthdays_on_home_are_hr_only(): void
    {
        $this->linked->update(['date_of_birth' => '1990-05-21']);
        $kinds = fn ($role) => array_column($this->props('/dashboard', $role)['celebrations'], 'kind');
        $this->assertSame(['birthday', 'anniversary'], $kinds('hr'));
        $this->assertSame(['anniversary'], $kinds('viewer'));
    }
}
