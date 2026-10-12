<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProbationCheckin extends Model
{
    use LogsActivity;

    public const KINDS = ['day30' => '30-day check-in', 'day60' => '60-day check-in', 'day90' => '90-day check-in', 'extra' => 'Additional check-in'];

    protected $attributes = ['sort_order' => 0];

    protected $fillable = [
        'probation_plan_id', 'kind', 'sort_order', 'held_on', 'manager_summary', 'employee_summary',
        'action_items', 'next_follow_up', 'initials',
    ];

    protected function casts(): array
    {
        return ['held_on' => 'date:Y-m-d', 'next_follow_up' => 'date:Y-m-d'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProbationPlan::class, 'probation_plan_id');
    }

    /** Uses the loaded plan if there is one, otherwise looks up by probation_plan_id (no lazy loading). */
    public function activityEmployeeId(): ?int
    {
        if ($this->relationLoaded('plan')) {
            return $this->plan?->employee_id;
        }

        return ProbationPlan::whereKey($this->probation_plan_id)->value('employee_id');
    }

    public function activityLabel(): string
    {
        return '90-day plan: '.(self::KINDS[$this->kind] ?? $this->kind);
    }
}
