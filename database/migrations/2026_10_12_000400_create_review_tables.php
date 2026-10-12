<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance reviews. A cycle ("2026 mid-year") lists what is rated (criteria, copied from config when the cycle
 * is created so later config changes don't rewrite old reviews). Each person in the cycle gets one review:
 * self-review on My page, then HR's manager review, then the person acknowledges it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('self_due')->nullable();
            $table->date('manager_due')->nullable();
            // draft | open | closed
            $table->string('status', 20)->default('draft');
            $table->json('criteria');                 // [{key, label, description}]
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // self_review | manager_review | completed | cancelled  (ReviewRules)
            $table->string('status', 20)->default('self_review');
            $table->json('self_ratings')->nullable();      // {criterion key: 1-5}
            $table->json('self_comments')->nullable();     // {criterion key: text}
            $table->text('self_summary')->nullable();
            $table->timestamp('self_submitted_at')->nullable();
            $table->json('manager_ratings')->nullable();
            $table->json('manager_comments')->nullable();
            $table->text('manager_summary')->nullable();
            $table->json('goals')->nullable();             // [{title, measure, due}]
            $table->string('recommendation', 40)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manager_submitted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('employee_comment')->nullable();
            $table->timestamps();
            $table->unique(['review_cycle_id', 'employee_id']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('review_cycles');
    }
};
