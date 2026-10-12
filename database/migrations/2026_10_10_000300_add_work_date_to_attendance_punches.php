<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * work_date: the day the punch belongs to. HR.my gives it (a night shift that clocks out at 09:00 the next
 * morning still belongs to the evening it started); for machine punches it is the punch's own date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->date('work_date')->nullable()->after('punched_at');
            $table->index(['work_date', 'source']);
        });
        DB::table('attendance_punches')->whereNull('work_date')->update(['work_date' => DB::raw('date(punched_at)')]);
    }

    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropIndex(['work_date', 'source']);
            $table->dropColumn('work_date');
        });
    }
};
