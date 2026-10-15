<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Attendance > Links: everyone's machine number, device and HR.my ID, edited in the row or through Excel. */
class AttendanceLinksTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private AttendanceDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00'));
        $this->device = AttendanceDevice::create(['name' => 'Main office', 'host' => '203.0.113.10']);
    }

    private function punch(string $source, string $id, string $date, ?string $name = null, bool $onDevice = true): void
    {
        AttendancePunch::create(['source' => $source, 'device_user_id' => $id, 'device_name' => $name, 'punched_at' => "$date 09:00:00", 'work_date' => $date,
            'attendance_device_id' => $source === 'machine' && $onDevice ? $this->device->id : null]);
    }

    public function test_table_shows_links_last_punches_and_what_is_missing(): void
    {
        $both = $this->employee($this->company, ['full_name' => 'Fake Both', 'attendance_machine_id' => '7', 'hr_portal_id' => 'H-7', 'attendance_device_id' => $this->device->id]);
        $old = $this->employee($this->company, ['full_name' => 'Fake Old', 'attendance_machine_id' => '8']);
        $none = $this->employee($this->company, ['full_name' => 'Fake None']);
        $this->employee($this->company, ['full_name' => 'Fake Gone', 'status' => 'exited', 'attendance_machine_id' => '9']);
        $this->punch('machine', '7', '2026-10-14');
        $this->punch('hrmy', 'H-7', '2026-10-13');
        $this->punch('machine', '8', '2026-09-01');
        $this->punch('machine', '55', '2026-10-14', 'Fake None');

        $this->actingAs($this->user('hr'))->get('/attendance/links')->assertOk()->assertInertia(function (AssertableInertia $p) use ($both, $old, $none) {
            $p->component('attendance/links')->has('rows', 3)->has('unlinked', 1)
                ->where('unlinked.0.device_user_id', '55')->where('unlinked.0.suggestion', $none->id)->where('unlinked.0.confidence', 'match')
                ->where('unlinked.0.device_id', $this->device->id);
            $rows = collect($p->toArray()['props']['rows'])->keyBy('id');
            $this->assertSame([[], '2026-10-14', 'ATD-'.str_pad((string) $this->device->id, 3, '0', STR_PAD_LEFT)],
                [$rows[$both->id]['issues'], $rows[$both->id]['latest'], $rows[$both->id]['device']]);
            $this->assertSame(['no_hrmy', 'stale'], $rows[$old->id]['issues']);
            $this->assertSame(['no_machine', 'no_hrmy', 'nothing'], $rows[$none->id]['issues']);
        });
    }

    public function test_hr_edits_a_row_and_numbers_stay_with_one_person(): void
    {
        $ali = $this->employee($this->company, ['full_name' => 'Fake Ali', 'attendance_machine_id' => '7']);
        $sara = $this->employee($this->company, ['full_name' => 'Fake Sara']);
        $this->punch('machine', '12', '2026-10-14');
        $hr = $this->user('hr');

        $this->actingAs($hr)->put("/attendance/links/{$sara->id}", ['attendance_machine_id' => '12', 'hr_portal_id' => ' H-1 ', 'attendance_device_id' => null])
            ->assertSessionHasNoErrors();
        $sara->refresh();
        $this->assertSame(['12', 'H-1', $this->device->id], [$sara->attendance_machine_id, $sara->hr_portal_id, $sara->attendance_device_id], 'device from its punches');

        $this->actingAs($hr)->put("/attendance/links/{$sara->id}", ['attendance_machine_id' => '7', 'hr_portal_id' => 'H-1'])
            ->assertSessionHasErrors(['attendance_machine_id' => 'Machine number 7 already belongs to Fake Ali ('.$ali->employee_code.').']);

        $this->actingAs($hr)->put("/attendance/links/{$sara->id}", ['attendance_machine_id' => '', 'hr_portal_id' => 'H-1'])->assertSessionHasNoErrors();
        $this->assertSame([null, null], [$sara->fresh()->attendance_machine_id, $sara->fresh()->attendance_device_id], 'removing the number removes the device');

        $this->actingAs($this->user('viewer'))->put("/attendance/links/{$ali->id}", ['attendance_machine_id' => '1'])->assertForbidden();
        $this->actingAs($this->user('viewer'))->get('/attendance/links')->assertForbidden();
    }

    public function test_accept_links_every_exact_match_but_not_two_numbers_for_one_person(): void
    {
        $nida = $this->employee($this->company, ['full_name' => 'Fake Nida']);
        $omar = $this->employee($this->company, ['full_name' => 'Fake Omar']);
        $this->punch('machine', '20', '2026-10-14', 'Fake Nida');
        $this->punch('hrmy', 'H-20', '2026-10-14', 'Fake Nida');
        $this->punch('machine', '30', '2026-10-14', 'Fake Omar');
        $this->punch('machine', '31', '2026-10-13', 'Fake Omar');
        $this->punch('machine', '40', '2026-10-14', 'Someone Else');

        $this->actingAs($this->user('hr'))->post('/attendance/links/accept')
            ->assertSessionHas('success', 'Linked 2 exact matches. 2 left for you: two numbers point to the same person.');
        $this->assertSame(['20', 'H-20'], [$nida->fresh()->attendance_machine_id, $nida->fresh()->hr_portal_id]);
        $this->assertNull($omar->fresh()->attendance_machine_id);
    }

    public function test_excel_round_trip_sets_moves_and_removes_links(): void
    {
        $ali = $this->employee($this->company, ['full_name' => 'Fake Ali', 'attendance_machine_id' => '7', 'hr_portal_id' => 'H-7']);
        $sara = $this->employee($this->company, ['full_name' => 'Fake Sara']);
        $omar = $this->employee($this->company, ['full_name' => 'Fake Omar', 'attendance_machine_id' => '9']);
        $this->punch('machine', '12', '2026-10-14');
        $hr = $this->user('hr');

        $csv = $this->actingAs($hr)->get('/imports/links/template')->assertOk()->streamedContent();
        $this->assertStringContainsString('"System ID",Code,Name,Company,"Machine no.",Device,"HR.my ID"', $csv);

        $file = implode("\n", [
            'System ID,Code,Name,Company,Machine no.,Device,HR.my ID',
            "{$sara->system_id},,Fake Sara,,12,,H-2",
            "{$ali->system_id},,Fake Ali,,,,-",
            "{$omar->system_id},,Fake Omar,,7,ATD-999,",
        ]);
        $this->actingAs($hr)->post('/imports/links', ['file' => UploadedFile::fake()->createWithContent('links.csv', $file)])->assertRedirect('/imports/links');
        $this->actingAs($hr)->get('/imports/links')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('plan.matched', 3)
            ->has('plan.changes', 4)       // Sara: machine, device (from punches), HR.my; Ali: HR.my removed
            ->has('plan.problems', 2));    // Omar: 7 is Ali's; no device ATD-999
        $this->actingAs($hr)->post('/imports/links/apply')->assertSessionHas('success', 'Saved 4 changes for 2 people.');

        $this->assertSame(['12', $this->device->id, 'H-2'], [$sara->fresh()->attendance_machine_id, $sara->fresh()->attendance_device_id, $sara->fresh()->hr_portal_id]);
        $this->assertSame(['7', null], [$ali->fresh()->attendance_machine_id, $ali->fresh()->hr_portal_id]);
        $this->assertSame('9', $omar->fresh()->attendance_machine_id);
    }

    public function test_new_employee_from_an_unlinked_number_is_added_linked_and_not_onboarded(): void
    {
        $this->punch('machine', '174', '2026-10-15', 'Fake Kazmi');
        $hr = $this->user('hr');

        $this->actingAs($hr)->get('/employees/create?from=links&machine=174&name=Fake+Kazmi')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('employees/form')->where('prefill', ['name' => 'Fake Kazmi', 'machine' => '174', 'from' => 'links']));

        $this->actingAs($hr)->post('/employees', ['full_name' => 'Fake Kazmi', 'company_id' => $this->company->id, 'attendance_machine_id' => '174',
            'start_onboarding' => false, 'from' => 'links'])
            ->assertRedirect('/attendance/links?tab=numbers')->assertSessionHas('success', fn ($m) => str_contains($m, 'linked to machine no. 174'));
        $e = Employee::where('full_name', 'Fake Kazmi')->sole();
        $this->assertSame(['174', $this->device->id], [$e->attendance_machine_id, $e->attendance_device_id]);
        $this->assertFalse($e->onboarding()->exists(), 'someone who already works here is not put on probation');

        // The number now belongs to them: a second person can't take it, on Add or Edit.
        $this->actingAs($hr)->post('/employees', ['full_name' => 'Fake Other', 'company_id' => $this->company->id, 'attendance_machine_id' => '174'])
            ->assertSessionHasErrors(['attendance_machine_id' => 'This machine number already belongs to someone else.']);
        // A normal Add still starts onboarding.
        $this->actingAs($hr)->post('/employees', ['full_name' => 'Fake New Hire', 'company_id' => $this->company->id])->assertSessionHasNoErrors();
        $this->assertTrue(Employee::where('full_name', 'Fake New Hire')->sole()->onboarding()->exists());
    }

    public function test_devices_page_counts_linked_numbers(): void
    {
        $this->employee($this->company, ['full_name' => 'Fake Ali', 'attendance_machine_id' => '7']);
        $this->punch('machine', '7', '2026-10-14');
        $this->punch('machine', '8', '2026-10-14');
        $this->punch('machine', '9', '2026-10-14', null, false);   // uploaded file, not this device

        $this->actingAs($this->user('hr'))->get('/attendance/devices')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('devices.0.links', ['numbers' => 2, 'linked' => 1]));
        $this->actingAs($this->user('hr'))->get('/attendance/imports')->assertInertia(fn (AssertableInertia $p) => $p->where('unlinkedCount', 2));
    }
}
