<?php

namespace App\Services\Import;

use App\Models\Category;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Onboarding;
use App\Models\Team;
use App\Services\HrRules;
use App\Support\Xlsx\XlsxReader;
use DateTimeImmutable;
use RuntimeException;

/**
 * Employee_Master.xlsx: the cleaned list of current BCC and Emerge staff (tab Employee_Master), plus Odoo records
 * nobody has confirmed as current (tab For_Review), with the allowed values in tab Lookups.
 *
 * validate() checks every row and returns the full list of problems (rules V1-V10) before anything is written;
 * seed() then writes companies, departments, teams, categories and employees, links managers by code in a second
 * pass, and adds the For_Review rows as REV-### employees with status "review".
 *
 * Columns are read by header name, never by position. README and Team_Map are ignored, and so are the
 * manager_*_name formula columns (the codes are the link).
 */
class EmployeeMasterImporter
{
    public const MASTER = 'Employee_Master';

    public const REVIEW = 'For_Review';

    public const LOOKUPS = 'Lookups';

    /** Branch => company. "Lahore Office" is a BCC office. */
    public const BRANCHES = ['BCC' => 'BCC', 'Emerge' => 'Emerge', 'Lahore Office' => 'BCC'];

    public const COMPANIES = [
        'BCC' => ['code' => 'BCC', 'employee_code_prefix' => 'BCC-', 'employee_code_digits' => 3],
        'Emerge' => ['code' => 'EMG', 'employee_code_prefix' => 'EMG-', 'employee_code_digits' => 3],
    ];

    private const MASTER_COLUMNS = ['employee_code', 'full_name', 'status', 'branch', 'department', 'department_2', 'team',
        'designation', 'categories', 'employment_type', 'probation_status', 'probation_end_date', 'manager_1_code',
        'manager_1_unresolved', 'manager_2_code', 'manager_2_unresolved', 'gender', 'work_email', 'work_phone', 'cnic',
        'join_date', 'review_flags', 'hr_reviewed'];

    private const REVIEW_COLUMNS = ['legacy_odoo_sr_no', 'name', 'status', 'branch', 'department', 'category',
        'reporting_manager', 'review_reason', 'similar_current_employee', 'reports_in_odoo', 'hr_decision', 'hr_notes'];

    /** For_Review columns written into review_note as "key: value" lines, in this order. */
    private const REVIEW_NOTE_COLUMNS = ['reporting_manager', 'review_reason', 'similar_current_employee', 'hr_decision', 'hr_notes'];

    private const REVIEW_LEGACY_COLUMNS = ['legacy_odoo_sr_no', 'reports_in_odoo'];

    private const STATUSES = ['active' => 'active', 'inactive' => 'exited', 'review' => 'review'];

    private XlsxReader $xlsx;

    /** @var array<int, array<string, string>> sheet row => [header => value] */
    private array $master = [];

    /** @var array<int, array<string, string>> */
    private array $review = [];

    /** @var array<string, list<string>> Lookups header => allowed values */
    private array $lookups = [];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<array{sheet: string, row: int, name: string, detail: string}> */
    private array $warnings = [];

    /** @var array<string, int> */
    private array $stats = [];

    public function __construct(private string $path) {}

    /** @return list<string> every problem found; empty means the file can be imported */
    public function validate(): array
    {
        $this->xlsx = new XlsxReader($this->path);
        foreach ([self::MASTER, self::LOOKUPS] as $sheet) {
            if (! $this->xlsx->hasSheet($sheet)) {
                throw new RuntimeException("The workbook has no sheet named \"$sheet\". Found: ".implode(', ', $this->xlsx->sheetNames()));
            }
        }
        $this->errors = $this->warnings = [];
        $this->lookups = $this->readLookups();
        $this->master = $this->table(self::MASTER, self::MASTER_COLUMNS);
        $this->review = $this->xlsx->hasSheet(self::REVIEW) ? $this->table(self::REVIEW, self::REVIEW_COLUMNS) : [];
        if ($this->errors) {
            return $this->errors;       // V10: no point checking rows against columns that are not there
        }

        $codes = [];
        $emails = [];
        foreach ($this->master as $r => $row) {
            $at = self::MASTER." row $r";
            $code = $row['employee_code'];
            // V1
            if ($code === '') {
                $this->errors[] = "$at: employee_code is empty.";
            } elseif (! preg_match('/^(BCC|EMG)-\d{3}$/', $code)) {
                $this->errors[] = "$at: employee_code \"$code\" must look like BCC-001 or EMG-001.";
            } elseif (isset($codes[$code])) {
                $this->errors[] = "$at: employee_code $code is also used on row {$codes[$code]}.";
            } else {
                $codes[$code] = $r;
            }
            if ($row['full_name'] === '') {
                $this->errors[] = "$at: full_name is empty.";
            }
            // V2
            $this->inLookup($at, 'status', $row['status'], 'Status', required: true);
            $this->inLookup($at, 'branch', $row['branch'], 'Branch', required: true);
            $this->inLookup($at, 'department', $row['department'], 'Department', required: true);
            $this->inLookup($at, 'department_2', $row['department_2'], 'Department');
            foreach ($this->split($row['categories']) as $category) {
                $this->inLookup($at, 'categories', $category, 'Category');
            }
            $this->inLookup($at, 'employment_type', $row['employment_type'], 'Employment Type');
            $this->inLookup($at, 'probation_status', $row['probation_status'], 'Probation Status');
            $this->inLookup($at, 'gender', $row['gender'], 'Gender');
            if ($row['hr_reviewed'] !== '' && ! in_array(strtolower($row['hr_reviewed']), ['yes', 'no'], true)) {
                $this->errors[] = "$at: hr_reviewed \"{$row['hr_reviewed']}\" must be Yes, No or blank.";
            }
            if ($this->employmentType($row['employment_type']) === null && $row['employment_type'] !== ''
                && $this->allowed('Employment Type', $row['employment_type'])) {
                $this->errors[] = "$at: employment_type \"{$row['employment_type']}\" has no match in the app (".implode(', ', config('hrm.employment_types')).').';
            }
            foreach (['probation_end_date', 'join_date'] as $col) {
                if ($row[$col] !== '' && $this->date($row[$col]) === null) {
                    $this->errors[] = "$at: $col \"{$row[$col]}\" is not a date (use yyyy-mm-dd).";
                }
            }
            // V5
            if ($row['work_email'] !== '') {
                $email = strtolower($row['work_email']);
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->errors[] = "$at: work_email \"{$row['work_email']}\" is not a valid email.";
                } elseif (isset($emails[$email])) {
                    $this->errors[] = "$at: work_email $email is also used on row {$emails[$email]}.";
                } else {
                    $emails[$email] = $r;
                }
            }
            // V6
            if ($row['cnic'] !== '' && ! preg_match('/^\d{5}-\d{7}-\d$/', $row['cnic'])) {
                $this->errors[] = "$at: cnic \"{$row['cnic']}\" must look like 36302-1234567-1.";
            }
            // V7
            $company = self::BRANCHES[$row['branch']] ?? null;
            if ($company && preg_match('/^(BCC|EMG)-/', $code) && ! str_starts_with($code, self::COMPANIES[$company]['employee_code_prefix'])) {
                $this->errors[] = "$at: $code does not match branch {$row['branch']} (codes there start with ".self::COMPANIES[$company]['employee_code_prefix'].').';
            }
            // V8
            foreach (['manager_1_unresolved', 'manager_2_unresolved'] as $col) {
                if ($row[$col] !== '') {
                    $this->warnings[] = ['sheet' => self::MASTER, 'row' => $r, 'name' => $row['full_name'],
                        'detail' => "$col: \"{$row[$col]}\". Set the manager in the app; marked for review."];
                }
            }
        }

        // V3 + V4 need every code first
        $managerOf = [];
        foreach ($this->master as $r => $row) {
            foreach (['manager_1_code', 'manager_2_code'] as $col) {
                $m = $row[$col];
                if ($m === '') {
                    continue;
                }
                if ($m === $row['employee_code']) {
                    $this->errors[] = self::MASTER." row $r: $col is the row's own code ($m).";
                } elseif (! isset($codes[$m])) {
                    $this->errors[] = self::MASTER." row $r: $col $m is not an employee_code on ".self::MASTER.'.';
                } elseif ($col === 'manager_1_code') {
                    $managerOf[$row['employee_code']] = $m;
                }
            }
        }
        $reported = [];
        foreach (array_keys($managerOf) as $start) {
            $seen = [$start => true];
            for ($c = $managerOf[$start]; $c !== null; $c = $managerOf[$c] ?? null) {
                if (isset($seen[$c])) {
                    // Only a loop back to $start is reported here; one further up is reported from its own members.
                    $key = $this->loopKey(array_keys($seen));
                    if ($c === $start && ! isset($reported[$key])) {
                        $reported[$key] = true;
                        $this->errors[] = 'Reporting loop through manager_1_code: '.implode(' > ', [...array_keys($seen), $c]).'.';
                    }
                    break;
                }
                $seen[$c] = true;
            }
        }

        foreach ($this->review as $r => $row) {
            $at = self::REVIEW." row $r";
            if ($row['name'] === '') {
                $this->errors[] = "$at: name is empty.";
            }
            $this->inLookup($at, 'status', $row['status'], 'Status', required: true);
            if (strcasecmp($row['status'], 'Active') === 0) {      // V9
                $this->errors[] = "$at: {$row['name']} has status Active. Move this row to ".self::MASTER.' first.';
            }
            $this->inLookup($at, 'branch', $row['branch'], 'Branch', required: true);
            $this->inLookup($at, 'department', $row['department'], 'Department');
            foreach ($this->split($row['category']) as $category) {
                $this->inLookup($at, 'category', $category, 'Category');
            }
            $this->inLookup($at, 'hr_decision', $row['hr_decision'], 'HR Decision');
        }

        return $this->errors;
    }

    /**
     * Write everything. Call validate() first and only when it returned no errors; run inside a transaction.
     * Every employee is added to $matcher, so the onboarding workbook and the link carry-over can find them by name.
     *
     * @return array{stats: array<string, int>, warnings: list<array{sheet: string, row: int, name: string, detail: string}>}
     */
    public function seed(NameMatcher $matcher): array
    {
        $companies = [];
        foreach (self::COMPANIES as $name => $def) {
            $company = Company::firstOrCreate(['name' => $name], $def);
            $company->update($def);
            $companies[$name] = $company;
        }
        foreach ($this->lookups['Category'] ?? [] as $name) {
            Category::firstOrCreate(['name' => $name]);
        }
        $categoryIds = Category::pluck('id', 'name')->all();

        // Pass 1: employees, no managers yet
        $byCode = [];
        foreach ($this->master as $r => $row) {
            $company = $companies[self::BRANCHES[$row['branch']]];
            $department = $this->department($company, $row['department']);
            $flags = array_filter([$row['review_flags'], ...array_map(
                fn ($c) => $row[$c] !== '' ? "$c: {$row[$c]}" : '', ['manager_1_unresolved', 'manager_2_unresolved'])]);
            $unresolved = $row['manager_1_unresolved'] !== '' || $row['manager_2_unresolved'] !== '';
            $reviewed = strcasecmp($row['hr_reviewed'], 'Yes') === 0;
            $start = $this->date($row['join_date']);

            $employee = Employee::create([
                'employee_code' => $row['employee_code'],
                'company_id' => $company->id,
                ...$this->names($row['full_name']),
                'status' => self::STATUSES[strtolower($row['status'])],
                'department_id' => $department->id,
                'secondary_department_id' => $row['department_2'] !== '' ? $this->department($company, $row['department_2'])->id : null,
                'team_id' => $row['team'] !== '' ? Team::firstOrCreate(['department_id' => $department->id, 'name' => $this->clean($row['team'])])->id : null,
                'role_title' => $row['designation'] ?: null,
                'employment_type' => $this->employmentType($row['employment_type']),
                'gender' => $this->allowed('Gender', $row['gender']),
                'email' => $row['work_email'] !== '' ? strtolower($row['work_email']) : null,
                'phone' => $row['work_phone'] ?: null,
                'cnic' => $row['cnic'] ?: null,
                'start_date' => $start,
                'needs_review' => $unresolved || ($flags && ! $reviewed),
                'review_note' => $flags ? implode(' | ', $flags) : null,
                'source_ref' => self::MASTER." row $r",
                'legacy' => $this->legacy($row) ?: null,
            ]);
            $employee->categories()->sync($this->categoryIds($row['categories'], $categoryIds));
            $matcher->add($employee->id, $employee->full_name, $employee->employee_code);
            $byCode[$employee->employee_code] = $employee;
            $this->bump('employees_from_master');

            if (strcasecmp($row['probation_status'], 'On Probation') === 0) {
                Onboarding::updateOrCreate(['employee_id' => $employee->id], [
                    'probation_result' => 'Pending',
                    'probation_end_date' => $this->date($row['probation_end_date']) ?? HrRules::probationEnd($employee->start_date),
                ]);
                $this->bump('on_probation');
            }
        }

        // Pass 2: managers by code
        foreach ($this->master as $row) {
            $links = array_filter([
                'manager_id' => $byCode[$row['manager_1_code']]->id ?? null,
                'secondary_manager_id' => $byCode[$row['manager_2_code']]->id ?? null,
            ]);
            if ($links) {
                $byCode[$row['employee_code']]->update($links);
            }
        }

        // Pass 3: For_Review rows, REV-001... in row order
        $seq = 0;
        foreach ($this->review as $r => $row) {
            $company = $companies[self::BRANCHES[$row['branch']]];
            $note = [];
            foreach (self::REVIEW_NOTE_COLUMNS as $col) {
                if ($row[$col] !== '') {
                    $note[] = "$col: {$row[$col]}";
                }
            }
            $legacy = [];
            foreach (self::REVIEW_LEGACY_COLUMNS as $col) {
                if ($row[$col] !== '') {
                    $legacy[$col] = $row[$col];
                }
            }
            $employee = Employee::create([
                'employee_code' => 'REV-'.str_pad((string) ++$seq, 3, '0', STR_PAD_LEFT),
                'company_id' => $company->id,
                ...$this->names($row['name']),
                'status' => self::STATUSES[strtolower($row['status'])],
                'department_id' => $row['department'] !== '' ? $this->department($company, $row['department'])->id : null,
                'needs_review' => true,
                'review_note' => $note ? implode("\n", $note) : null,
                'source_ref' => self::REVIEW." row $r",
                'legacy' => $legacy ?: null,
            ]);
            $employee->categories()->sync($this->categoryIds($row['category'], $categoryIds));
            $matcher->add($employee->id, $employee->full_name, $employee->employee_code);
            $this->bump('employees_for_review');
        }

        return ['stats' => $this->stats, 'warnings' => $this->warnings];
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, list<string>> */
    private function readLookups(): array
    {
        $rows = $this->xlsx->rows(self::LOOKUPS);
        $first = array_key_first($rows);
        $header = $rows[$first] ?? [];
        $lists = [];
        foreach ($header as $col => $name) {
            $values = [];
            foreach ($rows as $r => $cells) {
                if ($r !== $first && ($v = $this->clean($cells[$col] ?? '')) !== '') {
                    $values[] = $v;
                }
            }
            $lists[$this->clean($name)] = $values;
        }

        return $lists;
    }

    /** Rows of a sheet keyed by header name; records V10 when a column is missing. */
    private function table(string $sheet, array $required): array
    {
        $rows = $this->xlsx->rows($sheet);
        $first = array_key_first($rows);
        $map = [];
        foreach ($rows[$first] ?? [] as $col => $text) {
            $key = strtolower($this->clean($text));
            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $col;
            }
        }
        $missing = array_values(array_diff($required, array_keys($map)));
        if ($missing) {
            $this->errors[] = "$sheet is missing these columns: ".implode(', ', $missing).'.';
        }

        $out = [];
        foreach ($rows as $r => $cells) {
            if ($r === $first) {
                continue;
            }
            $row = [];
            foreach ($map as $key => $col) {
                $row[$key] = $this->clean((string) ($cells[$col] ?? ''));
            }
            foreach ($required as $key) {
                $row[$key] ??= '';
            }
            if (implode('', $row) !== '') {
                $out[$r] = $row;
            }
        }

        return $out;
    }

    private function inLookup(string $at, string $column, string $value, string $list, bool $required = false): void
    {
        if ($value === '') {
            if ($required) {
                $this->errors[] = "$at: $column is empty.";
            }

            return;
        }
        if ($this->allowed($list, $value) === null) {
            $this->errors[] = "$at: $column \"$value\" is not in Lookups > $list.";
        }
    }

    /** The Lookups spelling of $value (case-insensitive), or null. */
    private function allowed(string $list, string $value): ?string
    {
        foreach ($this->lookups[$list] ?? [] as $allowed) {
            if (strcasecmp($allowed, $value) === 0) {
                return $allowed;
            }
        }

        return null;
    }

    /** "Full-Time" in the sheet is "Full Time" in the app. */
    private function employmentType(string $value): ?string
    {
        $wanted = strtolower(preg_replace('/[\s_-]+/', ' ', $value));
        foreach (config('hrm.employment_types') as $type) {
            if (strtolower($type) === $wanted) {
                return $type;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function split(string $list): array
    {
        return array_values(array_filter(array_map(fn ($v) => $this->clean($v), explode(',', $list))));
    }

    /** @param array<string, int> $ids */
    private function categoryIds(string $list, array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(function ($name) use ($ids) {
            $spelling = $this->allowed('Category', $name) ?? $name;

            return $ids[$spelling] ?? null;
        }, $this->split($list)))));
    }

    private function legacy(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if ((str_starts_with($key, 'legacy_') || $key === 'match_type') && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** First word is the first name, the rest the last name. */
    private function names(string $full): array
    {
        $full = $this->clean($full);
        $parts = explode(' ', $full, 2);

        return ['full_name' => $full, 'first_name' => $parts[0] ?: null, 'last_name' => $parts[1] ?? null];
    }

    private function department(Company $company, string $name): Department
    {
        return Department::firstOrCreate(['company_id' => $company->id, 'name' => $this->clean($name)]);
    }

    private function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $d = DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($d && $d->format($format) === $value) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    /** Same loop found from any of its members is reported once. */
    private function loopKey(array $codes): string
    {
        sort($codes);

        return implode(',', $codes);
    }

    private function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private function bump(string $key): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + 1;
    }
}
