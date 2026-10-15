<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Import\HrWorkbookImporter;
use App\Services\Import\MasterValidationException;
use App\Services\PeopleStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\XlsxBuilder;
use Tests\TestCase;

/**
 * hr:import --master: Employee_Master.xlsx (fake people, real layout) seeds employees, and the onboarding
 * workbook is matched to them by name. Validation rules V1-V10 from HRM-IMPORT-V2-SPEC.md.
 */
class MasterImportTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_HEADER = ['employee_code', 'full_name', 'status', 'branch', 'department', 'department_2', 'team', 'designation',
        'categories', 'employment_type', 'probation_status', 'probation_end_date', 'manager_1_code', 'manager_1_name', 'manager_1_unresolved',
        'manager_2_code', 'manager_2_name', 'manager_2_unresolved', 'gender', 'work_email', 'work_phone', 'cnic', 'join_date',
        'review_flags', 'hr_reviewed', 'legacy_list_id', 'legacy_list_name', 'legacy_odoo_department', 'match_type'];

    private const REVIEW_HEADER = ['legacy_odoo_sr_no', 'name', 'status', 'branch', 'department', 'category', 'reporting_manager',
        'review_reason', 'similar_current_employee', 'reports_in_odoo', 'hr_decision', 'hr_notes'];

    private function people(): array
    {
        $base = ['status' => 'Active', 'employment_type' => 'Full-Time', 'probation_status' => 'Confirmed'];

        return [
            ['employee_code' => 'BCC-001', 'full_name' => 'Nida Qaddir', 'branch' => 'BCC', 'department' => 'Executives', 'team' => 'Executives',
                'designation' => 'Founder', 'gender' => 'Female'] + $base,
            ['employee_code' => 'BCC-002', 'full_name' => 'Bilal Ahmed', 'branch' => 'BCC', 'department' => 'Code Blue', 'department_2' => 'HR',
                'team' => 'Google Ads-Calling team', 'categories' => 'Google Ads Neon, Etsy BS', 'manager_1_code' => 'BCC-001', 'manager_1_name' => 'Nida Qaddir',
                'work_email' => 'Bilal@Example.test', 'cnic' => '36302-1234567-1', 'join_date' => ['date' => '2026-09-01'], 'probation_status' => 'On Probation',
                'legacy_list_id' => 7, 'legacy_list_name' => 'Bilal  Ahmad', 'match_type' => 'variant'] + $base,
            ['employee_code' => 'BCC-003', 'full_name' => 'Saima Javed', 'branch' => 'BCC', 'department' => 'Code Blue', 'categories' => 'Etsy BS',
                'manager_1_code' => 'BCC-002', 'manager_2_code' => 'BCC-001', 'review_flags' => 'Department guessed from team', 'employment_type' => 'Intern'] + $base,
            ['employee_code' => 'EMG-001', 'full_name' => 'Zubair Hassan Malik', 'branch' => 'Emerge', 'department' => 'QA Team',
                'review_flags' => 'Checked already', 'hr_reviewed' => 'Yes', 'employment_type' => 'Contract', 'gender' => 'Other'] + $base,
            ['employee_code' => 'EMG-002', 'full_name' => 'M. Faisal', 'branch' => 'Emerge', 'department' => 'QA Team', 'manager_1_code' => 'EMG-001',
                'manager_1_unresolved' => 'Sir Ali'] + $base,
        ];
    }

    private function reviewRows(): array
    {
        return [
            ['legacy_odoo_sr_no' => 10, 'name' => 'Nauman Arshad', 'status' => 'Review', 'branch' => 'Lahore Office', 'department' => 'Code Blue',
                'category' => 'Etsy BS', 'reporting_manager' => 'Nida Qaddir', 'review_reason' => 'Not on current lists - likely left', 'reports_in_odoo' => 0],
            ['legacy_odoo_sr_no' => 11, 'name' => 'Aroob Fatima', 'status' => 'Inactive', 'branch' => 'Emerge', 'department' => 'QA Team', 'hr_decision' => 'Left'],
        ];
    }

    private function master(?array $people = null, ?array $review = null, array $dropColumns = []): string
    {
        $lookups = [
            'Branch' => ['BCC', 'Emerge', 'Lahore Office'],
            'Department' => ['Code Blue', 'Executives', 'HR', 'QA Team'],
            'Category' => ['Etsy BS', 'Google Ads Neon', 'HR'],
            'Employment Type' => ['Full-Time', 'Contract', 'Intern'],
            'Probation Status' => ['On Probation', 'Confirmed'],
            'Gender' => ['Male', 'Female', 'Other'],
            'HR Decision' => ['Left', 'Duplicate of current employee', 'Still employed - add to master'],
            'Status' => ['Active', 'Inactive', 'Review'],
        ];
        $lookupRows = [];
        $col = 'A';
        foreach ($lookups as $header => $values) {
            $lookupRows[1][$col] = $header;
            foreach ($values as $i => $v) {
                $lookupRows[$i + 2][$col] = $v;
            }
            $col++;
        }

        return (new XlsxBuilder)
            ->sheet('README', [1 => ['A' => 'Employee Master']])
            ->sheet('Employee_Master', $this->grid(array_values(array_diff(self::MASTER_HEADER, $dropColumns)), $people ?? $this->people()))
            ->sheet('For_Review', $this->grid(self::REVIEW_HEADER, $review ?? $this->reviewRows()))
            ->sheet('Lookups', $lookupRows)
            ->save(sys_get_temp_dir().'/hr-master-test-'.uniqid().'.xlsx');
    }

    private function grid(array $header, array $rows): array
    {
        $letters = [];
        $col = 'A';
        foreach ($header as $h) {
            $letters[$h] = $col++;
        }
        $grid = [1 => array_combine(array_values($letters), $header)];
        foreach ($rows as $i => $row) {
            foreach ($row as $key => $value) {
                if (isset($letters[$key]) && $value !== null && $value !== '') {
                    $grid[$i + 2][$letters[$key]] = $value;
                }
            }
        }

        return $grid;
    }

    /** Onboarding workbook with only the two sheets master mode reads. */
    private function onboarding(): string
    {
        return (new XlsxBuilder)
            ->sheet('Onboarding_Tracker', [
                1 => ['B' => 'Employee_Name', 'H' => 'Start_Date', 'J' => 'Employment_Type', 'O' => 'Email_Created', 'AC' => 'Probation_Result'],
                2 => ['B' => 'Nida Qadir', 'H' => ['date' => '2017-01-01'], 'O' => 'Yes', 'AC' => 'Pass'],
                3 => ['B' => 'Someone Who Left', 'H' => ['date' => '2020-01-01']],
            ])
            ->sheet('Onboarding_Document_Checklist', [
                1 => ['B' => 'Employee_Name', 'C' => 'Drive Folder Link', 'J' => 'CNIC Copy'],
                2 => ['B' => 'Saima Javed', 'C' => 'https://drive.google.com/drive/folders/xyz', 'J' => 'Yes'],
            ])
            ->save(sys_get_temp_dir().'/hr-onboarding-test-'.uniqid().'.xlsx');
    }

    private function errorsFor(string $path): array
    {
        try {
            (new HrWorkbookImporter(null, [], $path))->run(true);
        } catch (MasterValidationException $e) {
            return $e->errors;
        }
        $this->fail('Expected the master to fail validation.');
    }

    public function test_master_seeds_employees_managers_categories_review_rows_and_legacy(): void
    {
        $result = (new HrWorkbookImporter($this->onboarding(), [], $this->master()))->run(true);
        $report = collect($result['report']);

        $this->assertSame(5, Employee::where('status', 'active')->count());
        $this->assertSame('EMG-', Company::where('code', 'EMG')->value('employee_code_prefix'));

        $bilal = Employee::where('employee_code', 'BCC-002')->firstOrFail();
        $this->assertSame(['Bilal', 'Ahmed'], [$bilal->first_name, $bilal->last_name]);
        $this->assertSame('BCC-001', $bilal->manager->employee_code);
        $this->assertSame('HR', $bilal->secondaryDepartment->name);
        $this->assertSame(['Etsy BS', 'Google Ads Neon'], $bilal->categories->pluck('name')->all());
        $this->assertSame('bilal@example.test', $bilal->email);
        $this->assertSame('Full Time', $bilal->employment_type, 'Full-Time in the sheet is Full Time in the app');
        $this->assertSame('Google Ads-Calling team', $bilal->team->name);
        $this->assertSame(['legacy_list_id' => '7', 'legacy_list_name' => 'Bilal Ahmad', 'match_type' => 'variant'], $bilal->legacy);
        $this->assertSame('Pending', $bilal->onboarding->probation_result);
        $this->assertSame('2026-11-30', $bilal->onboarding->probation_end_date->toDateString(), 'start + 90 days');

        $saima = Employee::where('employee_code', 'BCC-003')->firstOrFail();
        $this->assertSame('BCC-001', $saima->secondaryManager->employee_code);
        $this->assertTrue($saima->needs_review);
        $this->assertSame('Intern', $saima->employment_type);
        $this->assertFalse(Employee::where('employee_code', 'EMG-001')->value('needs_review'), 'hr_reviewed = Yes clears the flags');
        $this->assertSame('Other', Employee::where('employee_code', 'EMG-001')->value('gender'));
        $faisal = Employee::where('employee_code', 'EMG-002')->firstOrFail();
        $this->assertTrue($faisal->needs_review, 'V8: unresolved manager text');
        $this->assertStringContainsString('Sir Ali', $faisal->review_note);
        $this->assertTrue($report->contains(fn ($r) => $r['result'] === 'warning' && $r['source_name'] === 'M. Faisal'));

        // For_Review: REV codes in row order, Lahore Office is BCC, notes and legacy kept
        $nauman = Employee::where('employee_code', 'REV-001')->firstOrFail();
        $this->assertSame(['Nauman Arshad', 'review', 'BCC'], [$nauman->full_name, $nauman->status, $nauman->company->name]);
        $this->assertTrue($nauman->needs_review);
        $this->assertStringContainsString("reporting_manager: Nida Qaddir\nreview_reason: Not on current lists", $nauman->review_note);
        $this->assertSame(['legacy_odoo_sr_no' => '10', 'reports_in_odoo' => '0'], $nauman->legacy);
        $this->assertSame('exited', Employee::where('employee_code', 'REV-002')->value('status'));
        $this->assertSame(3, Category::count(), 'seeded from Lookups');

        // Onboarding workbook links by name to the new codes; a name not on the master is skipped, not created
        $nida = Employee::where('employee_code', 'BCC-001')->firstOrFail();
        $this->assertSame('Pass', $nida->onboarding->probation_result);
        $this->assertSame('2017-01-01', $nida->start_date->toDateString());
        $this->assertSame('https://drive.google.com/drive/folders/xyz', $saima->fresh()->drive_folder_url);
        $this->assertFalse(Employee::where('full_name', 'Someone Who Left')->exists());
        $this->assertTrue($report->contains(fn ($r) => $r['result'] === 'not_in_master' && $r['source_name'] === 'Someone Who Left'));
    }

    public function test_fresh_rerun_gives_the_same_result(): void
    {
        $master = $this->master();
        $snapshot = function () {
            return Employee::orderBy('employee_code')->with('categories', 'manager', 'secondaryManager', 'department', 'team')->get()->map(fn ($e) => [
                $e->employee_code, $e->full_name, $e->status, $e->manager?->employee_code, $e->secondaryManager?->employee_code,
                $e->department?->name, $e->team?->name, $e->categories->pluck('name')->all(), $e->legacy, $e->needs_review,
            ])->all();
        };
        (new HrWorkbookImporter(null, [], $master))->run(true);
        $first = $snapshot();
        (new HrWorkbookImporter(null, [], $master))->run(true);
        $this->assertSame($first, $snapshot());
        $this->assertSame(7, Employee::count());
    }

    public function test_attendance_links_and_app_clock_ins_carry_over_by_name(): void
    {
        $device = AttendanceDevice::create(['name' => 'Main office', 'host' => '203.0.113.10']);
        $this->assertSame('ATD-'.str_pad((string) $device->id, 3, '0', STR_PAD_LEFT), $device->code);
        $company = Company::create(['name' => 'BCC', 'code' => 'BCC', 'employee_code_prefix' => 'BCC-']);
        $old = fn (string $code, string $name, array $links = []) => Employee::create(['employee_code' => $code, 'full_name' => $name, 'company_id' => $company->id] + $links);
        $bilal = $old('BCC-050', 'Bilal Ahmed', ['attendance_machine_id' => '45', 'hr_portal_id' => 'BCC-198', 'attendance_device_id' => $device->id]);
        $old('BCC-051', 'Saima Javeed', ['attendance_machine_id' => '46']);            // spelling: carried, device from its punches
        AttendancePunch::create(['source' => 'machine', 'device_user_id' => '46', 'attendance_device_id' => $device->id, 'punched_at' => '2026-10-14 09:05:00', 'work_date' => '2026-10-14']);
        $old('BCC-052', 'Nauman Arshad', ['hr_portal_id' => 'X-9']);                   // now a REV record
        $old('BCC-053', 'Faraz Awan', ['attendance_machine_id' => '52']);              // not on the master
        AttendancePunch::create(['source' => 'app', 'device_user_id' => (string) $bilal->id, 'punched_at' => '2026-10-14 09:00:00', 'work_date' => '2026-10-14']);

        $result = (new HrWorkbookImporter(null, [], $this->master()))->run(true);
        $report = collect($result['report']);

        $newBilal = Employee::where('employee_code', 'BCC-002')->firstOrFail();
        $this->assertSame(['45', 'BCC-198', $device->id], [$newBilal->attendance_machine_id, $newBilal->hr_portal_id, $newBilal->attendance_device_id]);
        $this->assertSame((string) $newBilal->id, AttendancePunch::where('source', 'app')->value('device_user_id'), 'app clock-ins follow the person');
        $this->assertSame(['46', $device->id], array_values(Employee::where('employee_code', 'BCC-003')->first(['attendance_machine_id', 'attendance_device_id'])->only(['attendance_machine_id', 'attendance_device_id'])));
        $this->assertSame('X-9', Employee::where('employee_code', 'REV-001')->value('hr_portal_id'));
        $this->assertTrue($report->contains(fn ($r) => $r['result'] === 'link_not_carried' && $r['source_name'] === 'Faraz Awan' && str_contains($r['detail'], 'machine no. 52')));
        $this->assertFalse(Employee::where('attendance_machine_id', '52')->exists());
        $this->assertSame(7, Employee::count(), 'old records are gone');
    }

    public function test_same_name_in_two_companies_uses_the_old_company_and_links_csv_wins(): void
    {
        $bcc = Company::create(['name' => 'BCC', 'code' => 'BCC', 'employee_code_prefix' => 'BCC-']);
        $emerge = Company::create(['name' => 'Emerge', 'code' => 'EMG', 'employee_code_prefix' => 'Emerge-']);
        Employee::create(['employee_code' => 'BCC-090', 'full_name' => 'Bilal Ahmed', 'company_id' => $bcc->id, 'attendance_machine_id' => '82']);
        Employee::create(['employee_code' => 'Emerge-090', 'full_name' => 'M. Faisal', 'company_id' => $emerge->id, 'attendance_machine_id' => '30']);
        $people = $this->people();
        $people[] = ['employee_code' => 'EMG-003', 'full_name' => 'Bilal Ahmed', 'branch' => 'Emerge', 'department' => 'QA Team'] + $people[3];
        $people[] = ['employee_code' => 'BCC-004', 'full_name' => 'M. Faisal', 'branch' => 'BCC', 'department' => 'Code Blue'] + $people[3];
        $people[] = ['employee_code' => 'EMG-004', 'full_name' => 'M. Faisal', 'branch' => 'Emerge', 'department' => 'QA Team'] + $people[3];
        foreach ($people as &$p) {
            unset($p['review_flags'], $p['hr_reviewed']);
        }
        unset($p);
        $people[4]['manager_1_unresolved'] = '';

        $links = [
            ['employee_code' => 'EMG-003', 'source' => 'hrmy', 'device_user_id' => 'H-1', 'line' => 2],
            ['employee_code' => 'EMG-004', 'source' => 'machine', 'device_user_id' => '82', 'line' => 3],   // moves 82 off BCC-002
            ['employee_code' => 'BCC-999', 'source' => 'machine', 'device_user_id' => '5', 'line' => 4],
        ];
        $result = (new HrWorkbookImporter(null, [], $this->master($people), $links))->run(true);
        $report = collect($result['report']);
        $code = fn (string $c) => Employee::where('employee_code', $c)->firstOrFail();

        // Old BCC Bilal: two Bilals now, only one in BCC -> carried there, checked
        $this->assertTrue($report->contains(fn ($r) => $r['result'] === 'link_check' && $r['employee_code'] === 'BCC-002'));
        $this->assertStringContainsString('by name and company', $code('BCC-002')->review_note);
        // Old Emerge M. Faisal: two in Emerge too -> not carried, both flagged
        $this->assertNull($code('EMG-002')->attendance_machine_id);
        foreach (['EMG-002', 'EMG-004'] as $c) {
            $this->assertTrue($code($c)->needs_review, $c);
            $this->assertStringContainsString('Old record Emerge-090 "M. Faisal" (machine no. 30) could be this person', $code($c)->review_note);
        }
        $this->assertFalse($code('BCC-004')->needs_review, 'not in the old company');
        // links.csv applied last
        $this->assertSame('H-1', $code('EMG-003')->hr_portal_id);
        $this->assertSame('82', $code('EMG-004')->attendance_machine_id);
        $this->assertNull($code('BCC-002')->attendance_machine_id);
        $this->assertTrue($report->contains(fn ($r) => $r['result'] === 'link_moved' && $r['employee_code'] === 'BCC-002'));
        $this->assertTrue($report->contains(fn ($r) => $r['result'] === 'bad_link' && str_contains($r['detail'], 'no employee has code BCC-999')));
    }

    public function test_command_takes_master_and_onboarding_and_prints_every_error(): void
    {
        Storage::fake('local');
        $this->artisan('hr:import', ['--master' => $this->master(), '--onboarding' => $this->onboarding()])->assertSuccessful();
        $this->assertSame(7, Employee::count());
        $this->artisan('hr:import', ['--master' => $this->master()])->assertFailed();   // has employees, no --fresh

        $people = $this->people();
        $people[2]['employee_code'] = 'BCC-1';
        $people[1]['work_email'] = 'not-an-email';
        $this->artisan('hr:import', ['--master' => $this->master($people), '--fresh' => true])
            ->expectsOutputToContain('2 problem(s) in the master file')
            ->expectsOutputToContain('employee_code "BCC-1"')
            ->expectsOutputToContain('work_email "not-an-email"')
            ->assertFailed();
        $this->assertSame(7, Employee::count(), 'nothing changed');
    }

    /** One fixture row breaks one rule. */
    public function test_each_validation_rule(): void
    {
        $cases = [
            'V1 format' => [fn (&$p) => $p[2]['employee_code'] = 'BCC-03', 'must look like BCC-001'],
            'V1 unique' => [fn (&$p) => $p[] = ['employee_code' => 'BCC-003', 'full_name' => 'Extra Person'] + $p[0], 'BCC-003 is also used on row 4'],
            'V2 lookup' => [fn (&$p) => $p[1]['department'] = 'Marketing', 'department "Marketing" is not in Lookups > Department'],
            'V2 category' => [fn (&$p) => $p[1]['categories'] = 'Etsy BS, Shopify', 'categories "Shopify" is not in Lookups > Category'],
            'V3 unknown' => [fn (&$p) => $p[1]['manager_1_code'] = 'BCC-099', 'manager_1_code BCC-099 is not an employee_code'],
            'V3 self' => [fn (&$p) => $p[1]['manager_2_code'] = 'BCC-002', "manager_2_code is the row's own code"],
            'V4 loop' => [fn (&$p) => $p[0]['manager_1_code'] = 'BCC-003', 'Reporting loop through manager_1_code: BCC-001 > BCC-003 > BCC-002 > BCC-001'],
            'V5 unique' => [fn (&$p) => $p[2]['work_email'] = 'bilal@example.test', 'work_email bilal@example.test is also used on row 3'],
            'V6 cnic' => [fn (&$p) => $p[0]['cnic'] = '3630212345671', 'must look like 36302-1234567-1'],
            'V7 prefix' => [fn (&$p) => $p[4]['branch'] = 'BCC', 'EMG-002 does not match branch BCC'],
        ];
        foreach ($cases as $rule => [$break, $message]) {
            $people = $this->people();
            $break($people);
            $errors = $this->errorsFor($this->master($people));
            $this->assertCount(1, $errors, "$rule: ".implode(' / ', $errors));
            $this->assertStringContainsString($message, $errors[0], $rule);
        }

        $review = $this->reviewRows();
        $review[0]['status'] = 'Active';
        $errors = $this->errorsFor($this->master(null, $review));
        $this->assertSame(['For_Review row 2: Nauman Arshad has status Active. Move this row to Employee_Master first.'], $errors, 'V9');

        $errors = $this->errorsFor($this->master(null, null, ['team', 'hr_reviewed']));
        $this->assertSame(['Employee_Master is missing these columns: team, hr_reviewed.'], $errors, 'V10');
        $this->assertSame(0, Employee::count());
    }

    public function test_review_people_are_left_out_until_hr_confirms_them_with_the_next_code(): void
    {
        (new HrWorkbookImporter(null, [], $this->master()))->run(true);
        $rev = Employee::where('employee_code', 'REV-001')->firstOrFail();
        $this->assertFalse(PeopleStats::onBooks($rev, '2026-10-15'));
        $this->actingAs($this->user('hr'))->get('/employees')->assertInertia(fn ($p) => $p->where('employees.total', 5));
        $this->actingAs($this->user('hr'))->get('/employees?status=review')->assertInertia(fn ($p) => $p->where('employees.total', 1));

        $this->actingAs($this->user('viewer'))->post("/employees/{$rev->id}/review-decision", ['decision' => 'active'])->assertForbidden();
        $this->actingAs($this->user('hr'))->post("/employees/{$rev->id}/review-decision", ['decision' => 'active'])->assertSessionHas('success');
        $rev->refresh();
        $this->assertSame(['BCC-004', 'active', false], [$rev->employee_code, $rev->status, $rev->needs_review]);
        $this->assertTrue(PeopleStats::onBooks($rev, '2026-10-15'));

        $this->actingAs($this->user('hr'))->post("/employees/{$rev->id}/review-decision", ['decision' => 'exited'])->assertStatus(422);
    }

    public function test_hr_edits_categories_second_department_second_manager_and_device(): void
    {
        (new HrWorkbookImporter(null, [], $this->master()))->run(true);
        $device = AttendanceDevice::create(['name' => 'Main office', 'host' => '203.0.113.10']);
        $nida = Employee::where('employee_code', 'BCC-001')->firstOrFail();
        $saima = Employee::where('employee_code', 'BCC-003')->firstOrFail();
        $hr = Department::query()->where('name', 'HR')->firstOrFail();
        $emergeDept = Department::query()->where('name', 'QA Team')->firstOrFail();

        $form = ['full_name' => $nida->full_name, 'company_id' => $nida->company_id, 'department_id' => $nida->department_id];
        $this->actingAs($this->user('hr'))->put("/employees/{$nida->id}", $form + [
            'category_ids' => [Category::where('name', 'HR')->value('id')], 'secondary_department_id' => $hr->id,
            'secondary_manager_id' => $saima->id, 'attendance_device_id' => $device->id,
        ])->assertSessionHasNoErrors();
        $nida->refresh();
        $this->assertSame(['HR'], $nida->categories->pluck('name')->all());
        $this->assertSame([$hr->id, $saima->id, $device->id], [$nida->secondary_department_id, $nida->secondary_manager_id, $nida->attendance_device_id]);

        $this->actingAs($this->user('hr'))->put("/employees/{$nida->id}", $form + ['secondary_department_id' => $emergeDept->id])
            ->assertSessionHasErrors(['secondary_department_id' => 'This department belongs to another company.']);
        $this->actingAs($this->user('hr'))->put("/employees/{$nida->id}", $form)->assertSessionHasNoErrors();
        $this->assertSame(['HR'], $nida->fresh()->categories->pluck('name')->all(), 'a form without category_ids keeps them');
    }

    public function test_admin_manages_categories(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->post('/settings/categories', ['name' => 'Etsy Neon'])->assertSessionHas('success');
        $this->actingAs($admin)->post('/settings/categories', ['name' => 'Etsy Neon'])->assertSessionHasErrors('name');
        $c = Category::where('name', 'Etsy Neon')->firstOrFail();
        $this->actingAs($admin)->put("/settings/categories/{$c->id}", ['name' => 'Etsy Neon Signs'])->assertSessionHas('success');
        $e = Employee::create(['employee_code' => 'X-1', 'full_name' => 'Tag Holder']);
        $e->categories()->attach($c->id);
        $this->actingAs($admin)->delete("/settings/categories/{$c->id}")->assertSessionHas('error');
        $e->categories()->detach();
        $this->actingAs($admin)->delete("/settings/categories/{$c->id}")->assertSessionHas('success');
        $this->actingAs($this->user('hr'))->post('/settings/categories', ['name' => 'Nope'])->assertForbidden();
    }
}
