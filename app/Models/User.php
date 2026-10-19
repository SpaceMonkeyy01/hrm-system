<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Notifications\SetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsActivity, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'is_active', 'employee_id'];

    /** Same defaults as the migration, so a just-created model has them in memory too. */
    protected $attributes = ['role' => 'viewer', 'is_active' => true, 'employee_id' => null];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Logins that see HR records: admin, hr, viewer. An "employee" login sees only its own record (My page). */
    public const STAFF_ROLES = ['admin', 'hr', 'viewer'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function canViewHr(): bool
    {
        return in_array($this->role, self::STAFF_ROLES, true);
    }

    /** Has an employee record of their own to look at (any role). */
    public function hasSelfService(): bool
    {
        return $this->employee_id !== null;
    }

    /** Where this login lands after signing in. */
    public function homeUrl(): string
    {
        return $this->canViewHr() ? route('dashboard') : route('me.show');
    }

    /** "Forgot your password?" email, in the app's own words. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new SetPasswordNotification($token));
    }

    /** admin and hr can change HR records; viewer is read-only. */
    public function canEdit(): bool
    {
        return in_array($this->role, ['admin', 'hr'], true);
    }

    /**
     * Every column that records which login did something. A login named in any of them (or in the activity log, other
     * than changes to its own login) has history and is switched off rather than deleted, so names stay on the record.
     * tests/Feature/UserManagementTest checks this covers every foreign key to users.
     */
    public const HISTORY_COLUMNS = [
        'activity_log' => ['user_id'], 'asset_assignments' => ['assigned_by', 'returned_by'], 'attendance_adjustments' => ['updated_by'],
        'attendance_imports' => ['uploaded_by'], 'attendance_requests' => ['requested_by', 'decided_by'], 'checklist_entries' => ['updated_by'],
        'employee_files' => ['uploaded_by'], 'employee_work_schedules' => ['created_by'], 'employment_history' => ['created_by'],
        'issued_letters' => ['issued_by'], 'leave_records' => ['created_by'], 'leave_requests' => ['requested_by', 'decided_by'], 'machine_syncs' => ['user_id'], 'offboardings' => ['created_by'],
        'office_networks' => ['created_by'], 'review_cycles' => ['created_by'], 'reviews' => ['reviewed_by'],
    ];

    /**
     * Which of these logins have done anything in the app.
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    public static function withHistory(array $ids): array
    {
        $found = [];
        foreach (self::HISTORY_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $left = array_values(array_diff($ids, array_keys($found)));
                if (! $left) {
                    return $found;
                }
                $q = DB::table($table)->whereIn($column, $left);
                if ($table === 'activity_log') {   // changes to their own login (e.g. setting a password) aren't history
                    $q->where(fn ($q) => $q->where('subject_type', '!=', 'User')->orWhereColumn('subject_id', '!=', $column));
                }
                foreach ($q->distinct()->pluck($column) as $id) {
                    $found[(int) $id] = true;
                }
            }
        }

        return $found;
    }

    public function activityLabel(): string
    {
        return "User {$this->email}";
    }
}
