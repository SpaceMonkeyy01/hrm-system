<?php

namespace App\Services\Import;

use App\Models\ActivityLog;
use App\Models\ChecklistEntry;
use App\Models\ChecklistItem;
use App\Models\Company;
use App\Models\ControlledDocument;
use App\Models\AttendancePunch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Offboarding;
use App\Models\Onboarding;
use App\Models\Team;
use App\Services\HrRules;
use App\Support\Xlsx\XlsxReader;
use Database\Seeders\ChecklistItemSeeder;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * One-time migration of HR_Onboarding.xlsx into the database.
 *
 *  1. Companies, departments, teams and the employee master   <- BCC / Emerge department sheets
 *  2. Onboarding rows + onboarding checklist                  <- Onboarding_Tracker
 *  3. Document checklist + Drive folder / portal IDs          <- Onboarding_Document_Checklist
 *  4. Facts typed in both sheets (NDA, NCA, Policy, CNIC, Appointment letter) are merged:
 *     the document checklist wins; the tracker only fills blanks; disagreements are reported.
 *  5. Controlled documents + Templates list, HR_Database (if filled), Offboarding_Tracker (if filled)
 *
 * Every decision (match type, new employee, conflict, invalid value) becomes a row in the report CSV.
 *
 * With a master file (Employee_Master.xlsx, see EmployeeMasterImporter) step 1 comes from the master instead of the
 * department sheets, attendance links are carried over from the records being replaced (LinkCarryOver), and a name
 * in the onboarding sheets that is not on the master is reported and skipped: the master says who works here.
 * The onboarding workbook is optional then.
 */
class HrWorkbookImporter
{
    public const TRACKER = 'Onboarding_Tracker';

    public const DOCS = 'Onboarding_Document_Checklist';

    public const COMPANY_SHEETS = [
        'BCC' => 'BCC Employee teams Departments',
        'Emerge' => 'Emerge Employee teams Departmen',
    ];

    /** Tracker header (normalized) => onboarding checklist key */
    private const TRACKER_ITEMS = [
        'emailcreated' => 'email_created',
        'deskallocation' => 'desk_allocated',
        'systemaccesssetup' => 'system_access',
        'contactinfo' => 'contact_info',
        'hrdatabase' => 'hr_database',
        'orientationsession1' => 'orientation_1',
        'orientationsession2' => 'orientation_2',
        'orientationsession3' => 'orientation_3',
        'day30checkincompleted' => 'day30_checkin',
        'day60checkincompleted' => 'day60_checkin',
        'day90evaluationcompleted' => 'day90_evaluation',
    ];

    /** Tracker header => document key (same fact also typed in the document checklist) */
    private const TRACKER_DOC_FACTS = [
        'cnic' => 'cnic_copy',
        'ndasigned' => 'nda_signed',
        'ncasigned' => 'nca_signed',
        'appointmentletterissuance' => 'appointment_letter',
        'policyacknowledgment' => 'policy_acknowledgment',
    ];

    /** Document checklist header => document key */
    private const DOC_ITEMS = [
        'cniccopy' => 'cnic_copy',
        'photo' => 'photo',
        'ndasigned' => 'nda_signed',
        'ncasigned' => 'nca_signed',
        'bankdetails' => 'bank_details',
        'educationaldocuments' => 'educational_documents',
        'emergencycontact' => 'emergency_contact',
        'appointmentletter' => 'appointment_letter',
        'signedcontract' => 'signed_contract',
        'experienceletters' => 'experience_letters',
        'policyacknowledgment' => 'policy_acknowledgment',
        'systemaccessform' => 'system_access_form',
        'laptopassetissued' => 'laptop_asset_issued',
    ];

    private XlsxReader $xlsx;

    private NameMatcher $matcher;

    /** @var array<string, array<string, ChecklistItem>> */
    private array $items = [];

    /** @var array<int, array<int, string>> employee id => item id => status */
    private array $entries = [];

    /** @var array<int, array<string, array{0: string, 1: int}>> employee id => doc key => [tracker value, row] */
    private array $trackerDocFacts = [];

    /** @var array<int, int> employee id => first tracker row */
    private array $seenTracker = [];

    /** @var array<int, int> employee id => first checklist row */
    private array $seenDocs = [];

    /** @var array<string, string> employee_code => who took it in this run (department lists) */
    private array $listedCodes = [];

    /** @var array<int, array<string, string>> */
    private array $report = [];

    /** @var array<string, int> */
    private array $stats = [];

    private ?EmployeeMasterImporter $master = null;

    /** Files of the records a fresh import deletes, removed from the disk once the import is saved. */
    private array $orphanFiles = [];

    /**
     * @param  list<array{employee_code: string, source: string, device_user_id: string, line: int}>  $links  links.csv: fixed
     *                                                                                                      attendance links, applied last
     */
    public function __construct(private ?string $path, private array $aliases = [], private ?string $masterPath = null, private array $links = []) {}

    /** @return array{stats: array<string, int>, report: array<int, array<string, string>>} */
    public function run(bool $fresh): array
    {
        if ($this->path === null && $this->masterPath === null) {
            throw new RuntimeException('Give the onboarding workbook, the master file, or both.');
        }
        if ($this->masterPath !== null) {
            $this->master = new EmployeeMasterImporter($this->masterPath);
            if ($errors = $this->master->validate()) {
                throw new MasterValidationException($errors);
            }
        }
        if ($this->path !== null) {
            $this->xlsx = new XlsxReader($this->path);
            $needed = $this->master ? [self::TRACKER, self::DOCS] : [self::TRACKER, self::DOCS, ...array_values(self::COMPANY_SHEETS)];
            foreach ($needed as $sheet) {
                if (! $this->xlsx->hasSheet($sheet)) {
                    throw new RuntimeException("The workbook has no sheet named \"$sheet\". Found: ".implode(', ', $this->xlsx->sheetNames()));
                }
            }
        }

        $carry = null;
        if ($fresh && $this->master && Employee::query()->exists()) {
            $carry = new LinkCarryOver;
            $carry->capture();
        }

        ActivityLog::$muted = true;
        try {
            DB::transaction(function () use ($fresh, $carry) {
                if ($fresh) {
                    $this->wipe();
                }
                (new ChecklistItemSeeder)->run();
                foreach (ChecklistItem::all() as $item) {
                    $this->items[$item->list][$item->key] = $item;
                }
                $this->matcher = new NameMatcher;
                $this->matcher->setAliases($this->aliases);

                if ($this->master) {
                    $seeded = $this->master->seed($this->matcher);
                    $this->stats += $seeded['stats'];
                    foreach ($seeded['warnings'] as $w) {
                        $this->log($w['sheet'], $w['row'], $w['name'], 'warning', '', null, $w['detail']);
                    }
                    if ($carry) {
                        $this->stats += $carry->apply($this->matcher, fn (...$a) => $this->log(...$a));
                    }
                } else {
                    $this->importCompanies();
                }
                if ($this->path !== null) {
                    $this->importTracker();
                    $this->importDocumentChecklist();
                    $this->mergeTrackerDocFacts();
                    $this->importHrDatabase();
                    $this->importControlledDocuments();
                    $this->importOffboarding();
                }
                $this->writeEntries();
                $this->applyLinks();
            });
        } finally {
            ActivityLog::$muted = false;
        }

        $disk = Storage::disk(config('hrm.uploads.disk'));
        foreach ($this->orphanFiles as $file) {
            $disk->delete($file);
        }

        $files = implode(' + ', array_map('basename', array_filter([$this->masterPath, $this->path])));
        ActivityLog::create([
            'event' => 'imported',
            'subject_type' => 'Import',
            'description' => sprintf('Imported %s: %d employees, %d onboarding rows, %d checklist values',
                $files, Employee::count(), Onboarding::count(), ChecklistEntry::count()),
            'changes' => $this->stats,
        ]);

        return ['stats' => $this->stats, 'report' => $this->report];
    }

    // ------------------------------------------------------------------ 1. org structure + employee master

    private function importCompanies(): void
    {
        $defs = [
            'BCC' => ['code' => 'BCC', 'employee_code_prefix' => 'BCC-', 'employee_code_digits' => 3],
            'Emerge' => ['code' => 'EMG', 'employee_code_prefix' => 'Emerge-', 'employee_code_digits' => 3],
        ];
        foreach (self::COMPANY_SHEETS as $companyName => $sheet) {
            $company = Company::firstOrCreate(['name' => $companyName], $defs[$companyName]);
            $rows = $this->xlsx->rows($sheet);

            // Find the header row: A = "ID", B = "Name"
            $headerRow = null;
            foreach ($rows as $r => $cells) {
                if (strcasecmp(trim($cells['A'] ?? ''), 'ID') === 0 && strcasecmp(trim($cells['B'] ?? ''), 'Name') === 0) {
                    $headerRow = $r;
                    break;
                }
            }
            if ($headerRow === null) {
                throw new RuntimeException("Could not find the ID / Name header row in \"$sheet\".");
            }

            // Department -> team table in columns I / J
            $dept = null;
            foreach ($rows as $r => $cells) {
                if ($r <= $headerRow) {
                    continue;
                }
                if (trim($cells['I'] ?? '') !== '') {
                    $dept = $this->department($company, $cells['I']);
                }
                if ($dept && trim($cells['J'] ?? '') !== '') {
                    $this->team($dept, $cells['J']);
                }
            }

            // Employee rows in columns A..D. Explicit IDs first, so a blank-ID row can never be given
            // a code that a later row claims; then blank IDs and repeated IDs get the next free code.
            $expected = [];
            $later = [];
            foreach ($rows as $r => $cells) {
                if ($r <= $headerRow) {
                    continue;
                }
                $deptName = trim($cells['F'] ?? '');
                if ($deptName !== '' && is_numeric($cells['G'] ?? null) && strcasecmp($deptName, 'Grand Total') !== 0) {
                    $expected[$deptName] = (int) $cells['G'];
                }
                $name = $this->clean($cells['B'] ?? '');
                if ($name === '') {
                    continue;
                }
                $rawId = trim($cells['A'] ?? '');
                if ($rawId === '') {
                    $later[] = [$r, $cells, $name, null];

                    continue;
                }
                $code = is_numeric($rawId)
                    ? $company->employee_code_prefix.str_pad((string) (int) $rawId, $company->employee_code_digits, '0', STR_PAD_LEFT)
                    : $rawId;
                if (isset($this->listedCodes[$code])) {
                    $later[] = [$r, $cells, $name, "ID $rawId is also used by {$this->listedCodes[$code]}"];

                    continue;
                }
                $this->listEmployee($company, $sheet, $r, $cells, $name, $code);
            }
            foreach ($later as [$r, $cells, $name, $duplicate]) {
                $employee = $this->listEmployee($company, $sheet, $r, $cells, $name, $company->nextEmployeeCode());
                if ($duplicate) {
                    $note = "$duplicate on the department list ($sheet row $r). Given a new code; check which ID is right.";
                    $employee->update(['needs_review' => true, 'review_note' => $note]);
                    $this->log($sheet, $r, $name, 'duplicate_id', '', $employee, "$duplicate. Not overwritten: given new code {$employee->employee_code}");
                    $this->bump('duplicate_ids');
                }
            }

            // Headcount check against the sheet's own "No. of Employees" table (informational)
            foreach ($expected as $deptName => $count) {
                $actual = Employee::where('company_id', $company->id)
                    ->whereHas('department', fn ($q) => $q->where('name', $this->clean($deptName)))->count();
                if ($actual !== $count) {
                    $this->log($sheet, 0, $deptName, 'headcount_differs', '', null, "Sheet says $count, list has $actual");
                }
            }
        }
    }

    private function listEmployee(Company $company, string $sheet, int $r, array $cells, string $name, string $code): Employee
    {
        $department = trim($cells['D'] ?? '') !== '' ? $this->department($company, $cells['D']) : null;
        $team = ($department && trim($cells['C'] ?? '') !== '') ? $this->team($department, $cells['C']) : null;

        $employee = Employee::withTrashed()->updateOrCreate(['employee_code' => $code], [
            'company_id' => $company->id,
            'full_name' => $name,
            'department_id' => $department?->id,
            'team_id' => $team?->id,
            'status' => 'active',
            'source_ref' => "$sheet row $r",
        ]);
        $this->listedCodes[$code] = "$name ($sheet row $r)";
        $this->matcher->add($employee->id, $name, $code);
        $this->bump('employees_from_department_lists');

        return $employee;
    }

    // ------------------------------------------------------------------ 2. Onboarding_Tracker

    private function importTracker(): void
    {
        $rows = $this->xlsx->rows(self::TRACKER);
        $map = $this->headerMap($rows[1] ?? []);
        $col = fn (array $cells, string $h) => isset($map[$h]) ? trim($cells[$map[$h]] ?? '') : '';

        foreach ($rows as $r => $cells) {
            if ($r === 1) {
                continue;
            }
            $name = $this->clean($col($cells, 'employeename'));
            if ($name === '') {
                continue;                                     // "Group A" label rows and blank rows
            }
            $employee = $this->resolve(self::TRACKER, $r, $name);
            if (! $employee) {
                continue;
            }
            $dup = isset($this->seenTracker[$employee->id]);
            if ($dup) {
                $this->log(self::TRACKER, $r, $name, 'duplicate_row', 'merged', $employee,
                    "Same person as row {$this->seenTracker[$employee->id]}; only blanks were filled");
            } else {
                $this->seenTracker[$employee->id] = $r;
            }

            // Employee master fields: only fill what is still empty
            $start = $this->date($col($cells, 'startdate'), self::TRACKER, $r, $name, 'Start_Date');
            $fill = array_filter([
                'start_date' => $start,
                'employment_type' => $this->pick($col($cells, 'employmenttype'), config('hrm.employment_types'), self::TRACKER, $r, $name, 'Employment_Type'),
                'role_title' => $col($cells, 'roletitle') ?: null,
            ]);
            $this->fillEmpty($employee, $fill);
            if ($manager = $this->clean($col($cells, 'managername'))) {
                $this->linkManager($employee, $manager, $r);
            }

            $onboarding = Onboarding::firstOrNew(['employee_id' => $employee->id]);
            $result = $this->pick($col($cells, 'probationresult'), config('hrm.probation_results'), self::TRACKER, $r, $name, 'Probation_Result');
            $values = [
                'cohort' => $col($cells, 'group') ?: null,
                'probation_end_date' => $this->date($col($cells, 'probationenddate'), self::TRACKER, $r, $name, 'Probation_End_Date')
                    ?? HrRules::probationEnd($employee->start_date),
                'probation_result' => $result,
                'notes' => $col($cells, 'notes') ?: null,
                'plan_url' => $col($cells, '90dayplanlink') ?: null,
            ];
            foreach ($values as $field => $value) {
                if ($value !== null && ($onboarding->$field === null || ! $dup)) {
                    $onboarding->$field = $value;
                }
            }
            $onboarding->save();
            $this->bump('onboarding_rows');

            foreach (self::TRACKER_ITEMS as $header => $key) {
                $this->setEntry($employee, 'onboarding', $key, $col($cells, $header), self::TRACKER, $r, $name);
            }
            foreach (self::TRACKER_DOC_FACTS as $header => $key) {
                $v = $col($cells, $header);
                if ($v !== '' && ! isset($this->trackerDocFacts[$employee->id][$key])) {
                    $this->trackerDocFacts[$employee->id][$key] = [$v, $r];
                }
            }
        }
    }

    // ------------------------------------------------------------------ 3. Onboarding_Document_Checklist

    private function importDocumentChecklist(): void
    {
        $rows = $this->xlsx->rows(self::DOCS);
        $map = $this->headerMap($rows[1] ?? []);
        $col = fn (array $cells, string $h) => isset($map[$h]) ? trim($cells[$map[$h]] ?? '') : '';

        foreach ($rows as $r => $cells) {
            if ($r === 1) {
                continue;
            }
            $name = $this->clean($col($cells, 'employeename'));
            if ($name === '') {
                continue;
            }
            $employee = $this->resolve(self::DOCS, $r, $name);
            if (! $employee) {
                continue;
            }
            if (isset($this->seenDocs[$employee->id])) {
                $this->log(self::DOCS, $r, $name, 'duplicate_row', 'merged', $employee,
                    "Same person as row {$this->seenDocs[$employee->id]}; only blanks were filled");
            } else {
                $this->seenDocs[$employee->id] = $r;
            }

            $this->fillEmpty($employee, array_filter([
                'drive_folder_url' => $col($cells, 'drivefolderlink') ?: null,
                'hr_portal_id' => $col($cells, 'hrportalid') ?: null,
                'attendance_machine' => $col($cells, 'attendancemachine') ?: null,
                'attendance_machine_id' => $col($cells, 'attendancemachineid') ?: null,
                'start_date' => $this->date($col($cells, 'startdate'), self::DOCS, $r, $name, 'Start_Date'),
            ]));

            // Everyone on the document checklist is in the onboarding process
            $onboarding = Onboarding::firstOrCreate(['employee_id' => $employee->id]);
            if (! $onboarding->probation_end_date && $employee->start_date) {
                $onboarding->update(['probation_end_date' => HrRules::probationEnd($employee->start_date)]);
            }

            foreach (self::DOC_ITEMS as $header => $key) {
                $this->setEntry($employee, 'document', $key, $col($cells, $header), self::DOCS, $r, $name);
            }
            $this->bump('document_checklist_rows');
        }
    }

    // ------------------------------------------------------------------ 4. merge facts typed twice

    private function mergeTrackerDocFacts(): void
    {
        foreach ($this->trackerDocFacts as $employeeId => $facts) {
            $employee = Employee::find($employeeId);
            foreach ($facts as $key => [$raw, $row]) {
                $item = $this->items['document'][$key];
                [$value] = $this->canon($raw, 'document');
                if ($value === null) {
                    $this->log(self::TRACKER, $row, $employee->full_name, 'invalid_value', '', $employee, "\"$raw\" is not a valid value for {$item->label}");

                    continue;
                }
                $current = $this->entries[$employeeId][$item->id] ?? null;
                if ($current === null) {
                    $this->entries[$employeeId][$item->id] = $value;
                    $this->bump('document_values_filled_from_tracker');
                } elseif (HrRules::isDone($current, 'document') !== HrRules::isDone($value, 'document')) {
                    $this->log(self::TRACKER, $row, $employee->full_name, 'conflict', 'kept checklist', $employee,
                        "{$item->label}: tracker says \"$value\", document checklist says \"$current\" (kept)");
                    $this->bump('conflicts_tracker_vs_checklist');
                }
            }
        }
    }

    private function writeEntries(): void
    {
        $now = now();
        $rows = [];
        foreach ($this->entries as $employeeId => $byItem) {
            foreach ($byItem as $itemId => $status) {
                $rows[] = ['employee_id' => $employeeId, 'checklist_item_id' => $itemId, 'status' => $status,
                    'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            ChecklistEntry::upsert($chunk, ['employee_id', 'checklist_item_id'], ['status', 'updated_at']);
        }
        $this->stats['checklist_values'] = count($rows);
    }

    // ------------------------------------------------------------------ 5. smaller sheets

    private function importHrDatabase(): void
    {
        if (! $this->xlsx->hasSheet('HR_Database')) {
            return;
        }
        $rows = $this->xlsx->rows('HR_Database');
        $map = $this->headerMap($rows[1] ?? []);
        foreach ($rows as $r => $cells) {
            $full = $this->clean($cells[$map['employeefullname'] ?? 'C'] ?? '');
            if ($r === 1 || $full === '') {
                continue;
            }
            if ($employee = $this->resolve('HR_Database', $r, $full)) {
                $this->fillEmpty($employee, array_filter([
                    'first_name' => trim($cells[$map['employeefirstname'] ?? 'A'] ?? '') ?: null,
                    'last_name' => trim($cells[$map['employeelastname'] ?? 'B'] ?? '') ?: null,
                    'cnic' => trim($cells[$map['cnic'] ?? 'D'] ?? '') ?: null,
                ]));
            }
        }
    }

    private function importControlledDocuments(): void
    {
        if ($this->xlsx->hasSheet('Controlled_Documents')) {
            $rows = $this->xlsx->rows('Controlled_Documents');
            $map = $this->headerMap($rows[1] ?? []);
            foreach ($rows as $r => $cells) {
                $number = trim($cells[$map['document'] ?? 'A'] ?? '');
                if ($r === 1 || $number === '') {
                    continue;
                }
                ControlledDocument::updateOrCreate(['doc_number' => $number], [
                    'doc_type' => trim($cells[$map['documenttype'] ?? 'B'] ?? '') ?: 'Policy',
                    'name' => $this->humanize($cells[$map['documentname'] ?? 'C'] ?? $number),
                    'description' => trim($cells[$map['documentdescription'] ?? 'D'] ?? '') ?: null,
                    'status' => trim($cells[$map['status'] ?? 'E'] ?? '') ?: 'Active',
                    'issued_on' => $this->date($cells[$map['dateissued'] ?? 'F'] ?? '', 'Controlled_Documents', $r, $number, 'Date_Issued'),
                    'obsolete_on' => $this->date($cells[$map['dateobsolete'] ?? 'G'] ?? '', 'Controlled_Documents', $r, $number, 'Date_Obsolete'),
                ]);
                $this->bump('controlled_documents');
            }
        }
        if ($this->xlsx->hasSheet('Templates')) {
            foreach ($this->xlsx->rows('Templates') as $r => $cells) {
                $name = $this->humanize($cells['B'] ?? '');
                if ($name === '' || ControlledDocument::where('name', $name)->exists()) {
                    continue;
                }
                ControlledDocument::create([
                    'doc_number' => ControlledDocument::nextNumber(),
                    'doc_type' => 'Template',
                    'name' => $name,
                    'description' => 'Listed on the Templates sheet. Add a link to the file.',
                    'status' => 'Draft',
                ]);
                $this->bump('templates');
            }
        }
    }

    private function importOffboarding(): void
    {
        if (! $this->xlsx->hasSheet('Offboarding_Tracker')) {
            return;
        }
        $rows = $this->xlsx->rows('Offboarding_Tracker');
        $map = $this->headerMap($rows[1] ?? []);
        foreach ($rows as $r => $cells) {
            if ($r === 1) {
                continue;
            }
            $name = $this->clean($cells[$map['employeefullname'] ?? 'A'] ?? '');
            $type = trim($cells[$map['offboardingtype'] ?? 'B'] ?? '');
            if ($name === '') {
                if ($type !== '') {
                    $this->log('Offboarding_Tracker', $r, '', 'skipped', '', null, "Row has type \"$type\" but no employee name");
                }

                continue;
            }
            if (! $employee = $this->resolve('Offboarding_Tracker', $r, $name)) {
                continue;
            }
            Offboarding::create([
                'employee_id' => $employee->id,
                'type' => $type ?: 'Other',
                'reason' => trim($cells[$map['reason'] ?? 'C'] ?? '') ?: null,
                'status' => 'in_progress',
            ]);
            $employee->update(['status' => 'offboarding']);
            $this->setEntry($employee, 'offboarding', 'assets_returned', $cells[$map['assetreturn'] ?? 'D'] ?? '', 'Offboarding_Tracker', $r, $name);
            $this->setEntry($employee, 'offboarding', 'exit_interview', $cells[$map['exitinterview'] ?? 'E'] ?? '', 'Offboarding_Tracker', $r, $name);
            $this->bump('offboardings');
        }
    }

    /**
     * links.csv: "this machine number / HR.my ID belongs to this employee code". Applied after everything else, so it wins;
     * the same number on anyone else is taken off them (reported). A machine number gets the device of its latest punch.
     */
    private function applyLinks(): void
    {
        $fields = ['machine' => 'attendance_machine_id', 'hrmy' => 'hr_portal_id'];
        foreach ($this->links as $l) {
            $field = $fields[$l['source']] ?? null;
            $employee = Employee::where('employee_code', $l['employee_code'])->first();
            if (! $field || ! $employee || $l['device_user_id'] === '') {
                $why = ! $field ? "source \"{$l['source']}\" must be machine or hrmy" : (! $employee ? "no employee has code {$l['employee_code']}" : 'the number is empty');
                $this->log('links.csv', $l['line'], $l['employee_code'], 'bad_link', '', null, "Not applied: $why.");
                $this->bump('bad_links');

                continue;
            }
            foreach (Employee::where($field, $l['device_user_id'])->where('id', '!=', $employee->id)->get() as $other) {
                $other->update([$field => null] + ($field === 'attendance_machine_id' ? ['attendance_device_id' => null] : []));
                $this->log('links.csv', $l['line'], $other->full_name, 'link_moved', '', $other, "{$l['source']} {$l['device_user_id']} taken off {$other->employee_code} and given to {$employee->employee_code}");
            }
            $update = [$field => $l['device_user_id']];
            if ($field === 'attendance_machine_id') {
                $update['attendance_device_id'] = AttendancePunch::deviceFor($l['device_user_id']) ?? $employee->attendance_device_id;
            }
            $employee->update($update);
            $this->log('links.csv', $l['line'], $employee->full_name, 'link_set', '', $employee, "{$l['source']} {$l['device_user_id']}");
            $this->bump('links_from_links_csv');
        }
    }

    // ------------------------------------------------------------------ helpers

    /** Find the employee for a name typed in a sheet; create one if the name is unknown. */
    private function resolve(string $sheet, int $row, string $name): ?Employee
    {
        [$bare, $hint] = NameMatcher::splitHint($name);
        $m = $this->matcher->match($name);              // alias on "Name (hint)" first, then on "Name"
        if ($m['type'] === 'bad_alias') {
            $this->log($sheet, $row, $name, 'bad_alias', 'bad_alias', null,
                "aliases.csv links this name to {$m['code']}, but no employee has that code. Row NOT imported. Fix the code in aliases.csv and import again.");
            $this->bump('bad_alias_rows_skipped');

            return null;
        }
        if ($m['type'] === 'ambiguous' && $hint) {
            // "Muhmmad Faisal ( developer )": keep the candidate whose team or department mentions the hint
            $stem = rtrim(strtolower($hint), 's');
            $hits = Employee::with('team', 'department')->whereIn('id', $m['ids'])->get()->filter(fn ($e) =>
                str_contains(strtolower(($e->team->name ?? '').' '.($e->department->name ?? '')), $stem))->pluck('id')->all();
            if (count($hits) === 1) {
                $m = ['type' => 'hint', 'ids' => $hits, 'score' => 0.9];
            }
        }
        if ($m['type'] === 'ambiguous') {
            $codes = Employee::whereIn('id', $m['ids'])->pluck('employee_code')->implode(', ');
            if ($this->master) {
                LinkCarryOver::flag($m['ids'], "\"$name\" in $sheet could be this person or another with the same name ($codes); that row was not imported. Add the right code to aliases.csv.");
            }
            $this->log($sheet, $row, $name, 'ambiguous', 'ambiguous', null,
                "Could be: $codes. Row NOT imported. Add this source_name with the right code to aliases.csv and import again.");
            $this->bump('ambiguous_rows_skipped');

            return null;
        }
        if ($m['ids']) {
            $employee = Employee::find($m['ids'][0]);
            if (in_array($m['type'], ['fuzzy', 'subset', 'hint'], true)) {
                $note = "\"$name\" in $sheet row $row was matched to \"{$employee->full_name}\" by similar spelling. Check it is the same person.";
                $employee->update(['needs_review' => true, 'review_note' => $employee->review_note ? $employee->review_note.' | '.$note : $note]);
            }
            $this->log($sheet, $row, $name, 'matched', $m['type'], $employee, $m['type'] === 'exact' ? '' : 'score '.$m['score']);
            $this->bump("match_{$m['type']}");

            return $employee;
        }

        // The master lists everyone who works here: a name it does not have is someone who left, or a typo.
        if ($this->master) {
            $this->log($sheet, $row, $name, 'not_in_master', 'none', null,
                'No one on the master has this name. Row NOT imported. If it is a spelling of someone on the master, add it to aliases.csv and import again.');
            $this->bump('rows_not_in_master_skipped');

            return null;
        }

        // Unknown name: create an employee so no data is lost, flagged for HR review.
        $name = $bare;
        $seq = Employee::withTrashed()->where('employee_code', 'like', 'UNASSIGNED-%')->count() + 1;
        $employee = Employee::create([
            'employee_code' => 'UNASSIGNED-'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'full_name' => $name,
            'status' => 'active',
            'needs_review' => true,
            'review_note' => "Not on the BCC or Emerge department lists (first seen in $sheet row $row). Set company and department, or merge with the right person.",
            'source_ref' => "$sheet row $row",
        ]);
        $this->matcher->add($employee->id, $name, $employee->employee_code);
        $this->log($sheet, $row, $name, 'created', 'none', $employee, 'New employee record, needs review');
        $this->bump('employees_created_unmatched');

        return $employee;
    }

    /** Link the manager only on a sure match (alias, exact, normalized, spelling); report anything else. */
    private function linkManager(Employee $employee, string $manager, int $row): void
    {
        $m = $this->matcher->match($manager);
        if (in_array($m['type'], ['alias', 'exact', 'normalized', 'spelling'], true)) {
            if (! $employee->manager_id) {
                $employee->update(['manager_id' => $m['ids'][0]]);
            }

            return;
        }
        $why = match ($m['type']) {
            'bad_alias' => "aliases.csv links this name to {$m['code']}, but no employee has that code",
            'ambiguous' => 'more than one employee has this name ('.Employee::whereIn('id', $m['ids'])->pluck('employee_code')->implode(', ').')',
            'none' => 'no employee has this name',
            default => "only a {$m['type']} match to \"{$this->matcher->nameOf($m['ids'][0])}\"",
        };
        $this->log(self::TRACKER, $row, $manager, 'manager_not_linked', $m['type'], $employee,
            "Manager of {$employee->full_name}: $why. Manager left blank; add an alias or set it in the app.");
        $this->bump('managers_not_linked');
    }

    private function setEntry(Employee $employee, string $list, string $key, ?string $raw, string $sheet, int $row, string $name): void
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return;
        }
        $item = $this->items[$list][$key] ?? null;
        if (! $item) {
            return;
        }
        [$value] = $this->canon($raw, $item->option_set);
        if ($value === null) {
            $this->log($sheet, $row, $name, 'invalid_value', '', $employee, "\"$raw\" is not a valid value for {$item->label}; left blank");
            $this->bump('invalid_values');

            return;
        }
        $current = $this->entries[$employee->id][$item->id] ?? null;
        if ($current === null) {
            $this->entries[$employee->id][$item->id] = $value;
        } elseif ($current !== $value) {
            $this->log($sheet, $row, $name, 'duplicate_conflict', '', $employee, "{$item->label}: kept \"$current\", ignored \"$value\" from the duplicate row");
        }
    }

    /** Case-insensitive match of a cell value to the allowed values of an option set. */
    private function canon(string $raw, string $optionSet): array
    {
        foreach (HrRules::options($optionSet) as $opt) {
            if (strcasecmp($opt['value'], trim($raw)) === 0) {
                return [$opt['value']];
            }
        }

        return [null];
    }

    private function pick(string $raw, array $allowed, string $sheet, int $row, string $name, string $column): ?string
    {
        if ($raw === '') {
            return null;
        }
        foreach ($allowed as $a) {
            if (strcasecmp($a, $raw) === 0) {
                return $a;
            }
        }
        $this->log($sheet, $row, $name, 'invalid_value', '', null, "$column \"$raw\" is not one of: ".implode(', ', $allowed));

        return null;
    }

    private function fillEmpty(Employee $employee, array $values): void
    {
        $changes = [];
        foreach ($values as $field => $value) {
            if ($value !== null && ($employee->$field === null || $employee->$field === '')) {
                $changes[$field] = $value;
            }
        }
        if ($changes) {
            $employee->update($changes);
        }
    }

    private function department(Company $company, string $name): Department
    {
        return Department::firstOrCreate(['company_id' => $company->id, 'name' => $this->clean($name)]);
    }

    private function team(Department $department, string $name): Team
    {
        return Team::firstOrCreate(['department_id' => $department->id, 'name' => $this->clean($name)]);
    }

    /** @return array<string, string> normalized header => column letter */
    private function headerMap(array $headerCells): array
    {
        $map = [];
        foreach ($headerCells as $colLetter => $text) {
            $key = preg_replace('/[^a-z0-9]/', '', strtolower($text));
            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $colLetter;
            }
        }

        return $map;
    }

    /** Dates come as Y-m-d from date cells; text dates may be d/m/Y, d-m-Y, d.m.Y or d-M-Y. Anything else is reported. */
    private function date(?string $value, string $sheet, int $row, string $name, string $column): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd-M-Y'] as $format) {
            $d = DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($d && DateTimeImmutable::getLastErrors() === false && preg_match('/\d{4}$|^\d{4}/', $value)) {
                return $d->format('Y-m-d');
            }
        }
        $this->log($sheet, $row, $name, 'invalid_value', '', null, "$column \"$value\" is not a date (use dd/mm/yyyy); left blank");
        $this->bump('invalid_values');

        return null;
    }

    private function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** "Employee_Setup _Template" -> "Employee Setup Template" */
    private function humanize(string $s): string
    {
        return $this->clean(str_replace('_', ' ', $s));
    }

    private function log(string $sheet, int $row, string $name, string $result, string $match, ?Employee $e, string $detail): void
    {
        $this->report[] = [
            'sheet' => $sheet, 'row' => (string) $row, 'source_name' => $name, 'result' => $result,
            'match_type' => $match, 'employee_code' => $e?->employee_code ?? '', 'employee_name' => $e?->full_name ?? '',
            'detail' => $detail,
        ];
    }

    private function bump(string $key): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + 1;
    }

    /**
     * Every record that belongs to an employee goes with them. Logins are kept (unlinked from their employee),
     * and so are punches: they are stored against machine numbers and HR.my IDs, not employees.
     * Controlled documents come from the onboarding workbook, so they are only replaced when it is imported.
     */
    private function wipe(): void
    {
        $this->orphanFiles = [
            ...DB::table('employee_files')->pluck('path')->all(),
            ...DB::table('employees')->whereNotNull('photo_path')->pluck('photo_path')->all(),
            ...DB::table('leave_requests')->whereNotNull('attachment_path')->pluck('attachment_path')->all(),
        ];
        $tables = ['activity_log', 'checklist_entries', 'probation_checkins', 'probation_plan_items', 'probation_plans',
            'onboardings', 'offboardings', 'employment_history', 'employee_files', 'employee_work_schedules',
            'attendance_adjustments', 'attendance_requests', 'leave_requests', 'leave_records', 'asset_assignments', 'reviews',
            'issued_letters', 'employee_category'];
        if ($this->path !== null) {
            $tables[] = 'controlled_documents';
        }
        foreach ($tables as $table) {
            DB::table($table)->delete();
        }
        DB::table('users')->whereNotNull('employee_id')->update(['employee_id' => null]);
        DB::table('employees')->update(['manager_id' => null, 'secondary_manager_id' => null]);
        foreach (['employees', 'teams', 'departments', 'companies', 'categories'] as $table) {
            DB::table($table)->delete();
        }
    }
}
