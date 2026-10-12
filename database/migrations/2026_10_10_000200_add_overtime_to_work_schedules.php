<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Overtime: minutes past the end of the day (or past the day's hours, flexible) before it counts. Null = not counted. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->unsignedSmallInteger('overtime_after_minutes')->nullable()->after('half_day_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropColumn('overtime_after_minutes');
        });
    }
};
