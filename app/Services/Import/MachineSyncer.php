<?php

namespace App\Services\Import;

use App\Models\AttendanceDevice;
use App\Models\AttendanceImport;
use App\Models\AttendancePunch;
use App\Models\MachineSync;
use App\Support\Zk\AttendanceMachine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads attendance machines (Attendance > Devices) and stores punches we don't have yet, the same way an uploaded
 * Full day log does (same table, same never-twice key), so the two can be mixed freely. Nothing on a machine is
 * changed: the connection is read only (ZkClient).
 *
 * Each attempt is a MachineSync row for its device: when, how many new punches, the machine's clock offset, or the
 * error. A sync that adds punches also appears under Uploaded files as "Machine sync", and its punches carry the
 * device. Machine numbers link to people through employees.attendance_machine_id on every device alike, so enrol a
 * person under the same number on each machine.
 */
class MachineSyncer
{
    public const IMPORT_NAME = 'Machine sync';

    /** Devices that are switched on. */
    public static function devices(): Collection
    {
        return AttendanceDevice::query()->where('is_active', true)->orderBy('name')->get();
    }

    public static function configured(): bool
    {
        return AttendanceDevice::query()->where('is_active', true)->exists();
    }

    /** The machine connection for $device. A fixed instance (tests: app()->instance(AttendanceMachine, fake)) wins:
     *  make() with parameters would skip it and build a real connection. */
    public static function connection(AttendanceDevice $device): AttendanceMachine
    {
        return app()->isShared(AttendanceMachine::class) ? app(AttendanceMachine::class) : app()->make(AttendanceMachine::class, ['device' => $device]);
    }

    /**
     * Syncs every active device, one after the other (automatic runs skip devices with auto_sync off).
     *
     * @return list<MachineSync>
     */
    public function runAll(string $trigger, ?int $userId = null): array
    {
        return self::devices()->filter(fn (AttendanceDevice $d) => $trigger !== 'cron' || $d->auto_sync)
            ->map(fn (AttendanceDevice $d) => $this->run($d, $trigger, $userId))->values()->all();
    }

    /** Runs one sync. Never throws: a failure is recorded on the returned row (ok false, error). */
    public function run(AttendanceDevice $device, string $trigger, ?int $userId = null): MachineSync
    {
        $lock = Cache::lock('attendance:machine-sync:'.$device->id, 300);
        if (! $lock->get()) {
            return (new MachineSync(['attendance_device_id' => $device->id, 'trigger' => $trigger, 'ok' => false,
                'error' => 'A sync is already running. Try again in a minute.']))->setRelation('device', $device);
        }
        $sync = MachineSync::create(['attendance_device_id' => $device->id, 'trigger' => $trigger, 'user_id' => $userId, 'started_at' => now()]);
        $sync->setRelation('device', $device);
        try {
            $machine = self::connection($device);
            $machine->connect();
            try {
                $clock = $machine->time();
                $info = $machine->info();
                $names = $machine->users();
                $punches = $machine->attendance();
            } finally {
                $machine->disconnect();
            }
            $offset = CarbonImmutable::parse($clock)->getTimestamp() - now()->getTimestamp();
            self::saveInfo($device, $info);
            [$checked, $import] = $this->store($device, $punches, $names, $userId);
            $sync->update(['ok' => true, 'finished_at' => now(), 'machine_records' => $info['records'], 'checked' => $checked,
                'added' => $import?->punches_added ?? 0, 'clock_offset' => $offset, 'attendance_import_id' => $import?->id]);
        } catch (Throwable $e) {
            report($e);
            $sync->update(['ok' => false, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 500)]);
        } finally {
            $lock->release();
        }
        MachineSync::query()->where('started_at', '<', now()->subDays(30))->delete();

        return $sync;
    }

    /**
     * Test connection: reads what the machine says about itself and its clock, nothing else.
     *
     * @return array{ok: bool, error?: string, info?: array, clock_offset?: int}
     */
    public static function test(AttendanceDevice $device): array
    {
        try {
            $machine = self::connection($device);
            $machine->connect();
            try {
                $clock = $machine->time();
                $info = $machine->info();
            } finally {
                $machine->disconnect();
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 500)];
        }
        self::saveInfo($device, $info);

        return ['ok' => true, 'info' => $info, 'clock_offset' => CarbonImmutable::parse($clock)->getTimestamp() - now()->getTimestamp()];
    }

    /** What the machine said about itself. Quietly: a sync every few minutes isn't an HR change to log. */
    private static function saveInfo(AttendanceDevice $device, array $info): void
    {
        $device->forceFill(['model_name' => ($info['name'] ?? '') ?: null, 'serial' => ($info['serial'] ?? '') ?: null,
            'firmware' => ($info['firmware'] ?? '') ?: null, 'user_count' => $info['users'] ?? null, 'record_count' => $info['records'] ?? null,
            'info_read_at' => now()])->saveQuietly();
    }

    /**
     * Saves the punches from a few days before this device's latest punch onwards (everything on its first good sync).
     *
     * @return array{0: int, 1: ?AttendanceImport} punches compared, and the import row when any were new
     */
    private function store(AttendanceDevice $device, array $punches, array $names, ?int $userId): array
    {
        // The first good sync takes the machine's whole history, even when files were uploaded before.
        $latest = MachineSync::query()->where('attendance_device_id', $device->id)->where('ok', true)->exists()
            ? AttendancePunch::query()->where('attendance_device_id', $device->id)->max('punched_at') : null;
        $from = $latest ? CarbonImmutable::parse($latest)->subDays($device->lookback_days)->format('Y-m-d H:i:s') : null;
        $rows = [];
        foreach ($punches as $p) {
            if ($from === null || $p['time'] >= $from) {
                $rows[$p['user_id'].'|'.$p['time']] = ['source' => 'machine', 'device_user_id' => $p['user_id'], 'device_name' => ($names[$p['user_id']] ?? '') ?: null,
                    'punched_at' => $p['time'], 'work_date' => substr($p['time'], 0, 10), 'method' => $p['method'], 'attendance_device_id' => $device->id];
            }
        }
        $checked = count($rows);
        if ($rows) {
            // Drop what we already have first (from any device or file), so a quiet sync writes nothing
            // (no empty "Machine sync" every few minutes).
            $times = array_column($rows, 'punched_at');
            AttendancePunch::query()->where('source', 'machine')->whereBetween('punched_at', [min($times), max($times)])
                ->toBase()->get(['device_user_id', 'punched_at'])
                ->each(function ($p) use (&$rows) {
                    unset($rows[$p->device_user_id.'|'.substr((string) $p->punched_at, 0, 19)]);
                });
        }
        if (! $rows) {
            return [$checked, null];
        }

        return [$checked, DB::transaction(function () use ($rows, $userId) {
            $dates = array_column($rows, 'work_date');
            $import = AttendanceImport::create(['source' => 'machine', 'original_name' => self::IMPORT_NAME, 'path' => null,
                'punches_found' => count($rows), 'date_from' => min($dates), 'date_to' => max($dates), 'uploaded_by' => $userId]);
            $added = 0;
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                $added += AttendancePunch::query()->insertOrIgnore(array_map(fn ($r) => $r + ['attendance_import_id' => $import->id, 'created_at' => now()], $chunk));
            }
            $import->update(['punches_added' => $added]);

            return $import;
        })];
    }

    /**
     * What the Attendance pages show: each active device's last sync and clock.
     *
     * @return array{configured: bool, devices?: list<array>}
     */
    public static function status(): array
    {
        $devices = self::devices();
        if ($devices->isEmpty()) {
            return ['configured' => false];
        }

        return ['configured' => true, 'devices' => $devices->map(fn (AttendanceDevice $d) => self::deviceStatus($d))->all()];
    }

    /** One device's last sync, last good sync, clock and the recent tries. */
    public static function deviceStatus(AttendanceDevice $d, int $recent = 8): array
    {
        $last = MachineSync::query()->where('attendance_device_id', $d->id)->latest('started_at')->latest('id')->first();
        $lastOk = $last?->ok ? $last : MachineSync::query()->where('attendance_device_id', $d->id)->where('ok', true)->latest('started_at')->latest('id')->first();

        return [
            'id' => $d->id,
            'name' => $d->name,
            'last' => $last ? ['at' => $last->started_at->toIso8601String(), 'ok' => $last->ok, 'added' => $last->added, 'error' => $last->error] : null,
            'last_ok_at' => $lastOk?->started_at->toIso8601String(),
            'clock_offset' => $lastOk?->clock_offset,
            'clock_wrong' => $lastOk && $lastOk->clock_offset !== null && abs($lastOk->clock_offset) > $d->clock_warn_seconds,
            'recent' => MachineSync::query()->where('attendance_device_id', $d->id)->latest('started_at')->latest('id')->limit($recent)->get()->map(fn ($s) => [
                'at' => $s->started_at->toIso8601String(), 'ok' => $s->ok, 'added' => $s->added, 'checked' => $s->checked, 'trigger' => $s->trigger, 'error' => $s->error,
            ])->all(),
        ];
    }

    /** One line for the cron service's log, the command and the flash message. */
    public static function summary(MachineSync $sync): string
    {
        $device = $sync->relationLoaded('device') ? $sync->device : null;
        if (! $sync->ok) {
            return 'Sync failed: '.($sync->error ?? 'unknown error');
        }
        $warn = $device?->clock_warn_seconds ?? 60;
        $clock = $sync->clock_offset !== null && abs($sync->clock_offset) > $warn
            ? ' The machine clock is '.abs($sync->clock_offset).' seconds '.($sync->clock_offset > 0 ? 'fast' : 'slow').'.' : '';

        return "Synced: {$sync->added} new ".($sync->added === 1 ? 'punch' : 'punches')." ({$sync->checked} recent checked, {$sync->machine_records} on the machine).".$clock;
    }

    /**
     * Several syncs as text: one device reads as its summary; more get "Name: summary" per line.
     *
     * @param  list<MachineSync>  $syncs
     */
    public static function summaries(array $syncs, string $glue = "\n"): string
    {
        if (count($syncs) === 1) {
            return self::summary($syncs[0]);
        }

        return implode($glue, array_map(fn (MachineSync $s) => ($s->device?->name ?? 'Device').': '.self::summary($s), $syncs));
    }
}
