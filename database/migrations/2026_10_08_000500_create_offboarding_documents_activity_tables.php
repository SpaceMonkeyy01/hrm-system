<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * offboardings         - Offboarding_Tracker (type, reason, dates, exit interview); clearance items use checklist_entries
 * controlled_documents - Controlled_Documents register (+ the Templates list)
 * activity_log         - who changed what and when, for every HR record
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offboardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);                         // Resignation / Termination / Retirement / Contract End / Other
            $table->text('reason')->nullable();
            $table->date('notice_date')->nullable();
            $table->date('last_working_day')->nullable();
            $table->string('status', 20)->default('in_progress'); // in_progress | completed | cancelled
            $table->text('exit_interview_notes')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'last_working_day']);
        });

        Schema::create('controlled_documents', function (Blueprint $table) {
            $table->id();
            $table->string('doc_number', 30)->unique();          // BCC-0001
            $table->string('doc_type', 30);                      // Policy / Template / Form / Letter / SOP
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('Active');     // Draft / Active / Obsolete
            $table->date('issued_on')->nullable();
            $table->date('obsolete_on')->nullable();
            $table->text('link_url')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 60);                  // model class basename, e.g. ChecklistEntry
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('event', 30);                         // created | updated | deleted | imported
            $table->string('description')->nullable();
            $table->json('changes')->nullable();                 // {"field": [old, new]}
            $table->timestamp('created_at')->useCurrent();
            $table->index(['employee_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('controlled_documents');
        Schema::dropIfExists('offboardings');
    }
};
