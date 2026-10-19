<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone asks for leave on My page, with the signed form (manager's approval on it) attached. HR approves it
 * (LeaveRequestController), which records the leave; the attendance and balance rules only ever read leave_records.
 */
class LeaveRequest extends Model
{
    use LogsActivity;

    public const STATUSES = ['pending' => 'Waiting for HR', 'approved' => 'Approved', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled'];

    protected $fillable = ['employee_id', 'leave_type_id', 'start_date', 'end_date', 'part', 'days', 'reason', 'status',
        'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size', 'requested_by', 'decided_by', 'decided_at',
        'decision_note', 'leave_record_id'];

    protected $attributes = ['status' => 'pending', 'part' => 'full'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'decided_at' => 'datetime', 'days' => 'float', 'attachment_size' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function activityEmployeeId(): ?int
    {
        return $this->employee_id;
    }

    public function activityLabel(): string
    {
        return 'Leave request '.$this->start_date?->toDateString();
    }

    /** Shape both pages use (My page and HR's queue). Load "type" and "decider" first. */
    public function props(): array
    {
        return [
            'id' => $this->id, 'type' => $this->type?->name, 'start_date' => $this->start_date->toDateString(), 'end_date' => $this->end_date->toDateString(),
            'part' => $this->part, 'days' => $this->days, 'reason' => $this->reason, 'status' => $this->status,
            'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'attachment' => $this->attachment_path ? ['name' => $this->attachment_name, 'size' => $this->attachment_size] : null,
            'decision_note' => $this->decision_note, 'decided_by' => $this->relationLoaded('decider') ? $this->decider?->name : null,
            'decided_at' => $this->decided_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
