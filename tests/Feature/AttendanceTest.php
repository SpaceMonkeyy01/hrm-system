<?php

namespace Tests\Feature;

use App\Models\AttendanceAdjustment;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\EmploymentHistory;
use App\Models\WorkSchedule;
use App\Support\Xls\XlsReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\XlsBuilder;
use Tests\TestCase;

/** Machine punch upload, linking, schedules and the daily view. Fake people and files only. */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->company, $this->dept] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-05-20 12:00'));
    }

    private function upload(array $punches, string $name = 'May 13th Full day log.xls')
    {
        $path = XlsBuilder::save(XlsBuilder::punchLog($punches));

        return $this->actingAs($this->user('hr'))->post('/attendance/imports', ['file' => new UploadedFile($path, $name, null, null, true)]);
    }

    private function schedule(array $attrs = []): WorkSchedule
    {
        return WorkSchedule::create($attrs + ['name' => 'Ten to six', 'type' => 'fixed', 'start_time' => '10:00', 'end_time' => '18:00',
            'late_grace_minutes' => 10, 'full_day_minutes' => 480, 'half_day_minutes' => 240, 'working_days' => [1, 2, 3, 4, 5]]);
    }

    public function test_reader_reads_the_machine_format(): void
    {
        $path = XlsBuilder::save(XlsBuilder::punchLog([['Fake Person', '7', '5/13/2026 9:58:03 AM']]));
        $records = (new XlsReader($path))->records();

        $this->assertSame([['Department' => 'OUR COMPANY', 'Name' => 'Fake Person', 'No.' => '7', 'Date/Time' => '5/13/2026 9:58:03 AM',
            'Location ID' => '1', 'ID Number' => '', 'VerifyCode' => 'FACE', 'CardNo' => '']], $records);
    }

    public function test_upload_stores_punches_once(): void
    {
        $punches = [['Fake One', '1', '5/13/2026 9:58:03 AM'], ['Fake One', '1', '5/13/2026 6:02:05 PM'], ['Fake Two', '2', '5/13/2026 1:11:28 PM']];
        $this->upload($punches)->assertSessionHas('success', 'Attendance machine file: read 3 punches, 3 new.');
        $this->upload([...$punches, ['Fake Two', '2', '5/14/2026 10:00:00 AM']])->assertSessionHas('success', 'Attendance machine file: read 4 punches, 1 new (the rest were already in).');

        $this->assertSame(4, AttendancePunch::count());
        $p = AttendancePunch::where('device_user_id', '1')->orderBy('punched_at')->first();
        $this->assertSame(['2026-05-13 09:58:03', 'Fake One', 'FACE', 'machine'], [$p->punched_at->format('Y-m-d H:i:s'), $p->device_name, $p->method, $p->source]);
        $this->assertCount(2, Storage::disk('local')->files('attendance-imports'));
    }

    public function test_the_full_report_and_other_files_are_refused(): void
    {
        $report = XlsBuilder::save(XlsBuilder::biff2([['Emp No.', 'AC-No.', 'No.', 'Name', 'Date', 'Clock In'], ['1', '1', '', 'Fake', '5/13/2026', '09:58']]));
        $this->actingAs($this->user('hr'))->post('/attendance/imports', ['file' => new UploadedFile($report, 'Full Report.xls', null, null, true)])
            ->assertSessionHasErrors(['file' => 'This is the machine\'s Full Report. Upload the "Full day log" instead (the file with a Date/Time column, one row per punch).']);

        $junk = XlsBuilder::save('not an excel file');
        $this->actingAs($this->user('hr'))->post('/attendance/imports', ['file' => new UploadedFile($junk, 'x.xls', null, null, true)])
            ->assertSessionHasErrors('file');
        $this->assertSame(0, AttendancePunch::count());
        $this->assertSame([], Storage::disk('local')->files('attendance-imports'));
    }

    public function test_linking_by_name_suggestion_then_by_number(): void
    {
        $e = $this->employee($this->company, ['full_name' => 'Fake One']);
        $this->employee($this->company, ['full_name' => 'Someone Else']);
        $this->upload([['Fake One', '1', '5/13/2026 9:58:03 AM'], ['Nobody Known', '9', '5/13/2026 10:00:00 AM']]);

        $this->actingAs($this->user('hr'))->get('/attendance/imports')->assertInertia(fn (AssertableInertia $p) => $p
            ->has('unlinked', 2)
            ->where('unlinked.0.source', 'machine')->where('unlinked.0.device_user_id', '1')->where('unlinked.0.suggestion', $e->id)->where('unlinked.0.confidence', 'match')
            ->where('unlinked.1.confidence', 'none'));

        $this->actingAs($this->user('hr'))->post('/attendance/link', ['source' => 'machine', 'links' => [['device_user_id' => '1', 'employee_id' => $e->id]]])
            ->assertSessionHasNoErrors();
        $this->assertSame('1', $e->fresh()->attendance_machine_id);

        // The number can't go to a second person.
        $other = $this->employee($this->company, ['full_name' => 'Other Fake']);
        $this->actingAs($this->user('hr'))->post('/attendance/link', ['source' => 'machine', 'links' => [['device_user_id' => '1', 'employee_id' => $other->id]]])
            ->assertSessionHasErrors('links.0.employee_id');
    }

    public function test_daily_view_applies_each_persons_schedule(): void
    {
        $tenToSix = $this->schedule(['is_default' => true]);
        $nineToFive = $this->schedule(['name' => 'Nine to five strict', 'start_time' => '09:00', 'end_time' => '17:00', 'late_grace_minutes' => 0]);
        $a = $this->employee($this->company, ['attendance_machine_id' => '1']);
        $b = $this->employee($this->company, ['attendance_machine_id' => '2']);
        $c = $this->employee($this->company, ['attendance_machine_id' => '3']);
        $d = $this->employee($this->company);   // no machine number: not counted absent
        EmployeeWorkSchedule::create(['employee_id' => $b->id, 'work_schedule_id' => $nineToFive->id, 'starts_on' => '2026-01-01']);
        $this->upload([
            ['A', '1', '5/13/2026 10:08:00 AM'], ['A', '1', '5/13/2026 6:00:00 PM'],   // 10-6, inside grace
            ['B', '2', '5/13/2026 9:08:00 AM'], ['B', '2', '5/13/2026 5:00:00 PM'],     // 9-5 strict: late 8 min
        ]);

        $this->actingAs($this->user('viewer'))->get('/attendance?date=2026-05-13')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('attendance/index')
            ->where('rows', fn ($rows) => collect($rows)->keyBy('employee.id')->map(fn ($r) => [$r['status'], $r['late'], $r['schedule']])->all() === [
                $a->id => ['Present', 0, 'Ten to six'],
                $b->id => ['Present', 8, 'Nine to five strict'],
                $c->id => ['Absent', 0, 'Ten to six'],
                $d->id => ['Not linked', 0, 'Ten to six'],
            ])
            ->where('counts.Late', 1)->where('counts.Absent', 1));
        $this->assertNotNull($tenToSix);
    }

    public function test_hr_enters_a_forgotten_punch_out(): void
    {
        $this->schedule(['is_default' => true]);
        $e = $this->employee($this->company, ['attendance_machine_id' => '1']);
        $this->upload([['A', '1', '5/13/2026 10:00:00 AM']]);

        $this->actingAs($this->user('viewer'))->put("/attendance/{$e->id}/2026-05-13", ['check_out' => '18:00', 'note' => 'x'])->assertForbidden();
        $this->actingAs($this->user('hr'))->put("/attendance/{$e->id}/2026-05-13", ['check_out' => '18:00'])->assertSessionHasErrors('note');
        $this->actingAs($this->user('hr'))->put("/attendance/{$e->id}/2026-05-13", ['check_in' => '18:00', 'check_out' => '18:00', 'note' => 'x'])->assertSessionHasErrors('check_out');
        $this->actingAs($this->user('hr'))->put("/attendance/{$e->id}/2026-05-13", ['check_out' => '18:00', 'note' => 'Forgot, confirmed by manager'])->assertSessionHasNoErrors();

        $this->actingAs($this->user('hr'))->get("/employees/{$e->id}?month=2026-05")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('attendance.month', '2026-05')
            ->where('attendance.days', fn ($days) => collect($days)->firstWhere('date', '2026-05-13')['status'] === 'Present'
                && collect($days)->firstWhere('date', '2026-05-13')['out_source'] === 'manual'));

        $this->actingAs($this->user('hr'))->delete("/attendance/{$e->id}/2026-05-13")->assertRedirect();
        $this->assertSame(0, AttendanceAdjustment::count());
    }

    public function test_schedules_are_managed_by_hr_and_assigned_by_department(): void
    {
        $hr = $this->user('hr');
        $rules = ['name' => 'Twelve to six', 'type' => 'fixed', 'start_time' => '12:00', 'end_time' => '18:00', 'late_grace_minutes' => 10,
            'early_grace_minutes' => 0, 'full_day_minutes' => 360, 'half_day_minutes' => 180, 'working_days' => [1, 2, 3, 4, 5], 'missing_checkout' => 'auto'];
        $this->actingAs($this->user('viewer'))->post('/attendance/schedules', $rules)->assertForbidden();
        $this->actingAs($hr)->post('/attendance/schedules', ['end_time' => '12:00'] + $rules)->assertSessionHasErrors('end_time');
        $this->actingAs($hr)->post('/attendance/schedules', ['half_day_minutes' => 400] + $rules)->assertSessionHasErrors('half_day_minutes');
        $this->actingAs($hr)->post('/attendance/schedules', $rules)->assertSessionHasNoErrors();
        $this->actingAs($hr)->post('/attendance/schedules', ['name' => 'Flexible', 'type' => 'flexible', 'is_default' => true] + $rules)->assertSessionHasNoErrors();

        $flex = WorkSchedule::where('name', 'Flexible')->sole();
        $this->assertSame([null, null, 'flag', true], [$flex->start_time, $flex->end_time, $flex->missing_checkout, $flex->is_default]);

        $inDept = $this->employee($this->company, ['department_id' => $this->dept->id]);
        $elsewhere = $this->employee($this->company);
        $twelve = WorkSchedule::where('name', 'Twelve to six')->sole();
        $this->actingAs($hr)->post("/attendance/schedules/{$twelve->id}/assign", ['department_id' => $this->dept->id, 'starts_on' => '2026-05-01'])
            ->assertSessionHas('success', '1 person moved to Twelve to six.');

        $this->assertSame($twelve->id, EmployeeWorkSchedule::where('employee_id', $inDept->id)->sole()->work_schedule_id);
        $this->assertFalse(EmployeeWorkSchedule::where('employee_id', $elsewhere->id)->exists());
        $h = EmploymentHistory::where('employee_id', $inDept->id)->sole();
        $this->assertSame(['Work schedule change', '2026-05-01', ['Work schedule' => [null, 'Twelve to six']]], [$h->change_type, $h->effective_date->toDateString(), $h->changes]);

        // A used schedule can't be deleted.
        $this->actingAs($hr)->delete("/attendance/schedules/{$twelve->id}")->assertSessionHasErrors('schedule');
    }

    public function test_schedule_changes_keep_past_days_on_the_old_rules(): void
    {
        $old = $this->schedule(['name' => 'Ten to six']);
        $new = $this->schedule(['name' => 'Nine to five strict', 'start_time' => '09:00', 'end_time' => '17:00', 'late_grace_minutes' => 0]);
        $e = $this->employee($this->company, ['attendance_machine_id' => '1']);
        $hr = $this->user('hr');
        $this->actingAs($hr)->put("/employees/{$e->id}", ['company_id' => $this->company->id, 'full_name' => $e->full_name, 'work_schedule_id' => $old->id, 'schedule_starts_on' => '2026-01-01']);
        $this->actingAs($hr)->put("/employees/{$e->id}", ['company_id' => $this->company->id, 'full_name' => $e->full_name, 'work_schedule_id' => $new->id, 'schedule_starts_on' => '2026-05-14']);
        $this->upload([['A', '1', '5/13/2026 10:05:00 AM'], ['A', '1', '5/13/2026 6:00:00 PM'], ['A', '1', '5/14/2026 10:05:00 AM'], ['A', '1', '5/14/2026 6:00:00 PM']]);

        $this->actingAs($hr)->get("/employees/{$e->id}?month=2026-05")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('attendance.days', function ($days) {
                $byDate = collect($days)->keyBy('date');

                return $byDate['2026-05-13']['late'] === 0 && $byDate['2026-05-13']['schedule'] === 'Ten to six'
                    && $byDate['2026-05-14']['late'] === 65 && $byDate['2026-05-14']['schedule'] === 'Nine to five strict';
            }));
        $this->assertSame(2, EmploymentHistory::where('employee_id', $e->id)->where('change_type', 'Work schedule change')->count());
    }

    private function hrmyFile(array $rows): string
    {
        $table = [['Time Clock Records for 2026-05-13'], [], ['Employer:', 'BCC'], ['Department:', '', '', '', 'Branch:'], [],
            ['Date', 'ID', 'Name', 'Action', 'Time', 'IP', 'Selfie', 'Geolocation', 'Remark', 'By', 'Department', 'Branch']];
        foreach ($rows as [$date, $id, $name, $action, $time]) {
            $table[] = [$date, $id, $name, $action, $time, '127.0.0.1', '', '', 'By HR.my Administrator', 'HR.my Administrator', 'CS', 'BCC'];
        }

        return XlsBuilder::save(XlsBuilder::biff2($table));
    }

    public function test_hrmy_export_with_night_shift_is_linked_by_id_not_code(): void
    {
        $this->schedule(['name' => 'Night', 'start_time' => '21:00', 'end_time' => '06:00', 'is_default' => true]);
        // The HR.my ID looks like one of our codes but belongs to someone else: it must not link by code.
        $codeTwin = $this->employee($this->company, ['full_name' => 'Code Twin', 'employee_code' => 'BCC-032']);
        $nightWorker = $this->employee($this->company, ['full_name' => 'Fake Night Worker']);
        $path = $this->hrmyFile([
            ['2026-05-13', 'BCC-032', 'Fake Night Worker', 'In', '09:15 PM'],
            ['2026-05-13', 'BCC-032', 'Fake Night Worker', 'Out', '06:20 AM (2026-05-14)'],
        ]);
        $hr = $this->user('hr');

        $this->actingAs($hr)->post('/attendance/imports', ['file' => new UploadedFile($path, '2c9f90cd.xls', null, null, true)])
            ->assertSessionHas('success', 'HR.my file: read 2 punches, 2 new.');
        $out = AttendancePunch::where('method', 'Out')->sole();
        $this->assertSame(['hrmy', '2026-05-14 06:20:00', '2026-05-13'], [$out->source, $out->punched_at->format('Y-m-d H:i:s'), $out->work_date->toDateString()]);

        $this->actingAs($hr)->get('/attendance/imports')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('unlinked.0.source', 'hrmy')->where('unlinked.0.suggestion', $nightWorker->id));
        $this->actingAs($hr)->post('/attendance/link', ['source' => 'hrmy', 'links' => [['device_user_id' => 'BCC-032', 'employee_id' => $nightWorker->id]]])
            ->assertSessionHasNoErrors();
        $this->assertSame('BCC-032', $nightWorker->fresh()->hr_portal_id);
        $this->assertNull($codeTwin->fresh()->hr_portal_id);

        $this->actingAs($hr)->get('/attendance?date=2026-05-13')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('rows', fn ($rows) => ($r = collect($rows)->firstWhere('employee.id', $nightWorker->id))
                && $r['status'] === 'Present' && $r['in'] === '21:15' && $r['out'] === '30:20' && $r['worked'] === 545 && $r['late'] === 15 && $r['early'] === 0));
    }

    public function test_machine_log_with_excel_date_numbers_is_read(): void
    {
        $this->upload([['Fake Person', '7', '46155.4153125'], ['Fake Person', '7', '46155.7500115741']])
            ->assertSessionHas('success', 'Attendance machine file: read 2 punches, 2 new.');
        $this->assertSame(['2026-05-13 09:58:03', '2026-05-13 18:00:01'],
            AttendancePunch::orderBy('punched_at')->get()->map(fn ($p) => $p->punched_at->format('Y-m-d H:i:s'))->all());
    }

    public function test_hrmy_daily_attendance_transactions_become_in_and_out_punches(): void
    {
        $table = [['Attendance Transactions for 2026-05-13'], [], ['Employer:', 'BCC'],
            ['Date', 'ID', 'Name', 'Status', 'First Clock In', 'Last Clock Out', 'Duration (Hour)', 'Break (Hour)', 'Actual (Hour)', 'Overtime (Hour)', 'Remark', 'Department', 'Branch'],
            ['2026-05-13', 'BCC-901', 'Fake Day', 'Present', '09:05 AM', '06:10 PM', '09:05', '', '09:05', '', '', 'CS', 'BCC'],
            ['2026-05-13', 'BCC-902', 'Fake Night', 'Present', '09:15 PM', '06:20 AM (2026-05-14)', '09:05', '', '09:05', '', '', 'CS', 'BCC'],
            ['2026-05-13', 'BCC-903', 'Fake Forgot', 'Present', '10:01 AM', '', '', '', '', '', '', 'CS', 'BCC'],
            ['2026-05-13', 'BCC-904', 'Fake Away', 'Absent', '', '', '', '', '', '', 'Casual leave', 'CS', 'BCC']];
        $path = XlsBuilder::save(XlsBuilder::biff2($table));

        $this->actingAs($this->user('hr'))->post('/attendance/imports', ['file' => new UploadedFile($path, 'transactions.xls', null, null, true)])
            ->assertSessionHas('success', 'HR.my file: read 5 punches, 5 new.');
        $night = AttendancePunch::where('device_user_id', 'BCC-902')->where('method', 'Out')->sole();
        $this->assertSame(['hrmy', '2026-05-14 06:20:00', '2026-05-13'], [$night->source, $night->punched_at->format('Y-m-d H:i:s'), $night->work_date->toDateString()]);
        $this->assertSame(['In'], AttendancePunch::where('device_user_id', 'BCC-903')->pluck('method')->all());
        $this->assertFalse(AttendancePunch::where('device_user_id', 'BCC-904')->exists());
    }
}
