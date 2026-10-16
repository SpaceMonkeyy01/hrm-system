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

    protected $fillable = ['name', 'type', 'start_time', 'end_time', 'late_grace_minutes', 'early_grace_minutes', 'absent_after',
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

    /**
     * Not checked in by this time = Absent (fixed schedules only), as minutes from midnight on the day's own clock
     * (a night shift's cut-off after midnight is 24+ hours). Empty = the middle of the day: 9-5 gives 13:00.
     */
    public function absentAfterMinutes(): ?int
    {
        if ($this->isFlexible() || ! $this->start_time || ! $this->end_time) {
            return null;
        }
        $start = self::toMinutes($this->start_time);
        $end = self::toMinutes($this->end_time);
        $end += $end <= $start ? 1440 : 0;
        if (! $this->absent_after) {
            return $start + intdiv($end - $start, 2);
        }
        $cut = self::toMinutes($this->absent_after);

        return $cut <= $start ? $cut + 1440 : $cut;
    }

    /** "13:00" (or "01:00" for a night shift's next morning) */
    public function absentAfterLabel(): ?string
    {
        $m = $this->absentAfterMinutes();

        return $m === null ? null : sprintf('%02d:%02d', intdiv($m, 60) % 24, $m % 60);
    }

    private static function toMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
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
