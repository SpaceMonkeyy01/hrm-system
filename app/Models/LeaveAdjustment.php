<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Leave counted without dates (opening balances from HR's sheet). With a type: days taken of that type.
 * Without a type: a deduction (absences, late / early sign-offs), taken from Casual then Annual. Rules: LeaveBalances.
 */
class LeaveAdjustment extends Model
{
    use LogsActivity;

    protected $fillable = ['employee_id', 'leave_type_id', 'year', 'days', 'source', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['days' => 'float', 'year' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function activityEmployeeId(): ?int
    {
        return $this->employee_id;
    }

    public function activityLabel(): string
    {
        return 'Leave adjustment '.$this->year;
    }
}
