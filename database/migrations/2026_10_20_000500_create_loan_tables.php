<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loans (2026-10-20). The person asks on My page (amount, months, reason); HR approves alone, setting the first
 * deduction month. Until payroll is built, HR records each month's deduction (loan_repayments); the loan closes
 * as Repaid once the deductions reach the amount. HR and admin only (plus the person's own).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('months');
            $table->decimal('installment', 12, 2)->nullable();    // monthly deduction, set when approved
            $table->date('first_month')->nullable();               // first deduction month (1st of the month), set when approved
            $table->text('reason');
            $table->string('status', 20)->default('pending');     // Loan::STATUSES
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('repaid_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('employee_id');
        });
        Schema::create('loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->date('month');                                  // 1st of the month it was deducted from
            $table->decimal('amount', 12, 2);
            $table->string('note', 300)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['loan_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_repayments');
        Schema::dropIfExists('loans');
    }
};
