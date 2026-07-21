<?php

namespace App\Enums;

enum StockVoucherType: string
{
    case STOCK_IN = 'stock_in';
    case GOODS_RECEIPT = 'goods_receipt';
    case DELIVERY = 'delivery';
    case STOCK_ADJUSTMENT = 'stock_adjustment';
}
