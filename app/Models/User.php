<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsActivity, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'is_active'];

    /** Same defaults as the migration, so a just-created model has them in memory too. */
    protected $attributes = ['role' => 'viewer', 'is_active' => true];

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
