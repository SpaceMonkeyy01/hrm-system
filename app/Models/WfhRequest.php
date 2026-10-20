<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone asks on My page to work from home on some days; HR approves (WfhRequestController). Approved days show
 * "WFH approved" on attendance; remote time on other days is flagged, never blocked (AttendanceBoard).
 */
class WfhRequest extends Model
{
    use LogsActivity;

    public const STATUSES = ['pending' => 'Waiting for HR', 'approved' => 'Approved', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled'];

    /** Longest request, in calendar days: anything longer is a work mode change, not a request. */
    public const MAX_DAYS = 31;

    protected $fillable = ['employee_id', 'start_date', 'end_date', 'reason', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'decided_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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
        return 'Work from home request '.$this->start_date?->toDateString();
    }

    /** Shape both pages use (My page and HR's queue). Load "decider" first. */
    public function props(): array
    {
        return [
            'id' => $this->id, 'start_date' => $this->start_date->toDateString(), 'end_date' => $this->end_date->toDateString(),
            'days' => (int) $this->start_date->diffInDays($this->end_date) + 1,
            'reason' => $this->reason, 'status' => $this->status, 'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'decision_note' => $this->decision_note, 'decided_by' => $this->relationLoaded('decider') ? $this->decider?->name : null,
            'decided_at' => $this->decided_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
