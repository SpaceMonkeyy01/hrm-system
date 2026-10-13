<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per attempt to read the attendance machine, so HR can see it is working (or why not). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 10);                           // cron | manual | command
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->boolean('ok')->nullable();                      // null = still running (or died mid-way)
            $table->unsignedInteger('machine_records')->nullable(); // punches stored on the machine
            $table->unsignedInteger('checked')->default(0);         // recent punches compared with ours
            $table->unsignedInteger('added')->default(0);
            $table->integer('clock_offset')->nullable();             // seconds the machine's clock is ahead (+) or behind (-)
            $table->text('error')->nullable();
            $table->foreignId('attendance_import_id')->nullable()->constrained()->nullOnDelete();
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_syncs');
    }
};
