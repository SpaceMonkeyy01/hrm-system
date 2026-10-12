<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\IssuedLetter;
use Carbon\CarbonImmutable;

/**
 * Fills a letter template's {{fields}} from the employee record. A field with nothing on file is left as
 * [Field name] in the draft and listed, so HR sees the gap before issuing.
 */
class LetterRenderer
{
    /** {{field}} => label HR sees in the template help and the "not on file" list. */
    public const FIELDS = [
        'name' => 'Full name', 'father_name' => "Father's or husband's name", 'cnic' => 'CNIC', 'code' => 'Employee code',
        'role' => 'Role', 'department' => 'Department', 'team' => 'Team', 'company' => 'Company', 'employment_type' => 'Employment type',
        'start_date' => 'Start date', 'confirmation_date' => 'Confirmation date', 'exit_date' => 'Exit date',
        'today' => "Today's date", 'details' => 'Details typed when issuing',
    ];

    /** @return array{body: string, missing: list<string>} */
    public static function render(string $body, Employee $e, ?string $details): array
    {
        $date = fn ($d) => $d ? CarbonImmutable::parse($d)->format('j F Y') : null;
        $values = [
            'name' => $e->full_name, 'father_name' => $e->father_name, 'cnic' => $e->cnic, 'code' => $e->employee_code,
            'role' => $e->role_title, 'department' => $e->department?->name, 'team' => $e->team?->name, 'company' => $e->company?->name,
            'employment_type' => $e->employment_type ? strtolower($e->employment_type) : null,
            'start_date' => $date($e->start_date), 'confirmation_date' => $date($e->confirmation_date), 'exit_date' => $date($e->exit_date),
            'today' => $date(CarbonImmutable::today()), 'details' => $details !== null && trim($details) !== '' ? trim($details) : null,
        ];
        $missing = [];
        $out = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($values, &$missing) {
            $key = $m[1];
            if (! array_key_exists($key, self::FIELDS)) {
                return $m[0];   // unknown field: left as typed, so the template's author notices
            }
            if ($values[$key] === null || $values[$key] === '') {
                $missing[self::FIELDS[$key]] = true;

                return '['.self::FIELDS[$key].']';
            }

            return $values[$key];
        }, $body);

        return ['body' => $out, 'missing' => array_keys($missing)];
    }

    /** "BCC/HR/2026/007": company code, year, then a running number per company and year. */
    public static function nextReference(Employee $e): string
    {
        $prefix = ($e->company?->code ?: 'HR').'/HR/'.CarbonImmutable::today()->year.'/';
        $last = IssuedLetter::where('reference', 'like', $prefix.'%')->pluck('reference')
            ->map(fn ($r) => (int) substr($r, strlen($prefix)))->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT);
    }
}
