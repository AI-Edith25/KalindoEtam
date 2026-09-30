<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports editing a Submitted Invoice (InvoiceService::updateSubmitted()). attention/tel/fax and
 * customer_address/customer_phone are per-Invoice overrides — today these are only ever displayed
 * via the linked Sales Order/Customer's own fields; null keeps showing that source's value (same
 * fallback convention Invoice.reference_1 already uses for the SO's document number), a non-null
 * value is this Invoice's own correction, never mutating the shared Sales Order/Customer row.
 * lock_version is a plain optimistic-lock counter — new to this codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('attention')->nullable()->after('reference_2');
            $table->string('tel')->nullable()->after('attention');
            $table->string('fax')->nullable()->after('tel');
            $table->string('customer_address')->nullable()->after('fax');
            $table->string('customer_phone')->nullable()->after('customer_address');
            $table->unsignedInteger('lock_version')->default(1)->after('revision');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['attention', 'tel', 'fax', 'customer_address', 'customer_phone', 'lock_version']);
        });
    }
};
