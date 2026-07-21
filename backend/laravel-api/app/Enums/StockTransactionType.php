<?php

namespace App\Enums;

enum StockTransactionType: string
{
    case IN = 'in';
    case OUT = 'out';
    case ADJUSTMENT = 'adjustment';
}
