<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * onboardings          - one row per employee (the non-checklist columns of Onboarding_Tracker)
 * probation_plans      - the 90_Day_Plan_Template, one per employee (no more "make a copy of template")
 * probation_plan_items - goals, behaviour, attendance and deliverable rows of the plan
 * probation_checkins   - 30/60/90-day and extra check-in log
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('cohort', 10)->nullable();             // tracker "Group" (A/B/C)
            $table->date('probation_end_date')->nullable();       // start_date + 90 days, editable for extensions
            $table->string('probation_result', 20)->nullable();   // Pending / Pass / Extend / Fail; null = not tracked
            $table->text('plan_url')->nullable();                 // legacy 90_Day_Plan_Link (Google Sheet copy)
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['probation_result', 'probation_end_date']);
        });

        Schema::create('probation_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('plan_date')->nullable();
            // Final evaluation (Meets / Missing / Exceeds) + notes
            $table->string('final_performance', 20)->nullable();
            $table->text('final_performance_notes')->nullable();
            $table->string('final_behaviour', 20)->nullable();
            $table->text('final_behaviour_notes')->nullable();
            $table->string('final_reliability', 20)->nullable();
            $table->text('final_reliability_notes')->nullable();
            $table->string('overall_fit', 20)->nullable();          // Strong / Moderate / Weak
            $table->text('overall_fit_notes')->nullable();
            $table->text('manager_recommendation')->nullable();
            $table->text('hr_comments')->nullable();
            // Signatures: typed name + date
            $table->string('employee_signed_name')->nullable();
            $table->date('employee_signed_on')->nullable();
            $table->string('manager_signed_name')->nullable();
            $table->date('manager_signed_on')->nullable();
            $table->string('hr_signed_name')->nullable();
            $table->date('hr_signed_on')->nullable();
            $table->timestamps();
        });

        Schema::create('probation_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('probation_plan_id')->constrained()->cascadeOnDelete();
            $table->string('section', 20);                 // goal | behaviour | attendance | deliverable
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('title')->nullable();           // goal / behaviour expectation / area / expected output
            $table->text('detail')->nullable();            // success criteria / expectation
            $table->date('due_date')->nullable();          // target date / due date
            $table->string('status', 20)->nullable();      // Not Started / In Progress / Completed
            $table->string('evaluation', 30)->nullable();  // Meets / Needs Improvement
            $table->text('manager_notes')->nullable();
            $table->text('employee_notes')->nullable();
            $table->timestamps();
            $table->index(['probation_plan_id', 'section', 'sort_order']);
        });

        Schema::create('probation_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('probation_plan_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                    // day30 | day60 | day90 | extra
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->date('held_on')->nullable();
            $table->text('manager_summary')->nullable();
            $table->text('employee_summary')->nullable();
            $table->text('action_items')->nullable();
            $table->date('next_follow_up')->nullable();
            $table->string('initials', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('probation_checkins');
        Schema::dropIfExists('probation_plan_items');
        Schema::dropIfExists('probation_plans');
        Schema::dropIfExists('onboardings');
    }
};
