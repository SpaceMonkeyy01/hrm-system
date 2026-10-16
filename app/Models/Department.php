<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use LogsActivity;

    protected $fillable = ['company_id', 'name', 'head_employee_id'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The department head (optional): top of the department on the org chart, default manager for new joiners. */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_employee_id');
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class)->orderBy('name');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function activityLabel(): string
    {
        return "Department {$this->name}";
    }
}
