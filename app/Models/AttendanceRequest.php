<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff member asks HR to fix one day's times (forgot to punch, machine down). Approving it writes the times
 * into an AttendanceAdjustment, so the attendance rules never read requests directly.
 */
class AttendanceRequest extends Model
{
    use LogsActivity;

    public const STATUSES = ['pending' => 'Waiting for HR', 'approved' => 'Approved', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled'];

    protected $fillable = ['employee_id', 'date', 'check_in', 'check_out', 'reason', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'decided_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
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
        return 'Attendance request '.$this->date?->toDateString();
    }

    /** Shape both pages use (My page and the HR queue). Load "decider" first to get its name. */
    public function props(): array
    {
        return [
            'id' => $this->id, 'date' => $this->date->toDateString(), 'check_in' => $this->check_in, 'check_out' => $this->check_out,
            'reason' => $this->reason, 'status' => $this->status, 'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'decision_note' => $this->decision_note, 'decided_by' => $this->relationLoaded('decider') ? $this->decider?->name : null,
            'decided_at' => $this->decided_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
