<?php

namespace App\Enums;

/**
 * GOODS_RECEIPT (default, backward-compat for every pre-existing row) — items are copied from
 * one or more Goods Receipts, no stock/PO to type manually. DIRECT — no Goods Receipt/PO at all,
 * lines post straight to a Chart-of-Accounts expense account (vehicle repairs, services, etc.,
 * same as the legacy "Invoice From Supplier" flow). Both share the same 'purchase_invoice'
 * Naming Series — see PurchaseInvoice::documentType().
 */
enum PurchaseInvoiceSource: string
{
    case GOODS_RECEIPT = 'goods_receipt';
    case DIRECT = 'direct';
}
