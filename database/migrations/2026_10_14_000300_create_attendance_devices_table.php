<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * attendance_devices - the ZKTeco machines the app reads (Attendance > Devices), each with its own connection and
 * sync settings. Replaces the single machine in ZK_HOST / ZK_PORT / ZK_COMM_KEY: when those are set, they become
 * the first device here, and earlier syncs and their punches are put under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);                                   // "Main office", "Warehouse gate"
            $table->string('site', 120)->nullable();                      // where it hangs, for people
            $table->string('host', 255);                                  // the static IP / host name the app connects to
            $table->unsignedInteger('port')->default(4370);
            $table->text('comm_key')->nullable();                         // encrypted; the machine's Comm. > Comm Key
            $table->boolean('is_active')->default(true);                  // off = never read (kept for its punches)
            $table->boolean('auto_sync')->default(true);                  // read by the cron link / scheduler
            $table->unsignedSmallInteger('timeout_seconds')->default(20);
            $table->unsignedSmallInteger('lookback_days')->default(3);    // each sync re-checks this many days
            $table->unsignedSmallInteger('clock_warn_seconds')->default(60);
            $table->text('notes')->nullable();
            // What the machine said about itself on the last good read.
            $table->string('model_name', 80)->nullable();
            $table->string('serial', 80)->nullable();
            $table->string('firmware', 80)->nullable();
            $table->unsignedInteger('user_count')->nullable();
            $table->unsignedInteger('record_count')->nullable();
            $table->timestamp('info_read_at')->nullable();
            $table->timestamps();
        });

        Schema::table('machine_syncs', function (Blueprint $table) {
            $table->foreignId('attendance_device_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
        Schema::table('attendance_punches', function (Blueprint $table) {
            // Which machine a synced punch came from; null for uploaded files, HR.my and the app.
            $table->foreignId('attendance_device_id')->nullable()->after('attendance_import_id')->constrained()->nullOnDelete();
        });

        $host = (string) config('hrm.machine.host');
        if ($host !== '') {
            $key = (int) config('hrm.machine.comm_key');
            $id = DB::table('attendance_devices')->insertGetId([
                'name' => 'Attendance machine', 'host' => $host, 'port' => (int) config('hrm.machine.port') ?: 4370,
                'comm_key' => $key ? Crypt::encryptString((string) $key) : null,
                'timeout_seconds' => (int) config('hrm.machine.timeout') ?: 20,
                'lookback_days' => (int) config('hrm.machine.lookback_days') ?: 3,
                'clock_warn_seconds' => (int) config('hrm.machine.clock_warn_seconds') ?: 60,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('machine_syncs')->update(['attendance_device_id' => $id]);
            DB::table('attendance_punches')->where('source', 'machine')
                ->whereIn('attendance_import_id', DB::table('machine_syncs')->whereNotNull('attendance_import_id')->select('attendance_import_id'))
                ->update(['attendance_device_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('attendance_punches', fn (Blueprint $table) => $table->dropConstrainedForeignId('attendance_device_id'));
        Schema::table('machine_syncs', fn (Blueprint $table) => $table->dropConstrainedForeignId('attendance_device_id'));
        Schema::dropIfExists('attendance_devices');
    }
};
