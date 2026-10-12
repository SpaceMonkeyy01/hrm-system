<?php

namespace Tests\Feature;

use App\Models\ChecklistEntry;
use App\Models\ChecklistItem;
use App\Models\ControlledDocument;
use App\Models\Employee;
use App\Services\Import\HrWorkbookImporter;
use App\Services\StatusBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\XlsxBuilder;
use Tests\TestCase;

/**
 * Runs the importer on a small workbook with the same sheets and columns as HR_Onboarding.xlsx
 * (fake people, real layout).
 */
class ImportTest extends TestCase
{
    use RefreshDatabase;

    private function workbook(): string
    {
        $docHeader = ['B' => 'Employee_Name', 'C' => 'Drive Folder Link', 'I' => 'Start_Date', 'J' => 'CNIC Copy', 'K' => 'Photo', 'L' => 'NDA Signed',
            'M' => 'NCA Signed', 'N' => 'Bank Details', 'O' => 'Educational Documents', 'P' => 'Emergency Contact', 'Q' => 'Appointment Letter',
            'R' => 'Signed Contract', 'S' => 'Experience Letter(s)', 'T' => 'Policy Acknowledgment', 'U' => 'System Access Form', 'V' => 'Laptop/Asset Issued', 'W' => 'Status'];
        $allDone = array_fill_keys(range('J', 'V'), 'Yes');

        return (new XlsxBuilder)
            ->sheet('BCC Employee teams Departments', [
                2 => ['A' => 'BCC Department list'],
                3 => ['A' => 'ID', 'B' => 'Name', 'C' => 'Team', 'D' => 'Department', 'F' => 'Department', 'G' => 'No. of Employees', 'I' => 'Department', 'J' => 'Team'],
                4 => ['A' => 1, 'B' => 'Nida Qaddir', 'C' => 'HR', 'D' => 'HR', 'F' => 'HR', 'G' => 1, 'I' => 'HR', 'J' => 'HR'],
                5 => ['A' => 2, 'B' => 'Bilal Ahmed', 'C' => 'Signage.com', 'D' => 'B2C Signage', 'F' => 'B2C Signage', 'G' => 3, 'I' => 'B2C Signage', 'J' => 'Signage.com'],
                6 => ['A' => 3, 'B' => 'Bilal Ahmed', 'C' => 'Google Ads', 'D' => 'B2C Signage', 'F' => 'B2C Neon', 'G' => 1, 'J' => 'Google Ads'],
                7 => ['A' => 4, 'B' => 'Saima Javed', 'C' => 'Google Ads', 'D' => 'B2C Signage', 'F' => 'Grand Total', 'G' => 5],
                8 => ['A' => 5, 'B' => 'M. Tariq Shah', 'C' => 'Core Team', 'D' => 'B2C Neon', 'I' => 'B2C Neon', 'J' => 'Core Team'],
            ])
            ->sheet('Emerge Employee teams Departmen', [
                1 => ['A' => 'Emerge Department list'],
                2 => ['A' => 'ID', 'B' => 'Name', 'C' => 'Team', 'D' => 'Department'],
                3 => ['A' => 'Emerge-001', 'B' => 'Zubair Hassan Malik', 'C' => 'QA Team', 'D' => 'QA Team'],
                4 => ['A' => 'Emerge-002', 'B' => 'M. Faisal', 'C' => 'Code Blue', 'D' => 'Developers'],
                5 => ['A' => 'Emerge-003', 'B' => 'M.Faisal', 'C' => 'Supporting staff', 'D' => 'Administration Department'],
            ])
            ->sheet('Onboarding_Tracker', [
                1 => ['B' => 'Employee_Name', 'G' => 'Group', 'H' => 'Start_Date', 'I' => 'Probation_End_Date', 'J' => 'Employment_Type',
                    'L' => 'Appointment_Letter_Issuance', 'M' => 'CNIC', 'O' => 'Email_Created', 'R' => 'Orientation_Session_1', 'S' => 'Orientation_Session_2',
                    'T' => 'Orientation_Session_3', 'V' => 'Policy_Acknowledgment', 'W' => 'NDA_Signed', 'X' => 'NCA_Signed', 'Z' => 'Day30_Checkin_Completed',
                    'AA' => 'Day60_Checkin_Completed', 'AB' => 'Day90_Evaluation_Completed', 'AC' => 'Probation_Result', 'AD' => 'Notes'],
                2 => ['A' => 'Group A'],
                3 => ['B' => 'Nida Qadir', 'G' => 'A', 'H' => ['date' => '2017-01-01'], 'I' => ['f' => '=H3+90', 'date' => '2017-04-01'], 'J' => 'Full Time',
                    'O' => 'Yes', 'R' => 'Done', 'S' => 'Done', 'T' => 'Done', 'V' => 'Yes', 'W' => 'Yes', 'X' => 'Yes', 'Z' => 'Yes', 'AA' => 'Yes', 'AB' => 'Yes', 'AC' => 'Pass'],
                4 => ['B' => 'Bilal Ahmed', 'G' => 'C'],
                5 => ['B' => 'Siama Javed', 'G' => 'C', 'H' => ['date' => '2026-01-14'], 'J' => 'Intern', 'R' => 'Done', 'W' => 'No', 'X' => 'No'],
                6 => ['B' => 'Muhammad Talha Shah', 'G' => 'C'],
                7 => ['B' => 'Muhmmad Faisal ( developer )', 'G' => 'C'],
                8 => ['B' => 'Zubair Hassan', 'G' => 'B', 'O' => 'Maybe'],
                9 => ['B' => 'Nida Qadir', 'G' => 'A', 'AD' => 'second row'],
            ])
            ->sheet('Onboarding_Document_Checklist', [
                1 => $docHeader,
                2 => ['A' => 'Group A'],
                3 => ['B' => 'Nida Qaddir', 'C' => 'https://drive.google.com/drive/folders/abc'] + $allDone + ['W' => ['f' => '=IF(...)', 'v' => 'Complete']],
                4 => ['B' => 'Saima Javed', 'J' => 'Yes', 'L' => 'Yes', 'N' => 'not available', 'O' => 'pending'],
                5 => ['B' => 'New Person', 'I' => ['date' => '2026-02-02'], 'J' => 'Yes'],
            ])
            ->sheet('Controlled_Documents', [
                1 => ['A' => 'Document#', 'B' => 'Document_Type', 'C' => 'Document_Name'],
                2 => ['A' => 'BCC-0001', 'B' => 'Policy', 'C' => 'Joining_Letter'],
            ])
            ->sheet('Templates', [1 => ['A' => 1, 'B' => 'Exit_Interview_Template']])
            ->sheet('Offboarding_Tracker', [1 => ['A' => 'Employee_Full_Name', 'B' => 'Offboarding_Type'], 2 => ['B' => 'Resignation']])
            ->save(sys_get_temp_dir().'/hr-import-test.xlsx');
    }

    private function value(Employee $e, string $list, string $key): ?string
    {
        $item = ChecklistItem::where('list', $list)->where('key', $key)->firstOrFail();

        return ChecklistEntry::where('employee_id', $e->id)->where('checklist_item_id', $item->id)->value('status');
    }

    public function test_import_builds_employee_master_and_links_rows_by_id(): void
    {
        $result = (new HrWorkbookImporter($this->workbook()))->run(true);
        $report = collect($result['report']);

        // 5 BCC + 3 Emerge from the department lists, plus 2 names found only in onboarding sheets
        $this->assertSame(10, Employee::count());
        $this->assertSame(['UNASSIGNED-001', 'UNASSIGNED-002'], Employee::where('employee_code', 'like', 'UNASSIGNED-%')->orderBy('employee_code')->pluck('employee_code')->all());

        // Spelling difference "Razaq" vs "Razzaq" is the same person; tracker + checklist land on one record
        $fizza = Employee::where('employee_code', 'BCC-001')->firstOrFail();
        $this->assertSame('2017-01-01', $fizza->start_date->toDateString());
        $this->assertSame('2017-04-01', $fizza->onboarding->probation_end_date->toDateString());
        $this->assertSame('Pass', $fizza->onboarding->probation_result);
        $this->assertSame('https://drive.google.com/drive/folders/abc', $fizza->drive_folder_url);
        $board = new StatusBoard([$fizza->id]);
        $this->assertSame('Onboarding Complete', $board->onboarding($fizza->id, 'Pass'));
        $this->assertSame('Complete', $board->documents($fizza->id));
        $this->assertTrue($report->contains(fn ($r) => $r['result'] === 'duplicate_row' && $r['employee_code'] === 'BCC-001'));

        // Typo match is used but flagged for review
        $laiba = Employee::where('employee_code', 'BCC-004')->firstOrFail();
        $this->assertTrue($laiba->needs_review);
        $this->assertSame('2026-04-14', $laiba->onboarding->probation_end_date->toDateString(), 'computed as start + 90 when the sheet has no end date');
        // Facts typed twice: checklist wins, tracker fills blanks, disagreements are reported
        $this->assertSame('Yes', $this->value($laiba, 'document', 'nda_signed'));
        $this->assertSame('No', $this->value($laiba, 'document', 'nca_signed'));
        $this->assertTrue($report->contains(fn ($r) => $r['result'] === 'conflict' && $r['employee_code'] === 'BCC-004' && str_contains($r['detail'], 'NDA')));
        // Lower-case values are mapped to the canonical spelling
        $this->assertSame('not available', $this->value($laiba, 'document', 'bank_details'));
        $this->assertSame('Pending', $this->value($laiba, 'document', 'educational_documents'));

        // Two "Bilal Ahmed" on the BCC list: never guessed, reported
        $this->assertSame(1, $report->where('result', 'ambiguous')->count());
        $this->assertFalse(Employee::where('employee_code', 'BCC-002')->firstOrFail()->onboarding()->exists());

        // "Muhammad Talha Shah" must not be matched to "M. Tariq Shah"
        $this->assertSame('UNASSIGNED-001', Employee::where('full_name', 'Muhammad Talha Shah')->value('employee_code'));
        $this->assertFalse(Employee::where('employee_code', 'BCC-005')->firstOrFail()->onboarding()->exists());

        // "(developer)" picks the Yasir in the Developers department
        $this->assertTrue(Employee::where('employee_code', 'Emerge-002')->firstOrFail()->onboarding()->exists());
        $this->assertFalse(Employee::where('employee_code', 'Emerge-003')->firstOrFail()->onboarding()->exists());

        // Subset match + invalid value reported and left blank
        $zubair = Employee::where('employee_code', 'Emerge-001')->firstOrFail();
        $this->assertTrue($zubair->needs_review);
        $this->assertNull($this->value($zubair, 'onboarding', 'email_created'));
        $this->assertTrue($report->contains(fn ($r) => $r['result'] === 'invalid_value' && str_contains($r['detail'], 'Maybe')));

        // Department headcounts agree with the sheet's own table
        $this->assertSame(0, $report->where('result', 'headcount_differs')->count());

        // Controlled documents + templates, offboarding stub reported
        $this->assertSame(['BCC-0001', 'BCC-0002'], ControlledDocument::orderBy('doc_number')->pluck('doc_number')->all());
        $this->assertSame('Joining Letter', ControlledDocument::where('doc_number', 'BCC-0001')->value('name'));
        $this->assertTrue($report->contains(fn ($r) => $r['result'] === 'skipped' && $r['sheet'] === 'Offboarding_Tracker'));
    }

    public function test_command_refuses_to_overwrite_without_fresh_and_is_repeatable_with_it(): void
    {
        Storage::fake('local');   // the report CSV goes to a fake disk, not storage/app
        $path = $this->workbook();
        $this->artisan('hr:import', ['path' => $path])->assertSuccessful();
        $this->assertSame(10, Employee::count());

        $this->artisan('hr:import', ['path' => $path])->assertFailed();

        $this->artisan('hr:import', ['path' => $path, '--fresh' => true])->assertSuccessful();
        $this->assertSame(10, Employee::count());
        $this->assertCount(2, Storage::disk('local')->files('import'), 'one report per successful run');
    }

    public function test_alias_file_resolves_an_ambiguous_name(): void
    {
        (new HrWorkbookImporter($this->workbook(), ['Bilal Ahmed' => 'BCC-003']))->run(true);
        $this->assertTrue(Employee::where('employee_code', 'BCC-003')->firstOrFail()->onboarding()->exists());
        $this->assertFalse(Employee::where('employee_code', 'BCC-002')->firstOrFail()->onboarding()->exists());
    }

    public function test_missing_sheet_fails_cleanly(): void
    {
        Storage::fake('local');
        $path = (new XlsxBuilder)->sheet('Sheet1', [1 => ['A' => 'x']])->save(sys_get_temp_dir().'/hr-empty.xlsx');
        $this->artisan('hr:import', ['path' => $path])->assertFailed();
        $this->assertSame(0, Employee::count());
    }
}
