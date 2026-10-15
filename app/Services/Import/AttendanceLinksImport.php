<?php

namespace App\Services\Import;

use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Services\AttendanceLinks;
use App\Support\SpreadsheetRows;
use Illuminate\Support\Facades\DB;

/**
 * Attendance links in Excel: one row per current employee with their machine number, device (ATD-001) and HR.my ID.
 * Download (also a backup of every link), change cells, upload, preview, save.
 *
 *   - Rows are matched on System ID (falls back to Code). Code, name and company are there to read only.
 *   - An empty cell is left alone; a single "-" removes that link.
 *   - A number can belong to one person only, counting the file itself.
 *   - A new machine number with no device code gets the device it punches on.
 */
class AttendanceLinksImport implements SpreadsheetImport
{
    /** Column heading => employee field */
    public const COLUMNS = ['Machine no.' => 'attendance_machine_id', 'Device' => 'attendance_device_id', 'HR.my ID' => 'hr_portal_id'];

    private const READ_ONLY = ['System ID', 'Code', 'Name', 'Company'];

    public static function meta(): array
    {
        return [
            'title' => 'Attendance links in Excel',
            'description' => "Everyone's machine number, device and HR.my ID in one sheet. Change what you need, upload it back and check the changes before they are saved.",
            'back' => ['href' => '/attendance/links', 'label' => 'Links'],
            'key' => 'System ID',
            'tips' => [
                "Don't change the System ID column: it is how each row finds its person.",
                'Empty cells are left alone. Type a single - to remove a link.',
                'Device: the code on Attendance > Devices, e.g. ATD-001. Leave it empty and a new machine number gets the machine it punches on.',
                'A number can belong to one person only. To move one, remove it from the first person in the app, then upload.',
            ],
        ];
    }

    public static function template(): array
    {
        $rows = AttendanceLinks::people()->map(fn (Employee $e) => [
            $e->system_id, $e->employee_code, $e->full_name, $e->company?->name,
            $e->attendance_machine_id, $e->attendanceDevice?->code, $e->hr_portal_id,
        ])->all();

        return [[...self::READ_ONLY, ...array_keys(self::COLUMNS)], $rows];
    }

    public function plan(string $path, string $extension): array
    {
        $rows = SpreadsheetRows::read($path, $extension, 'System ID');
        $people = Employee::query()->get(['id', 'system_id', 'employee_code', 'full_name', 'status', 'attendance_machine_id', 'attendance_device_id', 'hr_portal_id']);
        $bySystemId = $people->keyBy(fn ($e) => mb_strtolower((string) $e->system_id));
        $byCode = $people->keyBy(fn ($e) => mb_strtolower($e->employee_code));
        $devices = AttendanceDevice::query()->get(['id', 'code', 'name']);
        $deviceByCode = $devices->keyBy(fn ($d) => mb_strtolower((string) $d->code));
        $deviceById = $devices->keyBy('id');
        $out = ['rows' => count($rows), 'matched' => 0, 'changes' => [], 'problems' => []];
        $claimed = ['attendance_machine_id' => [], 'hr_portal_id' => []];

        foreach ($rows as $n => $row) {
            $e = $bySystemId->get(mb_strtolower(trim((string) ($row['System ID'] ?? '')))) ?? $byCode->get(mb_strtolower(trim((string) ($row['Code'] ?? ''))));
            $name = trim((string) ($row['Name'] ?? '')) ?: trim((string) ($row['Code'] ?? ''));
            if (! $e || ! in_array($e->status, ['active', 'offboarding'], true)) {
                if (array_filter(array_intersect_key($row, self::COLUMNS))) {
                    $out['problems'][] = ['row' => $n, 'name' => $name, 'message' => $e ? 'Not current staff: links are kept for current staff only. Row skipped.' : 'No employee with this System ID or code. Row skipped.'];
                }

                continue;
            }
            $out['matched']++;
            $machineChange = null;
            foreach (self::COLUMNS as $heading => $field) {
                $raw = trim((string) ($row[$heading] ?? ''));
                if ($raw === '') {
                    continue;
                }
                $remove = $raw === '-';
                $value = $remove ? null : preg_replace('/\.0$/', '', $raw);   // "7.0" from a number cell
                $shown = $remove ? null : $value;
                $error = null;
                if (! $remove && $field === 'attendance_device_id') {
                    $d = $deviceByCode->get(mb_strtolower($value));
                    $error = $d ? null : "no device has code $value (see Attendance > Devices).";
                    $value = $d?->id;
                    $shown = $d ? trim("{$d->code} {$d->name}") : $raw;
                } elseif (! $remove) {
                    if (mb_strlen($value) > 60) {
                        $error = 'is too long.';
                    } else {
                        $owner = $people->first(fn ($p) => (string) $p->{$field} === (string) $value && $p->id !== $e->id);
                        $inFile = $claimed[$field][(string) $value] ?? null;
                        if ($owner) {
                            $error = "$value already belongs to {$owner->full_name} ({$owner->employee_code}).";
                        } elseif ($inFile && $inFile !== $e->id) {
                            $error = "$value is given to two people in this file.";
                        } else {
                            $claimed[$field][(string) $value] = $e->id;
                        }
                    }
                }
                if ($error !== null) {
                    $out['problems'][] = ['row' => $n, 'name' => $e->full_name, 'message' => "$heading: $error"];

                    continue;
                }
                $current = $this->current($e, $field, $deviceById);
                if ($current === $shown) {
                    continue;
                }
                $change = ['employee_id' => $e->id, 'name' => $e->full_name, 'code' => $e->employee_code, 'field' => $field, 'label' => $heading,
                    'old' => $current, 'new' => $shown ?? '(removed)', 'value' => $value, 'replaces' => $current !== null];
                $out['changes'][] = $change;
                if ($field === 'attendance_machine_id') {
                    $machineChange = $change;
                }
            }
            // A new machine number and no device in the file: the device it punches on.
            if ($machineChange && $machineChange['value'] !== null && trim((string) ($row['Device'] ?? '')) === '') {
                $deviceId = AttendancePunch::deviceFor((string) $machineChange['value']);
                if ($deviceId && $deviceId !== $e->attendance_device_id) {
                    $out['changes'][] = ['employee_id' => $e->id, 'name' => $e->full_name, 'code' => $e->employee_code, 'field' => 'attendance_device_id',
                        'label' => 'Device', 'old' => $this->current($e, 'attendance_device_id', $deviceById),
                        'new' => trim($deviceById[$deviceId]->code.' '.$deviceById[$deviceId]->name), 'value' => $deviceId, 'replaces' => (bool) $e->attendance_device_id];
                }
            }
        }

        return $out;
    }

    public function apply(array $plan, ?int $userId): string
    {
        $byEmployee = collect($plan['changes'])->groupBy('employee_id');
        DB::transaction(function () use ($byEmployee) {
            foreach ($byEmployee as $employeeId => $changes) {
                $e = Employee::findOrFail($employeeId);
                foreach ($changes as $c) {
                    $e->{$c['field']} = $c['value'];
                }
                if ($e->attendance_machine_id === null) {
                    $e->attendance_device_id = null;   // no number, no device
                }
                $e->save();
            }
        });
        $n = count($plan['changes']);

        return "Saved $n ".($n === 1 ? 'change' : 'changes').' for '.$byEmployee->count().' '.($byEmployee->count() === 1 ? 'person' : 'people').'.';
    }

    private function current(Employee $e, string $field, $deviceById): ?string
    {
        if ($field === 'attendance_device_id') {
            $d = $e->attendance_device_id ? $deviceById->get($e->attendance_device_id) : null;

            return $d ? trim("{$d->code} {$d->name}") : null;
        }
        $v = $e->{$field};

        return $v === null || $v === '' ? null : (string) $v;
    }
}
