<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the existing (previously CRUD-only) taxes table into a real
 * Tax Engine entity — see docs/TAX_ENGINE_DESIGN.md §2. No new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->string('code')->unique()->after('id');
            $table->string('type')->default('vat')->after('name'); // TaxType enum value
        });
    }

    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->dropColumn(['code', 'type']);
        });
    }
};
