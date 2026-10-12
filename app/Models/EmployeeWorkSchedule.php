<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** From starts_on, this employee works to this schedule (until a later row takes over). */
class EmployeeWorkSchedule extends Model
{
    use LogsActivity;

    protected $fillable = ['employee_id', 'work_schedule_id', 'starts_on', 'created_by'];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }

    public function activityEmployeeId(): ?int
    {
        return $this->employee_id;
    }

    public function activityLabel(): string
    {
        return 'Work schedule';
    }
}
