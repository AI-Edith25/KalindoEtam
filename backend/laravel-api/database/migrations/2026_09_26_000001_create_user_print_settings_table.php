<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user, per-document print preferences (Delivery Order / Invoice print's "Print Options"
 * dialog) — lets an admin trial-and-error a stakeholder's paper/dot-matrix settings from their own
 * account without touching anyone else's printing. A prior attempt at server-side print settings
 * (2026_08_29_000001_create_invoice_print_settings_table, company-wide Header/Footer/Layout/
 * Columns) was cancelled and dropped (2026_08_29_000002) — this is a different shape (per-user,
 * generic JSON keyed by document_type) built for a different need, not a revival of that one.
 *
 * No backfill: this table starts empty, so every existing user keeps reading through
 * localStorage/defaults (see printOptions.ts) until they, or an admin, explicitly save once —
 * matches the "priority: server -> localStorage -> default" read order exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_print_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // 'delivery-order' | 'invoice' for now — generic string, not an enum, so a future
            // document type (Sales Order / Payment Voucher) can adopt this table with no migration.
            $table->string('document_type');
            $table->json('settings');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_print_settings');
    }
};
