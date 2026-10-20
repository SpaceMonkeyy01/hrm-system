<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One month's deduction against a loan, recorded by HR until payroll does it (LoanController). */
class LoanRepayment extends Model
{
    use LogsActivity;

    public const PRIVATE_FIELDS = ['amount', 'note'];

    protected $fillable = ['loan_id', 'month', 'amount', 'note', 'recorded_by'];

    protected function casts(): array
    {
        return ['month' => 'date:Y-m-d', 'amount' => 'float'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function activityEmployeeId(): ?int
    {
        return $this->loan?->employee_id;
    }

    public function activityLabel(): string
    {
        return 'Loan deduction '.$this->month?->format('M Y');
    }
}
