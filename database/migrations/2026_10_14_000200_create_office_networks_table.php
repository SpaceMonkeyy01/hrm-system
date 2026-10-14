<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * office_networks - the office's internet addresses, kept by HR on Attendance > App clock-ins. A clock-in on the
 * app from one of them counts as office time (AttendanceClock). Added to any set on the server (HRM_OFFICE_IPS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_networks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);                 // "Main line", "2nd floor Wi-Fi"
            $table->string('address', 50)->unique();    // public IP, or a range like 203.0.113.0/24
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_networks');
    }
};
