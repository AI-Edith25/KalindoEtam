<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tax_base (DPP) = subtotal - discount_amount, the amount PPN is actually computed against.
 * Stored (not derived on read) for the same reason subtotal/tax_amount/grand_total already are —
 * print/export/reports read it directly. discount_amount (existing column) is repurposed from
 * "manually typed header discount" to "sum of line discounts" — same column, new meaning, no
 * rename, since every existing reader already just treats it as a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('tax_base', 15, 2)->default(0)->after('discount_percentage');
        });

        DB::table('invoices')->update(['tax_base' => DB::raw('subtotal - discount_amount')]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('tax_base');
        });
    }
};
