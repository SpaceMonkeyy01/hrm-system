<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly off (2026-10-23): HR marks one person's working day as their day off on Edit day (staff whose weekly off
 * moves around). The day then reads Day off, or Worked day off with punches: never Absent, never off the leave balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_adjustments', function (Blueprint $table) {
            $table->boolean('day_off')->default(false)->after('sessions');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_adjustments', fn (Blueprint $table) => $table->dropColumn('day_off'));
    }
};
