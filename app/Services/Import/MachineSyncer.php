<?php

namespace App\Services\Import;

use App\Models\AttendanceImport;
use App\Models\AttendancePunch;
use App\Models\MachineSync;
use App\Support\Zk\AttendanceMachine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads the attendance machine and stores punches we don't have yet, the same way an uploaded Full day log does
 * (same table, same never-twice key), so the two can be mixed freely. Nothing on the machine is changed.
 *
 * Each attempt is a MachineSync row: when, how many new punches, the machine's clock offset, or the error.
 * A sync that adds punches also appears under Uploaded files as "Machine sync".
 */
class MachineSyncer
{
    public const IMPORT_NAME = 'Machine sync';

    public function __construct(private AttendanceMachine $machine) {}

    public static function configured(): bool
    {
        return (string) config('hrm.machine.host') !== '';
    }

    /** Runs one sync. Never throws: a failure is recorded on the returned row (ok false, error). */
    public function run(string $trigger, ?int $userId = null): MachineSync
    {
        $lock = Cache::lock('attendance:machine-sync', 300);
        if (! $lock->get()) {
            return new MachineSync(['trigger' => $trigger, 'ok' => false, 'error' => 'A sync is already running. Try again in a minute.']);
        }
        $sync = MachineSync::create(['trigger' => $trigger, 'user_id' => $userId, 'started_at' => now()]);
        try {
            $this->machine->connect();
            try {
                $clock = $this->machine->time();
                $info = $this->machine->info();
                $names = $this->machine->users();
                $punches = $this->machine->attendance();
            } finally {
                $this->machine->disconnect();
            }
            $offset = CarbonImmutable::parse($clock)->getTimestamp() - now()->getTimestamp();
            [$checked, $import] = $this->store($punches, $names, $userId);
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
     * Saves the punches from a few days before our latest machine punch onwards (everything on the first good sync).
     *
     * @return array{0: int, 1: ?AttendanceImport} punches compared, and the import row when any were new
     */
    private function store(array $punches, array $names, ?int $userId): array
    {
        // The first good sync takes the machine's whole history, even when files were uploaded before.
        $latest = MachineSync::query()->where('ok', true)->exists()
            ? AttendancePunch::query()->where('source', 'machine')->max('punched_at') : null;
        $from = $latest ? CarbonImmutable::parse($latest)->subDays((int) config('hrm.machine.lookback_days'))->format('Y-m-d H:i:s') : null;
        $rows = [];
        foreach ($punches as $p) {
            if ($from === null || $p['time'] >= $from) {
                $rows[$p['user_id'].'|'.$p['time']] = ['source' => 'machine', 'device_user_id' => $p['user_id'], 'device_name' => ($names[$p['user_id']] ?? '') ?: null,
                    'punched_at' => $p['time'], 'work_date' => substr($p['time'], 0, 10), 'method' => $p['method']];
            }
        }
        $checked = count($rows);
        if ($rows) {
            // Drop what we already have first, so a quiet sync writes nothing (no empty "Machine sync" every few minutes).
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

    /** What the Attendance pages show about the machine. */
    public static function status(): array
    {
        if (! self::configured()) {
            return ['configured' => false];
        }
        $last = MachineSync::query()->latest('started_at')->first();
        $lastOk = $last?->ok ? $last : MachineSync::query()->where('ok', true)->latest('started_at')->first();
        $warn = (int) config('hrm.machine.clock_warn_seconds');

        return [
            'configured' => true,
            'last' => $last ? ['at' => $last->started_at->toIso8601String(), 'ok' => $last->ok, 'added' => $last->added, 'error' => $last->error] : null,
            'last_ok_at' => $lastOk?->started_at->toIso8601String(),
            'clock_offset' => $lastOk?->clock_offset,
            'clock_wrong' => $lastOk && $lastOk->clock_offset !== null && abs($lastOk->clock_offset) > $warn,
            'recent' => MachineSync::query()->latest('started_at')->limit(8)->get()->map(fn ($s) => [
                'at' => $s->started_at->toIso8601String(), 'ok' => $s->ok, 'added' => $s->added, 'checked' => $s->checked, 'trigger' => $s->trigger, 'error' => $s->error,
            ])->all(),
        ];
    }

    /** One line for the cron service's log and the command. */
    public static function summary(MachineSync $sync): string
    {
        if (! $sync->ok) {
            return 'Sync failed: '.($sync->error ?? 'unknown error');
        }
        $clock = $sync->clock_offset !== null && abs($sync->clock_offset) > (int) config('hrm.machine.clock_warn_seconds')
            ? ' The machine clock is '.abs($sync->clock_offset).' seconds '.($sync->clock_offset > 0 ? 'fast' : 'slow').'.' : '';

        return "Synced: {$sync->added} new ".($sync->added === 1 ? 'punch' : 'punches')." ({$sync->checked} recent checked, {$sync->machine_records} on the machine).".$clock;
    }
}
