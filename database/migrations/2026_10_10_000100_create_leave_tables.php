<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * holidays       - public / company holidays (attendance shows them as Holiday, leave doesn't count them)
 * leave_types    - Annual, Casual, Sick ... (editable in the app)
 * leave_records  - approved leave. Requests and approvals stay in HR.my; HR records the outcome here
 *                  (or it is imported from HR.my) so attendance shows "On leave" instead of "Absent".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('name', 100);
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();   // null = every company
            $table->timestamps();
            $table->unique(['date', 'company_id']);
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Starting list; HR edits it on the Leave page.
        $now = now();
        DB::table('leave_types')->insert(collect(['Annual', 'Casual', 'Sick', 'Unpaid', 'Maternity', 'Paternity', 'Bereavement', 'Hajj / Umrah', 'Compensatory'])
            ->map(fn ($name, $i) => ['name' => $name, 'is_paid' => $name !== 'Unpaid', 'is_active' => true, 'sort_order' => ($i + 1) * 10,
                'created_at' => $now, 'updated_at' => $now])->all());

        Schema::create('leave_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('part', 12)->default('full');              // full | first_half | second_half (one day only)
            $table->decimal('days', 5, 1)->default(0);                // working days, worked out when saved
            $table->text('note')->nullable();
            $table->string('source', 20)->default('manual');          // manual | hrmy
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_records');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('holidays');
    }
};
