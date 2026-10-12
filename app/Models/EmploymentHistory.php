<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One job change: transfer, role, employment type, manager or work mode, with the date it took effect.
 * Written by EmployeeController@update when a job field changes, or added by hand for past events.
 */
class EmploymentHistory extends Model
{
    use LogsActivity;

    protected $table = 'employment_history';

    protected $fillable = ['employee_id', 'effective_date', 'change_type', 'changes', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['effective_date' => 'date:Y-m-d', 'changes' => 'array'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activityEmployeeId(): ?int
    {
        return $this->employee_id;
    }

    public function activityLabel(): string
    {
        return "Employment history: {$this->change_type}";
    }
}
