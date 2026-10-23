<?php

namespace App\Enums;

/**
 * GOODS_RECEIPT — every line is stock, copied from one or more Goods Receipts. DIRECT — every
 * line is non-stock, posts straight to a Chart-of-Accounts Expense/Liability account (vehicle
 * repairs, services, withholding-tax deductions, etc.). MIXED — both kinds of line on the same
 * invoice. All three share the same 'purchase_invoice' Naming Series — see
 * PurchaseInvoice::documentType(). Not accepted from the client — PurchaseInvoiceService::create()/
 * update() always derive this from which line groups are actually present.
 */
enum PurchaseInvoiceSource: string
{
    case GOODS_RECEIPT = 'goods_receipt';
    case DIRECT = 'direct';
    case MIXED = 'mixed';
}
