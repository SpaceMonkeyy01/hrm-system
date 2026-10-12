<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Support\WeekDays;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An office timing profile: fixed hours with grace periods, or flexible hours.
 * Rules that use it: app/Services/AttendanceRules.php.
 */
class WorkSchedule extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'type', 'start_time', 'end_time', 'late_grace_minutes', 'early_grace_minutes',
        'full_day_minutes', 'half_day_minutes', 'overtime_after_minutes', 'working_days', 'missing_checkout', 'is_default', 'notes'];

    protected $attributes = ['type' => 'fixed', 'late_grace_minutes' => 0, 'early_grace_minutes' => 0,
        'full_day_minutes' => 480, 'half_day_minutes' => 240, 'missing_checkout' => 'flag', 'is_default' => false];

    protected function casts(): array
    {
        return ['working_days' => 'array', 'is_default' => 'boolean', 'late_grace_minutes' => 'integer',
            'early_grace_minutes' => 'integer', 'full_day_minutes' => 'integer', 'half_day_minutes' => 'integer',
            'overtime_after_minutes' => 'integer'];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeWorkSchedule::class);
    }

    public function isFlexible(): bool
    {
        return $this->type === 'flexible';
    }

    /** "Mon-Fri, 09:00-17:00, 10 min grace" */
    public function summary(): string
    {
        $days = WeekDays::label($this->working_days ?? []);
        if ($this->isFlexible()) {
            return "$days, flexible, ".round($this->full_day_minutes / 60, 1).' h a day';
        }

        $night = $this->end_time <= $this->start_time ? ' (ends next day)' : '';

        return "$days, {$this->start_time}-{$this->end_time}$night".($this->late_grace_minutes ? ", {$this->late_grace_minutes} min grace" : ', no grace');
    }

    public function activityLabel(): string
    {
        return "Work schedule {$this->name}";
    }
}
