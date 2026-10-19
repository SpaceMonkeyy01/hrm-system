<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Short leave is a quarter day (0.25): leave days need two decimals, not one (Postgres rounded 0.25 to 0.3). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['leave_records', 'leave_requests'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->decimal('days', 6, 2)->default(0)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['leave_records', 'leave_requests'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->decimal('days', 5, 1)->default(0)->change();
            });
        }
    }
};
