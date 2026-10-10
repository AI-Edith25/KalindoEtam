<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sibling to supplier_id — payment_type=customer_advance pays a PK/PL customer directly (an
     * employee cash advance, say) instead of a Supplier. See PaymentEntry::journalLines() and
     * docs/superpowers/specs/2026-10-10-customer-receivable-categories-design.md.
     */
    public function up(): void
    {
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->foreignUuid('customer_id')->nullable()->after('supplier_id')->constrained('customers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
