<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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

    /** admin and hr can change HR records; viewer is read-only. */
    public function canEdit(): bool
    {
        return in_array($this->role, ['admin', 'hr'], true);
    }

    public function activityLabel(): string
    {
        return "User {$this->email}";
    }
}
