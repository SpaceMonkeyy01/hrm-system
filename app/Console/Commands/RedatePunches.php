<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\AttendancePunch;
use App\Services\AttendanceRules;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remote punches (app, HR.my) saved before the 03:00 rule (AttendanceRules::workDate) carry their calendar date: a
 * check-out at 01:30 sits on the new day. Machine punches are never moved. This moves them to the day before. Shows what would change; --apply saves it, in one
 * transaction, with one line in the activity log. Punches already dated to another day (HR.my's own shift date,
 * an app Out on its In's day) are left alone. Safe to run again: a second run finds nothing.
 */
class RedatePunches extends Command
{
    protected $signature = 'hr:redate-punches {--apply : save the changes (without it, only shows them)}';

    protected $description = 'Move remote (app, HR.my) punches before the day start (03:00) to the day before';

    public function handle(): int
    {
        $moves = [];
        AttendancePunch::query()->whereIn('source', ['app', 'hrmy'])->select(['id', 'source', 'device_user_id', 'punched_at', 'work_date'])->chunkById(2000, function ($punches) use (&$moves) {
            foreach ($punches as $p) {
                $calendar = $p->punched_at->toDateString();
                $day = AttendanceRules::workDate(CarbonImmutable::instance($p->punched_at));
                if ($p->work_date->toDateString() === $calendar && $day !== $calendar) {
                    $moves[$p->id] = [$p->source, $p->device_user_id, $p->punched_at->format('Y-m-d H:i'), $day];
                }
            }
        });

        if (! $moves) {
            $this->info('Nothing to move: every remote punch before the day start already counts for the day before.');

            return self::SUCCESS;
        }
        $this->table(['Source', 'Number', 'Punched at', 'Moves to'], array_slice(array_values($moves), 0, 50));
        if (count($moves) > 50) {
            $this->line('... and '.(count($moves) - 50).' more.');
        }
        $bySource = collect($moves)->countBy(fn ($m) => $m[0])->map(fn ($n, $s) => "$s $n")->implode(', ');
        $summary = count($moves).' punches from before '.config('hrm.attendance.day_starts_at')." to the day before ($bySource).";

        if (! $this->option('apply')) {
            $this->warn("Would move $summary Run again with --apply to save.");

            return self::SUCCESS;
        }
        DB::transaction(function () use ($moves, $summary) {
            foreach (collect($moves)->groupBy(fn ($m) => $m[3], true) as $day => $group) {
                AttendancePunch::query()->whereKey($group->keys()->all())->update(['work_date' => $day]);
            }
            ActivityLog::create(['subject_type' => 'AttendancePunch', 'subject_id' => null, 'event' => 'updated', 'description' => "Moved $summary"]);
        });
        $this->info("Moved $summary");

        return self::SUCCESS;
    }
}
