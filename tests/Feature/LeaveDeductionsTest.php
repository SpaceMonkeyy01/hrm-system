<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\WorkSchedule;
use App\Services\AttendanceRules;
use App\Services\LeaveBalances;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\XlsxBuilder;
use Tests\TestCase;

/** Short leave, deductions from attendance (Casual then Annual) and HR's leave sheet as opening balances. Fake people only. */
class LeaveDeductionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00'));   // Friday
        WorkSchedule::create(['name' => 'Ten to six', 'start_time' => '10:00', 'end_time' => '18:00', 'late_grace_minutes' => 10,
            'working_days' => [1, 2, 3, 4, 5], 'is_default' => true, 'full_day_minutes' => 480, 'half_day_minutes' => 240]);
    }

    private function type(string $name): LeaveType
    {
        return LeaveType::where('name', $name)->firstOrFail();
    }

    private function punch(Employee $e, string $at): void
    {
        AttendancePunch::create(['source' => 'machine', 'device_user_id' => $e->attendance_machine_id, 'punched_at' => $at, 'work_date' => substr($at, 0, 10)]);
    }

    private function settings(array $s): void
    {
        $this->actingAs($this->user('hr'))->put('/leave/settings', $s + ['absent_days' => 1, 'late_early_days' => 0.25, 'short_leave_days' => 0.25, 'deductions_from' => null])
            ->assertSessionHasNoErrors();
    }

    public function test_short_leave_counts_a_quarter_day_and_excuses_late_or_early(): void
    {
        $e = $this->employee($this->company, ['start_date' => '2020-01-01']);
        $this->actingAs($this->user('hr'))->post('/leave', ['employee_id' => $e->id, 'leave_type_id' => $this->type('Casual')->id,
            'start_date' => '2026-10-14', 'end_date' => '2026-10-14', 'part' => 'short'])
            ->assertSessionHas('success', fn ($m) => str_starts_with($m, "Recorded 0.25 days of leave for {$e->full_name}. Casual: 9.75 of 10"));
        $this->assertSame(0.25, LeaveRecord::sole()->days);
        $this->actingAs($this->user('hr'))->post('/leave', ['employee_id' => $e->id, 'leave_type_id' => $this->type('Casual')->id,
            'start_date' => '2026-10-12', 'end_date' => '2026-10-13', 'part' => 'short'])->assertSessionHasErrors('part');

        $schedule = WorkSchedule::sole();
        $d = AttendanceRules::day('2026-10-14', ['12:00:00', '16:00:00'], null, $schedule, now(), ['leave' => ['type' => 'Casual', 'part' => 'short']]);
        $this->assertSame(['Present', 0, 0], [$d['status'], $d['late'], $d['early']]);
    }

    public function test_absences_and_late_days_come_off_casual_then_annual_from_the_start_date(): void
    {
        $e = $this->employee($this->company, ['full_name' => 'Fake Late', 'start_date' => '2020-01-01', 'attendance_machine_id' => '5']);
        // Mon 12: absent (someone else punched, so the day is covered). Tue 13: late. Wed 14: late and early. Thu 15: on time.
        $other = $this->employee($this->company, ['attendance_machine_id' => '6']);
        foreach (['12', '13', '14', '15'] as $day) {
            $this->punch($other, "2026-10-$day 09:55:00");
        }
        $this->punch($e, '2026-10-13 10:30:00');
        $this->punch($e, '2026-10-13 18:00:00');
        $this->punch($e, '2026-10-14 10:30:00');
        $this->punch($e, '2026-10-14 16:00:00');
        $this->punch($e, '2026-10-15 10:00:00');
        $this->punch($e, '2026-10-15 18:00:00');

        // Off by default
        $this->assertSame(0.0, LeaveBalances::forEmployee($e, 2026)[1]['deducted']);
        $this->actingAs($this->user('hr'))->put('/leave/settings', ['absent_days' => 1, 'late_early_days' => 0.3, 'short_leave_days' => 0.25])->assertSessionHasErrors('late_early_days');
        $this->actingAs($this->user('viewer'))->put('/leave/settings', ['absent_days' => 1, 'late_early_days' => 0.25, 'short_leave_days' => 0.25])->assertForbidden();

        $this->settings(['deductions_from' => '2026-10-12']);
        $s = LeaveBalances::summary(collect([$e->fresh()]), 2026)[$e->id];
        $this->assertSame(['absent' => 1, 'late_early' => 3, 'sheet' => 0.0, 'days' => 1.75], $s['deductions']);
        $casual = collect($s['balances'])->firstWhere('type', 'Casual');
        $this->assertSame([1.75, 8.25], [$casual['deducted'], $casual['left']]);

        // From a later date, the earlier days don't count
        $this->settings(['deductions_from' => '2026-10-14']);
        $this->assertSame(0.5, LeaveBalances::summary(collect([$e->fresh()]), 2026)[$e->id]['deductions']['days']);

        // Casual used up: the rest comes off Annual
        $this->settings(['deductions_from' => '2026-10-12', 'absent_days' => 2]);
        LeaveRecord::create(['employee_id' => $e->id, 'leave_type_id' => $this->type('Casual')->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-13', 'days' => 9]);
        $rows = collect(LeaveBalances::forEmployee($e->fresh(), 2026))->keyBy('type');
        $this->assertSame([1.0, 0.0], [$rows['Casual']['deducted'], $rows['Casual']['left']]);
        $this->assertSame([1.75, 12.25], [$rows['Annual']['deducted'], $rows['Annual']['left']]);

        $this->actingAs($this->user('hr'))->get('/leave')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('settings.deductions_from', '2026-10-12')
            ->where('balances.rows', fn ($r) => collect($r)->firstWhere('name', 'Fake Late')['deductions']['days'] == 2.75));
    }

    public function test_hr_sheet_upload_adds_opening_balances_and_replaces_on_reupload(): void
    {
        $a = $this->employee($this->company, ['full_name' => 'Fake Alpha', 'start_date' => '2020-01-01']);
        $b = $this->employee($this->company, ['full_name' => 'Fake Bravo', 'start_date' => '2020-01-01', 'gender' => 'Male']);
        $this->employee($this->company, ['full_name' => 'Fake Twin']);
        $twin2 = $this->employee($this->company, ['full_name' => 'Fake Twin']);
        $hr = $this->user('hr');
        // HR's layout: titles on the Name row, group headings, then column names (the second block's Absent heading left blank)
        $head = ['C' => 'Absent', 'D' => 'Wedding leave', 'E' => 'Maternity/ Paternity Leave', 'F' => 'Annual Leave', 'G' => 'Casual Leave', 'H' => 'Sick Leave',
            'I' => 'Casual Leave', 'J' => 'Sick Leave', 'K' => 'Short leave', 'L' => 'Short Leave Due to LSI/ Early sign off', 'M' => 'Early sign off'];
        $sheet = fn (array $people) => (new XlsxBuilder)->sheet('Leave balance-2026', [
            1 => ['A' => 'someone'],
            2 => ['A' => 'Sr.No ', 'B' => 'Name', 'C' => 'BCC Leave Record (July)', 'O' => 'BCC Leave Record (August)'],
            3 => ['D' => 'Full day leave', 'I' => 'Half leave', 'K' => 'Short leave', 'N' => 'Total', 'P' => 'Full day leave', 'Z' => 'Total', 'AA' => 'Leave Count'],
            4 => $head + ['P' => 'Wedding leave', 'R' => 'Annual Leave', 'S' => 'Casual Leave', 'W' => 'Short leave'],
        ] + $people)->save(sys_get_temp_dir().'/leave-sheet-'.uniqid().'.xlsx');
        $upload = function (string $path) use ($hr) {
            $this->actingAs($hr)->post('/imports/leave-sheet', ['file' => new UploadedFile($path, 'BCC Leave balance.xlsx', null, null, true)]);
        };

        $upload($sheet([
            5 => ['A' => '1', 'B' => 'Fake Alpha', 'C' => '1', 'G' => '2', 'I' => '1', 'K' => '2', 'M' => '1', 'S' => '1', 'H' => '1', 'AA' => '15'],
            6 => ['A' => '2', 'B' => 'Fake  Bravo ', 'E' => '3', 'R' => '2'],
            7 => ['A' => '3', 'B' => 'Fake Twin'],
            8 => ['A' => '4', 'B' => 'Fake Twin (' . $twin2->employee_code . ')', 'X' => '4'],
            9 => ['A' => '5', 'B' => 'Fake Alfa'],
        ]));
        $this->actingAs($hr)->get('/imports/leave-sheet')->assertInertia(fn (AssertableInertia $p) => $p
            ->has('plan.changes', 3)->has('plan.problems', 2)
            ->where('plan.problems', fn ($pr) => str_contains(collect($pr)->firstWhere('name', 'Fake Twin')['message'], 'Several people')
                && str_contains(collect($pr)->firstWhere('name', 'Fake Alfa')['message'], 'Closest in the app: Fake Alpha')));
        $this->actingAs($hr)->post('/imports/leave-sheet/apply')->assertSessionHas('success', 'Added opening balances for 3 people (2026).');

        // Alpha: Casual 2 + 1 + half 0.5 + short 2 x 0.25 = 4; Sick 1; deductions absent 1 + early 0.25 = 1.25
        $rows = collect(LeaveBalances::forEmployee($a, 2026))->keyBy('type');
        $this->assertSame([4.0, 1.25, 4.75], [$rows['Casual']['used'], $rows['Casual']['deducted'], $rows['Casual']['left']]);
        $this->assertSame(1.0, $rows['Sick']['used']);
        // Bravo: Annual 2; 3 days of Maternity/Paternity, a man: Paternity
        $this->assertSame(2.0, collect(LeaveBalances::forEmployee($b, 2026))->firstWhere('type', 'Annual')['used']);
        $this->assertSame(3.0, (float) LeaveAdjustment::where('employee_id', $b->id)->where('leave_type_id', $this->type('Paternity')->id)->value('days'));
        // Twin, matched by code: 4 late / early sign-offs in August x 0.25 = 1 day deducted
        $this->assertSame(1.0, LeaveBalances::summary(collect([$twin2]), 2026)[$twin2->id]['deductions']['sheet']);

        $this->actingAs($hr)->get("/employees/{$a->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('leave.counted', fn ($c) => collect($c)->pluck('type')->all() === ['Casual', 'Sick', 'Deductions']));

        // Uploading again replaces, not adds
        $upload($sheet([5 => ['A' => '1', 'B' => 'Fake Alpha', 'G' => '1']]));
        $this->actingAs($hr)->post('/imports/leave-sheet/apply')->assertSessionHasNoErrors();
        $this->assertSame(1.0, collect(LeaveBalances::forEmployee($a, 2026))->firstWhere('type', 'Casual')['used']);
        $this->assertSame(1, LeaveAdjustment::where('employee_id', $a->id)->count());
        $this->actingAs($this->user('viewer'))->get('/imports/leave-sheet')->assertForbidden();
    }
}
