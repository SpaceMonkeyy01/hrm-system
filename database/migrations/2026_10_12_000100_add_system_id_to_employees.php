<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * system_id: this app's own permanent ID (HRM-00001). It is set once when the employee is created and never
 * changes, while employee_code (BCC-001) can still be corrected or replaced if HR settles on HR.my's numbering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('system_id', 20)->nullable()->unique()->after('id');
        });
        foreach (DB::table('employees')->whereNull('system_id')->pluck('id') as $id) {
            DB::table('employees')->where('id', $id)->update(['system_id' => 'HRM-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT)]);
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['system_id']);
            $table->dropColumn('system_id');
        });
    }
};
