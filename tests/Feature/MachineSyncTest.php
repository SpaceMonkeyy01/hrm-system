<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AttendanceImport;
use App\Models\AttendancePunch;
use App\Models\MachineSync;
use App\Services\Import\MachineSyncer;
use App\Support\Zk\AttendanceMachine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeMachine;
use Tests\TestCase;

/** Reading the attendance machine: stores only new punches, logs each try, never spams Uploaded files. */
class MachineSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-10-13 16:00'));
        config(['hrm.machine.host' => '203.0.113.10', 'hrm.cron_token' => 'secret-token']);
        $this->machine = new FakeMachine([
            ['user_id' => '2', 'time' => '2026-05-13 09:58:03', 'method' => 'FACE'],
            ['user_id' => '2', 'time' => '2026-10-13 09:01:00', 'method' => 'FACE'],
            ['user_id' => '7', 'time' => '2026-10-13 09:15:00', 'method' => 'FP'],
        ], ['2' => 'Fake Person', '7' => '']);
        $this->app->instance(AttendanceMachine::class, $this->machine);
    }

    public function test_first_sync_saves_everything_then_only_what_is_new(): void
    {
        $sync = app(MachineSyncer::class)->run('command');
        $this->assertTrue($sync->ok);
        $this->assertSame([3, 3], [$sync->checked, $sync->added]);
        $this->assertSame(['Fake Person', null], [AttendancePunch::where('device_user_id', '2')->value('device_name'), AttendancePunch::where('device_user_id', '7')->value('device_name')]);
        $this->assertSame('2026-10-13', AttendancePunch::where('device_user_id', '7')->first()->work_date->toDateString());
        $import = AttendanceImport::sole();
        $this->assertSame([MachineSyncer::IMPORT_NAME, 'machine', 3], [$import->original_name, $import->source, $import->punches_added]);

        // Nothing new: no import row, nothing in the activity log.
        $activity = ActivityLog::count();
        $sync = app(MachineSyncer::class)->run('cron');
        $this->assertSame([true, 0], [$sync->ok, $sync->added]);
        $this->assertSame(2, $sync->checked, 'only the last days before our latest punch are compared');
        $this->assertSame(1, AttendanceImport::count());
        $this->assertSame($activity, ActivityLog::count());

        // A new punch arrives.
        $this->machine->punches[] = ['user_id' => '7', 'time' => '2026-10-13 15:59:00', 'method' => 'FP'];
        $this->assertSame(1, app(MachineSyncer::class)->run('cron')->added);
        $this->assertSame(4, AttendancePunch::count());
    }

    public function test_punches_already_uploaded_from_a_file_are_not_added_twice(): void
    {
        AttendancePunch::create(['source' => 'machine', 'device_user_id' => '2', 'punched_at' => '2026-10-13 09:01:00', 'work_date' => '2026-10-13']);
        $this->assertSame(2, app(MachineSyncer::class)->run('command')->added);
        $this->assertSame(3, AttendancePunch::count());
    }

    public function test_failures_and_a_wrong_clock_are_recorded_and_shown(): void
    {
        $this->machine->clock = '2026-10-13 15:58:30';   // 90 seconds slow
        app(MachineSyncer::class)->run('cron');
        $this->machine->fail = "Couldn't reach the machine at 203.0.113.10:4370 (timed out).";
        $failed = app(MachineSyncer::class)->run('cron');
        $this->assertSame([false, "Couldn't reach the machine at 203.0.113.10:4370 (timed out)."], [$failed->ok, $failed->error]);

        $this->actingAs($this->user('hr'))->get('/attendance/imports')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('machine.configured', true)->where('machine.last.ok', false)->where('machine.clock_offset', -90)->where('machine.clock_wrong', true)
            ->has('machine.recent', 2));
    }

    public function test_cron_link_needs_the_token_and_reports_failure_as_500(): void
    {
        $this->get('/cron/machine/wrong')->assertNotFound();
        $this->get('/cron/machine/secret-token')->assertOk()->assertSee('Synced: 3 new punches');
        $this->machine->fail = 'The machine refused the Comm Key.';
        $this->get('/cron/machine/secret-token')->assertStatus(500)->assertSee('Sync failed: The machine refused the Comm Key.');
        $this->assertSame([true, false], MachineSync::orderBy('id')->pluck('ok')->all());
    }

    public function test_a_generated_token_with_slashes_and_plus_works(): void
    {
        config(['hrm.cron_token' => 'aB3/x+Yz9/Qw==']);   // Render's generateValue is base64
        $this->get('/cron/machine/aB3/x+Yz9/Qw==')->assertOk()->assertSee('Synced: 3 new punches');
        $this->get('/cron/machine/aB3/x+Yz9')->assertNotFound();
    }

    public function test_sync_now_is_for_hr_and_admin_only(): void
    {
        $this->actingAs($this->user('viewer'))->post('/attendance/machine/sync')->assertForbidden();
        $hr = $this->user('hr');
        $this->actingAs($hr)->post('/attendance/machine/sync')->assertSessionHas('success', 'Synced: 3 new punches (3 recent checked, 3 on the machine).');
        $this->assertSame(['manual', $hr->id], [MachineSync::sole()->trigger, MachineSync::sole()->user_id]);
        $this->assertSame($hr->id, AttendanceImport::sole()->uploaded_by);
    }

    public function test_nothing_shows_when_no_machine_is_set_up(): void
    {
        config(['hrm.machine.host' => '']);
        $this->actingAs($this->user('hr'))->post('/attendance/machine/sync')->assertNotFound();
        $this->get('/cron/machine/secret-token')->assertOk()->assertSee('No machine set up');
        $this->assertSame(0, $this->machine->connects);
        $this->actingAs($this->user('hr'))->get('/attendance')->assertInertia(fn (AssertableInertia $p) => $p->where('machine', ['configured' => false]));
    }
}
