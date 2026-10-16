<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Absent cut-off: not checked in by this time = Absent. Null = the middle of the day (9-5 -> 13:00). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->string('absent_after', 5)->nullable()->after('early_grace_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropColumn('absent_after');
        });
    }
};
