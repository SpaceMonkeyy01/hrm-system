<?php

namespace App\Console\Commands;

use App\Models\AttendanceDevice;
use App\Services\Import\MachineSyncer;
use Illuminate\Console\Command;

/** Reads the attendance devices now. On Render free the same runs from /cron/machine/{CRON_TOKEN}. */
class SyncMachine extends Command
{
    protected $signature = 'hr:sync-machine {device? : device id (default: every active device)}';

    protected $description = 'Read new punches from the attendance devices (read only)';

    public function handle(MachineSyncer $syncer): int
    {
        if ($id = $this->argument('device')) {
            $device = AttendanceDevice::find($id);
            if (! $device) {
                $this->error("No attendance device $id.");

                return self::FAILURE;
            }
            $syncs = [$syncer->run($device, 'command')];
        } else {
            $syncs = $syncer->runAll('command');
        }
        if (! $syncs) {
            $this->error('No active attendance device: add one on Attendance > Devices.');

            return self::FAILURE;
        }
        $ok = ! array_filter($syncs, fn ($s) => ! $s->ok);
        $ok ? $this->info(MachineSyncer::summaries($syncs)) : $this->error(MachineSyncer::summaries($syncs));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
