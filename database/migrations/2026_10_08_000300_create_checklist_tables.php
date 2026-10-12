<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One generic checklist engine for three lists:
 *   onboarding  - setup tasks, orientation sessions, 30/60/90-day check-ins (Onboarding_Tracker)
 *   document    - documents collected/issued (Onboarding_Document_Checklist)
 *   offboarding - exit clearance (Offboarding_Tracker)
 * Items are rows, so HR can add a new document type without a code change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->string('list', 20);                 // onboarding | document | offboarding
            $table->string('key', 60);                  // stable code used by rules, e.g. nda_signed
            $table->string('label');
            $table->string('option_set', 20);           // yes_no | session | document | clearance (config/hrm.php)
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['list', 'key']);
        });

        Schema::create('checklist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->nullable();   // one of the item's option_set values; null = not set
            $table->string('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'checklist_item_id']);
            $table->index(['checklist_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_entries');
        Schema::dropIfExists('checklist_items');
    }
};
