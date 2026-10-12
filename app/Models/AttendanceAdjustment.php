<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** HR's manual check-in / check-out for one day, e.g. a forgotten punch-out. Overrides the punches it sets. */
class AttendanceAdjustment extends Model
{
    use LogsActivity;

    protected $fillable = ['employee_id', 'date', 'check_in', 'check_out', 'note', 'updated_by'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
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
