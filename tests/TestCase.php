<?php

namespace Tests;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();   // tests don't need built JS/CSS
    }

    protected function user(string $role = 'hr', array $attrs = []): User
    {
        return User::factory()->create(['role' => $role] + $attrs);
    }

    /** Checklist items + one company with one department. */
    protected function baseData(): array
    {
        (new ChecklistItemSeeder)->run();
        $company = Company::create(['name' => 'BCC', 'code' => 'BCC', 'employee_code_prefix' => 'BCC-', 'employee_code_digits' => 3]);
        $dept = Department::create(['company_id' => $company->id, 'name' => 'HR']);

        return [$company, $dept];
    }

    protected function employee(Company $company, array $attrs = []): Employee
    {
        return Employee::create($attrs + [
            'company_id' => $company->id,
            'employee_code' => $company->nextEmployeeCode(),
            'full_name' => 'Test Person '.uniqid(),
            'status' => 'active',
        ]);
    }
}
