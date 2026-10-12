<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offboarding extends Model
{
    use LogsActivity;

    public const STATUSES = ['in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

    protected $fillable = [
        'employee_id', 'type', 'reason', 'notice_date', 'last_working_day', 'status',
        'exit_interview_notes', 'notes', 'completed_at', 'created_by',
    ];

    protected $attributes = ['status' => 'in_progress'];

    protected function casts(): array
    {
        return [
            'notice_date' => 'date:Y-m-d',
            'last_working_day' => 'date:Y-m-d',
            'completed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function activityEmployeeId(): ?int
    {
        return $this->employee_id;
    }

    public function activityLabel(): string
    {
        return "Offboarding ({$this->type})";
    }
}
