<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales-side twin of 2026_10_22_000003's Purchase Invoice cleanup — see that migration's own
 * comment. Purges invoice_deliveries/invoice_sales_orders rows whose invoice_id points at an
 * already-soft-deleted Invoice, left behind by InvoiceService::delete() before it started
 * detaching them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $deletedInvoiceIds = DB::table('invoices')->whereNotNull('deleted_at')->pluck('id');

        DB::table('invoice_deliveries')->whereIn('invoice_id', $deletedInvoiceIds)->delete();
        DB::table('invoice_sales_orders')->whereIn('invoice_id', $deletedInvoiceIds)->delete();
    }

    public function down(): void
    {
        // Data cleanup only — the purged rows' own Invoice is itself soft-deleted, nothing meaningful to restore.
    }
};
