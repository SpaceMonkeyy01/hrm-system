<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assets (2026-10-19):
 *   asset_types     HR-managed list (was config hrm.assets.types), each with a tag prefix: LAP -> BCC-LAP-001.
 *                   assets.type keeps the type's name; renaming a type renames it on its assets.
 *   assets          company_id (which company owns it), cost (PKR), vendor, warranty_until.
 */
return new class extends Migration
{
    private const TYPES = ['Laptop' => 'LAP', 'Desktop' => 'DSK', 'Monitor' => 'MON', 'Mobile phone' => 'MOB', 'SIM card' => 'SIM',
        'ID card' => 'IDC', 'Access card' => 'ACC', 'Headset' => 'HDS', 'Other' => 'OTH'];

    public function up(): void
    {
        Schema::create('asset_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->string('prefix', 6)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        $i = 0;
        foreach (self::TYPES as $name => $prefix) {
            DB::table('asset_types')->insert(['name' => $name, 'prefix' => $prefix, 'sort_order' => $i += 10, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->decimal('cost', 12, 2)->nullable()->after('purchase_date');
            $table->string('vendor', 100)->nullable()->after('cost');
            $table->date('warranty_until')->nullable()->after('vendor');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['cost', 'vendor', 'warranty_until']);
        });
        Schema::dropIfExists('asset_types');
    }
};
