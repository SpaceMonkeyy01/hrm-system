<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clock in / out on the My page: a punch with source "app" (device_user_id = employee id). The app records
 * where it came from: office when the request came from an office IP (config hrm.clock.office_ips), else
 * remote; plus the browser's location when the person allowed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->string('place', 10)->nullable()->after('method');      // office | remote; null = by source (AttendanceRules::PLACES)
            $table->string('ip', 45)->nullable()->after('place');
            $table->decimal('latitude', 9, 6)->nullable()->after('ip');
            $table->decimal('longitude', 9, 6)->nullable()->after('latitude');
            $table->unsignedInteger('accuracy')->nullable()->after('longitude');   // metres, as the browser reported it
        });
    }

    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropColumn(['place', 'ip', 'latitude', 'longitude', 'accuracy']);
        });
    }
};
