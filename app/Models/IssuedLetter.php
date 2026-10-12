<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A letter as it was issued: the exact text, so a reprint matches the original. */
class IssuedLetter extends Model
{
    use LogsActivity;

    protected $fillable = ['employee_id', 'letter_template_id', 'reference', 'subject', 'body', 'issued_on', 'issued_by'];

    protected function casts(): array
    {
        return ['issued_on' => 'date:Y-m-d'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LetterTemplate::class, 'letter_template_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function activityEmployeeId(): ?int
    {
        return $this->employee_id;
    }

    public function activityLabel(): string
    {
        return "Letter {$this->reference}";
    }
}
