<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use LogsActivity;

    protected $attributes = ['employee_code_digits' => 3];

    protected $fillable = ['name', 'code', 'employee_code_prefix', 'employee_code_digits', 'hr_email'];

    /** [address, name] that emails to this person come from: their company's HR address, else null (the app default). */
    public static function mailFromFor(User $user): ?array
    {
        $company = $user->employee?->company;

        return $company?->hr_email ? [$company->hr_email, "{$company->name} HR"] : null;
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class)->orderBy('name');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Next free employee code, e.g. BCC-197.
     * Looks at the highest existing numeric suffix (including soft-deleted rows) so codes are never reused.
     */
    public function nextEmployeeCode(): string
    {
        $prefix = $this->employee_code_prefix;
        $max = Employee::withTrashed()
            ->where('employee_code', 'like', $prefix.'%')
            ->pluck('employee_code')
            ->map(fn ($code) => (int) substr($code, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($max + 1), $this->employee_code_digits, '0', STR_PAD_LEFT);
    }

    /** A company isn't one person's record, so its changes don't go on an employee timeline. */
    public function activityEmployeeId(): ?int
    {
        return null;
    }

    public function activityLabel(): string
    {
        return "Company {$this->name}";
    }
}
