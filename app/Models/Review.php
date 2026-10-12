<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Services\ReviewRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's review in one cycle. Ratings are 1-5 per criterion (config hrm.reviews.scale). */
class Review extends Model
{
    use LogsActivity;

    protected $fillable = ['review_cycle_id', 'employee_id', 'status', 'self_ratings', 'self_comments', 'self_summary', 'self_submitted_at',
        'manager_ratings', 'manager_comments', 'manager_summary', 'goals', 'recommendation', 'reviewed_by', 'manager_submitted_at',
        'acknowledged_at', 'employee_comment'];

    protected $attributes = ['status' => 'self_review'];

    protected function casts(): array
    {
        return [
            'self_ratings' => 'array', 'self_comments' => 'array', 'manager_ratings' => 'array', 'manager_comments' => 'array', 'goals' => 'array',
            'self_submitted_at' => 'datetime', 'manager_submitted_at' => 'datetime', 'acknowledged_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'review_cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function activityEmployeeId(): ?int
    {
        return $this->employee_id;
    }

    public function activityLabel(): string
    {
        return 'Performance review';
    }

    /** Average of HR's ratings, one decimal; null until there are any. */
    public function overall(): ?float
    {
        return ReviewRules::average($this->manager_ratings ?? []);
    }
}
