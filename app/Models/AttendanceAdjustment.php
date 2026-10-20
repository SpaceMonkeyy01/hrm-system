<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR's version of one day. Either sessions (list of {in, out, place}: replaces the punches' sessions, Edit day) or
 * a check-in / check-out that moves the first start and last end (approved "ask HR" requests, older edits).
 */
class AttendanceAdjustment extends Model
{
    use LogsActivity;

    protected $fillable = ['employee_id', 'date', 'check_in', 'check_out', 'sessions', 'note', 'updated_by'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'sessions' => 'array'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function activityEmployeeId(): ?int
    {
        return $this->employee_id;
    }

    public function activityLabel(): string
    {
        return 'Attendance '.$this->date?->toDateString();
    }
}
