<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'is_paid', 'is_active', 'sort_order'];

    protected $attributes = ['is_paid' => true, 'is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_paid' => 'boolean', 'is_active' => 'boolean'];
    }

    public function records(): HasMany
    {
        return $this->hasMany(LeaveRecord::class);
    }

    public function activityLabel(): string
    {
        return "Leave type {$this->name}";
    }
}
