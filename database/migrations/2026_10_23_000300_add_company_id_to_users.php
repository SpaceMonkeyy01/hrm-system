<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Home company of an HR, viewer or admin login (2026-10-23): pages with the company filter open on it (RememberCompany)
 * and the daily email covers only its people (SendReminders). Null = both companies. Not a restriction: they can
 * still pick All.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('role')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
    }
};
