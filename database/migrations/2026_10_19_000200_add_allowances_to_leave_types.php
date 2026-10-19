<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave allowances (rules: app/Services/LeaveBalances.php), set from the company policy (2026-10-19):
 *   yearly_days      working days a calendar year, pro-rated from the start date; unused days lapse.
 *                    Annual 14, Casual 10, Sick 6.
 *   per_leave_days   calendar days each time (each pregnancy, wedding, birth). Maternity 90, Wedding 14, Paternity 7.
 * Null = no allowance: days taken are just counted. Adds the Wedding type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->decimal('yearly_days', 5, 1)->nullable()->after('is_paid');
            $table->unsignedSmallInteger('per_leave_days')->nullable()->after('yearly_days');
        });

        foreach (['Annual' => 14, 'Casual' => 10, 'Sick' => 6] as $name => $days) {
            DB::table('leave_types')->where('name', $name)->update(['yearly_days' => $days]);
        }
        foreach (['Maternity' => 90, 'Paternity' => 7] as $name => $days) {
            DB::table('leave_types')->where('name', $name)->update(['per_leave_days' => $days]);
        }
        if (! DB::table('leave_types')->where('name', 'Wedding')->exists()) {
            DB::table('leave_types')->insert(['name' => 'Wedding', 'is_paid' => true, 'is_active' => true, 'per_leave_days' => 14,
                'sort_order' => 65, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['yearly_days', 'per_leave_days']);
        });
    }
};
