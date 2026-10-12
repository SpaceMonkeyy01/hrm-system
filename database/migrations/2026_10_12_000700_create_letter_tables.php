<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Letters: templates with {{fields}} (LetterRenderer), and every letter issued, kept as the exact text that was
 * printed so it can be reprinted later even if the template or the person's details change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('subject', 200)->nullable();     // the bold line at the top, e.g. "Experience letter"
            $table->text('body');
            $table->string('details_label', 150)->nullable();   // asks HR for {{details}} when issuing
            $table->string('checklist_key', 60)->nullable();    // issuing ticks this offboarding item Done
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('issued_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('letter_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 60)->unique();
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->date('issued_on');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table('letter_templates')->insert([
            [
                'name' => 'Experience letter', 'subject' => 'Experience letter', 'details_label' => null, 'checklist_key' => 'experience_letter_issued', 'sort_order' => 10,
                'body' => "TO WHOM IT MAY CONCERN\n\nThis is to certify that {{name}}, holding CNIC {{cnic}}, was employed with {{company}} as {{role}} in the {{department}} department from {{start_date}} to {{exit_date}}.\n\nDuring this time {{name}} carried out the duties of the role with diligence and professionalism. We wish {{name}} every success in the future.\n\nThis letter is issued on request and carries no liability on the part of the company.",
            ],
            [
                'name' => 'Employment verification', 'subject' => 'Employment verification', 'details_label' => 'Purpose (e.g. opening a bank account)', 'checklist_key' => null, 'sort_order' => 20,
                'body' => "TO WHOM IT MAY CONCERN\n\nThis is to confirm that {{name}}, holding CNIC {{cnic}}, has been employed with {{company}} since {{start_date}} as {{role}} in the {{department}} department, on a {{employment_type}} basis.\n\nThis letter is issued on request for {{details}} and carries no liability on the part of the company.",
            ],
            [
                'name' => 'Appointment letter', 'subject' => 'Letter of appointment', 'details_label' => 'Terms to include (salary, timings, reporting line)', 'checklist_key' => null, 'sort_order' => 30,
                'body' => "Dear {{name}},\n\nWe are pleased to appoint you as {{role}} in the {{department}} department of {{company}}, starting on {{start_date}}. Your employment is {{employment_type}}.\n\nThe first 90 days are a probation period, during which your manager and HR will review your progress with you at 30, 60 and 90 days.\n\n{{details}}\n\nPlease sign and return a copy of this letter to HR to confirm that you accept this appointment.",
            ],
            [
                'name' => 'Warning letter', 'subject' => 'Written warning', 'details_label' => 'What happened, with dates', 'checklist_key' => null, 'sort_order' => 40,
                'body' => "Dear {{name}},\n\nThis letter is a formal written warning about the following:\n\n{{details}}\n\nThis falls short of what {{company}} expects from every employee. We expect an immediate and lasting improvement. If it happens again, further action may follow.\n\nA copy of this letter is kept in your personnel file. If you want to give your side, please talk to HR within five working days.",
            ],
        ]);
        DB::table('letter_templates')->update(['is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_letters');
        Schema::dropIfExists('letter_templates');
    }
};
