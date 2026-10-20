<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback (2026-10-20), HR and admin only; the employee sees their own on My page and acknowledges each.
 *   conduct_entries  warnings (verbal, written, final) and appreciation, optionally linked to the letter that was issued
 *   meetings         notes from one-to-ones, probation check-ins and review discussions; shared = the employee sees it
 *   letter_templates.conduct_type: issuing such a letter adds the conduct entry
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conduct_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);                 // ConductEntry::TYPES
            $table->date('happened_on');
            $table->string('title', 200);
            $table->text('details')->nullable();
            $table->foreignId('issued_letter_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('employee_comment', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'happened_on']);
        });
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);                 // Meeting::TYPES
            $table->date('held_on');
            $table->string('attendees', 300)->nullable();
            $table->text('notes');
            $table->text('actions')->nullable();
            $table->boolean('shared')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('employee_comment', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'held_on']);
        });
        Schema::table('letter_templates', function (Blueprint $table) {
            $table->string('conduct_type', 30)->nullable()->after('checklist_key');
        });
    }

    public function down(): void
    {
        Schema::table('letter_templates', function (Blueprint $table) {
            $table->dropColumn('conduct_type');
        });
        Schema::dropIfExists('meetings');
        Schema::dropIfExists('conduct_entries');
    }
};
