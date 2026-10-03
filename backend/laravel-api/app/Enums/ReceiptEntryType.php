<?php

namespace App\Enums;

enum ReceiptEntryType: string
{
    case CUSTOMER = 'customer';
    case OTHER_INCOME = 'other_income';
}
