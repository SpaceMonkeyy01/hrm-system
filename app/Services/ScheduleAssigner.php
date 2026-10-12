<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\EmploymentHistory;
use App\Models\WorkSchedule;
use Illuminate\Support\Facades\DB;

/** Puts people on a work schedule from a date, and notes it in their employment history. */
class ScheduleAssigner
{
    /**
     * @param  iterable<Employee>  $employees
     * @return int how many people changed schedule
     */
    public static function assign(iterable $employees, WorkSchedule $schedule, string $startsOn, ?int $userId): int
    {
        return DB::transaction(function () use ($employees, $schedule, $startsOn, $userId) {
            $changed = 0;
            $names = WorkSchedule::pluck('name', 'id');
            foreach ($employees as $e) {
                $current = EmployeeWorkSchedule::query()->where('employee_id', $e->id)->where('starts_on', '<=', $startsOn)
                    ->orderByDesc('starts_on')->first();
                if ($current?->work_schedule_id === $schedule->id) {
                    continue;
                }
                EmployeeWorkSchedule::updateOrCreate(
                    ['employee_id' => $e->id, 'starts_on' => $startsOn],
                    ['work_schedule_id' => $schedule->id, 'created_by' => $userId],
                );
                EmploymentHistory::create([
                    'employee_id' => $e->id, 'effective_date' => $startsOn, 'change_type' => 'Work schedule change',
                    'changes' => ['Work schedule' => [$current ? $names[$current->work_schedule_id] ?? null : null, $schedule->name]],
                    'created_by' => $userId,
                ]);
                $changed++;
            }

            return $changed;
        });
    }

    /** The schedule in force for each employee today (latest assignment on or before today), keyed by employee id. */
    public static function current(array $employeeIds, string $today): array
    {
        $out = [];
        foreach (EmployeeWorkSchedule::query()->whereIn('employee_id', $employeeIds)->where('starts_on', '<=', $today)
            ->orderBy('starts_on')->get(['employee_id', 'work_schedule_id']) as $a) {
            $out[$a->employee_id] = $a->work_schedule_id;   // later rows overwrite earlier ones
        }

        return $out;
    }
}
