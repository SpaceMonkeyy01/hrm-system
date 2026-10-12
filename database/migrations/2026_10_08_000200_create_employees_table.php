<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employee master. Every other HR record points at employees.id.
 * In the spreadsheet, tabs were joined on the employee *name*, which broke on every typo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_code', 30)->unique();        // BCC-001, Emerge-001, UNASSIGNED-001
            $table->string('full_name');
            $table->string('first_name')->nullable();              // HR_Database sheet
            $table->string('last_name')->nullable();               // HR_Database sheet
            $table->string('cnic', 20)->nullable();                // HR_Database sheet (national ID)
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role_title')->nullable();              // tracker Role_Title
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('employment_type', 30)->nullable();     // Full Time / Intern / Contract
            $table->date('start_date')->nullable();
            $table->string('status', 20)->default('active');       // active / offboarding / exited
            $table->date('exit_date')->nullable();
            $table->string('hr_portal_id', 60)->nullable();        // checklist "HR Portal ID"
            $table->string('attendance_machine', 60)->nullable();  // checklist "Attendance machine"
            $table->string('attendance_machine_id', 60)->nullable();
            $table->text('drive_folder_url')->nullable();          // checklist "Drive Folder Link"
            $table->text('notes')->nullable();
            // Set by the importer when a name could not be matched cleanly; HR clears it after checking.
            $table->boolean('needs_review')->default(false);
            $table->string('review_note')->nullable();
            $table->string('source_ref')->nullable();              // e.g. "BCC list row 12"
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'company_id']);
            $table->index('full_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
