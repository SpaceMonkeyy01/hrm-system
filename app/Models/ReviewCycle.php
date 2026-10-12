<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One round of performance reviews, e.g. "2026 mid-year". Status rules: ReviewRules. */
class ReviewCycle extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'period_start', 'period_end', 'self_due', 'manager_due', 'status', 'criteria', 'notes', 'created_by'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'self_due' => 'date:Y-m-d', 'manager_due' => 'date:Y-m-d', 'criteria' => 'array'];
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function activityLabel(): string
    {
        return "Review cycle {$this->name}";
    }
}
