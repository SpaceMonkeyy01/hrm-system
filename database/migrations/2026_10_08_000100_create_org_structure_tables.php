<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Companies -> Departments -> Teams.
 * Source sheets: "BCC Employee teams Departments" and "Emerge Employee teams Departmen".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();              // BCC, Emerge
            $table->string('code', 20)->unique();          // BCC, EMG
            $table->string('employee_code_prefix', 20);    // "BCC-" -> BCC-001
            $table->unsignedTinyInteger('employee_code_digits')->default(3);
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        // Team names repeat across departments (e.g. "Code Blue"), so a team is unique per department.
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['department_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('companies');
    }
};
