<?php

namespace App\Services\Import;

use App\Models\Employee;
use App\Models\EmploymentHistory;
use App\Support\SpreadsheetRows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * "Fill in details": HR downloads one row per current employee, fills the blanks in Excel and uploads it back.
 *
 *   - Rows are matched on System ID (falls back to Code). Name, company and department are there to read only.
 *   - Only filled-in cells count. An empty cell never clears what is on file.
 *   - A cell that differs from what is on file replaces it, and the preview flags it as a replacement.
 *   - Every value goes through the same checks as the Edit form; a bad cell is skipped and listed, the rest apply.
 *   - Role and manager: filling a blank is not a job change; replacing a value writes employment history.
 */
class EmployeeDetailsImport implements SpreadsheetImport
{
    public static function meta(): array
    {
        return [
            'title' => 'Fill in details',
            'description' => 'Fill in missing details for many people at once in Excel: emails, phones, CNIC and expiry, date of birth, role, manager, machine number and HR.my ID.',
            'back' => ['href' => '/employees', 'label' => 'Employees'],
            'key' => 'System ID',
            'tips' => [
                "Don't change the System ID column: it is how each row finds its person.",
                'Empty cells are left alone. Nothing on file is ever deleted.',
                "Manager: type the manager's employee code, e.g. BCC-012.",
                'Dates: 2026-05-31 or 31/05/2026.',
            ],
        ];
    }

    /** Column heading => [field, label HR sees in the preview] */
    public const COLUMNS = [
        'Work email' => 'email',
        'Phone' => 'phone',
        'Personal email' => 'personal_email',
        'CNIC' => 'cnic',
        'CNIC expiry' => 'cnic_expiry',
        'Date of birth' => 'date_of_birth',
        'Role' => 'role_title',
        'Manager code' => 'manager_id',
        'Machine no.' => 'attendance_machine_id',
        'HR.my ID' => 'hr_portal_id',
    ];

    private const READ_ONLY = ['System ID', 'Code', 'Name', 'Company', 'Department'];

    private const RULES = [
        'email' => ['email', 'max:150'],
        'personal_email' => ['email', 'max:150'],
        'phone' => ['string', 'max:40'],
        'cnic' => ['regex:/^\d{5}-?\d{7}-?\d$/'],
        'cnic_expiry' => ['date_format:Y-m-d'],
        'date_of_birth' => ['date_format:Y-m-d', 'before:today', 'after:1930-01-01'],
        'role_title' => ['string', 'max:150'],
        'attendance_machine_id' => ['string', 'max:60'],
        'hr_portal_id' => ['string', 'max:60'],
    ];

    /** @return array{0: list<string>, 1: list<list<mixed>>} header and rows for Csv::download */
    public static function template(): array
    {
        $people = Employee::query()->whereIn('status', ['active', 'offboarding'])
            ->with(['company:id,name', 'department:id,name', 'manager:id,employee_code'])->orderBy('full_name')->get();
        $rows = $people->map(fn (Employee $e) => [
            $e->system_id, $e->employee_code, $e->full_name, $e->company?->name, $e->department?->name,
            $e->email, $e->phone, $e->personal_email, $e->cnic, $e->cnic_expiry?->toDateString(), $e->date_of_birth?->toDateString(),
            $e->role_title, $e->manager?->employee_code, $e->attendance_machine_id, $e->hr_portal_id,
        ])->all();

        return [[...self::READ_ONLY, ...array_keys(self::COLUMNS)], $rows];
    }

    /**
     * What the file would change, without saving anything.
     *
     * @return array{rows: int, matched: int, changes: list<array{employee_id: int, name: string, code: string, field: string, label: string, old: ?string, new: string, value: mixed, replaces: bool}>,
     *     problems: list<array{row: int, name: string, message: string}>}
     */
    public function plan(string $path, string $extension): array
    {
        $rows = SpreadsheetRows::read($path, $extension, 'System ID');
        $people = Employee::query()->get(['id', 'system_id', 'employee_code', 'full_name', 'status', 'manager_id', ...array_values(self::COLUMNS)]);
        $bySystemId = $people->keyBy(fn ($e) => mb_strtolower((string) $e->system_id));
        $byCode = $people->keyBy(fn ($e) => mb_strtolower($e->employee_code));
        $out = ['rows' => count($rows), 'matched' => 0, 'changes' => [], 'problems' => []];
        // A machine number / HR.my ID may belong to one person only, counting the file itself.
        $claimed = ['attendance_machine_id' => [], 'hr_portal_id' => []];

        foreach ($rows as $n => $row) {
            $e = $bySystemId->get(mb_strtolower(trim((string) ($row['System ID'] ?? '')))) ?? $byCode->get(mb_strtolower(trim((string) ($row['Code'] ?? ''))));
            $name = trim((string) ($row['Name'] ?? '')) ?: trim((string) ($row['Code'] ?? ''));
            if (! $e) {
                if (array_filter(array_intersect_key($row, self::COLUMNS))) {
                    $out['problems'][] = ['row' => $n, 'name' => $name, 'message' => 'No employee with this System ID or code. Row skipped.'];
                }

                continue;
            }
            $out['matched']++;
            foreach (self::COLUMNS as $heading => $field) {
                $raw = trim((string) ($row[$heading] ?? ''));
                if ($raw === '') {
                    continue;
                }
                [$value, $shown, $error] = $this->clean($field, $raw, $e, $byCode);
                if ($error === null && isset($claimed[$field])) {
                    $owner = $people->first(fn ($p) => (string) $p->{$field} === (string) $value && $p->id !== $e->id);
                    $inFile = $claimed[$field][(string) $value] ?? null;
                    if ($owner) {
                        $error = "$heading $value already belongs to {$owner->full_name} ({$owner->employee_code}).";
                    } elseif ($inFile && $inFile !== $e->id) {
                        $error = "$heading $value is given to two people in this file.";
                    } else {
                        $claimed[$field][(string) $value] = $e->id;
                    }
                }
                if ($error !== null) {
                    $out['problems'][] = ['row' => $n, 'name' => $e->full_name, 'message' => "$heading: $error"];

                    continue;
                }
                $current = $this->current($e, $field, $people);
                if ($current === $shown) {
                    continue;
                }
                $out['changes'][] = ['employee_id' => $e->id, 'name' => $e->full_name, 'code' => $e->employee_code, 'field' => $field, 'label' => $heading,
                    'old' => $current, 'new' => $shown, 'value' => $value, 'replaces' => $current !== null];
            }
        }

        return $out;
    }

    public function apply(array $plan, ?int $userId): string
    {
        $byEmployee = collect($plan['changes'])->groupBy('employee_id');

        DB::transaction(function () use ($byEmployee, $userId) {
            foreach ($byEmployee as $employeeId => $changes) {
                $e = Employee::findOrFail($employeeId);
                $history = [];
                foreach ($changes as $c) {
                    if ($c['replaces'] && in_array($c['field'], ['role_title', 'manager_id'], true)) {
                        $history[$c['field'] === 'role_title' ? 'Role' : 'Manager'] = [$c['old'], $c['new']];
                    }
                    $e->{$c['field']} = $c['value'];
                }
                $e->save();
                if ($history) {
                    EmploymentHistory::create(['employee_id' => $e->id, 'effective_date' => CarbonImmutable::today()->toDateString(),
                        'change_type' => isset($history['Role']) ? 'Role change' : 'Manager change', 'changes' => $history,
                        'note' => 'From the Fill in details upload', 'created_by' => $userId]);
                }
            }
        });
        $n = count($plan['changes']);

        return "Saved $n ".($n === 1 ? 'detail' : 'details').' for '.$byEmployee->count().' '.($byEmployee->count() === 1 ? 'person' : 'people').'.';
    }

    // ------------------------------------------------------------------

    /** @return array{0: mixed, 1: string, 2: ?string} value to store, value to show, error */
    private function clean(string $field, string $raw, Employee $e, $byCode): array
    {
        if ($field === 'manager_id') {
            $m = $byCode->get(mb_strtolower($raw));
            if (! $m || $m->status === 'exited') {
                return [null, $raw, "no current employee has code $raw."];
            }
            if ($m->id === $e->id) {
                return [null, $raw, "someone can't be their own manager."];
            }

            return [$m->id, "{$m->full_name} ({$m->employee_code})", null];
        }
        $value = match ($field) {
            'email', 'personal_email' => mb_strtolower($raw),
            // Excel drops the leading 0 of a mobile number typed as a number: 3001234567 -> 03001234567.
            'phone' => preg_match('/^3\d{9}$/', $raw) ? '0'.$raw : $raw,
            'cnic_expiry', 'date_of_birth' => self::date($raw) ?? $raw,
            'attendance_machine_id', 'hr_portal_id' => preg_replace('/\.0$/', '', $raw),   // "7.0" from a number cell
            default => $raw,
        };
        $v = Validator::make(['x' => $value], ['x' => self::RULES[$field]], [
            'x.regex' => 'must be 13 digits, like 35202-1234567-1.', 'x.email' => "$raw isn't an email address.",
            'x.date_format' => "$raw isn't a date. Use 2026-05-31 or 31/05/2026.", 'x.before' => "$raw is in the future.", 'x.after' => "$raw is too long ago.",
            'x.max' => 'is too long.',
        ]);
        if ($v->fails()) {
            return [null, $raw, $v->errors()->first('x')];
        }
        if ($field === 'cnic') {
            $d = preg_replace('/\D/', '', $value);
            $value = substr($d, 0, 5).'-'.substr($d, 5, 7).'-'.substr($d, 12, 1);
        }

        return [$value, (string) $value, null];
    }

    private function current(Employee $e, string $field, $people): ?string
    {
        if ($field === 'manager_id') {
            $m = $e->manager_id ? $people->firstWhere('id', $e->manager_id) : null;

            return $m ? "{$m->full_name} ({$m->employee_code})" : null;
        }
        $v = $e->{$field};

        return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : ($v === null || $v === '' ? null : (string) $v);
    }

    /** 2026-05-31, 31/05/2026, 31-05-2026, 31.05.2026, 31 May 2026, 31-May-2026 => 2026-05-31. Day first, as in Pakistan. */
    public static function date(string $raw): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'j M Y', 'd-M-Y', 'j-M-Y', 'd M Y', 'j/n/Y'] as $format) {
            try {
                $d = CarbonImmutable::createFromFormat('!'.$format, $raw);
            } catch (\Throwable) {
                continue;   // Carbon throws on text that doesn't fit the format
            }
            if ($d && $d->format($format) === $raw) {
                return $d->toDateString();
            }
        }

        return null;
    }
}
