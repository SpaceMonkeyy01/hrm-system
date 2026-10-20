<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * stated: an app Out the employee typed ("I left the office at 18:00") to close a machine check-in they forgot to
 * punch out of, before clocking in from home. It counts like a punch and is marked "(stated)" everywhere.
 * sessions: HR's version of a day as a list of {in, out, place}; it replaces the punches' sessions (the punches stay).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $t) {
            $t->boolean('stated')->default(false)->after('method');
        });
        Schema::table('attendance_adjustments', function (Blueprint $t) {
            $t->json('sessions')->nullable()->after('check_out');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $t) {
            $t->dropColumn('stated');
        });
        Schema::table('attendance_adjustments', function (Blueprint $t) {
            $t->dropColumn('sessions');
        });
    }
};
