<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\StatusBoard;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV downloads with the same numbers as the screens. Opens straight in Excel (UTF-8 BOM). */
class ExportController extends Controller
{
    public function __invoke(string $type): StreamedResponse
    {
        $employees = Employee::query()
            ->with(['company:id,name', 'department:id,name', 'team:id,name', 'onboarding'])
            ->when($type !== 'employees', fn ($q) => $q->whereIn('status', ['active', 'offboarding']))
            ->when($type === 'onboarding', fn ($q) => $q->whereHas('onboarding'))
            ->orderBy('full_name')->get();
        $board = new StatusBoard($employees->pluck('id')->all());

        [$header, $rows] = match ($type) {
            'employees' => $this->employees($employees),
            'onboarding' => $this->onboarding($employees, $board),
            'documents' => $this->documents($employees, $board),
        };

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map($this->safeCell(...), $row), escape: '');
            }
            fclose($out);
        }, "$type-".now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Stop spreadsheet formula injection: a text cell starting with = + - @ tab or CR is run as a
     * formula by Excel, so prefix it with ' (Excel shows the text as typed, e.g. "+92 300..." or "-5").
     * Real numbers (ints/floats) are left alone.
     */
    private function safeCell(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'".$value
            : $value;
    }

    private function base(Employee $e): array
    {
        return [$e->employee_code, $e->full_name, $e->company?->name, $e->department?->name];
    }

    private function employees($employees): array
    {
        return [
            ['Code', 'Name', 'Company', 'Department', 'Team', 'Role', 'Employment type', 'Start date', 'Status',
                'Exit date', 'CNIC', 'Email', 'Phone', 'HR.my ID', 'Attendance machine ID', 'Drive folder', 'Needs review', 'System ID'],
            $employees->map(fn ($e) => [...$this->base($e), $e->team?->name, $e->role_title, $e->employment_type,
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
