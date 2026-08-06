<?php

namespace App\Enums;

enum PaymentEntryType: string
{
    case SUPPLIER = 'supplier';
    case GENERAL_EXPENSE = 'general_expense';
}
