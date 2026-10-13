<?php

namespace App\Console\Commands;

use App\Services\Import\MachineSyncer;
use Illuminate\Console\Command;

/** Reads the attendance machine now. On Render free the same runs from /cron/machine/{CRON_TOKEN}. */
class SyncMachine extends Command
{
    protected $signature = 'hr:sync-machine';

    protected $description = 'Read new punches from the attendance machine (read only)';

    public function handle(MachineSyncer $syncer): int
    {
        if (! MachineSyncer::configured()) {
            $this->error('No machine set up: set ZK_HOST (and ZK_PORT, ZK_COMM_KEY).');

            return self::FAILURE;
        }
        $sync = $syncer->run('command');
        $sync->ok ? $this->info(MachineSyncer::summary($sync)) : $this->error(MachineSyncer::summary($sync));

        return $sync->ok ? self::SUCCESS : self::FAILURE;
    }
}
