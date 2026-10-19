<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave counted outside leave_records (2026-10-19), e.g. HR's own sheet before the app ("BCC Leave balance", Jul-Sep 2026):
 *   leave_type_id set   days taken of that type (comes off its balance like a leave record)
 *   leave_type_id null  a deduction: absences and late / early sign-offs, taken from Casual, then Annual (LeaveBalances)
 * source "sheet" rows are replaced when the sheet is uploaded again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->nullable()->constrained();
            $table->unsignedSmallInteger('year');
            $table->decimal('days', 6, 2);
            $table->string('source', 20)->default('manual');   // sheet | manual
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_adjustments');
    }
};
