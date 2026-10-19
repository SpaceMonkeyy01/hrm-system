<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\MachineSync;
use App\Services\Import\MachineSyncer;
use App\Support\Zk\AttendanceMachine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeMachine;
use Tests\TestCase;

/** Attendance > Devices: several machines, each with its own settings; the Comm Key never leaves the server. */
class AttendanceDeviceTest extends TestCase
{
    use RefreshDatabase;

    private array $form = ['name' => 'Main office', 'site' => 'Reception', 'host' => '203.0.113.10', 'port' => 4370, 'comm_key' => '4321',
        'is_active' => true, 'auto_sync' => true, 'timeout_seconds' => 20, 'lookback_days' => 3, 'clock_warn_seconds' => 60, 'notes' => null];

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-10-13 16:00'));
        config(['hrm.cron_token' => 'secret-token']);
    }

    public function test_hr_adds_edits_and_removes_a_device_and_the_comm_key_stays_secret(): void
    {
        $hr = $this->user('hr');
        $this->actingAs($hr)->post('/attendance/devices', $this->form)->assertSessionHasNoErrors();
        $device = AttendanceDevice::sole();
        $this->assertSame('4321', $device->comm_key);
        $this->assertNotSame('4321', $device->getRawOriginal('comm_key'), 'stored encrypted');

        $log = ActivityLog::where('subject_type', 'AttendanceDevice')->sole();
        $this->assertSame('hidden', $log->changes['comm_key']);

        $this->actingAs($hr)->get('/attendance/devices')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('attendance/devices')->where('devices.0.name', 'Main office')->where('devices.0.has_comm_key', true)
            ->missing('devices.0.comm_key'));
        $this->assertStringNotContainsString('4321', $this->actingAs($hr)->get('/attendance/devices')->getContent());

        // A blank key keeps the saved one; a new key replaces it; "no key" clears it.
        $this->actingAs($hr)->put("/attendance/devices/{$device->id}", ['comm_key' => '', 'name' => 'Head office'] + $this->form)->assertSessionHasNoErrors();
        $this->assertSame(['Head office', '4321'], [$device->fresh()->name, $device->fresh()->comm_key]);
        $this->actingAs($hr)->put("/attendance/devices/{$device->id}", ['comm_key' => '99'] + $this->form);
        $this->assertSame('99', $device->fresh()->comm_key);
        $this->actingAs($hr)->put("/attendance/devices/{$device->id}", ['comm_key' => '', 'clear_comm_key' => true] + $this->form);
        $this->assertNull($device->fresh()->comm_key);

        AttendancePunch::create(['source' => 'machine', 'device_user_id' => '2', 'punched_at' => '2026-10-13 09:00:00', 'work_date' => '2026-10-13',
            'attendance_device_id' => $device->id]);
        $this->actingAs($hr)->delete("/attendance/devices/{$device->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, AttendanceDevice::count());
        $this->assertNull(AttendancePunch::sole()->attendance_device_id, 'punches stay when a device is removed');
    }

    public function test_device_settings_are_checked(): void
    {
        $hr = $this->user('hr');
        foreach ([['host' => 'http://203.0.113.10'], ['host' => '203.0.113.10:4370'], ['port' => 70000], ['comm_key' => 'abc'], ['comm_key' => '1234567'],
            ['timeout_seconds' => 1], ['lookback_days' => 0], ['name' => '']] as $bad) {
            $this->actingAs($hr)->post('/attendance/devices', $bad + $this->form)->assertSessionHasErrors(array_key_first($bad));
        }
        $this->actingAs($hr)->post('/attendance/devices', ['host' => 'zk.office.example.com'] + $this->form)->assertSessionHasNoErrors();
        $this->actingAs($hr)->post('/attendance/devices', ['host' => '203.0.113.11'] + $this->form)->assertSessionHasErrors(['name' => 'Another device already has that name.']);
    }

    public function test_each_device_syncs_separately_and_cron_skips_manual_only_devices(): void
    {
        $a = AttendanceDevice::create(['name' => 'Main office', 'host' => '203.0.113.10']);
        $b = AttendanceDevice::create(['name' => 'Warehouse', 'host' => '203.0.113.20', 'lookback_days' => 1]);
        $off = AttendanceDevice::create(['name' => 'Old machine', 'host' => '203.0.113.30', 'is_active' => false]);
        $fakes = [
            $a->id => new FakeMachine([['user_id' => '2', 'time' => '2026-10-13 09:01:00', 'method' => 'FACE']], ['2' => 'Fake A']),
            $b->id => new FakeMachine([['user_id' => '2', 'time' => '2026-10-13 13:00:00', 'method' => 'FP'],
                ['user_id' => '5', 'time' => '2026-10-13 13:05:00', 'method' => 'FP']], ['2' => 'Fake A', '5' => 'Fake B']),
        ];
        $this->app->bind(AttendanceMachine::class, fn ($app, $p) => $fakes[$p['device']->id] ?? new FakeMachine(fail: 'Should not be read'));

        $this->get('/cron/machine/secret-token')->assertOk()
            ->assertSee("Main office: Synced: 1 new punch (1 recent checked, 1 on the machine).\nWarehouse: Synced: 2 new punches", false);
        $this->assertSame([$a->id => 1, $b->id => 2], AttendancePunch::query()->selectRaw('attendance_device_id, count(*) as n')
            ->groupBy('attendance_device_id')->pluck('n', 'attendance_device_id')->map(fn ($n) => (int) $n)->sortKeys()->all());
        $this->assertSame(0, MachineSync::where('attendance_device_id', $off->id)->count());
        $this->assertSame('Fake machine', $a->fresh()->model_name);
        $this->assertSame('FAKE0001', $a->fresh()->serial);

        // Sync now only: left out of the automatic run, still read by Sync now.
        $b->update(['auto_sync' => false]);
        $this->get('/cron/machine/secret-token')->assertOk()->assertSee('Synced: 0 new punches')->assertDontSee('Warehouse');
        $this->actingAs($this->user('hr'))->post("/attendance/devices/{$b->id}/sync")->assertSessionHas('success', 'Warehouse: Synced: 0 new punches (2 recent checked, 2 on the machine).');

        // One device failing makes the cron answer 500 but the others still sync.
        $b->update(['auto_sync' => true]);
        $fakes[$b->id]->fail = 'The machine refused the Comm Key.';
        $this->get('/cron/machine/secret-token')->assertStatus(500)->assertSee('Main office: Synced')->assertSee('Warehouse: Sync failed: The machine refused the Comm Key.');
    }

    public function test_test_connection_reads_details_and_reports_the_clock(): void
    {
        $device = AttendanceDevice::create(['name' => 'Main office', 'host' => '203.0.113.10']);
        $fake = new FakeMachine([['user_id' => '2', 'time' => '2026-10-13 09:01:00', 'method' => 'FACE']], ['2' => 'A'], '2026-10-13 15:58:00');
        $this->app->instance(AttendanceMachine::class, $fake);
        $hr = $this->user('hr');

        $this->actingAs($hr)->post("/attendance/devices/{$device->id}/test")
            ->assertSessionHas('success', 'Connected to Main office (Fake machine, serial FAKE0001): 1 users, 1 punches stored. Its clock is 120 seconds slow.');
        $this->assertSame([1, 1, 'Ver 6.60'], [$device->fresh()->user_count, $device->fresh()->record_count, $device->fresh()->firmware]);
        $this->assertSame(0, AttendancePunch::count(), 'a test reads no punches');
        $this->assertSame(0, ActivityLog::where('subject_type', 'AttendanceDevice')->where('event', 'updated')->count(), 'reading details is not logged');

        $fake->fail = "Couldn't reach the machine at 203.0.113.10:4370 (timed out).";
        $this->actingAs($hr)->post("/attendance/devices/{$device->id}/test")
            ->assertSessionHas('error', "Couldn't connect to Main office: Couldn't reach the machine at 203.0.113.10:4370 (timed out).");
    }

    public function test_only_hr_and_admin_manage_devices(): void
    {
        $device = AttendanceDevice::create(['name' => 'Main office', 'host' => '203.0.113.10']);
        $viewer = $this->user('viewer');
        $this->actingAs($viewer)->get('/attendance/devices')->assertForbidden();
        $this->actingAs($viewer)->post('/attendance/devices', $this->form)->assertForbidden();
        $this->actingAs($viewer)->post("/attendance/devices/{$device->id}/test")->assertForbidden();
        $this->actingAs($this->user('admin'))->get('/attendance/devices')->assertOk();
    }

    public function test_machine_syncer_uses_the_device_settings_for_the_real_connection(): void
    {
        $device = AttendanceDevice::create(['name' => 'Main office', 'host' => '203.0.113.10', 'port' => 4371, 'comm_key' => '77', 'timeout_seconds' => 9]);
        $client = MachineSyncer::connection($device);
        $read = fn (string $prop) => (fn () => $this->{$prop})->call($client);
        $this->assertSame(['203.0.113.10', 4371, 77, 9], [$read('host'), $read('port'), $read('commKey'), $read('timeout')]);
    }

    public function test_sync_overdue_shows_on_devices_and_in_the_daily_email(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->user('hr', ['email' => 'hr@example.test']);
        $main = AttendanceDevice::create(['name' => 'Main office', 'host' => '203.0.113.10']);
        $manual = AttendanceDevice::create(['name' => 'Warehouse', 'host' => '203.0.113.20', 'auto_sync' => false]);
        AttendanceDevice::create(['name' => 'Old machine', 'host' => '203.0.113.30', 'is_active' => false]);
        $sync = fn (int $minutesAgo, ?bool $ok = true) => MachineSync::forceCreate(['attendance_device_id' => $main->id, 'trigger' => 'cron',
            'started_at' => now()->subMinutes($minutesAgo), 'finished_at' => now()->subMinutes($minutesAgo), 'ok' => $ok]);

        // Never synced while on automatic sync: overdue. Manual-only and switched-off devices never are.
        $this->assertSame(['Main office'], array_column(MachineSyncer::overdueDevices(), 'name'));
        $sync(30);
        $this->assertSame([], MachineSyncer::overdueDevices());
        $this->assertFalse(MachineSyncer::deviceStatus($main)['overdue']);
        $this->assertFalse(MachineSyncer::deviceStatus($manual)['overdue']);

        // 90 minutes later, with only failed tries since: overdue, everywhere
        $this->travel(60)->minutes();
        $sync(5, false);
        $this->assertTrue(MachineSyncer::deviceStatus($main)['overdue']);
        $this->actingAs($this->user('hr'))->get('/attendance/devices')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('devices', fn ($d) => collect($d)->firstWhere('name', 'Main office')['status']['overdue'] === true));
        $this->actingAs($this->user('hr'))->get('/attendance')->assertInertia(fn (AssertableInertia $p) => $p->where('machine.devices.0.overdue', true));

        $this->artisan('hr:send-reminders')->assertSuccessful();
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\RemindersDigest::class, fn ($m) => count($m->overdue) === 1
            && $m->hasSubject('HR reminders for 13 Oct 2026: attendance machine not syncing'));
        $html = (new \App\Mail\RemindersDigest(now(), [], 0, MachineSyncer::overdueDevices()))->render();
        $this->assertStringContainsString("Main office ({$main->fresh()->code}): no automatic sync since 13 Oct 2026 15:30", $html);
    }
}
