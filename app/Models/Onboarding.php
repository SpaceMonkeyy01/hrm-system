<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Services\HrRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Onboarding extends Model
{
    use LogsActivity;

    protected $fillable = ['employee_id', 'cohort', 'probation_end_date', 'probation_result', 'plan_url', 'notes', 'closed_at', 'closed_note'];

    protected function casts(): array
    {
        return ['probation_end_date' => 'date:Y-m-d', 'closed_at' => 'datetime'];
    }

    /**
     * Put an employee into onboarding: the one place a tracker row is created (ARCH §4: a new hire is
     * Pending with end = start + 90 days). Returns the existing row if there already is one.
     */
    public static function startFor(Employee $employee): self
    {
        return static::firstOrCreate(['employee_id' => $employee->id], [
            'probation_end_date' => HrRules::probationEnd($employee->start_date),
            'probation_result' => 'Pending',
        ]);
    }

    /** Still being tracked (not closed out). Closed records stay on file but leave the tracker, counts and reminders. */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('closed_at');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function activityEmployeeId(): ?int
    {
        return $this->employee_id;
    }

    public function activityLabel(): string
    {
        return 'Onboarding';
    }
}
