<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Services\JobHistory;
use App\Services\ScheduleAssigner;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Employees list > tick people > change one thing for all of them. Each person is saved like a normal edit, so the job
 * history and the activity log get a row each. Department moves stay inside each person's own company.
 */
class EmployeeBulkController extends Controller
{
    public const FIELDS = ['department', 'manager', 'employment_type', 'work_mode', 'schedule'];

    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
            'field' => ['required', Rule::in(self::FIELDS)],
            'value' => ['nullable'],
            'effective_date' => ['nullable', 'date'],
        ], ['ids.required' => 'Tick at least one person.']);
        $value = $data['value'] ?? null;
        $field = $data['field'];
        $rules = match ($field) {
            'department' => ['required', 'integer', Rule::exists('departments', 'id')],
            'manager' => ['nullable', 'integer', Rule::exists('employees', 'id')->whereIn('status', ['active', 'offboarding'])->whereNull('deleted_at')],
            'employment_type' => ['required', Rule::in(config('hrm.employment_types'))],
            'work_mode' => ['nullable', Rule::in(config('hrm.work_modes'))],
            'schedule' => ['required', 'integer', Rule::exists('work_schedules', 'id')],
        };
        validator(['value' => $value], ['value' => $rules], ['value.required' => 'Choose the new value.'])->validate();

        $date = $data['effective_date'] ?? CarbonImmutable::today()->toDateString();
        $people = Employee::query()->with('team:id,department_id')->whereIn('id', $data['ids'])->whereIn('status', ['active', 'offboarding', 'review'])->get();
        $userId = $request->user()->id;

        if ($field === 'schedule') {
            $n = ScheduleAssigner::assign($people, WorkSchedule::findOrFail($value), $date, $userId);

            return back()->with('success', $n === 1 ? '1 person moved to the new schedule.' : "$n people moved to the new schedule.");
        }

        $dept = $field === 'department' ? Department::findOrFail($value) : null;
        $changed = 0;
        $skipped = [];
        DB::transaction(function () use ($people, $field, $value, $dept, $date, $userId, &$changed, &$skipped) {
            foreach ($people as $e) {
                $update = match ($field) {
                    // Same company only: a move between companies changes the employee code, so it's done one by one.
                    'department' => $e->company_id === $dept->company_id
                        ? ['department_id' => $dept->id] + ($e->team && $e->team->department_id !== $dept->id ? ['team_id' => null] : [])
                        : null,
                    'manager' => (int) $value === $e->id ? null : ['manager_id' => $value ? (int) $value : null],
                    'employment_type' => ['employment_type' => $value],
                    'work_mode' => ['work_mode' => $value ?: null],
                };
                if ($update === null) {
                    $skipped[] = $e->full_name;

                    continue;
                }
                $before = JobHistory::labels($e);
                $e->fill($update);
                if (! $e->isDirty()) {
                    continue;
                }
                $e->save();
                JobHistory::record($e, $before, ['change_effective_date' => $date], $userId);
                $changed++;
            }
        });

        if ($changed === 0 && $skipped) {
            throw ValidationException::withMessages(['value' => $field === 'department'
                ? 'That department is in another company. Moving someone to another company changes their code, so do it on their profile.'
                : 'Nobody can be their own manager.']);
        }
        $msg = $changed === 1 ? '1 person updated.' : "$changed people updated.";
        if ($skipped) {
            $msg .= ' Skipped '.count($skipped).' ('.implode(', ', array_slice($skipped, 0, 3)).(count($skipped) > 3 ? ', ...' : '').'): '
                .($field === 'department' ? 'that department is in another company.' : 'nobody can be their own manager.');
        }

        return back()->with('success', $msg);
    }
}
