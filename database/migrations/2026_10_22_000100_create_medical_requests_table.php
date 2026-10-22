<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medical requests (2026-10-22). The person asks on My page: claim back a bill they paid (bills attached) or an advance
 * for treatment. HR approves the amount asked or less, then marks it paid (date and how) until payroll is in the app.
 * No yearly limit: HR sees what the person had this year. HR and admin only (plus the person's own).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);                              // MedicalRequest::KINDS
            $table->string('patient', 20)->default('self');         // MedicalRequest::PATIENTS
            $table->date('treatment_date')->nullable();
            $table->decimal('amount', 12, 2);                        // asked for
            $table->decimal('approved_amount', 12, 2)->nullable();   // set when approved; at most the amount asked
            $table->text('reason');
            $table->string('status', 20)->default('pending');        // MedicalRequest::STATUSES
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->date('paid_on')->nullable();
            $table->string('paid_note', 300)->nullable();            // how: cash, bank transfer, with the salary
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_requests');
    }
};
