<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A loan someone asked for on My page (LoanController). HR approves it alone; until payroll exists HR records each
 * month's deduction, and the loan closes as Repaid when they add up to the amount. Amounts: HR, admin and the person.
 */
class Loan extends Model
{
    use LogsActivity;

    public const STATUSES = ['pending' => 'Waiting for HR', 'active' => 'Being repaid', 'repaid' => 'Repaid', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled'];

    /** Viewers can read the profile timeline: amounts and reasons stay out of it. */
    public const PRIVATE_FIELDS = ['amount', 'installment', 'reason'];

    protected $fillable = ['employee_id', 'amount', 'months', 'installment', 'first_month', 'reason', 'status', 'requested_by', 'decided_by',
        'decided_at', 'decision_note', 'repaid_at'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'installment' => 'float', 'months' => 'integer', 'first_month' => 'date:Y-m-d',
            'decided_at' => 'datetime', 'repaid_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class)->orderBy('month');
    }

    public function activityEmployeeId(): ?int
    {
        return $this->employee_id;
    }

    public function activityLabel(): string
    {
        return 'Loan request '.$this->created_at?->toDateString();
    }

    /** The equal monthly deduction, rounded up to the rupee so the months cover the amount. */
    public static function installmentFor(float $amount, int $months): float
    {
        return (float) ceil($amount / max(1, $months));
    }

    public function repaid(): float
    {
        return round((float) ($this->relationLoaded('repayments') ? $this->repayments->sum('amount') : $this->repayments()->sum('amount')), 2);
    }

    /** Shape both pages use. Load "decider" and "repayments" first. */
    public function props(): array
    {
        $repaid = $this->repaid();

        return [
            'id' => $this->id, 'amount' => $this->amount, 'months' => $this->months, 'installment' => $this->installment,
            'first_month' => $this->first_month?->format('Y-m'), 'reason' => $this->reason,
            'status' => $this->status, 'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'repaid' => $repaid, 'left' => round(max(0, $this->amount - $repaid), 2),
            'repayments' => $this->repayments->map(fn (LoanRepayment $r) => ['id' => $r->id, 'month' => $r->month->format('Y-m'), 'amount' => $r->amount, 'note' => $r->note])->values(),
            'decision_note' => $this->decision_note, 'decided_by' => $this->relationLoaded('decider') ? $this->decider?->name : null,
            'decided_at' => $this->decided_at?->toIso8601String(), 'repaid_at' => $this->repaid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
