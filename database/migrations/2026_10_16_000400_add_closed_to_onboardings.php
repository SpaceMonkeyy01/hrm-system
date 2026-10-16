<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closed onboarding: kept on record, no longer tracked (people who joined long before the app, imported with
 * half-empty checklists). Closed records leave the tracker, Home counts and reminders; they can be reopened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_note', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->dropColumn(['closed_at', 'closed_note']);
        });
    }
};
