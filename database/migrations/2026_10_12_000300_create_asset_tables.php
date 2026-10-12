<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset register: company property (laptops, phones, SIMs, ID cards) and who has it. An asset is with someone
 * while it has an assignment without returned_on. Offboarding can't be completed while anything is still out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_tag', 40)->unique();          // what is written on the sticker, e.g. LAP-014
            $table->string('type', 40);                          // config hrm.assets.types
            $table->string('name', 150);                         // make and model, e.g. "Dell Latitude 5420"
            $table->string('serial_number', 100)->nullable();
            // in_stock | assigned | repair | retired | lost (config hrm.assets.statuses)
            $table->string('status', 20)->default('in_stock');
            $table->date('purchase_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['type', 'status']);
        });

        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('assigned_on');
            $table->date('returned_on')->nullable();
            $table->string('condition_out', 150)->nullable();
            $table->string('condition_in', 150)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'returned_on']);
            $table->index(['asset_id', 'returned_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('assets');
    }
};
