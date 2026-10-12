<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row of a 90-day plan. Every change is logged with old and new values (manager notes are probation evidence). */
class ProbationPlanItem extends Model
{
    use LogsActivity;

    public const SECTIONS = ['goal', 'behaviour', 'attendance', 'deliverable'];

    protected $attributes = ['sort_order' => 0];

    protected $fillable = [
        'probation_plan_id', 'section', 'sort_order', 'title', 'detail', 'due_date',
        'status', 'evaluation', 'manager_notes', 'employee_notes',
    ];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d'];
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

    /** e.g. "90-day plan, behaviour: Teamwork & collaboration" */
    public function activityLabel(): string
    {
        return "90-day plan, {$this->section}".($this->title ? ": {$this->title}" : '');
    }
}
