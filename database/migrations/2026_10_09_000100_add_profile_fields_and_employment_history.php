<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * employees          - master profile: personal, contact, emergency contact, bank, work mode
 * employment_history - job changes (transfer, role, type, manager, work mode) with the date they took effect
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Personal (private: hidden from viewer logins, masked in the activity log)
            $table->string('father_name', 150)->nullable()->after('last_name');   // father or husband name, as on the CNIC
            $table->date('date_of_birth')->nullable()->after('father_name');
            $table->string('gender', 20)->nullable()->after('date_of_birth');
            $table->string('marital_status', 20)->nullable()->after('gender');
            $table->string('blood_group', 5)->nullable()->after('marital_status');
            $table->date('cnic_expiry')->nullable()->after('cnic');
            // Contact
            $table->string('personal_email', 150)->nullable()->after('email');
            $table->string('alternate_phone', 40)->nullable()->after('phone');
            $table->text('current_address')->nullable()->after('alternate_phone');
            $table->text('permanent_address')->nullable()->after('current_address');
            $table->string('city', 80)->nullable()->after('permanent_address');
            // Emergency contact
            $table->string('emergency_contact_name', 150)->nullable()->after('city');
            $table->string('emergency_contact_relation', 40)->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_phone', 40)->nullable()->after('emergency_contact_relation');
            // Bank (salary account)
            $table->string('bank_name', 100)->nullable()->after('emergency_contact_phone');
            $table->string('bank_account_title', 150)->nullable()->after('bank_name');
            $table->string('bank_account_number', 40)->nullable()->after('bank_account_title');   // account number or IBAN
            // Work: decides which attendance source applies (machine for Office, online portal for Remote)
            $table->string('work_mode', 20)->nullable()->after('employment_type');
            $table->string('work_location', 100)->nullable()->after('work_mode');
            $table->date('confirmation_date')->nullable()->after('start_date');      // made permanent after probation
        });

        Schema::create('employment_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_date');
            $table->string('change_type', 40);                   // config hrm.history_types
            // {"Department": ["Sales", "Marketing"], ...}: names, not ids, so the record reads the same after a rename
            $table->json('changes')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_history');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'father_name', 'date_of_birth', 'gender', 'marital_status', 'blood_group', 'cnic_expiry',
                'personal_email', 'alternate_phone', 'current_address', 'permanent_address', 'city',
                'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone',
                'bank_name', 'bank_account_title', 'bank_account_number', 'work_mode', 'work_location', 'confirmation_date',
            ]);
        });
    }
};
