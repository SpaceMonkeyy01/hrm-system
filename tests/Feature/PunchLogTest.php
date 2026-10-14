<?php

namespace Tests\Feature;

use App\Models\AttendanceImport;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Attendance > Punch log: every punch from every source. Fake people only; today is Wed 20 May 2026. */
class PunchLogTest extends TestCase
{
    use RefreshDatabase;

    private Employee $ali;

    private Employee $sara;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var Company $company */
        [$company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-05-20 18:00'));
        $this->ali = $this->employee($company, ['full_name' => 'Fake Ali', 'employee_code' => 'T-001', 'attendance_machine_id' => '7']);
        $this->sara = $this->employee($company, ['full_name' => 'Fake Sara', 'employee_code' => 'T-002', 'hr_portal_id' => 'H-9']);
        $sync = AttendanceImport::create(['source' => 'machine', 'original_name' => 'Machine sync']);
        $file = AttendanceImport::create(['source' => 'hrmy', 'original_name' => 'clock-may.xls']);
        $punch = fn (array $a) => AttendancePunch::create($a + ['work_date' => substr($a['punched_at'], 0, 10)]);
        $punch(['source' => 'machine', 'device_user_id' => '7', 'device_name' => 'ALI', 'punched_at' => '2026-05-20 09:01:00', 'method' => 'FACE', 'attendance_import_id' => $sync->id]);
        $punch(['source' => 'machine', 'device_user_id' => '99', 'device_name' => 'NEW GUY', 'punched_at' => '2026-05-20 09:30:00', 'method' => 'FP', 'attendance_import_id' => $sync->id]);
        $punch(['source' => 'hrmy', 'device_user_id' => 'H-9', 'device_name' => 'Sara F', 'punched_at' => '2026-05-20 10:00:00', 'method' => 'In', 'attendance_import_id' => $file->id]);
        $punch(['source' => 'app', 'device_user_id' => (string) $this->ali->id, 'punched_at' => '2026-05-20 17:00:00', 'method' => 'Out',
            'place' => 'remote', 'ip' => '198.51.100.5', 'latitude' => 31.5, 'longitude' => 74.3, 'accuracy' => 12]);
        // Night shift: punched at 02:00 on the 20th, counts on the 19th.
        $punch(['source' => 'hrmy', 'device_user_id' => 'H-9', 'punched_at' => '2026-05-20 02:00:00', 'work_date' => '2026-05-19', 'method' => 'Out', 'attendance_import_id' => $file->id]);
        $punch(['source' => 'machine', 'device_user_id' => '7', 'punched_at' => '2026-04-01 09:00:00', 'attendance_import_id' => $sync->id]);   // outside the default week
    }

    private function log(array $query = [])
    {
        return $this->actingAs($this->user('hr'))->get('/attendance/punches?'.http_build_query($query));
    }

    public function test_lists_every_source_newest_first_with_people_and_origin(): void
    {
        $this->log()->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('attendance/punches')
            ->where('filters.from', '2026-05-14')
            ->has('punches.data', 5)
            ->where('punches.data.0.source', 'app')->where('punches.data.0.employee.name', 'Fake Ali')->where('punches.data.0.via', 'Clock-in on My page')
            ->where('punches.data.0.ip', '198.51.100.5')->where('punches.data.0.location.accuracy', 12)->where('punches.data.0.place', 'remote')
            ->where('punches.data.1.source', 'hrmy')->where('punches.data.1.employee.name', 'Fake Sara')->where('punches.data.1.via', 'clock-may.xls')
            ->where('punches.data.1.place', 'remote')
            ->where('punches.data.2.employee', null)->where('punches.data.2.device_name', 'NEW GUY')
            ->where('punches.data.3.employee.code', 'T-001')->where('punches.data.3.place', 'office')->where('punches.data.3.via', 'Machine sync')
            ->where('punches.data.4.work_date', '2026-05-19')->where('punches.data.4.date', '2026-05-20')
            ->where('counts.machine', 2)->where('counts.hrmy', 2)->where('counts.app', 1));
    }

    public function test_filters(): void
    {
        $count = fn (array $q, int $n) => $this->log($q)->assertInertia(fn (AssertableInertia $page) => $page->has('punches.data', $n));
        $count(['source' => 'machine'], 2);
        $count(['q' => 'Ali'], 2);              // machine + app, through the links
        $count(['q' => 'T-002'], 2);            // by code
        $count(['q' => 'new guy'], 1);          // name on the device, not linked
        $count(['q' => '99'], 1);               // device ID
        $count(['place' => 'office'], 2);
        $count(['place' => 'remote'], 3);
        $count(['linked' => 'no'], 1);
        $count(['linked' => 'yes'], 4);
        $count(['from' => '2026-04-01', 'to' => '2026-04-01'], 1);
    }

    public function test_csv_download_follows_the_filters(): void
    {
        $res = $this->actingAs($this->user('hr'))->get('/attendance/punches/csv?source=app');
        $res->assertOk();
        $lines = array_values(array_filter(explode("\n", trim(str_replace("\xEF\xBB\xBF", '', $res->streamedContent())))));
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('Date,Time,"Counts on"', $lines[0]);
        $this->assertStringContainsString('2026-05-20,17:00:00,2026-05-20,T-001,"Fake Ali",Yes,App,', $lines[1]);
        $this->assertStringContainsString('198.51.100.5', $lines[1]);
    }

    public function test_only_hr_and_admin(): void
    {
        $this->actingAs($this->user('viewer'))->get('/attendance/punches')->assertForbidden();
        $this->actingAs($this->user('viewer'))->get('/attendance/punches/csv')->assertForbidden();
        $this->actingAs($this->user('admin'))->get('/attendance/punches')->assertOk();
    }
}
