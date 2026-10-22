<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cleans up purchase_invoice_goods_receipts/purchase_invoice_purchase_orders pivot rows left
 * behind by PurchaseInvoiceService::delete() before it started detaching them (see that
 * method's own comment) — deleting a draft Purchase Invoice soft-deletes it, so the pivot's
 * cascadeOnDelete on purchase_invoice_id never fired, permanently blocking that Goods Receipt
 * from ever being invoiced again (goods_receipt_id is unique on that pivot). Purges any pivot
 * row whose purchase_invoice_id points at an already-soft-deleted Purchase Invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $deletedInvoiceIds = DB::table('purchase_invoices')->whereNotNull('deleted_at')->pluck('id');

        DB::table('purchase_invoice_goods_receipts')->whereIn('purchase_invoice_id', $deletedInvoiceIds)->delete();
        DB::table('purchase_invoice_purchase_orders')->whereIn('purchase_invoice_id', $deletedInvoiceIds)->delete();
    }

    public function down(): void
    {
        // Data cleanup only — the purged rows' own Purchase Invoice is itself soft-deleted, nothing meaningful to restore.
    }
};
