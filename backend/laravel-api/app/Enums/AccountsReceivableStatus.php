<?php

namespace App\Enums;

enum AccountsReceivableStatus: string
{
    case UNPAID = 'unpaid';
    case PARTIALLY_PAID = 'partially_paid';
    case PAID = 'paid';
}
