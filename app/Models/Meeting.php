<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notes from a meeting with someone: a one-to-one, a probation check-in, a review discussion (FeedbackController).
 * HR and admin only, unless shared: then the person sees it on My page and can acknowledge it with a comment.
 */
class Meeting extends Model
{
    use LogsActivity;

    public const TYPES = ['one_to_one' => 'One-to-one', 'probation_checkin' => 'Probation check-in', 'review_discussion' => 'Review discussion', 'other' => 'Other'];

    /** What was discussed stays out of the activity log (viewers can read the profile timeline). */
    public const PRIVATE_FIELDS = ['notes', 'actions', 'attendees', 'employee_comment'];

    protected $fillable = ['employee_id', 'type', 'held_on', 'attendees', 'notes', 'actions', 'shared', 'acknowledged_at', 'employee_comment', 'created_by'];

    protected $attributes = ['shared' => false];

    protected function casts(): array
    {
        return ['held_on' => 'date:Y-m-d', 'shared' => 'boolean', 'acknowledged_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activityEmployeeId(): ?int
    {
        return $this->employee_id;
    }

    public function activityLabel(): string
    {
        return (self::TYPES[$this->type] ?? 'Meeting').' '.$this->held_on?->toDateString();
    }

    /** Load "author" first. */
    public function props(): array
    {
        return [
            'id' => $this->id, 'type' => $this->type, 'type_label' => self::TYPES[$this->type] ?? $this->type,
            'held_on' => $this->held_on->toDateString(), 'attendees' => $this->attendees, 'notes' => $this->notes, 'actions' => $this->actions,
            'shared' => $this->shared, 'acknowledged_at' => $this->acknowledged_at?->toIso8601String(), 'employee_comment' => $this->employee_comment,
            'by' => $this->relationLoaded('author') ? $this->author?->name : null, 'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
