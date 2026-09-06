<?php

namespace App\Enums;

enum StockVoucherType: string
{
    case STOCK_IN = 'stock_in';
    case GOODS_RECEIPT = 'goods_receipt';
    case DELIVERY = 'delivery';
    case STOCK_ADJUSTMENT = 'stock_adjustment';
    case STOCK_TRANSFER = 'stock_transfer';
    case PURCHASE_RETURN = 'purchase_return';
    case CREDIT_NOTE = 'credit_note';
    case OPENING_STOCK = 'opening_stock';
    case ISSUE_STOCK = 'issue_stock';
    case RECEIPT_STOCK = 'receipt_stock';
}
