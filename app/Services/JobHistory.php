<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentHistory;
use App\Models\Team;
use Carbon\CarbonImmutable;

/** Job changes (company, department, team, role, type, manager, work mode) as employment history rows. */
class JobHistory
{
    /** The job fields as people read them ("HR", not 12), to compare before and after a change. */
    public static function labels(Employee $e): array
    {
        $out = [];
        foreach (Employee::JOB_FIELDS as $field => $label) {
            $out[$label] = match ($field) {
                'company_id' => $e->company_id ? Company::find($e->company_id)?->name : null,
                'department_id' => $e->department_id ? Department::find($e->department_id)?->name : null,
                'team_id' => $e->team_id ? Team::find($e->team_id)?->name : null,
                'manager_id' => $e->manager_id ? Employee::withTrashed()->find($e->manager_id)?->full_name : null,
                default => $e->{$field},
            };
        }

        return $out;
    }

    /**
     * Write one employment_history row when a job field changed. The type is what HR picked, or a guess from what changed
     * (company/department/team = Transfer first). The effective date defaults to today.
     *
     * @param  array{change_effective_date?: ?string, change_type?: ?string, change_note?: ?string}  $change
     */
    public static function record(Employee $employee, array $before, array $change, ?int $userId): void
    {
        $after = self::labels($employee);
        $diff = [];
        foreach ($after as $label => $value) {
            if (($before[$label] ?? null) !== $value) {
                $diff[$label] = [$before[$label] ?? null, $value];
            }
        }
        if (! $diff) {
            return;
        }
        $guess = match (true) {
            (bool) array_intersect_key($diff, array_flip(['Company', 'Department', 'Team'])) => 'Transfer',
            isset($diff['Role']) => 'Role change',
            isset($diff['Employment type']) => 'Employment type change',
            isset($diff['Manager']) => 'Manager change',
            default => 'Work mode change',
        };
        EmploymentHistory::create([
            'employee_id' => $employee->id,
            'effective_date' => $change['change_effective_date'] ?? CarbonImmutable::today()->toDateString(),
            'change_type' => $change['change_type'] ?? $guess,
            'changes' => $diff,
            'note' => $change['change_note'] ?? null,
            'created_by' => $userId,
        ]);
    }
}
