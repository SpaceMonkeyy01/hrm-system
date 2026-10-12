<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\Import\HrWorkbookImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Support\XlsxBuilder;
use Tests\TestCase;

/** Regression tests for the importer findings I1-I7 in docs/REVIEW.md (fake people only). */
class ImportRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function book(array $bcc, array $tracker = [], array $docs = []): string
    {
        $hdr = ['A' => 'ID', 'B' => 'Name', 'C' => 'Team', 'D' => 'Department'];

        return (new XlsxBuilder)
            ->sheet('BCC Employee teams Departments', [1 => $hdr] + $bcc)
            ->sheet('Emerge Employee teams Departmen', [1 => $hdr])
            ->sheet('Onboarding_Tracker', [1 => ['B' => 'Employee_Name', 'H' => 'Start_Date', 'I' => 'Probation_End_Date', 'K' => 'Manager_Name', 'O' => 'Email_Created']] + $tracker)
            ->sheet('Onboarding_Document_Checklist', [1 => ['B' => 'Employee_Name', 'I' => 'Start_Date', 'J' => 'CNIC Copy']] + $docs)
            ->save(sys_get_temp_dir().'/import-regression-'.uniqid().'.xlsx');
    }

    private function import(string $path, array $aliases = []): Collection
    {
        return collect((new HrWorkbookImporter($path, $aliases))->run(true)['report']);
    }

    private function names(): array
    {
        return Employee::orderBy('employee_code')->pluck('full_name', 'employee_code')->all();
    }

    /** I1 */
    public function test_blank_id_never_takes_a_code_that_a_later_row_claims(): void
    {
        $this->import($this->book([2 => ['A' => '', 'B' => 'Alice Blank'], 3 => ['A' => 1, 'B' => 'Bob One']]));

        $this->assertSame(['BCC-001' => 'Bob One', 'BCC-002' => 'Alice Blank'], $this->names());
    }

    /** I2 */
    public function test_duplicate_id_is_reported_and_does_not_overwrite(): void
    {
        $report = $this->import($this->book([2 => ['A' => 7, 'B' => 'Carol Seven'], 3 => ['A' => 7, 'B' => 'Dave Seven']]));

        $this->assertSame(['BCC-007' => 'Carol Seven', 'BCC-008' => 'Dave Seven'], $this->names());
        $line = $report->firstWhere('result', 'duplicate_id');
        $this->assertNotNull($line);
        $this->assertSame(['3', 'Dave Seven', 'BCC-008'], [$line['row'], $line['source_name'], $line['employee_code']]);
        $this->assertTrue(Employee::where('employee_code', 'BCC-008')->value('needs_review'));
    }

    /** I3 */
    public function test_alias_copied_from_the_ambiguous_report_line_links_the_row(): void
    {
        $bcc = [2 => ['A' => 1, 'B' => 'Ali Raza', 'C' => 'Ops', 'D' => 'Admin'], 3 => ['A' => 2, 'B' => 'Ali Raza', 'C' => 'Ops', 'D' => 'Admin']];
        $tracker = [2 => ['B' => 'Ali Raza (night shift)', 'O' => 'Yes']];

        $source = $this->import($this->book($bcc, $tracker))->firstWhere('result', 'ambiguous')['source_name'];
        $this->assertSame('Ali Raza (night shift)', $source);

        $report = $this->import($this->book($bcc, $tracker), [$source => 'BCC-002']);
        $this->assertFalse($report->contains('result', 'ambiguous'));
        $this->assertTrue(Employee::where('employee_code', 'BCC-002')->first()->onboarding()->exists());
        $this->assertFalse(Employee::where('employee_code', 'BCC-001')->first()->onboarding()->exists());
    }

    /** I4 */
    public function test_text_dates_are_parsed_and_bad_ones_reported(): void
    {
        $bcc = [];
        $tracker = [];
        foreach (['15/01/2024', '15-01-2024', '15.01.2024', '15-Jan-2024', '2024-01-15'] as $i => $typed) {
            $bcc[$i + 2] = ['A' => $i + 1, 'B' => "Date Person $i"];
            $tracker[$i + 2] = ['B' => "Date Person $i", 'H' => $typed];
        }
        $bcc[9] = ['A' => 9, 'B' => 'Bad Date'];
        $tracker[9] = ['B' => 'Bad Date', 'H' => 'next Monday', 'I' => '31/02/2024'];
        $report = $this->import($this->book($bcc, $tracker));

        foreach (range(1, 5) as $n) {
            $e = Employee::with('onboarding')->where('employee_code', sprintf('BCC-%03d', $n))->first();
            $this->assertSame('2024-01-15', $e->start_date?->format('Y-m-d'), "row for BCC-00$n");
            $this->assertSame('2024-04-14', $e->onboarding->probation_end_date?->format('Y-m-d'));
        }
        $bad = Employee::where('employee_code', 'BCC-009')->first();
        $this->assertNull($bad->start_date);
        $invalid = $report->where('result', 'invalid_value')->where('source_name', 'Bad Date')->pluck('detail');
        $this->assertCount(2, $invalid);
        $this->assertTrue($invalid->contains(fn ($d) => str_contains($d, 'Start_Date "next Monday"')));
        $this->assertTrue($invalid->contains(fn ($d) => str_contains($d, 'Probation_End_Date "31/02/2024"')));
    }

    /** I5 */
    public function test_name_plus_hint_alias_splits_two_same_named_people(): void
    {
        $bcc = [2 => ['A' => 1, 'B' => 'Ali Raza'], 3 => ['A' => 2, 'B' => 'Ali Raza']];
        $tracker = [2 => ['B' => 'Ali Raza (day shift)', 'O' => 'Yes'], 3 => ['B' => 'Ali  Raza ( Night shift )', 'O' => 'No']];
        $report = $this->import($this->book($bcc, $tracker), ['Ali Raza (day shift)' => 'BCC-001', 'ali raza (night shift)' => 'BCC-002']);

        $this->assertSame(['BCC-001', 'BCC-002'], $report->where('result', 'matched')->pluck('employee_code')->values()->all());
        $this->assertFalse($report->contains('result', 'duplicate_conflict'));
        $this->assertTrue(Employee::where('employee_code', 'BCC-002')->first()->onboarding()->exists());
    }

    /** I6 */
    public function test_manager_is_linked_only_on_a_sure_match(): void
    {
        $bcc = [2 => ['A' => 1, 'B' => 'Hira Batool'], 3 => ['A' => 2, 'B' => 'Frank Staff'], 4 => ['A' => 3, 'B' => 'Gail Staff']];
        $tracker = [2 => ['B' => 'Frank Staff', 'K' => 'Hina Batool'], 3 => ['B' => 'Gail Staff', 'K' => 'hira  batool']];
        $report = $this->import($this->book($bcc, $tracker));

        $hira = Employee::where('employee_code', 'BCC-001')->value('id');
        $this->assertNull(Employee::where('employee_code', 'BCC-002')->value('manager_id'), 'fuzzy match is not linked');
        $this->assertSame($hira, Employee::where('employee_code', 'BCC-003')->value('manager_id'), 'exact match is linked');

        $line = $report->firstWhere('source_name', 'Hina Batool');
        $this->assertSame(['manager_not_linked', 'fuzzy', 'BCC-002'], [$line['result'], $line['match_type'], $line['employee_code']]);
        $this->assertFalse($report->contains('source_name', 'hira batool'));
    }

    /** I7 */
    public function test_alias_to_an_unknown_code_is_reported_and_creates_nobody(): void
    {
        $report = $this->import($this->book([2 => ['A' => 1, 'B' => 'Gina Real']], [2 => ['B' => 'Gina R']]), ['Gina R' => 'BCC-01']);

        $this->assertSame(1, Employee::count());
        $line = $report->firstWhere('result', 'bad_alias');
        $this->assertNotNull($line);
        $this->assertStringContainsString('BCC-01', $line['detail']);
        $this->assertFalse($report->contains('result', 'created'));
    }
}
