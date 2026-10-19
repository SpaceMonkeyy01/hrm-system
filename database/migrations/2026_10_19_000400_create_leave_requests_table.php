<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave requests from My page (2026-10-19). The person attaches the signed leave form with their manager's approval;
 * HR reviews and approves, which writes a leave_records row (leave_record_id). Over the balance is allowed, with a warning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('part', 20)->default('full');          // LeaveRecord::PARTS
            $table->decimal('days', 5, 1)->default(0);            // working days when asked (LeaveController::workingDays)
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');     // LeaveRequest::STATUSES
            // The signed form (private disk, config hrm.uploads); null when uploads are switched off
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('leave_record_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'start_date']);
            $table->index(['employee_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
