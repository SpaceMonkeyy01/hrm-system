<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A warning or an appreciation on someone's record (FeedbackController). HR and admin see them on the profile; the
 * person sees their own on My page and acknowledges each. Issuing a letter whose template has a conduct_type adds one.
 */
class ConductEntry extends Model
{
    use LogsActivity;

    public const TYPES = ['appreciation' => 'Appreciation', 'verbal_warning' => 'Verbal warning', 'written_warning' => 'Written warning', 'final_warning' => 'Final warning'];

    /** What was said stays out of the activity log (viewers can read the profile timeline). */
    public const PRIVATE_FIELDS = ['title', 'details', 'employee_comment'];

    protected $fillable = ['employee_id', 'type', 'happened_on', 'title', 'details', 'issued_letter_id', 'acknowledged_at', 'employee_comment', 'created_by'];

    protected function casts(): array
    {
        return ['happened_on' => 'date:Y-m-d', 'acknowledged_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(IssuedLetter::class, 'issued_letter_id');
    }

    public function activityEmployeeId(): ?int
    {
        return $this->employee_id;
    }

    public function activityLabel(): string
    {
        return (self::TYPES[$this->type] ?? 'Conduct entry').' '.$this->happened_on?->toDateString();
    }

    /** Load "author" and "letter" first. */
    public function props(): array
    {
        return [
            'id' => $this->id, 'type' => $this->type, 'type_label' => self::TYPES[$this->type] ?? $this->type,
            'happened_on' => $this->happened_on->toDateString(), 'title' => $this->title, 'details' => $this->details,
            'letter' => $this->letter ? ['id' => $this->letter->id, 'reference' => $this->letter->reference] : null,
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(), 'employee_comment' => $this->employee_comment,
            'by' => $this->relationLoaded('author') ? $this->author?->name : null, 'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
