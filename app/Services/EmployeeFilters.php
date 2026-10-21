<?php

namespace App\Services;

use App\Models\AttendancePunch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/** The Employees list filters, shared by the list and its CSV export so both show the same people. */
class EmployeeFilters
{
    public static function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'integer'],
            'department' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['current', 'active', 'review', 'offboarding', 'exited', 'all'])],
            'review' => ['nullable', 'boolean'],
            'unassigned' => ['nullable', 'boolean'],
            'type' => ['nullable', 'string', 'max:40'],
            'mode' => ['nullable', 'string', 'max:40'],
            'manager' => ['nullable', 'integer'],
            'no_manager' => ['nullable', 'boolean'],
            'started_from' => ['nullable', 'date_format:Y-m-d'],
            'started_to' => ['nullable', 'date_format:Y-m-d'],
            'login' => ['nullable', 'in:yes,no'],
            'linked' => ['nullable', 'in:yes,no'],
            // Export selected: exactly the people ticked on the list.
            'ids' => ['nullable', 'array', 'max:1000'],
            'ids.*' => ['integer'],
        ];
    }

    public static function apply(Builder $q, array $f): Builder
    {
        $status = $f['status'] ?? 'current';
        // Linked = punches can reach them: a machine number, an HR.my ID, or clock-ins on the app (AttendanceBoard::isLinked).
        $linked = fn ($q) => $q->whereNotNull('attendance_machine_id')->orWhereNotNull('hr_portal_id')
            ->orWhereIn('id', AttendancePunch::query()->where('source', 'app')->distinct()->pluck('device_user_id')->map(fn ($v) => (int) $v)->all());

        return $q->search($f['q'] ?? null)
            ->when($status === 'current', fn ($q) => $q->whereIn('status', ['active', 'offboarding']))
            ->when(in_array($status, ['active', 'review', 'offboarding', 'exited'], true), fn ($q) => $q->where('status', $status))
            ->when($f['company'] ?? null, fn ($q, $v) => $q->where('company_id', $v))
            ->when($f['department'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($f['review'] ?? false, fn ($q) => $q->where('needs_review', true))
            ->when($f['unassigned'] ?? false, fn ($q) => $q->whereNull('company_id'))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('employment_type', $v))
            ->when($f['mode'] ?? null, fn ($q, $v) => $q->where('work_mode', $v))
            ->when($f['manager'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('manager_id', $v)->orWhere('secondary_manager_id', $v)))
            ->when($f['no_manager'] ?? false, fn ($q) => $q->whereNull('manager_id'))
            ->when($f['started_from'] ?? null, fn ($q, $v) => $q->where('start_date', '>=', $v))
            ->when($f['started_to'] ?? null, fn ($q, $v) => $q->where('start_date', '<=', $v))
            ->when(($f['login'] ?? null) === 'yes', fn ($q) => $q->whereHas('user'))
            ->when(($f['login'] ?? null) === 'no', fn ($q) => $q->whereDoesntHave('user'))
            ->when(($f['linked'] ?? null) === 'yes', fn ($q) => $q->where($linked))
            ->when(($f['linked'] ?? null) === 'no', fn ($q) => $q->whereNot($linked))
            ->when($f['ids'] ?? null, fn ($q, $v) => $q->whereIn('id', $v));
    }
}
