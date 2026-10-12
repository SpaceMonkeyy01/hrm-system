<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Approved leave for one person over a date range (or half of one day). */
class LeaveRecord extends Model
{
    use LogsActivity;

    public const PARTS = ['full' => 'Full day', 'first_half' => 'First half', 'second_half' => 'Second half'];

    protected $fillable = ['employee_id', 'leave_type_id', 'start_date', 'end_date', 'part', 'days', 'note', 'source', 'created_by'];

    protected $attributes = ['part' => 'full', 'days' => 0, 'source' => 'manual'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'days' => 'float'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
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
        return 'Leave '.$this->start_date?->toDateString().($this->end_date && ! $this->end_date->equalTo($this->start_date) ? ' to '.$this->end_date->toDateString() : '');
    }
}
