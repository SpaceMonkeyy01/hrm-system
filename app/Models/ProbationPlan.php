<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProbationPlan extends Model
{
    use LogsActivity;

    protected $fillable = [
        'employee_id', 'plan_date',
        'final_performance', 'final_performance_notes', 'final_behaviour', 'final_behaviour_notes',
        'final_reliability', 'final_reliability_notes', 'overall_fit', 'overall_fit_notes',
        'manager_recommendation', 'hr_comments',
        'employee_signed_name', 'employee_signed_on', 'manager_signed_name', 'manager_signed_on',
        'hr_signed_name', 'hr_signed_on',
    ];

    protected function casts(): array
    {
        return [
            'plan_date' => 'date:Y-m-d',
            'employee_signed_on' => 'date:Y-m-d',
            'manager_signed_on' => 'date:Y-m-d',
            'hr_signed_on' => 'date:Y-m-d',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProbationPlanItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(ProbationCheckin::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activityEmployeeId(): ?int
    {
        return $this->employee_id;
    }

    public function activityLabel(): string
    {
        return '90-day plan';
    }
}
