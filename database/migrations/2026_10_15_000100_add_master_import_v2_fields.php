<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee_Master.xlsx (hr:import --master): categories (tags), a second department and manager, the old values
 * the master was cleaned from (legacy, read only), and our own device codes (ATD-001) with the machine each person
 * is enrolled on. The "review" status needs no schema change. Everything is nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();          // "Google Ads Neon", "Etsy BS"
            $table->timestamps();
        });

        Schema::create('employee_category', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['employee_id', 'category_id']);
        });

        Schema::table('attendance_devices', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique()->after('id');   // ATD-001, from the row id, never reused
        });
        foreach (DB::table('attendance_devices')->whereNull('code')->pluck('id') as $id) {
            DB::table('attendance_devices')->where('id', $id)->update(['code' => 'ATD-'.str_pad((string) $id, 3, '0', STR_PAD_LEFT)]);
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('secondary_department_id')->nullable()->after('department_id')->constrained('departments')->nullOnDelete();
            $table->foreignId('secondary_manager_id')->nullable()->after('manager_id')->constrained('employees')->nullOnDelete();
            // The machine this person is enrolled on (attendance_machine_id is their number on it). Information only:
            // punches link by number, whichever machine they come from.
            $table->foreignId('attendance_device_id')->nullable()->after('attendance_machine_id')->constrained()->nullOnDelete();
            $table->json('legacy')->nullable()->after('source_ref');
        });

        DB::table('companies')->where('code', 'EMG')->where('employee_code_prefix', 'Emerge-')->update(['employee_code_prefix' => 'EMG-']);
    }

    public function down(): void
    {
        DB::table('companies')->where('code', 'EMG')->where('employee_code_prefix', 'EMG-')->update(['employee_code_prefix' => 'Emerge-']);
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_device_id');
            $table->dropConstrainedForeignId('secondary_manager_id');
            $table->dropConstrainedForeignId('secondary_department_id');
            $table->dropColumn('legacy');
        });
        Schema::table('attendance_devices', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
        Schema::dropIfExists('employee_category');
        Schema::dropIfExists('categories');
    }
};
