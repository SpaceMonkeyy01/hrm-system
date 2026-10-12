<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Master profile (personal, contact, emergency, bank, work mode) and employment history. Fake data only. */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->dept] = $this->baseData();
    }

    private function profile(): array
    {
        return [
            'company_id' => $this->company->id, 'full_name' => 'Fake Person',
            'father_name' => 'Fake Father', 'date_of_birth' => '1995-04-01', 'gender' => 'Female', 'marital_status' => 'Single',
            'blood_group' => 'O+', 'cnic' => '1234512345671', 'cnic_expiry' => '2030-01-01',
            'personal_email' => 'fake@example.test', 'alternate_phone' => '0300 0000000', 'city' => 'Lahore',
            'current_address' => 'House 1, Street 1', 'permanent_address' => 'House 2, Street 2',
            'emergency_contact_name' => 'Fake Contact', 'emergency_contact_relation' => 'Mother', 'emergency_contact_phone' => '0300 1111111',
            'bank_name' => 'Fake Bank', 'bank_account_title' => 'Fake Person', 'bank_account_number' => 'pk00 fake 0000 0000 0000 0000',
            'work_mode' => 'Remote', 'work_location' => 'Home', 'confirmation_date' => '2026-01-01',
        ];
    }

    public function test_hr_saves_the_master_profile(): void
    {
        $e = $this->employee($this->company);

        $this->actingAs($this->user('hr'))->put("/employees/{$e->id}", $this->profile())->assertRedirect("/employees/{$e->id}");

        $e->refresh();
        $this->assertSame('1995-04-01', $e->date_of_birth->toDateString());
        $this->assertSame('PK00FAKE0000000000000000', $e->bank_account_number);
        $this->assertSame('12345-1234567-1', $e->cnic);
        $this->assertSame('Remote', $e->work_mode);
        $this->assertSame('Mother', $e->emergency_contact_relation);
    }

    public function test_profile_values_are_checked(): void
    {
        $e = $this->employee($this->company);
        $bad = ['gender' => 'Robot', 'blood_group' => 'C+', 'work_mode' => 'Moon', 'date_of_birth' => '2999-01-01',
            'bank_account_number' => 'abc$%', 'personal_email' => 'not-an-email'];

        $this->actingAs($this->user('hr'))->put("/employees/{$e->id}", ['company_id' => $this->company->id, 'full_name' => 'X'] + $bad)
            ->assertSessionHasErrors(array_keys($bad));
    }

    public function test_viewers_never_receive_private_fields(): void
    {
        $e = $this->employee($this->company, array_diff_key($this->profile(), ['company_id' => 1, 'full_name' => 1]));

        $this->actingAs($this->user('viewer'))->get("/employees/{$e->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('employee.private_hidden', true)
            ->where('employee.bank_account_number', null)
            ->where('employee.cnic', null)
            ->where('employee.date_of_birth', null)
            ->where('employee.emergency_contact_phone', null)
            ->where('employee.work_mode', 'Remote'));

        $this->actingAs($this->user('hr'))->get("/employees/{$e->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('employee.private_hidden', false)
            ->where('employee.bank_account_number', 'pk00 fake 0000 0000 0000 0000')
            ->where('employee.date_of_birth', '1995-04-01'));
    }

    public function test_activity_log_hides_private_values(): void
    {
        $e = $this->employee($this->company);
        $this->actingAs($this->user('hr'))->put("/employees/{$e->id}", $this->profile());

        $log = ActivityLog::where('subject_type', 'Employee')->where('event', 'updated')->latest('id')->first();
        $this->assertSame(['hidden', 'hidden'], $log->changes['bank_account_number']);
        $this->assertSame(['hidden', 'hidden'], $log->changes['cnic']);
        $this->assertSame([null, 'Lahore'], $log->changes['city']);
        $this->assertStringNotContainsString('PK00FAKE', json_encode(ActivityLog::all()->pluck('changes')));
    }

    public function test_job_change_on_edit_writes_employment_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00'));
        $sales = Department::create(['company_id' => $this->company->id, 'name' => 'Sales']);
        $e = $this->employee($this->company, ['department_id' => $this->dept->id, 'role_title' => 'Designer']);
        $hr = $this->user('hr');

        $this->actingAs($hr)->put("/employees/{$e->id}", ['company_id' => $this->company->id, 'full_name' => $e->full_name,
            'department_id' => $sales->id, 'role_title' => 'Senior Designer']);

        $h = EmploymentHistory::sole();
        $this->assertSame('Transfer', $h->change_type);
        $this->assertSame('2026-10-09', $h->effective_date->toDateString());
        $this->assertSame(['Department' => ['HR', 'Sales'], 'Role' => ['Designer', 'Senior Designer']], $h->changes);
        $this->assertSame($hr->id, $h->created_by);

        // HR picks the type and date; a save that changes no job field adds nothing.
        $this->actingAs($hr)->put("/employees/{$e->id}", ['company_id' => $this->company->id, 'full_name' => $e->full_name,
            'role_title' => 'Lead Designer', 'change_type' => 'Promotion', 'change_effective_date' => '2026-10-01', 'change_note' => 'Annual review']);
        $this->actingAs($hr)->put("/employees/{$e->id}", ['company_id' => $this->company->id, 'full_name' => $e->full_name, 'city' => 'Lahore']);

        $this->assertSame(2, EmploymentHistory::count());
        $latest = EmploymentHistory::latest('id')->first();
        $this->assertSame(['Promotion', '2026-10-01', 'Annual review'], [$latest->change_type, $latest->effective_date->toDateString(), $latest->note]);

        $this->actingAs($hr)->get("/employees/{$e->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->has('employmentHistory', 2)
            ->where('employmentHistory.0.change_type', 'Transfer')   // newest effective date first
            ->where('employmentHistory.1.change_type', 'Promotion'));
    }

    public function test_past_changes_are_added_by_hr_and_removed_by_admin_only(): void
    {
        $e = $this->employee($this->company);
        $entry = ['effective_date' => '2024-03-01', 'change_type' => 'Promotion', 'note' => 'Designer to Senior Designer'];

        $this->actingAs($this->user('viewer'))->post("/employees/{$e->id}/history", $entry)->assertForbidden();
        $this->actingAs($this->user('hr'))->post("/employees/{$e->id}/history", ['change_type' => 'Bogus'] + $entry)->assertSessionHasErrors('change_type');
        $this->actingAs($this->user('hr'))->post("/employees/{$e->id}/history", $entry)->assertRedirect();
        $h = EmploymentHistory::sole();
        $this->assertSame($e->id, $h->employee_id);

        $other = $this->employee($this->company);
        $this->actingAs($this->user('hr'))->delete("/employees/{$e->id}/history/{$h->id}")->assertForbidden();
        $this->actingAs($this->user('admin'))->delete("/employees/{$other->id}/history/{$h->id}")->assertNotFound();
        $this->actingAs($this->user('admin'))->delete("/employees/{$e->id}/history/{$h->id}")->assertRedirect();
        $this->assertSame(0, EmploymentHistory::count());
    }
}
