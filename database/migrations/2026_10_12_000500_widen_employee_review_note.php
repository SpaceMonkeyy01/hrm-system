<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The importer's review notes can run past 255 characters ("Not on the BCC or Emerge department lists (first seen in
 * ... row 187). Set company and department, or merge ..."). SQLite never enforced the limit; Postgres and MySQL do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->text('review_note')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('review_note')->nullable()->change();
        });
    }
};
