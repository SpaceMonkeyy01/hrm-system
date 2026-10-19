<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part of the absent cut-off rule: checked in after the cut-off but worked at least this many minutes = Half day
 * instead of Absent. Null = always Absent (as before). Also: work_schedules.type may now be 'none' (no clock-in).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->unsignedSmallInteger('late_half_day_minutes')->nullable()->after('absent_after');
        });
    }

    public function down(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropColumn('late_half_day_minutes');
        });
    }
};
