<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\EmployeeFilters;
use App\Services\StatusBoard;
use App\Support\Csv;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV downloads with the same numbers as the screens. Opens straight in Excel (UTF-8 BOM). */
class ExportController extends Controller
{
    public function __invoke(Request $request, string $type): StreamedResponse
    {
        // Employees: exactly who the list shows with its filters (the button sends them). A bare link exports everyone.
        $filters = $type === 'employees' ? $request->validate(EmployeeFilters::rules()) + ['status' => 'all'] : null;
        $employees = Employee::query()
            ->with(['company:id,name', 'department:id,name', 'team:id,name', 'onboarding'])
            ->when($filters !== null, fn ($q) => EmployeeFilters::apply($q, $filters))
            ->when($type !== 'employees', fn ($q) => $q->whereIn('status', ['active', 'offboarding']))
            ->when($type === 'onboarding', fn ($q) => $q->whereHas('onboarding'))
            ->orderBy('full_name')->get();
        $board = new StatusBoard($employees->pluck('id')->all());

        [$header, $rows] = match ($type) {
            'employees' => $this->employees($employees),
            'onboarding' => $this->onboarding($employees, $board),
            'documents' => $this->documents($employees, $board),
        };

        return Csv::download("$type-".now()->format('Y-m-d').'.csv', $header, $rows);
    }

    private function base(Employee $e): array
    {
        return [$e->employee_code, $e->full_name, $e->company?->name, $e->department?->name];
    }

    private function employees($employees): array
    {
        return [
            ['Code', 'Name', 'Company', 'Department', 'Team', 'Role', 'Employment type', 'Work mode', 'Start date', 'Status',
                'Exit date', 'CNIC', 'Email', 'Phone', 'HR.my ID', 'Attendance machine ID', 'Drive folder', 'Needs review', 'System ID'],
            $employees->map(fn ($e) => [...$this->base($e), $e->team?->name, $e->role_title, $e->employment_type, $e->work_mode,
                $e->start_date?->toDateString(), $e->status, $e->exit_date?->toDateString(), $e->cnic, $e->email, $e->phone,
                $e->hr_portal_id, $e->attendance_machine_id, $e->drive_folder_url, $e->needs_review ? 'Yes' : '', $e->system_id])->all(),
        ];
    }

    private function onboarding($employees, StatusBoard $board): array
    {
        $onb = $board->columns('onboarding');
        $legal = array_values(array_filter($board->columns('document'), fn ($c) => in_array($c['key'], ['policy_acknowledgment', 'nda_signed', 'nca_signed'], true)));

        return [
            ['Code', 'Name', 'Company', 'Department', 'Group', 'Start date', 'Probation end', 'Probation result',
                ...array_column($onb, 'label'), ...array_column($legal, 'label'), 'Orientation', 'Documents', 'Onboarding status'],
            $employees->map(function ($e) use ($board, $onb, $legal) {
                $o = $board->values($e->id, 'onboarding');
                $d = $board->values($e->id, 'document');

                return [...$this->base($e), $e->onboarding?->cohort, $e->start_date?->toDateString(),
                    $e->onboarding?->probation_end_date?->toDateString(), $e->onboarding?->probation_result,
                    ...array_map(fn ($c) => $o[$c['key']] ?? '', $onb), ...array_map(fn ($c) => $d[$c['key']] ?? '', $legal),
                    $board->orientation($e->id), $board->documents($e->id), $board->onboarding($e->id, $e->onboarding?->probation_result)];
            })->all(),
        ];
    }

    private function documents($employees, StatusBoard $board): array
    {
        $cols = $board->columns('document');

        return [
            ['Code', 'Name', 'Company', 'Department', ...array_column($cols, 'label'), 'Status', 'Drive folder'],
            $employees->map(function ($e) use ($board, $cols) {
                $d = $board->values($e->id, 'document');

                return [...$this->base($e), ...array_map(fn ($c) => $d[$c['key']] ?? '', $cols), $board->documents($e->id), $e->drive_folder_url];
            })->all(),
        ];
    }
}
