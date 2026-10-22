<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A medical request from My page (MedicalRequestController): a bill to claim back or an advance for treatment. HR
 * approves the amount asked or less, then marks it paid. Amounts, reasons and files: HR, admin and the person.
 */
class MedicalRequest extends Model
{
    use LogsActivity;

    public const KINDS = ['reimbursement' => 'Claim back a bill', 'advance' => 'Advance for treatment'];

    public const PATIENTS = ['self' => 'Myself', 'spouse' => 'Spouse', 'child' => 'Child', 'parent' => 'Parent', 'other' => 'Other family'];

    public const STATUSES = ['pending' => 'Waiting for HR', 'approved' => 'Approved, to be paid', 'paid' => 'Paid', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled'];

    /** Viewers can read the profile timeline: amounts, reasons and notes stay out of it. */
    public const PRIVATE_FIELDS = ['amount', 'approved_amount', 'reason', 'decision_note', 'paid_note'];

    protected $fillable = ['employee_id', 'kind', 'patient', 'treatment_date', 'amount', 'approved_amount', 'reason', 'status',
        'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size', 'requested_by', 'decided_by', 'decided_at', 'decision_note',
        'paid_on', 'paid_note', 'paid_by'];

    protected $attributes = ['status' => 'pending', 'patient' => 'self'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'approved_amount' => 'float', 'treatment_date' => 'date:Y-m-d', 'paid_on' => 'date:Y-m-d',
            'decided_at' => 'datetime', 'attachment_size' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function activityEmployeeId(): ?int
    {
        return $this->employee_id;
    }

    public function activityLabel(): string
    {
        return 'Medical request '.$this->created_at?->toDateString();
    }

    /** Approved or paid this calendar year: what HR has agreed to give the person (there is no yearly limit). */
    public static function approvedInYear(array $employeeIds, int $year): array
    {
        return self::query()->whereIn('employee_id', $employeeIds)->whereIn('status', ['approved', 'paid'])
            ->whereYear('decided_at', $year)->groupBy('employee_id')->selectRaw('employee_id, sum(approved_amount) as total')
            ->pluck('total', 'employee_id')->map(fn ($t) => round((float) $t, 2))->all();
    }

    /** Shape both pages use. Load "decider" and "payer" first. */
    public function props(): array
    {
        return [
            'id' => $this->id, 'kind' => $this->kind, 'kind_label' => self::KINDS[$this->kind] ?? $this->kind,
            'patient' => $this->patient, 'patient_label' => self::PATIENTS[$this->patient] ?? $this->patient,
            'treatment_date' => $this->treatment_date?->toDateString(), 'amount' => $this->amount, 'approved_amount' => $this->approved_amount,
            'reason' => $this->reason, 'status' => $this->status, 'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'attachment' => $this->attachment_path ? ['name' => $this->attachment_name, 'size' => $this->attachment_size] : null,
            'decision_note' => $this->decision_note, 'decided_by' => $this->relationLoaded('decider') ? $this->decider?->name : null,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'paid_on' => $this->paid_on?->toDateString(), 'paid_note' => $this->paid_note, 'paid_by' => $this->relationLoaded('payer') ? $this->payer?->name : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
