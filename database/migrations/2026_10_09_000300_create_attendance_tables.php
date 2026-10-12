<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_schedules           - office timing profiles HR assigns (9-5 strict, 12-6, flexible ...)
 * employee_work_schedules  - which schedule applies to whom from which date (past days keep their schedule)
 * attendance_imports       - each uploaded machine / HR.my file
 * attendance_punches       - raw punches, linked to people by device user id (employees.attendance_machine_id)
 * attendance_adjustments   - HR's manual check-in / check-out for a day (forgotten punch, machine down)
 *
 * Day status (Present, Late, Half day, Absent ...) is never stored: AttendanceRules works it out
 * from punches + adjustments + schedule, like the onboarding statuses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('type', 10)->default('fixed');            // fixed | flexible
            $table->string('start_time', 5)->nullable();             // "09:00" (fixed only)
            $table->string('end_time', 5)->nullable();               // "17:00" (fixed only)
            $table->unsignedSmallInteger('late_grace_minutes')->default(0);   // in after start + grace = late
            $table->unsignedSmallInteger('early_grace_minutes')->default(0);  // out before end - grace = left early
            $table->unsignedSmallInteger('full_day_minutes')->default(480);   // expected hours; flexible: worked less = short
            $table->unsignedSmallInteger('half_day_minutes')->default(240);   // worked less = half day
            $table->json('working_days');                            // ISO weekdays, [1,2,3,4,5] = Mon-Fri
            $table->string('missing_checkout', 10)->default('flag');  // flag (HR fixes it) | auto (out at end time)
            $table->boolean('is_default')->default(false);           // used for anyone without a schedule
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_schedule_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'starts_on']);
        });

        Schema::create('attendance_imports', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);                            // machine | hrmy
            $table->string('original_name');
            $table->string('path')->nullable();                      // copy of the file on the private disk
            $table->unsignedInteger('punches_found')->default(0);
            $table->unsignedInteger('punches_added')->default(0);    // new ones; re-uploading a day adds none
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);                            // machine | hrmy
            $table->string('device_user_id', 40);                    // machine "No." / HR.my id
            $table->string('device_name', 150)->nullable();          // name as typed on the device, to help linking
            $table->dateTime('punched_at');
            $table->string('method', 20)->nullable();                // FACE / FP / Other
            $table->foreignId('attendance_import_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['source', 'device_user_id', 'punched_at']);
            $table->index('punched_at');
        });

        Schema::create('attendance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('check_in', 5)->nullable();               // "09:05"; null = use the punch
            $table->string('check_out', 5)->nullable();
            $table->text('note');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_adjustments');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('attendance_imports');
        Schema::dropIfExists('employee_work_schedules');
        Schema::dropIfExists('work_schedules');
    }
};
