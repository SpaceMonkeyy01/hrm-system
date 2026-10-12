<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * employee_files - uploaded scans (CNIC copy, signed NDA, degree ...) kept on the private disk,
 * optionally linked to a document checklist item. Config: hrm.uploads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->nullable()->constrained()->nullOnDelete();   // null = "Other"
            $table->string('label', 150)->nullable();              // what HR typed, e.g. "Front and back"
            $table->string('original_name');                       // file name as uploaded, used for the download
            $table->string('path');                                // on the local (private) disk: employee-files/{employee}/{random}.ext
            $table->string('mime', 100);
            $table->unsignedInteger('size');                       // bytes
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'checklist_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_files');
    }
};
