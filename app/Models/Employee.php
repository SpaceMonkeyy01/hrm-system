<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'company_id', 'employee_code', 'full_name', 'first_name', 'last_name', 'cnic', 'email', 'phone',
        'father_name', 'date_of_birth', 'gender', 'marital_status', 'blood_group', 'cnic_expiry',
        'personal_email', 'alternate_phone', 'current_address', 'permanent_address', 'city',
        'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone',
        'bank_name', 'bank_account_title', 'bank_account_number', 'work_mode', 'work_location', 'confirmation_date',
        'department_id', 'team_id', 'role_title', 'manager_id', 'employment_type', 'start_date',
        'status', 'exit_date', 'hr_portal_id', 'attendance_machine', 'attendance_machine_id',
        'drive_folder_url', 'notes', 'needs_review', 'review_note', 'source_ref', 'photo_path',
        'secondary_department_id', 'secondary_manager_id', 'attendance_device_id', 'legacy',
    ];

    /**
     * Personal details only HR and admin logins see. Viewers get null, and the activity log records
     * that they changed but not the values (LogsActivity reads this list).
     */
    public const PRIVATE_FIELDS = [
        'cnic', 'cnic_expiry', 'father_name', 'date_of_birth', 'marital_status', 'blood_group',
        'personal_email', 'alternate_phone', 'current_address', 'permanent_address',
        'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone',
        'bank_name', 'bank_account_title', 'bank_account_number',
    ];

    /** Job fields: changing one on Edit writes an employment_history row. Field => label HR sees. */
    public const JOB_FIELDS = [
        'company_id' => 'Company', 'department_id' => 'Department', 'team_id' => 'Team', 'role_title' => 'Role',
        'employment_type' => 'Employment type', 'manager_id' => 'Manager', 'work_mode' => 'Work mode',
    ];

    /** URL of the profile photo (changes with every new photo, so browsers can cache it), or null. */
    public function photoUrl(): ?string
    {
        return $this->photo_path ? "/employees/{$this->id}/photo?v=".substr(md5($this->photo_path), 0, 8) : null;
    }

    /** Photo URL from a row that selected only id and photo_path (lists). */
    public static function photoUrlFor(int $id, ?string $path): ?string
    {
        return $path ? "/employees/{$id}/photo?v=".substr(md5($path), 0, 8) : null;
    }

    /** Details a complete record has (label => filled?), for the profile's completeness bar. HR and admin only. */
    public function completeness(): array
    {
        $checks = [
            'Work email' => $this->email, 'Phone' => $this->phone, 'CNIC' => $this->cnic, 'CNIC expiry' => $this->cnic_expiry,
            'Date of birth' => $this->date_of_birth, 'Start date' => $this->start_date, 'Role' => $this->role_title,
            'Manager' => $this->manager_id, 'Employment type' => $this->employment_type,
            'Emergency contact' => $this->emergency_contact_name && $this->emergency_contact_phone,
            'Bank account' => $this->bank_account_number,
            'Attendance ID' => $this->attendance_machine_id || $this->hr_portal_id,
        ];
        $missing = array_keys(array_filter($checks, fn ($v) => ! $v));

        return ['done' => count($checks) - count($missing), 'total' => count($checks), 'missing' => $missing];
    }

    /** Same defaults as the migration, so a just-created model has them in memory too. */
    protected $attributes = ['status' => 'active', 'needs_review' => false];

    /** This app's permanent ID, from the row id: never typed, never reused, unchanged when the code changes. */
    public static function systemIdFor(int $id): string
    {
        return 'HRM-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    protected static function booted(): void
    {
        static::created(function (Employee $e) {
            if (! $e->system_id) {
                $e->system_id = self::systemIdFor($e->id);
                $e->saveQuietly();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'exit_date' => 'date:Y-m-d',
            'date_of_birth' => 'date:Y-m-d',
            'cnic_expiry' => 'date:Y-m-d',
            'confirmation_date' => 'date:Y-m-d',
            'needs_review' => 'boolean',
            'legacy' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function secondaryManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'secondary_manager_id');
    }

    public function secondaryDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'secondary_department_id');
    }

    public function attendanceDevice(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'employee_category')->orderBy('name');
    }

    public function onboarding(): HasOne
    {
        return $this->hasOne(Onboarding::class);
    }

    public function checklistEntries(): HasMany
    {
        return $this->hasMany(ChecklistEntry::class);
    }

    public function probationPlan(): HasOne
    {
        return $this->hasOne(ProbationPlan::class);
    }

    public function offboardings(): HasMany
    {
        return $this->hasMany(Offboarding::class)->latest();
    }

    public function files(): HasMany
    {
        return $this->hasMany(EmployeeFile::class)->latest('id');
    }

    public function assetAssignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class)->orderByDesc('assigned_on')->orderByDesc('id');
    }

    public function leaveRecords(): HasMany
    {
        return $this->hasMany(LeaveRecord::class);
    }

    public function employmentHistory(): HasMany
    {
        return $this->hasMany(EmploymentHistory::class)->orderByDesc('effective_date')->orderByDesc('id');
    }

    public function activity(): HasMany
    {
        return $this->hasMany(ActivityLog::class)->latest('id');
    }

    /** Name, code, system ID, HR.my ID or CNIC contains $term. */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (! $term) {
            return $q;
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $q->where(fn ($w) => $w->whereLike('full_name', $like)
            ->orWhereLike('employee_code', $like)
            ->orWhereLike('system_id', $like)
            ->orWhereLike('hr_portal_id', $like)
            ->orWhereLike('cnic', $like));
    }

    public function activityEmployeeId(): ?int
    {
        return $this->id;
    }

    public function activityLabel(): string
    {
        return "Employee {$this->employee_code} {$this->full_name}";
    }
}
