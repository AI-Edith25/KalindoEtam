<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 1 of 3 (transaction_type is added nullable here, backfilled/
 * duplicated by the next migration, then locked NOT NULL by the third) —
 * a Tax record now applies to exactly one side of a transaction, and
 * calculation_mode moves from a per-call TaxService::calculate() argument
 * onto the Tax record itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->string('transaction_type')->nullable()->after('type');
            $table->string('calculation_mode')->default('exclusive')->after('rate');
        });
    }

    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->dropColumn(['transaction_type', 'calculation_mode']);
        });
    }
};
